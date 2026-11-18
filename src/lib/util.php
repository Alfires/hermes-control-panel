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

        // PEM private key blocks.
        $s = preg_replace('/-----BEGIN [A-Z ]*PRIVATE KEY-----.*?-----END [A-Z ]*PRIVATE KEY-----/s',
            '***CENSORED***', $s) ?? $s;

        return $s;
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
