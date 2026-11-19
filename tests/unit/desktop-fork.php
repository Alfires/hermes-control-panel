<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';

// The desktop client is a pinned, patched fork of the genuine Hermes Desktop.
// These checks keep the pin explicit, the patch narrow and secrets out of git.
$readme = (string) file_get_contents(repo_path('desktop/README.md'));
$boot = (string) file_get_contents(repo_path('desktop/bootstrap.sh'));
$patch = (string) file_get_contents(repo_path('desktop/patches/deck-identity.patch'));
$example = (string) file_get_contents(repo_path('desktop/connection.json.example'));

test('desktop.fork.pins.upstream.commit', static function () use ($readme, $boot): void {
    $ref = '7c7daa25c66262d504e3c2b29ade0ed5928b056e';
    contains($readme, $ref, 'README must name the exact upstream commit');
    contains($boot, $ref, 'bootstrap must default to the pinned commit');
    contains($boot, 'command -v pnpm', 'bootstrap must check its toolchain');
    contains($boot, 'git -C "$BUILD" apply --check', 'bootstrap must verify the patch before applying');
    contains($boot, 'git -C "$BUILD" checkout --detach "$UPSTREAM_REF"', 'bootstrap must detach at the pin');
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
