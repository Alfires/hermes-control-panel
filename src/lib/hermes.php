<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/registry.php';
require_once __DIR__ . '/util.php';

/** Narrow, scrubbed proxy to Hermes. The browser never sees the backend key and
 *  only curated endpoints with field whitelists may be reached. */
final class Hermes {
    public static function baseUrl(): string {
        return rtrim((string)(getenv('HERMES_URL') ?: 'http://hermes:8642'), '/');
    }

    public static function dashboardUrl(): string {
        return rtrim((string)(getenv('HERMES_DASHBOARD_URL') ?: 'http://hermes:9119'), '/');
    }

    public static function available(): bool {
        return self::baseUrl() !== '';
    }

    /** Fetch one curated source, returning only whitelisted + scrubbed fields. */
    public static function fetch(string $sourceId, array $params = []): array {
        $source = HermesSources::get($sourceId);
        if (!$source) return ['ok' => false, 'error' => 'unknown source'];
        if (HermesSources::isForbidden($source['path'])) return ['ok' => false, 'error' => 'forbidden source'];

        $cacheKey = $sourceId . ':' . md5(json_encode($params));
        $cached = DB::one('SELECT value,fetched_at,ttl FROM hermes_cache WHERE key=?', [$cacheKey]);
        if ($cached && (int)$cached['fetched_at'] + (int)$cached['ttl'] > time()) {
            $data = json_decode($cached['value'], true);
            return ['ok' => true, 'data' => is_array($data) ? $data : [], 'cached' => true];
        }

        $path = $source['path'];
        foreach ($params as $k => $v) {
            if (!preg_match('/^[A-Za-z0-9_-]+$/', (string)$k)) continue;
            if (str_contains($path, '{' . $k . '}')) {
                $path = str_replace('{' . $k . '}', rawurlencode(Util::str($v, 80)), $path);
            }
        }
        if (str_contains($path, '{')) return ['ok' => false, 'error' => 'missing path parameter'];

        $url = self::baseUrl() . $path;
        $headers = ["Accept: application/json"];
        $key = (string)(getenv('HERMES_API_KEY') ?: '');
        if ($key !== '') $headers[] = 'Authorization: Bearer ' . $key;
        $ctx = stream_context_create(['http' => [
            'method' => $source['method'], 'header' => implode("\r\n", $headers),
            'timeout' => 6, 'ignore_errors' => false,
        ]]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            return ['ok' => false, 'error' => 'Hermes unreachable', 'data' => [], 'offline' => true];
        }
        $decoded = json_decode($raw, true);
        $data = self::whitelist(is_array($decoded) ? $decoded : ['value' => $decoded], $source['fields']);
        $data = Util::scrubDeep($data);
        DB::run('INSERT INTO hermes_cache(key,value,fetched_at,ttl) VALUES(?,?,?,?)
                 ON CONFLICT(key) DO UPDATE SET value=excluded.value,fetched_at=excluded.fetched_at,ttl=excluded.ttl',
            [$cacheKey, json_encode($data), time(), (int)$source['ttl']]);
        return ['ok' => true, 'data' => $data, 'cached' => false];
    }

    private static function whitelist(array $data, array $fields): array {
        // Support both a flat object and a list of objects (most Hermes lists).
        if (array_is_list($data)) {
            return array_values(array_map(static fn($row) => is_array($row)
                ? self::pick($row, $fields) : $row, $data));
        }
        return self::pick($data, $fields);
    }

    private static function pick(array $row, array $fields): array {
        $out = [];
        foreach ($fields as $f) {
            if (array_key_exists($f, $row)) $out[$f] = $row[$f];
        }
        return $out;
    }

    public static function capabilities(): array {
        $r = self::fetch('hermes.gateway.capabilities');
        return $r['ok'] ? ($r['data']['capabilities'] ?? []) : [];
    }

    public static function deepLink(string $page): string {
        $allowed = PageRegistry::ids();
        if (!in_array($page, $allowed, true)) return self::dashboardUrl();
        return self::dashboardUrl() . '/' . $page;
    }

    public static function clearCache(): void {
        DB::run('DELETE FROM hermes_cache');
    }
}
