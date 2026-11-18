<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

$files = array_merge(
    glob(__DIR__ . '/unit/*.php') ?: [],
    glob(__DIR__ . '/contract/*.php') ?: [],
    glob(__DIR__ . '/rbac/*.php') ?: [],
    glob(__DIR__ . '/config/*.php') ?: [],
    glob(__DIR__ . '/ui/*.php') ?: [],
    glob(__DIR__ . '/install/*.php') ?: [],
    glob(__DIR__ . '/live/*.php') ?: []
);
sort($files);
foreach ($files as $file) {
    if (basename($file) === 'bootstrap.php') continue;
    require_once $file;
}

$total = count($GLOBALS['HCP_TESTS']);
echo "Hermes Control Deck — framework test suite\n";
echo "Registered cases: $total\n\n";
if ($total < 500) {
    echo "ERROR: need at least 500 cases, only $total registered\n";
    exit(2);
}
exit(run_tests());
