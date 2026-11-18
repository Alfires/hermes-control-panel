<?php
declare(strict_types=1);

/** SQLite storage for the panel. Kept outside the image in /var/lib/panel. */
final class DB {
    private static ?PDO $pdo = null;

    public static function path(): string {
        return getenv('PANEL_DB') ?: '/var/lib/panel/panel.db';
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
  created_at INTEGER NOT NULL
);
SQL);
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

    public static function audit(string $actor, string $action, string $detail = ''): void {
        self::run('INSERT INTO audit(actor,action,detail,created_at) VALUES(?,?,?,?)',
            [$actor, $action, substr($detail, 0, 2000), time()]);
    }
}
