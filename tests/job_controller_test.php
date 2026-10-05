<?php
/**
 * End-to-end tests for JobQueueController.
 *
 * These drive the controller's PSR-15 methods with real ServerRequest objects
 * against the real database. An earlier smoke test exercised only JobQueue and
 * passed while the controller itself referenced a column (`queue`) that does
 * not exist — so the point of this file is to exercise the SQL the controller
 * actually writes, not the layer beneath it.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';

if (is_file(base_path('.env'))) {
    Dotenv\Dotenv::createImmutable(base_path())->safeLoad();
}

use CMS\Database\Connection;
use CMS\Queue\JobQueue;
use CMS\Queue\Worker;
use CMS\Api\Controllers\JobQueueController;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\ResponseFactory;

$db         = new Connection(require $root . '/config/database.php');
$controller = new JobQueueController($db);
$reqFactory = new ServerRequestFactory();
$resFactory = new ResponseFactory();

$pass = 0;
$fail = 0;

/**
 * Every job this test creates, deleted at the end.
 *
 * Not cosmetic: queue_test.php asserts the suite leaves no queued/running jobs
 * behind, so a leak here fails an unrelated test. The idempotency check creates
 * a job beyond the two tracked by id, so ids alone are not enough — prefix the
 * keys and sweep on it.
 */
$created = [];
$KEY_PREFIX = 'ctrl-test-';

function cleanup(Connection $db, string $prefix): void
{
    $ids = $db->query(
        "SELECT id FROM jobs WHERE idempotency_key LIKE ?",
        [$prefix . '%']
    )->fetchAll(PDO::FETCH_COLUMN);

    foreach ($ids as $id) {
        $db->query('DELETE FROM job_logs WHERE job_id = ?', [$id]);
    }
    $db->query('DELETE FROM jobs WHERE idempotency_key LIKE ?', [$prefix . '%']);
}

function check(string $label, bool $ok, string $context = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  ok   $label\n";
        return;
    }
    $fail++;
    echo "  FAIL $label\n";
    if ($context !== '') {
        echo '       -> ' . str_replace("\n", ' ', substr($context, 0, 300)) . "\n";
    }
}

/**
 * Invoke a controller method and return [status, decodedBody].
 *
 * @param array $query  query params
 * @param array $body   parsed body
 * @param array $attrs  route attributes (id, etc.)
 */
function call(
    JobQueueController $c,
    ServerRequestFactory $rq,
    ResponseFactory $resF,
    string $method,
    array $query = [],
    array $body = [],
    array $attrs = []
): array {
    $request = $rq->createServerRequest('GET', '/api/jobs')
        ->withQueryParams($query)
        ->withAttribute('response', $resF->createResponse());

    foreach ($attrs as $k => $v) {
        $request = $request->withAttribute($k, $v);
    }
    if ($body !== []) {
        $request = $request->withParsedBody($body);
    }

    $response = $c->$method($request);
    $response->getBody()->rewind();
    $decoded = json_decode((string) $response->getBody(), true);

    return [$response->getStatusCode(), $decoded];
}

echo "\nJobQueueController\n";

// --- index -----------------------------------------------------------------
[$status, $body] = call($controller, $reqFactory, $resFactory, 'index');
check('index returns 200', $status === 200, (string) $status);
check('index has items + total', isset($body['items'], $body['total']), json_encode($body));

// The regression: `queue` is not a jobs column. Any filter referencing it
// produced "no such column: queue" and a 500.
[$status, ] = call($controller, $reqFactory, $resFactory, 'index', ['queue' => 'default']);
check('index ignores unknown queue param instead of erroring', $status === 200, (string) $status);

[$status, $body] = call($controller, $reqFactory, $resFactory, 'index', ['status' => 'queued']);
check('index filters by status', $status === 200 && is_array($body['items']), json_encode($body));

$statusRowsMatch = true;
foreach (($body['items'] ?? []) as $row) {
    if (($row['status'] ?? '') !== 'queued') {
        $statusRowsMatch = false;
        break;
    }
}
check('every returned row matches the status filter', $statusRowsMatch, json_encode($body));

[$status, ] = call($controller, $reqFactory, $resFactory, 'index', ['type' => 'seo.audit']);
check('index filters by type', $status === 200, (string) $status);

[$status, $body] = call($controller, $reqFactory, $resFactory, 'index', ['limit' => '99999']);
check('limit is clamped to 200', $status === 200 && $body['limit'] === 200, json_encode($body));

[$status, $body] = call($controller, $reqFactory, $resFactory, 'index', ['offset' => '-5']);
check('negative offset floors at 0', $status === 200 && $body['offset'] === 0, json_encode($body));

[$status, $body] = call($controller, $reqFactory, $resFactory, 'index', ['status' => 'nonsense']);
check('unknown status is rejected, not silently unfiltered', $status === 422, $status . ' ' . json_encode($body));
check('the rejection names the valid statuses', str_contains(json_encode($body), 'queued'), json_encode($body));

// --- create ----------------------------------------------------------------
[$status, ] = call($controller, $reqFactory, $resFactory, 'create', [], ['type' => '']);
check('create rejects empty type', $status === 400, (string) $status);

[$status, $body] = call($controller, $reqFactory, $resFactory, 'create', [], ['type' => 'does.not.exist']);
check('create rejects unroutable type with 422', $status === 422, $status . ' ' . json_encode($body));
check('rejection lists the known types', str_contains(json_encode($body), 'seo.audit'), json_encode($body));

// The 201 path: previously Responder::ok($response, $data, 201) passed a third
// argument to a two-parameter method, which is a fatal in PHP 8.
[$status, $body] = call($controller, $reqFactory, $resFactory, 'create', [], [
    'type'            => 'seo.audit',
    'payload'         => ['post_id' => 1],
    'idempotency_key' => $KEY_PREFIX . bin2hex(random_bytes(5)),
]);
check('create returns 201', $status === 201, $status . ' ' . json_encode($body));

$createdId = $body['id'] ?? 0;
check('create returns the job id', is_int($createdId) && $createdId > 0, json_encode($body));

// An omitted priority must stay at the column default. Passing 0 would sort
// the job ahead of everything on the queue (lower runs first).
$job = $db->query('SELECT priority, max_attempts, run_after, status FROM jobs WHERE id = ?', [$createdId])->fetch();
check('omitted priority stays at the column default of 100', (int) ($job['priority'] ?? 0) === 100, json_encode($job));
check('run_after is populated', !empty($job['run_after']), json_encode($job));
check('new job is queued', ($job['status'] ?? '') === 'queued', json_encode($job));

// Repeating the same idempotency key must return the same job, not a second one.
$key = $KEY_PREFIX . 'idem-' . bin2hex(random_bytes(5));
[$s1, $b1] = call($controller, $reqFactory, $resFactory, 'create', [], ['type' => 'seo.audit', 'idempotency_key' => $key]);
[$s2, $b2] = call($controller, $reqFactory, $resFactory, 'create', [], ['type' => 'seo.audit', 'idempotency_key' => $key]);
check('duplicate idempotency key returns the same job', $s1 === 201 && $s2 === 201 && ($b1['id'] ?? 0) === ($b2['id'] ?? -1), json_encode([$b1, $b2]));

[$status, $body] = call($controller, $reqFactory, $resFactory, 'create', [], [
    'type' => 'seo.audit', 'payload' => '{"post_id":2}', 'idempotency_key' => $KEY_PREFIX . 'second-' . bin2hex(random_bytes(5)),
]);
check('a JSON string payload is accepted', $status === 201, $status . ' ' . json_encode($body));
$createdId2 = $body['id'] ?? 0;

// --- show ------------------------------------------------------------------
[$status, $body] = call($controller, $reqFactory, $resFactory, 'show', [], [], ['id' => $createdId]);
check('show returns 200 for a real job', $status === 200, (string) $status);
check('show includes logs', isset($body['job']['logs']), json_encode($body));

[$status, ] = call($controller, $reqFactory, $resFactory, 'show', [], [], ['id' => 99999999]);
check('show 404s a missing job', $status === 404, (string) $status);

// --- cancel ----------------------------------------------------------------
[$status, ] = call($controller, $reqFactory, $resFactory, 'cancel', [], [], ['id' => $createdId]);
check('cancel returns 200', $status === 200, (string) $status);

$after = $db->query('SELECT status, finished_at FROM jobs WHERE id = ?', [$createdId])->fetch();
check('cancel writes cancelled', ($after['status'] ?? '') === 'cancelled', json_encode($after));
check('cancel stamps finished_at', !empty($after['finished_at']), json_encode($after));

// Cancelling twice must conflict rather than silently rewriting the audit trail.
[$status, ] = call($controller, $reqFactory, $resFactory, 'cancel', [], [], ['id' => $createdId]);
check('re-cancel returns 409', $status === 409, (string) $status);

[$status, ] = call($controller, $reqFactory, $resFactory, 'retry', [], [], ['id' => $createdId]);
check('retry refuses a cancelled job', $status === 409, (string) $status);

// --- retry -----------------------------------------------------------------
// Drive one into a failed state, then retry it for real.
$db->query(
    "UPDATE jobs SET status = 'failed', attempts = 2, last_error = 'boom',
            locked_by = 'w1', locked_at = datetime('now')
      WHERE id = ?",
    [$createdId2]
);

[$status, ] = call($controller, $reqFactory, $resFactory, 'retry', [], [], ['id' => $createdId2]);
check('retry returns 200 for a failed job', $status === 200, (string) $status);

$after2 = $db->query(
    'SELECT status, attempts, last_error, locked_by, locked_at, run_after FROM jobs WHERE id = ?',
    [$createdId2]
)->fetch();
check('retry requeues the job', ($after2['status'] ?? '') === 'queued', json_encode($after2));
check('retry resets attempts', (int) ($after2['attempts'] ?? -1) === 0, json_encode($after2));

// `?? 'x'` is wrong here: on a NULL value it yields the default, so a correct
// NULL could never compare equal to NULL. Read the key directly.
check('retry clears last_error', array_key_exists('last_error', $after2) && $after2['last_error'] === null, json_encode($after2));
check('retry clears the worker lock', array_key_exists('locked_by', $after2) && $after2['locked_by'] === null, json_encode($after2));
check('retry clears locked_at', array_key_exists('locked_at', $after2) && $after2['locked_at'] === null, json_encode($after2));
check('retry makes the job immediately runnable', (string) ($after2['run_after'] ?? '') <= gmdate('Y-m-d H:i:s'), json_encode($after2));

[$status, ] = call($controller, $reqFactory, $resFactory, 'retry', [], [], ['id' => 99999999]);
check('retry 404s a missing job', $status === 404, (string) $status);

// --- stats -----------------------------------------------------------------
[$status, $body] = call($controller, $reqFactory, $resFactory, 'stats');
check('stats returns 200', $status === 200, (string) $status);

$allStatuses = true;
foreach (['queued', 'running', 'succeeded', 'failed', 'dead', 'cancelled'] as $s) {
    if (!array_key_exists($s, $body ?? [])) {
        $allStatuses = false;
        break;
    }
}
check('stats reports all six statuses even at zero', $allStatuses, json_encode($body));

// --- Worker registry is the single source ----------------------------------
check('Worker::JOB_TYPES is non-empty', count(Worker::JOB_TYPES) > 0);
check('isKnownType accepts a registered type', Worker::isKnownType('seo.audit'));
check('isKnownType rejects an unknown type', !Worker::isKnownType('nope'));

// Every type the controller accepts must have a handler, or the API queues work
// the worker dead-letters on first dispatch.
$worker   = new Worker(new JobQueue($db));
$allBound = true;
foreach (Worker::JOB_TYPES as $type) {
    if (!$worker->hasHandler($type)) {
        $allBound = false;
        echo "       (no handler for {$type})\n";
        break;
    }
}
check('every JOB_TYPES entry has a worker handler', $allBound);

// --- cleanup ---------------------------------------------------------------
cleanup($db, $KEY_PREFIX);

echo "\n  {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);