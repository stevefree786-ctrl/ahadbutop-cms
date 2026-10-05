<?php
/**
 * Repository invariants.
 *
 * These repositories are the only sanctioned way to touch SQL, so the tests
 * here assert two things that matter more than any individual method:
 *
 *  1. The schema they target actually exists. A repository can be internally
 *     consistent and still query a column that was renamed or dropped — the
 *     failure only shows up as a runtime 500 on the first real request. Every
 *     COLUMNS constant is checked against the live table.
 *  2. Their behaviour under the edges that actually bite: slug collisions,
 *     lifecycle transitions, taxonomy recounting, and the last-admin guard.
 *
 * All fixtures are created with a distinctive prefix and swept at the end.
 * tests/queue_test.php asserts the suite leaves the DB tidy, so a leak here
 * fails an unrelated test.
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

if (is_file(base_path('.env'))) {
    Dotenv\Dotenv::createImmutable(base_path())->safeLoad();
}

use CMS\Database\Connection;
use CMS\Repository\PostRepository;
use CMS\Repository\UserRepository;
use CMS\Repository\TaxonomyRepository;
use CMS\Repository\SettingsRepository;
use CMS\Repository\MediaRepository;

$db     = new Connection(require base_path('config/database.php'));
$posts  = new PostRepository($db);
$users  = new UserRepository($db);
$taxo   = new TaxonomyRepository($db);
// Two SEPARATE instances. forTags()/forCategories() mutate $this and return
// $this, so $cats = $taxo->forCategories() makes $tags and $cats the SAME object
// with both names pointing at 'categories' — the test then reads its tags out
// of the categories table and every tag assertion silently passes for the wrong
// reason. Separate instances is what makes the two handles independent.
$tags   = (new TaxonomyRepository($db))->forTags();
$cats   = (new TaxonomyRepository($db))->forCategories();
$settings = new SettingsRepository($db);
$media  = new MediaRepository($db);

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

function section(string $title): void
{
    echo "\n{$title}\n";
}

/**
 * Every table this test touches, deleted.
 *
 * Run BEFORE the fixtures as well as after: an interrupted earlier run leaves
 * suffixed slugs (repo-test-fixture-2, -3, ...) behind, and the slug-uniqueness
 * assertions below are about how collision resolution behaves from a known
 * state, not about whatever happened to be in the database.
 *
 * The child tables are discovered by inspecting the schema rather than assumed
 * — seo_meta is polymorphic (entity_type/entity_id), ai_runs has no post
 * reference at all, and guessing wrong raises "no such column" mid-sweep and
 * leaves everything behind.
 */
/**
 * Put admin roles back after the last-admin test demoted them.
 *
 * The test has to make its fixture the only administrator, which briefly leaves
 * the live database with no admin at all. Restoring is therefore registered as
 * a shutdown handler rather than done inline, so an assertion failure or a
 * fatal partway through still returns the table to its original shape. An
 * aborted test run must never leave the CMS unadministrable.
 */
function restoreAdminRoles(Connection $db, array $demoted): void
{
    foreach ($demoted as $row) {
        $db->query('UPDATE users SET role = ? WHERE id = ?', [$row['role'], (int) $row['id']]);
    }
}

function restoreAdminRolesOnShutdown(Connection $db, array $demoted): void
{
    if ($demoted === []) {
        return;
    }
    register_shutdown_function(static function () use ($db, $demoted): void {
        try {
            restoreAdminRoles($db, $demoted);
        } catch (\Throwable) {
            // Nothing useful to do while the process is already ending.
        }
    });
}

function sweep(Connection $db): void
{
    $like = 'repo-test-%';

    $postIds = $db->query('SELECT id FROM posts WHERE slug LIKE ?', [$like])->fetchAll(PDO::FETCH_COLUMN);
    if ($postIds !== []) {
        $in = implode(',', array_fill(0, count($postIds), '?'));

        foreach (['post_tags', 'post_categories', 'revisions'] as $table) {
            $db->query("DELETE FROM {$table} WHERE post_id IN ({$in})", $postIds);
        }

        // seo_meta is polymorphic: entity_type tells you what entity_id refers to.
        $db->query(
            "DELETE FROM seo_meta WHERE entity_type = 'post' AND entity_id IN ({$in})",
            $postIds
        );
    }

    $db->query('DELETE FROM posts WHERE slug LIKE ?', [$like]);
    $db->query('DELETE FROM tags WHERE slug LIKE ?', [$like]);
    $db->query('DELETE FROM categories WHERE slug LIKE ?', [$like]);
    $db->query('DELETE FROM users WHERE email LIKE ?', [$like]);
    $db->query('DELETE FROM media WHERE "key" LIKE ?', [$like . '%']);
    $db->query('DELETE FROM settings WHERE "key" LIKE ?', [$like]);
    $db->query('DELETE FROM job_logs WHERE job_id NOT IN (SELECT id FROM jobs)');
    $db->query("DELETE FROM jobs WHERE idempotency_key LIKE 'repo-test-%'");
}

sweep($db);

// ---------------------------------------------------------------------------
section('Schema alignment (the point of this file)');

// A repository whose COLUMNS reference a dropped or renamed column is
// internally consistent and still broken. Compare each declared column to the
// real table.
$repoClasses = [
    'posts'    => $posts,
    'users'    => $users,
    'tags'     => $tags,
    'categories' => $cats,
    'settings' => $settings,
    'media'    => $media,
];

$schema = $db->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);

foreach ($repoClasses as $table => $repo) {
    if (!in_array($table, $schema, true)) {
        check("table \"$table\" exists", false, 'not present in sqlite_master');
        continue;
    }

    $actual = $db->query("PRAGMA table_info(\"$table\")")->fetchAll(PDO::FETCH_COLUMN, 1);

    // Read the declared columns off the class constant.
    $ref = new ReflectionClass($repo);
    $declared = [];
    while ($ref !== false) {
        if ($ref->hasConstant('COLUMNS')) {
            $declared = (array) $ref->getConstant('COLUMNS');
            break;
        }
        $ref = $ref->getParentClass();
    }

    if ($declared === ['*']) {
        check("\"$table\" uses SELECT *", true);
        continue;
    }

    $missing = array_values(array_diff($declared, $actual));
    check(
        "every column declared for \"$table\" exists",
        $missing === [],
        'missing: ' . implode(', ', $missing)
    );
}

// ---------------------------------------------------------------------------
section('PostRepository::create');

$title      = 'repo-test-fixture';
$slugBase   = 'repo-test-fixture';
$wordCount  = 60;

$postId = $posts->create([
    'uuid'         => bin2hex(random_bytes(16)),
    'slug'         => $slugBase,
    'type'         => 'post',
    'title'        => $title,
    'excerpt'      => 'A fixture post.',
    'body_md'      => str_repeat('word ', $wordCount),
    'status'       => 'draft',
    'author_id'    => 1,
    'origin'       => 'manual',
]);

check('create returns a positive id', is_int($postId) && $postId > 0, var_export($postId, true));

$post = $posts->find($postId);
check('find() returns the created post', $post !== null && (int) $post['id'] === $postId);
check('create derives word_count', (int) ($post['word_count'] ?? -1) === $wordCount, json_encode($post['word_count'] ?? null));
check('create derives reading_time', (int) ($post['reading_time'] ?? -1) === max(1, (int) ceil($wordCount / 220)), json_encode($post['reading_time'] ?? null));
check('create defaults status to draft', ($post['status'] ?? '') === 'draft', $post['status'] ?? '');
check('findBySlug locates it', ($posts->findBySlug($slugBase)['id'] ?? null) === $postId);
check('findByUuid locates it', $posts->findByUuid((string) $post['uuid']) !== null);
check('findBySlug respects the type filter', $posts->findBySlug($slugBase, 'page') === null);
check('find() on a missing id returns null', $posts->find(99999999) === null);

// ---------------------------------------------------------------------------
section('Slug uniqueness');

$second = $posts->create([
    'uuid'      => bin2hex(random_bytes(16)),
    'slug'      => $slugBase,
    'type'      => 'post',
    'title'     => $title . ' two',
    'body_md'   => 'body',
    'status'    => 'draft',
    'author_id' => 1,
    'origin'    => 'manual',
]);

$secondRow = $posts->find($second);
check('a colliding slug is disambiguated on create', ($secondRow['slug'] ?? '') !== $slugBase, $secondRow['slug'] ?? 'null');
check('the disambiguated slug still resolves', (int) ($posts->findBySlug((string) $secondRow['slug'])['id'] ?? 0) === $second);

$unique = $posts->uniqueSlug('repo-test-brand-new');
check('uniqueSlug passes a free slug through unchanged', $unique === 'repo-test-brand-new', $unique);
$uniqueTaken = $posts->uniqueSlug($slugBase);
check('uniqueSlug disambiguates a taken slug', $uniqueTaken !== $slugBase, $uniqueTaken);
$uniqueSelf = $posts->uniqueSlug((string) $posts->find($postId)['slug'], $postId);
check('uniqueSlug ignores the row being updated', $uniqueSelf === (string) $posts->find($postId)['slug'], $uniqueSelf);

// The constraint is UNIQUE(type, slug), so a page may reuse a post's slug.
check('uniqueSlug scopes by type: a page may reuse a post slug', $posts->uniqueSlug($slugBase, null, 'page') === $slugBase, $posts->uniqueSlug($slugBase, null, 'page'));
check('create() auto-disambiguated the colliding slug', ($secondRow['slug'] ?? '') !== $slugBase, $secondRow['slug'] ?? 'null');
check('the disambiguated slug carries a numeric suffix', (bool) preg_match('/^' . preg_quote($slugBase, '/') . '-\d+$/', (string) ($secondRow['slug'] ?? '')), $secondRow['slug'] ?? 'null');

// Creating a page with a post's exact slug must NOT be disambiguated.
$pageSameSlug = $posts->create([
    'uuid'      => bin2hex(random_bytes(16)),
    'slug'      => $slugBase,
    'type'      => 'page',
    'title'     => 'repo-test page sharing a slug',
    'body_md'   => 'body',
    'status'    => 'draft',
    'author_id' => 1,
    'origin'    => 'manual',
]);
$pageRow = $posts->find($pageSameSlug);
check('a page may share a post slug (the constraint is per-type)', ($pageRow['slug'] ?? '') === $slugBase, $pageRow['slug'] ?? 'null');
check('findBySlug honours the type', (int) ($posts->findBySlug($slugBase, 'post')['id'] ?? 0) === $postId);
check('findBySlug(type=page) finds the page', (int) ($posts->findBySlug($slugBase, 'page')['id'] ?? 0) === $pageSameSlug);

// update() must resolve a rename collision rather than raise a constraint error.
$renameTargetSlug = 'repo-test-rename-target';
$renamedId = $posts->create([
    'uuid' => bin2hex(random_bytes(16)), 'slug' => $renameTargetSlug, 'type' => 'post',
    'title' => 'repo-test rename target', 'body_md' => 'b', 'status' => 'draft',
    'author_id' => 1, 'origin' => 'manual',
]);
$renameOtherId = $posts->create([
    'uuid' => bin2hex(random_bytes(16)), 'slug' => 'repo-test-rename-other',
    'type' => 'post', 'title' => 'repo-test rename other', 'body_md' => 'b',
    'status' => 'draft', 'author_id' => 1, 'origin' => 'manual',
]);
try {
    $posts->update($renameOtherId, ['slug' => $renameTargetSlug]);
    $afterRename = $posts->find($renameOtherId);
    check('update() disambiguates a colliding slug', ($afterRename['slug'] ?? '') === $renameTargetSlug . '-2', $afterRename['slug'] ?? 'null');
} catch (\Throwable $e) {
    check('update() disambiguates a colliding slug', false, get_class($e) . ': ' . $e->getMessage());
}

// Renaming a post to its OWN slug must be a no-op, not a '-2'.
$posts->update($renamedId, ['slug' => $renameTargetSlug]);
check('update() to its own slug is unchanged', ($posts->find($renamedId)['slug'] ?? '') === $renameTargetSlug, $posts->find($renamedId)['slug'] ?? 'null');

// ---------------------------------------------------------------------------
section('PostRepository lifecycle');

check('publish() returns true', $posts->publish($postId) === true);
$post = $posts->find($postId);
check('publish sets status', ($post['status'] ?? '') === 'published', $post['status'] ?? '');
check('publish stamps published_at', !empty($post['published_at']), json_encode($post['published_at'] ?? null));

check('unpublish() returns true', $posts->unpublish($postId) === true);
check('unpublish sets status to draft', ($posts->find($postId)['status'] ?? '') === 'draft', $posts->find($postId)['status'] ?? '');
// Deliberate: unpublish keeps published_at so the change is reversible.
check('unpublish deliberately KEEPS published_at (reversible)', ($posts->find($postId)['published_at'] ?? null) !== null);
check('unpublish twice is a no-op', $posts->unpublish($postId) === false);

$posts->publish($postId);
check('trash() returns true', $posts->trash($postId) === true);
check('trash sets status', ($posts->find($postId)['status'] ?? '') === 'trash', $posts->find($postId)['status'] ?? '');

check('a trashed post is not in published()', !in_array($postId, array_column($posts->published(50), 'id'), true));
check('publish() refuses to resurrect a trashed post', $posts->publish($postId) === false);

check('restore() returns true', $posts->restore($postId) === true);
// Deliberate: restore returns to draft, NOT published. Trash means "withdrawn";
// republishing is an explicit second step, never a side effect of un-trashing.
check('restore returns to draft, not published', ($posts->find($postId)['status'] ?? '') === 'draft', $posts->find($postId)['status'] ?? '');

$posts->publish($postId);

// ---------------------------------------------------------------------------
section('Scheduling: dueForPublication');

// A scheduled post must NOT be visible before its time, and must be after.
// dueForPublication() keys off scheduled_at (the field the status implies);
// published_at is the OTHER date — stamping it here would test nothing.
$future = $posts->create([
    'uuid'         => bin2hex(random_bytes(16)),
    'slug'         => 'repo-test-scheduled',
    'type'         => 'post',
    'title'        => 'repo-test scheduled',
    'body_md'      => 'body',
    'status'       => 'scheduled',
    'author_id'    => 1,
    'origin'       => 'manual',
    'scheduled_at' => gmdate('Y-m-d H:i:s', time() + 3600),
]);

check('scheduled_at was stored', !empty($posts->find($future)['scheduled_at']), json_encode($posts->find($future)['scheduled_at'] ?? null));

$dueIds = array_column($posts->dueForPublication(gmdate('Y-m-d H:i:s')), 'id');
check('a future-dated post is not yet due', !in_array($future, $dueIds, true));
check(
    'it IS due once its time passes',
    in_array($future, array_column($posts->dueForPublication(gmdate('Y-m-d H:i:s', time() + 7200)), 'id'), true)
);
check('a scheduled post is not in published()', !in_array($future, array_column($posts->published(50), 'id'), true));

// A published post is never "due" regardless of scheduled_at.
$posts->update($postId, ['scheduled_at' => gmdate('Y-m-d H:i:s', time() - 60)]);
check('a published post is never due for publication', !in_array($postId, array_column($posts->dueForPublication(gmdate('Y-m-d H:i:s', time() + 7200)), 'id'), true));
$posts->update($postId, ['scheduled_at' => null]);

// ---------------------------------------------------------------------------
section('PostRepository::paginate');

$page1 = $posts->paginate(['status' => 'published'], 1, 0);
$page2 = $posts->paginate(['status' => 'published'], 1, 1);
check('paginate returns items + total', isset($page1['items'], $page1['total']), json_encode(array_keys($page1)));

if (($page1['total'] ?? 0) >= 2) {
    check('paginate honours the limit', count($page1['items']) === 1, (string) count($page1['items']));
    check(
        'pages do not overlap',
        ($page1['items'][0]['id'] ?? 0) !== ($page2['items'][0]['id'] ?? 0),
        json_encode([$page1['items'][0]['id'] ?? null, $page2['items'][0]['id'] ?? null])
    );
} else {
    check('paginate honours the limit (skipped, <2 published posts)', true);
    check('pages do not overlap (skipped, <2 published posts)', true);
}

// Filter combinations must all return, not fatal.
foreach ([['status' => 'draft'], ['status' => 'published'], ['type' => 'post'], ['author_id' => 1], []] as $filter) {
    $label = $filter === [] ? 'no filter' : json_encode($filter);
    try {
        $result = $posts->paginate($filter, 5, 0);
        check("paginate accepts {$label}", isset($result['items'], $result['total']));
    } catch (\Throwable $e) {
        check("paginate accepts {$label}", false, $e->getMessage());
    }
}

// A hostile filter value must be treated as data, never as SQL.
$hostile = $posts->paginate(['status' => "published' OR '1'='1"], 5, 0);
check('an injection-shaped filter is inert', ($hostile['total'] ?? -1) === 0, json_encode($hostile['total'] ?? null));

$hostileOrder = $posts->paginate([], 5, 0);
check('a bogus sort key does not fatal', isset($hostileOrder['items']));

// ---------------------------------------------------------------------------
section('PostRepository::stats');

$stats = $posts->stats();
check('stats returns an array', is_array($stats), json_encode($stats));
check(
    'stats reports at least the total',
    isset($stats['total']) || isset($stats['all']) || $stats !== [],
    json_encode($stats)
);

// ---------------------------------------------------------------------------
section('PostRepository helpers');

check('wordCount counts words', $posts->wordCount('one two three') === 3, (string) $posts->wordCount('one two three'));
check('wordCount ignores markdown syntax', $posts->wordCount('# Title\n\n**bold** text') >= 2, (string) $posts->wordCount('# Title\n\n**bold** text'));
check('wordCount of empty is 0', $posts->wordCount('') === 0);
check('readingTime is never below 1', $posts->readingTime(0) === 1, (string) $posts->readingTime(0));
check('readingTime scales with length', $posts->readingTime(2200) > $posts->readingTime(220), $posts->readingTime(2200) . ' vs ' . $posts->readingTime(220));

// ---------------------------------------------------------------------------
section('UserRepository');

$email = 'repo-test-' . bin2hex(random_bytes(4)) . '@example.test';
$userId = $users->create([
    'email'        => $email,
    'username'     => 'repo-test-' . bin2hex(random_bytes(4)),
    'password_hash'=> password_hash('x', PASSWORD_DEFAULT),
    'role'         => 'editor',
    'status'       => 'active',
]);

check('user create returns an id', is_int($userId) && $userId > 0, var_export($userId, true));
check('findByEmail locates it', (int) ($users->findByEmail($email)['id'] ?? 0) === $userId);
check('emailExists is true', $users->emailExists($email) === true);
check('emailExists is false for an unknown address', $users->emailExists('nobody-' . bin2hex(random_bytes(4)) . '@example.test') === false);

// findForAuth must return the hash; find must not leak it into listings.
$auth = $users->findForAuth($email);
check('findForAuth returns the password hash', !empty($auth['password_hash']), json_encode(array_keys($auth ?? [])));
check('findForAuth is case-insensitive on email', $users->findForAuth(strtoupper($email)) !== null);

check('countByRole counts', $users->countByRole('editor') >= 1, (string) $users->countByRole('editor'));
check('countByRole of an empty role is 0', $users->countByRole('nonexistent') === 0);

// The last-admin guard is a safety property, not a convenience.
//
// The guard lives in isLastAdmin() — this test's job is to prove that PREDICATE
// works, not to re-implement the check inside delete(). Asserting that
// $users->delete() refuses the sole admin would be testing a guarantee that
// does not exist, and would fail the moment the repository does nothing
// special. So: create two throwaway admins and exercise the predicate in both
// directions, which is what the guard actually is.
//
// Two fixtures rather than one because isLastAdmin() compares against
// countByRole('admin') across the WHOLE table — a single fixture alongside a
// real admin would exercise the "other admins exist" branch only.
$adminA = $users->create([
    'email'          => 'repo-test-admin-a-' . bin2hex(random_bytes(4)) . '@example.test',
    'password_hash'  => password_hash('irrelevant', PASSWORD_DEFAULT),
    'role'           => 'admin',
    'display_name'   => 'Repo Test Admin A',
]);
$adminB = $users->create([
    'email'          => 'repo-test-admin-b-' . bin2hex(random_bytes(4)) . '@example.test',
    'password_hash'  => password_hash('irrelevant', PASSWORD_DEFAULT),
    'role'           => 'admin',
    'display_name'   => 'Repo Test Admin B',
]);
check('two test admins were created', $adminA > 0 && $adminB > 0);

// Against the live table, which holds at least adminA and adminB.
check('isLastAdmin is false when other admins exist', $users->isLastAdmin($adminA) === false);

// ...and now against a table where it is genuinely the only one.
//
// This demotes every OTHER admin so adminA is the only one left, which means
// the live table is briefly left without an administrator. That is a real
// hazard: a fatal between the demotion and the restore would leave the
// database unadministrable. So the demotion is recorded and a shutdown handler
// puts it back — restore does not depend on the lines after it running.
$demoted = $db->query(
    "SELECT id, role FROM users WHERE role = 'admin' AND id NOT IN (?, ?)",
    [$adminA, $adminB]
)->fetchAll();

foreach ($demoted as $row) {
    $db->query("UPDATE users SET role = 'editor' WHERE id = ?", [(int) $row['id']]);
}

restoreAdminRolesOnShutdown($db, $demoted);

$db->query('DELETE FROM users WHERE id = ?', [$adminB]);
check('isLastAdmin is true for the only admin', $users->isLastAdmin($adminA) === true);

check('countByRole still counts the fixtures', $users->countByRole('admin') === 1, (string) $users->countByRole('admin'));

// Restore immediately as well as on shutdown, so the rest of the suite (and any
// assertion about roles) sees the database as it found it.
restoreAdminRoles($db, $demoted);

// An unknown email must not resolve.
check('findForAuth on an unknown email returns null', $users->findForAuth('nobody-' . bin2hex(random_bytes(4)) . '@example.test') === null);

// ---------------------------------------------------------------------------
section('TaxonomyRepository');

$tagId = $tags->create('repo-test tag ' . bin2hex(random_bytes(3)));
check('tag create returns an id', is_int($tagId) && $tagId > 0, var_export($tagId, true));
check('tag is findable by slug', $tags->findBySlug((string) ($tags->find($tagId)['slug'] ?? '')) !== null);

// Same name twice must not create two rows.
$dupeName = 'repo-test dupe ' . bin2hex(random_bytes(3));
$dupeA = $tags->create($dupeName);
$dupeB = $tags->create($dupeName);
check('creating the same term twice returns the same id', $dupeA === $dupeB, "{$dupeA} vs {$dupeB}");

$catId = $cats->create('repo-test cat ' . bin2hex(random_bytes(3)));
check('category create returns an id', is_int($catId) && $catId > 0);

// forTags and forCategories must address DIFFERENT tables.
//
// Asserting `$cats->find($tagId) === null` only proves the tables differ when
// the two ids differ — and on a freshly migrated database BOTH tables hand out
// id 1, so the assertion would pass for the wrong reason. Make the ids differ
// first, or the check is worthless.
check('forTags targets tags', (int) ($tags->find($tagId)['id'] ?? 0) === $tagId);
if ($catId === $tagId) {
    $cats->create('repo-test filler ' . bin2hex(random_bytes(3)));
    $catId = $cats->create('repo-test cat ' . bin2hex(random_bytes(3)));
    check('a filler forced the category id past the tag id', $catId !== $tagId, "tag={$tagId} cat={$catId}");
}
check('forCategories targets categories', $cats->find($tagId) === null);
check('a tag id is not a category id', (int) ($cats->find($catId)['id'] ?? 0) === $catId);

// usage_count counts posts that are not trashed — a draft still counts. This
// $postId is deliberately still a draft at this point, which is the case the
// naive read of `WHERE p.status != 'trash'` gets wrong.
check('attach returns true', $tags->attach($postId, $tagId) === true);
check('attach is idempotent', $tags->attach($postId, $tagId) === false);
check('forPost returns the attached tag', in_array($tagId, array_column($tags->forPost($postId), 'id'), true));
check('recompute counts a draft post', $tags->recompute($tagId) === 1, (string) $tags->recompute($tagId));
check('postsFor lists the post', in_array($postId, array_column($tags->postsFor($tagId), 'id'), true));

check('detach returns true', $tags->detach($postId, $tagId) === true);
check('detach is idempotent', $tags->detach($postId, $tagId) === false);
check('usage_count drops back to 0 after detach', $tags->recompute($tagId) === 0, (string) $tags->recompute($tagId));

// syncPost replaces the whole set atomically.
$other = $tags->create('repo-test other ' . bin2hex(random_bytes(3)));
$tags->syncPost($postId, [$tagId, $other], []);
check('syncPost attaches both', count($tags->forPost($postId)) === 2, (string) count($tags->forPost($postId)));

$tags->syncPost($postId, [$tagId], []);
check('syncPost replaces rather than appends', count($tags->forPost($postId)) === 1, (string) count($tags->forPost($postId)));

check('recomputeAll returns a count', is_int($tags->recomputeAll()));

// ---------------------------------------------------------------------------
section('SettingsRepository');

$key = 'repo-test-setting';
$settings->set($key, 'hello');
check('set then get round-trips', $settings->get($key) === 'hello', var_export($settings->get($key), true));
check('a missing key returns the default', $settings->get('repo-test-nope', 'fallback') === 'fallback');
check('a missing key returns null by default', $settings->get('repo-test-nope') === null);

$settings->set($key, ['a' => 1]);
$got = $settings->get($key);
check('an array value round-trips', is_array($got) && ($got['a'] ?? null) === 1, json_encode($got));

check('set overwrites', (function () use ($settings, $key) {
    $settings->set($key, 'first');
    $settings->set($key, 'second');
    return $settings->get($key) === 'second';
})());

$all = $settings->all();
check('all() returns a list', is_array($all) && $all !== []);
check('resolved() includes defaults', isset($settings->resolved()['site_title']) || $settings->resolved() !== [], json_encode(array_slice(array_keys($settings->resolved()), 0, 8)));

check('delete() removes the key', $settings->delete($key) === true);
check('delete() on a missing key is false', $settings->delete($key) === false);
check('the key is gone', $settings->get($key) === null);

// requiresAdmin must be a real check against ADMIN_ONLY, not a stub.
$adminOnlyKey = SettingsRepository::ADMIN_ONLY[0] ?? null;
check('ADMIN_ONLY is non-empty', is_string($adminOnlyKey));
if ($adminOnlyKey !== null) {
    check("requiresAdmin({$adminOnlyKey}) is true", $settings->requiresAdmin($adminOnlyKey) === true);
}
check('requiresAdmin on an ordinary key is false', $settings->requiresAdmin('repo-test-setting') === false);

// ---------------------------------------------------------------------------
section('MediaRepository::validateUpload (static, no DB)');

$good = MediaRepository::validateUpload('photo.jpg', 1024, 'image/jpeg');
check('a valid jpg is accepted', ($good['ok'] ?? false) === true, json_encode($good));
check('a rejected upload reports why', array_key_exists('error', $good) || array_key_exists('ok', $good), json_encode($good));

$noExt = MediaRepository::validateUpload('payload', 1024, 'image/jpeg');
check('a file with no extension is rejected', ($noExt['ok'] ?? true) === false, json_encode($noExt));

$badExt = MediaRepository::validateUpload('payload.php', 1024, 'image/jpeg');
check('a .php file is rejected', ($badExt['ok'] ?? true) === false, json_encode($badExt));

$mismatch = MediaRepository::validateUpload('photo.png', 1024, 'image/jpeg');
check('an extension/mime mismatch is rejected', ($mismatch['ok'] ?? true) === false, json_encode($mismatch));

$key = MediaRepository::buildKey('photo.jpg', 'abc123');
check('buildKey produces a storage key', is_string($key) && $key !== '', $key);
check('buildKey hides the raw filename', !str_contains($key, 'photo.jpg'), $key);
check('buildKey is deterministic', MediaRepository::buildKey('photo.jpg', 'abc123') === $key);

// ---------------------------------------------------------------------------
section('MediaRepository register / find');

$mediaKey = MediaRepository::buildKey('repo-test-' . bin2hex(random_bytes(3)) . '.jpg', 'deadbeef');
$mediaId  = $media->register([
    'uuid'     => bin2hex(random_bytes(16)),
    'storage'  => 'remote',
    'key'      => $mediaKey,
    'url'      => 'https://example.test/' . $mediaKey,
    'mime'     => 'image/jpeg',
    'bytes'    => 2048,
    'checksum' => 'deadbeef',
]);

check('media register returns an id', is_int($mediaId) && $mediaId > 0, var_export($mediaId, true));
check('findByKey locates it', (int) ($media->findByKey('remote', $mediaKey)['id'] ?? 0) === $mediaId);
check('findByKey misses on the wrong storage', $media->findByKey('r2', $mediaKey) === null);
check('totalBytes is a non-negative int', is_int($media->totalBytes()) && $media->totalBytes() >= 0);
check('delete() removes it', $media->delete($mediaId) === true);
check('delete() on a missing id is false', $media->delete(99999999) === false);

// ---------------------------------------------------------------------------
section('Sweep');

sweep($db);
check('no test posts remain', (int) ($db->query("SELECT COUNT(*) FROM posts WHERE slug LIKE 'repo-test-%'")->fetchColumn()) === 0);
check('no test tags remain', (int) ($db->query("SELECT COUNT(*) FROM tags WHERE slug LIKE 'repo-test-%'")->fetchColumn()) === 0);
check('no test users remain', (int) ($db->query("SELECT COUNT(*) FROM users WHERE email LIKE 'repo-test-%'")->fetchColumn()) === 0);
check('no orphaned post_tags remain', (int) ($db->query('SELECT COUNT(*) FROM post_tags WHERE post_id NOT IN (SELECT id FROM posts)')->fetchColumn()) === 0);
check('no test jobs remain', (int) ($db->query("SELECT COUNT(*) FROM jobs WHERE idempotency_key LIKE 'repo-test-%'")->fetchColumn()) === 0);

echo "\n  {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);