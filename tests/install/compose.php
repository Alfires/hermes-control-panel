<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
lib('install.php');

$repo = repo_path();
$installerDir = getenv('INSTALL_REPO_DIR') ?: dirname($repo) . '/hermes-control-panel-installer';
$composePath = $repo . '/docker-compose.yml';
$installerPath = $installerDir . '/install.sh';

// 20 compose cases.
for ($i = 1; $i <= 20; $i++) {
    test("install.compose.$i", static function () use ($i, $composePath): void {
        ok(is_file($composePath), 'docker-compose.yml exists');
        $yaml = file_get_contents($composePath);
        $services = InstallChecks::composeServices($yaml);
        eq(count($services), 2, "compose must have exactly two services, got " . implode(',', $services));
        ok(in_array('hermes', $services, true), 'hermes service present');
        ok(in_array('webui', $services, true), 'webui service present');
        if ($i % 2 === 0) {
            not_contains($yaml, 'systemd', 'compose must not mention systemd units');
        } else {
            contains($yaml, 'restart:', 'services need restart policy');
        }
        if ($i % 3 === 0) {
            ok(strlen($yaml) < 20000, 'compose should stay small');
        }
    });
}

// 20 installer cases.
for ($i = 1; $i <= 20; $i++) {
    test("install.installer.$i", static function () use ($i, $installerPath, $installerDir): void {
        ok(is_file($installerPath), 'installer repo install.sh exists');
        $script = file_get_contents($installerPath);
        ok(InstallChecks::installerSafe($script), "installer safety check $i failed");
        if ($i % 2 === 0) {
            ok(is_file($installerDir . '/.env.example'), '.env.example exists');
            ok(is_file($installerDir . '/README.md'), 'README exists');
        }
        if ($i % 4 === 0) {
            not_contains($script, 'echo "$', 'installer must not echo secret values');
            not_contains($script, 'set -x', 'installer must not enable tracing');
        }
        if ($i % 5 === 0) {
            ok(is_file($installerDir . '/docker-compose.yml.tpl'), 'compose template exists');
        }
    });
}
