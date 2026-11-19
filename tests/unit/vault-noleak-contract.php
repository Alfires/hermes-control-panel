<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';

// The vault contract the owner asked for: agents may USE real credentials but
// can never see or print them. The root broker does value-based redaction; the
// PHP side double-scrubs and exposes only list/use (never get). These checks
// keep that contract visible in the local suite; the live proof is
// tests/leak-probe.sh against the real root broker.
$util = (string) file_get_contents(repo_path('src/lib/util.php'));
$vault = (string) file_get_contents(repo_path('src/lib/vault.php'));
$api = (string) file_get_contents(repo_path('src/public/api.php'));
$probe = (string) file_get_contents(repo_path('tests/leak-probe.sh'));
$echo = (string) file_get_contents(repo_path('tests/leak_echo_server.py'));

test('vault.noleak.php.scrubs.placeholder.and.shapes', static function () use ($util): void {
    contains($util, "'{{secret}}'", 'vault placeholder must be censored on the PHP side');
    contains($util, 'authorization', 'Authorization headers must be censored');
    contains($util, 'private', 'PEM private keys must be censored');
    contains($util, 'AKIA', 'AWS key shapes must be censored');
    contains($util, 'sk-', 'OpenAI-style key shapes must be censored');
    contains($util, 'function scrubDeep', 'recursive scrubbing must exist');
});

test('vault.noleak.list.and.use.only.no.get', static function () use ($vault): void {
    contains($vault, "'op' => 'list'", 'list operation must exist');
    contains($vault, "'op' => 'use'", 'use operation must exist');
    not_contains($vault, "'op' => 'get'", 'there must be no secret-read operation');
    not_contains($vault, "'op' => 'read'", 'there must be no secret-read operation');
    contains($vault, 'scrubTree($reply)', 'every vault reply must be scrubbed before return');
    contains($vault, "DB::audit", 'every use must be audited');
});

test('vault.noleak.agent.never.supplies.a.value', static function () use ($api, $vault): void {
    contains($vault, 'The agent never supplies or receives a value', 'contract must be documented in code');
    // vault.test accepts only a logical name, kind, purpose and params - no value field.
    $pos = strpos($api, "case 'vault.test':");
    ok($pos !== false, 'vault.test action missing');
    $block = substr($api, $pos, 700);
    not_contains($block, "\$b['value']", 'vault.test must not accept a raw value');
    not_contains($block, "\$b['secret']", 'vault.test must not accept a raw secret');
    contains($block, "'name'", 'vault.test must take a logical secret name');
});

test('vault.noleak.print.probe.covers.all.encodings', static function () use ($probe, $echo): void {
    contains($probe, 'leak-probe.sh', 'probe must identify itself');
    contains($probe, 'vault.test', 'probe must call the real owner test action');
    contains($probe, 'vault.py add', 'probe must store a real synthetic secret');
    contains($probe, 'vault.py rm', 'probe must remove the secret afterwards');
    contains($probe, 'approval policy', 'probe must pre-authorise so it runs unattended');
    contains($echo, 'base64', 'probe must echo the base64 form');
    contains($echo, 'quote', 'probe must echo the URL-encoded form');
    contains($echo, '"nested"', 'probe must echo a nested credential shape');
    contains($echo, '"error"', 'probe must echo a printed-variable error string');
});
