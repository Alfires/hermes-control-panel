<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
lib('db.php');
lib('hostctl.php');
lib('forgejo.php');
lib('projects.php');
require_once repo_path('src/public/projects-api.php');

// Dedicated DB so these tests never touch the panel's real data.
$projDb = tempnam(sys_get_temp_dir(), 'hcp-projects-') . '.sqlite';
@unlink($projDb);
$_ENV['PANEL_DB'] = $projDb;
putenv('PANEL_DB=' . $projDb);
DB::init();
ProjectModel::migrate();
register_shutdown_function(static function () use ($projDb): void { @unlink($projDb); });

function proj_temp_db(callable $fn): mixed {
    $prev = getenv('PANEL_DB');
    $path = tempnam(sys_get_temp_dir(), 'hcp-proj-tmp-') . '.sqlite';
    @unlink($path);
    $_ENV['PANEL_DB'] = $path;
    putenv('PANEL_DB=' . $path);
    DB::init();
    try {
        return $fn();
    } finally {
        $previous = is_string($prev) && $prev !== '' ? $prev : null;
        if ($previous === null) { putenv('PANEL_DB'); unset($_ENV['PANEL_DB']); }
        else { putenv('PANEL_DB=' . $previous); $_ENV['PANEL_DB'] = $previous; }
        DB::init();
        @unlink($path);
    }
}

function proj_rmtree(string $dir): void {
    if (!is_dir($dir)) return;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $entry) {
        $path = $entry->getPathname();
        if ($entry->isDir()) @rmdir($path); else @unlink($path);
    }
    @rmdir($dir);
}

function proj_fake_forgejo(?array &$calls = null): object {
    $calls = $calls ?? [];
    return new class($calls) {
        public array $calls = [];
        public function __construct(array &$calls) { $this->calls =& $calls; }
        public function createRepo(string $name, bool $private, string $description = ''): array {
            $this->calls[] = ['name' => $name, 'private' => $private];
            return ['ok' => true, 'created' => true, 'repo' => [
                'id' => 42, 'name' => $name, 'full_name' => 'Alfires/' . $name,
                'clone_url' => 'http://192.168.2.157:3001/Alfires/' . $name . '.git',
                'html_url' => 'http://192.168.2.157:3001/Alfires/' . $name,
                'private' => $private, 'default_branch' => 'main',
            ]];
        }
    };
}

function proj_recording_git(?array &$argvLog = null): GitRunner {
    $argvLog = $argvLog ?? [];
    return new GitRunner(static function (array $argv) use (&$argvLog): array {
        $argvLog[] = $argv;
        // A real git clone creates the target; fake it for the first-commit step.
        if (($argv[1] ?? '') === 'clone' && isset($argv[6]) && !is_dir($argv[6])) {
            @mkdir($argv[6], 0777, true);
        }
        return ['ok' => true, 'code' => 0, 'out' => '', 'err' => ''];
    });
}

test('projects.visibility.validation', static function (): void {
    foreach (ProjectModel::VISIBILITIES as $v) {
        ok(ProjectModel::validVisibility($v), "$v must be accepted");
    }
    foreach (['public', 'PRIVATE', 'Main', '', 'secret', 'private '] as $v) {
        ok(!ProjectModel::validVisibility($v), "'$v' must be rejected");
    }
    $r = ProjectModel::insert(['name' => 'vis-test', 'visibility' => 'public']);
    ok(empty($r['ok']), 'insert refuses an unknown visibility');
});

test('projects.name.validation', static function (): void {
    foreach (['alpha', 'a-b_c.2', 'z' . str_repeat('x', 98)] as $n) {
        ok(ProjectModel::validName($n), "'$n' should be a valid name");
    }
    foreach (['', '.', '..', 'a/b', 'a\\b', 'a..b', 'A B', 'repo.git', '-bad', 'a b',
              str_repeat('x', 101)] as $n) {
        ok(!ProjectModel::validName($n), "'$n' must be rejected");
        ok(!Forgejo::validRepoName($n), "Forgejo must also reject '$n'");
    }
});

test('projects.path.traversal.rejected', static function (): void {
    $bad = ['../etc/passwd', '/etc/passwd', 'a/../../b', 'foo/..', 'foo/./bar',
            'foo//bar', '..\\windows\\system32', 'C:/Windows', '%2e%2e/x', '.', '',
            'a/b/c/d/e/f/g/h/i', "a\0b"];
    foreach ($bad as $p) {
        ok(ProjectModel::safeRelativePath($p) === null, "path must be rejected: $p");
    }
    eq(ProjectModel::safeRelativePath('my-repo'), 'my-repo');
    eq(ProjectModel::safeRelativePath('apps/my.repo-2'), 'apps/my.repo-2');
});

test('projects.path.resolve.stays.under.root', static function (): void {
    $root = sys_get_temp_dir() . '/hcp-proj-root-' . getmypid();
    proj_rmtree($root);
    mkdir($root . '/apps', 0777, true);
    try {
        eq(ProjectModel::resolveUnderRoot('apps/demo', $root), $root . '/apps/demo');
        ok(ProjectModel::resolveUnderRoot('../../etc', $root) === null, 'traversal must not resolve');
        $outside = sys_get_temp_dir() . '/hcp-proj-outside-' . getmypid();
        mkdir($outside, 0777, true);
        if (@symlink($outside, $root . '/escape')) {
            ok(ProjectModel::resolveUnderRoot('escape/secret', $root) === null,
               'symlink pointing outside the workspace must be refused');
            @unlink($root . '/escape');
        }
        proj_rmtree($outside);
    } finally {
        proj_rmtree($root);
    }
});

test('projects.model.persistence', static function (): void {
    $r = ProjectModel::insert([
        'name' => 'persist-test', 'visibility' => 'main',
        'forgejo_repo' => 'Alfires/persist-test', 'local_path' => 'persist-test', 'owner' => 'owner',
    ]);
    ok(!empty($r['ok']), 'insert succeeds: ' . json_encode($r));
    $id = (int)$r['id'];
    $row = ProjectModel::get($id);
    ok($row !== null, 'row can be read back');
    eq($row['visibility'], 'main');
    eq($row['forgejo_repo'], 'Alfires/persist-test');
    eq($row['owner'], 'owner');
    ok((int)$row['created_at'] > 0, 'created_at recorded');
    $listed = ProjectModel::all();
    ok(count(array_filter($listed, static fn(array $p): bool => $p['id'] === $id)) === 1,
       'project appears exactly once in list');
    eq(array_keys(ProjectModel::describe($row)),
       ['id', 'name', 'visibility', 'forgejo_repo', 'local_path', 'owner', 'created_at'],
       'describe() exposes only the seven documented fields');
    ok(!ProjectModel::validOwner('../root'), 'owner field validates');
});

test('projects.model.migration.safe', static function (): void {
    proj_temp_db(static function (): void {
        DB::run('CREATE TABLE projects (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL UNIQUE)');
        ProjectModel::migrate();
        ProjectModel::migrate();
        $cols = array_column(DB::all('PRAGMA table_info(projects)'), 'name');
        foreach (['id', 'name', 'visibility', 'forgejo_repo', 'local_path', 'owner', 'created_at'] as $c) {
            ok(in_array($c, $cols, true), "migration adds $c");
        }
    });
});

test('projects.create.happy.path.is.scoped', static function (): void {
    proj_temp_db(static function (): void {
        $root = sys_get_temp_dir() . '/hcp-proj-create-' . getmypid();
        proj_rmtree($root);
        mkdir($root, 0777, true);
        try {
            $calls = [];
            $argvLog = [];
            $result = ProjectModel::create(
                ['name' => 'created-demo', 'visibility' => 'main', 'owner' => 'owner'],
                ['forgejo' => proj_fake_forgejo($calls), 'git' => proj_recording_git($argvLog), 'root' => $root]
            );
            ok(!empty($result['ok']), 'create succeeds: ' . json_encode($result));
            eq($calls[0]['private'], false, 'main visibility maps to private=false');
            eq($calls[0]['name'], 'created-demo');
            $project = $result['data']['project'];
            eq($project['forgejo_repo'], 'Alfires/created-demo');
            eq($project['local_path'], 'created-demo');
            ok(str_starts_with($argvLog[0][5] ?? '', 'http'), 'clone URL is an http URL');
            eq($argvLog[0][1], 'clone');
            eq($argvLog[0][4], '--', 'clone uses -- before operands');
            ok(str_starts_with($argvLog[0][6] ?? '', $root . '/'), 'clone target stays under workspace root');
            $sawCommit = false;
            foreach ($argvLog as $cmd) {
                if (in_array('commit', $cmd, true)) $sawCommit = true;
            }
            ok($sawCommit, 'first commit issued');
            ok(in_array('Initial commit from Hermes Control Deck', end($argvLog), true),
               'commit message is the fixed curated string');
            ok(is_file($root . '/created-demo/README.md'), 'first-commit file written');
            not_contains((string) file_get_contents($root . '/created-demo/README.md'), '{{secret}}',
                         'README contains no secret placeholder');
            ok(ProjectModel::get((int)$project['id']) !== null, 'metadata persisted');
        } finally {
            proj_rmtree($root);
        }
    });
});

test('projects.create.rejects.bad.input.before.network', static function (): void {
    $calls = 0;
    $forgejo = new class($calls) {
        public int $calls = 0;
        public function __construct(public int &$counter) {}
        public function createRepo(string $n, bool $p, string $d = ''): array {
            $this->counter++;
            return ['ok' => true, 'repo' => []];
        }
    };
    foreach ([
        ['name' => 'bad/name', 'visibility' => 'private'],
        ['name' => 'okay-name', 'visibility' => 'public'],
        ['name' => 'okay-name', 'visibility' => 'private', 'local_path' => '../../etc'],
        ['name' => 'okay-name', 'visibility' => 'private', 'owner' => '../root'],
    ] as $input) {
        $r = ProjectModel::create($input, ['forgejo' => $forgejo, 'root' => '/tmp']);
        ok(empty($r['ok']), 'invalid create input refused: ' . json_encode($input));
    }
    eq($forgejo->calls, 0, 'no Forgejo call for invalid input');
});

test('projects.git.status.is.curated', static function (): void {
    $git = new GitRunner(static fn(array $argv): array => [
        'ok' => true, 'code' => 0, 'err' => '',
        'out' => "## main...origin/main\n M src/lib/forgejo.php\n?? TEST-SECRET-DO-NOT-USE-1-abcXYZ123\n",
    ]);
    $r = $git->run('status', ['cwd' => '/tmp']);
    ok(!empty($r['ok']), 'status succeeds');
    eq($r['data']['branch'], 'main');
    eq($r['data']['clean'], false);
    not_contains(json_encode($r), 'TEST-SECRET-DO-NOT-USE', 'secret marker scrubbed from status');
    ok(!array_key_exists('patch', $r['data']) && !array_key_exists('contents', $r['data']),
       'no file contents in status');
});

test('projects.git.log.keeps.short.hash.only', static function (): void {
    $git = new GitRunner(static fn(array $argv): array => [
        'ok' => true, 'code' => 0, 'err' => '',
        'out' => "a1b2c3\x1fInitial commit\x1f2026-11-19 10:00:00 +0000\n"
               . "deadbeef\x1fpassword=hunter2secret1234567890abcdefgh\x1f2026-11-18 09:00:00 +0000\n",
    ]);
    $r = $git->run('log', ['cwd' => '/tmp', 'limit' => 5]);
    ok(!empty($r['ok']), 'log succeeds');
    eq(count($r['data']['commits']), 2);
    eq($r['data']['commits'][0]['hash'], 'a1b2c3');
    not_contains(json_encode($r), 'hunter2secret', 'credential-shaped subject is scrubbed');
});

test('projects.git.diff.never.returns.patches', static function (): void {
    $git = new GitRunner(static fn(array $argv): array => [
        'ok' => true, 'code' => 0, 'err' => '',
        'out' => " README.md | 4 ++++\n 1 file changed, 4 insertions(+)\n"
               . "diff --git a/.env b/.env\n+api_key=TEST-SECRET-DO-NOT-USE-2-abcXYZ123\n",
    ]);
    $r = $git->run('diff', ['cwd' => '/tmp']);
    ok(!empty($r['ok']), 'diff succeeds');
    eq($r['data']['stat'], [' README.md | 4 ++++', ' 1 file changed, 4 insertions(+)']);
    not_contains(json_encode($r), 'api_key=', 'no patch line reaches the response');
    not_contains(json_encode($r), 'TEST-SECRET-DO-NOT-USE', 'no secret marker reaches the response');
});

test('projects.actions.catalog.is.complete', static function (): void {
    $actions = \Deck\Projects\actions();
    eq(count($actions), 7);
    foreach (['project.create', 'project.list', 'project.get', 'project.git.status',
              'project.git.log', 'project.git.diff', 'project.open'] as $name) {
        ok(isset($actions[$name]), "catalog contains $name");
        eq($actions[$name]['owner_only'], true, "$name is owner-only");
        eq($actions[$name]['method'], $name === 'project.create' ? 'POST' : 'GET');
        contains($actions[$name]['capability'], 'panel.project_', 'capability namespace');
    }
    not_contains(json_encode($actions), 'token', 'catalog has no token field');
    not_contains(json_encode($actions), 'secret', 'catalog has no secret field');
});

test('projects.handle.owner.gate', static function (): void {
    $denied = \Deck\Projects\handle('project.list', [], []);
    ok(empty($denied['ok']) && $denied['status'] === 403, 'non-owner is refused');
    $unknown = \Deck\Projects\handle('project.nope', [], ['owner' => true]);
    ok(empty($unknown['ok']) && $unknown['status'] === 404, 'unknown action refused');
    $list = \Deck\Projects\handle('project.list', [], ['owner' => true]);
    ok(!empty($list['ok']) && is_array($list['data']['projects']), 'owner can list');
    $insert = ProjectModel::insert(['name' => 'api-test', 'visibility' => 'private',
        'forgejo_repo' => 'Alfires/api-test', 'local_path' => 'api-test']);
    $got = \Deck\Projects\handle('project.get', ['id' => $insert['id']], ['owner' => true]);
    ok(!empty($got['ok']) && $got['data']['project']['name'] === 'api-test', 'owner can get');
    eq(array_keys($got), ['ok', 'data'], 'success response has only ok/data');
});

test('projects.handle.git.reads.use.project.id', static function (): void {
    $insert = ProjectModel::insert(['name' => 'git-api-test', 'visibility' => 'private',
        'forgejo_repo' => 'Alfires/git-api-test', 'local_path' => 'git-api-test']);
    $root = sys_get_temp_dir() . '/hcp-proj-api-' . getmypid();
    proj_rmtree($root);
    mkdir($root, 0777, true);
    $git = new GitRunner(static fn(array $argv): array => [
        'ok' => true, 'code' => 0, 'err' => '', 'out' => "## main\n",
    ]);
    $r = \Deck\Projects\handle('project.git.status', ['id' => $insert['id']],
        ['owner' => true, 'deps' => ['git' => $git, 'root' => $root]]);
    ok(!empty($r['ok']), 'curated git read via handle succeeds');
    eq($r['data']['branch'], 'main');
    eq($r['data']['project_id'], $insert['id']);
    $missing = \Deck\Projects\handle('project.git.status', ['id' => 999999], ['owner' => true]);
    ok(empty($missing['ok']) && $missing['status'] === 404, 'unknown project id refused');
    proj_rmtree($root);
});

test('projects.forgejo.token.is.vault.only', static function (): void {
    $src = (string) file_get_contents(repo_path('src/lib/forgejo.php'));
    contains($src, 'Vault::use', 'Forgejo must go through the vault broker');
    contains($src, "{{secret}}", 'only the placeholder crosses the boundary');
    not_contains($src, 'file_get_contents(/.env', 'no .env reads');
    not_contains($src, 'getenv(\'FORGEJO_TOKEN\')', 'no token value from env');
    $safe = Forgejo::safeUrl('http://user:supersecret@192.168.2.157:3001/repo?token=abc123#frag');
    not_contains($safe, 'supersecret', 'userinfo stripped');
    not_contains($safe, 'token=abc123', 'query stripped');
    not_contains($safe, 'frag', 'fragment stripped');
    contains(Forgejo::safeError('GET http://u:pw@example.com/x failed'), 'example.com',
             'errors keep the host but not credentials');
    not_contains(Forgejo::safeError('GET http://u:pw@example.com/x failed'), 'pw@',
                 'credentials removed from errors');
});

test('projects.backup.no.secret.contract', static function (): void {
    $src = (string) file_get_contents(repo_path('tools/backup.sh'));
    contains($src, '--output', 'backup supports --output');
    contains($src, '--verify', 'backup supports --verify');
    contains($src, 'tar -tzf', 'verify lists archive contents');
    contains($src, 'FORBIDDEN_RE', 'archive names are checked');
    contains($src, '\\.env', '.env entries are refused');
    contains($src, 'panel.db', 'database is included');
    contains($src, 'setup.lock', 'setup state is included');
    not_contains($src, 'curl ', 'backup makes no network call');
    not_contains($src, 'VAULT_SOCKET', 'backup does not touch the vault broker');
    not_contains($src, 'vault-broker', 'backup does not touch the vault broker');
    not_contains($src, 'getenv(\'PANEL_ADMIN_PASSWORD\')', 'no admin password in backup');
});

test('projects.api.never.returns.secret.values', static function (): void {
    $src = (string) file_get_contents(repo_path('src/public/projects-api.php'));
    not_contains($src, "\$payload['value']", 'no raw value passthrough');
    not_contains($src, "\$payload['secret']", 'no raw secret passthrough');
    not_contains($src, 'getenv(', 'no environment reads in the API layer');
    not_contains($src, 'shell_exec', 'no shell execution in the API layer');
    contains($src, 'ProjectModel::describe', 'projects are whitelisted');
    contains($src, 'ownerAllowed', 'owner capability is enforced');
});

// Standalone: php tests/unit/projects.php. Under tests/suite.php the harness
// is already running and this file only registers its cases.
if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    exit(run_tests());
}
