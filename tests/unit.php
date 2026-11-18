<?php
declare(strict_types=1);
/** Fast unit tests. Run: php tests/unit.php */
putenv('PANEL_DB=/tmp/panel-unit-' . getmypid() . '.db');
require_once __DIR__ . '/../src/lib/auth.php';
require_once __DIR__ . '/../src/lib/rbac.php';
require_once __DIR__ . '/../src/lib/engine.php';

$fail = 0; $pass = 0;
function ok(bool $cond, string $name): void {
    global $fail, $pass;
    if ($cond) { $pass++; echo "  PASS $name\n"; }
    else { $fail++; echo "  FAIL $name\n"; }
}
function section(string $s): void { echo "\n== $s ==\n"; }

section('storage');
ok(DB::one('SELECT name FROM sqlite_master WHERE type=? AND name=?', ['table','agents']) !== null,
   'agents table exists');
ok(DB::one('SELECT name FROM sqlite_master WHERE type=? AND name=?', ['table','escalations']) !== null,
   'escalations table exists');
RBAC::installDefaults();
ok(count(DB::all('SELECT id FROM agents')) >= 4, 'four default agents installed');

section('redaction — the value must never survive');
$secret = 'S3cr3t-T0ken-9f8a7b';
ok(!str_contains(Util::scrub('Authorization: Bearer ' . $secret), $secret), 'bearer token scrubbed');
ok(!str_contains(Util::scrub('api_key=' . $secret), $secret), 'key=value scrubbed');
ok(!str_contains(Util::scrub('{"password":"' . $secret . '"}'), $secret), 'JSON password scrubbed');
ok(!str_contains(Util::scrub('token: ' . $secret), $secret), 'YAML-style token scrubbed');
ok(!str_contains(Util::scrub('token: ' . base64_encode($secret)), base64_encode($secret)),
   'base64-encoded secret in a token field is scrubbed');
ok(str_contains(Util::scrub('token: ' . $secret), 'CENSORED'), 'replacement is explicit');
ok(!str_contains(Util::scrub("-----BEGIN PRIVATE KEY-----\n$secret\n-----END PRIVATE KEY-----"), $secret),
   'PEM block scrubbed');

section('permissions');
$chat = null; $system = null; $web = null;
foreach (DB::all('SELECT * FROM agents') as $a) {
    $a = RBAC::agent((int)$a['id']);
    if ($a['slug'] === 'chat') $chat = $a;
    if ($a['slug'] === 'system') $system = $a;
    if ($a['slug'] === 'webui') $web = $a;
}
[$allow] = RBAC::can($chat, 'chat'); ok($allow, 'chat agent may chat');
[$allow] = RBAC::can($chat, 'host_status'); ok(!$allow, 'chat agent may NOT read host status');
[$allow] = RBAC::can($chat, 'vault_use', ['name'=>'anything']); ok(!$allow, 'chat agent may NOT use secrets');
[$allow] = RBAC::can($chat, 'webui_edit'); ok(!$allow, 'chat agent may NOT edit the UI');
[$allow] = RBAC::can($web, 'host_status'); ok(!$allow, 'webui agent may NOT read the host');
[$allow] = RBAC::can($system, 'host_apply'); ok(!$allow, 'privileged tool needs escalation');
[$allow] = RBAC::can($system, 'vault_use', ['name'=>'x']); ok(!$allow, 'secret needs an explicit grant');
$id = RBAC::requestEscalation((int)$system['id'], 'host_apply', 'test', 60);
ok(!RBAC::activeEscalation((int)$system['id'], 'host_apply'), 'pending ask is not authority');
ok(RBAC::decide($id, true), 'owner can approve');
ok(RBAC::activeEscalation((int)$system['id'], 'host_apply'), 'approved ask grants one use');
RBAC::consumeEscalation((int)$system['id'], 'host_apply');
ok(!RBAC::activeEscalation((int)$system['id'], 'host_apply'), 'approval is one-shot');
RBAC::decide(RBAC::requestEscalation((int)$system['id'], 'host_apply', 'deny test', 60), false);
ok(!RBAC::activeEscalation((int)$system['id'], 'host_apply'), 'denial grants nothing');

section('tool execution — no tool reaches a backend without permission');
$r = Engine::executeTool($chat, 'host_status', []);
ok(empty($r['ok']) && str_contains($r['error'], 'not allowed'), 'denied tool returns no data');
$r = Engine::executeTool($chat, 'vault_use', ['name'=>'x','kind'=>'http','params'=>[]]);
ok(empty($r['ok']), 'denied vault use returns no data');
$r = Engine::executeTool($chat, 'request_escalation', ['capability'=>'host_status','reason'=>'test','ttl'=>60]);
ok(!empty($r['ok']), 'agent can request escalation');
$r = Engine::executeTool($chat, 'noop', []);
ok(!empty($r['ok']), 'noop available to every agent');

section('tool-call parsing');
ok(Engine::parseToolCall('TOOL_CALL: {"name":"host_status","args":{}}')['name'] === 'host_status',
   'strict one-line tool call');
ok(Engine::parseToolCall('I think TOOL_CALL: {"name":"x"}') === null,
   'embedded/malformed call is ignored');
ok(Engine::parseToolCall('{"tool":"notes_read","args":{}}')['name'] === 'notes_read',
   'JSON fallback form');
ok(Engine::parseToolCall('plain answer') === null, 'ordinary text is not a tool call');

@unlink(getenv('PANEL_DB'));
echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
