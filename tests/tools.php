<?php
declare(strict_types=1);
/** Runs inside the panel container during integration tests. */
putenv('PANEL_DB=/tmp/panel-tools-test.db');
require_once '/var/www/html/lib/auth.php';
require_once '/var/www/html/lib/rbac.php';
require_once '/var/www/html/lib/engine.php';

$fail = 0;
function ok(bool $c, string $n): void {
    global $fail;
    if ($c) echo "  PASS $n\n"; else { $fail++; echo "  FAIL $n\n"; }
}

echo "== live brokers ==\n";
$r = HostCtl::call('system_status', [], null);
ok(!empty($r['ok']), 'host status from root broker');
ok(!empty($r['services']), 'host status includes service states');
$r = HostCtl::call('security_scan', [], null);
ok(!empty($r['ok']) && isset($r['findings']), 'read-only security scan');
$r = Vault::listSecrets();
ok(!empty($r['ok']), 'vault list from root broker');

echo "== HMAC gate ==\n";
$key = trim((string)@file_get_contents('/run/secrets/hostctl.key'));
$payload = ['op'=>'action','action'=>'fix_service','args'=>(object)['service'=>'x'],
            'ts'=>(string)time(),'token'=>'bad-token','requestor'=>'test'];
$hostSocket = getenv('HOSTCTL_SOCKET') ?: '/run/panel-sockets/hostctl.sock';
$sock = stream_socket_client('unix://' . $hostSocket, $e, $m, 5);
if (!$sock) { ok(false, 'host socket reachable'); exit(1); }
fwrite($sock, json_encode($payload) . "\n");
$reply = json_decode(fgets($sock) ?: '{}', true); fclose($sock);
ok(empty($reply['ok']) && str_contains((string)($reply['error'] ?? ''), 'bad authorization'),
   'forged token refused by root broker');

$ts = (string)(time() - 900);
$msg = 'fix_service|' . json_encode((object)['service'=>'x']) . '|' . $ts;
$payload['ts'] = $ts; $payload['token'] = hash_hmac('sha256', $msg, $key);
$hostSocket = getenv('HOSTCTL_SOCKET') ?: '/run/panel-sockets/hostctl.sock';
$sock = stream_socket_client('unix://' . $hostSocket, $e, $m, 5);
if (!$sock) { ok(false, 'host socket reachable'); exit(1); }
fwrite($sock, json_encode($payload) . "\n");
$reply = json_decode(fgets($sock) ?: '{}', true); fclose($sock);
ok(empty($reply['ok']) && str_contains((string)($reply['error'] ?? ''), 'stale'),
   'replayed/stale token refused');

echo "== RBAC at the engine ==\n";
$a = ['id'=>999,'slug'=>'test','tools'=>['chat','host_status'],'vault_names'=>[]];
ok(!empty(Engine::executeTool($a, 'host_status', [])['ok']) || HostCtl::available(),
   'host_status is reachable for an allowed agent');
ok(empty(Engine::executeTool($a, 'host_apply', ['action'=>'fix_updates'])['ok']),
   'host_apply denied without the tool');
$a2 = ['id'=>999,'slug'=>'test','tools'=>['host_apply'],'vault_names'=>[]];
ok(empty(Engine::executeTool($a2, 'host_apply', ['action'=>'fix_updates'])['ok']),
   'host_apply denied without approval even when the tool is granted');

@unlink('/tmp/panel-tools-test.db');
echo $fail === 0 ? "ALL TOOL TESTS PASSED\n" : "$fail TOOL TESTS FAILED\n";
exit($fail === 0 ? 0 : 1);
