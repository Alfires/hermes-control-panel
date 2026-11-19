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

// 10. One-line installer must emit the variable names the PHP app actually reads.
test('debug.installer.env.names.match.app', static function (): void {
    $installer = (string) file_get_contents(dirname(__DIR__, 3) . '/hermes-control-panel-installer/install.sh');
    foreach (['PANEL_ADMIN_USER', 'PANEL_ADMIN_PASSWORD', 'PANEL_LLM_KEY',
              'VAULT_SOCKET', 'HOSTCTL_SOCKET', 'PANEL_HOST_KEY'] as $name) {
        contains($installer, $name, "installer is missing $name");
    }
    not_contains($installer, 'ADMIN_PASSWORD_HASH', 'legacy unused env name must be gone');
});
