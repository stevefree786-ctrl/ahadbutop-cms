<?php
/**
 * Job queue tests.
 *
 * Proves the queue's safety properties, above all the concurrency contract:
 * two workers racing for the same job must produce exactly ONE winner.
 *
 * The race is exercised twice — sequentially (a second claim must not return the
 * same id) and genuinely concurrently (two separate OS processes released from a
 * shared start barrier, asserting the claimed ids never collide).
 */
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

if (is_file(base_path('.env'))) {
    Dotenv\Dotenv::createImmutable(base_path())->safeLoad();
}

use CMS\Queue\Cron;
use CMS\Queue\JobQueue;
use CMS\Queue\Worker;

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  PASS  {$label}\n";
    } else {
        $fail++;
        echo "  FAIL  {$label} {$detail}\n";
    }
}

/** Unique-ish token so a failed run never collides with a previous one. */
$tag = 'qtest_' . getmypid() . '_' . substr((string) time(), -6);

// Rows this test created, removed at the end.
$createdJobs = [];

function track(int $id): int
{
    global $createdJobs;
    if ($id > 0) {
        $createdJobs[] = $id;
    }
    return $id;
}

$queue = new JobQueue();
$pdo   = $queue->pdo();

// Baseline: remove leftovers from an earlier aborted run of this same test.
$pdo->prepare('DELETE FROM job_logs WHERE job_id IN (SELECT id FROM jobs WHERE idempotency_key LIKE ?)')
    ->execute([$tag . '%']);
$pdo->prepare('DELETE FROM jobs WHERE idempotency_key LIKE ?')->execute([$tag . '%']);

echo "\n== 1. Enqueue + claim ==\n";
$jobId = track($queue->enqueue('generate_daily_post', ['topic' => 'testing'], [
    'idempotency_key' => $tag . '_basic',
]));
check('enqueue returns a job id', $jobId > 0);

$claimed = $queue->claim('worker-A', 1);
check('claim returns exactly one job', count($claimed) === 1, '(got ' . count($claimed) . ')');
check('claimed the job we enqueued', ($claimed[0]['id'] ?? 0) === $jobId);

$row = $queue->find($jobId);
check('status is running', ($row['status'] ?? '') === 'running', '(got ' . ($row['status'] ?? '?') . ')');
check('attempts incremented to 1', (int) ($row['attempts'] ?? 0) === 1, '(got ' . ($row['attempts'] ?? '?') . ')');
check('locked_by is the worker id', ($row['locked_by'] ?? '') === 'worker-A');
check('payload round-trips through JSON', ($claimed[0]['payload']['topic'] ?? '') === 'testing');

// The runner-up: finish this one so it can't pollute later ordering assertions.
$queue->complete($jobId, ['ok' => true]);

echo "\n== 2. Two workers racing to claim the same job ==\n";
$raceId = track($queue->enqueue('render_site', ['site' => 'race'], [
    'idempotency_key' => $tag . '_race',
]));

$first  = $queue->claim('worker-first', 1);
$second = $queue->claim('worker-second', 1);

$firstIds  = array_map(fn($j) => (int) $j['id'], $first);
$secondIds = array_map(fn($j) => (int) $j['id'], $second);

check('first claim won the job', in_array($raceId, $firstIds, true), '(first: ' . json_encode($firstIds) . ')');
check(
    'second claim did NOT get the same id',
    !in_array($raceId, $secondIds, true),
    '(second: ' . json_encode($secondIds) . ')'
);
check(
    'the two claims are disjoint',
    array_intersect($firstIds, $secondIds) === [],
    '(overlap: ' . json_encode(array_intersect($firstIds, $secondIds)) . ')'
);

// Retire everything just claimed.
//
// A claim moves a job queued -> running and nothing else. A row left `running`
// is never returned by claim() again, so no drain can ever clear it — it
// survives until this file's final cleanup deletes it by id.
//
// That was the flake in section 8. These leftover rows all carry the DEFAULT
// priority of 100, and section 8 enqueues its own priority-100 job with a
// HIGHER id. claim() orders by (priority ASC, id ASC), so the orphan sorts
// ahead of the intended job and is claimed in its place: section 8 saw
// [1690, 1692, 1684] where it expected [1690, 1692, 1691]. It failed roughly
// one run in six, and only because the orphans had to line up with the right
// enqueue — which is exactly the kind of timing-dependent, hard-to-attribute
// failure worth removing rather than re-running.
//
// Complete them here so the queue is in a known state before the next section.
foreach (array_merge($firstIds, $secondIds) as $claimedId) {
    $queue->complete((int) $claimedId, ['retired' => 'by-queue_test']);
}

// Genuine concurrency: two OS processes released from a shared barrier.
//
// Results are passed back through files rather than proc_open pipes: pipe
// capture is unreliable under Git Bash on Windows (the parent can read a handle
// it cannot see output on), whereas a child writing a file always works on both
// platforms. proc_close() waits for each child to exit.
$raceHelper = __DIR__ . '/.queue_race_helper.php';
@file_put_contents($raceHelper, <<<'PHP'
<?php
// Spawned by queue_test.php. Claims exactly one job and writes the id to $argv[3].
// argv: [workerId, barrierPath, outPath]
declare(strict_types=1);
require_once __DIR__ . '/../vendor/autoload.php';
if (is_file(base_path('.env'))) { Dotenv\Dotenv::createImmutable(base_path())->safeLoad(); }

$workerId = $argv[1];
$barrier  = $argv[2];
$outPath  = $argv[3];

try {
    $queue = new \CMS\Queue\JobQueue();
    $waited = 0;
    while (!file_exists($barrier)) {
        usleep(1000);
        if (++$waited > 15000) { break; } // safety valve; never hang the suite
    }
    $claimed = $queue->claim($workerId, 1);
    file_put_contents($outPath, $claimed === [] ? '0' : (string) (int) $claimed[0]['id']);
} catch (\Throwable $e) {
    file_put_contents($outPath, 'ERR:' . $e->getMessage());
}
PHP
);

$concurrentId = track($queue->enqueue('render_site', ['site' => 'concurrent'], [
    'idempotency_key' => $tag . '_concurrent',
]));

$barrier = sys_get_temp_dir() . '/queue_race_barrier_' . $tag;
$outA   = sys_get_temp_dir() . '/queue_race_a_' . $tag;
$outB   = sys_get_temp_dir() . '/queue_race_b_' . $tag;
foreach ([$barrier, $outA, $outB] as $f) {
    @unlink($f);
}

$spawn = static function (string $workerName, string $outPath) use ($raceHelper, $barrier) {
    $cmd = escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg($raceHelper)
        . ' ' . escapeshellarg($workerName)
        . ' ' . escapeshellarg($barrier)
        . ' ' . escapeshellarg($outPath);

    // Results travel through $outPath; stdout/stderr are redirected to files too
    // rather than pipes, because an unread pipe keeps the child alive on Windows
    // (proc_close blocks forever) — a live child would hold its SQLite connection
    // and lock every later assertion.
    return proc_open(
        $cmd,
        [
            1 => ['file', $outPath . '.stdout', 'w'],
            2 => ['file', $outPath . '.stderr', 'w'],
        ],
        $pipes
    );
};

$procA = $spawn('race-a-' . $tag, $outA);
$procB = $spawn('race-b-' . $tag, $outB);

// Release both processes at the same instant so they collide inside claim().
file_put_contents($barrier, 'go');

proc_close($procA);
proc_close($procB);

// Poll until both children have published a result. A worker that loses the
// claim writes '0' (claimed nothing) and exits cleanly, so "no file yet" is a
// timing artefact — never a signal about who won. Waiting here keeps the
// assertion about the race itself rather than about process teardown speed.
$readResult = static function (string $path, float $timeoutSec = 10.0): string {
    $deadline = microtime(true) + $timeoutSec;
    while (microtime(true) < $deadline) {
        clearstatcache(true);
        if (is_file($path) && filesize($path) > 0) {
            $v = file_get_contents($path);
            if ($v !== false && $v !== '') {
                return trim($v);
            }
        }
        usleep(20_000);
    }
    return '';
};

$out1 = $readResult($outA);
$out2 = $readResult($outB);

$errA = trim((string) (@file_get_contents($outA . '.stderr') ?: ''));
$errB = trim((string) (@file_get_contents($outB . '.stderr') ?: ''));

foreach ([$barrier, $outA, $outB, $raceHelper,
          $outA . '.stdout', $outA . '.stderr',
          $outB . '.stdout', $outB . '.stderr'] as $f) {
    @unlink($f);
}

check('both race processes reported a result', $out1 !== '' && $out2 !== '', "(a:'$out1' b:'$out2' errA:'$errA' errB:'$errB')");
check('exactly one process claimed the job', $out1 !== $out2, "(both got '$out1')");
check(
    'the winner claimed our job, and only one did',
    in_array($concurrentId, [(int) $out1, (int) $out2], true),
    "(a:'$out1' b:'$out2' job:$concurrentId)"
);

// Retire whatever the race children left `running`.
//
// Each child claimed exactly one job and exited without completing it. Exactly
// one of $out1/$out2 is non-zero (the winner); the loser wrote '0'. So it is
// not enough to complete $out1 — when process B wins, that is the row still
// running, and completing it alone leaves the orphan behind.
//
// That matters beyond tidiness: these orphans are what made section 8 fail one
// run in six. claim() orders by (priority ASC, id ASC), a stale `running` row
// is never claimable again so no drain removes it, and the default priority of
// 100 ties with section 8's own priority-100 job — where the LOWER id wins. The
// orphan therefore displaced the job section 8 meant to claim.
foreach (['race-a' => $out1, 'race-b' => $out2] as $who => $raw) {
    $claimedId = (int) $raw;
    if ($claimedId > 0) {
        $queue->complete($claimedId, ['retired' => 'by-queue_test-' . $who]);
        // Only track ids this file did NOT create, so cleanup deletes them too.
        if (!in_array($claimedId, $createdJobs, true)) {
            track($claimedId);
        }
    }
}

$concurrentRow = $queue->find($concurrentId);
check(
    'the concurrently claimed job shows attempts=1 (not 2)',
    (int) ($concurrentRow['attempts'] ?? 0) === 1,
    '(got ' . ($concurrentRow['attempts'] ?? '?') . ')'
);

echo "\n== 3. complete() ==\n";
$doneId = track($queue->enqueue('seo.persist', ['entity_id' => 7], [
    'idempotency_key' => $tag . '_complete',
]));
$queue->claim('worker-complete', 1); // may claim any queued job; drain until ours
// Drain anything the race left behind so the next claim is deterministic.
for ($i = 0; $i < 10; $i++) {
    $queue->claim('worker-drain', 5);
}
$queue->complete($doneId, ['persisted' => true, 'score' => 42]);

$row = $queue->find($doneId);
check('status is succeeded', ($row['status'] ?? '') === 'succeeded', '(got ' . ($row['status'] ?? '?') . ')');
check('result JSON stored', str_contains((string) ($row['result'] ?? ''), '"persisted":true'));
check('finished_at is set', ($row['finished_at'] ?? '') !== '');

$levels = array_column($queue->logs($doneId), 'level');
check('complete() wrote a job_logs row', in_array('info', $levels, true), '(levels: ' . json_encode($levels) . ')');

echo "\n== 4. fail() under the attempt limit requeues with backoff ==\n";
$retryId = track($queue->enqueue('seo.audit', ['n' => 1], [
    'idempotency_key' => $tag . '_retry',
    'max_attempts'    => 3,
]));
$queue->claim('worker-retry', 1);
$queue->fail($retryId, 'transient DB blip');

$row = $queue->find($retryId);
check('status back to queued', ($row['status'] ?? '') === 'queued', '(got ' . ($row['status'] ?? '?') . ')');
check('last_error stored', str_contains((string) ($row['last_error'] ?? ''), 'transient DB blip'));
check('attempts is 1', (int) ($row['attempts'] ?? 0) === 1);

$runAfter = (string) ($row['run_after'] ?? '');
$inFuture = strtotime($runAfter . ' UTC') > time();
check('run_after pushed into the future', $inFuture, "(run_after: {$runAfter})");
check(
    'backoff is 2**attempts seconds (2s here)',
    abs((strtotime($runAfter . ' UTC') - time()) - 2) <= 1,
    "(delta: " . (strtotime($runAfter . ' UTC') - time()) . 's)'
);

$logs = $queue->logs($retryId);
check('fail() wrote a warn job_logs row', in_array('warn', array_column($logs, 'level'), true));

// fail() puts it back in `queued` with a future run_after, so it is undrainable
// for the length of the backoff — 2s here, growing later. Section 8 drains
// 200 slots in a tight loop, which finishes well inside that window, so this row
// is still sitting in the queue when section 8 enqueues its priority jobs.
// Retiring it keeps the priority assertions about section 8's own jobs.
$queue->complete($retryId, ['retired' => 'by-queue_test']);

echo "\n== 5. fail() past max_attempts marks dead ==\n";
$deadId = track($queue->enqueue('sitemap.ping', [], [
    'idempotency_key' => $tag . '_dead',
    'max_attempts'    => 2,
]));

// Drive it to its attempt ceiling. The backoff on attempt 1 blocks re-claiming,
// so pull run_after back to now before each retry.
for ($i = 0; $i < 2; $i++) {
    $pdo->prepare("UPDATE jobs SET run_after = datetime('now') WHERE id = ?")->execute([$deadId]);
    $queue->claim('worker-dead', 1);
    $queue->fail($deadId, "failure #{$i}");
}

$row = $queue->find($deadId);
check('status is dead', ($row['status'] ?? '') === 'dead', '(got ' . ($row['status'] ?? '?') . ')');
check('attempts reached max_attempts', (int) ($row['attempts'] ?? 0) === 2, '(got ' . ($row['attempts'] ?? '?') . ')');
check('last_error retained on dead job', str_contains((string) ($row['last_error'] ?? ''), 'failure #1'));
check('dead job wrote an error job_logs row', in_array('error', array_column($queue->logs($deadId), 'level'), true));

echo "\n== 6. idempotency_key creates exactly one row ==\n";
$key = $tag . '_idem';
$id1 = track($queue->enqueue('generate_daily_post', ['x' => 1], ['idempotency_key' => $key]));
$id2 = track($queue->enqueue('generate_daily_post', ['x' => 2], ['idempotency_key' => $key]));
$id3 = track($queue->enqueue('generate_daily_post', ['x' => 3], ['idempotency_key' => $key]));

$stmt = $pdo->prepare('SELECT COUNT(*) FROM jobs WHERE idempotency_key = ?');
$stmt->execute([$key]);
check('three enqueues -> one row', (int) $stmt->fetchColumn() === 1, '(got ' . $stmt->fetchColumn() . ')');
check('duplicate enqueue returns the existing id', $id2 === $id1 && $id3 === $id1, "(1:$id1 2:$id2 3:$id3)");
check('first payload was preserved, not overwritten', str_contains((string) $queue->find($id1)['payload'], '"x":1'));

echo "\n== 7. Cron idempotency ==\n";
$cron    = new Cron($queue);
$slot    = $cron->slotFor('daily_blog', new DateTimeImmutable('2026-03-05 09:14:00', new DateTimeZone('UTC')));

check('daily_blog slot is midnight UTC', $slot === '2026-03-05 00:00:00', "(got {$slot})");

$ran1 = $cron->claimSlot('daily_blog', $slot, 'generate_daily_post', ['topic' => 'a']);
$ran2 = $cron->claimSlot('daily_blog', $slot, 'generate_daily_post', ['topic' => 'b']);
$ran3 = $cron->claimSlot('daily_blog', $slot, 'generate_daily_post', ['topic' => 'c']);

check('first cron fire claims the slot', $ran1 === true);
check('second fire for the same slot does not re-run', $ran2 === false);
check('third fire for the same slot does not re-run', $ran3 === false);

$stmt = $pdo->prepare('SELECT COUNT(*) FROM cron_runs WHERE cron_key = ? AND scheduled_for = ?');
$stmt->execute(['daily_blog', $slot]);
check('exactly one cron_runs row for the slot', (int) $stmt->fetchColumn() === 1);

$stmt = $pdo->prepare('SELECT COUNT(*) FROM jobs WHERE idempotency_key = ?');
$stmt->execute(['daily_blog:' . $slot]);
$enqueuedCount = (int) $stmt->fetchColumn();
check('exactly one job enqueued for the slot', $enqueuedCount === 1, "(got {$enqueuedCount})");
if ($enqueuedCount === 1) {
    track((int) $pdo->query('SELECT id FROM jobs WHERE idempotency_key = ' . $pdo->quote('daily_blog:' . $slot))
        ->fetchColumn());
}
check('shouldRun reports the slot is taken', $cron->shouldRun('daily_blog', $slot) === false);

// record() twice must update the one row, not insert a second.
$cron->record('daily_blog', $slot, 'ok', 'first', null);
$cron->record('daily_blog', $slot, 'failed', 'second', null);
$stmt = $pdo->prepare('SELECT status, detail FROM cron_runs WHERE cron_key = ? AND scheduled_for = ?');
$stmt->execute(['daily_blog', $slot]);
$cronRow = $stmt->fetch();
check('record() twice keeps one row', is_array($cronRow));
check('record() twice leaves the latest status', ($cronRow['status'] ?? '') === 'failed', '(got ' . ($cronRow['status'] ?? '?') . ')');

$slot2 = $cron->slotFor('daily_blog', new DateTimeImmutable('2026-03-06 09:14:00', new DateTimeZone('UTC')));
check('a different day is a different slot', $slot2 === '2026-03-06 00:00:00', "(got {$slot2})");
check('a fresh slot should run', $cron->shouldRun('daily_blog', $slot2) === true);

echo "\n== 8. priority ASC ordering ==\n";
// Drain first so only our priority jobs are claimable.
//
// A `running` row cannot be drained — claim() only ever returns `queued` ones —
// so if this suite (or another process) left a job mid-flight, it stays put and
// the assertion below becomes meaningless. Snapshot it so a failure can be
// attributed rather than guessed at.
$preExistingRunning = (int) $pdo
    ->query("SELECT COUNT(*) FROM jobs WHERE status = 'running'")
    ->fetchColumn();
if ($preExistingRunning > 0) {
    fwrite(STDERR, "[queue_test] WARNING: {$preExistingRunning} pre-existing RUNNING job(s) "
        . "- section 8 ordering may be polluted.\n");
}
for ($i = 0; $i < 20; $i++) {
    $queue->claim('worker-drain2', 10);
}

// Defer any `queued` row whose run_after is still in the future. A job put back
// by fail() carries a 2**attempts backoff, and this drain loop finishes well
// inside that window, so such a row is still claimable-by-time when section 8
// enqueues — and it would be claimed in place of one of the jobs under test.
//
// The rows are pushed out of the window rather than deleted: they are stashed
// and restored afterwards, so the sections above still see the queue in the
// state they created and nothing is lost.
$stashed = $pdo->query(
    "SELECT id FROM jobs
      WHERE status = 'queued' AND run_after > datetime('now')"
)->fetchAll();
$stashedIds = array_map(fn($r) => (int) $r['id'], $stashed);
if ($stashedIds !== []) {
    $in = implode(',', array_fill(0, count($stashedIds), '?'));
    $pdo->prepare("UPDATE jobs SET run_after = datetime('now', '+1 day') WHERE id IN ({$in})")
        ->execute($stashedIds);
}
$lowId  = track($queue->enqueue('render_site', ['p' => 'low'],  ['priority' => 1,   'idempotency_key' => $tag . '_p1']));
$highId = track($queue->enqueue('render_site', ['p' => 'high'], ['priority' => 100, 'idempotency_key' => $tag . '_p100']));
$midId  = track($queue->enqueue('render_site', ['p' => 'mid'],  ['priority' => 50,  'idempotency_key' => $tag . '_p50']));

$order = array_map(fn($j) => (int) $j['id'], $queue->claim('worker-order', 3));
check('claim returns all three', count($order) === 3, '(got ' . json_encode($order) . ')');
check('priority 1 claimed before 50 before 100', $order === [$lowId, $midId, $highId], '(got ' . json_encode($order) . ')');

if ($order !== [$lowId, $midId, $highId]) {
    // Dump the actual queue so the failure is diagnosable from the run alone.
    $state = $pdo->query(
        "SELECT id, status, priority, attempts, max_attempts, locked_by, run_after, idempotency_key
           FROM jobs
          WHERE status IN ('queued', 'running')
          ORDER BY priority ASC, id ASC"
    )->fetchAll();
    fwrite(STDERR, "[queue_test] section-8 mismatch: expected "
        . json_encode([$lowId, $midId, $highId]) . " got " . json_encode($order) . "\n");
    fwrite(STDERR, "[queue_test] queue state: " . json_encode($state) . "\n");
}

echo "\n== 9. delayed job is not claimed ==\n";
$delayedId = track($queue->enqueue('render_site', ['d' => 1], [
    'delay'           => 3600,
    'idempotency_key' => $tag . '_delayed',
]));
$row = $queue->find($delayedId);
check('delayed job is queued', ($row['status'] ?? '') === 'queued');
check('run_after is ~1h out', strtotime((string) $row['run_after'] . ' UTC') - time() > 3500);

$claims = $queue->claim('worker-delay', 10);
$gotIds = array_map(fn($j) => (int) $j['id'], $claims);
check('delayed job was NOT claimed', !in_array($delayedId, $gotIds, true), '(got ' . json_encode($gotIds) . ')');

echo "\n== 10. unknown job type fails gracefully ==\n";
$unknownId = track($queue->enqueue('no_such_job_type', ['x' => 1], [
    'idempotency_key' => $tag . '_unknown',
]));
$unknownRow = $queue->find($unknownId);
$pdo->prepare("UPDATE jobs SET run_after = datetime('now') WHERE id = ?")->execute([$unknownId]);

$worker = new Worker($queue, 'worker-unknown');
check('worker has no handler for the unknown type', $worker->hasHandler('no_such_job_type') === false);

$threw = false;
$job   = null;
foreach ($queue->claim('worker-unknown', 20) as $c) {
    if ((int) $c['id'] === $unknownId) {
        $job = $c;
    }
}
check('unknown job was claimed', $job !== null);

if ($job !== null) {
    try {
        $ok = $worker->process($job);
        check('process() did not throw', true);
        check('process() reported failure', $ok === false);
    } catch (\Throwable $e) {
        $threw = true;
        check('process() did not throw', false, '(' . $e->getMessage() . ')');
    }
}

$row = $queue->find($unknownId);
check('unknown job status is failed', ($row['status'] ?? '') === 'failed', '(got ' . ($row['status'] ?? '?') . ')');
check('last_error names the bad type', str_contains((string) ($row['last_error'] ?? ''), 'no_such_job_type'));
check('last_error is clear and actionable', str_contains((string) ($row['last_error'] ?? ''), 'No handler registered'));
check('finished_at set on the failure', ($row['finished_at'] ?? '') !== '');

$logLevels = array_column($queue->logs($unknownId), 'level');
check('unknown job wrote an error job_logs row', in_array('error', $logLevels, true), '(got ' . json_encode($logLevels) . ')');

echo "\n== 11. Worker end-to-end + known handler ==\n";
// Drain any job still queued by earlier sections (the backed-off retry, the
// delayed job, etc.) so the single job claimed below is unambiguously ours.
for ($i = 0; $i < 20; $i++) {
    if ($queue->claim('worker-pre-e2e', 20) === []) {
        break;
    }
}
$knownId = track($queue->enqueue('generate_daily_post', ['topic' => 'e2e'], [
    'idempotency_key' => $tag . '_e2e',
]));
$pdo->prepare("UPDATE jobs SET run_after = datetime('now') WHERE id = ?")->execute([$knownId]);

$e2eWorker = new Worker($queue, 'worker-e2e');
// max is a budget on *processed* jobs, so an empty queue would spin forever.
// --once semantics keep this bounded: process exactly the one queued job.
$runStats  = $e2eWorker->run(max: 1, sleep: 0, once: true);
check('worker processed jobs', $runStats['processed'] === 1, '(processed ' . $runStats['processed'] . ')');
check('known job type succeeded', ($queue->find($knownId)['status'] ?? '') === 'succeeded', '(got ' . ($queue->find($knownId)['status'] ?? '?') . ')');
check('no fatal — worker returned cleanly', is_array($runStats));

echo "\n== 12. stats() ==\n";
$stats = $queue->stats();
check('stats has every status key', count(array_diff(
    ['queued', 'running', 'succeeded', 'failed', 'dead', 'cancelled'],
    array_keys($stats)
)) === 0, '(got ' . json_encode(array_keys($stats)) . ')');
check('stats counts are integers', array_sum(array_map('is_int', $stats)) === count($stats));

// ------------------------------------------------------------------ cleanup
// Restore the rows section 8 stashed past their backoff window, so a run that
// is interrupted here does not leave a live database with jobs a day out.
if (!empty($stashedIds)) {
    $in = implode(',', array_fill(0, count($stashedIds), '?'));
    $pdo->prepare("UPDATE jobs SET run_after = datetime('now') WHERE id IN ({$in})")
        ->execute($stashedIds);
}

$ids = $createdJobs;
foreach ($ids as $id) {
    $pdo->prepare('DELETE FROM job_logs WHERE job_id = ?')->execute([$id]);
    $pdo->prepare('DELETE FROM jobs WHERE id = ?')->execute([$id]);
}
$pdo->prepare('DELETE FROM cron_runs WHERE cron_key = ? AND scheduled_for IN (?, ?)')
    ->execute(['daily_blog', $slot, $slot2]);

// Leave the queue clean for the other tests / the real app.
for ($i = 0; $i < 5; $i++) {
    $queue->claim('worker-final', 50);
}

$left = $pdo->prepare('SELECT COUNT(*) FROM jobs WHERE status IN (?, ?)');
$left->execute(['queued', 'running']);
$remaining = (int) $left->fetchColumn();
check('cleanup left no queued/running jobs', $remaining === 0, "({$remaining} left)");

echo "\n" . str_repeat('=', 46) . "\n";
echo "  PASSED: {$pass}   FAILED: {$fail}\n";
echo str_repeat('=', 46) . "\n";
exit($fail === 0 ? 0 : 1);