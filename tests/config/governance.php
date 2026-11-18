<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
lib('db.php');
lib('config.php');

function gov_fresh_db(): string {
    $path = tempnam(sys_get_temp_dir(), 'hcp-gov-') . '.sqlite';
    @unlink($path);
    $_ENV['PANEL_DB'] = $path;
    putenv('PANEL_DB=' . $path);
    if (class_exists('DB')) DB::init();
    return $path;
}

function gov_proposal(string $path, mixed $value, string $op = 'replace'): array {
    return [['op' => $op, 'path' => $path, 'value' => $value]];
}

// 20 valid proposals.
$validPaths = [
    '/meta/title' => 'My Deck',
    '/meta/locale' => 'de',
    '/theme/preset' => 'brezel-dark-rounded',
    '/theme/mode' => 'dark',
    '/theme/radius' => 'square',
    '/theme/tokens/--accent' => '#3f78d8',
    '/theme/tokens/--radius' => '0px',
    '/feature_flags/voice' => false,
    '/feature_flags/self_test' => true,
    '/prompts/style' => 'concise',
    '/schedule/self_test' => 'daily 05:30',
    '/pages/0/nav/label' => 'Home',
    '/pages/0/nav/icon' => '⌂',
    '/pages/0/nav/order' => 5,
    '/pages/0/blocks/0/instance' => 'metrics-main',
    '/pages/0/blocks/0/props/metrics/0' => 'sessions',
    '/meta/description' => 'A personal control deck',
    '/theme/font' => 'mono',
    '/feature_flags/hermes_proxy' => false,
    '/prompts/normalise' => false,
];
$i = 0;
foreach ($validPaths as $path => $value) {
    $i++;
    test("config.propose.valid.$i", static function () use ($path, $value, $i): void {
        gov_fresh_db();
        $r = ConfigGovernance::propose(gov_proposal($path, $value), 'owner');
        ok($r['ok'] === true, "proposal $i rejected: " . json_encode($r));
        ok(isset($r['revision'], $r['hash'], $r['diff']), "proposal $i missing fields");
        eq($r['state'] ?? 'draft', 'draft', "proposal $i must be a draft");
    });
}

// 20 invalid proposals.
$invalid = [
    gov_proposal('/secrets/token', 'abc'),
    gov_proposal('/theme/tokens/--accent', 'url(evil)'),
    gov_proposal('/pages/0/blocks/0/type', 'unknownBlock'),
    gov_proposal('/pages/0/blocks/0/source', 'hermes.env.reveal'),
    gov_proposal('/meta/title', str_repeat('x', 5000)),
    [['op' => 'delete', 'path' => '/meta/title']],
    [['op' => 'replace', 'path' => 'meta/title', 'value' => 'x']],
    [['op' => 'replace', 'path' => '/schema_version', 'value' => 99]],
    [['op' => 'replace', 'path' => '/pages/0/route', 'value' => 'bad-route']],
    [['op' => 'add', 'path' => '/pages/-', 'value' => ['id' => 'dup', 'route' => '/']]],
    gov_proposal('/feature_flags/hermes_proxy', 'yes'),
    gov_proposal('/schedule/self_test', 'every 0m'),
    gov_proposal('/prompts/style', '<script>alert(1)</script>'),
    gov_proposal('/theme/mode', 'neon'),
    gov_proposal('/pages/0/capabilities/0', 'secret.read'),
    gov_proposal('/theme/tokens/background', 'url(http://evil)'),
    gov_proposal('/meta/title', ['nested' => ['<b>bold</b>']]),
    gov_proposal('/pages/0/blocks/0/props/html', '<img onerror=alert(1)>'),
    [['op' => 'replace', 'path' => '/theme/tokens/--accent', 'value' => 'javascript:alert(1)']],
    gov_proposal('/unknown_root', ['x' => 1]),
];
$i = 0;
foreach ($invalid as $patch) {
    $i++;
    test("config.propose.invalid.$i", static function () use ($patch, $i): void {
        gov_fresh_db();
        $r = ConfigGovernance::propose($patch, 'agent:webui');
        ok($r['ok'] === false, "invalid proposal $i accepted: " . json_encode($r));
    });
}

// 15 approval cases.
for ($i = 1; $i <= 15; $i++) {
    test("config.approve.$i", static function () use ($i): void {
        gov_fresh_db();
        $p = ConfigGovernance::propose(gov_proposal('/meta/title', 'Deck ' . $i), 'owner');
        ok($p['ok'] === true, "setup proposal $i failed");
        $r = ConfigGovernance::approve($p['proposal_id'], 'owner');
        ok($r['ok'] === true, "approval $i failed: " . json_encode($r));
        eq(ConfigGovernance::active()['meta']['title'], 'Deck ' . $i, "approval $i not applied");
    });
}

// 15 rollback/history cases.
for ($i = 1; $i <= 15; $i++) {
    test("config.rollback.$i", static function () use ($i): void {
        gov_fresh_db();
        $a = ConfigGovernance::propose(gov_proposal('/meta/title', 'A' . $i), 'owner');
        ConfigGovernance::approve($a['proposal_id'], 'owner');
        $b = ConfigGovernance::propose(gov_proposal('/meta/title', 'B' . $i), 'owner');
        ConfigGovernance::approve($b['proposal_id'], 'owner');
        $r = ConfigGovernance::rollback($a['revision'], 'owner');
        ok($r['ok'] === true, "rollback $i failed: " . json_encode($r));
        eq(ConfigGovernance::active()['meta']['title'], 'A' . $i, "rollback $i wrong state");
        $h = ConfigGovernance::history();
        count_at_least(count($h), 3, "history $i too short");
    });
}
