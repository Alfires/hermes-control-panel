<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';

$live = getenv('RUN_LIVE') === '1';
$checks = [
    'health.php answers 200',
    'login rejects wrong password',
    'login accepts owner password',
    'bootstrap contains 4 default agents',
    'search.query returns results',
    'hermes.status source answers',
    'hermes.sessions source shape safe',
    'ui.config.propose is dry-run',
    'ui.config.approve applies revision',
    'ui.config.rollback restores revision',
    'theme presets endpoint works',
    'voice status reports engine',
    'selftest.run creates a report',
    'state.diff returns versioned delta',
    'CSRF token enforced',
    'login rate limit enforced',
    'vault broker list answers',
    'host broker status answers',
    'forged HMAC refused',
    'secret redaction marker present',
];
foreach ($checks as $i => $description) {
    if (!$live) {
        skip_test('live.' . ($i + 1) . '.' . str_replace(' ', '-', $description), 'set RUN_LIVE=1');
    } else {
        test('live.' . ($i + 1) . '.' . str_replace(' ', '-', $description), static function () use ($description): void {
            ok(LiveChecks::run($description), 'live check failed: ' . $description);
        });
    }
}
