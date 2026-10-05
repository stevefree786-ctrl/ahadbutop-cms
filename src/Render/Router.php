<?php
declare(strict_types=1);

namespace CMS\Render;

use CMS\Database\Connection;
use CMS\Repository\MediaRepository;
use CMS\Repository\PostRepository;
use CMS\Repository\RedirectRepository;
use CMS\Repository\SettingsRepository;
use CMS\Repository\TaxonomyRepository;
use CMS\Repository\UserRepository;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ResponseFactory;

/**
 * The public site router.
 *
 * Everything a browser sees comes through here: the home listing, a post, a
 * page, taxonomy and date archives, search, theme assets, and the 404. The
 * JSON API lives on /api/v1 and the health probe on /health; this router is
 * mounted as a CATCH-ALL *after* both, because Slim matches routes in
 * registration order and a catch-all registered first would swallow every
 * /api/v1/... call, turning JSON replies into HTML 404s.
 *
 * Two responsibilities are split so the routing table is testable without an
 * HTTP stack:
 *
 *   match()  — maps (method, path) to ['template' => …, 'vars' => …], or
 *              throws NotFoundException. Every template the theme ships is
 *              reachable through exactly one branch here.
 *   handle() — PSR-15. Turns that array into a real Response with the right
 *              status, Content-Type and body.
 *
 * Visibility is decided in the repositories, never in the templates: only
 * `published` rows are ever handed to a template, so a draft or trashed post
 * is indistinguishable from a slug that never existed — both 404.
 */
final class Router implements RequestHandlerInterface
{
    private const DEFAULT_PER_PAGE  = 10;
    private const MAX_PER_PAGE      = 50;
    private const MAX_QUERY_LENGTH  = 200;

    private Connection $db;
    private PostRepository $posts;
    private TaxonomyRepository $tags;
    private TaxonomyRepository $categories;
    private UserRepository $users;
    private SettingsRepository $settings;
    private MediaRepository $media;

    /** Built on first miss; null until a request actually consults redirects. */
    private ?RedirectRepository $redirectRepo = null;

    private ?Theme $theme = null;
    private ?ResponseFactoryInterface $responseFactory = null;

    /**
     * The request-invariant half of the shared template vars, memoised so one
     * request reads settings and the tag list once.
     */
    private array $sharedStatic = [];

    /** This request's query params, merged in by sharedVars(). */
    private array $query = [];

    /**
     * Key of the last FTS rebuild. Posts-table mtime, so a long-lived
     * process re-syncs after writes without rebuilding on every request.
     */
    private static ?int $ftsSyncedAt = null;

    public function __construct(
        ?Connection $db = null,
        ?Theme $theme = null,
        ?ResponseFactoryInterface $responseFactory = null
    ) {
        $this->db         = $db ?? new Connection(require base_path('config/database.php'));
        $this->posts      = new PostRepository($this->db);
        // TaxonomyRepository holds tags and categories in one class with a
        // swapped table, so each mode needs its own instance — a shared one
        // would answer category lookups out of the tags table.
        $this->tags       = (new TaxonomyRepository($this->db))->forTags();
        $this->categories = (new TaxonomyRepository($this->db))->forCategories();
        $this->users      = new UserRepository($this->db);
        $this->settings   = new SettingsRepository($this->db);
        $this->media      = new MediaRepository($this->db);
        $this->responseFactory = $responseFactory;
        $this->theme      = $theme;
    }

    // -----------------------------------------------------------------------
    // PSR-15 entry point
    // -----------------------------------------------------------------------

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $method = strtoupper($request->getMethod());
        $path   = self::normalisePath($request->getUri()->getPath());

        // The per-request query params; match() is also callable directly by
        // tests, which then get an empty query and the defaults.
        $this->query = $request->getQueryParams();

        // Only GET/HEAD render the site. Another verb under the catch-all is a
        // client error — falling through to a template would imply a POST
        // endpoint that does not exist.
        if ($method !== 'GET' && $method !== 'HEAD') {
            return $this->htmlResponse($request, $this->renderNotFound(), 405);
        }

        // Static theme assets are files on disk, not rendered content, so
        // they short-circuit the template layer entirely. An /assets/ path
        // that does not resolve is a hard 404 — it must never fall through to
        // slug routing, or a missing stylesheet would 200 as an empty post.
        if (str_starts_with($path, '/assets/')) {
            return $this->serveAsset($path)
                ?? $this->htmlResponse($request, $this->renderNotFound(), 404);
        }

        try {
            $route = $this->match($method, $path);
        } catch (RedirectException $redirect) {
            return $this->redirectResponse($redirect);
        } catch (NotFoundException) {
            // A miss renders the theme's 404 template, not a bare status code:
            // the visitor still gets a styled page.
            return $this->htmlResponse($request, $this->renderNotFound(), 404);
        }

        return $this->htmlResponse($request, $this->renderTemplate($route['template'], $route['vars']), 200);
    }

    /**
     * A 3xx for a stored redirect.
     *
     * The hit counter is recorded before the response is built and is
     * best-effort inside countHit(), so a locked database costs the statistic
     * rather than the redirect.
     *
     * @see RedirectException
     */
    private function redirectResponse(RedirectException $redirect): ResponseInterface
    {
        if ($redirect->id > 0) {
            $this->redirects()->countHit($redirect->id);
        }

        return $this->responseFactory()->createResponse($redirect->status)
            ->withHeader('Location', $redirect->target)
            // Caches are the usual reason a rename appears not to have
            // worked: an intermediary keeps serving the 301 after the page is
            // back at its old slug. Short max-age plus must-revalidate lets the
            // browser re-check while a bot can still cache it.
            ->withHeader('Cache-Control', 'max-age=0, must-revalidate');
    }

    /**
     * The redirect repository, built once per request.
     *
     * Lazy like the other repositories so a request that never misses a route
     * does not construct it.
     */
    private function redirects(): RedirectRepository
    {
        return $this->redirectRepo ??= new RedirectRepository($this->db);
    }

    /**
     * Map a method + path to a template and its variables.
     *
     * Throws NotFoundException when nothing matches — including when a path
     * matches syntactically but the resource does not exist or is not public
     * (a draft post, a missing tag). Both must be indistinguishable from the
     * outside: a 404 either way.
     *
     * @return array{template:string, vars:array}
     * @throws NotFoundException
     */
    public function match(string $method, string $path): array
    {
        if ($method !== 'GET' && $method !== 'HEAD') {
            throw new NotFoundException();
        }

        $parts = self::segments($path);

        // `/` — the paginated home listing.
        if ($parts === []) {
            return ['template' => 'home', 'vars' => $this->homeVars($this->pageNumber())];
        }

        $head = $parts[0];
        $rest = array_slice($parts, 1);

        if ($head === 'page') {
            return $this->pagePrefixRoute($rest);
        }
        if ($head === 'tag') {
            return $this->termRoute($this->tags, $rest, 'tag');
        }
        if ($head === 'category') {
            return $this->termRoute($this->categories, $rest, 'category');
        }
        if ($head === 'author') {
            return $this->authorRoute($rest);
        }
        if ($head === 'search') {
            if ($rest !== []) {
                throw new NotFoundException();
            }
            return $this->searchRoute();
        }
        if ($head === 'archive') {
            return $this->archiveRoute($rest);
        }

        // More than one segment cannot be a post slug (slugs are a single
        // segment) and matched none of the prefixes above.
        if ($rest !== []) {
            $this->maybeRedirect($path);
            throw new NotFoundException();
        }

        // `/<slug>` — a published post.
        try {
            return $this->postRoute($head);
        } catch (NotFoundException) {
            // The slug is free. Before 404ing, ask whether it used to be a page
            // that has since been renamed — that is the whole point of the
            // redirects table, and this is the only place a renamed slug can
            // still be recognised.
            $this->maybeRedirect($path);
            throw new NotFoundException();
        }
    }

    /**
     * Throw a RedirectException if $path has a stored redirect.
     *
     * Called from the miss paths only — a live page is never redirected away,
     * and a redirect is never allowed to shadow a route that matched.
     *
     * @throws RedirectException
     */
    private function maybeRedirect(string $path): void
    {
        $row = $this->redirects()->find($path);

        if ($row === null) {
            return;
        }

        throw new RedirectException(
            target: (string) $row['target_path'],
            status: (int) ($row['status_code'] ?? 301),
            id: (int) ($row['id'] ?? 0)
        );
    }

    // -----------------------------------------------------------------------
    // Per-route builders
    // -----------------------------------------------------------------------

    /**
     * Resolve the `/page/...` collision deliberately.
     *
     * `/page/{n}` (pagination of the home listing) and `/page/{slug}` (a
     * page) share a prefix, so they cannot be told apart by prefix alone.
     * The rule, in order:
     *
     *   1. No segment            -> page 1 of the listing.
     *   2. An all-digit segment  -> pagination, PROVIDED that page of the
     *      listing actually exists. A digit is only read as pagination when
     *      the home listing has that many pages; otherwise we fall through.
     *   3. Anything else        -> a page slug.
     *
     * The existence check in (2) is what makes the ambiguity harmless: the
     * digit branch is a *fallback*, never a preempt. So a page genuinely
     * slugs "2024" still renders as a page on a site with only one page of
     * posts, and renders as page 2 of the listing on a site with three —
     * where "/page/2" as a page slug would be an unusual URL nobody links to,
     * while "/page/2" as pagination is what a visitor expects. The listing
     * wins the tie; that is the deliberate call.
     *
     * @param string[] $rest segments after /page/
     * @return array{template:string, vars:array}
     */
    private function pagePrefixRoute(array $rest): array
    {
        if ($rest === []) {
            return ['template' => 'home', 'vars' => $this->homeVars(1)];
        }
        if (count($rest) !== 1) {
            throw new NotFoundException();
        }

        $segment = $rest[0];

        if (ctype_digit($segment) && (int) $segment >= 1) {
            $page = (int) $segment;
            if ($page <= $this->lastHomePage()) {
                return ['template' => 'home', 'vars' => $this->homeVars($page)];
            }
            // Past the end of the listing: try it as a page slug before
            // giving up, so /page/99 still 200s if such a page exists.
        }

        return $this->pageRoute($segment);
    }

    /**
     * Variables for the home listing at $page (1-based).
     *
     * @return array<string,mixed>
     */
    private function homeVars(int $page): array
    {
        $page = max(1, $page);
        $per  = $this->perPage();

        // Past the end is a miss, not an empty list — a broken pagination link
        // should 404 rather than render a hollow page-99.
        if ($page > $this->lastHomePage()) {
            throw new NotFoundException();
        }

        $result = $this->posts->paginate(
            // 'public' => true, not just status => 'published': a post dated
            // for next month is already 'published', and listing it here would
            // publish tomorrow's front page today. See PostRepository::paginate().
            ['status' => 'published', 'type' => 'post', 'order' => 'recent', 'public' => true],
            $per,
            ($page - 1) * $per
        );

        $shared = $this->sharedVars();
        return $shared + [
            'posts'      => $this->decorateAll($result['items']),
            'pagination' => $this->pagination($page, max(1, (int) $result['pages'])),
            'title'      => $shared['site']['title'],
            'intro'      => '',
            'introTitle' => 'Latest',
            'bodyClass'  => 'home',
            'ogType'     => 'website',
        ];
    }

    /**
     * `/page/{slug}` — a published page.
     *
     * @return array{template:string, vars:array}
     */
    private function pageRoute(string $slug): array
    {
        // Only `published` pages are reachable. A draft page 404s for the
        // public exactly like a slug that does not exist.
        $page = $this->posts->findPublishedBySlug($slug, 'page');
        if ($page === null) {
            // A page whose slug was changed still answers here, so renaming a
            // page keeps the old URL alive instead of breaking every link to
            // it. Rename and the two are one operation.
            $this->maybeRedirect('/page/' . $slug);
            throw new NotFoundException();
        }

        $shared = $this->sharedVars();
        $page   = $this->decorateOne($page);

        return [
            'template' => $this->templateFor($page, 'page'),
            'vars'     => $shared + [
                'post'            => $page,
                'title'           => $page['title'],
                'metaDescription' => $page['excerpt'] ?: $shared['site']['description'],
                'bodyClass'       => 'page',
                'ogType'          => 'article',
            ],
        ];
    }

    /**
     * Which template renders this row.
     *
     * The posts.template column was written by the admin API and length-checked
     * on the way in, but both routes hardcoded 'post'/'page' here and never read
     * it — so per-post layout overrides were stored and then ignored.
     *
     * Trust is bounded in two places. The name is reduced to characters that
     * cannot express a path, and TemplateEngine::resolve() independently
     * re-sanitises, confirms the resolved file is inside the theme directory,
     * and falls back to 'default' when it is not. So a hostile value here
     * degrades to the default template rather than reaching the filesystem.
     */
    private function templateFor(array $row, string $fallback): string
    {
        $t = trim((string) ($row['template'] ?? ''));
        $t = preg_replace('/[^a-z0-9_\/-]/i', '', $t) ?? '';

        return $t !== '' ? $t : $fallback;
    }

    /**
     * `/<slug>` — a published post by slug.
     *
     * @return array{template:string, vars:array}
     */
    private function postRoute(string $slug): array
    {
        $post = $this->posts->findPublishedBySlug($slug, 'post');
        if ($post === null) {
            throw new NotFoundException();
        }

        $shared = $this->sharedVars();
        $post   = $this->decorateOne($post);

        return [
            'template' => $this->templateFor($post, 'post'),
            'vars'     => $shared + [
                'post'            => $post,
                'title'           => $post['title'],
                'metaDescription' => $post['excerpt'] ?: $shared['site']['description'],
                'bodyClass'       => 'single',
                'ogType'          => 'article',
            ],
        ];
    }

    /**
     * `/tag/{slug}` or `/category/{slug}`.
     *
     * @param TaxonomyRepository $repo tags or categories, already switched
     * @param string[]           $rest segments after the prefix
     * @return array{template:string, vars:array}
     */
    private function termRoute(TaxonomyRepository $repo, array $rest, string $kind): array
    {
        if (count($rest) !== 1) {
            throw new NotFoundException();
        }

        $term = $repo->findBySlug($rest[0]);
        if ($term === null) {
            throw new NotFoundException();
        }

        $page   = $this->pageNumber();
        $result = $repo->publishedPostsFor((int) $term['id'], $this->perPage(), ($page - 1) * $this->perPage());

        return [
            'template' => 'archive',
            'vars'     => $this->sharedVars() + [
                'heading'     => (string) $term['name'],
                'description' => (string) ($term['description'] ?? ''),
                'posts'       => $this->decorateAll($result['items']),
                'count'       => (int) $result['total'],
                'title'       => (string) $term['name'],
                'bodyClass'   => 'archive taxonomy-' . $kind,
                'ogType'      => 'website',
            ],
        ];
    }

    /**
     * `/author/{username}` — a public author archive.
     *
     * @param string[] $rest segments after /author/
     * @return array{template:string, vars:array}
     */
    private function authorRoute(array $rest): array
    {
        if (count($rest) !== 1) {
            throw new NotFoundException();
        }

        // findPublicByUsername excludes suspended accounts: a suspended
        // author's byline has no business on a public page.
        $author = $this->users->findPublicByUsername($rest[0]);
        if ($author === null) {
            throw new NotFoundException();
        }

        $page   = $this->pageNumber();
        $result = $this->posts->paginate(
            [
                'status'   => 'published',
                'type'     => 'post',
                'author_id' => (int) $author['id'],
                'order'    => 'recent',
            ],
            $this->perPage(),
            ($page - 1) * $this->perPage()
        );

        $label = (string) ($author['display_name'] ?: $author['username']);

        return [
            'template' => 'archive',
            'vars'     => $this->sharedVars() + [
                'heading'     => $label,
                'description' => 'Posts by ' . $label,
                'posts'       => $this->decorateAll($result['items']),
                'count'       => (int) $result['total'],
                'title'       => $label,
                'bodyClass'   => 'archive author',
                'ogType'      => 'website',
            ],
        ];
    }

    /**
     * `/search?q=...` — full-text search over published posts.
     *
     * An empty query is not a miss: it renders the search page with no
     * results (the template shows just the box). A query the FTS layer cannot
     * use also yields zero results rather than a 500 — hostile input like
     * `q="` or `q=*` must not be able to take the route down.
     *
     * @return array{template:string, vars:array}
     */
    private function searchRoute(): array
    {
        $shared = $this->sharedVars();
        $query  = trim((string) ($shared['query']['q'] ?? ''));
        if (!is_scalar($shared['query']['q'] ?? null)) {
            $query = '';
        }
        if (mb_strlen($query) > self::MAX_QUERY_LENGTH) {
            $query = mb_substr($query, 0, self::MAX_QUERY_LENGTH);
        }

        $result = $query === ''
            ? ['items' => [], 'total' => 0]
            : $this->posts->search($query, $this->perPage());

        return [
            'template' => 'search',
            'vars'     => $shared + [
                'query'     => $query,
                'posts'     => $this->decorateAll($result['items']),
                'total'     => (int) $result['total'],
                'title'     => $query === '' ? 'Search' : 'Search: ' . $query,
                'bodyClass' => 'search',
                'ogType'    => 'website',
            ],
        ];
    }

    /**
     * `/archive/{yyyy}/{mm}` — posts published in a given month.
     *
     * @param string[] $rest segments after /archive/
     * @return array{template:string, vars:array}
     */
    private function archiveRoute(array $rest): array
    {
        // Exactly two numeric segments, a four-digit year and a real month.
        // Validating here means the repository only ever receives integers in
        // range, and a malformed archive URL is a plain 404.
        if (count($rest) !== 2
            || !ctype_digit($rest[0]) || strlen($rest[0]) !== 4
            || !ctype_digit($rest[1]) || (int) $rest[1] < 1 || (int) $rest[1] > 12
        ) {
            throw new NotFoundException();
        }

        $year   = (int) $rest[0];
        $month  = (int) $rest[1];
        $result = $this->posts->publishedInMonth($year, $month, $this->perPage());

        // No posts in that month is still a 200 archive page: an empty date
        // archive is a legitimate answer, not a missing route.
        $label = date('F Y', mktime(0, 0, 0, $month, 1, $year));

        return [
            'template' => 'archive',
            'vars'     => $this->sharedVars() + [
                'heading'     => $label,
                'description' => 'Archive for ' . $label,
                'posts'       => $this->decorateAll($result['items']),
                'count'       => (int) $result['total'],
                'title'       => $label,
                'bodyClass'   => 'archive',
                'ogType'      => 'website',
            ],
        ];
    }

    // -----------------------------------------------------------------------
    // Path and query normalisation
    // -----------------------------------------------------------------------

    /**
     * Normalise a raw path to a canonical, slash-delimited form.
     *
     * Strips NUL bytes (which truncate strings in C-level path functions),
     * collapses duplicate slashes, and removes a trailing slash except at
     * root. Decoding happens per-segment in segments(), after splitting, so a
     * %2F inside a slug cannot manufacture a new path segment.
     */
    public static function normalisePath(string $path): string
    {
        $path = str_replace("\0", '', $path);
        $path = preg_replace('#/{2,}#', '/', $path) ?? $path;
        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }
        return $path === '' ? '/' : $path;
    }

    /**
     * Split a path into decoded, validated, non-empty segments.
     *
     * This is the traversal defence for URL handling: each segment is
     * rawurl-decoded and then re-checked against a strict slug charset, so
     * `%2e%2e%2f`, `..`, backslashes and NUL are all rejected before they can
     * be joined into anything. Returns [] for a malformed path, which every
     * caller treats as "matched nothing" — a 404, never a filesystem read.
     *
     * @return string[]
     */
    public static function segments(string $path): array
    {
        $path = self::normalisePath($path);
        if ($path === '/') {
            return [];
        }

        $out = [];
        foreach (explode('/', trim($path, '/')) as $raw) {
            if ($raw === '') {
                continue;
            }
            $seg = rawurldecode($raw);
            if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $seg)) {
                // One bad segment makes the whole path unmatchable.
                return [];
            }
            $out[] = $seg;
        }
        return $out;
    }

    /**
     * Read a positive page number from the query string, defaulting to 1.
     */
    private function pageNumber(): int
    {
        $raw = $this->query['page'] ?? '1';
        if (!is_scalar($raw)) {
            return 1;
        }
        $n = (int) $raw;
        return $n >= 1 ? $n : 1;
    }

    /** Posts per page, clamped to a sane range. */
    private function perPage(): int
    {
        $per = (int) ($this->sharedVars()['perPage'] ?? self::DEFAULT_PER_PAGE);
        return $per >= 1 ? min($per, self::MAX_PER_PAGE) : self::DEFAULT_PER_PAGE;
    }

    /** How many pages the home listing currently has (at least 1). */
    private function lastHomePage(): int
    {
        $per   = $this->perPage();
        $total = $this->posts->count("status = 'published' AND type = 'post'");
        return max(1, (int) ceil($total / $per));
    }

    /** Pagination links the home template renders. */
    private function pagination(int $page, int $pages): array
    {
        $page  = max(1, $page);
        $pages = max(1, $pages);
        return [
            'page'     => $page,
            'pages'    => $pages,
            'prev_url' => $page > 1     ? '/page/' . ($page - 1) : '#',
            'next_url' => $page < $pages ? '/page/' . ($page + 1) : '#',
        ];
    }

    // -----------------------------------------------------------------------
    // Template variables
    // -----------------------------------------------------------------------

    /**
     * Variables every template receives: site metadata, nav, query.
     *
     * The static half is cached on the instance so one request reads settings
     * and the tag list once; the query is re-read per call because it is
     * per-request state and match() may be called without handle().
     *
     * @return array<string,mixed>
     */
    private function sharedVars(): array
    {
        if ($this->sharedStatic === []) {
            $cfg = $this->settings->resolved();

            $this->sharedStatic = [
                'site'     => [
                    'title'       => (string) ($cfg['site_title'] ?? 'Untitled Site'),
                    'description' => (string) ($cfg['site_description'] ?? ''),
                    'url'         => (string) ($cfg['site_url'] ?? ''),
                ],
                'perPage'  => (int) ($cfg['posts_per_page'] ?? self::DEFAULT_PER_PAGE),
                'navItems' => $this->navItems(),
                'lang'     => 'en',
            ];
        }

        return $this->sharedStatic + ['query' => $this->query];
    }

    /**
     * Nav links from the site's own published pages, ordered by menu_order.
     *
     * Previously this synthesised the header from the eight most-used tags, so
     * the navigation was a list of topics nobody had authored and could not be
     * reordered. menu_order was written by the API and read by nothing; this is
     * the read that gives it meaning.
     *
     * Tag links are still appended, after the pages. Taxonomy is genuinely
     * useful navigation on a blog, but it must not displace author intent --
     * so pages lead, and the tag block only appears once there are pages, or
     * on its own on a site that genuinely has none.
     *
     * @return array<int,array{label:string, url:string}>
     */
    private function navItems(): array
    {
        $items = [];

        foreach ($this->posts->navPages() as $page) {
            // The home page is already a separate, always-present link in the
            // layout; listing it again would duplicate it in the header.
            if ($page['slug'] === 'home' || $page['slug'] === '') {
                continue;
            }
            $url = $this->urlFor($page['slug'], 'page');
            if ($url === '') {
                continue;
            }
            $items[] = [
                'label' => $page['title'],
                'url'   => $url,
            ];
        }

        if ($items === []) {
            // A fresh install has no pages yet. Rather than an empty header,
            // fall back to the topic list this used to be, then to search.
            foreach ($this->tags->all(8) as $tag) {
                $items[] = [
                    'label' => (string) $tag['name'],
                    'url'   => '/tag/' . rawurlencode((string) $tag['slug']),
                ];
            }
        }

        if ($items === []) {
            $items[] = ['label' => 'Search', 'url' => '/search'];
        }

        return $items;
    }

    /**
     * The URL a page is served at.
     *
     * A flat slug is "/page/{slug}" (pageRoute()). A nested slug such as
     * "docs/getting-started" has no route at all — pagePrefixRoute() rejects
     * anything with more than one segment after /page/ — so it cannot be
     * linked. Excluding those from the nav is the honest option: rendering a
     * link that 404s is worse than omitting a page the author can still put
     * in the header once the hierarchy is routable.
     */
    private function urlFor(string $slug, string $type): string
    {
        $clean = ltrim($slug, '/');

        return str_contains($clean, '/') ? '' : '/' . $type . '/' . $clean;
    }

    /**
     * Attach the fields a card/post template reads that the posts table does
     * not return directly: author name, tags, rendered body, featured image.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function decorateAll(array $rows): array
    {
        foreach ($rows as $i => $row) {
            $rows[$i] = $this->decorateOne($row);
        }
        return $rows;
    }

    /**
     * @param array<string,mixed> $post
     * @return array<string,mixed>
     */
    private function decorateOne(array $post): array
    {
        $post['author_name'] = $this->authorName($post['author_id'] ?? null);
        $post['tags']        = $this->tags->forPost((int) ($post['id'] ?? 0));
        $post['body_html']   = $this->renderBody($post);

        $featured = $this->featuredImage($post);
        $post['featured_image_url'] = $featured['url'];
        $post['featured_alt']       = $featured['alt'];
        $post['featured_caption']   = $featured['caption'];

        if (empty($post['excerpt'])) {
            $post['excerpt'] = Markdown::excerpt((string) ($post['body_md'] ?? ''));
        }

        return $post;
    }

    /** Render the post body to safe HTML (the body_html cache wins when present). */
    private function renderBody(array $post): string
    {
        $cached = (string) ($post['body_html'] ?? '');
        return $cached !== ''
            ? $cached
            : Markdown::render((string) ($post['body_md'] ?? ''));
    }

    /** Display name (or username) for an author id, '' when unknown. */
    private function authorName(mixed $authorId): string
    {
        if ($authorId === null || (int) $authorId <= 0) {
            return '';
        }
        $author = $this->users->find((int) $authorId);
        return $author === null ? '' : (string) ($author['display_name'] ?: $author['username']);
    }

    /**
     * Resolve a post's featured media to a safe public URL plus alt/caption.
     *
     * The URL passes through Markdown::safeUrl(), so a `javascript:` value in
     * the media row can never reach an <img src> — the media table is
     * populated by uploads, which are less trusted than a human author.
     *
     * @param array<string,mixed> $post
     * @return array{url:string, alt:string, caption:string}
     */
    private function featuredImage(array $post): array
    {
        $blank = ['url' => '', 'alt' => '', 'caption' => ''];

        $id = $post['featured_media_id'] ?? null;
        if ($id === null || (int) $id <= 0) {
            return $blank;
        }

        $media = $this->media->find((int) $id);
        if ($media === null) {
            return $blank;
        }

        return [
            'url'     => Markdown::safeUrl((string) $media['url']) ?? '',
            'alt'     => (string) ($media['alt'] ?? ''),
            'caption' => (string) ($media['caption'] ?? ''),
        ];
    }

    // -----------------------------------------------------------------------
    // Rendering and response
    // -----------------------------------------------------------------------

    /** The active theme, loaded once, falling back to 'default'. */
    private function theme(): Theme
    {
        if ($this->theme === null) {
            $name  = (string) ($this->settings->get('theme', 'default') ?? 'default');
            $theme = new Theme(Theme::sanitiseName($name));
            $this->theme = $theme->exists() ? $theme : new Theme('default');
        }
        return $this->theme;
    }

    /** Render a named template through the active theme's engine. */
    private function renderTemplate(string $template, array $vars): string
    {
        return (new TemplateEngine($this->theme()))->render($template, $vars);
    }

    /** The theme's 404 body, used for every miss. */
    private function renderNotFound(): string
    {
        return $this->renderTemplate('404', $this->sharedVars() + [
            'title'     => 'Not found',
            'bodyClass' => 'error-404',
            'ogType'    => 'website',
        ]);
    }

    /**
     * Wrap rendered HTML in a response with the right status and Content-Type.
     */
    private function htmlResponse(ServerRequestInterface $request, string $html, int $status): ResponseInterface
    {
        $factory  = $this->responseFactory();
        $response = $factory->createResponse($status)
            ->withHeader('Content-Type', 'text/html; charset=utf-8');

        // A HEAD reply carries the headers of the GET but no body.
        if ($request->getMethod() !== 'HEAD') {
            $response->getBody()->write($html);
        }

        return $response;
    }

    // -----------------------------------------------------------------------
    // Static theme assets
    // -----------------------------------------------------------------------

    /**
     * Serve a file from the active theme's assets/ directory.
     *
     * Returns null when the path is not an asset request OR when the asset
     * does not resolve inside the theme — null means "not mine to serve", and
     * the caller lets the request fall through to routing (and then the 404).
     * An asset request that does not resolve must NOT be routed as a post
     * slug, so handle() re-checks and 404s explicitly below.
     *
     * Traversal defence is the same realpath-containment technique
     * TemplateEngine::pathFor() uses: build the candidate, realpath() it, and
     * confirm the resolved file really lives under the assets directory.
     * A `..` that escapes is rejected rather than clamped.
     */
    private function serveAsset(string $path): ?ResponseInterface
    {
        if (!str_starts_with($path, '/assets/')) {
            return null;
        }

        $relative = substr($path, strlen('/assets/'));
        if ($relative === '') {
            return null;
        }

        // Defence in depth, before touching the filesystem: segments() already
        // rejects `..`, but this is cheap and keeps the guarantee local to the
        // method that does path joins.
        if (str_contains($relative, "\0") || str_contains($relative, '..')) {
            return null;
        }

        $base = $this->theme()->path('assets');
        $root = realpath($base);
        if ($root === false) {
            return null;
        }

        $real = realpath($base . DIRECTORY_SEPARATOR . $relative);
        if ($real === false || !str_starts_with($real, $root . DIRECTORY_SEPARATOR) || !is_file($real)) {
            return null;
        }

        $body     = (string) file_get_contents($real);
        $response = $this->responseFactory()->createResponse(200)
            ->withHeader('Content-Type', self::mimeFor($real))
            ->withHeader('Content-Length', (string) strlen($body))
            // Assets are content-hashed by mtime upstream in most deployments;
            // a short max-age keeps a theme edit from needing a hard refresh
            // without letting a stale asset stick for long.
            ->withHeader('Cache-Control', 'public, max-age=300');
        $response->getBody()->write($body);

        return $response;
    }

    /**
     * Guess a MIME type from a file extension — an allow-list, not a
     * blacklist. An unknown extension is octet-stream, so a stray `.php` or
     * `.phtml` under assets is never handed to a browser as something
     * executable. Only known-static types are served at all.
     */
    private static function mimeFor(string $file): string
    {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

        return match ($ext) {
            'css'          => 'text/css; charset=utf-8',
            'js', 'mjs'    => 'text/javascript; charset=utf-8',
            'json', 'map'  => 'application/json',
            'webmanifest'  => 'application/manifest+json',
            'svg'          => 'image/svg+xml',
            'png'          => 'image/png',
            'jpg', 'jpeg'  => 'image/jpeg',
            'gif'          => 'image/gif',
            'webp'         => 'image/webp',
            'avif'         => 'image/avif',
            'ico'          => 'image/x-icon',
            'woff'         => 'font/woff',
            'woff2'        => 'font/woff2',
            'ttf'          => 'font/ttf',
            'otf'          => 'font/otf',
            'eot'          => 'application/vnd.ms-fontobject',
            'txt', 'md'    => 'text/plain; charset=utf-8',
            default        => 'application/octet-stream',
        };
    }

    /** Lazily obtain a PSR-17 response factory (Slim ships one). */
    private function responseFactory(): ResponseFactoryInterface
    {
        return $this->responseFactory ??= new ResponseFactory();
    }
}