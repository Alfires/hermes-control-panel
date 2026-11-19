<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/schema.php';
require_once __DIR__ . '/registry.php';
require_once __DIR__ . '/render.php';

/** Versioned, proposal-driven configuration. An agent may propose; only the owner
 *  may approve. Nothing mutates the active revision before approval. */
final class ConfigGovernance {
    private const ALLOWED_ROOTS = ['meta', 'theme', 'pages', 'feature_flags', 'prompts', 'schedule'];

    public static function defaultConfig(): array {
        $pages = array_values(PageRegistry::builtin());
        // Give the overview page a metric list so AI patches can target array items.
        $pages[0]['blocks'][0]['props']['metrics'] = ['agents', 'sessions'];
        return [
            'schema_version' => Schema::VERSION,
            'revision' => 1,
            'meta' => ['title' => 'Hermes Control Deck', 'locale' => 'en', 'description' => 'Your AI team, one deck.'],
            'theme' => [
                'preset' => 'brezel-light-rounded', 'mode' => 'light', 'radius' => 'rounded', 'font' => 'system',
                'tokens' => Theme::presets()['brezel-light-rounded']['tokens'],
            ],
            'pages' => $pages,
            'feature_flags' => ['hermes_proxy' => true, 'voice' => true, 'self_test' => true, 'search' => true, 'wizard' => true],
            'prompts' => ['style' => 'casual-ok', 'normalise' => true],
            'schedule' => ['self_test' => 'daily 04:20'],
        ];
    }

    public static function active(): array {
        $row = DB::one("SELECT data FROM ui_config_revisions WHERE state='active' ORDER BY revision DESC LIMIT 1");
        if ($row) {
            $data = json_decode($row['data'], true);
            if (is_array($data)) return $data;
        }
        $default = self::defaultConfig();
        $hash = self::hash($default);
        DB::run('INSERT OR IGNORE INTO ui_config_revisions(schema_version,revision,data,hash,state,author,reason,created_at,activated_at)
                 VALUES(?,?,?,?,\'active\',\'system\',\'initial configuration\',?,?)',
            [Schema::VERSION, 1, json_encode($default), $hash, time(), time()]);
        return $default;
    }

    public static function currentRevision(): int {
        return (int) (self::active()['revision'] ?? 1);
    }

    /** Always append: a rolled-back revision number stays in history, so the
     *  next proposal must be greater than every revision ever written. */
    public static function nextRevision(): int {
        $row = DB::one('SELECT MAX(revision) n FROM ui_config_revisions');
        return max(self::currentRevision(), (int)($row['n'] ?? 0)) + 1;
    }

    public static function propose(array $patches, string $author): array {
        if (!is_array($patches) || count($patches) === 0 || count($patches) > 100) {
            return ['ok' => false, 'errors' => ['patch list must contain 1-100 operations']];
        }
        $base = self::active();
        $candidate = $base;
        foreach ($patches as $patch) {
            $applied = self::applyPatch($candidate, $patch);
            if (!$applied['ok']) return ['ok' => false, 'errors' => [$applied['error']]];
            $candidate = $applied['config'];
        }
        $check = Schema::validateConfig($candidate);
        if (!$check['ok']) return ['ok' => false, 'errors' => $check['errors']];

        $candidate['revision'] = self::nextRevision();
        $hash = self::hash($candidate);
        $diff = self::diff($base, $candidate);
        $now = time();
        DB::run('INSERT INTO ui_config_revisions(schema_version,revision,parent_revision,data,hash,state,author,reason,created_at)
                 VALUES(?,?,?,?,?,\'draft\',?,?,?)',
            [Schema::VERSION, $candidate['revision'], $base['revision'] ?? 1,
             json_encode($candidate), $hash, self::e($author), 'ai proposal', $now]);
        $revisionId = (int) DB::pdo()->lastInsertId();
        DB::run('INSERT INTO ui_config_proposals(revision_id,diff,status,expires_at) VALUES(?,?,\'pending\',?)',
            [$revisionId, json_encode($diff), $now + 86400]);
        $proposalId = (int) DB::pdo()->lastInsertId();
        DB::audit($author, 'ui.config.propose', 'revision ' . $candidate['revision']);
        return ['ok' => true, 'revision' => $candidate['revision'], 'hash' => $hash,
                'diff' => $diff, 'state' => 'draft', 'proposal_id' => $proposalId];
    }

    public static function approve(int $proposalId, string $actor): array {
        $proposal = DB::one('SELECT * FROM ui_config_proposals WHERE id=? AND status=\'pending\'', [$proposalId]);
        if (!$proposal) return ['ok' => false, 'error' => 'proposal not found or already decided'];
        // expires_at was written but never checked: a stale draft could still be
        // activated long after the owner should have been asked again.
        if (!empty($proposal['expires_at']) && (int)$proposal['expires_at'] <= time()) {
            DB::run("UPDATE ui_config_proposals SET status='expired' WHERE id=?", [$proposalId]);
            DB::run("UPDATE ui_config_revisions SET state='rejected' WHERE id=? AND state='draft'",
                [(int)$proposal['revision_id']]);
            return ['ok' => false, 'error' => 'proposal has expired'];
        }
        $revision = DB::one('SELECT * FROM ui_config_revisions WHERE id=?', [(int)$proposal['revision_id']]);
        if (!$revision || $revision['state'] !== 'draft') return ['ok' => false, 'error' => 'revision is not a draft'];
        DB::run("UPDATE ui_config_revisions SET state='superseded' WHERE state='active'");
        DB::run("UPDATE ui_config_revisions SET state='active', approval_id=?, activated_at=? WHERE id=?",
            [$proposalId, time(), (int)$revision['id']]);
        DB::run("UPDATE ui_config_proposals SET status='approved', decided_by=?, decided_at=? WHERE id=?",
            [self::e($actor), time(), $proposalId]);
        DB::audit($actor, 'ui.config.approve', 'revision ' . $revision['revision']);
        return ['ok' => true, 'revision' => (int)$revision['revision']];
    }

    public static function rollback(int $revisionNumber, string $actor): array {
        $row = DB::one('SELECT * FROM ui_config_revisions WHERE revision=?', [$revisionNumber]);
        if (!$row) return ['ok' => false, 'error' => 'revision not found'];
        // Only revisions that were actually approved and active can be restored.
        // Previously a pending AI draft could be activated by rollback without
        // any approval at all.
        if (!in_array($row['state'], ['active', 'rolled_back', 'superseded'], true)) {
            return ['ok' => false, 'error' => 'only previously approved revisions can be restored'];
        }
        if ((int)$row['revision'] === (int)(self::active()['revision'] ?? 0)) {
            return ['ok' => false, 'error' => 'revision is already active'];
        }
        DB::run("UPDATE ui_config_revisions SET state='rolled_back' WHERE state='active'");
        DB::run("UPDATE ui_config_revisions SET state='active', activated_at=? WHERE id=?", [time(), (int)$row['id']]);
        DB::audit($actor, 'ui.config.rollback', 'revision ' . $revisionNumber);
        return ['ok' => true, 'revision' => $revisionNumber];
    }

    public static function history(): array {
        return DB::all('SELECT r.id,r.revision,r.parent_revision,r.state,r.author,r.reason,r.hash,
                        r.created_at,r.activated_at,
                        (SELECT p.id FROM ui_config_proposals p WHERE p.revision_id=r.id
                         ORDER BY p.id DESC LIMIT 1) AS proposal_id
                        FROM ui_config_revisions r ORDER BY r.revision ASC');
    }

    public static function proposal(int $id): ?array {
        return DB::one('SELECT * FROM ui_config_proposals WHERE id=?', [$id]);
    }

    public static function revision(int $id): ?array {
        return DB::one('SELECT * FROM ui_config_revisions WHERE id=?', [$id]);
    }

    public static function diff(array $before, array $after): array {
        $lines = [];
        $flatBefore = self::flatten($before);
        $flatAfter = self::flatten($after);
        foreach ($flatBefore as $k => $v) {
            if (!array_key_exists($k, $flatAfter)) $lines[] = "- $k: " . self::short($v);
            elseif ($flatAfter[$k] !== $v) $lines[] = "~ $k: " . self::short($v) . ' -> ' . self::short($flatAfter[$k]);
        }
        foreach ($flatAfter as $k => $v) {
            if (!array_key_exists($k, $flatBefore)) $lines[] = "+ $k: " . self::short($v);
        }
        return $lines;
    }

    private static function flatten(array $a, string $prefix = ''): array {
        $out = [];
        foreach ($a as $k => $v) {
            $key = $prefix === '' ? (string)$k : $prefix . '.' . $k;
            if (is_array($v)) $out += self::flatten($v, $key);
            else $out[$key] = is_bool($v) ? ($v ? 'true' : 'false') : (string)$v;
        }
        return $out;
    }

    private static function short(string $value): string {
        $value = Util::scrub($value);
        return strlen($value) > 80 ? substr($value, 0, 77) . '...' : $value;
    }

    private static function hash(array $config): string {
        unset($config['revision']);
        return hash('sha256', json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private static function e(string $value): string {
        return mb_substr($value, 0, 120);
    }

    private static function applyPatch(array $config, mixed $patch): array {
        if (!is_array($patch)) return ['ok' => false, 'error' => 'patch must be an object'];
        $op = $patch['op'] ?? null;
        $path = $patch['path'] ?? null;
        if (!in_array($op, ['add', 'replace', 'remove'], true)) return ['ok' => false, 'error' => 'unknown patch operation'];
        if (!is_string($path) || !str_starts_with($path, '/')) return ['ok' => false, 'error' => 'path must start with /'];
        $segments = array_slice(explode('/', $path), 1);
        if (count($segments) === 0 || count($segments) > 12) return ['ok' => false, 'error' => 'invalid path depth'];
        if (!in_array($segments[0], self::ALLOWED_ROOTS, true)) return ['ok' => false, 'error' => 'path root is not editable'];
        foreach ($segments as $s) {
            if ($s === '') return ['ok' => false, 'error' => 'empty path segment'];
            if (!preg_match('/^[A-Za-z0-9_-]+$/', $s)) return ['ok' => false, 'error' => 'unsafe path segment'];
        }
        if ($op === 'remove' && count($segments) < 2) return ['ok' => false, 'error' => 'cannot remove a top-level section'];

        $ref =& $config;
        $parent =& $config;
        $last = $segments[0];
        $isLast = false;
        foreach ($segments as $index => $s) {
            if (!is_array($ref)) return ['ok' => false, 'error' => 'path traverses a scalar'];
            $isLast = $index === count($segments) - 1;
            if ($op === 'add' && $s === '-' && $isLast) {
                $parent =& $ref;
                $last = '-';
                break;
            }
            if (!array_key_exists($s, $ref)) {
                if ($op === 'remove') return ['ok' => false, 'error' => 'path does not exist'];
                $ref[$s] = [];
            }
            $parent =& $ref;
            $last = $s;
            $ref =& $ref[$s];
        }
        if (!is_array($parent)) return ['ok' => false, 'error' => 'invalid path parent'];
        if ($op === 'remove') {
            unset($parent[$last]);
        } elseif ($op === 'add' && $last === '-') {
            $parent[] = $patch['value'] ?? null;
        } else {
            $parent[$last] = $patch['value'] ?? null;
        }
        unset($ref);
        unset($parent);
        return ['ok' => true, 'config' => $config];
    }
}
