<?php
declare(strict_types=1);

namespace CMS\Queue;

use CMS\Database\Connection;

/**
 * Cron slot bookkeeping.
 *
 * Idempotency is enforced by the database, not by application logic:
 * `cron_runs` carries UNIQUE (cron_key, scheduled_for). A cron that fires twice
 * for the same slot (a retry, an overlapping scheduler tick, two machines) can
 * therefore never double-post — the second insert is rejected by the constraint.
 *
 * All slots are computed in UTC. PHP's date() is only ever used here for
 * *computing which slot we are in* (never for a DB write); every row the queue
 * writes gets its timestamp from SQLite's own clock.
 */
class Cron
{
    private Connection $conn;
    private JobQueue $queue;

    public function __construct(?JobQueue $queue = null, ?Connection $conn = null)
    {
        $this->conn  = $conn ?? new Connection(require base_path('config/database.php'));
        $this->queue = $queue ?? new JobQueue($this->conn);
    }

    public function queue(): JobQueue
    {
        return $this->queue;
    }

    /**
     * Has this (key, slot) already been claimed?
     *
     * Check-then-act is advisory only; the authoritative guarantee is the UNIQUE
     * constraint inside record(). Callers that want strict exactly-once semantics
     * must use claimSlot() rather than this check.
     */
    public function shouldRun(string $cronKey, string $scheduledFor): bool
    {
        $row = $this->conn->query(
            'SELECT id FROM cron_runs WHERE cron_key = ? AND scheduled_for = ?',
            [$cronKey, $scheduledFor]
        )->fetch();

        return $row === false;
    }

    /**
     * Atomically claim a cron slot, enqueueing its job in the same call.
     *
     * Returns true when THIS caller won the slot (job enqueued), false when the
     * slot was already taken. This is the safe path: the insert is
     * INSERT OR IGNORE against UNIQUE(cron_key, scheduled_for), so exactly one
     * caller can ever get true for a given slot.
     *
     * @return bool true if the slot was claimed and the job enqueued
     */
    public function claimSlot(
        string $cronKey,
        string $scheduledFor,
        string $jobType,
        array $payload = [],
        array $opts = []
    ): bool {
        $stmt = $this->conn->getPdo()->prepare(
            "INSERT OR IGNORE INTO cron_runs
                (cron_key, scheduled_for, job_id, status, started_at)
             VALUES (?, ?, NULL, 'running', datetime('now'))"
        );
        $stmt->execute([$cronKey, $scheduledFor]);

        if ($stmt->rowCount() !== 1) {
            return false; // slot already claimed — another run owns it
        }

        $jobId = $this->queue->enqueue(
            $jobType,
            $payload + ['cron_key' => $cronKey, 'scheduled_for' => $scheduledFor],
            // Keying the job by the same (key, slot) makes the enqueue itself
            // idempotent too, so a crash between claim and enqueue cannot retry
            // into a second post on the next tick.
            ($opts + [
                'idempotency_key' => $cronKey . ':' . $scheduledFor,
            ])
        );

        $this->conn->query(
            "UPDATE cron_runs SET job_id = ? WHERE cron_key = ? AND scheduled_for = ?",
            [$jobId, $cronKey, $scheduledFor]
        );

        return true;
    }

    /**
     * Record the outcome of a cron run.
     *
     * Uses INSERT OR REPLACE on the slot key, so calling record() twice for the
     * same slot updates the single row rather than creating a duplicate — the
     * second call cannot double-post.
     */
    public function record(
        string $cronKey,
        string $scheduledFor,
        string $status,
        ?string $detail = null,
        ?int $jobId = null
    ): bool {
        if (!in_array($status, ['ok', 'failed', 'skipped', 'running'], true)) {
            throw new \InvalidArgumentException(
                "Invalid cron status '{$status}'; expected ok|failed|skipped|running"
            );
        }

        $stmt = $this->conn->getPdo()->prepare(
            "INSERT INTO cron_runs
                (cron_key, scheduled_for, job_id, status, detail, started_at, finished_at)
             VALUES (?, ?, ?, ?, ?, datetime('now'), datetime('now'))
             ON CONFLICT(cron_key, scheduled_for) DO UPDATE SET
                job_id      = COALESCE(excluded.job_id, cron_runs.job_id),
                status      = excluded.status,
                detail      = excluded.detail,
                finished_at = excluded.finished_at"
        );
        $stmt->execute([$cronKey, $scheduledFor, $jobId, $status, $detail]);

        return true;
    }

    /**
     * The crons this system knows about, with the UTC slot arithmetic each needs.
     *
     * slot() returns the canonical scheduled_for string for a point in time; two
     * different invocations within the same slot produce identical strings, which
     * is exactly what makes the UNIQUE constraint suppress the duplicate.
     */
    public function definitions(): array
    {
        return [
            'daily_blog' => [
                'description' => 'Generate one AI blog post per day.',
                'job_type'    => 'generate_daily_post',
                'interval'    => 'daily',
                'slot'        => static fn(\DateTimeImmutable $at): string
                    => $at->setTimezone(new \DateTimeZone('UTC'))
                          ->setTime(0, 0, 0)
                          ->format('Y-m-d H:i:s'),
            ],

            'seo_persist' => [
                'description' => 'Flush queued SEO metadata to posts/pages.',
                'job_type'    => 'seo.persist',
                'interval'    => 'hourly',
                'slot'        => static fn(\DateTimeImmutable $at): string
                    => $at->setTimezone(new \DateTimeZone('UTC'))
                          ->setTime((int) $at->format('G'), 0, 0)
                          ->format('Y-m-d H:i:s'),
            ],

            'sitemap_ping' => [
                'description' => 'Rebuild sitemap.xml and notify search engines.',
                'job_type'    => 'sitemap.ping',
                'interval'    => 'daily',
                'slot'        => static fn(\DateTimeImmutable $at): string
                    => $at->setTimezone(new \DateTimeZone('UTC'))
                          ->setTime(3, 0, 0)
                          ->format('Y-m-d H:i:s'),
            ],

            'seo_audit' => [
                'description' => 'Re-score published content for SEO regressions.',
                'job_type'    => 'seo.audit',
                'interval'    => 'daily',
                'slot'        => static fn(\DateTimeImmutable $at): string
                    => $at->setTimezone(new \DateTimeZone('UTC'))
                          ->setTime(4, 0, 0)
                          ->format('Y-m-d H:i:s'),
            ],
        ];
    }

    /**
     * Compute the canonical UTC slot for a cron key at a given instant.
     * Unknown keys fall back to a per-minute slot.
     */
    public function slotFor(string $cronKey, ?\DateTimeImmutable $at = null): string
    {
        $at   = $at ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $defs = $this->definitions();

        if (!isset($defs[$cronKey])) {
            return $at->setTimezone(new \DateTimeZone('UTC'))
                      ->format('Y-m-d H:i:00');
        }

        return ($defs[$cronKey]['slot'])($at);
    }
}