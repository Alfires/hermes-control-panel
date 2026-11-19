<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';

// The desktop client is a pinned, patched fork of the genuine Hermes Desktop.
// These checks keep the pin explicit, the patch narrow and secrets out of git.
$readme = (string) file_get_contents(repo_path('desktop/README.md'));
$boot = (string) file_get_contents(repo_path('desktop/bootstrap.sh'));
$patch = (string) file_get_contents(repo_path('desktop/patches/deck-identity.patch'));
$example = (string) file_get_contents(repo_path('desktop/connection.json.example'));
$platforms = (string) file_get_contents(repo_path('docs/DESKTOP-PLATFORMS.md'));
$connection = (string) file_get_contents(repo_path('desktop/CONNECTION.md'));

test('desktop.fork.pins.upstream.commit', static function () use ($readme, $boot): void {
    $ref = '7c7daa25c66262d504e3c2b29ade0ed5928b056e';
    contains($readme, $ref, 'README must name the exact upstream commit');
    contains($boot, $ref, 'bootstrap must default to the pinned commit');
    contains($boot, 'command -v pnpm', 'bootstrap must check its toolchain');
    contains($boot, 'git -C "$BUILD" apply --check', 'bootstrap must verify the patch before applying');
    contains($boot, 'git -C "$BUILD" checkout --detach "$UPSTREAM_REF"', 'bootstrap must detach at the pin');
    contains($boot, 'git -C "$BUILD" reset --hard "$UPSTREAM_REF"', 'bootstrap must reset the worktree so re-runs are idempotent');
    contains($boot, 'scripts/build/electron-runtime.mjs', 'bootstrap must acquire the pinned Electron binary before packaging');
    contains($boot, 'dist-linux)', 'bootstrap must expose a reproducible Linux distributable build');
    contains($boot, 'pnpm run dist:linux', 'Linux distributables must use upstream dist:linux');
});

test('desktop.fork.patch.is.narrow', static function () use ($patch): void {
    contains($patch, 'a/apps/desktop/index.html', 'patch must touch the renderer title');
    contains($patch, 'a/apps/desktop/product-identity.cjs', 'patch must touch packaging identity');
    not_contains($patch, 'apps/desktop/src/', 'identity patch must not rewrite application code');
    not_contains($patch, 'token', 'identity patch must not carry credentials');
    not_contains($patch, 'password', 'identity patch must not carry credentials');
    contains($patch, "channel: null", 'fork must not consume the upstream release feed');
    contains($patch, 'com.alfires.', 'fork must use its own application identities');
});

test('desktop.fork.connection.example.has.no.secret', static function () use ($example, $boot): void {
    contains($example, 'safeStorage', 'tokens belong in Electron safeStorage');
    contains($example, '<entered-in-the-app-not-in-git>', 'example token must be a placeholder');
    not_contains($boot, 'TOKEN=', 'bootstrap must never inject a token value');
    not_contains($example, 'PANEL_ADMIN_PASSWORD', 'desktop config must not hold the panel password');
});

test('desktop.fork.no.local.gateway', static function () use ($boot, $readme): void {
    contains($boot, 'HERMES_DESKTOP_VARIANT=light', 'build must be the remote-only variant');
    contains($readme, 'does not install or start a second local agent', 'remote-only contract must be documented');
    contains($readme, 'docs/ROADMAP.md', 'remaining work must be tracked honestly');
});

test('desktop.fork.platforms.status.matrix.is.honest', static function () use ($boot, $platforms): void {
    contains($boot, '  platforms)', 'bootstrap must expose a read-only platform status command');
    contains($boot, 'Linux AppImage', 'status matrix must report the built Linux artifacts');
    contains($boot, 'Linux rpm', 'status matrix must report the rpm gap');
    contains($boot, 'Windows MSIX', 'status matrix must report the Windows gap');
    contains($boot, 'macOS dmg/zip', 'status matrix must report the macOS gap');
    contains($boot, 'BLOCKED', 'blocked targets must be named as blocked');
    contains($boot, 'pacman -S rpm-tools', 'rpm blocker must name the package that needs root');
    contains($boot, 'build-hud-modifier-monitor.mjs:36', 'Windows blocker must name the upstream guard');
    not_contains($boot, 'dist-win)', 'no Windows build mode may pretend to work here');
    not_contains($boot, 'dist-mac)', 'no macOS build mode may pretend to work here');
    contains($platforms, 'Build status matrix', 'evidence doc must carry the matrix');
    contains($platforms, 'sudo -n pacman -S --noconfirm rpm-tools', 'rpm evidence must include the exact command');
    contains($platforms, 'Build Windows packages on Windows so the HUD helper is included.', 'MSIX evidence must include the exact upstream error');
    contains($platforms, 'cannot be cross-built from Linux', 'macOS blocker must be explicit');
});

test('desktop.fork.login.bridge.invariants.documented', static function () use ($platforms, $connection): void {
    contains($platforms, 'hermes.deep_link', 'current panel link path must be described');
    contains($platforms, 'src/lib/hermes.php', 'current gateway helper must be described');
    contains($platforms, 'safeStorage', 'token storage must stay in Electron safeStorage');
    foreach ([
        '**owner-only**',
        '**one-use**',
        '**short TTL**',
        '**no token in git/logs/URLs**',
        '**no conversation relay**',
    ] as $invariant) {
        contains($platforms, $invariant, "login bridge must document invariant $invariant");
    }
    contains($platforms, 'What remains to implement', 'unimplemented work must be listed honestly');
    contains($connection, 'docs/DESKTOP-PLATFORMS.md', 'connection guide must point to the bridge design');
});
