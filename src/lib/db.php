<?php
declare(strict_types=1);

require_once __DIR__ . '/util.php';

/** SQLite storage for the panel. Kept outside the image in /var/lib/panel. */
final class DB {
    private static ?PDO $pdo = null;

    public static function path(): string {
        return getenv('PANEL_DB') ?: (getenv('PANEL_DATA') ? rtrim((string)getenv('PANEL_DATA'), '/') . '/panel.db' : '/var/lib/panel/panel.db');
    }

    /** Re-open the connection (used by tests with a fresh PANEL_DB and by the
     *  installer when it relocates the data directory). */
    public static function init(): self {
        self::$pdo = null;
        self::pdo();
        return new self();
    }

    public static function pdo(): PDO {
        if (self::$pdo === null) {
            $path = self::path();
            @mkdir(dirname($path), 0770, true);
            self::$pdo = new PDO('sqlite:' . $path);
            self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            self::$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            self::$pdo->exec('PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON; PRAGMA busy_timeout=5000;');
            self::migrate(self::$pdo);
        }
        return self::$pdo;
    }

    private static function migrate(PDO $p): void {
        $p->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  username TEXT NOT NULL UNIQUE,
  password_hash TEXT NOT NULL,
  created_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS logins (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
  token_hash TEXT NOT NULL UNIQUE,
  created_at INTEGER NOT NULL,
  expires_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS agents (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  slug TEXT NOT NULL UNIQUE,
  name TEXT NOT NULL,
  role TEXT NOT NULL,
  description TEXT NOT NULL DEFAULT '',
  system_prompt TEXT NOT NULL DEFAULT '',
  model TEXT NOT NULL DEFAULT 'big-pickle',
  tools TEXT NOT NULL DEFAULT '[]',
  vault_names TEXT NOT NULL DEFAULT '[]',
  can_message INTEGER NOT NULL DEFAULT 1,
  enabled INTEGER NOT NULL DEFAULT 1,
  schedule TEXT,
  created_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS agent_sessions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  agent_id INTEGER NOT NULL REFERENCES agents(id) ON DELETE CASCADE,
  title TEXT NOT NULL DEFAULT 'New session',
  messages TEXT NOT NULL DEFAULT '[]',
  created_at INTEGER NOT NULL,
  updated_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS escalations (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  agent_id INTEGER NOT NULL REFERENCES agents(id) ON DELETE CASCADE,
  capability TEXT NOT NULL,
  reason TEXT NOT NULL DEFAULT '',
  status TEXT NOT NULL DEFAULT 'pending',
  ttl_seconds INTEGER NOT NULL DEFAULT 300,
  decided_at INTEGER,
  expires_at INTEGER,
  token_hash TEXT,
  uses_left INTEGER NOT NULL DEFAULT 1,
  created_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS approvals (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  agent_id INTEGER REFERENCES agents(id) ON DELETE CASCADE,
  kind TEXT NOT NULL,
  summary TEXT NOT NULL,
  payload TEXT NOT NULL DEFAULT '{}',
  status TEXT NOT NULL DEFAULT 'pending',
  created_at INTEGER NOT NULL,
  decided_at INTEGER
);
CREATE TABLE IF NOT EXISTS messages (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  from_agent INTEGER REFERENCES agents(id) ON DELETE CASCADE,
  to_agent INTEGER NOT NULL REFERENCES agents(id) ON DELETE CASCADE,
  body TEXT NOT NULL,
  created_at INTEGER NOT NULL,
  read_at INTEGER
);
CREATE TABLE IF NOT EXISTS findings (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  source TEXT NOT NULL,
  severity TEXT NOT NULL,
  title TEXT NOT NULL,
  detail TEXT NOT NULL DEFAULT '',
  status TEXT NOT NULL DEFAULT 'open',
  created_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS layout (
  key TEXT PRIMARY KEY,
  value TEXT NOT NULL,
  updated_by TEXT NOT NULL DEFAULT 'owner',
  updated_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS audit (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  actor TEXT NOT NULL,
  action TEXT NOT NULL,
  detail TEXT NOT NULL DEFAULT '',
  created_at INTEGER NOT NULL,
  prev_hash TEXT,
  hash TEXT
);
CREATE TABLE IF NOT EXISTS ui_config_revisions (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  schema_version INTEGER NOT NULL,
  revision INTEGER NOT NULL UNIQUE,
  parent_revision INTEGER,
  data TEXT NOT NULL,
  hash TEXT NOT NULL,
  state TEXT NOT NULL DEFAULT 'draft',
  author TEXT NOT NULL,
  reason TEXT NOT NULL DEFAULT '',
  approval_id INTEGER,
  test_result TEXT,
  created_at INTEGER NOT NULL,
  activated_at INTEGER
);
CREATE TABLE IF NOT EXISTS ui_config_proposals (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  revision_id INTEGER NOT NULL,
  diff TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'pending',
  decided_by TEXT,
  decided_at INTEGER,
  expires_at INTEGER
);
CREATE TABLE IF NOT EXISTS hermes_cache (
  key TEXT PRIMARY KEY,
  value TEXT NOT NULL,
  fetched_at INTEGER NOT NULL,
  ttl INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS stt_jobs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  status TEXT NOT NULL,
  duration REAL,
  engine TEXT,
  error TEXT,
  created_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS search_docs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  kind TEXT NOT NULL,
  ref_id TEXT NOT NULL,
  title TEXT NOT NULL,
  body TEXT NOT NULL,
  updated_at INTEGER NOT NULL
);
CREATE TABLE IF NOT EXISTS schedule_runs (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  job TEXT NOT NULL,
  status TEXT NOT NULL,
  detail TEXT,
  created_at INTEGER NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS idx_search_doc ON search_docs(kind, ref_id);
SQL);
        // Existing decks have a legacy audit table without the hash chain.
        // CREATE TABLE IF NOT EXISTS cannot migrate it, so add columns in place.
        self::ensureColumn($p, 'audit', 'prev_hash', 'TEXT');
        self::ensureColumn($p, 'audit', 'hash', 'TEXT');

        // Backfill legacy audit rows so the append-only chain stays verifiable.
        $prev = '';
        foreach ($p->query('SELECT id,actor,action,detail,created_at,hash FROM audit ORDER BY id ASC') as $row) {
            if (!empty($row['hash'])) { $prev = (string) $row['hash']; continue; }
            $hash = hash('sha256', $prev . '|' . $row['actor'] . '|' . $row['action'] . '|'
                . $row['detail'] . '|' . $row['created_at']);
            $stmt = $p->prepare('UPDATE audit SET prev_hash=?, hash=? WHERE id=?');
            $stmt->execute([$prev, $hash, (int) $row['id']]);
            $prev = $hash;
        }
    }

    private static function ensureColumn(PDO $p, string $table, string $column, string $type): void {
        $columns = [];
        foreach ($p->query('PRAGMA table_info(' . $table . ')') as $row) {
            $columns[] = $row['name'];
        }
        if (!in_array($column, $columns, true)) {
            $p->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $type);
        }
    }

    public static function run(string $sql, array $args = []): PDOStatement {
        $s = self::pdo()->prepare($sql);
        $s->execute($args);
        return $s;
    }

    public static function one(string $sql, array $args = []): ?array {
        $r = self::run($sql, $args)->fetch();
        return $r === false ? null : $r;
    }

    public static function all(string $sql, array $args = []): array {
        return self::run($sql, $args)->fetchAll();
    }

    /** Append-only audit row with a SHA-256 hash chain. */
    public static function audit(string $actor, string $action, string $detail = ''): void {
        $detail = Util::scrub(substr($detail, 0, 2000));
        $last = self::one('SELECT hash FROM audit WHERE hash IS NOT NULL ORDER BY id DESC LIMIT 1');
        $prev = $last['hash'] ?? '';
        $now = time();
        $hash = hash('sha256', $prev . '|' . $actor . '|' . $action . '|' . $detail . '|' . $now);
        self::run('INSERT INTO audit(actor,action,detail,created_at,prev_hash,hash) VALUES(?,?,?,?,?,?)',
            [$actor, $action, $detail, $now, $prev, $hash]);
    }

    public static function verifyAuditChain(): bool {
        $rows = self::all('SELECT * FROM audit ORDER BY id ASC');
        $prev = '';
        foreach ($rows as $r) {
            if (($r['prev_hash'] ?? '') !== $prev) return false;
            $expected = hash('sha256', $prev . '|' . $r['actor'] . '|' . $r['action'] . '|' . $r['detail'] . '|' . $r['created_at']);
            if (!hash_equals($expected, (string)($r['hash'] ?? ''))) return false;
            $prev = (string) $r['hash'];
        }
        return true;
    }
}
