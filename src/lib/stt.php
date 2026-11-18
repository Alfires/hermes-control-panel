<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/util.php';

/** Loopback whisper.cpp proxy. No cloud service, no third container: the engine
 *  is supervised inside the webui image and is only reachable from PHP. */
final class STT {
    public static function endpoint(): string {
        return (string)(getenv('STT_ENDPOINT') ?: 'http://127.0.0.1:8713');
    }

    public static function modelDir(): string {
        return (string)(getenv('STT_MODEL_DIR') ?: '/opt/stt/models');
    }

    public static function status(): array {
        $models = [];
        foreach (['ggml-small.en-q5_0.bin', 'ggml-base.en-q5_0.bin', 'ggml-large-v3-turbo-q5_0.bin'] as $f) {
            $path = self::modelDir() . '/' . $f;
            if (is_file($path)) $models[] = $f;
        }
        $engine = false;
        $ctx = stream_context_create(['http' => ['timeout' => 1, 'ignore_errors' => true]]);
        $ping = @file_get_contents(self::endpoint() . '/health', false, $ctx);
        if ($ping !== false) $engine = true;
        return [
            'ok' => true, 'available' => $engine && count($models) > 0,
            'engine' => $engine, 'endpoint' => 'loopback only', 'models' => $models,
            'max_bytes' => 10 * 1024 * 1024,
        ];
    }

    public static function transcribe(string $tmpFile, string $filename = 'audio.webm'): array {
        $started = microtime(true);
        $status = self::status();
        if (!$status['available']) {
            self::log('error', 'engine unavailable');
            return ['ok' => false, 'error' => 'local speech engine is not ready'];
        }
        $boundary = 'hcp' . bin2hex(random_bytes(8));
        $name = preg_replace('/[^A-Za-z0-9_.-]/', '_', basename($filename));
        $body = "--$boundary\r\n"
            . "Content-Disposition: form-data; name=\"file\"; filename=\"$name\"\r\n"
            . "Content-Type: application/octet-stream\r\n\r\n"
            . file_get_contents($tmpFile) . "\r\n--$boundary--\r\n";
        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: multipart/form-data; boundary=$boundary\r\nContent-Length: " . strlen($body),
            'content' => $body, 'timeout' => 60,
        ]]);
        $raw = @file_get_contents(self::endpoint() . '/inference', false, $ctx);
        $duration = round(microtime(true) - $started, 3);
        if ($raw === false) {
            self::log('error', 'engine request failed');
            return ['ok' => false, 'error' => 'speech engine request failed'];
        }
        $decoded = json_decode($raw, true);
        $text = is_array($decoded) ? (string)($decoded['text'] ?? '') : '';
        self::log('done', $duration);
        return ['ok' => true, 'text' => mb_substr($text, 0, 4000), 'duration' => $duration, 'engine' => 'whisper.cpp (local)'];
    }

    private static function log(string $status, string $error = ''): void {
        DB::run('INSERT INTO stt_jobs(status,duration,engine,error,created_at) VALUES(?,?,?,?,?)',
            [$status, null, 'whisper.cpp', substr($error, 0, 200), time()]);
    }
}
