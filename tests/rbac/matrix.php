<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
lib('rbac.php');

$agents = [
    'system' => ['tools' => ['chat', 'notes_read', 'host_status', 'host_scan', 'cve_lookup', 'host_apply', 'message_agent', 'noop']],
    'webui' => ['tools' => ['chat', 'notes_read', 'notes_write', 'webui_read', 'webui_edit', 'message_agent', 'noop']],
    'chat' => ['tools' => ['chat', 'notes_read', 'notes_write', 'message_agent', 'noop']],
    'security' => ['tools' => ['chat', 'notes_read', 'host_status', 'host_scan', 'cve_lookup', 'message_agent', 'noop']],
];
$tools = ['chat', 'notes_read', 'notes_write', 'host_status', 'host_scan', 'cve_lookup', 'host_apply',
          'webui_read', 'webui_edit', 'vault_list', 'vault_use', 'message_agent', 'request_escalation', 'noop'];
$risky = ['host_apply', 'webui_edit', 'vault_use', 'host_scan', 'cve_lookup'];
$i = 0;
foreach ($agents as $slug => $agent) {
    foreach ($tools as $tool) {
        foreach (['none', 'approved', 'expired'] as $state) {
            $i++;
            test("rbac.matrix.$i", static function () use ($slug, $agent, $tool, $state, $risky): void {
                $granted = in_array($tool, $agent['tools'], true);
                $needsApproval = in_array($tool, $risky, true);
                $expected = $granted && (!$needsApproval || $state === 'approved');
                eq(RBAC::can($slug, $tool, $state), $expected,
                    "$slug/$tool/$state expected " . var_export($expected, true));
            });
        }
    }
}

// 20 vault-grant cases.
for ($n = 1; $n <= 20; $n++) {
    $name = 'SECRET_' . $n;
    $grant = $n % 2 === 1;
    test("rbac.vault.$n", static function () use ($name, $grant, $n): void {
        eq(RBAC::canVaultUse('system', $name, $grant), $grant, "vault grant case $n");
    });
}

// 20 agent-message cases.
for ($n = 1; $n <= 20; $n++) {
    $optIn = $n % 3 !== 0;
    test("rbac.message.$n", static function () use ($optIn, $n): void {
        eq(RBAC::canMessage('system', 'chat', $optIn), $optIn, "message opt-in case $n");
    });
}
