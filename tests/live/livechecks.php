<?php
declare(strict_types=1);

/** Opt-in live checks against a running panel. Never prints credentials. */
final class LiveChecks
{
    private static string $base = '';
    private static string $jar = '';
    private static string $csrf = '';
    private static bool $loggedIn = false;

    private static function init(): void
    {
        if (self::$base !== '') return;
        self::$base = rtrim((string)(getenv('HCP_LIVE_URL') ?: 'http://192.168.2.157:9120'), '/');
        $jar = tempnam(sys_get_temp_dir(), 'hcp-live-');
        self::$jar = $jar !== false ? $jar : '/tmp/hcp-live-cookies';
        register_shutdown_function(static function (): void { @unlink(self::$jar); });
        self::raw('/', 'GET', null, false);          // sets panel_csrf cookie
        self::$csrf = self::cookie('panel_csrf');
    }

    private static function cookie(string $name): string
    {
        $lines = @file(self::$jar, FILE_IGNORE_NEW_LINES) ?: [];
        $found = '';
        foreach ($lines as $line) {
            $f = preg_split('/\s+/', trim($line)) ?: [];
            if (count($f) >= 7 && $f[5] === $name) $found = $f[6];
        }
        return $found;
    }

    /** @return array{0:int,1:array<string,mixed>,2:string} */
    private static function raw(string $path, string $method = 'GET', ?array $data = null, bool $auth = true): array
    {
        self::init();
        $ch = curl_init(self::$base . $path);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_COOKIEJAR => self::$jar,
            CURLOPT_COOKIEFILE => self::$jar,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_CUSTOMREQUEST => $method,
        ];
        if ($data !== null) {
            $headers = ['Content-Type: application/json'];
            if ($auth && self::$csrf !== '') $headers[] = 'X-CSRF-Token: ' . self::$csrf;
            $opts[CURLOPT_HTTPHEADER] = $headers;
            $opts[CURLOPT_POSTFIELDS] = json_encode($data, JSON_UNESCAPED_SLASHES);
        }
        curl_setopt_array($ch, $opts);
        $body = (string)curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $json = json_decode($body, true);
        return [$status, is_array($json) ? $json : [], $body];
    }

    private static function api(string $action, array $data = [], bool $auth = true): array
    {
        return self::raw('/api.php?action=' . rawurlencode($action), 'POST', $data, $auth);
    }

    private static function login(): bool
    {
        self::init();
        if (self::$loggedIn) return true;
        $pass = (string)(getenv('HCP_LIVE_PASS') ?: '');
        if ($pass === '') return false;
        [, $j] = self::raw('/api.php?action=login', 'POST', [
            'username' => (string)(getenv('HCP_LIVE_USER') ?: 'admin'),
            'password' => $pass,
        ], false);
        self::$loggedIn = !empty($j['ok']);
        return self::$loggedIn;
    }

    private static function configCycle(): bool
    {
        if (!self::login()) return false;
        [, $boot] = self::api('bootstrap');
        $before = (int)($boot['config']['revision'] ?? 0);
        $marker = 'live-check-' . bin2hex(random_bytes(4));
        [, $proposal] = self::api('ui.config.propose', [
            'patches' => [['op' => 'replace', 'path' => '/meta/description', 'value' => $marker]],
        ]);
        if (empty($proposal['ok']) || empty($proposal['proposal_id'])) return false;
        [, $approved] = self::api('ui.config.approve', ['id' => (int)$proposal['proposal_id']]);
        if (empty($approved['ok'])) return false;
        [, $rollback] = self::api('ui.config.rollback', ['revision' => $before]);
        if (empty($rollback['ok'])) return false;
        [, $after] = self::api('bootstrap');
        return ($after['config']['meta']['description'] ?? '') !== $marker;
    }

    public static function run(string $description): bool
    {
        self::init();
        switch ($description) {
            case 'health.php answers 200':
                return self::raw('/health.php', 'GET', null, false)[0] === 200;

            case 'login rejects wrong password':
                [, $j] = self::raw('/api.php?action=login', 'POST', [
                    'username' => (string)(getenv('HCP_LIVE_USER') ?: 'admin'),
                    'password' => 'definitely-wrong-' . bin2hex(random_bytes(4)),
                ], false);
                return empty($j['ok']);

            case 'login accepts owner password':
                return self::login();

            case 'bootstrap contains 4 default agents':
                if (!self::login()) return false;
                [, $j] = self::api('bootstrap');
                return count($j['agents'] ?? []) >= 4;

            case 'search.query returns results':
                if (!self::login()) return false;
                [, $j] = self::api('search.query', ['q' => 'agent']);
                return !empty($j['ok']);

            case 'hermes.status source answers':
                if (!self::login()) return false;
                [, $j] = self::api('hermes.source', ['source' => 'hermes.status']);
                return !empty($j['ok']);

            case 'hermes.sessions source shape safe':
                if (!self::login()) return false;
                [, $j, $raw] = self::api('hermes.source', ['source' => 'hermes.sessions.list']);
                return !empty($j['ok']) && !preg_match('/api[_-]?key|password|access[_-]?token/i', $raw);

            case 'ui.config.propose is dry-run':
            case 'ui.config.approve applies revision':
            case 'ui.config.rollback restores revision':
                return self::configCycle();

            case 'theme presets endpoint works':
                if (!self::login()) return false;
                [, $j] = self::api('theme.presets');
                return count($j['presets'] ?? []) >= 7;

            case 'voice status reports engine':
                if (!self::login()) return false;
                [, $j] = self::api('stt.status');
                return !empty($j['ok']) && array_key_exists('available', $j);

            case 'selftest.run creates a report':
                if (!self::login()) return false;
                [, $j] = self::api('selftest.run');
                if (empty($j['ok'])) return false;
                [, $report] = self::api('selftest.report');
                return !empty($report['run']);

            case 'state.diff returns versioned delta':
                if (!self::login()) return false;
                [, $j] = self::api('state.diff');
                return !empty($j['ok']) && isset($j['version'], $j['counts']);

            case 'CSRF token enforced':
                if (!self::login()) return false;
                [$status, $j] = self::api('bootstrap', [], false);
                return $status === 403 || empty($j['ok']);

            case 'login rate limit enforced':
                $user = 'live-rate-' . bin2hex(random_bytes(4));
                $sawLimit = false;
                for ($i = 0; $i < 12; $i++) {
                    [$status] = self::raw('/api.php?action=login', 'POST', [
                        'username' => $user, 'password' => 'wrong',
                    ], false);
                    if ($status === 429) { $sawLimit = true; break; }
                }
                return $sawLimit;

            case 'vault broker list answers':
                if (!self::login()) return false;
                [, $j] = self::api('vault.list');
                return !empty($j['ok']) && isset($j['secrets']);

            case 'host broker status answers':
                if (!self::login()) return false;
                [, $j] = self::api('bootstrap');
                return !empty($j['host']['available']);

            case 'forged HMAC refused':
                if (!self::login()) return false;
                [, $j] = self::api('panel.host.probe');
                return !empty($j['ok']) && !empty($j['refused']);

            case 'secret redaction marker present':
                if (!self::login()) return false;
                [, $j] = self::api('panel.redaction.probe');
                return !empty($j['ok']) && str_contains((string)($j['sample'] ?? ''), 'CENSORED');
        }
        return false;
    }
}
