<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/util.php';

/** Loopback whisper.cpp proxy. No cloud service, no third container: the engine
 *  is supervised inside the webui image and is only reachable from PHP. */
final class STT {
    /** Model actually installed by the Dockerfile (upstream publishes only
     *  q5_1 for small.en; the build quantises the pinned F16 model to q5_0). */
    private const MODEL = 'ggml-small.en-q5_0.bin';
    private const MODELS = [
        'ggml-small.en-q5_0.bin',
        'ggml-base.en-q5_0.bin',
        'ggml-large-v3-turbo-q5_0.bin',
    ];

    private const STATUS_TIMEOUT = 2.0;
    private const INFERENCE_TIMEOUT = 120.0;
    private const CONNECT_TIMEOUT = 1.0;
    private const MAX_BYTES = 10 * 1024 * 1024;

    /** Only loopback HTTP is allowed, even if the environment is tampered with. */
    public static function isLoopbackEndpoint(string $url): bool {
        if (preg_match('#^https?://#i', $url) !== 1) return false;
        $parts = parse_url($url);
        if (!is_array($parts) || isset($parts['user']) || isset($parts['pass'])) return false;
        $host = strtolower((string)($parts['host'] ?? ''));
        return in_array(trim($host, '[]'), ['127.0.0.1', '::1', 'localhost'], true);
    }

    public static function endpoint(): string {
        $raw = trim((string)(getenv('STT_ENDPOINT') ?: ''));
        return $raw !== '' && self::isLoopbackEndpoint($raw) ? $raw : 'http://127.0.0.1:8713';
    }

    public static function modelDir(): string {
        return (string)(getenv('STT_MODEL_DIR') ?: '/opt/stt/models');
    }

    /** In-image copy: survives the read-only host bind-mount over modelDir(). */
    public static function bakedModelDir(): string {
        return (string)(getenv('STT_BAKED_MODEL_DIR') ?: '/opt/stt/baked');
    }

    /** @return list<string> filenames present in either model directory */
    private static function modelFiles(): array {
        $found = [];
        foreach (self::MODELS as $file) {
            if (self::modelPath($file) !== null) $found[] = $file;
        }
        return $found;
    }

    private static function modelPath(string $file): ?string {
        foreach ([self::modelDir(), self::bakedModelDir()] as $dir) {
            $path = rtrim($dir, '/') . '/' . $file;
            if (is_file($path) && is_readable($path)) return $path;
        }
        return null;
    }

    /** whisper.cpp answers 503 {"status":"loading model"} until the model is
     *  resident, so a non-empty body alone is not proof that STT is usable. */
    private static function engineUp(): bool {
        $ctx = stream_context_create(['http' => [
            'method' => 'GET',
            'header' => "Accept: application/json\r\n",
            'timeout' => self::STATUS_TIMEOUT,
            'connect_timeout' => self::CONNECT_TIMEOUT,
            'ignore_errors' => true,
        ]]);
        $body = @file_get_contents(self::endpoint() . '/health', false, $ctx);
        if ($body === false) return false;
        $status = $http_response_header[0] ?? '';
        return preg_match('#^HTTP/\S+\s+2\d\d\b#', $status) === 1;
    }

    /** @return array{ok:bool,available:bool,engine:bool,endpoint:string,models:array,max_bytes:int} */
    public static function status(): array {
        $models = self::modelFiles();
        $engine = self::engineUp();
        return [
            'ok' => true,
            'available' => $engine && count($models) > 0,
            'engine' => $engine,
            'endpoint' => 'loopback only',
            'models' => $models,
            'max_bytes' => self::MAX_BYTES,
        ];
    }

    /** Remove control characters and credential-shaped strings before anything
     *  coming from an engine response can reach the stt_jobs table. */
    public static function scrubError(string $message): string {
        $clean = Util::scrub($message);
        $clean = preg_replace('/[\r\n\t]+/', ' ', $clean) ?? $clean;
        return substr($clean, 0, 200);
    }

    /** @return array{ok:bool,text?:string,duration?:float,engine?:string,error?:string} */
    public static function transcribe(string $tmpFile, string $filename = 'audio.webm'): array {
        $started = microtime(true);

        if (!is_file($tmpFile) || !is_readable($tmpFile)) {
            self::log('error', 'audio input missing');
            return ['ok' => false, 'error' => 'audio input is not readable'];
        }
        $size = @filesize($tmpFile);
        if ($size === false || $size < 1 || $size > self::MAX_BYTES) {
            self::log('error', 'audio input size invalid');
            return ['ok' => false, 'error' => 'audio file is empty or too large'];
        }
        if (self::modelPath(self::MODEL) === null) {
            self::log('error', 'model file missing');
            return ['ok' => false, 'error' => 'local speech model is not installed'];
        }
        if (!self::engineUp()) {
            self::log('error', 'engine not ready');
            return ['ok' => false, 'error' => 'local speech engine is not ready'];
        }

        $audio = @file_get_contents($tmpFile);
        if ($audio === false || $audio === '') {
            self::log('error', 'audio read failed');
            return ['ok' => false, 'error' => 'could not read audio file'];
        }

        $boundary = 'hcp' . bin2hex(random_bytes(16));
        $name = preg_replace('/[^A-Za-z0-9_.-]/', '_', basename($filename)) ?: 'audio.webm';
        $body = "--$boundary\r\n"
            . "Content-Disposition: form-data; name=\"file\"; filename=\"$name\"\r\n"
            . "Content-Type: application/octet-stream\r\n\r\n"
            . $audio . "\r\n--$boundary--\r\n";
        $ctx = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: multipart/form-data; boundary=$boundary\r\nContent-Length: " . strlen($body),
            'content' => $body,
            'timeout' => self::INFERENCE_TIMEOUT,
            'connect_timeout' => self::CONNECT_TIMEOUT,
            'ignore_errors' => true,
        ]]);
        $raw = @file_get_contents(self::endpoint() . '/inference', false, $ctx);
        $duration = round(microtime(true) - $started, 3);
        if ($raw === false) {
            self::log('error', 'engine request failed');
            return ['ok' => false, 'error' => 'speech engine request failed'];
        }
        $status = $http_response_header[0] ?? '';
        if (preg_match('#^HTTP/\S+\s+[45]\d\d\b#', $status) === 1) {
            // Deliberately do not log the body: a proxy/engine may echo headers.
            self::log('error', 'engine HTTP error');
            return ['ok' => false, 'error' => 'speech engine request failed'];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || isset($decoded['error'])) {
            self::log('error', 'engine response invalid');
            return ['ok' => false, 'error' => 'speech engine returned an invalid response'];
        }
        $text = isset($decoded['text']) && is_scalar($decoded['text']) ? (string)$decoded['text'] : '';
        self::log('done', '', $duration);
        return [
            'ok' => true,
            'text' => mb_substr($text, 0, 4000),
            'duration' => $duration,
            'engine' => 'whisper.cpp (local)',
        ];
    }

    private static function log(string $status, string $error = '', ?float $duration = null): void {
        // Logging is best-effort: a broken DB must never break transcription,
        // and no raw engine response is ever stored.
        try {
            DB::run(
                'INSERT INTO stt_jobs(status,duration,engine,error,created_at) VALUES(?,?,?,?,?)',
                [$status, $duration, 'whisper.cpp', self::scrubError($error), time()]
            );
        } catch (Throwable $e) {
            // swallowed: no secret values or paths are written anywhere
        }
    }
}
