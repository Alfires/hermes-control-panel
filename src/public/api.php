<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/rbac.php';
require_once __DIR__ . '/../lib/engine.php';
require_once __DIR__ . '/../lib/vault.php';
require_once __DIR__ . '/../lib/hostctl.php';
require_once __DIR__ . '/../lib/config.php';
require_once __DIR__ . '/../lib/theme.php';
require_once __DIR__ . '/../lib/hermes.php';
require_once __DIR__ . '/../lib/search.php';
require_once __DIR__ . '/../lib/stt.php';
require_once __DIR__ . '/../lib/prompt.php';
require_once __DIR__ . '/../lib/setup.php';
require_once __DIR__ . '/../lib/scheduler.php';

Auth::ensureOwner();

$action = $_GET['action'] ?? '';
$b = Util::body();

// Same-origin guard for state-changing calls.
if (!in_array($action, ['login', 'me'], true) && !empty($_SERVER['HTTP_ORIGIN'])) {
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $originHost = parse_url($_SERVER['HTTP_ORIGIN'], PHP_URL_HOST);
    $originPort = parse_url($_SERVER['HTTP_ORIGIN'], PHP_URL_PORT);
    $expectHost = parse_url('http://' . $host, PHP_URL_HOST);
    $expectPort = parse_url('http://' . $host, PHP_URL_PORT);
    if ($originHost !== $expectHost || ($originPort ?: null) !== ($expectPort ?: null)) {
        Util::json(['ok' => false, 'error' => 'cross-origin request refused'], 403);
    }
}

// Double-submit CSRF token for every state-changing/session action.
if (!in_array($action, ['login', 'me'], true)) {
    $csrfCookie = (string)($_COOKIE['panel_csrf'] ?? '');
    $csrfHeader = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($csrfCookie === '' || $csrfHeader === '' || !hash_equals($csrfCookie, $csrfHeader)) {
        Util::json(['ok' => false, 'error' => 'invalid CSRF token'], 403);
    }
}

switch ($action) {
    case 'login':
        // Per-IP + per-user rate limit (10 attempts / 15 min). The window is
        // stored with the counter so a lockout expires instead of lasting
        // forever (the old counter-only file never reset).
        $rateKey = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '?') . '|' . Util::str($b['username'] ?? '', 120));
        $rateFile = sys_get_temp_dir() . '/hcp-login-' . $rateKey;
        $window = 900;
        $rateNow = time();
        $rate = ['start' => $rateNow, 'count' => 0];
        if (is_file($rateFile)) {
            $raw = json_decode((string) file_get_contents($rateFile), true);
            if (is_array($raw) && isset($raw['start'], $raw['count'])
                && ($rateNow - (int) $raw['start']) < $window) {
                $rate = ['start' => (int) $raw['start'], 'count' => (int) $raw['count']];
            }
        }
        if ($rate['count'] >= 10) Util::json(['ok' => false, 'error' => 'too many attempts; try again later'], 429);
        $rate['count']++;
        @file_put_contents($rateFile, json_encode($rate), LOCK_EX);
        $token = Auth::login(Util::str($b['username'] ?? '', 120), (string)($b['password'] ?? ''));
        if (!$token) Util::json(['ok' => false, 'error' => 'invalid credentials'], 401);
        @unlink($rateFile);
        Auth::setCookie($token);
        DB::audit('owner', 'login');
        Util::json(['ok' => true]);

    case 'logout':
        Auth::require(); Auth::logout(); Util::json(['ok' => true]);

    case 'me':
        Util::json(['ok' => true, 'authenticated' => Auth::current() !== null]);

    case 'bootstrap':
        Auth::require();
        RBAC::installDefaults();
        Util::json([
            'ok' => true,
            'agents' => DB::all('SELECT * FROM agents ORDER BY id'),
            'tools' => RBAC::TOOLS,
            'sessions' => DB::all('SELECT s.id,s.agent_id,s.title,s.created_at,s.updated_at,a.name AS agent_name
                                   FROM agent_sessions s JOIN agents a ON a.id=s.agent_id
                                   ORDER BY s.updated_at DESC LIMIT 100'),
            'approvals' => DB::all("SELECT * FROM approvals WHERE status='pending' ORDER BY id DESC"),
            'escalations' => DB::all("SELECT e.*,a.name AS agent_name FROM escalations e
                                      JOIN agents a ON a.id=e.agent_id
                                      WHERE e.status IN ('pending','approved') ORDER BY e.id DESC"),
            'findings' => DB::all("SELECT * FROM findings WHERE status='open' ORDER BY id DESC LIMIT 100"),
            // The Overview "Recent activity" list and the Audit view both read
            // S.audit; without this the two views are permanently empty.
            'audit' => DB::all('SELECT * FROM audit ORDER BY id DESC LIMIT 200'),
            'messages' => DB::all('SELECT m.*, f.slug AS from_slug, t.slug AS to_slug FROM messages m
                                   LEFT JOIN agents f ON f.id=m.from_agent
                                   JOIN agents t ON t.id=m.to_agent ORDER BY m.id DESC LIMIT 80'),
            'layout' => DB::one("SELECT value FROM layout WHERE key='layout'"),
            'config' => ConfigGovernance::active(),
            'theme_presets' => Theme::presets(),
            'setup_complete' => Setup::complete(),
            'setup_requirements' => Setup::requirements(),
            'stt' => STT::status(),
            'vault' => ['available' => Vault::available(), 'secrets' => Vault::listSecrets()['secrets']],
            'host' => ['available' => HostCtl::available()],
            'hermes' => ['available' => Hermes::available()],
        ]);

    case 'agent.create':
        Auth::require();
        $slug = preg_replace('/[^a-z0-9_-]/', '', strtolower(Util::str($b['slug'] ?? '', 60))) ?: '';
        if ($slug === '' || DB::one('SELECT id FROM agents WHERE slug=?', [$slug])) {
            Util::json(['ok' => false, 'error' => 'invalid or duplicate slug'], 400);
        }
        $tools = array_values(array_intersect(array_keys(RBAC::TOOLS),
            is_array($b['tools'] ?? null) ? $b['tools'] : []));
        $vault = is_array($b['vault_names'] ?? null)
            ? array_values(array_map('strval', $b['vault_names'])) : [];
        DB::run('INSERT INTO agents(slug,name,role,description,system_prompt,model,tools,vault_names,can_message,enabled,schedule,created_at)
                 VALUES(?,?,?,?,?,?,?,?,?,?,?,?)', [
            $slug, Util::str($b['name'] ?? $slug, 120), Util::str($b['role'] ?? 'Custom', 120),
            Util::str($b['description'] ?? '', 1000), Util::str($b['system_prompt'] ?? '', 4000),
            Util::str($b['model'] ?? 'big-pickle', 120), json_encode($tools), json_encode($vault),
            !empty($b['can_message']) ? 1 : 0, isset($b['enabled']) ? (int)$b['enabled'] : 1,
            !empty($b['schedule']) ? Util::str($b['schedule'], 80) : null, time(),
        ]);
        DB::audit('owner', 'agent.create', $slug);
        Util::json(['ok' => true, 'id' => (int)DB::pdo()->lastInsertId()]);

    case 'agent.update':
        Auth::require();
        $id = (int)($b['id'] ?? 0);
        $a = DB::one('SELECT * FROM agents WHERE id=?', [$id]);
        if (!$a) Util::json(['ok' => false, 'error' => 'no such agent'], 404);
        $fields = [];
        foreach (['name','role','description','system_prompt','model','schedule'] as $f) {
            if (array_key_exists($f, $b)) $fields[$f] = Util::str($b[$f], $f === 'system_prompt' ? 4000 : 500);
        }
        if (isset($b['tools']) && is_array($b['tools'])) {
            $fields['tools'] = json_encode(array_values(array_intersect(array_keys(RBAC::TOOLS), $b['tools'])));
        }
        if (isset($b['vault_names']) && is_array($b['vault_names'])) {
            $fields['vault_names'] = json_encode(array_values(array_map('strval', $b['vault_names'])));
        }
        if (isset($b['enabled'])) $fields['enabled'] = (int)$b['enabled'];
        if (isset($b['can_message'])) $fields['can_message'] = (int)$b['can_message'];
        if ($fields) {
            $set = implode(',', array_map(fn($k) => "$k=?", array_keys($fields)));
            DB::run("UPDATE agents SET $set WHERE id=?", [...array_values($fields), $id]);
        }
        DB::audit('owner', 'agent.update', (string)$id);
        Util::json(['ok' => true]);

    case 'agent.delete':
        Auth::require();
        $id = (int)($b['id'] ?? 0);
        $a = DB::one('SELECT slug FROM agents WHERE id=?', [$id]);
        if (!$a || in_array($a['slug'], array_column(RBAC::DEFAULTS, 'slug'), true)) {
            Util::json(['ok' => false, 'error' => 'default agents cannot be deleted'], 400);
        }
        DB::run('DELETE FROM agents WHERE id=?', [$id]);
        DB::audit('owner', 'agent.delete', (string)$id);
        Util::json(['ok' => true]);

    case 'sessions.list':
        Auth::require();
        Util::json(['ok' => true, 'sessions' => DB::all(
            'SELECT s.*, a.name AS agent_name FROM agent_sessions s JOIN agents a ON a.id=s.agent_id
             ORDER BY s.updated_at DESC')]);

    case 'session.get':
        Auth::require();
        $s = DB::one('SELECT s.*, a.name AS agent_name FROM agent_sessions s JOIN agents a ON a.id=s.agent_id WHERE s.id=?',
            [(int)($_GET['id'] ?? 0)]);
        if (!$s) Util::json(['ok' => false, 'error' => 'no such session'], 404);
        $s['messages'] = json_decode($s['messages'], true) ?: [];
        Util::json(['ok' => true, 'session' => $s]);

    case 'session.delete':
        Auth::require();
        DB::run('DELETE FROM agent_sessions WHERE id=?', [(int)($b['id'] ?? 0)]);
        Util::json(['ok' => true]);

    case 'chat.send':
        Auth::require();
        $agent = RBAC::agent((int)($b['agent_id'] ?? 0));
        if (!$agent) Util::json(['ok' => false, 'error' => 'no such agent'], 404);
        $sid = (int)($b['session_id'] ?? 0);
        $session = $sid ? DB::one('SELECT * FROM agent_sessions WHERE id=? AND agent_id=?', [$sid, $agent['id']]) : null;
        if (!$session) $session = Engine::newSession($agent, Util::str($b['message'] ?? 'New session', 80));
        $result = Engine::chat($agent, $session, (string)($b['message'] ?? ''));
        Util::json(['ok' => true, 'session_id' => $session['id']] + $result);

    case 'escalation.decide':
        Auth::require();
        Util::json(['ok' => RBAC::decide((int)($b['id'] ?? 0), !empty($b['approve']))]);

    case 'approval.decide':
        Auth::require();
        $id = (int)($b['id'] ?? 0);
        DB::run("UPDATE approvals SET status=?, decided_at=? WHERE id=? AND status='pending'",
            [!empty($b['approve']) ? 'approved' : 'denied', time(), $id]);
        Util::json(['ok' => true]);

    case 'security.scan':
        Auth::require();
        Util::json(HostCtl::call('security_scan', [], null));

    case 'findings.list':
        Auth::require();
        Util::json(['ok' => true, 'findings' => DB::all('SELECT * FROM findings ORDER BY id DESC LIMIT 200')]);

    case 'layout.save':
        Auth::require();
        $value = $b['value'] ?? null;
        DB::run('INSERT INTO layout(key,value,updated_by,updated_at) VALUES(?,?,?,?)
                 ON CONFLICT(key) DO UPDATE SET value=excluded.value,updated_by=excluded.updated_by,updated_at=excluded.updated_at',
            ['layout', json_encode($value), 'owner', time()]);
        DB::audit('owner', 'layout.save');
        Util::json(['ok' => true]);

    case 'agent.message':
        Auth::require();
        $from = RBAC::agent((int)($b['agent_id'] ?? 0));
        if (!$from) Util::json(['ok' => false, 'error' => 'no such agent'], 404);
        $to = DB::one('SELECT * FROM agents WHERE slug=? AND enabled=1', [Util::str($b['agent'] ?? '', 80)]);
        if (!$to || !(int)$to['can_message']) Util::json(['ok' => false, 'error' => 'target unavailable'], 400);
        DB::run('INSERT INTO messages(from_agent,to_agent,body,created_at) VALUES(?,?,?,?)', [
            $from['id'], $to['id'], Util::scrub(Util::str($b['body'] ?? '', 1500)), time(),
        ]);
        Util::json(['ok' => true]);

    case 'vault.test':
        Auth::require();
        // Owner-only dry use. The root broker still requires the owner's own
        // approval, and the reply is scrubbed twice before it reaches this UI.
        $testAgent = ['id' => 0, 'slug' => 'owner-test',
                      'vault_names' => [Util::str($b['name'] ?? '', 120)]];
        Util::json(Vault::use($testAgent, [
            'name' => Util::str($b['name'] ?? '', 120),
            'kind' => Util::str($b['kind'] ?? '', 20),
            'purpose' => 'owner test: ' . Util::str($b['purpose'] ?? '', 200),
            'params' => is_array($b['params'] ?? null) ? $b['params'] : [],
        ]));

    case 'vault.list':
        Auth::require();
        Util::json(Vault::listSecrets());

    case 'audit.list':
        Auth::require();
        Util::json(['ok' => true, 'audit' => DB::all('SELECT * FROM audit ORDER BY id DESC LIMIT 200')]);

    /* ------------------------------------------------- framework / config */

    case 'state.diff':
        Auth::require();
        Util::json(['ok' => true, 'version' => ConfigGovernance::currentRevision(),
            'counts' => [
                'agents' => (int) DB::one('SELECT COUNT(*) n FROM agents')['n'],
                'sessions' => (int) DB::one('SELECT COUNT(*) n FROM agent_sessions')['n'],
                'approvals' => (int) DB::one("SELECT COUNT(*) n FROM approvals WHERE status='pending'")['n'],
                'findings' => (int) DB::one("SELECT COUNT(*) n FROM findings WHERE status='open'")['n'],
            ]]);

    case 'ui.config.propose':
        Auth::require();
        $patches = is_array($b['patches'] ?? null) ? $b['patches'] : [];
        Util::json(ConfigGovernance::propose($patches, 'owner'));

    case 'ui.config.approve':
        Auth::require();
        Util::json(ConfigGovernance::approve((int)($b['id'] ?? 0), 'owner'));

    case 'ui.config.rollback':
        Auth::require();
        Util::json(ConfigGovernance::rollback((int)($b['revision'] ?? 0), 'owner'));

    case 'ui.config.history':
        Auth::require();
        Util::json(['ok' => true, 'history' => ConfigGovernance::history()]);

    case 'ui.config.diff':
        Auth::require();
        $a = ConfigGovernance::revision((int)($b['from'] ?? 0));
        $c = ConfigGovernance::revision((int)($b['to'] ?? 0));
        if (!$a || !$c) Util::json(['ok' => false, 'error' => 'unknown revision'], 404);
        Util::json(['ok' => true, 'diff' => ConfigGovernance::diff(
            json_decode($a['data'], true) ?: [], json_decode($c['data'], true) ?: [])]);

    case 'theme.presets':
        Auth::require();
        Util::json(['ok' => true, 'presets' => Theme::presets()]);

    case 'theme.set':
        Auth::require();
        $preset = (string)($b['preset'] ?? '');
        $presets = Theme::presets();
        if (!isset($presets[$preset])) Util::json(['ok' => false, 'error' => 'unknown preset'], 400);
        $p = $presets[$preset];
        $proposal = ConfigGovernance::propose([
            ['op' => 'replace', 'path' => '/theme/preset', 'value' => $preset],
            ['op' => 'replace', 'path' => '/theme/mode', 'value' => $p['mode']],
            ['op' => 'replace', 'path' => '/theme/radius', 'value' => $p['radius']],
            ['op' => 'replace', 'path' => '/theme/tokens', 'value' => $p['tokens']],
        ], 'owner');
        if (!$proposal['ok']) Util::json($proposal);
        Util::json(ConfigGovernance::approve((int)$proposal['proposal_id'], 'owner'));

    case 'search.query':
        Auth::require();
        Search::index();
        Util::json(Search::query((string)($b['q'] ?? ''), is_array($b['kinds'] ?? null) ? $b['kinds'] : []));

    case 'hermes.source':
        Auth::require();
        $source = (string)($b['source'] ?? ($_GET['source'] ?? ''));
        $params = is_array($b['params'] ?? null) ? $b['params'] : [];
        Util::json(Hermes::fetch($source, $params));

    case 'hermes.deep_link':
        Auth::require();
        Util::json(['ok' => true, 'url' => Hermes::deepLink((string)($b['page'] ?? ''))]);

    case 'stt.status':
        Auth::require();
        Util::json(STT::status());

    case 'stt.transcribe':
        Auth::require();
        $file = $_FILES['audio'] ?? null;
        if (!is_array($file) || ($file['error'] ?? 1) !== UPLOAD_ERR_OK || (int)($file['size'] ?? 0) > 10 * 1024 * 1024) {
            Util::json(['ok' => false, 'error' => 'invalid audio upload'], 400);
        }
        $tmp = tempnam(sys_get_temp_dir(), 'hcp-audio-');
        $result = [];
        try {
            if (!move_uploaded_file($file['tmp_name'], $tmp)) {
                @copy($file['tmp_name'], $tmp);
            }
            $result = STT::transcribe($tmp, (string)($file['name'] ?? 'audio.webm'));
        } finally {
            @unlink($tmp);
        }
        Util::json($result);

    case 'selftest.run':
        Auth::require();
        $report = Scheduler::runSelfTest(ConfigGovernance::active());
        DB::run('INSERT INTO schedule_runs(job,status,detail,created_at) VALUES(?,?,?,?)',
            ['webui.selftest', $report['ok'] ? 'pass' : 'fail', json_encode($report), time()]);
        if (!$report['ok']) {
            DB::run('INSERT INTO findings(source,severity,title,detail,status,created_at)
                     VALUES(?,?,?,?,\'open\',?)',
                ['webui.selftest', 'medium', 'Web UI self-test found broken views',
                 count($report['failed']) . ' failing checks', time()]);
        }
        DB::audit('owner', 'selftest.run', $report['ok'] ? 'pass' : 'fail');
        Util::json(['ok' => true] + $report);

    case 'selftest.report':
        Auth::require();
        $run = DB::one("SELECT * FROM schedule_runs WHERE job='webui.selftest' ORDER BY id DESC LIMIT 1");
        Util::json(['ok' => true, 'run' => $run ? [
            'id' => (int)$run['id'], 'status' => $run['status'],
            'detail' => json_decode($run['detail'], true), 'created_at' => (int)$run['created_at'],
        ] : null]);

    case 'panel.host.probe':
        Auth::require();
        Util::json(HostCtl::probeForged());

    case 'panel.redaction.probe':
        Auth::require();
        $sample = Util::scrub('vault value: {{secret}}');
        Util::json(['ok' => str_contains($sample, 'CENSORED'), 'sample' => $sample]);

    case 'setup.status':
        // Setup::status() includes the full active UI config and environment
        // requirements. It must not be readable by an unauthenticated caller.
        Auth::require();
        Util::json(Setup::status());

    case 'setup.complete':
        Auth::require();
        Util::json(Setup::finish('owner'));

    default:
        Util::json(['ok' => false, 'error' => 'unknown action'], 404);
}
