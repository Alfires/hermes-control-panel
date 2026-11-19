<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
lib('registry.php');

// --- action catalogue -------------------------------------------------------

test('hygiene.catalog.nonempty', static function (): void {
    ok(count(PanelActions::catalog()) >= 39, 'catalogue must declare at least the original 39 actions');
});

test('hygiene.catalog.matches.implemented.names', static function (): void {
    $src = (string) file_get_contents(repo_path('src/public/api.php'));
    ok((bool) preg_match_all("/case '([a-z0-9_.]+)':/", $src, $m), 'could not read api.php cases');
    $implemented = $m[1];
    $catalog = array_keys(PanelActions::catalog());
    eq(count($catalog), count($implemented), 'catalogue count must match the api.php switch exactly');
    sort($implemented);
    sort($catalog);
    eq($catalog, $implemented, 'catalogue names must match the api.php switch exactly');
});

$newActions = [
    'layout.save' => ['POST', 'panel.layout_save', true],
    'agent.message' => ['POST', 'panel.agent_message', true],
    'panel.host.probe' => ['POST', 'panel.panel_host_probe', true],
    'panel.redaction.probe' => ['POST', 'panel.panel_redaction_probe', true],
];
foreach ($newActions as $action => [$method, $capability, $ownerOnly]) {
    test("hygiene.catalog.$action.flags", static function () use ($action, $method, $capability, $ownerOnly): void {
        $catalog = PanelActions::catalog();
        ok(isset($catalog[$action]), "$action missing from catalogue");
        $entry = $catalog[$action];
        eq($entry['method'], $method, "$action method");
        eq($entry['capability'], $capability, "$action capability");
        eq((bool) $entry['owner_only'], $ownerOnly, "$action owner_only");
    });
}

// --- declared-verb helper ---------------------------------------------------

test('hygiene.assertmethod.get.accepted', static function (): void {
    ok(PanelActions::assertMethod('state.diff', 'GET'), 'state.diff declares GET');
});

test('hygiene.assertmethod.is.case.insensitive', static function (): void {
    ok(PanelActions::assertMethod('state.diff', 'get'), 'verb comparison must be case-insensitive');
});

test('hygiene.assertmethod.rejects.wrong.verb', static function (): void {
    ok(!PanelActions::assertMethod('state.diff', 'POST'), 'state.diff must reject POST');
});

test('hygiene.assertmethod.mutating.bootstrap.is.post', static function (): void {
    ok(PanelActions::assertMethod('bootstrap', 'POST'), 'bootstrap installs RBAC defaults and must be POST');
    ok(!PanelActions::assertMethod('bootstrap', 'GET'), 'bootstrap must not be callable with GET');
});

test('hygiene.assertmethod.post.accepted', static function (): void {
    ok(PanelActions::assertMethod('layout.save', 'POST'), 'layout.save declares POST');
});

test('hygiene.assertmethod.fails.closed.for.unknown.action', static function (): void {
    ok(!PanelActions::assertMethod('not.a.real.action', 'GET'), 'unknown actions must fail closed');
});

// --- dead frontend globals --------------------------------------------------

test('hygiene.no.hcp.config.injection', static function (): void {
    $index = (string) file_get_contents(repo_path('src/public/index.php'));
    not_contains($index, 'HCP_CONFIG', 'unused HCP_CONFIG injection must be removed');
});

test('hygiene.no.hcp.theme.presets.read', static function (): void {
    $app = (string) file_get_contents(repo_path('src/public/app.js'));
    not_contains($app, 'HCP_THEME_PRESETS', 'dead HCP_THEME_PRESETS read must be removed');
    contains($app, 'S.theme_presets || {}', 'applyLayout must use the bootstrap presets');
});

test('hygiene.no.window.hcp.globals', static function (): void {
    foreach (glob(repo_path('src/public/*.php')) ?: [] as $file) {
        not_contains((string) file_get_contents($file), 'window.HCP_', 'dead HCP global in ' . basename($file));
    }
    foreach (glob(repo_path('src/public/*.js')) ?: [] as $file) {
        not_contains((string) file_get_contents($file), 'window.HCP_', 'dead HCP global in ' . basename($file));
    }
});

// --- curated critical packages ----------------------------------------------

$reviewed = ['ssh', 'sudo', 'curl', 'wget', 'git', 'docker', 'podman',
             'systemd', 'iptables', 'nftables', 'openssl'];

test('hygiene.critical.packages.defined', static function (): void {
    ok(defined('CRITICAL_PACKAGES'), 'CRITICAL_PACKAGES constant must exist');
});

test('hygiene.critical.packages.nonempty', static function (): void {
    ok(is_array(CRITICAL_PACKAGES), 'CRITICAL_PACKAGES must be an array');
    count_at_least(count(CRITICAL_PACKAGES), 1, 'CRITICAL_PACKAGES must not be empty');
});

test('hygiene.critical.packages.reviewed', static function () use ($reviewed): void {
    $actual = array_values(CRITICAL_PACKAGES);
    eq($actual, $reviewed, 'CRITICAL_PACKAGES must match the reviewed set');
    foreach ($actual as $pkg) {
        ok(is_string($pkg) && $pkg !== '', 'every critical package must be a non-empty name');
    }
});

test('hygiene.critical.rules.installed.nonempty', static function () use ($reviewed): void {
    $sh = (string) file_get_contents(repo_path('deploy/install.sh'));
    ok(strpos($sh, "printf '{}\\n'") === false && !preg_match("/echo '\{\}'/", $sh),
       'installer must not write an empty {} rules file');
    foreach ($reviewed as $pkg) {
        contains($sh, '"' . $pkg . '"', "installer rules must include $pkg");
    }
    // The rules are a broker file, not a config key: adding them to config
    // would be rejected by Schema::TOP_KEYS and would never be consumed.
    ok(strpos($sh, 'critical-packages.json') !== false, 'rules file path must be written');
});

// Allow `php tests/unit/hygiene.php` to execute this file standalone; when the
// suite includes it, suite.php owns run_tests() as usual.
if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === realpath(__FILE__)) {
    exit(run_tests());
}
