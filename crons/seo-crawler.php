<?php
/**
 * SEO crawler cron.
 *
 *   php crons/seo-crawler.php                audit published posts lacking an audit
 *   php crons/seo-crawler.php --dry-run      list what it would enqueue
 *   php crons/seo-crawler.php --limit=10     cap the number of jobs enqueued
 *   php crons/seo-crawler.php --force        audit every published post, even
 *                                            ones already audited
 *   php crons/seo-crawler.php --include=page  also audit type='page' rows
 *   php crons/seo-crawler.php --help
 *
 * composer: `composer cron:seo`
 *
 * "Lacking an audit" means: no seo_meta row, or a seo_meta row whose
 * checked_at predates the post's last content change. seo_meta is keyed
 * (entity_type, entity_id) with entity_id stored as TEXT, so the join casts
 * p.id to TEXT rather than comparing integer to string — SQLite would
 * otherwise never match and every post would look unaudited forever.
 *
 * Per-post idempotency key is `seo-audit:post:{id}:{hash}`, where hash is a
 * digest over the fields that actually change the score. Re-running the cron
 * therefore re-audits an edited post and leaves an untouched one alone,
 * without needing to read the jobs table at all.
 *
 * This script only enqueues. SeoAgent::score() is deterministic and touches no
 * model, but SeoAgent::audit() persists through IntentRegistry::execute(),
 * whose save_seo_meta writer requires the `editor` role — so the acting role
 * travels in the payload (overridable with --role) and the worker supplies it.
 */
declare(strict_types=1);

use CMS\Agents\Context;
use CMS\Database\Connection;
use CMS\Queue\CronCli;
use CMS\Queue\JobQueue;
use CMS\Queue\Worker;

// Same bootstrap as bin/worker.php — see the note there.
require_once __DIR__ . '/../vendor/autoload.php';

if (is_file(base_path('.env'))) {
    Dotenv\Dotenv::createImmutable(base_path())->safeLoad();
}

$SPEC = [
    'dry-run' => 'bool',
    'force'   => 'bool',
    'limit'   => 'int',
    'include' => 'string',
    'role'    => 'string',
];

$usage = <<<'TXT'
SEO crawler cron

  --dry-run          print what would be enqueued and write nothing
  --limit=N          enqueue at most N jobs (default: no cap)
  --force            re-audit every published post, including audited ones
  --include=TYPE     extra posts.type to audit, 'post' or 'page'
                     (default: posts only; repeat not supported)
  --role=ROLE        acting user_role for the worker: author|editor|admin
                     (default: editor — save_seo_meta requires it)
  --help             this message

Enqueues one seo.audit job per published post that has never been audited,
or whose content changed since its last audit. Editing a post re-queues it;
leaving it alone does not.
TXT;

['opts' => $opts, 'error' => $parseError] = CronCli::parse(array_slice($argv, 1), $SPEC);

if ($parseError !== null) {
    CronCli::err('seo-crawler: ' . $parseError);
    CronCli::err('seo-crawler: run with --help for usage');
    exit(CronCli::EXIT_USAGE);
}

if ($opts[CronCli::HELP]) {
    CronCli::out($usage);
    exit(0);
}

try {
    $dryRun  = (bool) $opts['dry-run'];
    $force   = (bool) $opts['force'];
    $limit   = isset($opts['limit']) ? (int) $opts['limit'] : null;

    // Context::normalizeRole() is the canonical gate: anything unrecognised
    // becomes 'author', which is exactly the value that would make
    // save_seo_meta throw. Validate early so the operator finds out here.
    $role = Context::normalizeRole(isset($opts['role']) ? (string) $opts['role'] : 'editor');
    $requestedRole = trim((string) ($opts['role'] ?? 'editor'));
    if (array_key_exists('role', $opts) && $role !== strtolower($requestedRole)) {
        CronCli::err('seo-crawler: invalid --role "' . $requestedRole . '"; expected one of: '
            . implode(', ', array_keys(Context::ROLES)));
        exit(CronCli::EXIT_USAGE);
    }

    // Only posts.type is expandable. 'page' lives in the same table with a
    // different type, so --include is how a page enters scope — not a
    // different query.
    $types   = ['post'];
    $include = trim((string) ($opts['include'] ?? ''));
    if ($include !== '') {
        $include = strtolower($include);
        if (!in_array($include, ['post', 'page'], true)) {
            CronCli::err('seo-crawler: invalid --include "' . $include . '"; expected post or page');
            exit(CronCli::EXIT_USAGE);
        }
        if (!in_array($include, $types, true)) {
            $types[] = $include;
        }
    }

    $typeList = implode(', ', $types);

    if (!Worker::isKnownType('seo.audit')) {
        CronCli::err('seo-crawler: seo.audit is not a registered job type '
            . '(Worker::JOB_TYPES is the source of truth)');
        exit(CronCli::EXIT_FAILURE);
    }

    $conn  = new Connection(require base_path('config/database.php'));
    $queue = new JobQueue($conn);
    $pdo   = $queue->pdo();

    // -------------------------------------------------------------- candidates

    // A post needs (re-)auditing when it has no seo_meta row at all, or when
    // the row exists but checked_at is older than the last content change.
    // updated_at is only bumped by an explicit write (there are no triggers on
    // posts), so it is a usable proxy for "changed since".
    //
    // The type list is bound through placeholders rather than interpolated:
    // a whitelist is a reason to bind, not a reason to skip binding. Nothing
    // derived from data reaches the statement; $typeList is display-only.
    $placeholders = implode(', ', array_fill(0, count($types), '?'));

    $candidates = $pdo->prepare(
        "SELECT p.id, p.slug, p.title, p.type, p.updated_at,
                COALESCE(s.checked_at, '') AS checked_at,
                COALESCE(s.score, -1)     AS score
           FROM posts p
      LEFT JOIN seo_meta s
             ON s.entity_type = 'post'
            AND s.entity_id   = CAST(p.id AS TEXT)
          WHERE p.status = 'published'
            AND p.type IN ({$placeholders})
       ORDER BY COALESCE(p.published_at, p.updated_at, p.created_at) ASC, p.id ASC"
    );
    $candidates->execute($types);
    $candidates = $candidates->fetchAll();

    // Read SQLite's clock, not PHP's. Inside a process that has already been
    // running for a while (the test harness spawns these back to back) a PHP
    // timestamp can straddle the boundary the previous run wrote with
    // datetime('now'), which would make unchanged content look edited.
    $now = (string) $pdo->query("SELECT datetime('now')")->fetchColumn();

    $stale = [];
    foreach ($candidates as $row) {
        $postId = (int) $row['id'];
        if ($force || (string) $row['checked_at'] === '') {
            $stale[] = $row;
            continue;
        }
        if ((string) $row['checked_at'] < (string) $row['updated_at']) {
            $stale[] = $row;
        }
    }

    $capped = false;
    if ($limit !== null && $limit >= 0 && count($stale) > $limit) {
        $stale  = array_slice($stale, 0, $limit);
        $capped = true;
    }

    // Build the work list up front so --dry-run and the real path cannot drift.
    $plan = [];
    foreach ($stale as $row) {
        $postId = (int) $row['id'];
        $key    = seoAuditKey($postId, $row);
        $plan[] = [
            'post_id'    => $postId,
            'slug'       => (string) $row['slug'],
            'title'      => (string) $row['title'],
            'type'       => (string) $row['type'],
            'updated_at' => (string) $row['updated_at'],
            'checked_at' => (string) $row['checked_at'],
            'key'        => $key,
            'payload'    => [
                'post_id'       => $postId,
                'slug'          => (string) $row['slug'],
                'user_role'     => $role,
                'cron_key'      => 'seo_crawler',
                'content_hash'  => substr($key, strrpos($key, ':') + 1),
                'scheduled_for' => $now,
            ],
        ];
    }

    // -------------------------------------------------------------- dry run

    if ($dryRun) {
        CronCli::out('seo-crawler dry run — types: ' . $typeList . ($force ? ' (--force: all published)' : ''));
        CronCli::out(sprintf(
            '  %d published post(s) considered, %d need an audit%s',
            count($candidates),
            count($plan),
            $capped ? ' (limited to --limit=' . (string) $limit . ')' : ''
        ));

        foreach ($plan as $item) {
            CronCli::out(sprintf(
                '  would enqueue: seo.audit post %d "%s"  key=%s',
                $item['post_id'],
                $item['slug'],
                $item['key']
            ));
        }

        if ($plan === []) {
            CronCli::out('  nothing to do — every published post has a current audit');
        }

        CronCli::out('  nothing was written');
        exit(0);
    }

    // ------------------------------------------------------------- no work

    if ($plan === []) {
        CronCli::out(sprintf(
            'seo-crawler: nothing to do — %d published post(s) checked, all have a current audit',
            count($candidates)
        ));
        exit(0);
    }

    // ------------------------------------------------------------- enqueue

    $enqueued = 0;
    $reused   = 0;

    foreach ($plan as $item) {
        // --force means "re-audit this post now". The key is derived from
        // content, so for an unchanged post it is identical to the queued job's
        // and enqueue() would hand back its id — making --force a silent
        // no-op. Clearing the job first is what gives the flag teeth; the
        // plain path below keeps the key and stays idempotent.
        if ($force) {
            $clearLogs = $pdo->prepare('DELETE FROM job_logs WHERE job_id IN (SELECT id FROM jobs WHERE idempotency_key = ?)');
            $clearLogs->execute([$item['key']]);
            $pdo->prepare('DELETE FROM jobs WHERE idempotency_key = ?')->execute([$item['key']]);
        }

        // enqueue() is INSERT OR IGNORE on the UNIQUE idempotency_key: a
        // duplicate returns the existing id rather than throwing, so a second
        // cron tick inside the same second is a no-op, not a double audit.
        $before = $pdo->prepare('SELECT COUNT(*) FROM jobs WHERE idempotency_key = ?');
        $before->execute([$item['key']]);
        $existed = (int) $before->fetchColumn() > 0;

        $queue->enqueue('seo.audit', $item['payload'], [
            'idempotency_key' => $item['key'],
            'priority'        => 200,
            'max_attempts'    => 3,
        ]);

        if ($existed) {
            $reused++;
        } else {
            $enqueued++;
        }
    }

    CronCli::out(sprintf(
        'seo-crawler: %d post(s) audited-enqueued, %d already queued%s',
        $enqueued,
        $reused,
        $capped ? ' (limited to --limit=' . (string) $limit . ')' : ''
    ));

    foreach ($plan as $item) {
        CronCli::out(sprintf('  post %d "%s"  key=%s', $item['post_id'], $item['slug'], $item['key']));
    }

    CronCli::out('  a worker will pick these up: php bin/worker.php');
    exit(0);
} catch (\InvalidArgumentException $e) {
    CronCli::err('seo-crawler: ' . $e->getMessage());
    exit(CronCli::EXIT_USAGE);
} catch (\Throwable $e) {
    CronCli::err('seo-crawler: ' . $e->getMessage());
    exit(CronCli::EXIT_FAILURE);
}

/**
 * Per-post idempotency key: `seo-audit:post:{id}:{hash}`.
 *
 * The hash covers exactly the fields SeoAgent::score() reads, so the key
 * changes if and only if the score could change. Nothing here interpolates
 * user input into SQL — this only ever produces a string bound as a
 * parameter.
 */
function seoAuditKey(int $postId, array $row): string
{
    $material = implode("\x1F", [
        (string) ($row['slug'] ?? ''),
        (string) ($row['title'] ?? ''),
        (string) ($row['updated_at'] ?? ''),
    ]);

    return 'seo-audit:post:' . $postId . ':' . substr(hash('sha256', $material), 0, 16);
}
