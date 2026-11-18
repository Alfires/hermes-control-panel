<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
lib('theme.php');

// 20 preset catalogue cases.
$presetIds = [
    'brezel-light-rounded', 'brezel-dark-rounded', 'brezel-light-square', 'brezel-dark-square',
    'midnight', 'ember', 'mono',
];
count_at_least(count($presetIds), 6, 'at least six theme presets required');
$i = 0;
foreach ($presetIds as $id) {
    $i++;
    test("theme.preset.$i", static function () use ($id): void {
        $presets = Theme::presets();
        ok(isset($presets[$id]), "$id preset missing");
        $preset = $presets[$id];
        ok(isset($preset['mode']) && in_array($preset['mode'], ['light', 'dark'], true), "$id mode");
        ok(isset($preset['radius']) && in_array($preset['radius'], ['rounded', 'square'], true), "$id radius");
        ok(isset($preset['tokens']) && is_array($preset['tokens']), "$id tokens");
        ok(isset($preset['tokens']['--accent']), "$id accent token");
        contains((string) $preset['tokens']['--accent'], '#', "$id accent colour");
    });
}

// 20 token-validation cases.
$validTokens = [
    ['--accent' => '#ed8d30'],
    ['--navy' => '#203448'],
    ['--radius' => '18px'],
    ['--space' => '1rem'],
    ['--accent' => '#fff'],
    ['--accent' => '#12345678'],
    ['--font' => 'system'],
    ['--font' => 'mono'],
    ['--accent' => 'rgb(1,2,3)'],
    ['--shadow' => '0 8px 24px rgba(0,0,0,.1)'],
];
$invalidTokens = [
    ['--accent' => 'url(http://evil)'],
    ['--accent' => 'javascript:alert(1)'],
    ['--accent' => 'expression(alert(1))'],
    ['--custom' => '<script>'],
    ['--accent' => '@import "evil.css"'],
    ['--font' => '../../etc/passwd'],
    ['--radius' => '</style>body{display:none}'],
    ['--accent' => str_repeat('x', 500)],
    ['background-image' => 'url(https://evil)'],
    ['--accent' => 'red; position:fixed'],
];
$i = 0;
foreach ($validTokens as $tokens) {
    $i++;
    test("theme.token.valid.$i", static function () use ($tokens, $i): void {
        ok(Theme::validateTokens($tokens), "valid token set $i rejected");
    });
}
$i = 0;
foreach ($invalidTokens as $tokens) {
    $i++;
    test("theme.token.invalid.$i", static function () use ($tokens, $i): void {
        ok(!Theme::validateTokens($tokens), "invalid token set $i accepted");
    });
}
