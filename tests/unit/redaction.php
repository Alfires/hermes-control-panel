<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
lib('util.php');

// 40 string-redaction cases: every credential-like pattern must disappear.
$bases = [
    'AKIA' . str_repeat('A', 16),
    'sk-' . str_repeat('x', 32),
    'ghp_' . str_repeat('q', 36),
    'Bearer ' . str_repeat('b', 40),
    '-----BEGIN PRIVATE KEY-----' . "\n" . str_repeat('p', 44) . "\n-----END PRIVATE KEY-----",
    fake_secret(1),
    fake_secret(2),
    'password=' . str_repeat('s', 24),
    'api_key=' . str_repeat('k', 28),
    str_repeat('Z', 64),
];
$wrappers = [
    'plain' => static fn(string $s): string => $s,
    'json' => static fn(string $s): string => json_encode(['value' => $s], JSON_UNESCAPED_SLASHES),
    'header' => static fn(string $s): string => "Authorization: $s\r\nX-Test: ok",
    'url' => static fn(string $s): string => 'https://example.com/?token=' . rawurlencode($s),
    'multiline' => static fn(string $s): string => "line one\n$s\nline three",
];
$i = 0;
foreach ($bases as $bi => $secret) {
    foreach ($wrappers as $wname => $wrap) {
        $i++;
        test("redaction.string.$i", static function () use ($secret, $wrap, $wname, $bi): void {
            $input = $wrap($secret);
            $out = Util::scrub($input);
            not_contains($out, $secret, "wrapper $wname leaked base $bi");
            contains($out, '***CENSORED***', "wrapper $wname missing redaction marker");
        });
    }
}

// 20 deep-structure redaction cases.
$shapes = [
    static fn(string $s): array => ['value' => $s],
    static fn(string $s): array => ['nested' => ['deep' => ['secret' => $s]]],
    static fn(string $s): array => ['list' => [$s, 'safe', ['k' => $s]]],
    static fn(string $s): array => ['Authorization' => "Bearer $s", 'body' => "key=$s"],
];
for ($n = 1; $n <= 20; $n++) {
    $shape = $shapes[($n - 1) % count($shapes)];
    $secret = fake_secret(100 + $n);
    test("redaction.deep.$n", static function () use ($shape, $secret, $n): void {
        $out = Util::scrubDeep($shape($secret));
        $encoded = json_encode($out);
        not_contains($encoded, $secret, "deep shape $n leaked");
        contains($encoded, '***CENSORED***', "deep shape $n missing marker");
    });
}
