<?php
declare(strict_types=1);

final class Util {
    public static function json($value, int $status = 200): void {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    public static function body(): array {
        $raw = file_get_contents('php://input') ?: '';
        if ($raw === '' && !empty($_POST)) {
            return $_POST;
        }
        $d = json_decode($raw, true);
        return is_array($d) ? $d : [];
    }

    public static function str($v, int $max = 4000): string {
        return substr(trim(is_scalar($v) ? (string)$v : ''), 0, $max);
    }

    /**
     * Last line of defence. The vault broker already scrubs its own answers;
     * this catches secrets that leak in from model chatter, logs or tool
     * output. It is deliberately aggressive: a redaction is always safer than
     * a leaked credential.
     */
    public static function scrub(?string $text): string {
        if ($text === null || $text === '') return '';
        $s = $text;

        // Authorization headers and bearer/opaque tokens.
        $s = preg_replace('/(?i)(authorization\s*[:=]\s*)(bearer\s+)?[^\s"\',}]+/',
            '$1$2***CENSORED***', $s) ?? $s;
        $s = preg_replace('/(?i)(bearer\s+)[A-Za-z0-9._~+\/=-]{8,}/',
            '$1***CENSORED***', $s) ?? $s;

        // key=value / "key": "value" / key: value for credential-ish names.
        $names = 'password|passwd|pwd|secret|token|api[_-]?key|access[_-]?key|'
               . 'private[_-]?key|credential|authorization|auth[_-]?header|session[_-]?key';
        $s = preg_replace('/(?i)("(?:' . $names . ')"\s*:\s*)"[^"]*"/',
            '$1"***CENSORED***"', $s) ?? $s;
        $s = preg_replace('/(?i)\b(' . $names . ')(\s*[:=]\s*)[\'" ]?([^\s\'"\',;}]{3,})/',
            '$1$2***CENSORED***', $s) ?? $s;

        // Common vault placeholder syntax if a helper echoed the template back.
        $s = str_ireplace('{{secret}}', '***CENSORED***', $s);

        // PEM private key blocks (also URL-encoded newlines from query strings).
        $s = preg_replace('/-----BEGIN[^\n%]*PRIVATE KEY-----[\s\S]*?-----END[^\n%]*PRIVATE KEY-----/',
            '***CENSORED***', $s) ?? $s;

        // Provider-specific credential shapes and the synthetic leak-test marker.
        $patterns = [
            '/\bAKIA[0-9A-Z]{16}\b/',
            '/\bsk-[A-Za-z0-9_-]{20,}\b/',
            '/\bghp_[A-Za-z0-9]{30,}\b/',
            '/\bTEST-SECRET-DO-NOT-USE-[A-Za-z0-9-]+\b/',
            '/(?i)\bBearer[+\s][A-Za-z0-9._~+\/=-]{8,}/',
            '/(?<![A-Za-z0-9])[A-Za-z0-9+\/]{32,}={0,2}(?![A-Za-z0-9])/',
        ];
        foreach ($patterns as $pattern) {
            $s = preg_replace($pattern, '***CENSORED***', $s) ?? $s;
        }

        return $s;
    }

    /** Recursively scrub strings in arrays and scalars. */
    public static function scrubDeep(mixed $value): mixed {
        if (is_string($value)) return self::scrub($value);
        if (is_array($value)) {
            $out = [];
            foreach ($value as $k => $v) $out[$k] = self::scrubDeep($v);
            return $out;
        }
        return $value;
    }

    /** Parse the text tool-call protocol. Returns null when no valid single call exists. */
    public static function parseToolCall(string $text): ?array {
        $text = trim($text);
        if ($text === '' || strlen($text) > 8000) return null;
        $calls = [];

        if (preg_match_all('/^\s*TOOL_CALL:\s*(\{.*\})\s*$/m', $text, $m)) {
            foreach ($m[1] as $raw) {
                $d = json_decode(trim($raw), true);
                if (is_array($d) && isset($d['name']) && is_string($d['name']) && $d['name'] !== ''
                    && isset($d['args']) && preg_match('/"args"\s*:\s*\{/', $raw)) {
                    $calls[] = ['name' => $d['name'], 'args' => $d['args']];
                }
            }
        }
        if (!$calls && preg_match('/```(?:json)?\s*(\{[\s\S]*?\})\s*```/', $text, $m)) {
            $d = json_decode($m[1], true);
            if (is_array($d)) $calls[] = $d;
        }
        if (!$calls) {
            $d = json_decode($text, true);
            if (is_array($d)) $calls[] = $d;
        }
        if (count($calls) !== 1) return null;
        $call = $calls[0];
        $name = $call['name'] ?? $call['tool'] ?? null;
        $args = $call['args'] ?? null;
        if (!is_string($name) || $name === '' || !is_array($args)) return null;
        return ['name' => $name, 'args' => $args];
    }

    public static function validSlug(string $value): bool {
        return (bool) preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $value);
    }

    public static function sock(string $socket, array $payload, int $timeout = 30): array {
        $sock = @stream_socket_client('unix://' . $socket, $errno, $errstr, 5);
        if (!$sock) {
            return ['ok' => false, 'error' => "broker unavailable: $errstr"];
        }
        stream_set_timeout($sock, $timeout);
        fwrite($sock, json_encode($payload, JSON_UNESCAPED_SLASHES) . "\n");
        $buf = '';
        while (strpos($buf, "\n") === false && !feof($sock)) {
            $chunk = fread($sock, 65536);
            if ($chunk === false) break;
            $buf .= $chunk;
        }
        fclose($sock);
        $line = explode("\n", $buf, 2)[0];
        if ($line === '') return ['ok' => false, 'error' => 'broker closed the connection'];
        $decoded = json_decode($line, true);
        if (!is_array($decoded)) return ['ok' => false, 'error' => 'broker returned invalid JSON'];
        return $decoded;
    }

    public static function now(): int { return time(); }
}
