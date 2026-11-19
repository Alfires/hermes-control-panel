<?php
declare(strict_types=1);
/** Framework preview entry point. The production shell remains index.php; this
 *  endpoint demonstrates config-driven, server-rendered pages from the same
 *  registry that governs AI-editable UI. It is owner-only. */
require_once dirname(__DIR__) . '/../vendor/autoload.php';
require_once dirname(__DIR__) . '/lib/auth.php';
require_once dirname(__DIR__) . '/lib/config.php';
require_once dirname(__DIR__) . '/lib/registry.php';

Auth::require();

$config = ConfigGovernance::active();
$app = Deck\Framework\Kernel::app($config, static function (string $page) use ($config): array {
    // Data comes from curated panel/Hermes sources only. Never pass raw env.
    return [
        'page' => $page,
        'agents' => [],
        'sessions' => [],
        'title' => $config['meta']['title'] ?? 'Hermes Control Deck',
    ];
});
$app->run();
