<?php

namespace CMS\Api\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use CMS\Api\Responder;
use CMS\Database\Connection;
use CMS\Agents\Context;
use CMS\Repository\PostRepository;
use CMS\Repository\UserRepository;
use CMS\Repository\MediaRepository;
use CMS\Repository\SettingsRepository;

/**
 * The write layer behind the admin UI.
 *
 * This exists because the admin screens are server-rendered but every
 * MUTATION they perform goes through /api/v1 — one route set, one set of role
 * checks, one CSRF story. The alternative (a parallel set of admin-only write
 * routes) would mean every rule in here exists twice, and the copy that
 * rots is always the one nobody reads.
 *
 * Read screens live in AdminController; this class only writes.
 *
 * SECURITY RULES THAT APPLY TO EVERY METHOD HERE
 *   - Identity comes from $request->getAttribute('user_id'|'user_role'),
 *     which AuthMiddleware sets from the verified JWT. Never from the body.
 *     `author_id` on a new post is the caller's id from the token, not a
 *     field the client sent: otherwise an author could forge posts.
 *   - Every value reaches SQLite through a bound parameter. Column names
 *     come from an allowlist in the repository, never from the request.
 *   - ids are cast to int and validated for existence before use.
 *
 * Transcripts / migration history:
 *   - `ai_runs` exists but is an LLM TOKEN-COST ledger (provider, model, tokens,
 *     cost_usd, latency). It is not an agent-activity log and has no user_id
 *     or agent column, so it cannot record "SeoAgent ran". A new `agent_runs`
 *     table carries that; see database/schema.sql.
 */
final class AdminApiController
{
    private Connection $db;
    private PostRepository $posts;
    private UserRepository $users;
    private MediaRepository $media;
    private SettingsRepository $settings;

    public function __construct(Connection $db)
    {
        $this->db       = $db;
        $this->posts    = new PostRepository($db);
        $this->users    = new UserRepository($db);
        $this->media    = new MediaRepository($db);
        $this->settings = new SettingsRepository($db);
    }

    // =====================================================================
    // Posts
    // =====================================================================

    /**
     * Create a post.
     *
     * status is NOT taken from the request. A new post is always a draft:
     * "create" and "publish" are different decisions and collapsing them
     * means a mis-click publishes to the world. The editor screen publishes
     * by making a second, separately-authorized call.
     */
    public function createPost(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body = $this->body($request);

        $title = trim((string) ($body['title'] ?? ''));
        if ($title === '') {
            return Responder::error($response, 400, 'title is required');
        }

        $type = $this->oneOf($body['type'] ?? 'post', ['post', 'page'], 'post');

        $data = [
            'title'     => $title,
            'type'      => $type,
            'slug'      => $this->slug($body['slug'] ?? $title),
            'excerpt'   => $this->str($body['excerpt'] ?? null, 500),
            'body_md'   => $this->str($body['body_md'] ?? '', 200000),
            // From the token. Never from $body.
            'author_id' => (int) $request->getAttribute('user_id', 1),
            'origin'    => 'manual',
        ];

        if (isset($body['status']) && $body['status'] !== 'draft') {
            // A client asking for anything other than draft must still be an
            // editor, and the transition still runs through publish().
            if (!$this->hasRole($request, 'editor')) {
                return $this->forbid($response, 'editor');
            }
            $data['status'] = $this->oneOf($body['status'], ['published', 'scheduled', 'review'], 'draft');
        }

        $id = $this->posts->create($data);

        $this->syncTerms($id, $body);
        // Entity type is the route's business, never the caller's. This is
        // POST /posts, so the thing being described is a post.
        //
        // Note the cast binds tighter than ??: `$body['x'] ?? 'post'` was
        // evaluated as `(string) ($body['x'] ?? 'post')` in the reader's eye but
        // PHP applies the cast to the array access FIRST, raising
        // "Undefined array key" whenever the caller omits the field. Writing it
        // as an explicit default sidesteps the whole trap.
        $this->syncSeo('post', $id, $body);

        $post = $this->posts->find($id);
        return Responder::json($response, ['id' => $id, 'post' => $post], 201);
    }

    /**
     * Update a post.
     *
     * Only the fields a person can actually edit are accepted. `author_id`,
     * `origin`, `word_count`, `reading_time`, `uuid` and `id` are absent from
     * this allowlist on purpose — recomputing them server-side (which
     * PostRepository::update already does for the metrics) is what keeps them
     * trustworthy.
     */
    public function updatePost(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id   = $this->intId($args['id'] ?? 0);
        $body = $this->body($request);

        if (($this->posts->find($id)) === null) {
            return Responder::notFound($response, 'Post');
        }

        $data = [];

        if (array_key_exists('title', $body)) {
            $title = trim((string) $body['title']);
            if ($title === '') {
                return Responder::error($response, 400, 'title cannot be empty');
            }
            $data['title'] = $title;
        }
        foreach (['excerpt' => 500, 'body_md' => 200000, 'template' => 64] as $field => $max) {
            if (array_key_exists($field, $body)) {
                $data[$field] = $this->str($body[$field], $max);
            }
        }
        if (array_key_exists('slug', $body) && trim((string) $body['slug']) !== '') {
            $data['slug'] = $this->slug((string) $body['slug']);
        }
        if (array_key_exists('featured_media_id', $body)) {
            $data['featured_media_id'] = (int) $body['featured_media_id'] ?: null;
        }
        if (array_key_exists('status', $body)) {
            if (!$this->hasRole($request, 'editor')) {
                return $this->forbid($response, 'editor');
            }
            $data['status'] = $this->oneOf(
                $body['status'],
                ['draft', 'published', 'review', 'scheduled', 'trash'],
                'draft'
            );
        }

        if ($data !== []) {
            $this->posts->update($id, $data);
        }

        // All four taxonomy keys, not just the *_ids forms: a caller sending
        // {"tags":["a"]} was having it ignored entirely.
        if (array_key_exists('tag_ids', $body) || array_key_exists('category_ids', $body)
            || array_key_exists('tags', $body) || array_key_exists('categories', $body)
        ) {
            $this->syncTerms($id, $body);
        }

        // As in createPost: the entity type is fixed by the route, never read
        // from the body, and written explicitly rather than cast around `??`.
        $this->syncSeo('post', $id, $body);

        return Responder::ok($response, ['id' => $id, 'post' => $this->posts->find($id)]);
    }

    /**
     * Publish / unpublish / trash / restore as an explicit state machine.
     *
     * The admin UI's toggle button posts here rather than writing `status`
     * directly: publishing stamps published_at exactly once and refuses to
     * resurrect a trashed post, which a raw UPDATE would happily do.
     */
    public function postTransition(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id       = $this->intId($args['id'] ?? 0);
        $transition = (string) ($args['transition'] ?? '');

        if (($this->posts->find($id)) === null) {
            return Responder::notFound($response, 'Post');
        }

        // Unpublishing and trashing are recoverable states; deleting is not,
        // so it is admin-only even though the verb is DELETE.
        $need = in_array($transition, ['unpublish', 'trash', 'restore'], true) ? 'editor' : 'admin';
        if (!$this->hasRole($request, $need)) {
            return $this->forbid($response, $need);
        }

        $ok = match ($transition) {
            'publish'   => $this->posts->publish($id),
            'unpublish' => $this->posts->unpublish($id),
            'trash'     => $this->posts->trash($id),
            'restore'   => $this->posts->restore($id),
            default     => null,
        };

        if ($ok === null) {
            return Responder::error($response, 400, "Unknown transition '{$transition}'");
        }
        if (!$ok) {
            return Responder::error($response, 409, "Cannot {$transition} a post in its current state");
        }
        return Responder::ok($response, ['id' => $id, 'post' => $this->posts->find($id)]);
    }

    // =====================================================================
    // SEO
    // =====================================================================

    /**
     * Persist an SEO meta pack for an entity.
     *
     * seo_meta is keyed on (entity_type, entity_id) — a polymorphic table, so
     * the entity type comes from the ROUTE (a constant per route) and never
     * from the request body. That keeps a caller from writing meta rows onto
     * entities it did not name.
     */
    public function saveSeo(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $body = $request->getParsedBody() ?? [];

        $entityType = (string) ($args['entity'] ?? 'post');
        $entityId   = $this->str($args['id'] ?? '', 64);

        if ($entityType === 'post') {
            if ($this->posts->find($this->intId($entityId)) === null) {
                return Responder::notFound($response, 'Post');
            }
        }

        $metaTitle       = $this->str($body['meta_title'] ?? null, 200);
        $metaDescription = $this->str($body['meta_description'] ?? null, 400);
        $focusKeyword    = $this->str($body['focus_keyword'] ?? null, 120);

        // Search engines truncate these; storing a 900-char description just
        // wastes the field. Tell the caller rather than silently cutting.
        $warnings = [];
        if ($metaDescription !== null && strlen($metaDescription) > 160) {
            $warnings[] = 'meta_description is longer than the ~160 characters Google will display';
        }
        if ($metaTitle !== null && strlen($metaTitle) > 60) {
            $warnings[] = 'meta_title is longer than the ~60 characters Google will display';
        }

        $stmt = $this->db->getPdo()->prepare(
            "INSERT INTO seo_meta
                (entity_type, entity_id, meta_title, meta_description, focus_keyword,
                 canonical_url, robots, score, checked_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, datetime('now'), datetime('now'))
             ON CONFLICT(entity_type, entity_id) DO UPDATE SET
                meta_title       = excluded.meta_title,
                meta_description = excluded.meta_description,
                focus_keyword    = excluded.focus_keyword,
                canonical_url    = excluded.canonical_url,
                checked_at       = datetime('now'),
                updated_at       = datetime('now')"
        );
        $stmt->execute([
            $entityType,
            $entityId,
            $metaTitle,
            $metaDescription,
            $focusKeyword,
            $this->str($body['canonical_url'] ?? null, 500),
            $this->oneOf($body['robots'] ?? 'index,follow', ['index,follow', 'noindex,follow', 'index,nofollow', 'noindex,nofollow'], 'index,follow'),
            isset($body['score']) ? (int) $body['score'] : null,
        ]);

        return Responder::ok($response, [
            'saved'    => true,
            'entity'   => $entityType . ':' . $entityId,
            'warnings' => $warnings,
        ]);
    }

    // =====================================================================
    // Users
    // =====================================================================

    public function createUser(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->hasRole($request, 'admin')) {
            return $this->forbid($response, 'admin');
        }
        $body = $this->body($request);

        $email = strtolower(trim((string) ($body['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Responder::error($response, 400, 'a valid email is required');
        }
        if ($this->users->emailExists($email)) {
            return Responder::error($response, 409, 'That email is already registered');
        }

        $password = (string) ($body['password'] ?? '');
        if (strlen($password) < 8) {
            return Responder::error($response, 400, 'password must be at least 8 characters');
        }

        $id = $this->users->create([
            'email'         => $email,
            'username'      => $this->str($body['username'] ?? strstr($email, '@', true), 64),
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'display_name'  => $this->str($body['display_name'] ?? null, 120),
            'role'          => $this->oneOf($body['role'] ?? 'author', ['author', 'editor', 'admin'], 'author'),
            'status'        => $this->oneOf($body['status'] ?? 'active', ['active', 'suspended'], 'active'),
        ]);

        return Responder::json($response, ['id' => $id, 'user' => $this->users->find($id)], 201);
    }

    /**
     * Update a user — role, status, profile fields, and optionally a password.
     *
     * Two guards live here rather than in the repository, because they are
     * about WHO is asking, not about the data:
     *
     *   1. An admin cannot demote themselves away from admin. Doing so would
     *      leave the install with no administrator and no way back in except
     *      the database.
     *   2. The last remaining admin cannot be demoted, suspended or deleted
     *      by anyone.
     */
    public function updateUser(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        if (!$this->hasRole($request, 'admin')) {
            return $this->forbid($response, 'admin');
        }
        $id   = $this->intId($args['id'] ?? 0);
        $body = $this->body($request);

        $target = $this->users->find($id);
        if ($target === null) {
            return Responder::notFound($response, 'User');
        }

        $callerId = (int) $request->getAttribute('user_id', 0);

        $newRole   = array_key_exists('role', $body)
            ? $this->oneOf($body['role'], ['author', 'editor', 'admin'], 'author')
            : (string) $target['role'];

        $newStatus = array_key_exists('status', $body)
            ? $this->oneOf($body['status'], ['active', 'suspended'], 'active')
            : (string) $target['status'];

        $losingAdmin = ($target['role'] === 'admin') && ($newRole !== 'admin' || $newStatus !== 'active');

        if ($losingAdmin && $id === $callerId) {
            return Responder::error($response, 409, 'You cannot remove your own administrator role');
        }
        if ($losingAdmin && $this->isLastAdmin($id)) {
            return Responder::error($response, 409, 'The last administrator cannot be demoted or suspended');
        }

        $data = [];
        foreach (['display_name' => 120, 'username' => 64, 'locale' => 10] as $f => $max) {
            if (array_key_exists($f, $body)) {
                $data[$f] = $this->str($body[$f], $max);
            }
        }
        if (array_key_exists('email', $body)) {
            $email = strtolower(trim((string) $body['email']));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return Responder::error($response, 400, 'a valid email is required');
            }
            $clash = $this->users->findByEmail($email);
            if ($clash !== null && (int) $clash['id'] !== $id) {
                return Responder::error($response, 409, 'That email belongs to another account');
            }
            $data['email'] = $email;
        }
        $data['role']   = $newRole;
        $data['status'] = $newStatus;

        $this->users->update($id, $data);

        // A suspended or demoted account must lose its live sessions, otherwise
        // the change looks applied but the old token keeps working until it
        // expires on its own.
        if ($newStatus === 'suspended' || $newRole !== $target['role']) {
            $this->db->getPdo()->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([$id]);
        }

        $password = (string) ($body['password'] ?? '');
        if ($password !== '') {
            if (strlen($password) < 8) {
                return Responder::error($response, 400, 'password must be at least 8 characters');
            }
            $this->users->setPassword($id, password_hash($password, PASSWORD_DEFAULT));
            $this->db->getPdo()->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([$id]);
        }

        return Responder::ok($response, ['id' => $id, 'user' => $this->users->find($id)]);
    }

    public function deleteUser(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        if (!$this->hasRole($request, 'admin')) {
            return $this->forbid($response, 'admin');
        }
        $id = $this->intId($args['id'] ?? 0);

        if ($this->users->find($id) === null) {
            return Responder::notFound($response, 'User');
        }
        if ($id === (int) $request->getAttribute('user_id', 0)) {
            return Responder::error($response, 409, 'You cannot delete your own account');
        }
        if ($this->isLastAdmin($id)) {
            return Responder::error($response, 409, 'The last administrator cannot be deleted');
        }

        $this->db->getPdo()->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([$id]);
        $this->users->delete($id);

        return Responder::ok($response, ['deleted' => true, 'id' => $id]);
    }

    /**
     * True when $excludeId is the only active administrator left.
     *
     * Counts only ACTIVE admins: a suspended administrator cannot restore the
     * install, so it does not count toward keeping one alive.
     */
    private function isLastAdmin(int $excludeId): bool
    {
        $stmt = $this->db->getPdo()->prepare(
            "SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active' AND id != ?"
        );
        $stmt->execute([$excludeId]);
        return ((int) $stmt->fetchColumn()) === 0;
    }

    // =====================================================================
    // Taxonomy
    // =====================================================================

    public function createTerm(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $kind = (string) ($args['kind'] ?? 'tags');
        $repo = $this->taxonomyFor($kind);
        if ($repo === null) {
            return Responder::error($response, 400, "Unknown taxonomy '{$kind}'");
        }

        $body = $request->getParsedBody() ?? [];
        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            return Responder::error($response, 400, 'name is required');
        }

        $slug = $this->str($body['slug'] ?? null, 100);
        $id   = $repo->create($name, $slug !== null && $slug !== '' ? $this->slug($slug) : null);

        return Responder::json($response, ['id' => $id, 'term' => $repo->find($id)], 201);
    }

    public function deleteTerm(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $repo = $this->taxonomyFor((string) ($args['kind'] ?? 'tags'));
        if ($repo === null) {
            return Responder::error($response, 400, 'Unknown taxonomy');
        }

        $id  = $this->intId($args['id'] ?? 0);
        $hit = $repo->delete($id);

        if ($hit <= 0) {
            return Responder::notFound($response, 'Term');
        }
        // delete() returns how many link rows it removed. Non-zero means posts
        // pointed at this term and have now silently lost it — the caller
        // needs to know so the UI can say "3 posts lost this tag" rather than
        // "deleted".
        return Responder::ok($response, ['deleted' => true, 'id' => $id, 'detached_posts' => $hit]);
    }

    public function recomputeTags(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $repo = new \CMS\Repository\TaxonomyRepository($this->db);
        $repo = $repo->forTags();

        $stmt = $this->db->getPdo()->query('SELECT id FROM tags ORDER BY id');
        $ids  = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $changed = 0;
        foreach ($ids as $id) {
            $changed += $repo->recompute((int) $id) > 0 ? 1 : 0;
        }

        return Responder::ok($response, ['count' => count($ids), 'recomputed' => $changed]);
    }

    /**
     * TaxonomyRepository's forTags()/forCategories() MUTATE $this and return
     * $this — the same object. Two handles to one instance therefore point at
     * whichever table was set last, which is exactly the bug that made the
     * repository tests assert against the wrong table. This returns distinct
     * instances so a caller cannot accidentally alias them.
     */
    private function taxonomyFor(string $kind): ?\CMS\Repository\TaxonomyRepository
    {
        return match ($kind) {
            'tags'       => (new \CMS\Repository\TaxonomyRepository($this->db))->forTags(),
            'categories' => (new \CMS\Repository\TaxonomyRepository($this->db))->forCategories(),
            default      => null,
        };
    }

    // =====================================================================
    // Settings
    // =====================================================================

    /**
     * Write settings as a flat map of key => value.
     *
     * Each key must already EXIST. This endpoint edits configuration; it does
     * not create it. Creating settings from arbitrary keys would let an admin
     * (or anything that got an admin token) invent new configuration
     * variables the application then reads by name.
     */
    public function updateSettings(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->hasRole($request, 'admin')) {
            return $this->forbid($response, 'admin');
        }
        $body = $this->body($request);

        if ($body === []) {
            return Responder::error($response, 400, 'no settings supplied');
        }

        $saved    = [];
        $rejected = [];

        foreach ($body as $key => $value) {
            $key = (string) $key;
            if (preg_match('/^[a-z0-9]+(?:[._-][a-z0-9]+)*$/i', $key) !== 1) {
                $rejected[$key] = 'invalid key format';
                continue;
            }
            if ($this->settings->get($key, '__missing__') === '__missing__') {
                $rejected[$key] = 'unknown setting';
                continue;
            }

            // settings.value is JSON, so scalars are encoded, not concatenated.
            $this->settings->set($key, is_scalar($value) ? (string) $value : $value, 'system');
            $saved[] = $key;
        }

        if ($saved === []) {
            return Responder::error($response, 400, 'no known settings were supplied', ['rejected' => $rejected]);
        }

        return Responder::ok($response, ['saved' => $saved, 'rejected' => $rejected]);
    }

    // =====================================================================
    // Agents
    // =====================================================================

    /**
     * Run one agent synchronously and return its result.
     *
     * The agent class name comes from a FIXED allowlist, never from the
     * request. Constructing an arbitrary class named in a request body is a
     * remote-code-execution primitive the moment any constructor in the tree
     * does anything; the model output is even less trustworthy than the caller.
     *
     * Authorisation is PER AGENT, not blanket.
     *
     * An earlier version required 'editor' for every agent, which was safe
     * but wrong in a way that quietly removes features: SearchAgent and
     * AnalyticsAgent are read-only, and an author asking "what have I
     * published?" was refused because of agents that write. Every agent is
     * still reachable by an editor; only the read-only ones open up to an
     * author.
     *
     * The floor below is the minimum role that can invoke the endpoint at
     * all. It is NOT the permission check for the agent — AGENT_MIN_ROLE is.
     * Each agent also re-checks its own role internally before it writes
     * (EditorAgent and MediaAgent both return 'rejected' on their own), so a
     * mis-entry in the table below fails closed in two places rather than one.
     */
    public function runAgent(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->hasRole($request, 'author')) {
            return $this->forbid($response, 'author');
        }
        $body = $this->body($request);

        $available = self::agentAllowlist();
        $name      = (string) ($body['agent'] ?? '');

        if (!isset($available[$name]) || !class_exists($available[$name])) {
            return Responder::error($response, 400, 'Unknown agent', ['available' => array_keys($available)]);
        }

        // Per-agent permission. Anything not named here defaults to 'editor',
        // so adding an agent to the allowlist without deciding its role grants
        // nothing to an author — the new agent is simply editor-only.
        $floor = self::AGENT_MIN_ROLE[$name] ?? 'editor';
        if (!$this->hasRole($request, $floor)) {
            return $this->forbid($response, $floor);
        }

        $task = trim((string) ($body['task'] ?? ''));
        if ($task === '') {
            return Responder::error($response, 400, 'task is required');
        }

        // Reuse the request's identity so the agent authorizes against the
        // real caller's role rather than defaulting to 'author'.
        $context = Context::fromRequest($request, null, Context::siteSettings($this->db));

        try {
            $class   = new $available[$name]();
            $started = microtime(true);
            $result  = $class->execute($task, $context->toArray());
            $ms      = (int) round((microtime(true) - $started) * 1000);
        } catch (\Throwable $e) {
            $this->logRun($name, 'failed', (int) ((microtime(true) - $started ?? 0) * 1000), ['error' => $e->getMessage()], $context);
            return Responder::json($response, [
                'status' => 'failed',
                'agent'  => $name,
                'error'  => $e->getMessage(),
            ], 422);
        }

        $this->logRun($name, $result['status'] ?? 'ok', $ms, [], $context);

        return Responder::json($response, [
            'agent'   => $name,
            'status'  => $result['status'] ?? 'ok',
            'result'  => $result,
            'took_ms' => $ms,
        ]);
    }

    /**
     * POST /api/v1/agents/ceo — the goal-shaped entry point.
     *
     * Distinct from /agents/run in three ways that make it the better
     * interface for a person rather than for a script:
     *
     *  1. `dry_run: true` returns the plan WITHOUT executing it. The plan is
     *     a list of writes; letting an operator read one before it lands is
     *     the difference between an AI tool and a rumour. It costs a second
     *     model call, so it is opt-in rather than the default.
     *  2. `plan` (from an earlier dry run) may be supplied instead of a goal,
     *     so a reviewed plan can be executed without re-prompting — and
     *     therefore cannot be swapped between review and execution.
     *  3. The response is shaped for display: a status, a plain-English
     *     summary and a per-step table, rather than a raw intent dump.
     *
     * Role is unchanged and enforced identically — the CEO is 'editor' at the
     * allowlist and every step re-checks the CALLER's role in IntentRegistry.
     */
    public function runCeo(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->hasRole($request, 'editor')) {
            return $this->forbid($response, 'editor');
        }

        $body  = $this->body($request);
        $agent = new \CMS\Agents\CeoAgent($this->db);
        $ctx   = Context::fromRequest($request, null, Context::siteSettings($this->db));

        $dryRun = (bool) ($body['dry_run'] ?? false);
        // Three accepted names for one field. 'task' matches the sibling
        // /agents/run route, 'goal' matches CeoAgent's own vocabulary, and
        // 'prompt' is what the chat form posts — accepting all three means the
        // UI and the API cannot disagree about what the box is called, which
        // is the kind of mismatch that surfaces as a 400 to a real user.
        $goal   = trim((string) ($body['task'] ?? $body['goal'] ?? $body['prompt'] ?? ''));
        // A plan handed back from a dry run, executed verbatim.
        $supplied = $body['plan'] ?? null;

        try {
            $started = microtime(true);

            if (is_array($supplied) && $supplied !== []) {
                $steps = [];
                foreach ($supplied as $step) {
                    if (!is_array($step) || !isset($step['intent'])) {
                        // Same shape rules as plan() applies to model output:
                        // a step without an intent is not a step.
                        continue;
                    }
                    $steps[] = [
                        'specialist' => (string) ($step['specialist'] ?? 'AdminAgent'),
                        'intent'     => (string) $step['intent'],
                        'params'     => is_array($step['params'] ?? null) ? $step['params'] : [],
                    ];
                }
                if ($steps === []) {
                    return Responder::error($response, 400, 'plan contained no usable steps');
                }
                $result = $dryRun ? ['status' => 'ok', 'steps' => $steps, 'summary' => 'Ready to run.'] : $agent->runPlan($steps, $ctx);
            } elseif ($goal !== '') {
                $result = $dryRun ? $agent->plan($goal, $ctx) : $agent->execute($goal, $ctx);
            } else {
                return Responder::error($response, 400, 'task or plan is required');
            }

            $ms = (int) round((microtime(true) - $started) * 1000);
        } catch (\Throwable $e) {
            return Responder::json($response, [
                'status' => 'failed',
                'error'  => $e->getMessage(),
            ], 422);
        }

        $this->logRun('CeoAgent', $result['status'] ?? 'ok', $ms, [], $ctx);

        return Responder::json($response, [
            'status'   => $result['status'] ?? 'ok',
            'dry_run'  => $dryRun,
            'summary'  => $result['summary'] ?? '',
            'plan'     => $result['plan'] ?? ($result['steps'] ?? []),
            'steps'    => $result['steps'] ?? [],
            'done'     => $result['done'] ?? null,
            'failed'   => $result['failed'] ?? null,
            'role'     => $ctx->userRole,
            'took_ms'  => $ms,
        ]);
    }

    /**
     * GET /api/v1/skills — what a skill is and what it will do.
     *
     * Lists rather than runs. The catalog is safe to hand to a UI because
     * each entry carries its declared parameter slots, so a client can show
     * a real form instead of "type JSON". Nothing here executes anything.
     */
    public function listSkills(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->hasRole($request, 'author')) {
            return $this->forbid($response, 'author');
        }

        $skills = new \CMS\Agents\Skills($this->db);

        return Responder::json($response, [
            'skills' => $skills->describe(),
            'role'   => $this->roleOf($request),
        ]);
    }

    /**
     * POST /api/v1/skills/run — execute a named skill.
     *
     * The floor is 'author' rather than 'editor' for the same reason
     * /agents/run is: RoleMiddleware runs first, so an editor floor here
     * would reject every author before Skills::run() could report WHICH step
     * was refused. Author access here buys nothing an author did not already
     * have — every step still goes through IntentRegistry with the caller's
     * role, so an author gets 'denied' on the first write and no partial
     * result to reason about.
     */
    public function runSkill(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->hasRole($request, 'author')) {
            return $this->forbid($response, 'author');
        }

        $body  = $this->body($request);
        $name  = trim((string) ($body['skill'] ?? $body['name'] ?? ''));
        $skill = new \CMS\Agents\Skills($this->db);

        if ($name === '') {
            return Responder::error($response, 400, 'skill is required');
        }

        if (!$skill->has($name)) {
            return Responder::error($response, 404, 'unknown skill: ' . $name, [
                'available' => array_keys($skill->all()),
            ]);
        }

        $params = $body['params'] ?? $body['args'] ?? [];
        if (!is_array($params)) {
            return Responder::error($response, 400, 'params must be an object');
        }

        $started = microtime(true);
        $ctx     = Context::fromRequest($request, null, Context::siteSettings($this->db));

        try {
            // The caller's real role, not a default: Skills::run() passes this
            // to IntentRegistry per step, so an editor-author running a skill
            // is refused exactly the steps an editor cannot do.
            $result = $skill->run($name, $params, $ctx->userRole);
        } catch (\Throwable $e) {
            return Responder::json($response, [
                'status' => 'error',
                'skill'  => $name,
                'error'  => $e->getMessage(),
            ], 422);
        }

        $ms = (int) round((microtime(true) - $started) * 1000);

        // 'denied' is a normal outcome here, not a failure of the request —
        // the call succeeded and the answer was no. Logging it as 'ok' keeps
        // the agent_runs table a record of intent rather than of outcomes.
        $this->logRun('Skills:' . $name, $result['status'] ?? 'ok', $ms, [], $ctx);

        return Responder::json($response, $result + ['took_ms' => $ms, 'role' => $ctx->userRole]);
    }

    /**
     * POST /api/v1/research — the deep-research tool, at its own address.
     *
     * Separate from /agents/run because ResearchAgent needs no goal-shape
     * parsing and no delegation: it takes a question and returns findings.
     * Routing it through the generic agent route would mean the model chose
     * whether to call it, and "AI decided to browse" is one step closer to a
     * page telling it to.
     */
    public function research(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->hasRole($request, 'author')) {
            return $this->forbid($response, 'author');
        }

        $body = $this->body($request);
        $q    = trim((string) ($body['query'] ?? $body['task'] ?? ''));

        if ($q === '') {
            return Responder::error($response, 400, 'query is required');
        }

        $ctx = Context::fromRequest($request, null, Context::siteSettings($this->db));

        try {
            $started = microtime(true);

            // max_pages is honoured rather than ignored. It used to be accepted
            // and dropped, which is the worst of both: a caller who asked for
            // two pages got four and had no way to tell. WebResearcher clamps
            // to 1..5, so an absurd value degrades rather than failing.
            $depth = (int) ($body['max_pages'] ?? 4);
            $depth = max(1, min(5, $depth));

            $result = (new \CMS\Agents\ResearchAgent($this->db))->execute($q, $ctx, $depth);
        } catch (\Throwable $e) {
            return Responder::json($response, [
                'status' => 'failed',
                'error'  => $e->getMessage(),
            ], 422);
        }

        $ms = (int) round((microtime(true) - $started) * 1000);
        $this->logRun('ResearchAgent', $result['status'] ?? 'ok', $ms, [], $ctx);

        // 'unavailable' and 'empty' are answers, not server errors — a 200
        // with status set is what lets the UI say "add a key" instead of
        // showing an opaque 500.
        return Responder::json($response, $result + ['took_ms' => $ms]);
    }

    /**
     * GET /api/v1/ai/providers — which providers have a key, never the key.
     *
     * The endpoint an admin screen reads to render "which AI am I running",
     * and the only way the BYOK feature is reachable from the UI at all.
     * Values are never returned — only 'configured' and a hint about where
     * the key came from, because a status endpoint that echoes credentials is
     * a credential endpoint.
     */
    public function listProviders(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->hasRole($request, 'admin')) {
            return $this->forbid($response, 'admin');
        }

        $store = new \CMS\Agents\ProviderKeyStore($this->db);

        return Responder::json($response, [
            'providers'   => $store->status(),
            // One field, not two. SecretBox::available() already answers "is
            // there a usable CMS_ENCRYPTION_KEY", which is the only question
            // the screen needs — libsodium being loaded and a key being set
            // are the same predicate here, and an earlier pair of flags that
            // claimed otherwise told an operator with a perfectly good key
            // that encryption was unavailable.
            'can_encrypt' => \CMS\Agents\SecretBox::available(),
        ]);
    }

    /**
     * POST /api/v1/ai/providers — store a provider key.
     *
     * A direct endpoint rather than an agent intent on purpose.
     *
     * set_provider_key also exists as an intent, so the CEO can rotate a key
     * from a prompt. That path builds the intent from model output, which
     * means the key travels as an argument inside a task that goes to the
     * model provider — the credential would be sent to Kilo, in a prompt,
     * in whatever retention policy applies there. So the two are NOT the same
     * route: the intent exists for operators driving the CEO deliberately,
     * and this one is what the settings form uses, where the key goes
     * straight from the browser to the database.
     *
     * The key is never echoed, not even on success. The response says whether
     * it STORED, and whether its shape looked right — enough to catch a paste
     * of the wrong thing, without returning the secret.
     */
    public function saveProviderKey(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->hasRole($request, 'admin')) {
            return $this->forbid($response, 'admin');
        }

        $body = $this->body($request);
        $name = trim((string) ($body['provider'] ?? ''));
        $key  = trim((string) ($body['api_key'] ?? ''));

        if ($name === '' || $key === '') {
            return Responder::error($response, 400, 'provider and api_key are required');
        }

        $store = new \CMS\Agents\ProviderKeyStore($this->db);

        if (!$store::isKnown($name)) {
            return Responder::error($response, 400, 'unknown provider: ' . $name, [
                'allowed' => $store::providers(),
            ]);
        }

        if (!\CMS\Agents\SecretBox::available()) {
            // Said plainly rather than as a 500: the fix is an env var, and an
            // operator who has to read a stack trace to learn that is being
            // told the symptom instead of the cause.
            return Responder::error($response, 503,
                'BYOK is not enabled. Set CMS_ENCRYPTION_KEY in .env and restart.');
        }

        try {
            $store->put($name, $key, (int) $request->getAttribute('user_id', 0) ?: null);
        } catch (\RuntimeException $e) {
            return Responder::error($response, 500, $e->getMessage());
        }

        $looksOk = $store::looksLikeKey($name, $key);

        return Responder::json($response, [
            'provider'      => $name,
            'stored'        => true,
            'looks_like_key' => $looksOk,
            // Deliberately a warning and not a rejection. Some providers issue
            // keys in shapes the check does not know, and refusing a key that
            // works would be worse than accepting one that does not — the
            // failure surfaces as an auth error on the next agent run, which
            // is a clear enough signal.
            'hint' => $looksOk
                ? null
                : 'Stored, but the value does not match this provider\'s usual key format. Verify it works.',
        ], 201);
    }

    /**
     * DELETE /api/v1/ai/providers/{name} — forget a stored key.
     *
     * Falls back to the .env key afterwards rather than leaving the provider
     * with no key at all, which is the least surprising outcome: the operator
     * asked to remove what THIS screen stored, not to disable the provider.
     */
    public function deleteProviderKey(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if (!$this->hasRole($request, 'admin')) {
            return $this->forbid($response, 'admin');
        }

        $name  = trim((string) ($request->getAttribute('provider') ?? ''));
        $store = new \CMS\Agents\ProviderKeyStore($this->db);

        if (!$store::isKnown($name)) {
            return Responder::error($response, 404, 'unknown provider: ' . $name);
        }

        $store->forget($name);

        return Responder::json($response, [
            'provider' => $name,
            'removed'  => true,
            'status'   => $store->status()[$name] ?? null,
        ]);
    }

/**
     * The only agent classes a request may name.
     *
     * Adding a capability means adding a line here, which is a reviewable
     * diff — not widening a class-resolution hole at runtime.
     *
     * This is a security boundary: it decides WHICH AGENTS A REQUEST MAY RUN.
     * AdminController::agentFleet() carries a separate, longer list that only
     * decides what the admin UI displays. The two are deliberately not shared
     * — a display list that could add an agent would hand anyone with template
     * edit access the ability to run one.
     */
    public const AGENT_ALLOWLIST = [
        'BlogAgent'   => \CMS\Agents\BlogAgent::class,
        'SeoAgent'    => \CMS\Agents\SeoAgent::class,
        'DesignAgent' => \CMS\Agents\DesignAgent::class,
        'AdminAgent'  => \CMS\Agents\AdminAgent::class,
    ];

    /**
     * Agents a request may name, including ones added after this file was
     * written.
     *
     * MediaAgent and NewsAgent are looked up dynamically rather than listed
 * here as `MediaAgent::class` constants. A bare ::class constant for a
 * class that does not exist is harmless to the parser but useless: the
 * entry can never pass the class_exists() check below, so the agent would
 * be permanently unreachable while appearing configured. SearchAgent,
 * EditorAgent, SocialAgent and AnalyticsAgent are listed here for the same
 * reason — they were all added after the block above was written.
     *
     * dynamic is still an allowlist — build() below refuses anything outside
     * it — it just does not hard-fail at parse time when a class is absent.
     */
    private const AGENT_ALLOWLIST_DYNAMIC = [
        'MediaAgent'    => 'CMS\\Agents\\MediaAgent',
        'NewsAgent'     => 'CMS\\Agents\\NewsAgent',
        'SearchAgent'   => 'CMS\\Agents\\SearchAgent',
        'EditorAgent'   => 'CMS\\Agents\\EditorAgent',
        'SocialAgent'   => 'CMS\\Agents\\SocialAgent',
        'AnalyticsAgent' => 'CMS\\Agents\\AnalyticsAgent',
        'CeoAgent'      => 'CMS\\Agents\\CeoAgent',
        // Reads the public web. Author-level (see AGENT_MIN_ROLE) because it
        // cannot write — its whole output is findings plus source URLs.
        'ResearchAgent' => 'CMS\\Agents\\ResearchAgent',
    ];

    /**
     * Minimum role permitted to invoke each agent.
     *
     * Only the genuinely read-only agents are open to an author. An agent
     * missing from this table is editor-only — the default in runAgent() —
     * so the failure mode for a forgotten entry is a restriction, never a
     * grant.
     *
     * Which agents qualify as read-only is not a guess: SearchAgent,
     * AnalyticsAgent and SocialAgent contain no writer intent and no
     * INSERT/UPDATE/DELETE. AdminAgent is deliberately absent — it takes a
     * model-supplied intent name, which makes it a write-capable agent by
     * construction regardless of the task it is given.
     */
    private const AGENT_MIN_ROLE = [
        'SearchAgent'    => 'author',
        'AnalyticsAgent' => 'author',
        'SocialAgent'    => 'author',
        // Reading the public web is not privileged, and ResearchAgent holds no
        // write path at all — its output is findings and source URLs. Kept
        // at author so a low-privilege account can research; the ceiling is
        // enforced by the absence of any write intent it can reach.
        'ResearchAgent'  => 'author',
        // Write-capable agents fall through to the 'editor' default.
        'EditorAgent'    => 'editor',
        'MediaAgent'     => 'editor',
        'NewsAgent'      => 'editor',
        'BlogAgent'      => 'editor',
        'SeoAgent'       => 'editor',
        'DesignAgent'    => 'editor',
        'AdminAgent'     => 'editor',

        // The CEO is 'editor', not 'admin'. An earlier draft of the plan put
        // it at admin on the reasoning that composing many intents in one
        // request deserves a higher bar — but the step gate already makes
        // that moot: every step runs through IntentRegistry with the
        // CALLER's role, so an editor's CEO call is exactly as capable as an
        // editor's AdminAgent call and no more. AdminAgent is already
        // 'editor' while taking an arbitrary model-supplied intent name, so
        // raising only the CEO to admin would restrict the flagship
        // interface without removing a single capability an editor lacks.
        'CeoAgent'      => 'editor',

        // Skills run a FIXED list of intents, so the role question is the
        // same one the registry already answers per step. An editor-author
        // can therefore list and run skills whose steps are all editor-level
        // (seo_optimise_post), and is refused only on the step that needs
        // admin. Failing at the step keeps the diagnostic honest — it names
        // the intent that was refused — instead of hiding every skill behind
        // an all-or-nothing gate that would not track the actual risk.
        'Skills'        => 'editor',
    ];

    /** Merged allowlist: static entries plus any that exist on disk. */
    public static function agentAllowlist(): array
    {
        $all = self::AGENT_ALLOWLIST;
        foreach (self::AGENT_ALLOWLIST_DYNAMIC as $name => $class) {
            if (class_exists($class)) {
                $all[$name] = $class;
            }
        }
        return $all;
    }

    /** Record the run so /admin/agents shows recent activity. */
    private function logRun(string $agent, string $status, int $ms, array $error, Context $ctx): void
    {
        try {
            $this->db->getPdo()->prepare(
                "INSERT INTO agent_runs (agent, status, duration_ms, error, user_id, created_at)
                 VALUES (?, ?, ?, ?, ?, datetime('now'))"
            )->execute([
                $agent,
                $status,
                $ms,
                $error === [] ? null : json_encode($error),
                $ctx->userId,
            ]);
        } catch (\Throwable) {
            // agent_runs may not exist on an older schema. A missing audit row
            // must never fail the caller's request.
        }
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    /**
     * Attach tags and categories to a post.
     *
     * Accepts both shapes a client is likely to send:
     *   tag_ids / category_ids  -> numeric ids, used as-is (the admin UI's own
     *                              multi-select posts these)
     *   tags     / categories   -> human-readable NAMES, resolved by slug and
     *                              created on first use. This is what an API
     *                              caller or an agent writes, and silently
     *                              dropping it made `{"tags":["a","b"]}` a
     *                              no-op that returned 201 with nothing stored.
     *
     * A supplied list REPLACES the post's existing terms; omitting both keys
     * leaves them alone, so a partial update cannot wipe taxonomy the caller
     * never mentioned.
     */
    private function syncTerms(int $postId, array $body): void
    {
        $tagIdsIn   = $body['tag_ids'] ?? null;
        $catIdsIn   = $body['category_ids'] ?? null;
        $tagNames   = $body['tags'] ?? null;
        $catNames   = $body['categories'] ?? null;

        $haveTags = $tagIdsIn !== null || $tagNames !== null;
        $haveCats = $catIdsIn !== null || $catNames !== null;

        if (!$haveTags && !$haveCats) {
            return;
        }

        // SEPARATE instances: forTags()/forCategories() mutate and return $this.
        $tagRepo = (new \CMS\Repository\TaxonomyRepository($this->db))->forTags();
        $catRepo = (new \CMS\Repository\TaxonomyRepository($this->db))->forCategories();

        $tagIds = $this->termIds($tagRepo, $tagIdsIn, $tagNames);
        $catIds = $this->termIds($catRepo, $catIdsIn, $catNames);

        // syncPost needs BOTH lists, so an update that touches only tags must
        // still pass the post's current categories rather than clearing them.
        if (!$haveCats) {
            $catIds = $this->existingIds($postId, 'category_id');
        }
        if (!$haveTags) {
            $tagIds = $this->existingIds($postId, 'tag_id');
        }

        $tagRepo->syncPost($postId, $tagIds, $catIds);
    }

    /**
     * Resolve ids and/or names into a de-duplicated list of term ids.
     *
     * @param mixed $idsIn   numeric ids, if the caller sent them
     * @param mixed $namesIn term names, if the caller sent those
     * @return list<int>
     */
    private function termIds(\CMS\Repository\TaxonomyRepository $repo, mixed $idsIn, mixed $namesIn): array
    {
        $ids = [];

        foreach ((is_array($idsIn) ? $idsIn : []) as $raw) {
            $id = (int) $raw;
            if ($id > 0 && $repo->find($id) !== null) {
                $ids[$id] = $id;
            }
        }

        foreach ((is_array($namesIn) ? $namesIn : []) as $raw) {
            $name = trim((string) $raw);
            if ($name === '') {
                continue;
            }
            // Cap the length so a pathological name cannot become an unbounded
            // slug, and bound the count so one request cannot create hundreds
            // of terms.
            $name = mb_substr($name, 0, 80);
            $slug = $this->slugify($name);
            $found = $slug === '' ? null : $repo->findBySlug($slug);
            $id    = $found !== null ? (int) $found['id'] : $repo->create($name, $slug);
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    /** @return list<int> */
    private function existingIds(int $postId, string $column): array
    {
        // The table name is picked from a fixed pair, never from input, so the
        // interpolation below can only ever be one of two known literals.
        [$table, $col] = match ($column) {
            'tag_id'      => ['post_tags', 'tag_id'],
            'category_id' => ['post_categories', 'category_id'],
            default       => [null, null],
        };
        if ($table === null) {
            return [];
        }

        $stmt = $this->db->getPdo()->prepare("SELECT {$col} FROM {$table} WHERE post_id = ?");
        $stmt->execute([$postId]);

        return array_values(array_map(static fn ($r) => (int) $r[$col], $stmt->fetchAll()));
    }

    private function slugify(string $s): string
    {
        $s = mb_strtolower(trim($s));
        $s = preg_replace('/[^a-z0-9]+/u', '-', $s) ?? '';
        return trim($s, '-');
    }

    private function syncSeo(string $entityType, int $id, array $body): void
    {
        $focus = $body['focus_keyword'] ?? null;
        $desc  = $body['meta_description'] ?? null;

        if (($focus === null || $focus === '') && ($desc === null || $desc === '')) {
            return;
        }

        $stmt = $this->db->getPdo()->prepare(
            "INSERT INTO seo_meta (entity_type, entity_id, focus_keyword, meta_description, checked_at, updated_at)
             VALUES (?, ?, ?, ?, datetime('now'), datetime('now'))
             ON CONFLICT(entity_type, entity_id) DO UPDATE SET
                focus_keyword    = excluded.focus_keyword,
                meta_description = excluded.meta_description,
                updated_at       = datetime('now')"
        );
        $stmt->execute([$entityType, (string) $id, $focus, $desc]);
    }

    private function body(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();
        return is_array($body) ? $body : [];
    }

    private function intId(mixed $value): int
    {
        return max(0, (int) $value);
    }

    private function str(mixed $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        $s = trim(strip_tags((string) $value));
        // Strip control characters: they survive JSON and confuse log output,
        // and no legitimate CMS field contains them.
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? $s;
        return mb_substr($s, 0, $max);
    }

    private function slug(string $value): string
    {
        $slug = slugify($value);
        return $slug !== '' ? $slug : 'untitled';
    }

    /** Constrain a value to a fixed set; anything unexpected falls back. */
    private function oneOf(mixed $value, array $allowed, string $fallback): string
    {
        $value = (string) $value;
        return in_array($value, $allowed, true) ? $value : $fallback;
    }

    /**
     * Enforce a minimum role for the CALLER.
     *
     * Route-level RoleMiddleware already covers this for the routes that have
     * a configured minimum. This is the second line for actions whose blast
     * radius depends on WHICH user is being touched (demoting an admin,
     * changing global settings) rather than on the HTTP verb — those checks
     * cannot live in a static per-route middleware config.
     */
    /**
     * Whether the caller holds at least $role.
     *
     * Returns a boolean rather than throwing. RoleMiddleware already answers
     * 403 for the ROUTE's minimum role, but some guards depend on WHO is
     * calling and cannot be expressed as a static per-route role (the
     * last-admin check, self-demotion). A previous version threw a
     * RuntimeException, which reached Slim's default handler and came back as
     * HTTP 500 — telling the caller the server broke and sending an operator to
     * a stack trace for what is an ordinary permission denial.
     *
     * Returns the 403 response instead of a bool at the call sites that need
     * to short-circuit, via forbid() below.
     */
    private function hasRole(ServerRequestInterface $request, string $role): bool
    {
        $have = Context::ROLES[Context::normalizeRole($request->getAttribute('user_role'))] ?? 0;
        $need = Context::ROLES[$role] ?? PHP_INT_MAX;

        return $have >= $need;
    }

    /**
     * Deny helper matching RoleMiddleware's JSON error shape.
     *
     * @param ResponseInterface $response the 403 to return
     */
    private function forbid(ResponseInterface $response, string $role): ResponseInterface
    {
        return Responder::error($response, 403, "This action requires the {$role} role");
    }

    /**
     * The caller's normalized role, for echoing back to a UI.
     *
     * Normalized rather than raw because the raw attribute is whatever the
     * token said, and a client rendering a role name needs the same string
     * hasRole() compared against — otherwise "Admin" and "admin" appear in
     * two places and an operator is told they are an author.
     */
    private function roleOf(ServerRequestInterface $request): string
    {
        return Context::normalizeRole($request->getAttribute('user_role'));
    }
}