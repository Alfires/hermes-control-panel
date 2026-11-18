<?php
declare(strict_types=1);

/**
 * Zero-dependency test harness for the Hermes Control Deck framework rebuild.
 * Tests are registered with test(id, fn) and executed by tests/suite.php.
 * Every case is counted individually, including data-provider expansions.
 */

$GLOBALS['HCP_TESTS'] = [];
$GLOBALS['HCP_RESULTS'] = ['pass' => 0, 'fail' => 0, 'skip' => 0];
$GLOBALS['HCP_FAILURES'] = [];

function test(string $id, callable $fn): void {
    $GLOBALS['HCP_TESTS'][] = [$id, $fn];
}

function skip_test(string $id, string $reason): void {
    $GLOBALS['HCP_TESTS'][] = [$id, static function () use ($reason): void {
        throw new RuntimeException('SKIP:' . $reason);
    }];
}

function ok(bool $condition, string $message = 'assertion failed'): void {
    if (!$condition) throw new RuntimeException($message);
}

function eq(mixed $actual, mixed $expected, string $message = ''): void {
    if ($actual !== $expected) {
        throw new RuntimeException($message !== '' ? $message :
            'expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function contains(string $haystack, string $needle, string $message = ''): void {
    if (!str_contains($haystack, $needle)) {
        throw new RuntimeException($message !== '' ? $message : "missing '$needle'");
    }
}

function not_contains(string $haystack, string $needle, string $message = ''): void {
    if (str_contains($haystack, $needle)) {
        throw new RuntimeException($message !== '' ? $message : "unexpected '$needle'");
    }
}

function count_at_least(int $actual, int $minimum, string $message = ''): void {
    if ($actual < $minimum) {
        throw new RuntimeException($message !== '' ? $message : "expected >= $minimum, got $actual");
    }
}

function lib(string $file): void {
    $path = dirname(__DIR__) . '/src/lib/' . $file;
    if (is_file($path)) require_once $path;
}

function repo_path(string $relative = ''): string {
    return dirname(__DIR__) . ($relative !== '' ? '/' . ltrim($relative, '/') : '');
}

function fake_secret(int $n = 1): string {
    return 'TEST-SECRET-DO-NOT-USE-' . $n . '-abcXYZ123';
}

function fixture_config(array $overrides = []): array {
    $base = [
        'schema_version' => 1,
        'revision' => 1,
        'meta' => ['title' => 'Hermes Control Deck', 'locale' => 'en', 'description' => 'test'],
        'theme' => [
            'preset' => 'brezel-light-rounded',
            'mode' => 'light',
            'radius' => 'rounded',
            'tokens' => ['--accent' => '#ed8d30', '--navy' => '#203448', '--radius' => '18px'],
        ],
        'pages' => [
            ['id' => 'overview', 'route' => '/', 'nav' => ['label' => 'Overview', 'icon' => '◈', 'order' => 10],
             'capabilities' => ['panel.bootstrap'],
             'blocks' => [['instance' => 'kpi-1', 'type' => 'kpi', 'source' => 'panel.bootstrap',
                           'props' => ['metrics' => ['agents', 'sessions']]]]],
        ],
        'feature_flags' => ['hermes_proxy' => true, 'voice' => true, 'self_test' => true],
        'prompts' => ['style' => 'casual-ok', 'normalise' => true],
        'schedule' => ['self_test' => 'daily 04:20'],
    ];
    return array_replace_recursive($base, $overrides);
}

function run_tests(): int {
    foreach ($GLOBALS['HCP_TESTS'] as [$id, $fn]) {
        try {
            $fn();
            $GLOBALS['HCP_RESULTS']['pass']++;
        } catch (Throwable $e) {
            $msg = $e->getMessage();
            if (str_starts_with($msg, 'SKIP:')) {
                $GLOBALS['HCP_RESULTS']['skip']++;
                continue;
            }
            $GLOBALS['HCP_RESULTS']['fail']++;
            $GLOBALS['HCP_FAILURES'][] = [$id, $msg];
            echo "FAIL  $id :: $msg\n";
        }
    }
    $r = $GLOBALS['HCP_RESULTS'];
    $total = $r['pass'] + $r['fail'] + $r['skip'];
    echo "\nTOTAL: $total cases, {$r['pass']} passed, {$r['fail']} failed, {$r['skip']} skipped\n";
    if ($GLOBALS['HCP_FAILURES']) {
        echo "\nFirst failures:\n";
        foreach (array_slice($GLOBALS['HCP_FAILURES'], 0, 15) as [$id, $msg]) {
            echo "  - $id :: $msg\n";
        }
    }
    return $r['fail'] > 0 ? 1 : 0;
}
