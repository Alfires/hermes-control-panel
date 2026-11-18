<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/rbac.php';
require_once __DIR__ . '/../lib/engine.php';
require_once __DIR__ . '/../lib/vault.php';
require_once __DIR__ . '/../lib/hostctl.php';

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

switch ($action) {
    case 'login':
        $token = Auth::login(Util::str($b['username'] ?? '', 120), (string)($b['password'] ?? ''));
        if (!$token) Util::json(['ok' => false, 'error' => 'invalid credentials'], 401);
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
            'messages' => DB::all('SELECT m.*, f.slug AS from_slug, t.slug AS to_slug FROM messages m
                                   LEFT JOIN agents f ON f.id=m.from_agent
                                   JOIN agents t ON t.id=m.to_agent ORDER BY m.id DESC LIMIT 80'),
            'layout' => DB::one("SELECT value FROM layout WHERE key='layout'"),
            'vault' => ['available' => Vault::available(), 'secrets' => Vault::listSecrets()['secrets']],
            'host' => ['available' => HostCtl::available()],
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

    default:
        Util::json(['ok' => false, 'error' => 'unknown action'], 404);
}
