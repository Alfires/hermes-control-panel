<?php
declare(strict_types=1);

require_once __DIR__ . '/util.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/hostctl.php';
require_once __DIR__ . '/forgejo.php';

/**
 * Curated git operations. There is deliberately NO general command runner:
 * each supported operation maps to a fixed argv template, executed without a
 * shell. Paths are workspace-root-relative and resolved before they reach this
 * class; no agent-controlled string is ever interpreted as an option.
 */
final class GitRunner {
    private const OPS = ['clone', 'commit', 'push', 'status', 'log', 'diff'];
    private $executor = null; // optional callable(array $argv): array{ok,out,err,code}

    public function __construct(?callable $executor = null) { $this->executor = $executor; }

    public function run(string $op, array $args = []): array {
        if (!in_array($op, self::OPS, true)) return ['ok' => false, 'error' => 'unknown git operation'];
        $cwd = (string)($args['cwd'] ?? '');
        $url = (string)($args['url'] ?? '');
        $needsCwd = $op !== 'clone';
        if ($needsCwd && ($cwd === '' || $cwd[0] !== '/')) {
            return ['ok' => false, 'error' => 'invalid git working directory'];
        }
        $argv = match ($op) {
            'clone' => ['git', 'clone', '--depth', '1', '--',
                        Forgejo::safeUrl($url), (string)($args['target'] ?? '')],
            'commit' => ['git', '-C', $cwd, 'add', '--', 'README.md'],
            'push'   => ['git', '-C', $cwd, 'push', '-q', '-u', 'origin', 'main'],
            'status' => ['git', '-C', $cwd, 'status', '--porcelain=v1', '--branch'],
            'log'    => ['git', '-C', $cwd, 'log', '-n', (string)max(1, min(50, (int)($args['limit'] ?? 10))),
                         '--pretty=format:%h%x1f%s%x1f%ci'],
            'diff'   => ['git', '-C', $cwd, 'diff', '--stat', '--no-color'],
        };
        if ($op === 'clone' && (str_starts_with($url, '-') || !str_starts_with($url, 'http'))) {
            return ['ok' => false, 'error' => 'invalid clone URL'];
        }
        $res = $this->execute($argv);
        if ($op === 'commit' && !empty($res['ok'])) {
            $res2 = $this->execute(['git', '-C', $cwd, '-c', 'user.name=Deck Bot',
                                    '-c', 'user.email=deck@localhost', 'commit', '-q',
                                    '-m', 'Initial commit from Hermes Control Deck']);
            $res = ['ok' => !empty($res2['ok']), 'code' => (int)($res2['code'] ?? 0),
                    'out' => (string)($res['out'] ?? '') . (string)($res2['out'] ?? ''),
                    'err' => (string)($res['err'] ?? '') . (string)($res2['err'] ?? '')];
        }
        if (empty($res['ok'])) {
            return ['ok' => false, 'error' => Forgejo::safeError($res['err'] ?? 'git command failed')];
        }
        return ['ok' => true, 'data' => self::curate($op, (string)($res['out'] ?? ''))];
    }

    /** argv is passed to proc_open as an array: no shell, no globbing, no injection. */
    private function execute(array $argv): array {
        if ($this->executor !== null) {
            $fake = ($this->executor)($argv);
            return is_array($fake) ? $fake : ['ok' => false, 'err' => 'invalid executor reply'];
        }
        $desc = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = @proc_open($argv, $desc, $pipes);
        if (!is_resource($proc)) return ['ok' => false, 'code' => 127, 'out' => '', 'err' => 'git unavailable'];
        fclose($pipes[0]);
        $out = (string) stream_get_contents($pipes[1], 131072);
        $err = (string) stream_get_contents($pipes[2], 8192);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        return ['ok' => $code === 0, 'code' => $code,
                'out' => self::clean($out, 131072), 'err' => self::clean($err, 2000)];
    }

    /** Only status/list/stat metadata is returned: never a file patch or blob. */
    private static function curate(string $op, string $out): array {
        if ($op === 'status') {
            $branch = 'HEAD';
            $changes = [];
            foreach (explode("\n", $out) as $line) {
                $line = rtrim($line, "\r");
                if (str_starts_with($line, '## ')) {
                    $b = trim(substr($line, 3));
                    $b = preg_replace('/\s.*$/', '', explode('...', $b)[0]) ?: 'HEAD';
                    $branch = self::clean($b, 80);
                } elseif (strlen($line) >= 4) {
                    $changes[] = self::clean(substr($line, 3), 200);
                    if (count($changes) >= 100) break;
                }
            }
            return ['branch' => $branch, 'clean' => $changes === [], 'changes' => $changes];
        }
        if ($op === 'log') {
            $commits = [];
            foreach (explode("\n", $out) as $line) {
                $parts = explode("\x1f", $line);
                if (count($parts) < 3) continue;
                $hash = trim($parts[0]);
                if (!preg_match('/^[0-9a-f]{4,12}$/', $hash)) continue;
                $commits[] = ['hash' => $hash, 'subject' => self::clean($parts[1], 300),
                              'date' => self::clean($parts[2], 40)];
                if (count($commits) >= 50) break;
            }
            return ['commits' => $commits];
        }
        if ($op === 'diff') {
            $lines = [];
            foreach (explode("\n", $out) as $line) {
                // A stat line is " path | 3 +-", never a patch hunk or file body.
                if (!str_contains($line, '|') && !preg_match('/\d+ (file|files) changed/', $line)) continue;
                $lines[] = self::clean($line, 240);
                if (count($lines) >= 80) break;
            }
            return ['stat' => $lines];
        }
        return ['output' => self::clean($out, 1000)];
    }

    private static function clean(string $text, int $max): string {
        $text = preg_replace('/[^\P{C}\t\n]/u', '', $text) ?? '';
        return substr(Util::scrub($text), 0, $max);
    }
}

/** Project metadata persisted in the panel SQLite database. */
final class ProjectModel {
    public const VISIBILITIES = ['private', 'main'];
    private const NAME_RE = '/^[a-z0-9][a-z0-9_.-]{0,99}$/';
    private const OWNER_RE = '/^[A-Za-z0-9][A-Za-z0-9_-]{0,63}$/';
    private const SEGMENT_RE = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/';

    public static function migrate(): void {
        DB::run(<<<'SQL'
CREATE TABLE IF NOT EXISTS projects (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  name TEXT NOT NULL UNIQUE,
  visibility TEXT NOT NULL DEFAULT 'private' CHECK (visibility IN ('private','main')),
  forgejo_repo TEXT NOT NULL DEFAULT '',
  local_path TEXT NOT NULL DEFAULT '',
  owner TEXT NOT NULL DEFAULT 'owner',
  created_at INTEGER NOT NULL
)
SQL);
        DB::run('CREATE UNIQUE INDEX IF NOT EXISTS idx_projects_name ON projects(name)');
        foreach ([
            'visibility'  => "TEXT NOT NULL DEFAULT 'private'",
            'forgejo_repo'=> "TEXT NOT NULL DEFAULT ''",
            'local_path'  => "TEXT NOT NULL DEFAULT ''",
            'owner'       => "TEXT NOT NULL DEFAULT 'owner'",
            'created_at'  => 'INTEGER NOT NULL DEFAULT 0',
        ] as $column => $type) {
            $exists = false;
            foreach (DB::all('PRAGMA table_info(projects)') as $col) {
                if (($col['name'] ?? '') === $column) { $exists = true; break; }
            }
            if (!$exists) DB::run('ALTER TABLE projects ADD COLUMN ' . $column . ' ' . $type);
        }
    }

    public static function validName(string $name): bool {
        return (bool) preg_match(self::NAME_RE, $name) && $name !== '.' && $name !== '..'
            && !str_contains($name, '..') && !str_ends_with($name, '.git');
    }

    public static function validVisibility(string $visibility): bool {
        return in_array($visibility, self::VISIBILITIES, true);
    }

    public static function validOwner(string $owner): bool {
        return (bool) preg_match(self::OWNER_RE, $owner);
    }

    /** Accept only workspace-root-relative paths with plain, safe segments. */
    public static function safeRelativePath(string $path): ?string {
        $path = trim($path);
        if ($path === '' || strlen($path) > 200 || str_contains($path, '\\')) return null;
        if ($path[0] === '/' || preg_match('/^[A-Za-z]:/', $path)) return null;
        if (str_contains($path, '%') || str_contains($path, "\0")) return null;
        $segments = explode('/', $path);
        if (count($segments) > 8) return null;
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') return null;
            if (!preg_match(self::SEGMENT_RE, $segment)) return null;
        }
        return implode('/', $segments);
    }

    public static function workspaceRoot(): string {
        $root = getenv('PANEL_PROJECTS_ROOT') ?: getenv('PANEL_WORKSPACE_ROOT') ?: '';
        if ($root === '') {
            $data = getenv('PANEL_DATA') ?: '/var/lib/panel';
            $root = rtrim($data, '/') . '/projects';
        }
        return rtrim($root, '/');
    }

    /** Resolve a validated relative path and prove the result stays under root. */
    public static function resolveUnderRoot(string $relative, ?string $root = null): ?string {
        $rel = self::safeRelativePath($relative);
        if ($rel === null) return null;
        $root = rtrim($root ?? self::workspaceRoot(), '/');
        if ($root === '' || $root[0] !== '/') return null;
        $rootReal = realpath($root) ?: $root;
        $base = rtrim($rootReal, '/') . '/';
        $target = $base . $rel;
        $parent = dirname($target);
        $parentReal = realpath($parent);
        if ($parentReal === false) {
            // Parent may not exist yet; walk up to the nearest existing ancestor.
            $probe = $parent;
            while ($probe !== $rootReal && dirname($probe) !== $probe) {
                $probe = dirname($probe);
                $found = realpath($probe);
                if ($found !== false) { $parentReal = $found; break; }
            }
        }
        if ($parentReal === false) return null;
        if ($parentReal !== rtrim($rootReal, '/') && !str_starts_with($parentReal . '/', $base)) return null;
        $existing = realpath($target);
        if ($existing !== false && !str_starts_with($existing . '/', $base)) return null; // symlink escape
        return $target;
    }

    /** Insert metadata. Kept public so migration/persistence can be tested alone. */
    public static function insert(array $row): array {
        self::migrate();
        $name = Util::str($row['name'] ?? '', 100);
        $visibility = Util::str($row['visibility'] ?? 'private', 20);
        $relative = self::safeRelativePath(Util::str($row['local_path'] ?? $name, 200));
        $owner = Util::str($row['owner'] ?? 'owner', 64);
        $repo = Util::str($row['forgejo_repo'] ?? '', 200);
        if (!self::validName($name)) return ['ok' => false, 'error' => 'invalid project name'];
        if (!self::validVisibility($visibility)) return ['ok' => false, 'error' => 'invalid visibility'];
        if ($relative === null) return ['ok' => false, 'error' => 'invalid local_path'];
        if (!self::validOwner($owner)) return ['ok' => false, 'error' => 'invalid owner'];
        if ($repo !== '' && !preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repo)) {
            return ['ok' => false, 'error' => 'invalid forgejo_repo'];
        }
        if (self::exists($name)) return ['ok' => false, 'error' => 'project already exists'];
        try {
            DB::run('INSERT INTO projects(name,visibility,forgejo_repo,local_path,owner,created_at) VALUES(?,?,?,?,?,?)',
                [$name, $visibility, $repo, $relative, $owner, time()]);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'could not persist project'];
        }
        $id = (int) DB::pdo()->lastInsertId();
        return ['ok' => true, 'id' => $id, 'project' => self::describe(self::get($id) ?? [])];
    }

    public static function get(int $id): ?array {
        self::migrate();
        $row = DB::one('SELECT * FROM projects WHERE id=?', [$id]);
        return $row ?: null;
    }

    public static function all(int $limit = 200): array {
        self::migrate();
        $out = [];
        foreach (DB::all('SELECT * FROM projects ORDER BY id DESC LIMIT ' . max(1, min(500, $limit))) as $row) {
            $out[] = self::describe($row);
        }
        return $out;
    }

    public static function exists(string $name): bool {
        self::migrate();
        return DB::one('SELECT id FROM projects WHERE name=?', [$name]) !== null;
    }

    /** Whitelist the fields exposed by the API. No absolute paths, no secrets. */
    public static function describe(array $row): array {
        return [
            'id'           => (int)($row['id'] ?? 0),
            'name'         => Util::scrub(Util::str($row['name'] ?? '', 100)),
            'visibility'   => self::validVisibility((string)($row['visibility'] ?? '')) ? $row['visibility'] : 'private',
            'forgejo_repo' => Util::scrub(Util::str($row['forgejo_repo'] ?? '', 200)),
            'local_path'   => Util::scrub(Util::str($row['local_path'] ?? '', 200)),
            'owner'        => Util::scrub(Util::str($row['owner'] ?? 'owner', 64)),
            'created_at'   => (int)($row['created_at'] ?? 0),
        ];
    }

    /** If a curated host action for git is registered, use it; otherwise return
     *  null so the narrowly scoped local GitRunner is used. */
    private static function hostGit(string $op, array $args): ?array {
        if (!class_exists('HostCtl') || !isset(\HostCtl::ACTIONS['project_git'])) return null;
        return \HostCtl::call('project_git', ['op' => $op] + $args,
                              ['id' => 0, 'slug' => 'owner-projects']);
    }

    /**
     * Validate -> create Forgejo repo -> clone into the workspace -> first commit
     * -> persist metadata. $deps allows hermetic tests (fake forgejo/git/root).
     */
    public static function create(array $input, array $deps = []): array {
        self::migrate();
        $name = Util::str($input['name'] ?? '', 100);
        if (!self::validName($name)) return ['ok' => false, 'error' => 'invalid project name'];
        $visibility = Util::str($input['visibility'] ?? 'private', 20);
        if (!self::validVisibility($visibility)) {
            return ['ok' => false, 'error' => 'visibility must be private or main'];
        }
        $relative = self::safeRelativePath(Util::str($input['local_path'] ?? $name, 200));
        if ($relative === null) return ['ok' => false, 'error' => 'local_path must be workspace-root-relative'];
        $owner = Util::str($input['owner'] ?? 'owner', 64);
        if (!self::validOwner($owner)) return ['ok' => false, 'error' => 'invalid owner'];
        if (self::exists($name)) return ['ok' => false, 'error' => 'project already exists'];

        $root = !empty($deps['root']) ? rtrim((string)$deps['root'], '/') : self::workspaceRoot();
        if ($root === '' || $root[0] !== '/') return ['ok' => false, 'error' => 'invalid workspace root'];
        if (!is_dir($root) && !@mkdir($root, 0770, true) && !is_dir($root)) {
            return ['ok' => false, 'error' => 'workspace root is not writable'];
        }
        $absolute = self::resolveUnderRoot($relative, $root);
        if ($absolute === null) return ['ok' => false, 'error' => 'local_path escapes the workspace root'];

        $forgejo = $deps['forgejo'] ?? new Forgejo();
        $git = $deps['git'] ?? new GitRunner();
        $warnings = [];

        $created = $forgejo->createRepo($name, $visibility === 'private',
            'Project ' . $name . ' on the Hermes Control Deck');
        if (empty($created['ok'])) {
            return ['ok' => false, 'error' => Forgejo::safeError($created['error'] ?? 'Forgejo create failed')];
        }
        $repo = Forgejo::curateRepo(is_array($created['repo'] ?? null) ? $created['repo'] : []);
        $cloneUrl = Forgejo::safeUrl((string)($repo['clone_url'] ?? ''));
        if (!str_starts_with($cloneUrl, 'http')) {
            return ['ok' => false, 'error' => 'Forgejo returned no safe clone URL'];
        }

        $res = self::hostGit('clone', ['url' => $cloneUrl, 'target' => $absolute]);
        if ($res === null) $res = $git->run('clone', ['url' => $cloneUrl, 'target' => $absolute]);
        if (empty($res['ok'])) {
            return ['ok' => false, 'error' => Forgejo::safeError($res['error'] ?? 'clone failed')];
        }
        if (!is_dir($absolute)) {
            return ['ok' => false, 'error' => 'clone target is missing'];
        }
        // Fixed-content README: name/visibility passed strict validation above.
        $readme = '# ' . $name . "\n\nCreated by Hermes Control Deck.\nVisibility: " . $visibility . "\n";
        if (@file_put_contents($absolute . '/README.md', $readme) === false) {
            return ['ok' => false, 'error' => 'could not write first commit file'];
        }
        $res = self::hostGit('commit', ['cwd' => $absolute]);
        if ($res === null) $res = $git->run('commit', ['cwd' => $absolute]);
        if (empty($res['ok'])) $warnings[] = 'first commit could not be written locally';

        if (getenv('PANEL_PROJECTS_PUSH') === '1') {
            $res = self::hostGit('push', ['cwd' => $absolute]);
            if ($res === null) $res = $git->run('push', ['cwd' => $absolute]);
            if (empty($res['ok'])) $warnings[] = 'push to Forgejo failed (local commit kept)';
        }

        $saved = self::insert([
            'name'         => $name,
            'visibility'   => $visibility,
            'forgejo_repo' => $repo['full_name'] ?? (Forgejo::user() . '/' . $name),
            'local_path'   => $relative,
            'owner'        => $owner,
        ]);
        if (empty($saved['ok'])) return ['ok' => false, 'error' => $saved['error'] ?? 'could not record project'];
        DB::audit('owner', 'project.create', $name . ' visibility=' . $visibility);
        return ['ok' => true, 'data' => ['project' => $saved['project'], 'repo' => $repo, 'warnings' => $warnings]];
    }

    /** Curated git read for a stored project. Never accepts a caller path. */
    public static function git(string $op, int $projectId, array $args = [], array $deps = []): array {
        if (!in_array($op, ['status', 'log', 'diff'], true)) return ['ok' => false, 'error' => 'unknown git read'];
        $row = self::get($projectId);
        if (!$row) return ['ok' => false, 'error' => 'no such project'];
        $absolute = self::resolveUnderRoot((string)$row['local_path'], $deps['root'] ?? null);
        if ($absolute === null) return ['ok' => false, 'error' => 'stored project path is invalid'];
        $git = $deps['git'] ?? new GitRunner();
        $res = $git->run($op, ['cwd' => $absolute, 'limit' => (int)($args['limit'] ?? 10)]);
        if (empty($res['ok'])) return ['ok' => false, 'error' => Forgejo::safeError($res['error'] ?? 'git read failed')];
        return ['ok' => true, 'data' => ['project_id' => $projectId] + ($res['data'] ?? [])];
    }
}
