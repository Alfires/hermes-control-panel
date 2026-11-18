<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/rbac.php';
require_once __DIR__ . '/lib/engine.php';
require_once __DIR__ . '/lib/hostctl.php';

/** Cron entrypoints. Never accepts web input. */
$mode = $argv[1] ?? 'tick';

if ($mode === 'tick') {
    RBAC::expirePending();
    // Keep the tables bounded; the audit trail is the important history.
    DB::run('DELETE FROM approvals WHERE created_at < ?', [time() - 30 * 86400]);
    DB::run('DELETE FROM messages WHERE created_at < ? AND read_at IS NOT NULL', [time() - 14 * 86400]);
    echo "tick ok\n";
    exit(0);
}

if ($mode === 'security') {
    RBAC::expirePending();
    $scan = HostCtl::call('security_scan', [], null);
    if (!empty($scan['ok'])) {
        DB::run("UPDATE findings SET status='resolved' WHERE status='open'");
        foreach ($scan['findings'] ?? [] as $f) {
            DB::run('INSERT INTO findings(source,severity,title,detail,status,created_at) VALUES(?,?,?,?,?,?)', [
                Util::str($f['source'] ?? 'hostctl', 80),
                Util::str($f['severity'] ?? 'medium', 20),
                Util::scrub(Util::str($f['title'] ?? 'Finding', 300)),
                Util::scrub(Util::str($f['detail'] ?? '', 3000)),
                'open', time(),
            ]);
        }
        $count = count($scan['findings'] ?? []);
        DB::run('INSERT INTO audit(actor,action,detail,created_at) VALUES(?,?,?,?)',
            ['worker', 'security.scan', "$count finding(s)", time()]);

        // Wake the exploit agent with the fresh results and let it propose
        // fixes. It cannot apply anything without a one-time owner approval.
        $agent = DB::one("SELECT * FROM agents WHERE slug='security' AND enabled=1");
        if ($agent) {
            $a = RBAC::agent((int)$agent['id']);
            $s = Engine::newSession($a, 'Daily scan ' . date('Y-m-d'));
            $prompt = 'Daily security scan completed with ' . $count . ' finding(s). '
                . 'Use host_scan to read them. For each real issue, report severity and propose one '
                . 'host_apply action with a reason. Request escalation for any you need. Do not apply fixes.';
            Engine::chat($a, $s, $prompt);
            echo "security scan: $count finding(s), agent turn complete\n";
        } else {
            echo "security scan: $count finding(s)\n";
        }
    } else {
        echo "security scan failed: " . ($scan['error'] ?? 'unknown') . "\n";
    }
    exit(0);
}

if ($mode === 'agents') {
    RBAC::expirePending();
    $now = time();
    foreach (DB::all('SELECT * FROM agents WHERE enabled=1 AND schedule IS NOT NULL') as $row) {
        $slug = $row['slug'];
        // The exploit agent's daily pass is the dedicated `security` cron below:
        // it also persists findings, which a plain agent turn cannot do.
        if ($slug === 'security') continue;
        $schedule = (string)$row['schedule'];
        $key = 'last_run:' . $slug;
        $stored = DB::one('SELECT value FROM layout WHERE key=?', [$key]);
        $last = $stored ? (int)(json_decode($stored['value'], true) ?: 0) : 0;

        $due = false;
        if (preg_match('/^daily\s+(\d{1,2}):(\d{2})$/i', $schedule, $m)) {
            $target = mktime((int)$m[1], (int)$m[2], 0, (int)date('m'), (int)date('d'), (int)date('Y'));
            $due = $now >= $target && $last < $target;
        } elseif (preg_match('/^every\s+(\d+)\s*(m|min|h|hour)$/i', $schedule, $m)) {
            $step = (int)$m[1] * (stripos($m[2], 'h') === 0 ? 3600 : 60);
            $due = $step > 0 && ($now - $last) >= $step;
        }
        if (!$due) continue;

        $a = RBAC::agent((int)$row['id']);
        if (!$a || !in_array('chat', $a['tools'], true)) continue;
        $s = Engine::newSession($a, ucfirst($slug) . ' run ' . date('Y-m-d H:i'));
        Engine::chat($a, $s, 'Scheduled run (' . $schedule . '). Do your assigned job now and report concisely.');
        DB::run('INSERT INTO layout(key,value,updated_by,updated_at) VALUES(?,?,?,?)
                 ON CONFLICT(key) DO UPDATE SET value=excluded.value,updated_by=excluded.updated_by,updated_at=excluded.updated_at',
            [$key, json_encode($now), 'worker', $now]);
        echo "$slug ran\n";
    }
    exit(0);
}

fwrite(STDERR, "unknown worker mode: $mode\n");
exit(1);
