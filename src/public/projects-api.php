<?php
declare(strict_types=1);

/**
 * Phase 4 project API. Owned by api.php: this file only declares the catalog
 * and the handler. Every action is owner-gated and every response is curated.
 *
 * Wiring in src/public/api.php (before the final `default:` case):
 *
 *   case 'project.create':
 *   case 'project.list':
 *   case 'project.get':
 *   case 'project.git.status':
 *   case 'project.git.log':
 *   case 'project.git.diff':
 *   case 'project.open':
 *       Auth::require();
 *       require_once __DIR__ . '/projects-api.php';
 *       $result = \Deck\Projects\handle($action, $b, ['owner' => true]);
 *       Util::json($result, empty($result['ok']) ? (int)($result['status'] ?? 400) : 200);
 *
 * NOTE: the `status` key is an internal HTTP hint and is harmless in the JSON
 * body; the contract is {ok:bool, data:...} on success, {ok:false, error:...}
 * on failure. No secret value is ever part of a response.
 */

namespace Deck\Projects;

require_once dirname(__DIR__) . '/lib/util.php';
require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/auth.php';
require_once dirname(__DIR__) . '/lib/vault.php';
require_once dirname(__DIR__) . '/lib/hostctl.php';
require_once dirname(__DIR__) . '/lib/forgejo.php';
require_once dirname(__DIR__) . '/lib/projects.php';

/** Declarative catalog, same shape as PanelActions::catalog(). */
function actions(): array {
    $names = [
        'project.create'      => 'POST',
        'project.list'        => 'GET',
        'project.get'         => 'GET',
        'project.git.status'  => 'GET',
        'project.git.log'     => 'GET',
        'project.git.diff'    => 'GET',
        'project.open'        => 'GET',
    ];
    $out = [];
    $id = 0;
    foreach ($names as $name => $method) {
        $out[$name] = [
            'method'     => $method,
            'capability' => 'panel.' . str_replace('.', '_', $name),
            'response'   => ['ok' => 'boolean', 'data' => 'object'],
            'owner_only' => true,
            'id'         => ++$id,
        ];
    }
    return $out;
}

function fail(string $error, int $status = 400): array {
    return ['ok' => false, 'error' => \Forgejo::safeError($error), 'status' => $status];
}

function ok(array $data): array {
    return ['ok' => true, 'data' => $data];
}

function ownerAllowed(array $ctx): bool {
    if (array_key_exists('owner', $ctx)) return (bool)$ctx['owner'];
    if (class_exists('\Auth')) {
        try { return \Auth::current() !== null; } catch (\Throwable $e) { return false; }
    }
    return false;
}

function findProject(mixed $raw): ?array {
    $id = is_scalar($raw) ? (int)$raw : 0;
    return $id > 0 ? \ProjectModel::get($id) : null;
}

/** Entry point used by api.php. $ctx must assert the owner capability. */
function handle(string $action, array $payload, array $ctx): array {
    if (!isset(actions()[$action])) return fail('unknown project action', 404);
    if (!ownerAllowed($ctx)) return fail('owner capability required', 403);
    // Internal seam for hermetic tests only; api.php never passes this key and
    // no HTTP payload can reach it (the create input is explicitly filtered).
    $deps = is_array($ctx['deps'] ?? null) ? $ctx['deps'] : [];

    switch ($action) {
        case 'project.create':
            $input = [
                'name'       => \Util::str($payload['name'] ?? '', 100),
                'visibility' => \Util::str($payload['visibility'] ?? 'private', 20),
                'owner'      => \Util::str($payload['owner'] ?? 'owner', 64),
            ];
            if (array_key_exists('local_path', $payload)) {
                $input['local_path'] = \Util::str($payload['local_path'], 200);
            }
            $result = \ProjectModel::create($input, $deps);
            if (empty($result['ok'])) return fail($result['error'] ?? 'project.create failed');
            \DB::audit('owner', 'project.create', \Util::str($input['name'], 100));
            return ok($result['data']);

        case 'project.list':
            return ok(['projects' => \ProjectModel::all()]);

        case 'project.get':
            $row = findProject($payload['id'] ?? 0);
            if (!$row) return fail('no such project', 404);
            return ok(['project' => \ProjectModel::describe($row)]);

        case 'project.git.status':
        case 'project.git.log':
        case 'project.git.diff':
            $row = findProject($payload['id'] ?? 0);
            if (!$row) return fail('no such project', 404);
            $op = substr($action, strlen('project.git.'));
            $result = \ProjectModel::git($op, (int)$row['id'],
                ['limit' => (int)($payload['limit'] ?? 10)], $deps);
            if (empty($result['ok'])) return fail($result['error'] ?? 'git read failed');
            return ok($result['data']);

        case 'project.open':
            $row = findProject($payload['id'] ?? 0);
            if (!$row) return fail('no such project', 404);
            $project = \ProjectModel::describe($row);
            $absolute = \ProjectModel::resolveUnderRoot($project['local_path']);
            if ($absolute === null) return fail('stored project path is invalid');
            $webUrl = \Forgejo::safeUrl(\Forgejo::baseUrl() . '/' . $project['forgejo_repo']);
            \DB::audit('owner', 'project.open', 'id=' . $project['id']);
            return ok([
                'project'   => $project,
                'web_url'   => $webUrl,
                'available' => is_dir($absolute),
            ]);
    }
    return fail('unknown project action', 404);
}
