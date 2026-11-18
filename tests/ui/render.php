<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
lib('render.php');

$pages = [
    'overview', 'sessions', 'agents', 'approvals', 'security', 'vault', 'settings', 'audit', 'setup', 'search',
    'hermes-status', 'hermes-sessions', 'hermes-files', 'hermes-analytics', 'hermes-models', 'hermes-logs',
    'hermes-cron', 'hermes-skills', 'hermes-plugins', 'hermes-mcp', 'hermes-profiles', 'hermes-config',
    'hermes-env', 'hermes-system', 'voice', 'themes', 'self-test',
];
$i = 0;
foreach ($pages as $page) {
    foreach (['empty', 'populated', 'error'] as $mode) {
        $i++;
        test("ui.page.$page.$mode", static function () use ($page, $mode, $i): void {
            $html = Render::page($page, fixture_config(), ['mode' => $mode]);
            ok(is_string($html) && strlen($html) > 40, "page $page/$mode rendered nothing");
            not_contains($html, '<script', "page $page/$mode contains script tag");
            not_contains($html, fake_secret(1), "page $page/$mode leaked test secret");
            if ($mode === 'error') contains($html, 'error', "page $page error state missing");
        });
    }
}

$blocks = [
    'hero', 'kpi', 'cardGrid', 'table', 'form', 'jsonEditor', 'chat', 'timeline', 'chart',
    'searchResults', 'voiceRecorder', 'themeGallery', 'statusStrip',
];
$i = 0;
foreach ($blocks as $block) {
    foreach (['empty', 'populated', 'error'] as $mode) {
        $i++;
        test("ui.block.$block.$mode", static function () use ($block, $mode, $i): void {
            $html = Render::block($block, ['mode' => $mode, 'title' => 'Test'], []);
            ok(is_string($html) && strlen($html) > 20, "block $block/$mode rendered nothing");
            not_contains($html, '<script', "block $block/$mode contains script tag");
            not_contains($html, 'onerror=', "block $block/$mode contains inline handler");
        });
    }
}
