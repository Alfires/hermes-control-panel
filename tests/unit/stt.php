<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';

/**
 * STT contract tests: source-level guarantees (image paths, pinned downloads,
 * no new exposed port, no secrets) plus pure-PHP behaviour that needs no binary.
 * Run standalone with `php tests/unit/stt.php` or via `php tests/suite.php`.
 */
$sttDb = sys_get_temp_dir() . '/hcp-stt-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.db';
putenv('PANEL_DB=' . $sttDb);
register_shutdown_function(static function () use ($sttDb): void {
    foreach ([$sttDb, $sttDb . '-wal', $sttDb . '-shm'] as $p) @unlink($p);
});

require_once repo_path('src/lib/stt.php');

$sttRoot = repo_path();
$sttDocker = (string)@file_get_contents($sttRoot . '/Dockerfile');
$sttSupervisor = (string)@file_get_contents($sttRoot . '/supervisord.conf');
$sttSource = (string)@file_get_contents($sttRoot . '/src/lib/stt.php');
$sttDocs = (string)@file_get_contents($sttRoot . '/docs/STT.md');
$sttModel = 'ggml-small.en-q5_0.bin';

/** Run a closure with temporary STT_* env vars, then restore them exactly. */
function sttTestEnv(array $vars, callable $fn): void {
    $saved = [];
    foreach ($vars as $name => $value) {
        $saved[$name] = getenv($name);
        if ($value === null) { putenv($name); unset($_ENV[$name]); }
        else { putenv($name . '=' . $value); $_ENV[$name] = $value; }
    }
    try { $fn(); } finally {
        foreach ($saved as $name => $value) {
            if ($value === false) { putenv($name); unset($_ENV[$name]); }
            else { putenv($name . '=' . $value); $_ENV[$name] = $value; }
        }
    }
}

/** Fresh temp model directory; callback receives its path. */
function sttTestDir(callable $fn): void {
    $dir = sys_get_temp_dir() . '/hcp-stt-models-' . bin2hex(random_bytes(6));
    @mkdir($dir, 0770, true);
    try { $fn($dir); } finally {
        foreach (glob($dir . '/*') ?: [] as $f) @unlink($f);
        @rmdir($dir);
    }
}

// ---- image / supervisor source contracts ---------------------------------

test('stt.src.dockerfile_exists', static function () use ($sttDocker): void {
    ok(strlen($sttDocker) > 500, 'Dockerfile must be readable');
});

test('stt.src.pinned_whisper_tag', static function () use ($sttDocker): void {
    contains($sttDocker, 'WHISPER_VERSION=v1.7.6', 'whisper.cpp tag must be pinned');
    contains($sttDocker, 'ggml-org/whisper.cpp/archive/refs/tags/${WHISPER_VERSION}', 'tag must drive the download URL');
});

test('stt.src.source_archive_checksum', static function () use ($sttDocker): void {
    ok((bool)preg_match('/WHISPER_SRC_SHA256=[0-9a-f]{64}/', $sttDocker), 'source archive digest must be pinned');
    contains($sttDocker, 'sha256sum -c -', 'source download must be verified');
});

test('stt.src.pinned_model_revision_and_sha', static function () use ($sttDocker): void {
    ok((bool)preg_match('/WHISPER_MODEL_REV=[0-9a-f]{40}/', $sttDocker), 'HF revision must be pinned');
    ok((bool)preg_match('/WHISPER_MODEL_SHA256=[0-9a-f]{64}/', $sttDocker), 'published model sha256 must be pinned');
    contains($sttDocker, 'ggml-small.en.bin', 'download is the pinned upstream F16 model');
    contains($sttDocker, 'resolve/${WHISPER_MODEL_REV}/', 'download must use the pinned revision');
});

test('stt.src.quantizes_exact_model_filename', static function () use ($sttDocker, $sttModel): void {
    contains($sttDocker, 'quantize /tmp/ggml-small.en.bin /opt/stt/baked/' . $sttModel . ' q5_0',
        'q5_0 must be produced at the exact filename the guard expects');
    contains($sttDocker, '/opt/stt/models/' . $sttModel, 'modelDir path must be present in the Dockerfile');
});

test('stt.src.engine_binary_installed', static function () use ($sttDocker): void {
    contains($sttDocker, 'whisper-server /opt/stt/whisper-server', 'server must be installed at the guarded path');
    contains($sttDocker, '--target whisper-server quantize', 'only the two needed targets should be built');
});

test('stt.src.build_stage_is_discarded', static function () use ($sttDocker): void {
    contains($sttDocker, 'AS stt-build', 'multi-stage build stage must exist');
    ok(substr_count($sttDocker, "\nFROM ") >= 1, 'a separate runtime FROM must follow the builder');
    contains($sttDocker, 'COPY --from=stt-build /opt/stt/ /opt/stt/', 'only /opt/stt is copied to runtime');
    contains($sttDocker, 'rm -rf /tmp/whisper-src', 'builder sources must be removed');
});

test('stt.src.ffmpeg_and_cpp_runtime_present', static function () use ($sttDocker): void {
    contains($sttDocker, 'ffmpeg', 'browser webm needs ffmpeg transcoding');
    contains($sttDocker, 'libstdc++', 'C++ server runtime library must be installed');
});

test('stt.src.no_new_exposed_port', static function () use ($sttDocker): void {
    $exposes = [];
    foreach (explode("\n", $sttDocker) as $line) {
        if (preg_match('/^\s*EXPOSE\s+(.+)$/', $line, $m)) $exposes[] = trim($m[1]);
    }
    eq($exposes, ['8080'], 'only the existing panel port may be exposed');
    ok(!preg_match('/^\s*EXPOSE\s+.*8713/m', $sttDocker), '8713 must stay loopback-only');
});

test('stt.src.no_third_container_or_service', static function () use ($sttDocker, $sttSupervisor): void {
    eq(substr_count($sttDocker, 'ENTRYPOINT '), 1, 'one entrypoint only');
    eq(substr_count($sttSupervisor, '[program:'), 4, 'php-fpm, nginx, crond and stt only');
});

test('stt.src.build_stage_installs_git_for_ggml_cmake', static function () use ($sttDocker): void {
    // ggml/CMakeLists.txt calls find_program(GIT_EXE) unconditionally; without
    // git in the build stage cmake configure fails and the whole image build
    // dies. This regresses the Deck deploy failure seen on 2026-11-19.
    ok((bool) preg_match('/^RUN apk add[^\n]*\bgit\b/m', $sttDocker),
       'build-stage apk add must include git (ggml find_program GIT_EXE)');
});

test('stt.src.supervisor_guard_is_honest', static function () use ($sttSupervisor, $sttModel): void {
    contains($sttSupervisor, '[program:stt]');
    contains($sttSupervisor, '[ -x /opt/stt/whisper-server ]', 'binary guard');
    contains($sttSupervisor, '[ -r /opt/stt/models/' . $sttModel . ' ]', 'primary model guard');
    contains($sttSupervisor, '[ -r /opt/stt/baked/' . $sttModel . ' ]', 'baked fallback guard');
    contains($sttSupervisor, 'sleep 3600', 'idle fallback must remain honest');
    contains($sttSupervisor, 'stt: engine or model missing', 'guard must explain why it idles');
});

test('stt.src.supervisor_loopback_engine', static function () use ($sttSupervisor, $sttModel): void {
    contains($sttSupervisor, '-m \"$M\" --host 127.0.0.1 --port 8713', 'loopback bind, unchanged port');
    contains($sttSupervisor, '--convert', 'webm/opus must be converted to WAV');
    contains($sttSupervisor, '--public /opt/stt/public');
    contains($sttSupervisor, '-m \"$M\"', 'chosen model path must be passed to the engine');
    ok(preg_match_all('#\[ -r /opt/stt/(?:models|baked)/' . preg_quote($sttModel, '#') . ' \]#', $sttSupervisor) === 2,
        'both readable model paths are guarded exactly once');
});

test('stt.src.model_filename_consistent', static function () use ($sttDocker, $sttSupervisor, $sttSource, $sttModel): void {
    $reflected = new ReflectionClassConstant('STT', 'MODEL');
    eq($reflected->getValue(), $sttModel, 'PHP constant must name the installed model');
    contains($sttDocker, $sttModel);
    contains($sttSupervisor, $sttModel);
});

test('stt.src.docs_exist_and_are_honest', static function () use ($sttDocs): void {
    ok(strlen($sttDocs) > 800, 'docs/STT.md must exist with real content');
    contains($sttDocs, 'stt.status', 'verification path must be documented');
    contains($sttDocs, 'licence', 'model licence must be documented');
    ok(stripos($sttDocs, 'caveat') !== false, 'honest caveats section required');
});

foreach (['Dockerfile' => $sttDocker, 'supervisord.conf' => $sttSupervisor,
          'src/lib/stt.php' => $sttSource, 'docs/STT.md' => $sttDocs] as $label => $content) {
    test('stt.src.no_secret_literal.' . $label, static function () use ($content, $label): void {
        ok(!preg_match('/TEST-SECRET-DO-NOT-USE|AKIA[0-9A-Z]{16}|ghp_[A-Za-z0-9]{30,}|sk-[A-Za-z0-9_-]{24,}/', $content),
            "$label contains a credential-shaped literal");
        ok(!preg_match('/curl\s+(-u|--user|--header\s+["\']?Authorization)/i', $content),
            "$label contains an inline credential-bearing curl");
    });
}

// ---- pure PHP behaviour ---------------------------------------------------

test('stt.endpoint.default_is_loopback', static function (): void {
    sttTestEnv(['STT_ENDPOINT' => null], static function (): void {
        eq(STT::endpoint(), 'http://127.0.0.1:8713');
    });
});

test('stt.dirs.defaults', static function (): void {
    sttTestEnv(['STT_MODEL_DIR' => null, 'STT_BAKED_MODEL_DIR' => null], static function (): void {
        eq(STT::modelDir(), '/opt/stt/models');
        eq(STT::bakedModelDir(), '/opt/stt/baked');
    });
});

$loopbackCases = [
    'http://127.0.0.1:8713' => true,
    'http://[::1]:8713' => true,
    'http://localhost:8713' => true,
    'http://192.168.2.157:8713' => false,
    'http://127.0.0.1.evil.test:8713' => false,
    'ftp://127.0.0.1:8713' => false,
    'http://user:pass@127.0.0.1:8713' => false,
    'not a url' => false,
];
$i = 0;
foreach ($loopbackCases as $url => $expected) {
    $i++;
    test('stt.endpoint.loopback.' . $i, static function () use ($url, $expected): void {
        eq(STT::isLoopbackEndpoint($url), $expected, "loopback decision for $url");
    });
}

test('stt.endpoint.rejects_tampered_env', static function (): void {
    sttTestEnv(['STT_ENDPOINT' => 'http://attacker.example/inference'], static function (): void {
        eq(STT::endpoint(), 'http://127.0.0.1:8713', 'non-loopback env must be ignored');
    });
});

test('stt.status.shape_is_unchanged', static function () use ($sttModel): void {
    sttTestEnv(['STT_MODEL_DIR' => '/nonexistent-stt-models', 'STT_BAKED_MODEL_DIR' => '/nonexistent-stt-baked',
                'STT_ENDPOINT' => 'http://127.0.0.1:1'], static function () use ($sttModel): void {
        $s = STT::status();
        eq(array_keys($s), ['ok', 'available', 'engine', 'endpoint', 'models', 'max_bytes']);
        eq($s['ok'], true);
        eq($s['engine'], false);
        eq($s['available'], false);
        eq($s['endpoint'], 'loopback only');
        eq($s['models'], []);
        eq($s['max_bytes'], 10 * 1024 * 1024);
        ok(!isset($s['text']), 'status must not grow a text field');
        ok(!str_contains(json_encode($s), $sttModel), 'no model should be advertised');
    });
});

test('stt.status.model_in_primary_dir', static function () use ($sttModel): void {
    sttTestDir(static function (string $dir) use ($sttModel): void {
        file_put_contents($dir . '/' . $sttModel, 'not-a-real-model');
        sttTestEnv(['STT_MODEL_DIR' => $dir, 'STT_BAKED_MODEL_DIR' => '/nonexistent-stt-baked',
                    'STT_ENDPOINT' => 'http://127.0.0.1:1'], static function () use ($sttModel): void {
            $s = STT::status();
            eq($s['models'], [$sttModel]);
            eq($s['engine'], false);
            eq($s['available'], false, 'a file alone is not a running engine');
        });
    });
});

test('stt.status.model_in_baked_fallback', static function () use ($sttModel): void {
    sttTestDir(static function (string $dir) use ($sttModel): void {
        file_put_contents($dir . '/' . $sttModel, 'not-a-real-model');
        sttTestEnv(['STT_MODEL_DIR' => '/nonexistent-stt-models', 'STT_BAKED_MODEL_DIR' => $dir,
                    'STT_ENDPOINT' => 'http://127.0.0.1:1'], static function () use ($sttModel): void {
            eq(STT::status()['models'], [$sttModel], 'baked fallback must be discoverable');
        });
    });
});

test('stt.transcribe.missing_input', static function (): void {
    $r = STT::transcribe('/nonexistent-stt-audio.webm');
    eq(array_keys($r), ['ok', 'error']);
    eq($r['ok'], false);
    ok(is_string($r['error']) && $r['error'] !== '');
});

test('stt.transcribe.empty_input', static function (): void {
    $f = tmpfile();
    $path = stream_get_meta_data($f)['uri'];
    try { $r = STT::transcribe($path); eq($r['ok'], false); eq(array_keys($r), ['ok', 'error']); }
    finally { fclose($f); }
});

test('stt.transcribe.oversized_input', static function (): void {
    $f = tmpfile();
    $path = stream_get_meta_data($f)['uri'];
    ftruncate($f, 10 * 1024 * 1024 + 1);
    try {
        $r = STT::transcribe($path);
        eq($r['ok'], false);
        contains($r['error'], 'too large');
    } finally { fclose($f); }
});

test('stt.transcribe.missing_model', static function (): void {
    $f = tmpfile();
    $path = stream_get_meta_data($f)['uri'];
    fwrite($f, 'RIFFfake');
    sttTestEnv(['STT_MODEL_DIR' => '/nonexistent-stt-models', 'STT_BAKED_MODEL_DIR' => '/nonexistent-stt-baked'],
        static function () use ($path): void {
            $r = STT::transcribe($path);
            eq($r['ok'], false);
            contains($r['error'], 'model');
        });
    fclose($f);
});

test('stt.transcribe.engine_down_shape', static function () use ($sttModel): void {
    sttTestDir(static function (string $dir) use ($sttModel): void {
        file_put_contents($dir . '/' . $sttModel, 'not-a-real-model');
        $f = tmpfile();
        $path = stream_get_meta_data($f)['uri'];
        fwrite($f, 'RIFFfake');
        sttTestEnv(['STT_MODEL_DIR' => $dir, 'STT_BAKED_MODEL_DIR' => '/nonexistent-stt-baked',
                    'STT_ENDPOINT' => 'http://127.0.0.1:1'], static function () use ($path): void {
            $r = STT::transcribe($path);
            eq(array_keys($r), ['ok', 'error']);
            eq($r['ok'], false);
            contains($r['error'], 'not ready');
        });
        fclose($f);
    });
});

test('stt.scrub_error.scrubs_and_truncates', static function (): void {
    $secret = fake_secret(77);
    $out = STT::scrubError("engine said: $secret\nsecond line " . str_repeat('x', 400));
    not_contains($out, $secret, 'secret must not survive scrubbing');
    ok(strlen($out) <= 200, 'log messages are capped at 200 chars');
    not_contains($out, "\n", 'newlines must not survive scrubbing');
});

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    exit(run_tests());
}
