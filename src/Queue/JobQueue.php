<?php
declare(strict_types=1);

namespace CMS\Queue;

use CMS\Database\Connection;
use PDO;

/**
 * DB-backed job queue.
 *
 * Correctness rules this class exists to hold:
 *
 *  1. Claiming is a compare-and-swap. `claim()` takes SQLite's write lock with
 *     BEGIN IMMEDIATE, then flips status 'queued' -> 'running' guarded by
 *     `AND status='queued'`, accepting the row only when rowCount()===1. Two
 *     workers racing for the same job produce exactly one winner; the loser sees
 *     rowCount()===0 and moves on.
 *  2. Every timestamp is written by SQLite (`datetime('now')` / `datetime('now', ?)`).
 *     PHP date() is never used for a DB write, so a worker in any local timezone
 *     still agrees with the database clock.
 *  3. Every SQL statement interpolates nothing — table/column names are literals,
 *     every value is a bound `?`.
 */
class JobQueue
{
    public const STATUS_QUEUED    = 'queued';
    public const STATUS_RUNNING   = 'running';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED    = 'failed';
    public const STATUS_DEAD      = 'dead';
    public const STATUS_CANCELLED = 'cancelled';

    public const LOG_LEVELS = ['debug', 'info', 'warn', 'error'];

    private Connection $conn;
    private PDO $pdo;

    /**
     * How many times to retry acquiring the SQLite write lock in claim().
     * BEGIN IMMEDIATE does NOT honour busy_timeout — it fails immediately with
     * "database is locked" when another writer holds the lock — so contention is
     * handled here with a bounded backoff instead.
     */
    private int $lockRetries = 60;
    private int $lockRetryDelayUs = 25_000; // 25ms

    public function __construct(?Connection $conn = null)
    {
        $this->conn = $conn ?? new Connection(require base_path('config/database.php'));
        $this->pdo  = $this->conn->getPdo();

        // Concurrency-critical: a worker that hits "database is locked" must wait
        // for the competing worker to finish rather than aborting the claim. WAL
        // lets readers run during a write; busy_timeout covers writer-vs-writer.
        $this->pdo->exec('PRAGMA busy_timeout = 10000');
        $this->pdo->exec('PRAGMA journal_mode = WAL');
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    // ---------------------------------------------------------------- enqueue

    /**
     * Enqueue a job.
     *
     * $opts: delay (seconds, int) | priority (int, LOWER runs first, default 100)
     *        max_attempts (int) | idempotency_key (string) | created_by (int)
     *
     * Idempotent when `idempotency_key` is supplied: a duplicate is a no-op and
     * returns the EXISTING job id rather than throwing, so a cron firing twice in
     * the same slot cannot double-post.
     */
    public function enqueue(string $type, array $payload = [], array $opts = []): int
    {
        $delay        = max(0, (int) ($opts['delay'] ?? 0));
        $priority     = (int) ($opts['priority'] ?? 100);
        $maxAttempts  = max(1, (int) ($opts['max_attempts'] ?? 3));
        $idempotency  = $opts['idempotency_key'] ?? null;
        $createdBy    = isset($opts['created_by']) ? (int) $opts['created_by'] : null;

        // run_after is computed by SQLite from its own clock. A non-positive
        // modifier is the special form datetime('now') (exactly zero seconds);
        // anything else becomes datetime('now', '<n> seconds').
        $modifier = $delay > 0 ? sprintf('%+d seconds', $delay) : '+0 seconds';

        $sql = 'INSERT OR IGNORE INTO jobs
                    (type, payload, status, priority, run_after, max_attempts,
                     idempotency_key, created_by, created_at)
                VALUES (?, ?, ?, ?, datetime(\'now\', ?), ?, ?, ?, datetime(\'now\'))';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            $type,
            json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            self::STATUS_QUEUED,
            $priority,
            $modifier,
            $maxAttempts,
            $idempotency,
            $createdBy,
        ]);

        if ($stmt->rowCount() === 1) {
            return (int) $this->pdo->lastInsertId();
        }

        // INSERT OR IGNORE was ignored => the idempotency_key already exists.
        // Return the existing id so callers get a usable handle either way.
        $existing = $this->conn->query(
            'SELECT id FROM jobs WHERE idempotency_key = ?',
            [$idempotency]
        )->fetch();

        return $existing ? (int) $existing['id'] : 0;
    }

    // ------------------------------------------------------------------ claim

    /**
     * Atomically claim up to $limit queued jobs for $workerId.
     *
     * Concurrency contract: BEGIN IMMEDIATE takes SQLite's write lock up front,
     * so the select-then-update below cannot interleave with another claim. Each
     * UPDATE is additionally guarded by `AND status='queued'`, making it a
     * compare-and-swap: rowCount()===1 means this worker won the row.
     *
     * @return array<int, array<string,mixed>> claimed rows (decoded payload), [] if none
     */
    public function claim(string $workerId, int $limit = 1): array
    {
        if ($limit < 1) {
            return [];
        }

        $claimed = [];

        // BEGIN IMMEDIATE ignores busy_timeout, so acquisition is retried
        // explicitly. The body is idempotent on failure (it always rolls back),
        // which makes a retry safe.
        $attempt = 0;
        while (true) {
            $inTransaction = false;

            try {
                // IMMEDIATE: acquire the RESERVED lock now, so a competing
                // writer blocks here instead of racing us after we read the ids.
                $this->pdo->exec('BEGIN IMMEDIATE');
                $inTransaction = true;

                $candidates = $this->conn->query(
                    "SELECT id
                       FROM jobs
                      WHERE status = 'queued'
                        AND run_after <= datetime('now')
                      ORDER BY priority ASC, id ASC
                      LIMIT ?",
                    [$limit]
                )->fetchAll();

                foreach ($candidates as $row) {
                    $jobId = (int) $row['id'];

                    // Compare-and-swap. The status guard is what makes a racing
                    // second claim a no-op instead of a double-dispatch.
                    $upd = $this->pdo->prepare(
                        "UPDATE jobs
                            SET status     = 'running',
                                locked_by  = ?,
                                locked_at  = datetime('now'),
                                started_at = datetime('now'),
                                attempts   = attempts + 1
                          WHERE id = ?
                            AND status = 'queued'"
                    );
                    $upd->execute([$workerId, $jobId]);

                    if ($upd->rowCount() !== 1) {
                        continue; // lost the race — do not claim
                    }

                    $job = $this->conn->query('SELECT * FROM jobs WHERE id = ?', [$jobId])->fetch();
                    if ($job) {
                        $job['payload']      = json_decode((string) $job['payload'], true) ?? [];
                        $job['id']           = (int) $job['id'];
                        $job['attempts']     = (int) $job['attempts'];
                        $job['priority']     = (int) $job['priority'];
                        $job['max_attempts'] = (int) $job['max_attempts'];
                        $claimed[] = $job;
                    }
                }

                $this->pdo->exec('COMMIT');
                $inTransaction = false;

                return $claimed;
            } catch (\PDOException $e) {
                // Roll back using our own flag: PDO::inTransaction() does not
                // track a transaction opened via exec('BEGIN IMMEDIATE') on
                // SQLite, so trusting it could leak an open write lock.
                if ($inTransaction) {
                    try {
                        $this->pdo->exec('ROLLBACK');
                    } catch (\Throwable) {
                        // Lock is released on close() regardless.
                    }
                }

                if ($this->isLockContention($e) && $attempt < $this->lockRetries) {
                    // Random jitter keeps contending workers from re-colliding
                    // in lockstep on every retry.
                    usleep($this->lockRetryDelayUs + random_int(0, $this->lockRetryDelayUs));
                    $attempt++;
                    continue;
                }

                throw $e;
            } catch (\Throwable $e) {
                if ($inTransaction) {
                    try {
                        $this->pdo->exec('ROLLBACK');
                    } catch (\Throwable) {
                    }
                }
                throw $e;
            }
        }
    }

    /**
     * Does this PDOException mean "another writer holds the lock"?
     *
     * SQLite reports contention two ways depending on build: the extended
     * "database is locked" text, and SQLITE_BUSY (5) surfaced as a generic
     * error code.
     */
    private function isLockContention(\PDOException $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'locked')
            || str_contains($message, 'busy')
            || (int) ($e->errorInfo[1] ?? 0) === 5;
    }

    // ---------------------------------------------------------------- outcomes

    /**
     * Mark a claimed job succeeded, storing its JSON result.
     */
    public function complete(int $jobId, mixed $result = null): void
    {
        $this->conn->query(
            "UPDATE jobs
                SET status      = 'succeeded',
                    result      = ?,
                    last_error  = NULL,
                    finished_at = datetime('now')
              WHERE id = ?",
            [json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $jobId]
        );

        $this->log($jobId, 'info', 'Job completed successfully', ['result' => $result]);
    }

    /**
     * Record a failed attempt.
     *
     * Retry semantics: if the attempt count has reached max_attempts the job is
     * 'dead'. Otherwise it returns to 'queued' with run_after pushed out by an
     * exponential backoff of min(300, 2 ** attempts) seconds.
     */
    public function fail(int $jobId, string $error): void
    {
        $job = $this->conn->query('SELECT attempts, max_attempts FROM jobs WHERE id = ?', [$jobId])->fetch();
        if (!$job) {
            return;
        }

        $attempts    = (int) $job['attempts'];
        $maxAttempts = (int) $job['max_attempts'];
        $exhausted   = $attempts >= $maxAttempts;

        if ($exhausted) {
            $this->conn->query(
                "UPDATE jobs
                    SET status      = 'dead',
                        last_error  = ?,
                        locked_by   = NULL,
                        locked_at   = NULL,
                        finished_at = datetime('now')
                  WHERE id = ?",
                [$error, $jobId]
            );
            $this->log($jobId, 'error', 'Job exhausted retries and is dead', [
                'attempts'     => $attempts,
                'max_attempts' => $maxAttempts,
                'error'        => $error,
            ]);
            return;
        }

        $backoff = min(300, 2 ** $attempts);

        $this->conn->query(
            "UPDATE jobs
                SET status     = 'queued',
                    last_error = ?,
                    locked_by  = NULL,
                    locked_at  = NULL,
                    run_after  = datetime('now', ?)
              WHERE id = ?",
            [$error, sprintf('%+d seconds', $backoff), $jobId]
        );

        $this->log($jobId, 'warn', 'Job failed; requeued with backoff', [
            'attempts' => $attempts,
            'backoff'  => $backoff,
            'error'    => $error,
        ]);
    }

    /**
     * Terminal failure: mark 'failed' with no further retries (used for
     * non-retryable problems such as an unknown job type).
     */
    public function failPermanent(int $jobId, string $error): void
    {
        $this->conn->query(
            "UPDATE jobs
                SET status      = 'failed',
                    last_error  = ?,
                    locked_by   = NULL,
                    locked_at   = NULL,
                    finished_at = datetime('now')
              WHERE id = ?",
            [$error, $jobId]
        );

        $this->log($jobId, 'error', 'Job failed permanently (no retry)', ['error' => $error]);
    }

    /**
     * Append a row to job_logs. Level is validated against the table's CHECK.
     */
    public function log(int $jobId, string $level, string $message, array $context = []): void
    {
        $level = strtolower($level);
        if (!in_array($level, self::LOG_LEVELS, true)) {
            $level = 'info';
        }

        $this->conn->query(
            'INSERT INTO job_logs (job_id, level, message, context, created_at)
             VALUES (?, ?, ?, ?, datetime(\'now\'))',
            [
                $jobId,
                $level,
                $message,
                $context === [] ? null : json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]
        );
    }

    // ------------------------------------------------------------------- reads

    public function find(int $jobId): ?array
    {
        return $this->conn->query('SELECT * FROM jobs WHERE id = ?', [$jobId])->fetch() ?: null;
    }

    public function logs(int $jobId): array
    {
        return $this->conn->query(
            'SELECT * FROM job_logs WHERE job_id = ? ORDER BY id ASC',
            [$jobId]
        )->fetchAll();
    }

    /**
     * Counts by status. Every known status is always present (zero-filled) so
     * callers never have to guard for missing keys.
     */
    public function stats(): array
    {
        $stats = [
            self::STATUS_QUEUED    => 0,
            self::STATUS_RUNNING   => 0,
            self::STATUS_SUCCEEDED => 0,
            self::STATUS_FAILED    => 0,
            self::STATUS_DEAD      => 0,
            self::STATUS_CANCELLED => 0,
        ];

        $rows = $this->conn->query('SELECT status, COUNT(*) AS c FROM jobs GROUP BY status')->fetchAll();
        foreach ($rows as $row) {
            $stats[(string) $row['status']] = (int) $row['c'];
        }

        return $stats;
    }
}