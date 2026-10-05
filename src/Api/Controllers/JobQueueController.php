<?php

namespace CMS\Api\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use CMS\Database\Connection;
use CMS\Queue\JobQueue;
use CMS\Queue\Worker;
use CMS\Api\Responder;

/**
 * Job queue endpoints for the admin UI.
 *
 * The Connection comes from the request container rather than being rebuilt
 * per call: instantiating one here would open a fresh SQLite handle on every
 * request and bypass whatever the app configured.
 */
class JobQueueController
{
    public function __construct(
        private Connection $db,
        private ?JobQueue $queue = null
    ) {
        $this->queue ??= new JobQueue($db);
    }

    /**
     * List jobs, newest first, optionally filtered by status/queue.
     */
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $q = $request->getQueryParams();
        $response = $request->getAttribute('response') ?? new \Slim\Psr7\Response();

        $where  = ['1=1'];
        $params = [];

        if (!empty($q['status'])) {
            // The six values the table's CHECK constraint permits. An
            // unrecognised status is rejected rather than dropped: silently
            // ignoring the filter would hand the caller the unfiltered list,
            // which reads as "these are the dead jobs" when they are not.
            $allowed = [
                JobQueue::STATUS_QUEUED, JobQueue::STATUS_RUNNING, JobQueue::STATUS_SUCCEEDED,
                JobQueue::STATUS_FAILED,  JobQueue::STATUS_DEAD,    JobQueue::STATUS_CANCELLED,
            ];
            if (!in_array($q['status'], $allowed, true)) {
                return Responder::error(
                    $response,
                    422,
                    'Invalid status. Expected one of: ' . implode(', ', $allowed)
                );
            }
            $where[]  = 'status = ?';
            $params[] = $q['status'];
        }
        if (!empty($q['type'])) {
            $where[]  = 'type = ?';
            $params[] = (string) $q['type'];
        }

        $limit  = max(1, min((int) ($q['limit'] ?? 50), 200));
        $offset = max(0, (int) ($q['offset'] ?? 0));

        $sql = 'SELECT id, type, status, priority, attempts, max_attempts, run_after,
                       locked_by, last_error, created_at, started_at, finished_at
                FROM jobs
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY created_at DESC, id DESC
                LIMIT ? OFFSET ?';

        $stmt = $this->db->getPdo()->prepare($sql);
        $stmt->execute([...$params, $limit, $offset]);
        $items = $stmt->fetchAll();

        // The count query shares the WHERE clause but must not carry LIMIT/OFFSET.
        $countStmt = $this->db->getPdo()->prepare(
            'SELECT COUNT(*) FROM jobs WHERE ' . implode(' AND ', $where)
        );
        $countStmt->execute($params);

        return Responder::ok($response, [
            'items'  => $items,
            'total'  => (int) $countStmt->fetchColumn(),
            'limit'  => $limit,
            'offset' => $offset,
        ]);
    }

    /** Queue statistics for the dashboard. */
    public function stats(ServerRequestInterface $request): ResponseInterface
    {
        return Responder::ok(
            $request->getAttribute('response') ?? new \Slim\Psr7\Response(),
            $this->queue->stats()
        );
    }

    /**
     * Enqueue a job by type.
     *
     * The type is validated against Worker::JOB_TYPES — the same list the worker
     * registers handlers from. Without this check an unroutable type sits in the
     * queue only to be dead-lettered by the worker after a pointless claim.
     */
    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $response = $request->getAttribute('response') ?? new \Slim\Psr7\Response();
        $data     = $request->getParsedBody() ?? [];

        $type = trim((string) ($data['type'] ?? ''));
        if ($type === '') {
            return Responder::error($response, 400, 'type is required');
        }
        if (!Worker::isKnownType($type)) {
            return Responder::error(
                $response,
                422,
                "Unknown job type \"$type\". Known types: " . implode(', ', Worker::JOB_TYPES)
            );
        }

        $payload = $data['payload'] ?? [];
        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            $payload = is_array($decoded) ? $decoded : ['value' => $payload];
        }

        $opts = [
            // Lower priority runs first (the column comment says so); the column
            // default is 100, so an omitted priority must not become 0 and jump
            // the whole queue.
            'priority'     => array_key_exists('priority', $data) ? (int) $data['priority'] : 100,
            'max_attempts' => max(1, min((int) ($data['max_attempts'] ?? 3), 10)),
            'delay'        => max(0, (int) ($data['delay'] ?? 0)),
        ];

        // An idempotency key makes a retried POST safe instead of double-queuing.
        if (!empty($data['idempotency_key'])) {
            $opts['idempotency_key'] = (string) $data['idempotency_key'];
        }
        if (!empty($data['created_by'])) {
            $opts['created_by'] = (int) $data['created_by'];
        }

        try {
            $id = $this->queue->enqueue($type, (array) $payload, $opts);
        } catch (\InvalidArgumentException $e) {
            return Responder::error($response, 400, $e->getMessage());
        }

        // Responder::ok() takes no status argument — 201 goes through json().
        return Responder::json($response, ['id' => $id, 'type' => $type], 201);
    }

    /** A single job plus its log lines. */
    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $response = $request->getAttribute('response') ?? new \Slim\Psr7\Response();
        $job = $this->queue->find((int) $request->getAttribute('id'));

        if ($job === null) {
            return Responder::notFound($response, 'Job');
        }

        $job['logs'] = $this->queue->logs((int) $job['id']);
        return Responder::ok($response, ['job' => $job]);
    }

    /**
     * Cancel a job that has not finished yet.
     *
     * Only queued/running work can be cancelled; a completed job's record is
     * the audit trail and must not be rewritten.
     */
    public function cancel(ServerRequestInterface $request): ResponseInterface
    {
        $response = $request->getAttribute('response') ?? new \Slim\Psr7\Response();
        $id = (int) $request->getAttribute('id');

        $job = $this->queue->find($id);
        if ($job === null) {
            return Responder::notFound($response, 'Job');
        }
        if (in_array($job['status'], ['succeeded', 'dead', 'cancelled'], true)) {
            return Responder::error($response, 409, "Job is already {$job['status']}");
        }

        $this->db->getPdo()->prepare(
            "UPDATE jobs SET status = 'cancelled', finished_at = datetime('now') WHERE id = ?"
        )->execute([$id]);

        return Responder::ok($response, ['id' => $id, 'status' => 'cancelled']);
    }

    /**
     * Retry a dead or failed job.
     *
     * run_after is reset to now() rather than NULL — the column is NOT NULL, so
     * clearing it throws. The timestamp is written by SQLite's own clock so a
     * worker in any timezone agrees with the database.
     */
    public function retry(ServerRequestInterface $request): ResponseInterface
    {
        $response = $request->getAttribute('response') ?? new \Slim\Psr7\Response();
        $id = (int) $request->getAttribute('id');

        $job = $this->queue->find($id);
        if ($job === null) {
            return Responder::notFound($response, 'Job');
        }
        if (!in_array($job['status'], ['failed', 'dead'], true)) {
            return Responder::error($response, 409, "Job is {$job['status']}, not retryable");
        }

        $this->db->getPdo()->prepare(
            "UPDATE jobs
             SET status = 'queued', attempts = 0, last_error = NULL,
                 locked_at = NULL, locked_by = NULL,
                 run_after = datetime('now')
             WHERE id = ?"
        )->execute([$id]);

        return Responder::ok($response, ['id' => $id, 'status' => 'queued']);
    }
}