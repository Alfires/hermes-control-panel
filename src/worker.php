<?php
declare(strict_types=1);

/** Internal scheduler jobs (inside the webui container; no host systemd units).
 *
 *  cron calls:  worker.php tick     every minute  (web UI self-test schedule)
 *               worker.php agents   every 5 min   (wake agents with a schedule)
 *               worker.php security daily 03:15   (read-only host scan + wake)
 *
 *  These were documented in crontab but missing from this file, so the daily
 *  security scan and every agent wake silently printed "usage" and exited 1.
 */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/config.php';
require_once __DIR__ . '/lib/scheduler.php';
require_once __DIR__ . '/lib/setup.php';
require_once __DIR__ . '/lib/hostctl.php';
require_once __DIR__ . '/lib/util.php';

$cmd = $argv[1] ?? 'tick';

if ($cmd === 'tick') {
    $config = ConfigGovernance::active();
    $schedule = Scheduler::parse($config['schedule']['self_test'] ?? 'daily 04:20');
    $last = DB::one("SELECT created_at FROM schedule_runs WHERE job='webui.selftest' ORDER BY id DESC LIMIT 1");
    $lastAt = (int)($last['created_at'] ?? 0);
    if ($schedule && Scheduler::due($schedule, time(), $lastAt)) {
        $report = Scheduler::runSelfTest($config);
        DB::run('INSERT INTO schedule_runs(job,status,detail,created_at) VALUES(?,?,?,?)',
            ['webui.selftest', $report['ok'] ? 'pass' : 'fail', json_encode($report), time()]);
        if (!$report['ok']) {
            DB::run('INSERT INTO findings(source,severity,title,detail,status,created_at)
                     VALUES(?,?,?,?,\'open\',?)',
                ['webui.selftest', 'medium', 'Web UI self-test found broken views',
                 count($report['failed']) . ' failing checks', time()]);
        }
        DB::audit('scheduler', 'selftest.daily', $report['ok'] ? 'pass' : 'fail');
        fwrite(STDOUT, "self-test " . ($report['ok'] ? 'PASS' : 'FAIL') . "\n");
    }
    exit(0);
}

/** Read-only host scan. Persist new findings and wake Exploit Watch to propose
 *  fixes. Nothing is applied here: host_apply still needs a one-time owner
 *  escalation, so this job can never change the host by itself. */
if ($cmd === 'security') {
    $result = HostCtl::call('security_scan', [], null);
    $findings = is_array($result['findings'] ?? null) ? $result['findings'] : [];
    $stored = 0;
    foreach ($findings as $f) {
        $title = Util::str($f['title'] ?? '', 300);
        if ($title === '') continue;
        $exists = DB::one('SELECT id FROM findings WHERE source=? AND title=? AND status=?',
            ['hostctl', $title, 'open']);
        if ($exists) continue;
        DB::run('INSERT INTO findings(source,severity,title,detail,status,created_at)
                 VALUES(?,?,?,?,\'open\',?)', [
            'hostctl', Util::str($f['severity'] ?? 'medium', 20), $title,
            Util::str($f['detail'] ?? '', 2000), time(),
        ]);
        $stored++;
    }
    DB::run('INSERT INTO schedule_runs(job,status,detail,created_at) VALUES(?,?,?,?)',
        ['host.security', !empty($result['ok']) ? 'pass' : 'fail',
         json_encode(['total' => count($findings), 'stored' => $stored,
                      'error' => $result['error'] ?? null]), time()]);
    $agent = DB::one("SELECT id FROM agents WHERE slug='security' AND enabled=1");
    if ($agent) {
        DB::run('INSERT INTO messages(from_agent,to_agent,body,created_at) VALUES(?,?,?,?)', [
            null, (int)$agent['id'],
            'Daily scan complete: ' . $stored . ' new finding(s) recorded. Review the open findings and propose ONE host_apply fix per real issue with a clear reason. Do not apply anything yourself; the owner approves each action.',
            time(),
        ]);
    }
    DB::audit('scheduler', 'security.scan', 'new=' . $stored);
    fwrite(STDOUT, 'security scan ' . (!empty($result['ok']) ? 'ok' : 'failed') . " new=$stored\n");
    exit(0);
}

/** Wake scheduled agents by dropping a plain-language note into their inbox.
 *  The security agent is intentionally skipped here: its own cron line runs
 *  the real scan first, so it never gets woken with stale findings. */
if ($cmd === 'agents') {
    $now = time();
    foreach (DB::all("SELECT id,slug,schedule FROM agents
                      WHERE enabled=1 AND schedule IS NOT NULL AND slug<>'security'") as $a) {
        $parsed = Scheduler::parse($a['schedule']);
        if (!$parsed) continue;
        $job = 'agent.wake.' . (int)$a['id'];
        $last = DB::one('SELECT created_at FROM schedule_runs WHERE job=? ORDER BY id DESC LIMIT 1', [$job]);
        if (!Scheduler::due($parsed, $now, (int)($last['created_at'] ?? 0))) continue;
        DB::run('INSERT INTO messages(from_agent,to_agent,body,created_at) VALUES(?,?,?,?)', [
            null, (int)$a['id'],
            'Scheduled wake-up. Review your assigned work and report anything that needs the owner\'s attention.',
            time(),
        ]);
        DB::run('INSERT INTO schedule_runs(job,status,detail,created_at) VALUES(?,?,?,?)',
            [$job, 'pass', json_encode(['agent' => $a['slug'], 'schedule' => $a['schedule']]), $now]);
        DB::audit('scheduler', 'agent.wake', $a['slug']);
        fwrite(STDOUT, 'woke ' . $a['slug'] . "\n");
    }
    exit(0);
}

fwrite(STDERR, "usage: worker.php [tick|agents|security]\n");
exit(1);
