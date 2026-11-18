<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
lib('schema.php');

// 20 valid config cases.
for ($i = 1; $i <= 20; $i++) {
    test("schema.valid.$i", static function () use ($i): void {
        $cfg = fixture_config(['meta' => ['title' => 'Deck ' . $i]]);
        $r = Schema::validateConfig($cfg);
        ok($r['ok'] === true, "valid config $i rejected: " . json_encode($r));
    });
}

// 20 invalid config cases.
$mutators = [
    static function (array &$c): void { $c['schema_version'] = 99; },
    static function (array &$c): void { $c['unknown_key'] = 1; },
    static function (array &$c): void { $c['theme']['tokens']['--accent'] = 'url(evil)'; },
    static function (array &$c): void { $c['theme']['mode'] = 'purple'; },
    static function (array &$c): void { $c['theme']['radius'] = 'roundish'; },
    static function (array &$c): void { $c['pages'][0]['id'] = 'Bad Page'; },
    static function (array &$c): void { $c['pages'][0]['route'] = 'no-leading-slash'; },
    static function (array &$c): void { $c['pages'][0]['blocks'][0]['type'] = 'unknownBlock'; },
    static function (array &$c): void { $c['pages'][0]['blocks'][0]['source'] = 'hermes.env.reveal'; },
    static function (array &$c): void { $c['pages'][0]['blocks'][0]['props']['html'] = '<script>alert(1)</script>'; },
    static function (array &$c): void { $c['pages'][] = $c['pages'][0]; },
    static function (array &$c): void { $c['feature_flags'] = 'yes'; },
    static function (array &$c): void { $c['prompts']['style'] = '<img onerror=alert(1)>'; },
    static function (array &$c): void { $c['schedule']['self_test'] = 'every 0m'; },
    static function (array &$c): void { $c['meta']['description'] = str_repeat('x', 100000); },
    static function (array &$c): void { $c['pages'][0]['blocks'][0]['instance'] = '../etc/passwd'; },
    static function (array &$c): void { $c['theme']['tokens']['--accent'] = 'javascript:alert(1)'; },
    static function (array &$c): void { $c['pages'][0]['nav']['icon'] = '<svg onload=alert(1)>'; },
    static function (array &$c): void { $c['pages'][0]['capabilities'] = ['hermes.secret.read']; },
    static function (array &$c): void { $c['pages'][0]['blocks'][0]['source'] = '/api/config/raw'; },
];
$i = 0;
foreach ($mutators as $mutator) {
    $i++;
    test("schema.invalid.$i", static function () use ($mutator, $i): void {
        $cfg = fixture_config();
        $mutator($cfg);
        $r = Schema::validateConfig($cfg);
        ok($r['ok'] === false, "invalid config $i accepted");
    });
}

// 10 structural limit cases.
for ($i = 1; $i <= 10; $i++) {
    test("schema.limit.$i", static function () use ($i): void {
        $cfg = fixture_config();
        if ($i === 1) {
            for ($p = 0; $p < 101; $p++) {
                $cfg['pages'][] = ['id' => 'p' . $p, 'route' => '/p' . $p, 'nav' => ['label' => 'P', 'icon' => '◈', 'order' => $p],
                    'capabilities' => [], 'blocks' => []];
            }
        } elseif ($i === 2) {
            for ($b = 0; $b < 51; $b++) {
                $cfg['pages'][0]['blocks'][] = ['instance' => 'b' . $b, 'type' => 'hero', 'source' => 'panel.bootstrap', 'props' => []];
            }
        } else {
            $cfg['meta']['title'] = str_repeat('t', $i * 2000);
        }
        $r = Schema::validateConfig($cfg);
        ok($r['ok'] === false, "limit case $i should be rejected");
    });
}
