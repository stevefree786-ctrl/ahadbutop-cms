<?php
/**
 * End-to-end tests for the crons/ CLI entry points.
 *
 * These do NOT require the scripts. Each cron runs as a real subprocess via
 * PHP_BINARY and is asserted on exit code and stdout, because the failure
 * mode that matters for a cron is the one an in-process call hides: a
 * bootstrap that fatals on a blank env, an exit code a scheduler reads as
 * failure, or a --dry-run that quietly writes anyway.
 *
 * ISOLATION. config/database.php resolves the sqlite path through
 * env('DB_DATABASE'), and Dotenv's createImmutable() never overwrites a
 * variable already set in the environment — so passing DB_DATABASE to the
 * subprocess gives the child its own database and leaves the live one
 * untouched. Verified below, not assumed.
 *
 * CLEANUP. Every job these crons create carries a key under DAILY_PREFIX or
 * JOB_PREFIX, and both are swept at the end. queue_test.php asserts the suite
 * leaves no queued/running jobs behind, so a leak here fails an unrelated
 * test.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';

if (is_file(base_path('.env'))) {
    Dotenv\Dotenv::createImmutable(base_path())->safeLoad();
}

use CMS\Database\Connection;

const DAILY_PREFIX = 'daily-blog:';
const JOB_PREFIX   = 'seo-audit:post:';

$pass = 0;
$fail = 0;

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
 * Delete every job under $prefix, its logs, and the cron_runs rows this test
 * created.
 *
 * job_logs is ON DELETE CASCADE, but foreign_keys is a per-connection pragma
 * and this test opens its own connection — so deleting explicitly means the
 * cleanup is correct either way.
 */
function cleanup(Connection $db, string $prefix): void
{
    $db->query(
        'DELETE FROM job_logs WHERE job_id IN (SELECT id FROM jobs WHERE idempotency_key LIKE ?)',
        [$prefix . '%']
    );
    $db->query('DELETE FROM jobs WHERE idempotency_key LIKE ?', [$prefix . '%']);
    $db->query("DELETE FROM cron_runs WHERE cron_key IN ('daily_blog', 'seo_crawler')");
}

/**
 * Run a cron entry point in its own process against $dbPath.
 *
 * DB_DATABASE goes into proc_open's $env array rather than a `VAR=value cmd`
 * prefix: proc_open routes a string command through cmd.exe on Windows, which
 * does not accept that prefix form. An explicit env entry is set before PHP
 * starts, so Dotenv's immutable loader leaves it alone and config/database.php
 * resolves to the sandbox.
 *
 * @return array{code:int, stdout:string, stderr:string}
 */
function runCron(string $script, array $args = [], ?string $dbPath = null): array
{
    $cmd = escapeshellarg(PHP_BINARY)
         . ' ' . escapeshellarg(base_path('crons/' . $script));

    foreach ($args as $arg) {
        $cmd .= ' ' . escapeshellarg($arg);
    }

    // PATH/SystemRoot are not inherited automatically when $env is supplied,
    // and PHP needs at least the system vars to start on Windows.
    $env = $dbPath === null ? null : getenv() + ['DB_DATABASE' => $dbPath];

    $pipes   = [];
    $process = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $env);

    if (!is_resource($process)) {
        return ['code' => -1, 'stdout' => '', 'stderr' => 'proc_open failed'];
    }

    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['code' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
}

/**
 * Split a .sql file into statements.
 *
 * Identical to database/migrate.php's splitter — that file runs a command
 * switch on include, so its functions cannot be reused by requiring it. A
 * naive explode on ';' would corrupt statements whose CHECK bodies contain
 * semicolons, and would choke on the exported DDL's line comments.
 */
function splitSqlStatements(string $sql): array
{
    $out       = [];
    $buf       = '';
    $depth     = 0;
    $inString  = false;
    $len       = strlen($sql);

    for ($i = 0; $i < $len; $i++) {
        $ch   = $sql[$i];
        $next = $sql[$i + 1] ?? '';

        if ($inString) {
            $buf .= $ch;
            if ($ch === "'" && $next === "'") {
                $buf .= $next;
                $i++;
            } elseif ($ch === "'") {
                $inString = false;
            }
            continue;
        }

        if ($ch === "'") {
            $inString = true;
            $buf .= $ch;
            continue;
        }
        if ($ch === '-' && $next === '-') {
            while ($i < $len && $sql[$i] !== "\n") {
                $i++;
            }
            $buf .= "\n";
            continue;
        }
        if ($ch === '(') {
            $depth++;
        }
        if ($ch === ')') {
            $depth--;
        }

        if ($ch === ';' && $depth === 0) {
            $stmt = trim($buf);
            if ($stmt !== '') {
                $out[] = $stmt;
            }
            $buf = '';
            continue;
        }
        $buf .= $ch;
    }

    $stmt = trim($buf);
    if ($stmt !== '') {
        $out[] = $stmt;
    }

    return $out;
}

/**
 * Count rows on a database.
 *
 * Opens a fresh connection on purpose. Connection::getPdo() is memoised, so
 * a long-lived handle can serve a WAL read snapshot that predates the cron
 * subprocesses and make a job that really was enqueued look absent — this is
 * what turns a green run into a false failure.
 */
function countJobs(?Connection $db = null, string $sql = 'SELECT COUNT(*) FROM jobs'): int
{
    if ($db === null) {
        $db = new Connection(require base_path('config/database.php'));
    }
    return (int) $db->query($sql)->fetchColumn();
}

/**
 * Read the sandbox through a fresh connection — see countJobs() for why.
 *
 * @param array<int,mixed> $params
 */
/**
 * @return array{0: ?PDO, 1: ?PDOStatement} statement, and the handle keeping it alive
 */
function sandboxStatement(string $sql, array $params = []): array
{
    static $pdo = null;
    if ($pdo === null) {
        $pdo = (new Connection([
            'default'     => 'sqlite',
            'connections' => [
                'sqlite' => ['driver' => 'sqlite', 'database' => (string) getenv('CRON_TEST_SANDBOX')],
            ],
        ]))->getPdo();
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    return [$stmt, $pdo];
}

function sandboxQuery(string $sql, array $params = []): PDOStatement
{
    return sandboxStatement($sql, $params)[0];
}

/**
 * Run a write against the sandbox, attributing any failure to its intent.
 *
 * @param array<int,mixed> $params
 */
function write(PDO $pdo, string $sql, array $params = [], string $label = ''): void
{
    try {
        $pdo->prepare($sql)->execute($params);
    } catch (\PDOException $e) {
        check('sandbox write: ' . ($label !== '' ? $label : $sql), false, $e->getMessage());
    }
}

/** Build a throwaway database from the authoritative schema.sql. */
function buildSandbox(string $path): PDO
{
    foreach ([$path, $path . '-wal', $path . '-shm'] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }

    $pdo = new PDO('sqlite:' . $path, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // WAL + busy_timeout so this handle can read the sandbox while a cron
    // subprocess is mid-write. Without WAL a reader blocks the writer's commit
    // and the cron dies with "database is locked" — a test harness failure that
    // looks like a production bug.
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $pdo->exec('PRAGMA foreign_keys = ON');

    foreach (splitSqlStatements((string) file_get_contents(base_path('database/schema.sql'))) as $sql) {
        $pdo->exec($sql);
    }

    return $pdo;
}

// --------------------------------------------------------------------- set up

$db = new Connection(require $root . '/config/database.php');

// Leftovers from an earlier aborted run would skew the idempotency counts.
cleanup($db, DAILY_PREFIX);
cleanup($db, JOB_PREFIX);

$liveDbPath   = (string) (require $root . '/config/database.php')['connections']['sqlite']['database'];
$sandbox      = $root . '/storage/cron_test_' . getmypid() . '.db';
$sandboxFiles = [$sandbox, $sandbox . '-wal', $sandbox . '-shm'];

$spdo = buildSandbox($sandbox);

// sandboxQuery() reads this back through a fresh connection each time.
putenv('CRON_TEST_SANDBOX=' . $sandbox);

$liveJobsBefore = countJobs($db);

echo "\nCron entry points\n";

// --- isolation -------------------------------------------------------------

$r = runCron('daily-blog.php', ['--dry-run'], $sandbox);
check('a cron runs against the sandbox DB', $r['code'] === 0, $r['code'] . ' ' . $r['stderr']);
check(
    'the sandbox is a different file from the live DB',
    realpath((string) $spdo->query('PRAGMA database_list')->fetch()['file']) !== realpath($liveDbPath),
    "sandbox={$sandbox} live={$liveDbPath}"
);
check(
    'the live DB gained no job',
    countJobs($db) === $liveJobsBefore,
    'the child wrote to the live database'
);

// Seed content: two published posts (in scope), a draft and a page (out).
// origin is bound as 'manual' because posts.origin CHECKs against
// ('manual','ai','ai_edited') — not 'draft', which is a status value.
foreach ([
    ['cron-fixture-alpha', 'post', 'Alpha', 'A description long enough to count as a meta description.'],
    ['cron-fixture-beta',  'post', 'Beta',  'Another description, also of a workable length.'],
    ['cron-fixture-draft', 'post', 'Draft', 'Never published, so never audited.'],
    ['cron-fixture-page',  'page', 'Page',  'A page; excluded unless --include=page is passed.'],
] as [$slug, $type, $title, $excerpt]) {
    write(
        $spdo,
        "INSERT INTO posts (uuid, slug, type, title, excerpt, body_md, status, origin,
                            created_at, updated_at, published_at)
         VALUES (?, ?, ?, ?, ?, '## Heading', 'draft', 'manual',
                 datetime('now'), datetime('now'), datetime('now'))",
        [bin2hex(random_bytes(8)), $slug, $type, $title, $excerpt],
        'seed post ' . $slug
    );
}

write(
    $spdo,
    "UPDATE posts SET status = 'published' WHERE slug IN ('cron-fixture-alpha', 'cron-fixture-beta')",
    [],
    'publish the two fixture posts'
);

$postId = static fn(string $slug): int => (int) $spdo
    ->query("SELECT id FROM posts WHERE slug = " . $spdo->quote($slug))
    ->fetchColumn();

$alphaId = $postId('cron-fixture-alpha');
$betaId  = $postId('cron-fixture-beta');
$draftId = $postId('cron-fixture-draft');
$pageId  = $postId('cron-fixture-page');

check('sandbox seeded two published posts', $alphaId > 0 && $betaId > 0, "{$alphaId}/{$betaId}");

// --- daily-blog ------------------------------------------------------------

echo "\n  daily-blog\n";

$r = runCron('daily-blog.php', ['--help'], $sandbox);
check('--help exits 0', $r['code'] === 0, (string) $r['code']);
check('--help prints usage', str_contains($r['stdout'], '--dry-run'), $r['stdout']);

$r = runCron('daily-blog.php', ['--not-a-flag'], $sandbox);
check('an unknown flag exits non-zero', $r['code'] !== 0, (string) $r['code']);
check('an unknown flag names the option', str_contains($r['stderr'], 'not-a-flag'), $r['stderr']);

$r = runCron('daily-blog.php', ['--date=not-a-date'], $sandbox);
check('a malformed --date exits non-zero', $r['code'] !== 0, (string) $r['code']);

$r = runCron('daily-blog.php', ['stray-positional'], $sandbox);
check('a stray positional argument exits non-zero', $r['code'] !== 0, (string) $r['code']);

$r = runCron('daily-blog.php', ['--dry-run', '--date=2026-03-05'], $sandbox);
check('--dry-run exits 0', $r['code'] === 0, $r['code'] . ' ' . $r['stderr']);
check('--dry-run reports the day', str_contains($r['stdout'], '2026-03-05'), $r['stdout']);
check('--dry-run shows the idempotency key', str_contains($r['stdout'], 'daily-blog:2026-03-05'), $r['stdout']);
check('--dry-run says it wrote nothing', str_contains($r['stdout'], 'nothing was written'), $r['stdout']);
check(
    '--dry-run enqueued nothing',
    (int) sandboxQuery('SELECT COUNT(*) FROM jobs')->fetchColumn() === 0
);
check(
    '--dry-run claimed no cron slot',
    (int) sandboxQuery("SELECT COUNT(*) FROM cron_runs WHERE cron_key = 'daily_blog'")->fetchColumn() === 0
);

// Real run, twice. Idempotency is the entire point of this cron.
$r1 = runCron('daily-blog.php', ['--date=2026-03-05'], $sandbox);
$r2 = runCron('daily-blog.php', ['--date=2026-03-05'], $sandbox);

check('the first run exits 0', $r1['code'] === 0, $r1['code'] . ' ' . $r1['stderr']);
check('the second run exits 0', $r2['code'] === 0, $r2['code'] . ' ' . $r2['stderr']);

$firstJob = sandboxQuery(
    'SELECT id, type, status FROM jobs WHERE idempotency_key = ?',
    ['daily-blog:2026-03-05']
)->fetch();

check('the day produced a job', is_array($firstJob), json_encode($firstJob));
check('the job is generate_daily_post', ($firstJob['type'] ?? '') === 'generate_daily_post', json_encode($firstJob));
check('the job is queued', ($firstJob['status'] ?? '') === 'queued', json_encode($firstJob));
check(
    'running the cron twice still leaves one job',
    (int) sandboxQuery("SELECT COUNT(*) FROM jobs WHERE type = 'generate_daily_post'")->fetchColumn() === 1
);
check(
    'the cron claimed its slot exactly once',
    (int) sandboxQuery("SELECT COUNT(*) FROM cron_runs WHERE cron_key = 'daily_blog'")->fetchColumn() === 1,
    json_encode(sandboxQuery('SELECT cron_key, scheduled_for, status FROM cron_runs')->fetchAll())
);

// A different day is a different key, so it is a different job.
$r3 = runCron('daily-blog.php', ['--date=2026-03-06'], $sandbox);
check('a different --date exits 0', $r3['code'] === 0, $r3['code'] . ' ' . $r3['stderr']);
check(
    'a different --date enqueues a second job',
    (int) sandboxQuery("SELECT COUNT(*) FROM jobs WHERE type = 'generate_daily_post'")->fetchColumn() === 2,
    json_encode(sandboxQuery('SELECT id, idempotency_key FROM jobs')->fetchAll())
);

// --force replaces the day's job rather than resolving to the existing one.
$r4 = runCron('daily-blog.php', ['--date=2026-03-05', '--force'], $sandbox);

check('--force exits 0', $r4['code'] === 0, $r4['code'] . ' ' . $r4['stderr']);
check(
    '--force replaced the job instead of duplicating it',
    is_array($firstJob)
        && (int) sandboxQuery("SELECT COUNT(*) FROM jobs WHERE idempotency_key = 'daily-blog:2026-03-05'")->fetchColumn() === 1
        && (int) sandboxQuery("SELECT id FROM jobs WHERE idempotency_key = 'daily-blog:2026-03-05'")->fetchColumn() > (int) ($firstJob['id'] ?? 0),
    sprintf('force said: %s', trim($r4['stdout']))
);

// A post already attributed to today stops the cron; that is success, not error.
// The day comes from SQLite's clock, not PHP's: the cron compares against
// published_at, which it writes with datetime('now').
write(
    $spdo,
    "UPDATE posts SET origin = 'ai', published_at = datetime('now') WHERE id = ?",
    [$alphaId],
    'mark today\'s post as already generated'
);

$today = (string) $spdo->query("SELECT date('now')")->fetchColumn();
$r5    = runCron('daily-blog.php', ['--date=' . $today], $sandbox);
check("today's slot is already spoken for -> exit 0", $r5['code'] === 0, $r5['code'] . ' ' . $r5['stderr']);
check('...and it says there is nothing to do', str_contains($r5['stdout'], 'nothing to do'), $r5['stdout']);

// --- seo-crawler -----------------------------------------------------------

echo "\n  seo-crawler\n";

$r = runCron('seo-crawler.php', ['--help'], $sandbox);
check('--help exits 0', $r['code'] === 0, (string) $r['code']);
check('--help prints usage', str_contains($r['stdout'], '--limit'), $r['stdout']);

$r = runCron('seo-crawler.php', ['--limit=notanint'], $sandbox);
check('a non-integer --limit exits non-zero', $r['code'] !== 0, (string) $r['code']);

$r = runCron('seo-crawler.php', ['--limit'], $sandbox);
check('--limit with no value exits non-zero', $r['code'] !== 0, (string) $r['code']);

$r = runCron('seo-crawler.php', ['--include=bogus'], $sandbox);
check('an invalid --include exits non-zero', $r['code'] !== 0, (string) $r['code']);

$r = runCron('seo-crawler.php', ['--role=wizard'], $sandbox);
check('an invalid --role exits non-zero', $r['code'] !== 0, (string) $r['code']);

$jobsBeforeDryRun = (int) sandboxQuery('SELECT COUNT(*) FROM jobs')->fetchColumn();

$r = runCron('seo-crawler.php', ['--dry-run'], $sandbox);
check('--dry-run exits 0', $r['code'] === 0, $r['code'] . ' ' . $r['stderr']);
check('--dry-run says what it would enqueue', str_contains($r['stdout'], 'would enqueue'), $r['stdout']);
check('--dry-run says it wrote nothing', str_contains($r['stdout'], 'nothing was written'), $r['stdout']);
check(
    '--dry-run enqueued nothing',
    (int) sandboxQuery('SELECT COUNT(*) FROM jobs')->fetchColumn() === $jobsBeforeDryRun
);

$r = runCron('seo-crawler.php', [], $sandbox);
check('a real run exits 0', $r['code'] === 0, $r['code'] . ' ' . $r['stderr']);

$AUDIT_JOBS = "SELECT id, type, status, idempotency_key, payload
                 FROM jobs
                WHERE idempotency_key LIKE 'seo-audit:post:%'";

$auditJobs = sandboxQuery($AUDIT_JOBS)->fetchAll();

check('one seo.audit job per published post', count($auditJobs) === 2, json_encode($auditJobs));
check(
    'every audit job is typed seo.audit',
    array_reduce($auditJobs, static fn(bool $ok, array $j): bool => $ok && $j['type'] === 'seo.audit', true),
    json_encode($auditJobs)
);

$keys = array_column($auditJobs, 'idempotency_key');
check('each post has a distinct key', count(array_unique($keys)) === count($auditJobs) && count($keys) === 2, json_encode($keys));

$startsWith = static fn(int $id): bool => (bool) array_filter(
    $keys,
    static fn(string $k): bool => str_starts_with($k, "seo-audit:post:{$id}:")
);
check("alpha's key names the post", $startsWith($alphaId), json_encode($keys));
check("beta's key names the post", $startsWith($betaId), json_encode($keys));

$payload = json_decode((string) ($auditJobs[0]['payload'] ?? '{}'), true);
check(
    'the payload carries the post id',
    is_array($payload) && in_array((int) ($payload['post_id'] ?? 0), [$alphaId, $betaId], true),
    (string) ($auditJobs[0]['payload'] ?? '')
);
check(
    'the payload carries the acting role',
    is_array($payload) && ($payload['user_role'] ?? '') === 'editor',
    (string) ($auditJobs[0]['payload'] ?? '')
);

// Re-running finds nothing new: the keys are content-derived and unchanged,
// so every post is already queued and the enqueue is a no-op.
$r = runCron('seo-crawler.php', [], $sandbox);
check('a second run exits 0', $r['code'] === 0, $r['code'] . ' ' . $r['stderr']);
check('a second run reports nothing new enqueued', str_contains($r['stdout'], '0 post(s) audited-enqueued'), $r['stdout']);
check(
    'a second run enqueued no new jobs',
    count(sandboxQuery($AUDIT_JOBS)->fetchAll()) === 2,
    're-running produced duplicate audit jobs'
);

// Scope: the draft was never a candidate.
check(
    'the unpublished post was not audited',
    (int) sandboxQuery("SELECT COUNT(*) FROM jobs WHERE idempotency_key LIKE 'seo-audit:post:{$draftId}:%'")->fetchColumn() === 0,
    'the draft was audited'
);
check(
    'the page was not audited without --include',
    (int) sandboxQuery("SELECT COUNT(*) FROM jobs WHERE idempotency_key LIKE 'seo-audit:post:{$pageId}:%'")->fetchColumn() === 0,
    'the page was audited'
);

// An audit older than the post is stale, so the post returns to the queue.
write(
    $spdo,
    "INSERT INTO seo_meta (entity_type, entity_id, score, checked_at, updated_at)
     VALUES ('post', ?, 42, '2001-01-01 00:00:00', '2001-01-01 00:00:00')",
    [(string) $alphaId],
    'record a stale seo_meta for alpha'
);

$r = runCron('seo-crawler.php', [], $sandbox);
check(
    'a stale audit puts the post back in the queue',
    $r['code'] === 0
        && (int) sandboxQuery("SELECT COUNT(*) FROM jobs WHERE idempotency_key LIKE 'seo-audit:post:{$alphaId}:%'")->fetchColumn() === 1,
    $r['stdout']
);

// A current audit is not re-run, but --force is.
write(
    $spdo,
    "UPDATE seo_meta SET checked_at = datetime('now') WHERE entity_type = 'post' AND entity_id = ?",
    [(string) $alphaId],
    'mark alpha\'s audit current'
);

$r = runCron('seo-crawler.php', ['--force'], $sandbox);
check('--force exits 0', $r['code'] === 0, $r['code'] . ' ' . $r['stderr']);
// A content-derived key means the forced job REPLACES the queued one rather
// than sitting beside it — the count holding at 1 IS the evidence that
// --force did something; a second key would mean the flag was ignored, and a
// "0 enqueued" line would mean the delete+enqueue pair was missing.
check(
    '--force replaces the queued audit instead of ignoring the flag',
    $r['code'] === 0
        && (int) sandboxQuery("SELECT COUNT(*) FROM jobs WHERE idempotency_key LIKE 'seo-audit:post:{$alphaId}:%'")->fetchColumn() === 1
        && str_contains($r['stdout'], '2 post(s) audited-enqueued, 0 already queued'),
    $r['stdout']
);

// --limit caps the batch.
write($spdo, "UPDATE seo_meta SET checked_at = '2001-01-01 00:00:00'", [], 'make every audit stale again');

$r = runCron('seo-crawler.php', ['--dry-run', '--limit=1'], $sandbox);
check('--dry-run --limit=1 reports the cap', str_contains($r['stdout'], 'limited to --limit=1'), $r['stdout']);

$before = (int) sandboxQuery('SELECT COUNT(*) FROM jobs')->fetchColumn();
$r      = runCron('seo-crawler.php', ['--limit=1'], $sandbox);
$after  = (int) sandboxQuery('SELECT COUNT(*) FROM jobs')->fetchColumn();

check('--limit=1 exits 0', $r['code'] === 0, $r['code'] . ' ' . $r['stderr']);
check('--limit=1 enqueues at most one job', ($after - $before) <= 1, "delta " . ($after - $before));

// --include=page widens the scope to pages.
write($spdo, 'UPDATE posts SET status = ? WHERE id = ?', ['published', $pageId], 'publish the fixture page');
$r = runCron('seo-crawler.php', ['--include=page'], $sandbox);
check('--include=page exits 0', $r['code'] === 0, $r['code'] . ' ' . $r['stderr']);
check(
    '--include=page audits the page',
    (int) sandboxQuery("SELECT COUNT(*) FROM jobs WHERE idempotency_key LIKE 'seo-audit:post:{$pageId}:%'")->fetchColumn() >= 1,
    $r['stdout']
);

// Every post current -> a clean exit 0, not an error.
// cron_runs first: its job_id references jobs(id), so deleting the jobs first
// trips the foreign key (the sandbox runs with PRAGMA foreign_keys = ON).
write($spdo, 'DELETE FROM job_logs', [], 'clear job logs');
write($spdo, 'DELETE FROM cron_runs', [], 'clear cron runs');
write($spdo, 'DELETE FROM jobs', [], 'clear jobs');
write($spdo, 'DELETE FROM seo_meta', [], 'clear seo_meta');

// Run through the temporary handle so nothing survives into the teardown: on
// Windows a live handle keeps the WAL/SHM files locked and they cannot be
// removed.
[$publishedStmt, $publishedPdo] = sandboxStatement("SELECT id FROM posts WHERE status = 'published'");
$publishedIds = $publishedStmt->fetchAll(PDO::FETCH_COLUMN);
unset($publishedStmt, $publishedPdo);
foreach ($publishedIds as $id) {
    write(
        $spdo,
        "INSERT INTO seo_meta (entity_type, entity_id, score, checked_at, updated_at)
         VALUES ('post', ?, 10, '2999-01-01 00:00:00', '2999-01-01 00:00:00')",
        [(string) $id],
        'mark post ' . $id . ' as freshly audited'
    );
}

$r = runCron('seo-crawler.php', [], $sandbox);
check('nothing to do exits 0', $r['code'] === 0, $r['code'] . ' ' . $r['stderr']);
check('nothing to do says so', str_contains($r['stdout'], 'nothing to do'), $r['stdout']);
check(
    'nothing to do enqueues nothing',
    (int) sandboxQuery('SELECT COUNT(*) FROM jobs')->fetchColumn() === 0,
    json_encode(sandboxQuery("SELECT id, idempotency_key FROM jobs")->fetchAll())
);

// --- the live DB was never touched ----------------------------------------

check(
    'the live DB gained no job',
    countJobs($db) === $liveJobsBefore,
    'the crons wrote to the live database'
);

// --- cleanup ---------------------------------------------------------------

cleanup($db, DAILY_PREFIX);
cleanup($db, JOB_PREFIX);

$leaked = countJobs($db, "SELECT COUNT(*) FROM jobs
                            WHERE idempotency_key LIKE 'daily-blog:%'
                               OR idempotency_key LIKE 'seo-audit:%'");
check('cleanup left no test job behind', $leaked === 0, "({$leaked} left)");

// queue_test.php asserts this for the whole suite; a cron test that leaves a
// queued job would fail it, so assert it here too.
check(
    'no queued/running jobs left behind',
    countJobs($db, "SELECT COUNT(*) FROM jobs WHERE status IN ('queued', 'running')") === 0
);

// Removing the sandbox is best-effort cleanup: every assertion that matters
// has already run against its contents by now. Windows keeps a WAL-mode
// sqlite file locked while any handle is open, and runCron()'s retained
// process handles stay open until this script exits — so a leftover file is
// expected, not a failure. Report it rather than emitting an unlink warning
// or failing a green suite over it.
$leftBehind = [];
foreach ($sandboxFiles as $file) {
    if (is_file($file) && !@unlink($file)) {
        $leftBehind[] = basename($file);
    }
}

if ($leftBehind !== []) {
    echo '  note: sandbox file(s) still locked at exit: ' . implode(', ', $leftBehind) . "\n";
}

echo "\n  {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
