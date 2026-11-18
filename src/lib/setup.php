<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/hermes.php';
require_once __DIR__ . '/stt.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/util.php';

/** Idempotent first-launch wizard state machine. */
final class Setup {
    public static function lockPath(): string {
        return (string)(getenv('PANEL_DATA') ?: '/var/lib/panel') . '/setup.lock';
    }

    public static function complete(): bool {
        return is_file(self::lockPath());
    }

    public static function requirements(): array {
        $checks = [];
        $checks[] = ['id' => 'php', 'label' => 'PHP 8.3+', 'ok' => PHP_VERSION_ID >= 80300, 'level' => 'fail'];
        $dataDir = dirname(DB::path());
        $checks[] = ['id' => 'data', 'label' => 'Writable data directory',
            'ok' => is_dir($dataDir) ? is_writable($dataDir) : @mkdir($dataDir, 0770, true), 'level' => 'fail'];
        $checks[] = ['id' => 'sqlite', 'label' => 'SQLite storage', 'ok' => class_exists('PDO') && in_array('sqlite', PDO::getAvailableDrivers(), true), 'level' => 'fail'];
        $hermesOk = false;
        $ctx = stream_context_create(['http' => ['timeout' => 3, 'ignore_errors' => true]]);
        $r = @file_get_contents(Hermes::baseUrl() . '/health', false, $ctx);
        if ($r !== false) $hermesOk = true;
        $checks[] = ['id' => 'hermes', 'label' => 'Hermes gateway reachable', 'ok' => $hermesOk, 'level' => 'warn'];
        $stt = STT::status();
        $checks[] = ['id' => 'stt', 'label' => 'Local speech engine',
            'ok' => $stt['available'], 'level' => 'warn', 'detail' => $stt['available'] ? 'ready' : 'voice page will show setup help'];
        $checks[] = ['id' => 'vault', 'label' => 'Secret vault broker',
            'ok' => is_dir('/run/panel-sockets'), 'level' => 'warn'];
        return $checks;
    }

    public static function status(): array {
        $steps = [
            ['id' => 1, 'title' => 'Welcome & requirements', 'done' => true],
            ['id' => 2, 'title' => 'Environment', 'done' => self::complete()],
            ['id' => 3, 'title' => 'Hermes connection', 'done' => self::complete()],
            ['id' => 4, 'title' => 'Owner account', 'done' => self::complete() && self::ownerExists()],
            ['id' => 5, 'title' => 'Personalise', 'done' => self::complete()],
            ['id' => 6, 'title' => 'Done', 'done' => self::complete()],
        ];
        return ['ok' => true, 'complete' => self::complete(), 'steps' => $steps,
                'requirements' => self::requirements(), 'config' => ConfigGovernance::active()];
    }

    private static function ownerExists(): bool {
        return DB::one('SELECT id FROM users LIMIT 1') !== null;
    }

    public static function finish(string $actor = 'owner'): array {
        if (self::complete()) return ['ok' => true, 'already' => true];
        $dir = dirname(self::lockPath());
        @mkdir($dir, 0770, true);
        if (@file_put_contents(self::lockPath(), json_encode([
            'completed_at' => time(), 'actor' => Util::scrub($actor), 'revision' => ConfigGovernance::currentRevision(),
        ])) === false) return ['ok' => false, 'error' => 'could not write setup lock'];
        DB::audit($actor, 'setup.complete');
        return ['ok' => true];
    }
}
