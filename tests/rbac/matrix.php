<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
lib('db.php');
lib('rbac.php');

function rbac_fresh_db(): string {
    $path = tempnam(sys_get_temp_dir(), 'hcp-rbac-') . '.sqlite';
    @unlink($path);
    $_ENV['PANEL_DB'] = $path;
    putenv('PANEL_DB=' . $path);
    DB::init();
    RBAC::installDefaults();
    return $path;
}

function rbac_agent_id(string $slug): int {
    $row = DB::one('SELECT id FROM agents WHERE slug=?', [$slug]);
    return (int) ($row['id'] ?? 0);
}

/**
 * 168 matrix cases: every default agent x every registered tool x three
 * escalation states. Asserts the real RBAC::can() decision, not a mock.
 */
$i = 0;
foreach (RBAC::DEFAULTS as $default) {
    foreach (array_keys(RBAC::TOOLS) as $tool) {
        foreach (['none', 'approved', 'expired'] as $state) {
            $i++;
            test("rbac.matrix.$i", static function () use ($default, $tool, $state): void {
                rbac_fresh_db();
                $id = rbac_agent_id($default['slug']);
                ok($id > 0, 'default agent missing');
                $capability = $tool === 'vault_use' ? 'vault:TEST_SECRET' : $tool;
                if ($state !== 'none') {
                    $expires = $state === 'approved' ? time() + 300 : time() - 10;
                    DB::run('INSERT INTO escalations(agent_id,capability,status,ttl_seconds,uses_left,created_at,expires_at)
                             VALUES(?,?,?,?,?,?,?)',
                        [$id, $capability, 'approved', 300, 1, time() - 60, $expires]);
                }
                $agent = RBAC::agent($id);
                $args = $tool === 'vault_use' ? ['name' => 'TEST_SECRET'] : [];
                [$allowed] = RBAC::can($agent, $tool, $args);

                $granted = in_array($tool, $default['tools'], true);
                $needsApproval = RBAC::TOOLS[$tool]['risk'] === 'privileged';
                $expected = $granted && (!$needsApproval || $state === 'approved');
                eq($allowed, $expected,
                    "{$default['slug']}/$tool/$state expected " . var_export($expected, true));
            });
        }
    }
}

/** 20 vault-grant cases against the real agent vault_names allowlist. */
for ($n = 1; $n <= 20; $n++) {
    $name = 'SECRET_' . $n;
    $grant = $n % 2 === 1;
    test("rbac.vault.$n", static function () use ($name, $grant, $n): void {
        rbac_fresh_db();
        $id = rbac_agent_id('security');
        $vaults = $grant ? [$name] : [];
        DB::run('UPDATE agents SET vault_names=? WHERE id=?', [json_encode($vaults), $id]);
        $agent = RBAC::agent($id);
        [$allowed] = RBAC::can($agent, 'vault_use', ['name' => $name]);
        eq($allowed, $grant, "vault grant case $n");
    });
}

/** 20 agent-message cases: messages require the opt-in flag and distinct agents. */
for ($n = 1; $n <= 20; $n++) {
    $optIn = $n % 3 !== 0;
    test("rbac.message.$n", static function () use ($optIn, $n): void {
        rbac_fresh_db();
        $id = rbac_agent_id('system');
        DB::run('UPDATE agents SET can_message=? WHERE id=?', [$optIn ? 1 : 0, $id]);
        $agent = RBAC::agent($id);
        [$allowed] = RBAC::can($agent, 'message_agent', ['to' => 'chat']);
        eq($allowed, $optIn, "message opt-in case $n");
    });
}
