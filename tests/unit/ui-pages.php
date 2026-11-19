<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap.php';
require_once repo_path('vendor/autoload.php');
lib('db.php');
lib('config.php');
lib('rbac.php');
require_once repo_path('src/public/ui-pages.php');

use Deck\Framework\PageManifests;
use Deck\Framework\Renderer;

/** Fresh isolated SQLite DB per case (same pattern as tests/config/governance.php). */
function uip_fresh_db(): void {
    $path = tempnam(sys_get_temp_dir(), 'hcp-uip-') . '.sqlite';
    @unlink($path);
    putenv('PANEL_DB=' . $path);
    $_ENV['PANEL_DB'] = $path;
    \DB::init();
}

function uip_ctx(): array {
    return ['owner' => true, 'actor' => 'owner', 'authenticated' => true];
}

function uip_agent(): void {
    \DB::run('INSERT OR IGNORE INTO agents(id,slug,name,role,created_at) VALUES(1,?,?,?,?)',
        ['webui-test', 'Web UI', 'Editor', time()]);
}

function uip_escalation(string $token = 'tok', string $capability = 'webui_edit', int $uses = 5): int {
    uip_agent();
    \DB::run('INSERT INTO escalations(agent_id,capability,reason,status,ttl_seconds,decided_at,expires_at,token_hash,uses_left,created_at)
              VALUES(1,?,?,?,?,?,?,?,?,?)', [
        $capability, 'unit test', 'approved', 600, time(), time() + 600,
        hash('sha256', $token), $uses, time(),
    ]);
    return (int) \DB::pdo()->lastInsertId();
}

function uip_denied_escalation(): int {
    uip_agent();
    \DB::run('INSERT INTO escalations(agent_id,capability,reason,status,ttl_seconds,created_at)
              VALUES(1,?,?,?,?,?)', ['webui_edit', 'unit test', 'pending', 600, time()]);
    $id = (int) \DB::pdo()->lastInsertId();
    ok(\RBAC::decide($id, false) === true, 'RBAC::decide(deny) failed');
    return $id;
}

function uip_active_title(string $id): string {
    $r = \Deck\UiPages\handle('ui.page.list', [], uip_ctx());
    ok($r['ok'] === true, 'ui.page.list failed');
    foreach ($r['data']['pages'] as $page) if ($page['id'] === $id) return (string) $page['title'];
    return '';
}

test('ui.page.actions.catalogue.is.complete', static function (): void {
    $actions = \Deck\UiPages\actions();
    eq(count($actions), 6, 'expected six ui.page actions');
    foreach (['ui.page.list', 'ui.page.preview', 'ui.page.propose', 'ui.page.approve', 'ui.page.rollback', 'ui.page.history'] as $action) {
        ok(isset($actions[$action]), "missing $action");
        ok(isset($actions[$action]['method'], $actions[$action]['capability'], $actions[$action]['response']), "$action has no catalogue entry");
    }
    eq($actions['ui.page.propose']['mutating'], true, 'propose must be marked mutating');
    eq($actions['ui.page.list']['mutating'], false, 'list must be read-only');
});

test('ui.manifest.defaults.cover.every.registered.page', static function (): void {
    $manifests = PageManifests::defaults();
    foreach (\PageRegistry::ids() as $id) {
        ok(isset($manifests[$id]), "no shipped manifest for registered page $id");
        $report = [];
        ok(PageManifests::validate($manifests[$id], $report) !== null, "manifest $id failed validation: " . implode(';', $report['errors']));
    }
    count_at_least(count($manifests), 13, 'default manifest set is incomplete');
});

test('ui.manifest.valid.renders.full.page.shell', static function (): void {
    $manifests = PageManifests::defaults();
    $html = (new Renderer())->page('overview', ['manifests' => $manifests]);
    contains($html, 'data-block="hero"', 'manifest hero block missing');
    contains($html, 'Live status, pending approvals', 'manifest-specific content missing');
    contains($html, 'data-theme=', 'page shell theme missing');
    contains($html, '/vendor/htmx-2.0.11.min.js', 'vendored htmx missing');
    not_contains($html, '<script>', 'inline script injected');
    not_contains($html, 'https://cdn.', 'framework must not use a CDN');

    $direct = (new Renderer())->manifest($manifests['vault']);
    contains($direct, 'data-block="hero"', 'Renderer::manifest() failed');
    contains($direct, 'Secret names, never values', 'vault manifest content missing');
    not_contains($direct, 'not registered', 'valid manifest used the stub fallback');
});

test('ui.manifest.every.default.page.renders', static function (): void {
    $manifests = PageManifests::defaults();
    $renderer = new Renderer();
    foreach ($manifests as $id => $manifest) {
        $html = $renderer->page($id, ['manifests' => $manifests]);
        contains($html, 'data-block="', "page $id rendered no blocks");
        not_contains($html, 'Page not registered', "page $id used the missing-page stub");
        not_contains($html, '<script>', "page $id injected an inline script");
    }
});

test('ui.manifest.unknown.block.and.source.dropped', static function (): void {
    $manifest = PageManifests::defaults()['overview'];
    $originalCount = count($manifest['blocks']);
    $manifest['blocks'][] = ['instance' => 'evil', 'type' => 'evilBlock', 'source' => 'panel.bootstrap',
        'props' => ['note' => 'SHOULD-NOT-RENDER']];
    $manifest['blocks'][] = ['instance' => 'leaky', 'type' => 'hero', 'source' => 'hermes.env.reveal',
        'props' => ['title' => 'SHOULD-NOT-RENDER-EITHER']];

    $report = [];
    $clean = PageManifests::validate($manifest, $report);
    ok($clean !== null, 'valid manifest rejected after adding bad blocks');
    eq(count($clean['blocks']), $originalCount, 'unknown block/source was not dropped');
    eq(count($report['dropped']), 2, 'drop report is incomplete');
    ok(!PageManifests::isValidSource('hermes.env.reveal'), 'forbidden Hermes source accepted');

    $html = (new Renderer())->manifest($clean);
    not_contains($html, 'SHOULD-NOT-RENDER', 'unknown block was rendered');
    not_contains($html, 'SHOULD-NOT-RENDER-EITHER', 'forbidden source block was rendered');
});

test('ui.manifest.script.css.and.import.injection.rejected', static function (): void {
    $base = PageManifests::defaults()['chats'];
    $variants = [];

    $v = $base; $v['title'] = '<script>alert(1)</script>'; $variants[] = $v;
    $v = $base; $v['blocks'][0]['props']['title'] = 'x</style><style>body{display:none}'; $variants[] = $v;
    $v = $base; $v['blocks'][0]['props']['subtitle'] = '@import url(https://evil.invalid/x.css)'; $variants[] = $v;
    $v = $base; $v['script'] = 'alert(1)'; $variants[] = $v;
    $v = $base; $v['blocks'][0]['style'] = 'color:red'; $variants[] = $v;
    $v = $base; $v['blocks'][0]['type'] = 'hero'; $v['blocks'][0]['props']['action'] = 'javascript:alert(1)'; $variants[] = $v;

    foreach ($variants as $i => $bad) {
        $report = [];
        ok(PageManifests::validate($bad, $report) === null, "injection variant $i was accepted");
        ok(count($report['errors']) > 0, "injection variant $i has no error report");
    }

    uip_fresh_db();
    $r = \Deck\UiPages\handle('ui.page.preview', ['manifest' => $variants[0]], uip_ctx());
    ok($r['ok'] === false, 'preview accepted an injected manifest');
    not_contains(json_encode($r), 'alert(1)', 'preview echoed the injected script');
});

test('ui.page.list.returns.every.registered.page', static function (): void {
    uip_fresh_db();
    $r = \Deck\UiPages\handle('ui.page.list', [], uip_ctx());
    ok($r['ok'] === true, 'ui.page.list failed');
    count_at_least($r['data']['count'], 13, 'ui.page.list lost pages');
    $ids = array_column($r['data']['pages'], 'id');
    eq(count(array_unique($ids)), count($ids), 'duplicate page ids in list');
    foreach (\PageRegistry::ids() as $id) ok(in_array($id, $ids, true), "page $id missing from list");
    not_contains(json_encode($r), fake_secret(1), 'list leaked a secret');
});

test('ui.page.preview.renders.html.and.structured.diff.without.writing', static function (): void {
    uip_fresh_db();
    \ConfigGovernance::active(); // seed the initial revision before counting
    $before = count(\ConfigGovernance::history());
    $manifest = PageManifests::defaults()['agents'];
    $manifest['title'] = 'Agent Studio';
    $r = \Deck\UiPages\handle('ui.page.preview', ['manifest' => $manifest], uip_ctx());

    ok($r['ok'] === true, 'preview failed: ' . json_encode($r['data']));
    contains($r['data']['html'], 'Agent Studio', 'preview did not render candidate title');
    contains($r['data']['html'], 'data-block="hero"', 'preview is not a page shell');
    ok(isset($r['data']['diff']['changes'], $r['data']['diff']['lines']), 'preview diff is not structured');
    $found = false;
    foreach ($r['data']['diff']['changes'] as $change) {
        if (($change['path'] ?? '') === 'title') {
            eq($change['before'], 'Agents', 'diff before is wrong');
            eq($change['after'], 'Agent Studio', 'diff after is wrong');
            $found = true;
        }
    }
    ok($found, 'no title change in preview diff');
    eq(count(\ConfigGovernance::history()), $before, 'preview wrote a revision');
});

test('ui.page.denied.escalation.blocks.apply', static function (): void {
    uip_fresh_db();
    $denied = uip_denied_escalation();
    $revBefore = \ConfigGovernance::currentRevision();
    $manifest = PageManifests::defaults()['overview'];

    $r = \Deck\UiPages\handle('ui.page.propose', ['manifest' => $manifest, 'escalation_id' => $denied], uip_ctx());
    ok($r['ok'] === false, 'denied escalation authorised a proposal');
    contains($r['data']['error'], 'escalation', 'unexpected denial error');
    eq(\ConfigGovernance::currentRevision(), $revBefore, 'denied escalation wrote a revision');

    $missing = \Deck\UiPages\handle('ui.page.propose', ['manifest' => $manifest], uip_ctx());
    ok($missing['ok'] === false, 'proposal without escalation was accepted');

    $approved = uip_escalation('good-token');
    $wrong = \Deck\UiPages\handle('ui.page.propose', [
        'manifest' => $manifest, 'escalation_id' => $approved, 'escalation_token' => 'wrong-token',
    ], uip_ctx());
    ok($wrong['ok'] === false, 'token mismatch was accepted');
    eq(\ConfigGovernance::currentRevision(), $revBefore, 'token mismatch wrote a revision');
});

test('ui.page.owner.capability.required.for.mutations', static function (): void {
    uip_fresh_db();
    \ConfigGovernance::active(); // seed the initial revision before counting
    $esc = uip_escalation();
    $manifest = PageManifests::defaults()['overview'];
    $r = \Deck\UiPages\handle('ui.page.propose', [
        'manifest' => $manifest, 'escalation_id' => $esc, 'escalation_token' => 'tok',
    ], ['owner' => false, 'actor' => 'agent:webui']);
    ok($r['ok'] === false, 'non-owner mutated pages');
    contains($r['data']['error'], 'owner', 'unexpected capability error');
    eq(count(\ConfigGovernance::history()), 1, 'non-owner wrote a revision');
});

test('ui.page.approve.and.rollback.restores.manifest', static function (): void {
    uip_fresh_db();
    $originalRevision = \ConfigGovernance::currentRevision();
    eq(uip_active_title('vault'), 'Vault', 'wrong starting title');

    $manifest = PageManifests::defaults()['vault'];
    $manifest['version'] = 3;
    $manifest['title'] = 'Vault Names Only';

    $e1 = uip_escalation('tok-1');
    $proposal = \Deck\UiPages\handle('ui.page.propose', [
        'manifest' => $manifest, 'escalation_id' => $e1, 'escalation_token' => 'tok-1',
    ], uip_ctx());
    ok($proposal['ok'] === true, 'proposal failed: ' . json_encode($proposal['data']));
    eq(uip_active_title('vault'), 'Vault', 'draft changed the active page');
    ok($proposal['data']['revision'] > $originalRevision, 'draft revision was not appended');
    ok(is_string($proposal['data']['hash']) && $proposal['data']['hash'] !== '', 'proposal has no revision hash');

    $e2 = uip_escalation('tok-2');
    $approved = \Deck\UiPages\handle('ui.page.approve', [
        'proposal_id' => $proposal['data']['proposal_id'], 'escalation_id' => $e2, 'escalation_token' => 'tok-2',
    ], uip_ctx());
    ok($approved['ok'] === true, 'approve failed: ' . json_encode($approved['data']));
    eq(uip_active_title('vault'), 'Vault Names Only', 'approved title was not applied');

    $e3 = uip_escalation('tok-3');
    $rolled = \Deck\UiPages\handle('ui.page.rollback', [
        'revision' => $originalRevision, 'escalation_id' => $e3, 'escalation_token' => 'tok-3',
    ], uip_ctx());
    ok($rolled['ok'] === true, 'rollback failed: ' . json_encode($rolled['data']));
    eq(uip_active_title('vault'), 'Vault', 'rollback did not restore the original manifest');

    $history = \Deck\UiPages\handle('ui.page.history', [], uip_ctx());
    ok($history['ok'] === true, 'history failed');
    count_at_least(count($history['data']['history']), 2, 'history lost revisions');
    ok(\DB::verifyAuditChain(), 'audit hash chain broken by page mutations');
});

test('ui.page.escalation.is.one.shot', static function (): void {
    uip_fresh_db();
    $esc = uip_escalation('once', 'webui_edit', 1);
    $manifest = PageManifests::defaults()['overview'];
    $args = ['manifest' => $manifest, 'escalation_id' => $esc, 'escalation_token' => 'once'];
    $first = \Deck\UiPages\handle('ui.page.propose', $args, uip_ctx());
    ok($first['ok'] === true, 'first use of escalation failed');
    $second = \Deck\UiPages\handle('ui.page.propose', $args, uip_ctx());
    ok($second['ok'] === false, 'one-shot escalation was reused');
});

test('ui.page.responses.never.leak.secret.values', static function (): void {
    uip_fresh_db();
    $secret = fake_secret(1);
    $ctx = ['owner' => true, 'actor' => 'owner', 'authorization' => 'Bearer ' . $secret, 'secret' => $secret];
    $esc = uip_escalation('tok');

    $manifest = PageManifests::defaults()['chats'];
    $manifest['title'] = $secret; // credential-shaped content must be refused AND scrubbed everywhere
    $responses = [
        \Deck\UiPages\handle('ui.page.list', [], $ctx),
        \Deck\UiPages\handle('ui.page.preview', ['manifest' => $manifest], $ctx),
        \Deck\UiPages\handle('ui.page.propose', ['manifest' => $manifest, 'escalation_id' => $esc, 'escalation_token' => 'tok'], $ctx),
        \Deck\UiPages\handle('ui.page.history', [], $ctx),
    ];
    $json = json_encode($responses);
    not_contains($json, $secret, 'a page response leaked a secret value');
    not_contains($json, 'Bearer ' . $secret, 'a page response echoed a bearer token');
    ok($responses[2]['ok'] === false, 'credential-shaped manifest was accepted');
    contains($responses[2]['data']['error'], 'credential-like', 'wrong rejection reason');

    // The output envelope itself is the last line of defence.
    $scrubbed = \Deck\UiPages\_out(true, ['note' => $secret]);
    contains(json_encode($scrubbed), 'CENSORED', 'response envelope did not scrub a secret');
    not_contains(json_encode($scrubbed), $secret, 'response envelope leaked a secret');
});

// Registers cases for tests/suite.php; executes them when run on its own.
if (basename(__FILE__) === basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''))) {
    exit(run_tests());
}
