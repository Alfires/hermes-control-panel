<?php
declare(strict_types=1);

/** Schedule parsing and the internal webui self-test job. */
final class Scheduler {
    public static function parse(mixed $expr): ?array {
        if (!is_string($expr)) return null;
        $expr = trim($expr);
        if (preg_match('/^daily\s+(\d{2}):(\d{2})$/', $expr, $m)) {
            $h = (int) $m[1]; $mi = (int) $m[2];
            if ($h > 23 || $mi > 59 || strlen($m[1]) !== 2 || strlen($m[2]) !== 2) return null;
            return ['kind' => 'daily', 'hour' => $h, 'minute' => $mi];
        }
        if (preg_match('/^every\s+([1-9]\d*)m$/', $expr, $m)) {
            return ['kind' => 'every', 'minutes' => (int) $m[1]];
        }
        return null;
    }

    public static function due(?array $parsed, int $now, int $lastRun): bool {
        if (!$parsed) return false;
        if ($parsed['kind'] === 'every') return ($now - $lastRun) >= $parsed['minutes'] * 60;
        $today = gmdate('Y-m-d', $now);
        $target = strtotime($today . ' ' . sprintf('%02d:%02d', $parsed['hour'], $parsed['minute']) . ' UTC');
        return $target !== false && $now >= $target && $lastRun < $target;
    }

    /** Read-only self-test: returns a structured report without changing config. */
    public static function runSelfTest(array $config): array {
        $checks = [];
        $pages = $config['pages'] ?? [];
        foreach ($pages as $page) {
            $id = (string) ($page['id'] ?? 'unknown');
            $html = class_exists('Render') ? Render::page($id, $config, ['mode' => 'populated']) : '';
            $checks[] = ['name' => 'page:' . $id, 'ok' => is_string($html) && strlen($html) > 20];
        }
        $sources = class_exists('HermesSources') ? HermesSources::catalog() : [];
        foreach (array_keys($sources) as $source) {
            $checks[] = ['name' => 'source:' . $source, 'ok' => class_exists('HermesSources')];
        }
        $failed = array_values(array_filter($checks, static fn($c) => !$c['ok']));
        return [
            'ok' => count($failed) === 0,
            'pages' => count($pages),
            'checks' => $checks,
            'failed' => $failed,
            'ran_at' => time(),
        ];
    }
}
