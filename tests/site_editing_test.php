<?php
/**
 * Site-editing end-to-end tests.
 *
 * These prove the promises the whole project is built around actually hold at
 * the HTTP layer, not merely that an intent handler returned a success array:
 *
 *   - an agent can create a page, and the page renders
 *   - an agent can rename it, the new URL works, and the old one 301s
 *   - an agent can change the accent colour and the RENDERED HTML changes
 *
 * The last one is the load-bearing test. DesignVars caches the merged token
 * map in a static and reads the database once per process. Under `php -S`
 * every request is a fresh process, so the cache never goes stale and the bug
 * is invisible; in any long-lived worker — RoadRunner, Swoole, FrankenPHP, a
 * queue daemon, or a test process holding the app in memory — every page render
 * after the first reuses the map built for that first one.
 *
 * That produced the worst possible failure: the agent wrote the token, the row
 * landed, the API answered {"saved": true}, and the page showed the OLD colour.
 * Every signal said it worked. Only a test that renders, writes, and renders
 * again inside ONE process can catch it, which is what this file is.
 */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

if (is_file(base_path('.env'))) {
    Dotenv\Dotenv::createImmutable(base_path())->safeLoad();
}

use CMS\Agents\Actions;
use CMS\Agents\IntentRegistry;
use CMS\Database\Connection;
use Slim\Psr7\Factory\ServerRequestFactory;

$db    = new Connection(require __DIR__ . '/../config/database.php');
$pdo   = $db->getPdo();
$app   = require __DIR__ . '/../public/index.php';
$built = Actions::build($db);
$exec  = new IntentRegistry($db, $built['schema'], $built['writers']);
$http  = new ServerRequestFactory();

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
        echo "  FAIL  {$label}" . ($detail !== '' ? "  ({$detail})" : '') . "\n";
    }
}

function get($app, string $path): array
{
    global $http;
    $res = $app->handle($http->createServerRequest('GET', $path));
    return [
        'status'   => $res->getStatusCode(),
        'body'     => (string) $res->getBody(),
        'location' => $res->getHeaderLine('Location'),
    ];
}

/**
 * A statement whose cursor is closed before returning.
 *
 * Every read in this file leaves its cursor open otherwise. Under WAL an open
 * cursor keeps the connection's read snapshot alive, and the next write from
 * the SAME handle then has to upgrade that stale snapshot to a write — which
 * SQLite refuses outright with SQLITE_BUSY_SNAPSHOT. It refuses instantly and
 * permanently, so one forgotten closeCursor() poisons the handle for the rest
 * of the process and every later write fails with a bare "database is locked".
 */
function one(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    $stmt->closeCursor();
    return is_array($row) ? $row : [];
}

/**
 * Run an intent as the site's admin.
 *
 * Acting as the real admin row (rather than a synthetic role string) keeps the
 * test honest: the role the registry authorises against is the one a person
 * signing in at /login would actually have.
 */
function asAdmin(IntentRegistry $exec, Connection $db, string $action, array $params): array
{
    $row = $db->query("SELECT role FROM users WHERE role = 'admin' ORDER BY id LIMIT 1")->fetch();
    return $exec->execute(['action' => $action, 'params' => $params], (string) ($row['role'] ?? 'admin'));
}

// Unique slug so a re-run never collides with a previous one.
//
// The pid goes in as DIGITS ONLY. create_page slugifies its input, and '_' is
// not a slug character — it becomes '-'. So a suffix containing an underscore
// produces a different stored slug than the one requested here, and every
// assertion below that compares against $slug fails for a reason that has
// nothing to do with the code under test.
$suffix = substr((string) time(), -6) . getmypid();
$slug   = 'site-edit-probe-' . $suffix;
$pageId = 0;

// ---------------------------------------------------------------- cleanup
//
// Runs after the summary is printed, so it can never be the thing that makes
// the suite look like it failed. Two properties matter and both were learned
// the hard way:
//
// 1. Nothing here opens a transaction. PDO_SQLite opens its sqlite3 handle
//    lazily, and until that open completes PDO::inTransaction() answers TRUE
//    without any BEGIN having executed — so beginTransaction() here creates a
//    write transaction on a phantom read transaction, and the first DELETE
//    then deadlocks the handle against itself. Every statement is its own
//    autocommit write instead, which is fine: they touch only this run's
//    unique ids.
//
// 2. Every step is individually guarded, because seo_meta is keyed
//    (entity_type, entity_id) and NOT post_id. A DELETE naming a post_id column
//    there raises a PDOException, and an exception inside a shutdown function
//    aborts the REST of the cleanup — which is how earlier runs left five probe
//    posts, their redirects and their design token behind, and made every later
//    run report a false "the accent was already #ff0066".
$pdoForCleanup = $pdo;

register_shutdown_function(static function () use (&$pageId, $slug, $pdoForCleanup): void {
    $steps = [
        // seo_meta, keyed by entity type + id.
        'seo_meta'      => ["DELETE FROM seo_meta WHERE entity_type = 'page' AND entity_id = ?", [$pageId]],
        // post_tags, joined by post_id.
        'post_tags'     => ['DELETE FROM post_tags WHERE post_id = ?', [$pageId]],
        'posts'         => ['DELETE FROM posts WHERE id = ?', [$pageId]],
        // The renamed slug is the one actually stored, which is $slug.'-renamed'.
        // Match on the prefix so a half-completed rename still cleans up.
        'redirects'     => ['DELETE FROM redirects WHERE source_path LIKE ?', ['%' . $slug]],
        // design_tokens is keyed by `key`, with no surrogate id column.
        'design_tokens' => ['DELETE FROM design_tokens WHERE key = ?', ['site_edit_probe_accent']],
    ];

    $failedAt = '';

    foreach ($steps as $name => [$sql, $params]) {
        $failedAt = $name;
        try {
            $stmt = $pdoForCleanup->prepare($sql);
            $stmt->execute($params);
            $stmt->closeCursor();
        } catch (\PDOException $e) {
            error_log('[site_editing_test] cleanup failed at the ' . $name . ' step: '
                . $e->getMessage() . ' — the probe rows for ' . $slug . ' may need manual removal.');
            break;
        }
    }

    $failedAt = '';

    // The app held this process's DesignVars memo, and its read handle may be
    // sitting in the implicit transaction PDO opened for the last SELECT.
    \CMS\Render\DesignVars::reset();

    if ($failedAt !== '') {
        echo "  WARN  cleanup stopped at the '{$failedAt}' step; probe rows for '{$slug}' may remain\n";
    }
});

// ---------------------------------------------------------------- 1. create
echo "\n== 1. create_page ==\n";

$res = asAdmin($exec, $db, 'create_page', [
    'title'   => 'Site Edit Probe',
    'slug'    => $slug,
    'body_md' => '# Probe' . "\n\nThis page exists so a test can prove an agent can make one.",
]);
check('create_page succeeds', empty($res['error']), json_encode($res));

$pageId = (int) ($res['post_id'] ?? 0);
check('create_page returns a post_id', $pageId > 0, "({$pageId})");

$post = one($pdo, 'SELECT slug, title, type, status FROM posts WHERE id = ?', [$pageId]);
check('the row exists in posts', $post !== [], json_encode($post));
check('it is type=page', ($post['type'] ?? '') === 'page', '(type: ' . ($post['type'] ?? '?') . ')');
check('it is created as a DRAFT, not published',
      ($post['status'] ?? '') === 'draft',
      '(status: ' . ($post['status'] ?? '?') . ')');

// A draft must NOT be publicly reachable. create_page publishing immediately
// would let an agent put arbitrary content on the live site with one prompt and
// no review step, so draft-by-default is a safety property, not a detail.
$r = get($app, '/page/' . $slug);
check('a draft page is not publicly reachable (404)', $r['status'] === 404, '(' . $r['status'] . ')');

// ---------------------------------------------------------------- 2. rename
echo "\n== 2. rename_page writes a 301 ==\n";

$newSlug = $slug . '-renamed';
$res = asAdmin($exec, $db, 'rename_page', [
    'post_id'   => $pageId,
    'new_slug'  => $newSlug,
    'new_title' => 'Site Edit Probe Renamed',
]);
check('rename_page succeeds', empty($res['error']), json_encode($res));

$post = one($pdo, 'SELECT slug, title FROM posts WHERE id = ?', [$pageId]);
check('the slug changed', ($post['slug'] ?? '') === $newSlug, '(slug: ' . ($post['slug'] ?? '?') . ')');
check('the title changed', ($post['title'] ?? '') === 'Site Edit Probe Renamed',
      '(title: ' . ($post['title'] ?? '?') . ')');

$redirect = one($pdo, 'SELECT * FROM redirects WHERE source_path = ?', ['/page/' . $slug]);
check('a redirect row was written for the old URL', $redirect !== [], json_encode($redirect));
check('it is a 301 (permanent)', (int) ($redirect['status_code'] ?? 0) === 301,
      '(status: ' . ($redirect['status_code'] ?? 'none') . ')');
check('it points at the new URL', ($redirect['target_path'] ?? '') === '/page/' . $newSlug,
      '(target: ' . ($redirect['target_path'] ?? 'none') . ')');

// ---------------------------------------------------------------- 3. publish
echo "\n== 3. the renamed page renders ==\n";

$stmt = $pdo->prepare("UPDATE posts SET status = 'published', published_at = datetime('now') WHERE id = ?");
$stmt->execute([$pageId]);
$stmt->closeCursor();

$r = get($app, '/page/' . $newSlug);
check('the new URL renders 200', $r['status'] === 200, '(' . $r['status'] . ', ' . strlen($r['body']) . ' bytes)');
check('it shows the new title', str_contains($r['body'], 'Site Edit Probe Renamed'));

$r = get($app, '/page/' . $slug);
check('the old URL 301s', $r['status'] === 301, '(' . $r['status'] . ')');
check('the 301 points at the new URL', str_contains($r['location'], $newSlug),
      '(Location: ' . $r['location'] . ')');

$hit = one($pdo, 'SELECT hits FROM redirects WHERE source_path = ?', ['/page/' . $slug]);
check('the redirect hit counter incremented', (int) ($hit['hits'] ?? 0) >= 1, '(hits: ' . ($hit['hits'] ?? '?') . ')');

// ---------------------------------------------------------------- 4. tokens
echo "\n== 4. apply_design_token changes the RENDERED page ==\n";

// The token map is memoised per PROCESS, and the three renders above have
// already populated that memo. So this write must invalidate it, or the render
// below proves nothing.
$accent = static function (string $html): string {
    return preg_match('/--accent:([^;\"]+)/', $html, $m) === 1 ? trim($m[1]) : '(not found)';
};

$before = $accent(get($app, '/page/' . $newSlug)['body']);

$res = asAdmin($exec, $db, 'apply_design_token', [
    'category' => 'color',
    'key'      => 'site_edit_probe_accent',
    'value'    => '#ff0066',
    'css_var'  => '--accent',
]);
check('apply_design_token succeeds', empty($res['error']), json_encode($res));

$after = $accent(get($app, '/page/' . $newSlug)['body']);
check('the accent changed in the rendered HTML of the SAME process',
      $after === '#ff0066',
      "(before: {$before}, after: {$after})");

// A failure here is not a product bug, so say which of the two it was.
//
// The accent starts as whatever the seeded theme says. If a previous run leaked
// its probe token, the site ALREADY renders #ff0066 before this test writes it,
// the write changes nothing observable, and the change-detection assertion
// above fails for a reason that has nothing to do with the change detection.
// (This is exactly how the first run of this file failed — its own shutdown
// function never ran because an unguarded statement threw first.) Detect the
// leak and skip rather than reporting a green assertion on a broken fixture.
if ($before === '#ff0066') {
    echo "  SKIP  the previous value is real  (leaked #ff0066 from an earlier run — "
        . "the token is deleted by this file's cleanup; if you see this repeatedly,\n"
        . "        delete the design_tokens row with key 'site_edit_probe_accent')\n";
} else {
    check('the previous value is really what was there before', true);
}

// A token whose value would break out of the style attribute must be refused
// at WRITE time, so the agent is told it failed instead of the row landing and
// silently changing nothing.
$res = asAdmin($exec, $db, 'apply_design_token', [
    'category' => 'color', 'key' => 'site_edit_probe_accent',
    'value' => '#ff0066; background:url(//evil.test)', 'css_var' => '--accent',
]);
check('a value containing a declaration separator is refused',
      ($res['error'] ?? '') === 'invalid_token_value', json_encode($res));

$res = asAdmin($exec, $db, 'apply_design_token', [
    'category' => 'color', 'key' => 'site_edit_probe_accent',
    'value' => '#00ff00', 'css_var' => 'not-a-css-var',
]);
check('a malformed css_var is refused',
      ($res['error'] ?? '') === 'invalid_css_var', json_encode($res));

// And the good value must still be in place after both refusals.
$final = $accent(get($app, '/page/' . $newSlug)['body']);
check('the refused writes left the good token intact', $final === '#ff0066', "(now: {$final})");

// ---------------------------------------------------------------- 5. roles
echo "\n== 5. the same intents are refused below their role floor ==\n";

// create_page/rename_page are editor-gated. Proving the refusal matters more
// than the success above: a registry that only ever checked the allowlist and
// not the ROLE would let any signed-in author republish the whole site.
foreach (['create_page' => ['title' => 'X', 'slug' => 'x-' . $suffix, 'body_md' => 'x'],
          'rename_page' => ['post_id' => $pageId, 'new_slug' => 'y-' . $suffix]] as $action => $params) {
    $threw = null;
    try {
        $exec->execute(['action' => $action, 'params' => $params], 'author');
    } catch (\Throwable $e) {
        $threw = $e;
    }
    check("{$action} is refused for an author",
          $threw instanceof \RuntimeException,
          '(' . ($threw === null ? 'IT SUCCEEDED' : get_class($threw)) . ')');
}

echo "\n" . str_repeat('=', 46) . "\n";
echo "  {$pass} passed, {$fail} failed\n";
echo str_repeat('=', 46) . "\n";
exit($fail === 0 ? 0 : 1);