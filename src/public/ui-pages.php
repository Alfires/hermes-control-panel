<?php
declare(strict_types=1);

namespace Deck\UiPages;

/** AI-editable page manifests.
 *
 * Wiring (api.php): require this file, then route the seven `ui.page.*` actions
 * to handle($action, Util::body(), ['owner' => true, 'actor' => 'owner']).
 * Auth::ensureOwner() at the top of api.php is the first gate; every mutation
 * here additionally requires an approved, unconsumed escalation id and owner
 * capability. Reads never return secret values (all output is deep-scrubbed).
 */

require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/util.php';
require_once dirname(__DIR__) . '/lib/config.php';
require_once dirname(__DIR__) . '/lib/registry.php';
require_once dirname(__DIR__) . '/framework/PageManifests.php';

if (!class_exists('Twig\\Environment')) {
    $autoload = dirname(__DIR__, 2) . '/vendor/autoload.php';
    if (is_file($autoload)) require_once $autoload;
}
require_once dirname(__DIR__) . '/framework/Renderer.php';

use Deck\Framework\PageManifests;
use Deck\Framework\Renderer;

/** Capabilities that may authorise a page-manifest mutation. */
const ESCALATION_CAPABILITIES = ['webui_edit', 'ui.page.edit', 'ui.page.propose', 'ui.page.approve', 'ui.page.rollback'];

/** @return array<string, array<string, mixed>> action catalogue for discovery/UI */
function actions(): array {
    return [
        'ui.page.list'     => ['method' => 'GET',  'capability' => 'panel.ui_page_list',     'mutating' => false, 'response' => ['ok' => 'boolean', 'data' => 'object']],
        'ui.page.preview'  => ['method' => 'POST', 'capability' => 'panel.ui_page_preview',  'mutating' => false, 'response' => ['ok' => 'boolean', 'data' => 'object']],
        'ui.page.propose'  => ['method' => 'POST', 'capability' => 'panel.ui_page_propose',  'mutating' => true,  'response' => ['ok' => 'boolean', 'data' => 'object']],
        'ui.page.approve'  => ['method' => 'POST', 'capability' => 'panel.ui_page_approve',  'mutating' => true,  'response' => ['ok' => 'boolean', 'data' => 'object']],
        'ui.page.rollback' => ['method' => 'POST', 'capability' => 'panel.ui_page_rollback', 'mutating' => true,  'response' => ['ok' => 'boolean', 'data' => 'object']],
        'ui.page.history'  => ['method' => 'GET',  'capability' => 'panel.ui_page_history',  'mutating' => false, 'response' => ['ok' => 'boolean', 'data' => 'object']],
    ];
}

/** Every action returns the panel-wide {ok, data} envelope. */
function handle(string $action, array $payload, array $ctx): array {
    $catalogue = actions();
    if (!isset($catalogue[$action])) return _out(false, 'unknown page action');
    $mutating = (bool) $catalogue[$action]['mutating'];

    if ($mutating) {
        $owner = _requireOwner($ctx);
        if (!$owner['ok']) return _out(false, $owner['error']);
        $esc = _checkEscalation($payload);
        if (!$esc['ok']) return _out(false, $esc['error']);
    }

    switch ($action) {
        case 'ui.page.list':
            $config = \ConfigGovernance::active();
            $manifests = PageManifests::effective($config);
            return _out(true, [
                'revision' => (int) ($config['revision'] ?? 1),
                'count' => count($manifests),
                'pages' => array_values($manifests),
            ]);

        case 'ui.page.preview':
            $candidate = _candidateManifest($payload);
            if ($candidate === null) return _out(false, 'manifest must be an object or a JSON object string');
            $report = [];
            $clean = PageManifests::validate($candidate, $report);
            if ($clean === null) return _out(false, implode('; ', $report['errors']) ?: 'invalid manifest');
            $current = _activeManifest($clean['id']);
            $html = (new Renderer())->manifest($clean, is_array($payload['data'] ?? null) ? $payload['data'] : []);
            return _out(true, [
                'id' => $clean['id'],
                'html' => $html,
                'manifest' => $clean,
                'diff' => _diff($current ?? [], $clean),
                'dropped' => $report['dropped'] ?? [],
                'rendered_from' => 'manifest',
            ]);

        case 'ui.page.history':
            $config = \ConfigGovernance::active();
            return _out(true, [
                'revision' => (int) ($config['revision'] ?? 1),
                'history' => \ConfigGovernance::history(),
                'pages' => array_keys(PageManifests::effective($config)),
            ]);

        case 'ui.page.propose':
            $candidate = _candidateManifest($payload);
            if ($candidate === null) return _out(false, 'manifest must be an object or a JSON object string');
            $report = [];
            $clean = PageManifests::validate($candidate, $report);
            if ($clean === null) return _out(false, implode('; ', $report['errors']) ?: 'invalid manifest');
            $config = \ConfigGovernance::active();
            $patches = _patchesFor($clean, $config);
            if ($patches === null) return _out(false, 'page is not in the active configuration');
            $result = \ConfigGovernance::propose($patches, 'owner');
            if (empty($result['ok'])) {
                return _out(false, implode('; ', $result['errors'] ?? [$result['error'] ?? 'proposal rejected']));
            }
            _consumeEscalation((int) $esc['escalation']['id']);
            return _out(true, [
                'revision' => (int) $result['revision'],
                'parent_revision' => (int) ($config['revision'] ?? 1),
                'hash' => (string) $result['hash'],
                'proposal_id' => (int) $result['proposal_id'],
                'state' => 'draft',
                'manifest' => $clean,
                'diff' => _diff(_activeManifest($clean['id']) ?? [], $clean),
                'dropped' => $report['dropped'] ?? [],
            ]);

        case 'ui.page.approve':
            $proposalId = (int) ($payload['proposal_id'] ?? ($payload['id'] ?? 0));
            $result = \ConfigGovernance::approve($proposalId, _actor($ctx));
            if (empty($result['ok'])) return _out(false, (string) ($result['error'] ?? 'approval failed'));
            _consumeEscalation((int) $esc['escalation']['id']);
            return _out(true, [
                'revision' => (int) $result['revision'],
                'proposal_id' => $proposalId,
                'pages' => array_values(PageManifests::effective(\ConfigGovernance::active())),
            ]);

        case 'ui.page.rollback':
            $revision = (int) ($payload['revision'] ?? 0);
            $result = \ConfigGovernance::rollback($revision, _actor($ctx));
            if (empty($result['ok'])) return _out(false, (string) ($result['error'] ?? 'rollback failed'));
            _consumeEscalation((int) $esc['escalation']['id']);
            return _out(true, [
                'revision' => (int) $result['revision'],
                'pages' => array_values(PageManifests::effective(\ConfigGovernance::active())),
            ]);
    }
    return _out(false, 'unsupported page action');
}

/** Reads are harmless, but fail closed when a caller is explicitly not an owner. */
function _requireOwner(array $ctx): array {
    if (!empty($ctx['owner']) || (string) ($ctx['actor'] ?? '') === 'owner') {
        return ['ok' => true];
    }
    return ['ok' => false, 'error' => 'owner capability required'];
}

function _actor(array $ctx): string {
    $actor = trim((string) ($ctx['actor'] ?? 'owner'));
    return $actor === '' ? 'owner' : substr($actor, 0, 60);
}

/**
 * Mutation gate. Mirrors api.php's escalation.decide / RBAC::decide pattern:
 * the escalation must exist, be approved, still have a use left and not be
 * expired. Its capability must be an edit capability. A token, when supplied,
 * is checked against the stored token_hash with hash_equals(). A denied or
 * consumed escalation can never authorise a write.
 */
function _checkEscalation(array $payload): array {
    $id = (int) ($payload['escalation_id'] ?? 0);
    if ($id <= 0) return ['ok' => false, 'error' => 'an approved escalation_id is required'];
    $row = \DB::one(
        'SELECT * FROM escalations WHERE id=? AND status=? AND uses_left>0 AND expires_at>?',
        [$id, 'approved', time()]
    );
    if (!$row) return ['ok' => false, 'error' => 'escalation is not approved, is expired or was already used'];
    if (!in_array((string) $row['capability'], ESCALATION_CAPABILITIES, true)) {
        return ['ok' => false, 'error' => 'escalation capability does not permit page edits'];
    }
    $token = (string) ($payload['escalation_token'] ?? ($payload['token'] ?? ''));
    if ($token !== '' && !empty($row['token_hash'])
        && !hash_equals((string) $row['token_hash'], hash('sha256', $token))) {
        return ['ok' => false, 'error' => 'escalation token mismatch'];
    }
    return ['ok' => true, 'escalation' => $row];
}

/** One-shot: a grant is spent by the mutation it authorised. */
function _consumeEscalation(int $id): void {
    if ($id <= 0) return;
    \DB::run('UPDATE escalations SET uses_left=uses_left-1 WHERE id=? AND uses_left>0', [$id]);
}

/** Accept a manifest object or an encoded JSON object. */
function _candidateManifest(array $payload): ?array {
    $raw = $payload['manifest'] ?? ($payload['page'] ?? null);
    if (is_string($raw)) {
        $decoded = json_decode($raw, true);
        $raw = is_array($decoded) ? $decoded : null;
    }
    return is_array($raw) ? $raw : null;
}

function _activeManifest(string $id): ?array {
    $manifests = PageManifests::effective(\ConfigGovernance::active());
    return $manifests[$id] ?? null;
}

/** Convert a validated manifest into ConfigGovernance patches (allowed root only). */
function _patchesFor(array $manifest, array $config): ?array {
    $index = null;
    foreach (($config['pages'] ?? []) as $i => $page) {
        if (is_array($page) && ($page['id'] ?? null) === $manifest['id']) { $index = $i; break; }
    }
    if ($index === null) return null;
    $page = PageManifests::toPage($manifest);
    $nav = is_array($config['pages'][$index]['nav'] ?? null) ? $config['pages'][$index]['nav'] : [];
    $nav['label'] = $manifest['title'];
    $nav['icon'] = $manifest['icon'];
    $page['nav'] = $nav;
    $base = '/pages/' . $index;
    return [
        ['op' => 'replace', 'path' => $base . '/nav', 'value' => $page['nav']],
        ['op' => 'replace', 'path' => $base . '/route', 'value' => $page['route']],
        ['op' => 'replace', 'path' => $base . '/blocks', 'value' => $page['blocks']],
        ['op' => 'replace', 'path' => $base . '/origin', 'value' => $page['origin']],
    ];
}

/** Structured + line diff of two manifests, scrubbed before it leaves PHP. */
function _diff(array $before, array $after): array {
    $b = _flatten($before);
    $a = _flatten($after);
    $changes = [];
    $lines = [];
    foreach ($b as $path => $value) {
        if (!array_key_exists($path, $a)) {
            $changes[] = ['op' => 'remove', 'path' => $path, 'before' => \Util::scrub($value), 'after' => null];
            $lines[] = "- $path: " . \Util::scrub($value);
        } elseif ($a[$path] !== $value) {
            $changes[] = ['op' => 'replace', 'path' => $path, 'before' => \Util::scrub($value), 'after' => \Util::scrub($a[$path])];
            $lines[] = "~ $path: " . \Util::scrub($value) . ' -> ' . \Util::scrub($a[$path]);
        }
    }
    foreach ($a as $path => $value) {
        if (!array_key_exists($path, $b)) {
            $changes[] = ['op' => 'add', 'path' => $path, 'before' => null, 'after' => \Util::scrub($value)];
            $lines[] = "+ $path: " . \Util::scrub($value);
        }
    }
    return ['changes' => $changes, 'lines' => $lines];
}

function _flatten(array $data, string $prefix = ''): array {
    $out = [];
    foreach ($data as $key => $value) {
        $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
        if (is_array($value)) $out += _flatten($value, $path);
        else $out[$path] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
    }
    return $out;
}

/** Metadata keys that can never carry a user-supplied secret; preserving them
 *  keeps hash-chained audit fields useful after redaction. */
const SAFE_METADATA_KEYS = ['hash', 'revision', 'parent_revision', 'proposal_id', 'id', 'version', 'count', 'state', 'op', 'path'];

/** Final defence: nothing free-form in a response may carry a credential value. */
function _out(bool $ok, array|string $data): array {
    if (!$ok) return ['ok' => false, 'data' => ['error' => \Util::scrub(is_string($data) ? $data : (string) ($data['error'] ?? 'request refused'))]];
    return ['ok' => true, 'data' => _scrubData($data)];
}

function _scrubData(mixed $value, ?string $key = null): mixed {
    if ($key !== null && in_array($key, SAFE_METADATA_KEYS, true) && is_scalar($value)) return $value;
    if (is_string($value)) return \Util::scrub($value);
    if (is_array($value)) {
        $out = [];
        foreach ($value as $k => $child) $out[$k] = _scrubData($child, (string) $k);
        return $out;
    }
    return $value;
}
