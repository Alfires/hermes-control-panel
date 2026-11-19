<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
lib('hostctl.php');

/**
 * Regressions found during the deep debugging pass. Each test guards a real
 * defect that was fixed in this repo; keep them when touching these files.
 */

// 1. The daily security scan and agent wake-ups were missing from worker.php,
//    so crontab called a command that only printed usage and exited 1.
test('debug.worker.has.security.command', static function (): void {
    $src = (string) file_get_contents(repo_path('src/worker.php'));
    contains($src, "cmd === 'security'", 'worker.php must implement the security cron command');
    contains($src, "cmd === 'agents'", 'worker.php must implement the agents cron command');
    contains($src, "HostCtl::call('security_scan'", 'security command must run a real read-only scan');
});

test('debug.worker.no.duplicate.loop', static function (): void {
    $src = (string) file_get_contents(repo_path('supervisord.conf'));
    not_contains($src, 'worker.php loop', 'supervisord must not run a second tick loop alongside cron');
});

test('debug.cron.declares.all.jobs', static function (): void {
    $cron = (string) file_get_contents(repo_path('crontab'));
    foreach (['worker.php tick', 'worker.php agents', 'worker.php security'] as $job) {
        contains($cron, $job, "crontab is missing $job");
    }
});

// 2. The Audit view and Overview activity read S.audit, but bootstrap never
//    supplied it, leaving both permanently empty.
test('debug.bootstrap.includes.audit', static function (): void {
    $src = (string) file_get_contents(repo_path('src/public/api.php'));
    ok((bool) preg_match("/'bootstrap'.+?'audit'\s*=>\s*DB::all/s", $src),
        'bootstrap must return the audit rows used by two UI views');
});

// 3. setup.status returned the entire active config and requirements without
//    requiring a session.
test('debug.setup.status.is.authenticated', static function (): void {
    $src = (string) file_get_contents(repo_path('src/public/api.php'));
    ok((bool) preg_match("/case 'setup\.status':\s*\/\/.*?Auth::require\(\);/s", $src),
        'setup.status must call Auth::require() before exposing config');
});

// 4. The login lockout file stored only a counter with no window timestamp,
//    so one burst of 10 failures locked an IP out forever.
test('debug.login.rate.window.expires', static function (): void {
    $src = (string) file_get_contents(repo_path('src/public/api.php'));
    contains($src, "'start' => \$rateNow", 'login rate limit must store a window start');
    contains($src, "\$rateNow - (int) \$raw['start']) < \$window", 'login rate limit must expire');
});

// 5. PHP and Python signed host actions with different JSON key orders, so
//    multi-key actions would be refused as forged.
test('debug.hostctl.args.are.sorted', static function (): void {
    $m = new ReflectionMethod('HostCtl', 'sortedArgs');
    $m->setAccessible(true);
    $sorted = $m->invoke(null, ['zeta' => 1, 'alpha' => ['b' => 2, 'a' => 3]]);
    eq(json_encode($sorted), '{"alpha":{"a":3,"b":2},"zeta":1}',
        'HMAC args must match Python sort_keys=True canonical JSON');
});

// 6. The installer force-removed any container literally named hermes-agent,
//    which can be the production gateway managed from /opt/hermes.
test('debug.installer.does.not.kill.production.gateway', static function (): void {
    $src = (string) file_get_contents(repo_path('deploy/install.sh'));
    not_contains($src, 'docker rm -f hermes-agent', 'installer must not kill a bare container name');
    contains($src, 'com.docker.compose.project=hermes-control-panel',
        'cleanup must be scoped to this compose project');
});

// 7. A plain `docker compose up -d` must never start a second gateway on the
//    ports already owned by the production Hermes container.
test('debug.compose.gateway.is.profile.gated', static function (): void {
    $src = (string) file_get_contents(repo_path('docker-compose.yml'));
    contains($src, 'profiles: ["standalone"]', 'the hermes service must be behind a profile');
    not_contains($src, 'depends_on:', 'webui must not depend on the standalone gateway');
});

// 8. The root broker's convenience ops used to be callable without an HMAC.
test('debug.hostctl.ops.require.hmac', static function (): void {
    $src = (string) file_get_contents(repo_path('hostctl/hostctl.py'));
    contains($src, 'if op in {"status", "scan", "cve"}:', 'read-only broker ops must be signed');
    contains($src, 'verify("op:" + op', 'read-only broker ops must call verify()');
});

// 9. The critical-package rules file was written to one path and read from another.
test('debug.hostctl.rules.path.aligned', static function (): void {
    $py = (string) file_get_contents(repo_path('hostctl/hostctl.py'));
    $sh = (string) file_get_contents(repo_path('deploy/install.sh'));
    contains($py, '/opt/panel-secrets/critical-packages.json', 'broker rules path');
    contains($sh, 'critical-packages.json', 'installer must write the rules file');
});

// 10b. Every registered page must resolve to a title AND a real destination.
//      Before this fix 18 of the 32 nav items fell through to one generic view
//      with a blank header, which made the UI feel broken and unintuitive.
test('debug.ui.every.registered.page.is.reachable', static function (): void {
    $registry = (string) file_get_contents(repo_path('src/lib/registry.php'));
    ok((bool) preg_match('/public static function builtin\(\): array\s*\{(.*?)\$pages = \[\]/s', $registry, $m),
        'could not locate PageRegistry::builtin() groups');
    preg_match_all("/'([a-z][a-z0-9-]*)'/", $m[1], $ids);
    $pages = array_values(array_unique($ids[1]));
    // The builder deliberately simplified navigation: one built-in Hermes area
    // instead of 19 separate Hermes pages, and voice lives inside the chat.
    ok(count($pages) >= 12, 'expected the simplified registered page set, got ' . count($pages));
    ok(!in_array('voice', $pages, true), 'voice must not be its own sidebar page');
    ok(in_array('chats', $pages, true) && in_array('hermes', $pages, true),
        'chats and one integrated Hermes area must be registered');
    ok(count(array_filter($pages, static fn($p) => str_starts_with($p, 'hermes-'))) === 0,
        'Hermes features must not create separate sidebar sections');

    $js = (string) file_get_contents(repo_path('src/public/app.js'));
    ok((bool) preg_match('/const TITLES = \{(.*?)\n\};/s', $js, $t), 'TITLES map missing');
    preg_match_all("/'?([a-z][a-z0-9-]*)'?\s*:\s*\[/", $t[1], $titled);
    ok((bool) preg_match('/const fn = \{(.*?)\};/s', $js, $f), 'view renderer map missing');
    preg_match_all("/'?([a-z][a-z0-9-]*)'?\s*:/", $f[1], $rendered);
    ok((bool) preg_match('/const PAGE_SOURCE = \{(.*?)\n\};/s', $js, $ps), 'PAGE_SOURCE map missing');
    preg_match_all("/'([a-z][a-z0-9-]*)'\s*:/", $ps[1], $sources);
    ok((bool) preg_match('/const PAGE_NOTE = \{(.*?)\n\};/s', $js, $pn), 'PAGE_NOTE map missing');
    preg_match_all("/'([a-z][a-z0-9-]*)'\s*:/", $pn[1], $notes);

    $titles = array_flip($titled[1]);
    $renderers = array_flip($rendered[1]);
    $sources = array_flip($sources[1]);
    $notes = array_flip($notes[1]);
    $missing = [];
    foreach ($pages as $page) {
        $hasTitle = isset($titles[$page]);
        $hasDest = isset($renderers[$page]) || isset($sources[$page]) || isset($notes[$page]);
        if (!$hasTitle || !$hasDest) $missing[] = $page . (!$hasTitle ? '(no title)' : '(no destination)');
    }
    ok($missing === [], 'registered pages with no working view: ' . implode(', ', $missing));
});

// 9b. The 10s background refresh rebuilt #content and silently erased anything
//     the owner was typing. It now pauses while a form is focused or dirty.
test('debug.ui.refresh.preserves.typed.input', static function (): void {
    $js = (string) file_get_contents(repo_path('src/public/app.js'));
    contains($js, 'function hasUnsavedInput()', 'form-dirty guard must exist');
    ok((bool) preg_match('/setInterval\(async \(\) => \{(.*?)\}, 10000\)/s', $js, $m),
        'auto-refresh interval not found');
    contains($m[1], '!hasUnsavedInput()', 'auto-refresh must skip dirty forms');
});

// 9c. "Recorded actions" used pending-only data, so decided approvals vanished.
test('debug.ui.approval.history.is.loaded', static function (): void {
    $api = (string) file_get_contents(repo_path('src/public/api.php'));
    $js = (string) file_get_contents(repo_path('src/public/app.js'));
    contains($api, "'approval_history' => DB::all", 'bootstrap must supply decided approvals');
    contains($js, 'S.approval_history||S.approvals', 'Recorded actions must use full history');
});

// 9d. Empty vault left an active "Use secret" button that could only error.
test('debug.ui.empty.vault.form.is.disabled', static function (): void {
    $js = (string) file_get_contents(repo_path('src/public/app.js'));
    contains($js, 'noSecrets', 'vault view must know when there are no secrets');
    contains($js, "data-vault-use \${noSecrets?'disabled':''}", 'empty vault must disable injection');
});

// 9e. Proposal expiry and rollback state were written but never enforced.
test('debug.config.expiry.and.rollback.state.are.enforced', static function (): void {
    $cfg = (string) file_get_contents(repo_path('src/lib/config.php'));
    contains($cfg, "(int)\$proposal['expires_at'] <= time()", 'proposal expiry must be checked');
    contains($cfg, "['active', 'rolled_back', 'superseded']", 'rollback must reject draft revisions');
});

// 9f. RBAC::expirePending() was dead code until the minute tick called it.
test('debug.worker.expires.stale.escalations', static function (): void {
    $w = (string) file_get_contents(repo_path('src/worker.php'));
    contains($w, 'RBAC::expirePending();', 'the minute tick must expire stale escalations');
});

// 10. One-line installer must emit the variable names the PHP app actually reads.
test('debug.installer.env.names.match.app', static function (): void {
    $installer = (string) file_get_contents(dirname(__DIR__, 3) . '/hermes-control-panel-installer/install.sh');
    foreach (['PANEL_ADMIN_USER', 'PANEL_ADMIN_PASSWORD', 'PANEL_LLM_KEY',
              'VAULT_SOCKET', 'HOSTCTL_SOCKET', 'PANEL_HOST_KEY'] as $name) {
        contains($installer, $name, "installer is missing $name");
    }
    not_contains($installer, 'ADMIN_PASSWORD_HASH', 'legacy unused env name must be gone');
});

// Builder UI pass: chat stays on the right, fullscreen is opt-in, the microphone
// lives in the composer, and no menu is allowed to clip its own content.
test('debug.ui.chat.dock.and.mic', static function (): void {
    $html = (string) file_get_contents(repo_path('src/public/index.php'));
    $js = (string) file_get_contents(repo_path('src/public/app.js'));
    $css = (string) file_get_contents(repo_path('src/public/app.css'));
    contains($html, 'id="chat-dock-btn"', 'top bar must open the right-side chat');
    contains($html, 'class="mic-compose" id="chat-mic"', 'voice must be a button in the chat composer');
    contains($html, 'id="chat-full"', 'chat must have an explicit fullscreen control');
    contains($js, "classList.toggle('fullscreen'", 'fullscreen must be a toggle, not the default');
    contains($js, 'function bindMicControl(', 'one shared microphone implementation is required');
    contains($js, 'bindMicControl($(\'#chat-mic\')', 'chat mic must be wired to the composer');
    not_contains($js, "$('#voice-btn')", 'the old separate Voice menu must be gone');
    contains($css, '.chat-overlay{position:fixed; top:0; right:0;', 'chat must dock on the right');
    contains($css, '.chat-overlay.fullscreen{width:100vw', 'fullscreen chat must use the whole window');
});
test('debug.ui.every.menu.scrolls', static function (): void {
    $css = (string) file_get_contents(repo_path('src/public/app.css'));
    foreach (['.nav{', '.content{', '.drawer-body{', '.chat-thread{', '.view-tabs{'] as $selector) {
        $pos = strpos($css, $selector);
        ok($pos !== false, "missing scrollable rule for $selector");
        if ($pos !== false) {
            $rule = substr($css, $pos, strpos($css, '}', $pos) - $pos);
            contains($rule, 'overflow', "$selector must declare overflow");
        }
    }
});
