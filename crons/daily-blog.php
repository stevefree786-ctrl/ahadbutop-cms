<?php
/**
 * Daily blog cron.
 *
 *   php crons/daily-blog.php                 enqueue today's generate_daily_post
 *   php crons/daily-blog.php --dry-run       print the plan, write nothing
 *   php crons/daily-blog.php --date=2026-03-05
 *   php crons/daily-blog.php --force         enqueue even if a post already exists
 *   php crons/daily-blog.php --topic="..."   override the generated topic
 *   php crons/daily-blog.php --help
 *
 * composer: `composer cron:daily-blog`
 *
 * This script only *enqueues*. It never calls an agent and never writes a
 * post itself — that is the worker's job (bin/worker.php), which is why a
 * blank API key is not fatal here.
 *
 * Idempotency is enforced twice, on purpose:
 *
 *  1. `jobs.idempotency_key` is UNIQUE and is keyed by calendar day, so a
 *     double cron tick within one day can never produce two jobs. That is the
 *     guarantee this script relies on.
 *  2. `cron_runs` is UNIQUE (cron_key, scheduled_for) via Cron::claimSlot().
 *     Belt-and-braces, and it gives an operator a readable history of when
 *     the cron actually fired.
 *
 * Under --force BOTH are bypassed, so a forced run replaces the day's job
 * instead of quietly resolving to the existing one.
 */
declare(strict_types=1);

use CMS\Queue\Cron;
use CMS\Queue\CronCli;
use CMS\Queue\JobQueue;
use CMS\Queue\Worker;

// Same bootstrap as bin/worker.php: autoload (which pulls in base_path()),
// then Dotenv so config/database.php resolves the same DB the worker uses.
// base_path() keeps every other path absolute, so the script is cwd-agnostic.
require_once __DIR__ . '/../vendor/autoload.php';

if (is_file(base_path('.env'))) {
    Dotenv\Dotenv::createImmutable(base_path())->safeLoad();
}

$SPEC = ['dry-run' => 'bool', 'force' => 'bool', 'date' => 'string', 'topic' => 'string'];

$usage = <<<'TXT'
Daily blog cron

  --dry-run       print what would be enqueued and write nothing
  --date=YYYY-MM-DD   use a specific calendar day (UTC); default: today
  --force         enqueue even if this day is already spoken for
  --topic=TEXT    topic for the post; default: derived from the site
  --help          this message

Exactly one generate_daily_post job is enqueued per UTC day. Running the
cron twice on the same day is a no-op, not a second post.
TXT;

['opts' => $opts, 'error' => $parseError] = CronCli::parse(array_slice($argv, 1), $SPEC);

if ($parseError !== null) {
    CronCli::err('daily-blog: ' . $parseError);
    CronCli::err('daily-blog: run with --help for usage');
    exit(CronCli::EXIT_USAGE);
}

if ($opts[CronCli::HELP]) {
    CronCli::out($usage);
    exit(0);
}

$dryRun = (bool) $opts['dry-run'];
$force  = (bool) $opts['force'];

/** @var \PDO $pdo */
$pdo = null;

try {
    // ------------------------------------------------------------- resolve day

    $day = isset($opts['date']) && $opts['date'] !== ''
        ? CronCli::day((string) $opts['date'])
        : new \DateTimeImmutable('today', new \DateTimeZone('UTC'));

    $dayKey = $day->format('Y-m-d');

    $conn  = new \CMS\Database\Connection(require base_path('config/database.php'));
    $queue = new JobQueue($conn);
    $pdo   = $queue->pdo();
    $cron  = new Cron($queue, $conn);

    // Same UTC midnight slot Cron::claimSlot() records, so --date= and the
    // default path can never land on two different slots for one day.
    $slot = $cron->slotFor('daily_blog', $day);

    // Canonical idempotency key. YYYY-MM-DD matches the format --date= accepts,
    // so `--date` and the default path address the same key for the same day.
    $key = 'daily-blog:' . $dayKey;

    // -------------------------------------------------------------- job topic

    $topic = trim((string) ($opts['topic'] ?? ''));
    if ($topic === '') {
        $site = \CMS\Agents\Context::siteSettings($conn);
        $name = trim($site['title'] ?? '');
        $topic = 'a timely post for ' . ($name !== '' ? $name : 'this site');
    }

    $payload = [
        'topic'        => $topic,
        'cron_key'     => 'daily_blog',
        'scheduled_for' => $slot,
        'date'         => $dayKey,
        'author_role'  => 'author',
    ];

    // ------------------------------------------------------------ preflight

    $guards = [];

    if (!Worker::isKnownType('generate_daily_post')) {
        CronCli::err('daily-blog: generate_daily_post is not a registered job type '
            . '(Worker::JOB_TYPES is the source of truth)');
        exit(CronCli::EXIT_FAILURE);
    }

    $guards[] = ['job type generate_daily_post is registered (Worker::JOB_TYPES)', Worker::isKnownType('generate_daily_post')];

    // A post already attributed to today means today's entry already ran.
    $existingToday = $pdo->prepare(
        "SELECT COUNT(*) FROM posts
          WHERE type = 'post'
            AND origin IN ('ai', 'ai_edited')
            AND substr(COALESCE(published_at, created_at), 1, 10) = ?"
    );
    $existingToday->execute([$dayKey]);
    $postsToday = (int) $existingToday->fetchColumn();
    $guards[] = ["posts already generated for {$dayKey}: {$postsToday}", $force || $postsToday === 0];

    $already = $pdo->prepare('SELECT id FROM jobs WHERE idempotency_key = ?');
    $already->execute([$key]);
    $existingJobId = $already->fetchColumn();
    $guards[] = [
        'idempotency key ' . $key . ($existingJobId !== false
            ? " already used by job #{$existingJobId}"
            : ' is unused'),
        $force || $existingJobId === false,
    ];

    // -------------------------------------------------------------- dry run

    if ($dryRun) {
        CronCli::out('daily-blog dry run for ' . $dayKey . ' (slot ' . $slot . ')');
        CronCli::out('  would enqueue: generate_daily_post');
        CronCli::out('  topic: ' . $topic);
        CronCli::out('  idempotency key: ' . $key);
        CronCli::out('  payload: ' . json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        foreach ($guards as [$label, $wouldRun]) {
            CronCli::out(sprintf('  %s %s', $wouldRun ? '[ok]  ' : '[skip]', $label));
        }

        if (CronCli::apiKeyNotice() !== null) {
            CronCli::out('  [note] ' . CronCli::apiKeyNotice());
        }

        CronCli::out('  nothing was written');
        exit(0);
    }

    // ------------------------------------------------------- guarded run

    // Any failed guard means this day is already handled. Exit 0: an already
    // published post is the desired end state, not an error.
    $blocked = array_values(array_filter($guards, static fn(array $g): bool => !$g[1]));
    if ($blocked !== []) {
        CronCli::out('daily-blog: nothing to do for ' . $dayKey . ' - already handled');
        foreach ($guards as [$label, $ok]) {
            CronCli::out(sprintf('  %s %s', $ok ? '[ok]  ' : '[skip]', $label));
        }
        CronCli::out('  (use --force to enqueue anyway)');
        exit(0);
    }

    if ($force) {
        // Clear BOTH idempotency layers, not just the job. claimSlot() opens
        // with INSERT OR IGNORE against UNIQUE(cron_key, scheduled_for), so a
        // stale cron_runs row would make claimSlot() return false and --force
        // would silently produce nothing.
        //
        // cron_runs.job_id references jobs(id), so the slot row has to go
        // first. The WHERE clause keeps this to exactly one day: the scheduled
        // run we are deliberately replacing.
        $pdo->prepare('DELETE FROM cron_runs WHERE cron_key = ? AND scheduled_for = ?')
            ->execute(['daily_blog', $slot]);

        $pdo->prepare('DELETE FROM job_logs WHERE job_id IN (SELECT id FROM jobs WHERE idempotency_key = ?)')
            ->execute([$key]);
        $pdo->prepare('DELETE FROM jobs WHERE idempotency_key = ?')->execute([$key]);
    }

    $claimed = $cron->claimSlot('daily_blog', $slot, 'generate_daily_post', $payload, [
        'idempotency_key' => $key,
        'priority'        => 100,
        'max_attempts'    => 3,
    ]);

    $cron->record('daily_blog', $slot, $claimed ? 'ok' : 'skipped', $topic);

    if ($claimed) {
        CronCli::out(sprintf('daily-blog: enqueued a generate_daily_post job for %s', $dayKey));
        CronCli::out('  topic: ' . $topic);
        CronCli::out('  idempotency key: ' . $key);
        if (($notice = CronCli::apiKeyNotice()) !== null) {
            CronCli::out('  note: ' . $notice);
        }
        CronCli::out('  a worker will pick this up: php bin/worker.php');
        exit(0);
    }

    // claimSlot() lost the race to a concurrent cron tick. Its UNIQUE
    // constraint guarantees exactly one job exists for this slot.
    CronCli::out(sprintf('daily-blog: slot %s is already claimed by another run - no new job', $slot));
    exit(0);
} catch (\InvalidArgumentException $e) {
    CronCli::err('daily-blog: ' . $e->getMessage());
    exit(CronCli::EXIT_USAGE);
} catch (\Throwable $e) {
    CronCli::err('daily-blog: ' . $e->getMessage());
    exit(CronCli::EXIT_FAILURE);
}
