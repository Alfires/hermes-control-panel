<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
lib('registry.php');

$sourceIds = [
    'hermes.status', 'hermes.sessions.list', 'hermes.session.get', 'hermes.models.options',
    'hermes.tools.toolsets', 'hermes.skills.list', 'hermes.cron.jobs', 'hermes.analytics',
    'hermes.themes', 'hermes.config.schema', 'hermes.env.names', 'hermes.messaging.platforms',
    'hermes.webhooks', 'hermes.gateway.capabilities', 'hermes.logs', 'hermes.files.meta',
    'hermes.git.status', 'hermes.profiles', 'hermes.plugins', 'hermes.mcp',
];
count_at_least(count($sourceIds), 20, 'expected source list too small');

// 40 cases: two assertions per curated source.
$i = 0;
foreach ($sourceIds as $id) {
    foreach (['shape', 'scrub'] as $check) {
        $i++;
        test("contract.hermes.$id.$check", static function () use ($id, $check): void {
            $sources = HermesSources::catalog();
            ok(isset($sources[$id]), "source $id missing");
            $s = $sources[$id];
            if ($check === 'shape') {
                ok(isset($s['method'], $s['path'], $s['fields'], $s['capability'], $s['ttl']), "$id shape");
                ok(str_starts_with((string) $s['path'], '/'), "$id path must be absolute");
                ok(is_array($s['fields']) && count($s['fields']) > 0, "$id field whitelist");
            } else {
                eq((bool) ($s['scrub'] ?? true), true, "$id must scrub responses");
                $json = json_encode($s);
                not_contains($json, 'env/reveal', "$id references forbidden env/reveal");
                not_contains($json, 'config/raw', "$id references forbidden config/raw");
                not_contains($json, 'debug-share', "$id references forbidden debug-share");
            }
        });
    }
}

// 10 forbidden endpoint cases.
$forbidden = [
    '/api/env/reveal', '/api/config/raw', '/api/ops/debug-share', '/api/files/ticket',
    '/api/pty', '/api/host-terminal', '/auth/native/token', '/api/ws', '/api/audio/transcribe-stream',
    '/api/dashboard/plugin-providers',
];
foreach ($forbidden as $i => $path) {
    test("contract.hermes.forbidden." . ($i + 1), static function () use ($path): void {
        ok(HermesSources::isForbidden($path), "$path must be forbidden");
        ok(!in_array($path, array_column(HermesSources::catalog(), 'path'), true), "$path must not be a source");
    });
}
