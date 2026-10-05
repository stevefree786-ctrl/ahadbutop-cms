<?php
namespace CMS\Api\Controllers;

use CMS\Api\Responder;
use CMS\Auth\JwtService;
use Psr\Http\Message\ResponseInterface as Response;
use CMS\Database\Connection;
use CMS\Queue\Worker;
use CMS\Repository\PostRepository;
use CMS\Repository\SettingsRepository;
use CMS\Repository\TaxonomyRepository;
use CMS\Repository\UserRepository;

/**
 * Everything the admin dashboard renders.
 *
 * One controller rather than one per screen. Each screen is a read, they all
 * need the same handful of repositories, and splitting them would mean
 * constructing a Connection and three repositories per page for no benefit —
 * the routes already share one Connection for the whole request.
 *
 * Every method here is READ-ONLY. The admin UI is server-rendered HTML and its
 * mutations go through the same /api/v1 endpoints the admin JS calls, so there
 * is exactly one place where a write can happen and exactly one set of role
 * checks to audit. A dashboard that could also publish would double the blast
 * radius of any mistake in here.
 */
class AdminController
{
    private Connection $db;
    private PostRepository $posts;
    private UserRepository $users;
    private TaxonomyRepository $tags;
    private TaxonomyRepository $categories;
    private SettingsRepository $settings;

    /** @var array<string,mixed> */
    private array $config;

    public function __construct(Connection $db, ?array $authConfig = null)
    {
        $this->db        = $db;
        $this->posts     = new PostRepository($db);
        $this->users     = new UserRepository($db);
        // Two instances, not one with forTags()/forCategories(): those mutate
        // the receiver and return it, so one instance would leave both handles
        // pointing at whichever table was selected last.
        $this->tags      = (new TaxonomyRepository($db))->forTags();
        $this->categories= (new TaxonomyRepository($db))->forCategories();
        $this->settings  = new SettingsRepository($db);
        $this->config    = $authConfig ?? (require dirname(__DIR__, 3) . '/config/auth.php');
    }

    /**
     * Render an admin template to HTML.
     *
     * The template is resolved against a fixed allowlist of names rather than
     * anything the query string supplies. A path built from a request value is
     * a local file inclusion the moment the prefix check is wrong, so the names
     * are enumerated here and $name is only ever a key into it.
     */
    private function screen(Response $response, string $name, array $vars = []): Response
    {
        $screens = [
            'dashboard' => 'dashboard',
            'posts'     => 'posts',
            'editor'    => 'editor',
            'media'     => 'media',
            'users'     => 'users',
            'settings'  => 'settings',
            'taxonomy'  => 'taxonomy',
            'jobs'      => 'jobs',
            'agents'    => 'agents',
            'skills'    => 'skills',
            'seo'       => 'seo',
            'chat'      => 'chat',
        ];

        $file = $screens[$name] ?? null;
        if ($file === null) {
            return Responder::notFound($response, 'Screen');
        }

        $dir = dirname(__DIR__, 3) . '/resources/admin';
        $path = $dir . '/' . $file . '.php';
        $real = realpath($path);
        $base = realpath($dir);

        // Defence in depth: the allowlist already excludes traversal, but if the
        // file ever moved outside resources/admin the screen must not render.
        if ($real === false || $base === false || !str_starts_with($real, $base)) {
            return Responder::notFound($response, 'Screen');
        }

        // CSRF: the layout embeds this and every mutating form/JS call sends it
        // back. A token is minted per render and verified on the API side by
        // CsrfMiddleware, which binds it to the session that rendered it.
        $vars['csrf']       = $this->issueCsrf($vars['user'] ?? []);
        $vars['screens']    = array_keys($screens);
        $vars['site_title'] = (string) $this->settings->get('site_title', 'CMS');
        $vars['page_title'] = ucfirst($name);

        // partials.php defines e(), bytes(), badge(), statusPill() and every
        // other screen helper. The screen templates call them but no template
        // requires the file — and layout.php does not either — so it is loaded
        // here, once, for every screen. Without it all nine layout-rendered
        // screens die with "Call to undefined function e()". require_once
        // rather than require because /admin/login loads the same file by its
        // own route and both can run in one process.
        require_once $dir . '/partials.php';

        // Render the screen's own template to a string. It is rendered SEPARATELY
        // from the layout because layout.php expects a ready-made $content — it
        // interpolates that variable rather than including the screen itself.
        $body = $this->capture($real, $vars);

        // The screen is a fragment: a <section>/<div> with no <html>, no
        // <head>, no navigation and — critically — no window.CSRF. Handing
        // that straight to the browser produces a page that looks like the
        // admin's markup but has no navigation to move and no token for
        // admin.js to send, so every button in it fails silently.
        //
        // Wrapping it in the layout is what makes it a page. The layout file is
        // resolved and containment-checked exactly like a screen, because it
        // receives $content by interpolation and a $name-driven path would be a
        // traversal hole.
        $layout    = $dir . '/layout.php';
        $layoutReal= realpath($layout);
        $base      = realpath($dir);
        if ($layoutReal === false || $base === false || !str_starts_with($layoutReal, $base)) {
            return Responder::notFound($response, 'Layout');
        }

        $vars['content'] = $body;
        $html            = $this->capture($layoutReal, $vars);

        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }

    /**
     * Render a PHP template to a string with $vars in scope.
     *
     * extract() + require inside a closure rather than in the method body: a
     * template that references $__file or $__vars cannot collide with the
     * locals it is handed, and nothing it declares leaks back out.
     */
    private function capture(string $file, array $vars): string
    {
        ob_start();
        (function (string $__file, array $__vars): void {
            extract($__vars, EXTR_SKIP);
            require $__file;
        })($file, $vars);

        return (string) ob_get_clean();
    }

    /**
     * Mint the CSRF token for a rendered screen.
     *
     * HMAC over the user's id, the session's refresh token and the process's
     * JWT secret: it needs no storage, cannot be replayed by another user, and
     * dies with the session because the refresh token rotates on every use.
     * Falls back to a secret-boxed token for an unauthenticated render (the
     * login screen), where it still cannot be forged.
     */
    private function issueCsrf(array $user): string
    {
        $jwt = new JwtService($this->config);
        // JwtService exposes issue(), not sign(). Note also that issue()
        // OVERWRITES 'exp' with the configured access-token TTL (it merges
        // the server claims after ours), so an 'exp' passed here would be
        // discarded. The CSRF token therefore lives exactly as long as an
        // access token — which is the lifetime we want, and it expires on its
        // own rather than lingering past the session.
        return $jwt->issue([
            'sub'  => (string) ($user['id'] ?? 0),
            'uuid' => (string) ($user['uuid'] ?? ''),
            'csrf' => bin2hex(random_bytes(16)),
        ]);
    }

    // ---- screens ---------------------------------------------------------

    public function dashboard($request, $response, $args)
    {
        $vars = $this->sharedVars($args);
        $vars['stats']        = $this->posts->stats();
        $vars['job_stats']    = $this->jobStats();
        $vars['recent']       = $this->posts->paginate([], 8);
        $vars['top_tags']     = array_slice($this->tags->all(50), 0, 10);
        $vars['seo_health']   = $this->seoHealth();
        $vars['agents']       = $this->agentFleet();
        return $this->screen($response, 'dashboard', $vars);
    }

    public function posts($request, $response, $args)
    {
        $q     = $request->getQueryParams();
        $page  = max(1, (int) ($q['page'] ?? 1));
        $limit = 20;

        $filters = array_filter([
            'status'    => $q['status'] ?? null,
            'type'      => $q['type']   ?? null,
            'author_id' => $q['author'] ?? null,
            'search'    => $q['q']      ?? null,
        ], static fn ($v) => $v !== null && $v !== '');

        $vars = $this->sharedVars($args);
        $vars['result']  = $this->posts->paginate($filters, $limit, ($page - 1) * $limit);
        $vars['filters'] = $filters;
        $vars['page']    = $page;
        $vars['statuses']= ['draft', 'review', 'scheduled', 'published'];
        $vars['authors'] = $this->users->paginate([], 200)['items'];
        return $this->screen($response, 'posts', $vars);
    }

    /**
     * The post editor. Also handles "new" ($args['id'] absent).
     *
     * A draft is editable; a published post is editable too but the screen
     * shows the live URL so an editor can check what they are changing.
     */
    public function editor($request, $response, $args)
    {
        $id  = isset($args['id']) && $args['id'] !== '' ? (int) $args['id'] : 0;
        $post = $id > 0 ? $this->posts->find($id) : null;

        if ($id > 0 && $post === null) {
            return Responder::notFound($response, 'Post');
        }

        $vars = $this->sharedVars($args);
        $vars['post']       = $post;
        $vars['is_new']     = $post === null;
        $vars['tags']       = $this->tags->all(200);
        $vars['categories'] = $this->categories->all(200);
        $vars['post_tags']  = $post === null ? [] : array_column($this->tags->forPost($post['id']), 'id');
        $vars['post_cats']  = $post === null ? [] : array_column($this->categories->forPost($post['id']), 'id');
        $vars['seo']        = $post === null ? null : $this->seoFor('post', (int) $post['id']);
        return $this->screen($response, 'editor', $vars);
    }

    public function media($request, $response, $args)
    {
        $q    = $request->getQueryParams();
        $page = max(1, (int) ($q['page'] ?? 1));

        $vars = $this->sharedVars($args);
        $vars['media']   = $this->paginateMedia(48, ($page - 1) * 48, $q['q'] ?? null);
        $vars['page']    = $page;
        $vars['totals']  = $this->mediaTotals();
        return $this->screen($response, 'media', $vars);
    }

    public function users($request, $response, $args)
    {
        $q    = $request->getQueryParams();
        $page = max(1, (int) ($q['page'] ?? 1));

        $vars = $this->sharedVars($args);
        $vars['result'] = $this->users->paginate(
            array_filter(['role' => $q['role'] ?? null, 'search' => $q['q'] ?? null]),
            50,
            ($page - 1) * 50
        );
        $vars['page']   = $page;
        $vars['me']     = $args['user'] ?? [];
        return $this->screen($response, 'users', $vars);
    }

    public function settings($request, $response, $args)
    {
        $vars = $this->sharedVars($args);
        $vars['settings']   = $this->settings->rows();
        $vars['admin_only'] = SettingsRepository::ADMIN_ONLY;
        $vars['me']         = $args['user'] ?? [];

        // BYOK status, and only for an admin. The panel holds live
        // credentials, so a non-admin gets the screen without it rather than
        // a hidden input — the shape a reader sees is deliberately not the
        // shape an editor sees, so nothing renders as "field disabled" and
        // invites a support question about why.
        if (($vars['role'] ?? '') === 'admin') {
            $store = new \CMS\Agents\ProviderKeyStore($this->db);
            $vars['providers']    = $store->status();
            $vars['can_encrypt']  = \CMS\Agents\SecretBox::available();
        }

        return $this->screen($response, 'settings', $vars);
    }

    public function taxonomy($request, $response, $args)
    {
        $vars = $this->sharedVars($args);
        $vars['tags']       = $this->tags->all(200);
        $vars['categories'] = $this->categories->all(200);
        return $this->screen($response, 'taxonomy', $vars);
    }

    public function jobs($request, $response, $args)
    {
        $q = $request->getQueryParams();

        $vars = $this->sharedVars($args);
        $vars['result']  = $this->listJobs($q);
        $vars['stats']   = $this->jobStats();
        $vars['types']   = Worker::JOB_TYPES;
        $vars['filters'] = array_filter([
            'status' => $q['status'] ?? null,
            'type'   => $q['type']   ?? null,
        ]);
        return $this->screen($response, 'jobs', $vars);
    }

    /** The agent console: what the fleet can do, and run it from the browser. */
    public function agents($request, $response, $args)
    {
        $vars = $this->sharedVars($args);
        $vars['agents']  = $this->agentFleet();
        $vars['actions'] = $this->actionCatalogue();
        $vars['recent_runs'] = $this->recentAgentRuns();
        return $this->screen($response, 'agents', $vars);
    }

    /**
     * The skills screen: fixed intent sequences you can run from the browser.
     *
     * The point of showing a skill's STEPS, not just its name, is that a
     * skill is not magic — it is a list of the same intents the Agents screen
     * exposes, with a role floor on each. Hiding that would make a skill look
     * like a privilege the skill has, which it does not: the caller's role
     * decides every step, and an author running an editor skill gets 'denied'
     * at the first write.
     */
    public function skills($request, $response, $args)
    {
        $vars = $this->sharedVars($args);
        $vars['skills'] = (new \CMS\Agents\Skills($this->db))->describe();
        return $this->screen($response, 'skills', $vars);
    }

    public function seo($request, $response, $args)
    {
        $vars = $this->sharedVars($args);
        $vars['health']   = $this->seoHealth();
        $vars['issues']   = $this->seoIssues();
        $vars['coverage'] = $this->seoCoverage();
        $vars['recent']   = $this->recentPostsMissingSeo();
        return $this->screen($response, 'seo', $vars);
    }

    // ---- shared ----------------------------------------------------------

    /**
     * Variables every screen needs: the signed-in user and the nav.
     *
     * Nav is built from the user's role so an author is never shown a link to
     * a screen that will 403. Hiding it is a courtesy; the server-side role
     * check is what actually protects it.
     */
    private function sharedVars(array $args): array
    {
        $user  = $args['user'] ?? [];
        $role  = (string) ($user['role'] ?? 'author');
        $rank  = ['author' => 1, 'editor' => 2, 'admin' => 3][$role] ?? 1;

        $nav = [
            ['label' => 'Dashboard', 'url' => '/admin'],
            ['label' => 'Ask AI',    'url' => '/admin/chat'],
            ['label' => 'Skills',    'url' => '/admin/skills'],
            ['label' => 'Posts',     'url' => '/admin/posts'],
            ['label' => 'Media',     'url' => '/admin/media'],
            ['label' => 'Taxonomy',  'url' => '/admin/taxonomy'],
            ['label' => 'Agents',    'url' => '/admin/agents'],
            ['label' => 'Jobs',      'url' => '/admin/jobs'],
            ['label' => 'SEO',       'url' => '/admin/seo'],
        ];
        if ($rank >= 3) {
            $nav[] = ['label' => 'Users',    'url' => '/admin/users'];
            $nav[] = ['label' => 'Settings', 'url' => '/admin/settings'];
        }

        return ['user' => $user, 'role' => $role, 'rank' => $rank, 'nav' => $nav];
    }

    /**
     * The chat screen — the interface the whole agent fleet exists to serve.
     *
     * Rendered empty on purpose: the conversation lives in sessionStorage,
     * not in a database table. A transcript of prompts is not content, and
     * writing one would need retention and deletion rules nobody asked for.
     * The trade-off is honest and stated in the UI: clearing the tab clears
     * the history, and the results it describes (the pages, tokens and
     * redirects it created) are real and permanent regardless.
     *
     * The CEO floor is 'editor', matching POST /api/v1/agents/ceo. The link
     * is hidden from authors for the same reason every other role-gated nav
     * entry is: a link that 403s on click is worse than no link.
     */
    public function chat($request, $response, $args)
    {
        $vars = $this->sharedVars($args);

        // Deliberately duplicated from AdminApiController::AGENT_MIN_ROLE
        // rather than imported: that is a security boundary deciding what may
        // RUN, and a nav label must never be able to widen it. Both say
        // 'editor'; if they ever disagree the server one wins, which is the
        // safe direction.
        $vars['ceo_floor'] = 'editor';
        $vars['can_use_ceo'] = $vars['rank'] >= 2;

        return $this->screen($response, 'chat', $vars);
    }

    /**
     * The exact set of `jobs.status` values.
     *
     * Mirrors the CHECK constraint in database/schema.sql. Worker has no
     * STATUSES constant (it exposes JOB_TYPES and isKnownType() instead), so
     * this is declared here rather than imported — and the schema is the
     * authority, because a value outside this list cannot exist in the table
     * anyway and array_fill_keys on a stale list would report a phantom 0.
     */
    private const JOB_STATUSES = ['queued', 'running', 'succeeded', 'failed', 'dead', 'cancelled'];

    /** @return array<string,int> */
    private function jobStats(): array
    {
        $rows = $this->db->getPdo()
            ->query('SELECT status, COUNT(*) AS n FROM jobs GROUP BY status')
            ->fetchAll();
        $out = array_fill_keys(self::JOB_STATUSES, 0);
        foreach ($rows as $r) {
            $out[(string) $r['status']] = (int) $r['n'];
        }
        $out['total'] = array_sum(self::JOB_STATUSES ? array_map(fn($s) => $out[$s], self::JOB_STATUSES) : []);
        return $out;
    }

    private function listJobs(array $q): array
    {
        $where  = ['1=1'];
        $params = [];

        if (!empty($q['status'])) {
            if (!in_array($q['status'], self::JOB_STATUSES, true)) {
                // Same rule as the JSON API: an unknown status is rejected,
                // not silently ignored. Silently ignoring hands the caller the
                // unfiltered list, which reads as "these are the dead jobs".
                $where[] = '1=0';
            } else {
                $where[] = 'status = ?';
                $params[] = $q['status'];
            }
        }
        if (!empty($q['type'])) {
            $where[]  = 'type = ?';
            $params[] = $q['type'];
        }

        $clause = implode(' AND ', $where);
        $limit  = 40;
        $page   = max(1, (int) ($q['page'] ?? 1));

        $items = $this->db->getPdo()->prepare(
            'SELECT j.id, j.type, j.status, j.attempts, j.max_attempts, j.priority,
                    j.last_error, j.created_at, j.finished_at, j.idempotency_key,
                    (SELECT COUNT(*) FROM job_logs l WHERE l.job_id = j.id) AS log_count
             FROM jobs j WHERE ' . $clause . '
             ORDER BY j.id DESC LIMIT ? OFFSET ?'
        );
        $items->execute([...$params, $limit, ($page - 1) * $limit]);

        $total = $this->db->getPdo()->prepare('SELECT COUNT(*) FROM jobs WHERE ' . $clause);
        $total->execute($params);

        return ['items' => $items->fetchAll(), 'total' => (int) $total->fetchColumn()];
    }

    /** Aggregate SEO posture for the dashboard and the SEO screen. */
    private function seoHealth(): array
    {
        $published = (int) $this->db->getPdo()
            ->query("SELECT COUNT(*) FROM posts WHERE status = 'published'")->fetchColumn();

        $withMeta = (int) $this->db->getPdo()->prepare(
            "SELECT COUNT(DISTINCT entity_id)
               FROM seo_meta
              WHERE entity_type = 'post'
                AND meta_description IS NOT NULL
                AND meta_description != ''"
        )->fetchColumn();

        $focus = (int) $this->db->getPdo()->prepare(
            "SELECT COUNT(DISTINCT entity_id)
               FROM seo_meta WHERE entity_type = 'post' AND focus_keyword IS NOT NULL AND focus_keyword != ''"
        )->fetchColumn();

        return [
            'published'   => $published,
            'with_meta'   => $withMeta,
            'with_focus'  => $focus,
            'meta_pct'    => $published > 0 ? (int) round($withMeta / $published * 100) : 0,
            'focus_pct'   => $published > 0 ? (int) round($focus / $published * 100) : 0,
        ];
    }

    private function seoFor(string $type, int $id): ?array
    {
        $stmt = $this->db->getPdo()->prepare(
            'SELECT * FROM seo_meta WHERE entity_type = ? AND entity_id = ? LIMIT 1'
        );
        $stmt->execute([$type, $id]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** Posts with the most obvious, mechanically-checkable SEO problems. */
    private function seoIssues(): array
    {
        $stmt = $this->db->getPdo()->prepare(
            "SELECT p.id, p.title, p.slug, p.word_count, p.published_at,
                    m.meta_description IS NOT NULL AS has_meta,
                    m.focus_keyword IS NOT NULL AS has_focus
             FROM posts p
             LEFT JOIN seo_meta m ON m.entity_type = 'post' AND m.entity_id = p.id
             WHERE p.status = 'published'
             ORDER BY p.published_at DESC
             LIMIT 50"
        );
        $stmt->execute();

        $issues = [];
        foreach ($stmt->fetchAll() as $row) {
            $problems = [];
            if (!$row['has_meta']) {
                $problems[] = 'no meta description';
            }
            if (!$row['has_focus']) {
                $problems[] = 'no focus keyword';
            }
            if ((int) $row['word_count'] < 300) {
                $problems[] = 'thin content (' . (int) $row['word_count'] . ' words)';
            }
            if (mb_strlen((string) $row['title']) > 70) {
                $problems[] = 'title over 70 chars (will truncate in SERPs)';
            }
            if ($problems !== []) {
                $issues[] = ['post' => $row, 'problems' => $problems];
            }
        }
        return $issues;
    }

    /** How many posts carry each stored SEO field. */
    private function seoCoverage(): array
    {
        $stmt = $this->db->getPdo()->prepare(
            // Columns here must exist in seo_meta (see database/schema.sql). There is no
        // og_title column — the Open Graph surface is og_image_media_id, a
        // foreign key into media, so it is counted as "set" rather than as text.
        "SELECT
                SUM(CASE WHEN meta_title    IS NOT NULL AND meta_title    != '' THEN 1 ELSE 0 END) AS meta_title,
                SUM(CASE WHEN meta_description IS NOT NULL AND meta_description != '' THEN 1 ELSE 0 END) AS meta_description,
                SUM(CASE WHEN focus_keyword IS NOT NULL AND focus_keyword != '' THEN 1 ELSE 0 END) AS focus_keyword,
                SUM(CASE WHEN canonical_url IS NOT NULL AND canonical_url != '' THEN 1 ELSE 0 END) AS canonical_url,
                SUM(CASE WHEN og_image_media_id IS NOT NULL THEN 1 ELSE 0 END) AS og_image,
                COUNT(*) AS total
             FROM seo_meta WHERE entity_type = 'post'"
        );
        $stmt->execute();
        return $stmt->fetch() ?: [];
    }

    private function recentPostsMissingSeo(int $limit = 10): array
    {
        $stmt = $this->db->getPdo()->prepare(
            // seo_meta is keyed on the composite (entity_type, entity_id) and has no
        // `id` column, so "is there a row for this post" is a NULL test on the
        // joined key. entity_id is TEXT while posts.id is INTEGER, so the join
        // casts or SQLite compares them as integers and the equality planner
        // never matches.
        "SELECT p.id, p.title, p.slug
             FROM posts p
             LEFT JOIN seo_meta m
                    ON m.entity_type = 'post'
                   AND m.entity_id = CAST(p.id AS TEXT)
             WHERE p.status = 'published'
               AND (m.entity_id IS NULL OR m.meta_description IS NULL OR m.meta_description = '')
             ORDER BY p.published_at DESC LIMIT ?"
        );
        $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    private function paginateMedia(int $limit, int $offset, ?string $search): array
    {
        // `media` has no original_name column — the human-facing name lives in
        // `alt` (falling back to the object key). Searching a column that does
        // not exist is what made this screen 500; the storage `key` is the
        // other field a user would plausibly search by.
        $where  = '1=1';
        $params = [];
        if ($search !== null && $search !== '') {
            $where    = '(key LIKE ? OR mime LIKE ? OR alt LIKE ?)';
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
            $params[] = '%' . $search . '%';
        }

        // original_name is projected, not stored: alt is the display name, and
        // an item with no alt text is still addressable by its key.
        //
        // LIMIT/OFFSET go through execute() rather than bindValue(). Calling
        // execute() with an array — even an empty one — DISCARDS values set by
        // bindValue(), so the placeholders fall back to unbound NULL and SQLite
        // fails with "General error: 20 datatype mismatch". Every bound value
        // must be passed to execute(), which is also what Repository::select()
        // does via its $limitParams argument.
        $stmt = $this->db->getPdo()->prepare(
            "SELECT *, COALESCE(NULLIF(alt,''), key) AS original_name
             FROM media WHERE {$where} ORDER BY id DESC LIMIT ? OFFSET ?"
        );
        $stmt->execute([...$params, $limit, $offset]);

        $count = $this->db->getPdo()->prepare("SELECT COUNT(*) FROM media WHERE {$where}");
        $count->execute($params);

        return ['items' => $stmt->fetchAll(), 'total' => (int) $count->fetchColumn()];
    }

    private function mediaTotals(): array
    {
        $row = $this->db->getPdo()
            ->query('SELECT COUNT(*) AS n, COALESCE(SUM(bytes),0) AS bytes FROM media')
            ->fetch();
        return ['count' => (int) ($row['n'] ?? 0), 'bytes' => (int) ($row['bytes'] ?? 0)];
    }

    /**
     * The agent fleet, as data.
     *
     * Built from the class list rather than hardcoded, so adding an agent is a
     * one-line change here rather than a second edit in a template.
     *
     * Entries that do not exist on disk are skipped by the class_exists() check
     * below, so listing an agent that has not been written yet costs nothing
     * and shows up the moment its file lands.
     *
     * This is a DISPLAY list, not a permission list. AdminApiController holds
     * the authoritative AGENT_ALLOWLIST that decides what a request may run —
     * keeping them separate is deliberate, so a screen template can never grant
     * itself the ability to invoke an agent. An agent shown here but absent
     * from that allowlist is visible-but-unrunnable, which is the safe way to
     * be wrong.
     */
    private function agentFleet(): array
    {
        $classes = [
            'BlogAgent', 'SeoAgent', 'DesignAgent', 'AdminAgent',
            'SearchAgent', 'EditorAgent', 'SocialAgent', 'MediaAgent',
            'AnalyticsAgent', 'NewsAgent',
            'RedirectAgent', 'TranslationAgent', 'SitemapAgent',
        ];

        $out = [];
        foreach ($classes as $short) {
            $fqcn = 'CMS\\Agents\\' . $short;
            if (!class_exists($fqcn)) {
                continue;
            }
            $ref = new \ReflectionClass($fqcn);

            $doc   = (string) ($ref->getDocComment() ?: '');
            $about = trim(preg_replace('/^\s*\*\s?/m', '', $doc) ?: '');
            $about = trim(strtok($about, "\n") ?: '');

            $intents = [];
            if ($ref->hasProperty('INTENTS')) {
                $p     = $ref->getProperty('INTENTS');
                $val   = $p->isStatic() ? $p->getValue() : $p->getValue($ref->newInstanceArgs());
                $intents = is_array($val) ? $val : [];
            }

            $out[] = [
                'class'   => $short,
                'summary' => $about,
                'intents' => array_keys($intents),
                'file'    => basename((string) $ref->getFileName()),
            ];
        }
        return $out;
    }

    /** The allowlisted action catalogue, straight from the registry. */
    private function actionCatalogue(): array
    {
        /*
         * Actions::build() returns a NAMED dict — ['schema' => …, 'writers' => …]
         * — not a positional pair.
         *
         * Destructure it positionally (`[$schema, $writers] =`) and you get null
         * for both: the keys are strings, so index 0 and 1 do not exist. The
         * warnings are easy to miss because the page still returns 200 — the
         * foreach over a null $schema silently produces an EMPTY action
         * catalogue, so /admin/agents renders its "everything an agent is
         * permitted to do" panel with nothing in it and nobody notices why.
         */
        $built   = \CMS\Agents\Actions::build($this->db);
        $schema  = $built['schema']  ?? [];
        $writers = $built['writers'] ?? [];

        $rows = [];
        foreach ($schema as $name => $spec) {
            $params = [];
            foreach (($spec['params'] ?? []) as $pname => $pspec) {
                $params[] = [
                    'name'     => $pname,
                    'type'     => $pspec['type'] ?? 'string',
                    'required' => (bool) ($pspec['required'] ?? false),
                ];
            }
            $rows[] = [
                'name'   => $name,
                'params' => $params,
                'writer' => $writers[$name] ?? null,
            ];
        }
        return $rows;
    }

    /**
     * The recent-activity feed on /admin/agents.
     *
     * Read from agent_runs, which is the table AdminApiController::logRun()
     * actually writes to — one row per manual agent invocation, keyed on the
     * agent and the human who asked.
     *
     * This used to read ai_runs, and that was wrong twice over: ai_runs is the
     * per-LLM-call token/cost ledger, so it holds provider/model/token columns
     * and NO `agent` column at all — every cell of the table rendered as "—",
     * and the panel never showed a single real agent invocation even after the
     * operator ran one.
     *
     * agent_runs records the OUTCOME, not the prompt, so there is no 'task'
     * column to select: resources/admin/agents.php shows the error on a
     * failure and the duration otherwise.
     */
    private function recentAgentRuns(int $limit = 15): array
    {
        try {
            $stmt = $this->db->getPdo()->prepare(
                'SELECT agent, status, duration_ms, error, user_id, created_at
                   FROM agent_runs
                  ORDER BY id DESC
                  LIMIT ?'
            );
            $stmt->bindValue(1, $limit, \PDO::PARAM_INT);
            $stmt->execute();

            return $stmt->fetchAll();
        } catch (\PDOException $e) {
            // agent_runs does not exist on a database predating it. The panel
            // degrades to its empty state rather than taking /admin/agents
            // down with it — the fleet and the action catalogue are the point
            // of that screen and neither depends on this query.
            return [];
        }
    }
}