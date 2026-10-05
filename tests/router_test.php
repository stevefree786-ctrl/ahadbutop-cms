<?php
/**
 * End-to-end tests for the public site router (CMS\Render\Router).
 *
 * Two things are exercised deliberately:
 *
 *  - match() with no HTTP at all — the routing table is a pure function of
 *    (method, path), so its branches are provable without a request stack.
 *    This is where the /page/{n} vs /page/{slug} collision is pinned down.
 *  - handle() with real PSR-7 requests — status codes, Content-Type, asset
 *    bytes and the draft-invisibility guarantee are only observable end to end.
 *
 * Every fixture is tagged with a unique prefix and removed at the end, including
 * the FTS index: queue_test.php asserts the suite leaves the DB tidy, so a
 * leaked post or tag here fails an unrelated test.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';

if (is_file(base_path('.env'))) {
    Dotenv\Dotenv::createImmutable(base_path())->safeLoad();
}

use CMS\Database\Connection;
use CMS\Render\NotFoundException;
use CMS\Render\Router;
use CMS\Repository\PostRepository;
use CMS\Repository\TaxonomyRepository;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\ResponseFactory;

$db      = new Connection(require $root . '/config/database.php');
$pdo     = $db->getPdo();
$reqF    = new ServerRequestFactory();
$resF    = new ResponseFactory();
$router  = new Router($db, null, $resF);

/** Unique per run so parallel or repeated runs cannot collide. */
$PREFIX  = 'rt-' . bin2hex(random_bytes(4));
$TAG     = $PREFIX . 'tag';
$USER    = $PREFIX . 'author';

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
 * Insert a post directly.
 *
 * Written by hand rather than via PostRepository::create() so a test can set
 * `status` and `published_at` to combinations create() would not produce —
 * a scheduled-but-not-yet-published row is the interesting case, and the
 * repository has no path that makes one.
 */
function mkPost(PDO $pdo, array $over = []): int
{
    $row = array_merge([
        'uuid'      => bin2hex(random_bytes(16)),
        'slug'      => 'rt-' . bin2hex(random_bytes(6)),
        'type'      => 'post',
        'title'     => 'Router fixture',
        'excerpt'   => 'Fixture excerpt.',
        'body_md'   => "Some **markdown** body for the fixture.",
        'status'    => 'published',
        'origin'    => 'manual',          // schema: manual|ai|ai_edited — NOT 'human'
        'author_id' => null,
        'created_at' => '2026-01-01 00:00:00',
        'updated_at' => '2026-01-01 00:00:00',
        'published_at' => '2026-01-01 00:00:00',
    ], $over);

    $cols = array_keys($row);
    $sql  = 'INSERT INTO posts (' . implode(', ', $cols) . ') VALUES ('
          . implode(', ', array_fill(0, count($cols), '?')) . ')';
    $pdo->prepare($sql)->execute(array_values($row));

    return (int) $pdo->lastInsertId();
}

/**
 * Insert a post and return its slug.
 *
 * The generated slug carries the run's $PREFIX so the cleanup block can find
 * it. A slug made of a fresh random value would survive cleanup and leak a row
 * into the database every run — and tests/queue_test.php asserts the DB is
 * left tidy.
 */
function mkSlug(PDO $pdo, array $over = []): string
{
    global $PREFIX;
    $row = $over;
    $row['slug'] ??= $PREFIX . '-' . bin2hex(random_bytes(6));
    mkPost($pdo, $row);
    return (string) $row['slug'];
}

/**
 * Dispatch a request and return [status, contentType, body].
 *
 * @param array<string,string> $query
 * @return array{0:int,1:string,2:string}
 */
function http(Router $router, string $path, array $query = [], string $method = 'GET'): array
{
    $req = (new ServerRequestFactory())->createServerRequest($method, $path);
    if ($query !== []) {
        $req = $req->withQueryParams($query);
    }
    $res = $router->handle($req);
    $res->getBody()->rewind();
    return [
        $res->getStatusCode(),
        $res->getHeaderLine('Content-Type'),
        (string) $res->getBody(),
    ];
}

/** True when match() throws NotFoundException. */
function miss(Router $router, string $path, string $method = 'GET'): bool
{
    try {
        $router->match($method, $path);
        return false;
    } catch (NotFoundException) {
        return true;
    }
}

// ---------------------------------------------------------------- fixtures
$publishedId  = mkPost($pdo, [
    'slug'         => $PREFIX . '-published',
    'title'        => 'Router published fixture',
    'status'       => 'published',
    'published_at' => '2026-03-15 12:00:00',
    'author_id'    => null,
]);
$publishedSlug = $PREFIX . '-published';

$draftId  = mkPost($pdo, [
    'slug'         => $PREFIX . '-draft',
    'title'        => 'Router DRAFT fixture',
    'status'       => 'draft',
    'published_at' => null,
]);
$draftSlug = $PREFIX . '-draft';

$trashId = mkPost($pdo, [
    'slug'   => $PREFIX . '-trash',
    'title'  => 'Router TRASH fixture',
    'status' => 'trash',
]);
$trashSlug = $PREFIX . '-trash';

$reviewId = mkPost($pdo, [
    'slug'   => $PREFIX . '-review',
    'title'  => 'Router REVIEW fixture',
    'status' => 'review',
]);
$reviewSlug = $PREFIX . '-review';

$pageId = mkPost($pdo, [
    'slug'   => $PREFIX . '-page',
    'type'   => 'page',
    'title'  => 'Router page fixture',
    'status' => 'published',
]);
$pageSlug = $PREFIX . '-page';

$draftPageId = mkPost($pdo, [
    'slug'   => $PREFIX . '-draftpage',
    'type'   => 'page',
    'title'  => 'Router DRAFT page fixture',
    'status' => 'draft',
]);
$draftPageSlug = $PREFIX . '-draftpage';

// A post published in a specific month, for /archive/{yyyy}/{mm}.
$archiveId = mkPost($pdo, [
    'slug'         => $PREFIX . '-archived',
    'status'       => 'published',
    'published_at' => '2026-07-04 09:30:00',
]);
$archiveSlug = $PREFIX . '-archived';

// A future-dated post: published, but published_at is still ahead of now.
// The public site must not show it before its moment.
$futureId = mkPost($pdo, [
    'slug'         => $PREFIX . '-future',
    'title'        => 'Router FUTURE fixture',
    'status'       => 'published',
    'published_at' => '2999-01-01 00:00:00',
]);
$futureSlug = $PREFIX . '-future';

// Enough posts to make the listing paginate, so /page/2 is a real page and
// the collision test has something to resolve against. Seed past whatever
// per-page the site is configured for, so there are always 2+ pages.
$configuredRow = $pdo->query("SELECT value FROM settings WHERE key = 'posts_per_page'")->fetchColumn();
$configured    = json_decode((string) $configuredRow, true);
$perPage       = is_int($configured) && $configured > 0 ? $configured : 10;

$existingPublished = (int) $pdo
    ->query("SELECT COUNT(*) FROM posts WHERE status = 'published' AND type = 'post'")
    ->fetchColumn();

$bulkIds = [];
for ($i = $existingPublished; $i < $perPage + 2; $i++) {
    $bulkIds[] = mkPost($pdo, [
        'slug'         => $PREFIX . '-bulk-' . $i,
        'title'        => 'Router bulk fixture ' . $i,
        'status'       => 'published',
        'published_at' => '2026-02-' . str_pad((string) (($i % 27) + 1), 2, '0', STR_PAD_LEFT) . '08:00:00',
    ]);
}

// Taxonomy fixtures.
$tagRepo = (new TaxonomyRepository($db))->forTags();
$tagId   = $tagRepo->create('Router Tag', $TAG);
$tagRepo->attach($publishedId, $tagId);
// The future-dated post is tagged too, so the tag archive has to *exclude* it
// on its own merits rather than merely never receiving it.
$tagRepo->attach($futureId, $tagId);

$catRepo = (new TaxonomyRepository($db))->forCategories();
$catId   = $catRepo->create('Router Category', $PREFIX . 'cat');
$catRepo->attach($publishedId, $catId);

// A tag with no published posts: the archive must still 200, empty.
$emptyTagId = $tagRepo->create('Router Empty Tag', $PREFIX . 'emptytag');
$tagRepo->attach($draftId, $emptyTagId);

/** Insert a user row and return its id. */
function mkUser(PDO $pdo, string $username, string $status): int
{
    $pdo->prepare(
        "INSERT INTO users (uuid, email, username, password_hash, display_name, role, status)
         VALUES (?, ?, ?, 'x', ?, 'author', ?)"
    )->execute([
        bin2hex(random_bytes(16)),
        $username . '@example.invalid',
        $username,
        'Router Author',
        $status,
    ]);
    return (int) $pdo->lastInsertId();
}

// An author fixture, so /author/{username} has something to list.
$authorId = mkUser($pdo, $USER, 'active');
$pdo->prepare('UPDATE posts SET author_id = ? WHERE id = ?')->execute([$authorId, $publishedId]);

// A suspended author: their archive must be invisible.
$suspendedUser = $PREFIX . 'suspended';
mkUser($pdo, $suspendedUser, 'suspended');

echo "\nRouter — route matching\n";

// --- home -----------------------------------------------------------------
$home = $router->match('GET', '/');
check('/ maps to the home template', $home['template'] === 'home', $home['template']);
check('home carries a posts list', is_array($home['vars']['posts'] ?? null));
check('home carries pagination', isset($home['vars']['pagination']['page'], $home['vars']['pagination']['pages']));

// A trailing slash must reach the same route.
check('// normalises to home', $router->match('GET', '//')['template'] === 'home');
check('/ trailing slash reaches home', $router->match('GET', '/')['template'] === 'home');

// --- post / page ----------------------------------------------------------
$post = $router->match('GET', '/' . $publishedSlug);
check('/{slug} maps to the post template', $post['template'] === 'post', $post['template']);
check('post vars carry the post row', ($post['vars']['post']['id'] ?? 0) === $publishedId);
check('post body is rendered to HTML', str_contains((string) ($post['vars']['post']['body_html'] ?? ''), '<strong>'));
check('the post template name is post, not page', $post['template'] !== 'page');

$pg = $router->match('GET', '/page/' . $pageSlug);
check('/page/{slug} maps to the page template', $pg['template'] === 'page', $pg['template']);
check('page vars carry type=page', ($pg['vars']['post']['type'] ?? '') === 'page');

// --- /page/{n} vs /page/{slug}: the deliberate collision --------------------
// The listing wins when the digit names a page that exists; otherwise the
// segment is read as a slug. Both halves are asserted, because asserting
// only the first would pass even if the fallback had been deleted.
$pageTwo = $router->match('GET', '/page/2');
check('/page/2 resolves to the home listing (digits win when the page exists)',
    $pageTwo['template'] === 'home', $pageTwo['template']);
check('/page/2 reports page 2', ($pageTwo['vars']['pagination']['page'] ?? 0) === 2,
    json_encode($pageTwo['vars']['pagination'] ?? []));

// The seeded listing has several pages, so /page/9 must be a miss.
check('/page/9 beyond the listing is a 404', miss($router, '/page/9'));

// A page whose slug is all digits: on a site where the listing has fewer
// pages than the number, the same segment must be read as a SLUG. This is the
// other half of the resolution — asserting only "digits win" would still pass
// if the fallback had been deleted.
$numericPageSlug = '2' . substr($PREFIX, 3);      // e.g. "2rt-ab12cd34"
mkPost($pdo, [
    'slug'   => $numericPageSlug,
    'type'   => 'page',
    'status' => 'published',
    'title'  => 'Numeric slug page',
]);

$numeric = $router->match('GET', '/page/2' . substr($PREFIX, 3));
check('a numeric page slug is found once the digit no longer names a listing page',
    $numeric['template'] === 'page' && $numeric['vars']['post']['slug'] === $numericPageSlug,
    $numeric['template'] . ' ' . ($numeric['vars']['post']['slug'] ?? ''));

// A digit segment past the end of the listing is a 404, not a guessed page.
check('/page/999999 beyond the listing is a 404', miss($router, '/page/999999'));

// --- taxonomy / author / archive / search ---------------------------------
$tag = $router->match('GET', '/tag/' . $TAG);
check('/tag/{slug} maps to archive', $tag['template'] === 'archive', $tag['template']);
check('the tagged post is listed', count($tag['vars']['posts']) >= 1);
check('archive reports a count', ($tag['vars']['count'] ?? 0) >= 1);

$cat = $router->match('GET', '/category/' . $PREFIX . 'cat');
check('/category/{slug} maps to archive', $cat['template'] === 'archive', $cat['template']);

$emptyTag = $router->match('GET', '/tag/' . $PREFIX . 'emptytag');
check('a tag with no published posts still renders an empty archive (200)',
    $emptyTag['template'] === 'archive' && $emptyTag['vars']['posts'] === [],
    json_encode($emptyTag['vars']['posts']));

$author = $router->match('GET', '/author/' . $USER);
check('/author/{username} maps to archive', $author['template'] === 'archive', $author['template']);
check("the author's post is listed", count($author['vars']['posts']) >= 1);

$archive = $router->match('GET', '/archive/2026/07');
check('/archive/{yyyy}/{mm} maps to archive', $archive['template'] === 'archive', $archive['template']);
check('the July fixture is in the July archive',
    in_array($archiveSlug, array_column($archive['vars']['posts'], 'slug'), true),
    json_encode(array_column($archive['vars']['posts'], 'slug')));

$search = $router->match('GET', '/search');
check('/search maps to the search template', $search['template'] === 'search', $search['template']);

// --- misses ----------------------------------------------------------------
check('an unknown slug is a miss', miss($router, '/no-such-post-' . bin2hex(random_bytes(3))));
check('/tag with no slug is a miss', miss($router, '/tag'));
check('/author with no slug is a miss', miss($router, '/author'));
check('/archive with a bad month is a miss', miss($router, '/archive/2026/13'));
check('/archive with a short year is a miss', miss($router, '/archive/26/07'));
check('/archive with one segment is a miss', miss($router, '/archive/2026'));
check('/search/extra is a miss', miss($router, '/search/extra'));
check('a deep path is a miss', miss($router, '/a/b/c/d'));
check('POST is not routed', miss($router, '/', 'POST'));

// --- draft invisibility ---------------------------------------------------
// The core guarantee: a non-published post must be indistinguishable from
// one that never existed. Both 404, and neither leaks its title.
check('a draft post is a miss', miss($router, '/' . $draftSlug));
check('a trashed post is a miss', miss($router, '/' . $trashSlug));
check('a post in review is a miss', miss($router, '/' . $reviewSlug));
check('a draft page is a miss', miss($router, '/page/' . $draftPageSlug));
check('a future-dated post is a miss before its date', miss($router, '/' . $futureSlug));
check('a suspended author is a miss', miss($router, '/author/' . $suspendedUser));

[$st, , $body] = http($router, '/' . $draftSlug);
check('a draft post returns 404 over HTTP', $st === 404, (string) $st);
check('the draft title never reaches the response', !str_contains($body, 'Router DRAFT fixture'));

// A draft must also be absent from listings, not merely unaddressable.
$listed = array_column($router->match('GET', '/')['vars']['posts'], 'slug');
check('the draft is absent from the home listing', !in_array($draftSlug, $listed, true));
check('the trashed post is absent from the home listing', !in_array($trashSlug, $listed, true));

// A post whose published_at has not arrived is status='published' but still
// private. Every listing route must apply the same future-date guard that the
// single-post route applies, or tomorrow's posts appear on today's home page.
check('the future post is absent from the home listing', !in_array($futureSlug, $listed, true));

$found = $router->match('GET', '/search', ['q' => 'FUTURE'])['vars'];
$hits  = array_column($found['posts'] ?? [], 'slug');
check('the future post is absent from search', !in_array($futureSlug, $hits, true), json_encode($hits));

$tagPosts = array_column(
    $router->match('GET', '/tag/' . $PREFIX . 'emptytag')['vars']['posts'],
    'slug'
);
check('a draft is absent from its tag archive', $tagPosts === [], json_encode($tagPosts));

// And the tag that carries both the published and the future post must show
// only the published one.
$tagged = array_column(
    $router->match('GET', '/tag/' . $PREFIX . 'tag')['vars']['posts'],
    'slug'
);
check(
    'the future post is absent from its tag archive',
    !in_array($futureSlug, $tagged, true),
    json_encode($tagged)
);
check(
    'the published post is present in its tag archive',
    in_array($publishedSlug, $tagged, true),
    json_encode($tagged)
);

echo "\nRouter — HTTP responses\n";

// --- status codes and content types ---------------------------------------
[$st, $ct, $body] = http($router, '/');
check('/ returns 200', $st === 200, (string) $st);
check('/ is text/html; charset=utf-8', $ct === 'text/html; charset=utf-8', $ct);
check('/ renders a full document', str_starts_with(ltrim($body), '<!doctype html>'), substr($body, 0, 60));
check('/ lists the published fixture', str_contains($body, 'Router published fixture'));

[$st, $ct, ] = http($router, '/' . $publishedSlug);
check('a published post returns 200', $st === 200, (string) $st);
check('a post is text/html', $ct === 'text/html; charset=utf-8', $ct);

[$st, $ct, $body] = http($router, '/no-such-thing-' . bin2hex(random_bytes(3)));
check('an unknown path returns 404', $st === 404, (string) $st);
check('a 404 still returns HTML', $ct === 'text/html; charset=utf-8', $ct);
check('a 404 renders the theme 404 template', str_contains($body, 'Not found'), substr($body, 0, 200));

// POST must not render a page as if it were a read endpoint.
[$st, , ] = http($router, '/', [], 'POST');
check('POST returns 405, not a rendered page', $st === 405, (string) $st);

// HEAD carries the headers of the GET but no body.
$headReq = (new ServerRequestFactory())->createServerRequest('HEAD', '/' . $publishedSlug);
$headRes = $router->handle($headReq);
$headRes->getBody()->rewind();
check('HEAD returns 200 with no body',
    $headRes->getStatusCode() === 200 && (string) $headRes->getBody() === '',
    $headRes->getStatusCode() . ' body=' . strlen((string) $headRes->getBody()));

echo "\nRouter — static assets\n";

[$st, $ct, $body] = http($router, '/assets/css/site.css');
check('the theme stylesheet is served', $st === 200, (string) $st);
check('a .css asset gets text/css', str_starts_with($ct, 'text/css'), $ct);
check('the stylesheet body is not empty', $body !== '' && strlen($body) > 100, (string) strlen($body) . ' bytes');

[$st, ] = http($router, '/assets/nope.css');
check('a missing asset returns 404, not a rendered page', $st === 404, (string) $st);

echo "\nRouter — traversal safety\n";

// Every one of these is a real traversal attempt. They must all resolve to a
// 404 — never a 200 carrying a file from outside the theme's assets/.
$traversals = [
    '/assets/../layout.php',
    '/assets/../../config/database.php',
    '/assets/..%2F..%2Fconfig%2Fdatabase.php',
    '/assets/%2e%2e/%2e%2e/composer.json',
    '/assets/css/../../../composer.json',
    '/assets/....//composer.json',
    '/assets/css/../../../../../../etc/passwd',
];
foreach ($traversals as $attempt) {
    [$st, $ct, $body] = http($router, $attempt);
    $leaked = str_contains($body, 'DB_') || str_contains($body, '"autoload"')
        || str_contains($body, '<?php') || str_contains($body, 'root:');
    check("traversal refused: $attempt", $st === 404 && !$leaked, "$st " . substr($body, 0, 80));
}

// An absolute path to a real file must not be served either.
[$st, , $body] = http($router, '/assets/' . basename(base_path('composer.json')));
check('a bare filename outside assets is not served', $st === 404, (string) $st);

// The path helpers themselves must reject traversal segments outright.
check('segments() rejects a dot-dot segment', Router::segments('/assets/../layout.php') === []);
check('segments() rejects an encoded slash', Router::segments('/a%2Fb') === []);
check('segments() rejects a leading-dot segment', Router::segments('/.env') === []);
check('segments() keeps a legitimate slug', Router::segments('/tag/routing-notes') === ['tag', 'routing-notes']);
check('normalisePath strips a trailing slash', Router::normalisePath('/tag/php/') === '/tag/php');
check('normalisePath keeps the root', Router::normalisePath('/') === '/');

echo "\nRouter — search\n";

[$st, , $body] = http($router, '/search?q=Router+published+fixture');
check('a search for a published post returns 200', $st === 200, (string) $st);
check('the search finds the fixture', str_contains($body, 'Router published fixture'), 'no hit in body');

// FTS5 query syntax is an injection surface: these must not 500.
$hostileQueries = ['"', '*', 'NEAR(', 'a OR', '^', '(unbalanced', 'AND NOT', "\\"];
foreach ($hostileQueries as $q) {
    [$st, , ] = http($router, '/search', ['q' => $q]);
    check("hostile search query does not error: " . json_encode($q), $st === 200, (string) $st);
}

// An empty query renders the form rather than 404ing.
[$st, , ] = http($router, '/search', ['q' => '']);
check('an empty search query renders the form', $st === 200, (string) $st);

// ------------------------------------------------------------------ cleanup
// Ordered child-first: post_tags/post_categories cascade from posts, but
// removing the link rows explicitly keeps this independent of the FK pragma.
$pdo->prepare("DELETE FROM post_tags WHERE post_id IN (SELECT id FROM posts WHERE slug LIKE ? OR slug = ?)")
    ->execute([$PREFIX . '%', $numericPageSlug]);
$pdo->prepare("DELETE FROM post_categories WHERE post_id IN (SELECT id FROM posts WHERE slug LIKE ?)")
    ->execute([$PREFIX . '%']);
$pdo->prepare("DELETE FROM posts WHERE slug LIKE ? OR slug = ?")
    ->execute([$PREFIX . '%', $numericPageSlug]);
$pdo->prepare("DELETE FROM tags WHERE slug LIKE ?")
    ->execute([$PREFIX . '%']);
$pdo->prepare("DELETE FROM categories WHERE slug LIKE ?")
    ->execute([$PREFIX . '%']);
$pdo->prepare("DELETE FROM users WHERE username LIKE ?")
    ->execute([$PREFIX . '%']);

// Re-sync the FTS index so the external-content table stops referencing the
// rows just deleted. Without this the index holds dangling rowids, which
// would surface as phantom hits in a later search.
$pdo->exec("INSERT INTO posts_fts(posts_fts) VALUES ('rebuild')");

$countLike = static function (PDO $pdo, string $table, string $prefix): int {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM $table WHERE slug LIKE ?");
    $stmt->execute([$prefix . '%']);
    return (int) $stmt->fetchColumn();
};

$leftPosts = $countLike($pdo, 'posts', $PREFIX);
check('cleanup left no fixture posts behind', $leftPosts === 0, "({$leftPosts} left)");

$leftTags = $countLike($pdo, 'tags', $PREFIX);
check('cleanup left no fixture tags behind', $leftTags === 0, "({$leftTags} left)");

$leftCats = $countLike($pdo, 'categories', $PREFIX);
check('cleanup left no fixture categories behind', $leftCats === 0, "({$leftCats} left)");

$userStmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE username LIKE ?');
$userStmt->execute([$PREFIX . '%']);
$leftUsers = (int) $userStmt->fetchColumn();
check('cleanup left no fixture users behind', $leftUsers === 0, "({$leftUsers} left)");

// The FTS index must not still be pointing at the deleted rows.
$stale = (int) $pdo->query(
    "SELECT COUNT(*) FROM posts_fts f
      LEFT JOIN posts p ON p.id = f.rowid
      WHERE p.id IS NULL"
)->fetchColumn();
check('the FTS index holds no dangling rows after cleanup', $stale === 0, "({$stale} stale)");

echo "\n  {$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);