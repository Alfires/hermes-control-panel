<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/** Tool catalogue and the permission engine. No tool reaches a backend except
 *  through this class, and every call is recorded. */
final class RBAC {
    public const TOOLS = [
        'chat'         => ['risk' => 'none',      'desc' => 'Plain conversation, no system access.'],
        'notes_read'   => ['risk' => 'read',      'desc' => 'Read the shared notes board.'],
        'notes_write'  => ['risk' => 'write',     'desc' => 'Write a note to the shared board (no secrets).'],
        'host_status'  => ['risk' => 'read',      'desc' => 'Read-only Deck health: services, disk, containers, updates.'],
        'host_scan'    => ['risk' => 'read',      'desc' => 'Read-only exploit/config scan.'],
        'cve_lookup'   => ['risk' => 'read',      'desc' => 'Look up a CVE or package version.'],
        'host_apply'   => ['risk' => 'privileged','desc' => 'Run one allowlisted system fix. Needs your approval.'],
        'webui_read'   => ['risk' => 'read',      'desc' => 'Read the panel layout and agent definitions.'],
        'webui_edit'   => ['risk' => 'privileged','desc' => 'Change buttons/cards/layout. Needs your approval.'],
        'vault_list'   => ['risk' => 'read',      'desc' => 'List secret names and policies. Values are never returned.'],
        'vault_use'    => ['risk' => 'privileged','desc' => 'Use one named secret for one action. Owner approves at the broker.'],
        'message_agent'=> ['risk' => 'write',     'desc' => 'Send a message into another agent inbox. No shared data.'],
    ];

    /** The four default agents. Each starts with zero permissions beyond its
     *  listed tools; escalations are one-time and expire. */
    public const DEFAULTS = [
        [
            'slug' => 'system', 'name' => 'Deck System', 'role' => 'System overview + changes',
            'description' => 'Watches the Steam Deck and its services. Reports health, and can change system-level things only after you approve.',
            'system_prompt' => 'You are the Deck System agent. Use host_status to report real numbers before claiming anything. You may propose host_apply actions; they only run after the owner approves. Never invent a result. If you need a secret, request escalation instead of guessing it.',
            'tools' => ['chat','host_status','host_scan','host_apply','message_agent'],
            'vault' => [], 'can_message' => 1, 'schedule' => null,
        ],
        [
            'slug' => 'webui', 'name' => 'Web Interface', 'role' => 'This panel only',
            'description' => 'Only sees and edits the web interface: cards, buttons, menus and layout. No access to the host or the vault.',
            'system_prompt' => 'You are the Web Interface agent. You may read and edit only this panel\'s layout. Use webui_read before webui_edit. Never claim you touched the host: you cannot. Ask the owner before structural changes.',
            'tools' => ['chat','webui_read','webui_edit','message_agent'],
            'vault' => [], 'can_message' => 1, 'schedule' => null,
        ],
        [
            'slug' => 'chat', 'name' => 'Chat & News', 'role' => 'Conversation only',
            'description' => 'Talks with you and summarises what is new. No tools that touch the system, the panel or secrets.',
            'system_prompt' => 'You are the Chat and News agent. You are friendly and concise. You have no system, panel or vault access. If asked for any of those, say which agent handles it.',
            'tools' => ['chat','notes_read','notes_write'],
            'vault' => [], 'can_message' => 1, 'schedule' => null,
        ],
        [
            'slug' => 'security', 'name' => 'Exploit Watch', 'role' => 'Daily exploit scan + fixes on approval',
            'description' => 'Checks once a day for new exploits, unsafe settings and outdated packages. Fixes are proposed and only run after your approval.',
            'system_prompt' => 'You are the Exploit Watch agent. Run host_scan and read findings. For every real issue, propose exactly one host_apply action with a clear reason. Never auto-fix. Never ask for or repeat a credential value; use vault_use only for a named secret you were explicitly granted. report findings plainly with severity.',
            'tools' => ['chat','host_scan','cve_lookup','host_apply','vault_list','vault_use','message_agent'],
            'vault' => [], 'can_message' => 1, 'schedule' => 'daily 03:15',
        ],
    ];

    public static function installDefaults(): void {
        foreach (self::DEFAULTS as $a) {
            if (DB::one('SELECT id FROM agents WHERE slug=?', [$a['slug']])) continue;
            DB::run('INSERT INTO agents(slug,name,role,description,system_prompt,model,tools,vault_names,can_message,enabled,schedule,created_at)
                     VALUES(?,?,?,?,?,?,?,?,?,?,?,?)', [
                $a['slug'], $a['name'], $a['role'], $a['description'], $a['system_prompt'],
                'big-pickle', json_encode($a['tools']), json_encode($a['vault']),
                $a['can_message'], 1, $a['schedule'], time(),
            ]);
        }
    }

    public static function agent(int $id): ?array {
        $a = DB::one('SELECT * FROM agents WHERE id=? AND enabled=1', [$id]);
        if ($a) {
            $a['tools'] = json_decode($a['tools'], true) ?: [];
            $a['vault_names'] = json_decode($a['vault_names'], true) ?: [];
        }
        return $a;
    }

    /** @return array{0:bool,1:string} */
    public static function can(array $agent, string $tool, array $args = []): array {
        $tool = strtolower($tool);
        if (!isset(self::TOOLS[$tool])) return [false, "unknown tool $tool"];
        if (!in_array($tool, $agent['tools'], true)) {
            return [false, "agent '{$agent['slug']}' is not allowed to use $tool"];
        }

        if ($tool === 'vault_use') {
            $name = (string)($args['name'] ?? '');
            $granted = in_array($name, $agent['vault_names'], true)
                || self::activeEscalation((int)$agent['id'], 'vault:' . $name);
            if (!$granted) {
                return [false, "no permission for secret '$name'; request a one-time escalation"];
            }
            return [true, 'ok'];
        }

        if (self::TOOLS[$tool]['risk'] === 'privileged') {
            if (!self::activeEscalation((int)$agent['id'], $tool)) {
                return [false, "$tool needs a one-time approval; request escalation first"];
            }
        }
        return [true, 'ok'];
    }

    public static function activeEscalation(int $agentId, string $capability): bool {
        $row = DB::one('SELECT * FROM escalations WHERE agent_id=? AND capability=? AND status=?
                        AND uses_left>0 AND expires_at>? ORDER BY id DESC LIMIT 1',
            [$agentId, $capability, 'approved', time()]);
        return $row !== null;
    }

    public static function consumeEscalation(int $agentId, string $capability): void {
        DB::run('UPDATE escalations SET uses_left=uses_left-1 WHERE id=(
                   SELECT id FROM escalations WHERE agent_id=? AND capability=? AND status=?
                   AND uses_left>0 AND expires_at>? ORDER BY id DESC LIMIT 1)',
            [$agentId, $capability, 'approved', time()]);
    }

    public static function requestEscalation(int $agentId, string $capability, string $reason, int $ttl): int {
        $ttl = max(30, min(3600, $ttl));
        DB::run('INSERT INTO escalations(agent_id,capability,reason,status,ttl_seconds,created_at)
                 VALUES(?,?,?,\'pending\',?,?)', [$agentId, $capability, $reason, $ttl, time()]);
        $id = (int)DB::pdo()->lastInsertId();
        DB::audit('agent:' . $agentId, 'escalation.request', "$capability ttl={$ttl}s");
        return $id;
    }

    public static function decide(int $id, bool $approve): bool {
        $row = DB::one('SELECT * FROM escalations WHERE id=? AND status=?', [$id, 'pending']);
        if (!$row) return false;
        if ($approve) {
            $token = bin2hex(random_bytes(24));
            DB::run('UPDATE escalations SET status=?, decided_at=?, expires_at=?, token_hash=?, uses_left=1
                     WHERE id=?', [
                'approved', time(), time() + (int)$row['ttl_seconds'], hash('sha256', $token), $id,
            ]);
        } else {
            DB::run('UPDATE escalations SET status=?, decided_at=? WHERE id=?', ['denied', time(), $id]);
        }
        DB::audit('owner', $approve ? 'escalation.approve' : 'escalation.deny', (string)$id);
        return true;
    }

    /** Called by the minute tick: unanswered asks become expired so the agent
     *  can move on, and a note is dropped into its inbox. */
    public static function expirePending(): void {
        $rows = DB::all("SELECT e.*, a.slug FROM escalations e JOIN agents a ON a.id=e.agent_id
                         WHERE e.status='pending' AND e.created_at < ?", [time() - 300]);
        foreach ($rows as $e) {
            DB::run("UPDATE escalations SET status='expired', decided_at=? WHERE id=?", [time(), $e['id']]);
            DB::run('INSERT INTO messages(from_agent,to_agent,body,created_at) VALUES(?,?,?,?)', [
                null, (int)$e['agent_id'],
                "Your escalation request #{$e['id']} ({$e['capability']}) timed out with no answer. Continue with other work.",
                time(),
            ]);
        }
        DB::run('UPDATE escalations SET status=? WHERE status=? AND expires_at>0 AND expires_at<=?',
            ['expired', 'approved', time()]);
    }
}
