<?php
declare(strict_types=1);

/** Internal scheduler tick (inside the webui container; no systemd, no cron host).
 *  Invoked by supervisord every 60 seconds with: php /var/www/html/worker.php tick */
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/config.php';
require_once __DIR__ . '/lib/scheduler.php';
require_once __DIR__ . '/lib/setup.php';

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

if ($cmd === 'loop') {
    while (true) {
        passthru(PHP_BINARY . ' ' . escapeshellarg(__FILE__) . ' tick');
        sleep(60);
    }
}

fwrite(STDERR, "usage: worker.php [tick|loop]\n");
exit(1);
