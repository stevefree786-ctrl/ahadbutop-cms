<?php
declare(strict_types=1);

namespace CMS\Queue;

use CMS\Database\Connection;

/**
 * Long-running queue worker.
 *
 * Loop: claim a job -> dispatch to its handler -> complete or fail. A handler
 * that throws is caught and routed to fail() so one bad job can never take the
 * worker down; an unknown job type is a permanent (non-retried) failure with a
 * message naming the type, because retrying an unroutable job would just burn
 * the retry budget and then die anyway.
 */
class Worker
{
    private JobQueue $queue;
    private string $workerId;
    private bool $shouldStop = false;

    /** @var array<string, callable> */
    private array $handlers = [];

    public function __construct(?JobQueue $queue = null, ?string $workerId = null)
    {
        $this->queue    = $queue ?? new JobQueue();
        $this->workerId = $workerId ?? ('worker-' . getmypid());
        $this->registerDefaultHandlers();
    }

    public function id(): string
    {
        return $this->workerId;
    }

    /**
     * Register (or override) a handler for a job type.
     *
     * @param callable(JobQueue, array $payload, array $job): mixed $handler
     */
    public function on(string $type, callable $handler): void
    {
        $this->handlers[$type] = $handler;
    }

    public function hasHandler(string $type): bool
    {
        return isset($this->handlers[$type]);
    }

    /**
     * Ask the worker to finish after the current job. Safe to call from a signal
     * handler — it only flips a flag, never interrupts a DB write.
     */
    public function stop(): void
    {
        $this->shouldStop = true;
    }

    /**
     * Install SIGTERM/SIGINT handlers when pcntl is available.
     *
     * On Windows PHP builds pcntl is usually absent; we degrade quietly to a
     * worker that only stops via its --max/--once budget rather than pretending
     * signal support exists.
     */
    private function installSignalHandlers(): bool
    {
        if (!function_exists('pcntl_signal') || !function_exists('pcntl_async_signals')) {
            return false;
        }

        pcntl_async_signals(true);
        foreach ([SIGTERM, SIGINT] as $sig) {
            pcntl_signal($sig, function (): void {
                $this->stop();
            });
        }

        return true;
    }

    /**
     * Every job type this codebase dispatches on.
     *
     * This list is the single source of truth. The worker's no-op registration
     * and JobQueueController's enqueue validation both read it, so a type can
     * never be accepted by the API yet silently dead-lettered by the worker.
     * Adding a type here is the only step needed to make it enqueueable.
     */
    public const JOB_TYPES = [
        'generate_daily_post',
        'render_site',
        'seo.persist',
        'seo.audit',
        'sitemap.ping',
    ];

    /** True when $type has a default (or caller-registered) handler. */
    public static function isKnownType(string $type): bool
    {
        return in_array($type, self::JOB_TYPES, true);
    }

    /**
     * Handlers for every job type this codebase references.
     *
     * seo.persist is the one type actually enqueued today (SeoAgent). The others
     * are registered as explicit no-ops that log and report what they would do,
     * so a queued job is never silently dropped and never fatals on a missing
     * handler.
     */
    private function registerDefaultHandlers(): void
    {
        $noop = static function (JobQueue $queue, array $payload, array $job): array {
            $queue->log((int) $job['id'], 'info', 'Handler executed (no-op)', [
                'type' => (string) $job['type'],
            ]);

            return ['status' => 'noop', 'type' => (string) $job['type']];
        };

        foreach (self::JOB_TYPES as $type) {
            $this->on($type, $noop);
        }
    }

    /**
     * Process a single already-claimed job.
     *
     * @return bool true when the job succeeded
     */
    public function process(array $job): bool
    {
        $jobId = (int) $job['id'];
        $type  = (string) $job['type'];

        if (!$this->hasHandler($type)) {
            // Permanent: no handler means no amount of retrying will help.
            $message = sprintf(
                'No handler registered for job type "%s"; known types: %s',
                $type,
                implode(', ', array_keys($this->handlers)) ?: '(none)'
            );
            $this->queue->failPermanent($jobId, $message);
            return false;
        }

        try {
            $this->queue->log($jobId, 'info', 'Job started', [
                'worker'  => $this->workerId,
                'type'    => $type,
                'attempt' => (int) $job['attempts'],
            ]);

            $result = ($this->handlers[$type])($this->queue, (array) $job['payload'], $job);
            $this->queue->complete($jobId, $result);

            return true;
        } catch (\Throwable $e) {
            // Retryable by default: unexpected faults (network, transient SQL)
            // should get another go, bounded by max_attempts.
            $this->queue->fail($jobId, $e->getMessage());
            return false;
        }
    }

    /**
     * Claim and run jobs until stopped or out of budget.
     *
     * @param int|null  $max   process at most N jobs (null = unlimited)
     * @param int       $sleep seconds to wait when the queue is idle
     * @param bool      $once  process a single job then return
     * @return array{processed:int,succeeded:int,failed:int,stopped:bool}
     */
    public function run(?int $max = null, int $sleep = 5, bool $once = false): array
    {
        $hasSignals = $this->installSignalHandlers();
        $stats = ['processed' => 0, 'succeeded' => 0, 'failed' => 0, 'stopped' => false];

        while (!$this->shouldStop) {
            if ($max !== null && $stats['processed'] >= $max) {
                break;
            }

            $claimed = $this->queue->claim($this->workerId, 1);

            if ($claimed === []) {
                if ($once) {
                    break; // nothing to do — exit instead of spinning
                }
                $this->idle($sleep, $hasSignals);
                continue;
            }

            foreach ($claimed as $job) {
                $ok = $this->process($job);
                $stats['processed']++;
                $stats[$ok ? 'succeeded' : 'failed']++;

                if ($once) {
                    return $stats;
                }
            }
        }

        $stats['stopped'] = $this->shouldStop;
        return $stats;
    }

    /**
     * Idle wait between polls. Signal-aware where possible so a SIGTERM during
     * sleep is not swallowed until the next poll.
     */
    private function idle(int $sleep, bool $hasSignals): void
    {
        if ($sleep <= 0) {
            return;
        }

        if ($hasSignals && function_exists('usleep')) {
            // 1s slices so a stop signal lands promptly rather than after $sleep.
            for ($i = 0; $i < $sleep && !$this->shouldStop; $i++) {
                usleep(1_000_000);
            }
            return;
        }

        sleep($sleep);
    }
}