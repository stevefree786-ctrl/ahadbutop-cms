<?php
/**
 * Queue worker CLI.
 *
 *   php bin/worker.php --once           process one job then exit
 *   php bin/worker.php --max=25         process at most 25 jobs then exit
 *   php bin/worker.php --sleep=5        idle sleep in seconds (default 5)
 *   php bin/worker.php --worker=alpha   name this worker in jobs.locked_by
 *   php bin/worker.php --stats          print queue counts and exit
 *
 * --stats and --help exit without running the loop.
 */
declare(strict_types=1);

use CMS\Queue\JobQueue;
use CMS\Queue\Worker;

// Same bootstrap the HTTP app uses.
require_once __DIR__ . '/../vendor/autoload.php';

if (is_file(base_path('.env'))) {
    Dotenv\Dotenv::createImmutable(base_path())->safeLoad();
}

$config = require __DIR__ . '/../config/database.php';

// ---------------------------------------------------------------- arguments

$opts = [
    'once'   => false,
    'max'    => null,
    'sleep'  => 5,
    'worker' => null,
    'stats'  => false,
    'help'   => false,
];

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--once') {
        $opts['once'] = true;
    } elseif ($arg === '--stats') {
        $opts['stats'] = true;
    } elseif ($arg === '--help' || $arg === '-h') {
        $opts['help'] = true;
    } elseif (str_starts_with($arg, '--max=')) {
        $opts['max'] = max(1, (int) substr($arg, 6));
    } elseif ($arg === '--max') {
        $opts['max'] = 1;
    } elseif (str_starts_with($arg, '--sleep=')) {
        $opts['sleep'] = max(0, (int) substr($arg, 8));
    } elseif (str_starts_with($arg, '--worker=')) {
        $opts['worker'] = substr($arg, 9);
    }
}

if ($opts['help']) {
    fwrite(STDOUT, <<<'TXT'
    Queue worker

      --once          process one job then exit
      --max=N         process at most N jobs then exit
      --sleep=N       idle sleep in seconds (default 5)
      --worker=NAME   worker identity recorded in jobs.locked_by
      --stats         print queue counts and exit
      --help          this message

    TXT);
    exit(0);
}

$queue = new JobQueue();
$workerId = $opts['worker'] ?: ('worker-' . getmypid());
$worker = new Worker($queue, $workerId);

if ($opts['stats']) {
    fwrite(STDOUT, json_encode($queue->stats(), JSON_PRETTY_PRINT) . PHP_EOL);
    exit(0);
}

if (!function_exists('pcntl_signal')) {
    fwrite(STDERR, "note: pcntl unavailable; SIGTERM/SIGINT handling disabled on this platform\n");
}

fwrite(STDOUT, "worker {$worker->id()} starting\n");

$stats = $worker->run(
    max: $opts['max'],
    sleep: (int) $opts['sleep'],
    once: (bool) $opts['once'],
);

fwrite(STDOUT, sprintf(
    "worker %s finished — processed: %d, succeeded: %d, failed: %d%s\n",
    $worker->id(),
    $stats['processed'],
    $stats['succeeded'],
    $stats['failed'],
    $stats['stopped'] ? ' (stopped by signal)' : ''
));

exit($stats['failed'] > 0 ? 1 : 0);