<?php
/**
 * CMS Routes - API endpoints
 *
 * Route protection follows config/auth.php 'permissions':
 *   GET    -> author  (any signed-in user)
 *   POST   -> editor
 *   PUT    -> editor
 *   PATCH  -> editor
 *   DELETE -> admin
 *
 * Read-only routes under /auth are public (login) or require a token
 * (me, logout). Nothing writes without passing AuthMiddleware.
 */
use CMS\Api\Responder;
use CMS\Auth\AuthMiddleware;
use CMS\Auth\JwtService;
use CMS\Auth\RoleMiddleware;
use CMS\Database\Connection;
use Slim\Routing\RouteCollectorProxy;

// Shared singletons for the whole request.
$db         = new Connection(require __DIR__ . '/../../config/database.php');
$authConfig = require __DIR__ . '/../../config/auth.php';
$jwt        = new JwtService($authConfig);

// PSR-7 has no response factory on the request; the app owns one.
$responseFactory = $app->getResponseFactory();

$auth    = new AuthMiddleware($jwt, $authConfig, $responseFactory);
$csrf    = new \CMS\Auth\CsrfMiddleware($responseFactory, $jwt);
$perms   = $authConfig['permissions'];

// One controller for the whole request — no per-call construction.
$authController = new \CMS\Api\Controllers\AuthController($db, $jwt, $authConfig);

/**
 * Apply [AuthMiddleware, RoleMiddleware] to a route using the config map.
 *
 * Order matters: Slim's addMiddleware is LIFO, so the middleware added
 * LAST runs FIRST. Auth must run before Role (Role reads user_role) and
 * before CSRF (CSRF reads user_id), so auth is added LAST of the three.
 *
 * Pass $minRole to override the config default for routes whose blast
 * radius exceeds the HTTP verb (e.g. revoking every session).
 */
$protect = function (RouteCollectorProxy $group) use ($auth, $csrf, $perms, $responseFactory) {
    return function (string $method, string $pattern, callable $handler, ?string $minRole = null) use ($group, $auth, $csrf, $perms, $responseFactory) {
        $group->map([$method], $pattern, $handler)
            ->add(new RoleMiddleware($minRole ?? ($perms[$method] ?? 'author'), $responseFactory))
            // Between auth and role: it needs user_id to bind the token, but
            // must run before role so a forged request is refused on CSRF
            // grounds rather than leaking whether the role would have passed.
            ->add($csrf)
            ->add($auth);
    };
};


/*
 * RSS, at /feed.xml.
 *
 * The admin SEO screen links to /feed.xml, but nothing served that path —
 * the link led to a themed 404 that read like a broken page rather than a
 * missing feature. A feed is also how an AI-written CMS earns traffic: it
 * is the one surface a reader can subscribe to without an account.
 *
 * Public on purpose, like the sitemap: a feed carries no user data, and
 * requiring a token would make it useless to the one thing it is for.
 *
 * Registered here rather than in src/Render/Routes.php because that file is
 * a single catch-all declared LAST — anything registered after it is
 * unreachable.
 */
$app->get('/feed.xml', function ($request, $response) use ($db) {
    $base = $request->getUri()->getScheme() . '://' . $request->getUri()->getHost();

    $rows = $db->query(
        'SELECT slug, title, excerpt, published_at
           FROM posts
          WHERE status = \'published\' AND type = \'post\'
          ORDER BY published_at DESC
          LIMIT 30'
    )->fetchAll();

    // RFC 822 dates. published_at is 'YYYY-MM-DD HH:MM:SS' in UTC, which
    // strtotime parses with the explicit UTC suffix appended; a missing or
    // unparsable value becomes now() rather than the epoch, so one bad row
    // cannot date the entire feed to 1970.
    $rfc822 = static function (?string $value): string {
        $ts = ($value !== null && $value !== '') ? strtotime($value . ' UTC') : false;
        return date('D, d M Y H:i:s', $ts === false ? time() : $ts) . ' GMT';
    };

    $siteTitle = 'CMS';
    try {
        $row = $db->selectOne('settings', ['key' => 'site.title']);
        if ($row && !empty($row['value'])) {
            $siteTitle = (string) $row['value'];
        }
    } catch (\Throwable) {
        // A missing settings row must not take the feed down with it.
    }

    $x = static fn(string $v): string => htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');

    $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
    $xml .= "<rss version=\"2.0\" xmlns:atom=\"http://www.w3.org/2005/Atom\">\n";
    $xml .= "  <channel>\n";
    $xml .= '    <title>' . $x($siteTitle) . "</title>\n";
    $xml .= '    <link>' . $x($base) . "</link>\n";
    $xml .= "    <description>" . $x('Recent posts') . "</description>\n";
    $xml .= "    <language>en</language>\n";
    // The self-referencing atom:link is how a feed reader learns the
    // canonical URL of the feed it is subscribed to.
    $xml .= '    <atom:link href="' . $x($base . '/feed.xml')
          . '" rel="self" type="application/rss+xml" />' . "\n";

    foreach ($rows as $r) {
        $url = $base . '/posts/' . rawurlencode((string) $r['slug']);
        $xml .= "    <item>\n";
        $xml .= '      <title>' . $x((string) ($r['title'] ?? '')) . "</title>\n";
        $xml .= '      <link>' . $x($url) . "</link>\n";
        $xml .= '      <guid isPermaLink="true">' . $x($url) . "</guid>\n";
        $xml .= '      <description>' . $x((string) ($r['excerpt'] ?? '')) . "</description>\n";
        $xml .= '      <pubDate>' . $rfc822($r['published_at'] ?? null) . "</pubDate>\n";
        $xml .= "    </item>\n";
    }

    $xml .= "  </channel>\n</rss>";

    $response->getBody()->write($xml);

    return $response
        ->withHeader('Content-Type', 'application/rss+xml; charset=utf-8')
        // Readers poll this; a long cache means they never see a new post
        // until it expires.
        ->withHeader('Cache-Control', 'public, max-age=600');
});

// --- Public (no token required) ---------------------------------------------
$app->post('/api/v1/auth/login', function ($request, $response) use ($authController) {
    return $authController->login($request, $response);
});

$app->post('/api/v1/auth/refresh', function ($request, $response) use ($authController) {
    return $authController->refresh($request, $response);
});

// Logout is public: the refresh token IS the credential being revoked, so
// requiring a (possibly expired) access token would strand active sessions.
$app->post('/api/v1/auth/logout', function ($request, $response) use ($authController) {
    return $authController->logout($request, $response);
});

$app->group('/api/v1', function (RouteCollectorProxy $group) use ($db, $protect, $auth, $authController) {

    // --- Authenticated -----------------------------------------------------
    // Revoking every session is account-wide — admin only, not merely editor.
    $protect($group)('POST', '/auth/logout-all', function ($request, $response) use ($authController) {
        return $authController->logoutAll($request, $response);
    }, 'admin');

    $group->get('/auth/me', function ($request, $response) use ($authController) {
        return $authController->me($request, $response);
    })->add($auth);

    // --- Posts (read = author, write = editor) -----------------------------
    $protect($group)('GET', '/posts', function ($request, $response, $args) use ($db) {
        $stmt = $db->query(
            'SELECT id, uuid, slug, type, title, excerpt, status, origin, author_id,
                    word_count, reading_time, published_at, updated_at
             FROM posts
             WHERE status != \'trash\'
             ORDER BY created_at DESC
             LIMIT 50'
        );
        return Responder::ok($response, ['posts' => $stmt->fetchAll()]);
    });

    $protect($group)('GET', '/posts/{slug}', function ($request, $response, $args) use ($db) {
        // Connection::query() binds params properly — the old code called
        // PDO::query($sql, [$slug]), whose 2nd arg is silently ignored.
        $stmt = $db->query(
            'SELECT id, uuid, slug, type, title, excerpt, body_md, status, origin,
                    author_id, word_count, reading_time, published_at, updated_at
             FROM posts WHERE slug = ? AND status != \'trash\' LIMIT 1',
            [$args['slug']]
        );
        $post = $stmt->fetch();
        return $post
            ? Responder::ok($response, ['post' => $post])
            : Responder::notFound($response, 'Post');
    });

    // POST /posts lives in the admin write layer below, not here: it needs the
    // term sync, the SEO upsert and the draft-by-default rule, none of which
    // a thin inline handler can express. Registering it twice would leave the
    // FIRST match winning in Slim.

    // --- Jobs ---------------------------------------------------------------
    // One JobQueueController for the whole request, sharing the Connection the
    // routes already hold — no per-route construction, no second SQLite handle.
    $jobQueueController = new \CMS\Api\Controllers\JobQueueController($db);

    $protect($group)('GET', '/jobs', function ($request, $response) use ($jobQueueController) {
        return $jobQueueController->index($request);
    });

    // MUST be registered before '/jobs/{id}'. Slim matches routes in
    // registration order and {id} accepts any single segment, so a literal
    // segment declared afterwards is unreachable — the placeholder wins and
    // /jobs/stats is read as a job with the id "stats".
    $protect($group)('GET', '/jobs/stats', function ($request, $response) use ($jobQueueController) {
        return $jobQueueController->stats($request);
    });

    // Enqueueing work is a mutation, so it takes the POST role (editor), even
    // though the payload is a description rather than stored state.
    $protect($group)('POST', '/jobs', function ($request, $response) use ($jobQueueController) {
        return $jobQueueController->create($request);
    }, 'editor');

    $protect($group)('GET', '/jobs/{id}', function ($request, $response, $args) use ($jobQueueController) {
        return $jobQueueController->show($request->withAttribute('id', $args['id']));
    });

    // Cancelling stops work already in flight: editors may, but not authors.
    $protect($group)('POST', '/jobs/{id}/cancel', function ($request, $response, $args) use ($jobQueueController) {
        return $jobQueueController->cancel($request->withAttribute('id', $args['id']));
    }, 'editor');

    // Replaying a job re-runs an AI call and re-writes content: admin only.
    $protect($group)('POST', '/jobs/{id}/retry', function ($request, $response, $args) use ($jobQueueController) {
        return $jobQueueController->retry($request->withAttribute('id', $args['id']));
    }, 'admin');

    // --- SEO (public crawl endpoints) ---------------------------------------
    $group->get('/seo/sitemap.xml', function ($request, $response) use ($db) {
        $base = $request->getUri()->getScheme() . '://' . $request->getUri()->getHost();
        $rows = $db->query(
            'SELECT slug, updated_at FROM posts
             WHERE status = \'published\' AND type = \'post\' ORDER BY published_at DESC'
        )->fetchAll();

        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        $xml .= "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
        foreach ($rows as $r) {
            $xml .= "  <url><loc>" . htmlspecialchars("$base/posts/" . $r['slug'], ENT_XML1)
                 . "</loc><lastmod>" . substr((string) $r['updated_at'], 0, 10) . "</lastmod></url>\n";
        }
        $xml .= "</urlset>";

        $response->getBody()->write($xml);
        return $response->withHeader('Content-Type', 'application/xml; charset=utf-8');
    });

    $group->get('/seo/robots.txt', function ($request, $response) {
        $base = $request->getUri()->getScheme() . '://' . $request->getUri()->getHost();
        $response->getBody()->write(
            "User-agent: *\n"
            . "Allow: /\n"
            // /admin is listed as Disallow rather than merely omitted: without
            // it a crawler will discover the login screen through links in
            // rendered output and index it.
            . "Disallow: /admin\n"
            . "Disallow: /api/\n"
            . "Sitemap: {$base}/api/v1/seo/sitemap.xml\n"
        );
        return $response->withHeader('Content-Type', 'text/plain; charset=utf-8');
    });

    // --- Settings -----------------------------------------------------------
    $protect($group)('GET', '/settings', function ($request, $response) use ($db) {
        $rows = $db->query('SELECT key, value, scope, updated_at FROM settings ORDER BY key')->fetchAll();
        return Responder::ok($response, ['settings' => $rows]);
    });

    // --- Admin write layer --------------------------------------------------
    //
    // The admin UI is server-rendered but every mutation it performs goes
    // through these endpoints, so there is exactly one set of role checks and
    // one CSRF story rather than a second admin-only path that rots.
    $adminApi = new \CMS\Api\Controllers\AdminApiController($db);

    /*
     * Posts.
     *
     * The floor is left at the config default (POST/PUT -> editor) rather
     * than overridden to 'author'. These two routes previously carried an
     * explicit 'author' override, which contradicted both config/auth.php
     * ("Read = author, write = editor") and tests/rbac_test.php, and did so in
     * the permissive direction: any signed-in author could create and rewrite
     * published posts. createPost() has no role check of its own, so the
     * route floor was the only gate.
     *
     * Authoring still works — it goes through BlogAgent, whose save_draft_post
     * intent is editor-gated, and through the admin editor screen, which is
     * role-checked per screen. If an author-owns-drafts role is ever wanted,
     * it belongs in one place with a comment, not as a silent override here.
     */
    $protect($group)('POST', '/posts', function ($request, $response) use ($adminApi) {
        return $adminApi->createPost($request, $response);
    });

    $protect($group)('PUT', '/posts/{id:[0-9]+}', function ($request, $response, $args) use ($adminApi) {
        return $adminApi->updatePost($request, $response, $args);
    });

    // Literal segments MUST be declared before the placeholder they would
    // otherwise be swallowed by: Slim matches in registration order, and
    // /posts/{id}/publish would otherwise match /posts/{id}/... first.
    foreach (['publish', 'unpublish', 'trash', 'restore'] as $transition) {
        $protect($group)('POST', "/posts/{id:[0-9]+}/{$transition}", function ($request, $response, $args) use ($adminApi, $transition) {
            return $adminApi->postTransition($request, $response, $args + ['transition' => $transition]);
        }, 'editor');
    }

    // SEO meta. The entity type comes from the route, never the body.
    $protect($group)('POST', '/seo/{entity:post|page}/{id:[0-9]+}', function ($request, $response, $args) use ($adminApi) {
        return $adminApi->saveSeo($request, $response, $args);
    }, 'editor');

    // Users — admin only. RoleMiddleware enforces it; the controller re-checks
    // because some guards (last-admin, self-demotion) depend on WHO is calling
    // and cannot be expressed as a static per-route role.
    $protect($group)('POST', '/users', function ($request, $response) use ($adminApi) {
        return $adminApi->createUser($request, $response);
    }, 'admin');

    $protect($group)('PATCH', '/users/{id:[0-9]+}', function ($request, $response, $args) use ($adminApi) {
        return $adminApi->updateUser($request, $response, $args);
    }, 'admin');

    $protect($group)('DELETE', '/users/{id:[0-9]+}', function ($request, $response, $args) use ($adminApi) {
        return $adminApi->deleteUser($request, $response, $args);
    }, 'admin');

    // Taxonomy
    $protect($group)('POST', '/taxonomy/{kind:tags|categories}', function ($request, $response, $args) use ($adminApi) {
        return $adminApi->createTerm($request, $response, $args);
    }, 'editor');

    $protect($group)('DELETE', '/taxonomy/{kind:tags|categories}/{id:[0-9]+}', function ($request, $response, $args) use ($adminApi) {
        return $adminApi->deleteTerm($request, $response, $args);
    }, 'editor');

    $protect($group)('POST', '/taxonomy/tags/recompute', function ($request, $response) use ($adminApi) {
        return $adminApi->recomputeTags($request, $response);
    }, 'editor');

    // Settings — editing configuration is account-wide, so admin only.
    $protect($group)('PUT', '/settings', function ($request, $response) use ($adminApi) {
        return $adminApi->updateSettings($request, $response);
    }, 'admin');

    /*
     * Agents.
     *
     * The route floor is 'author', NOT the 'editor' that config/auth.php
     * assigns to POST by default, and that override is load-bearing.
     * RoleMiddleware runs BEFORE the handler, so an 'editor' floor here
     * rejects every author outright and AdminApiController::runAgent() never
     * executes — making its per-agent AGENT_MIN_ROLE table unreachable.
     *
     * At 'author' the handler does the real check: it resolves the agent from
     * the fixed allowlist, then requires the role that specific agent
     * declares. Read-only agents (Search, Analytics, Social) pass; every
     * write-capable one still requires editor, and each also re-checks its
     * own role internally before writing.
     */
    $protect($group)('POST', '/agents/run', function ($request, $response) use ($adminApi) {
        return $adminApi->runAgent($request, $response);
    }, 'author');

    /*
     * The CEO, at its own address.
     *
     * The floor is 'editor' rather than 'author', unlike /agents/run beside
     * it, because this route has no per-agent table to fall through to — the
     * agent is fixed. RoleMiddleware runs before the handler, so an editor
     * floor here means an author is stopped at the door rather than reaching
     * a handler-level check.
     */
    $protect($group)('POST', '/agents/ceo', function ($request, $response) use ($adminApi) {
        return $adminApi->runCeo($request, $response);
    }, 'editor');

    /*
     * Skills.
     *
     * Floor 'author' on both, for the reason given on /agents/run above: the
     * per-step role check lives in Skills::run(), so an editor floor here
     * would reject the whole request before the caller could be told WHICH
     * step was refused. The catalog is harmless at any role; the run is
     * gated per step by the same registry every other write uses.
     */
    $protect($group)('GET', '/skills', function ($request, $response) use ($adminApi) {
        return $adminApi->listSkills($request, $response);
    }, 'author');

    $protect($group)('POST', '/skills/run', function ($request, $response) use ($adminApi) {
        return $adminApi->runSkill($request, $response);
    }, 'author');

    /*
     * Deep research, at its own address rather than through /agents/run.
     *
     * A named route rather than an agent in the allowlist means the operator
     * chooses to browse. Leaving it to the model would let any page the
     * fetcher returns influence whether the next thing that happens is
     * another fetch — one step from a stranger's text steering the agent.
     */
    $protect($group)('POST', '/research', function ($request, $response) use ($adminApi) {
        return $adminApi->research($request, $response);
    }, 'author');

    /*
     * BYOK status.
     *
     * 'admin' because this screen holds live credentials. It returns whether
     * a key EXISTS and never the key itself — the write paths (set_provider_key
     * / clear_provider_key) go through IntentRegistry, not through here.
     */
    $protect($group)('GET', '/ai/providers', function ($request, $response) use ($adminApi) {
        return $adminApi->listProviders($request, $response);
    }, 'admin');

    /*
     * BYOK writes.
     *
     * These take a credential directly from the request body and put it in
     * the database. They are deliberately NOT implemented as agent intents
     * reached through /agents/run: that path serialises the task and sends it
     * to the model provider, so a key pasted into one would leave the server.
     * The set_provider_key INTENT still exists for a CEO-driven rotation —
     * but that is an operator asking a model to do it deliberately, not a
     * form field. This comment is the thing to read before "simplifying"
     * these two into the agents route.
     */
    $protect($group)('POST', '/ai/providers', function ($request, $response) use ($adminApi) {
        return $adminApi->saveProviderKey($request, $response);
    }, 'admin');

    $protect($group)('DELETE', '/ai/providers/{provider}', function ($request, $response, $args) use ($adminApi) {
        return $adminApi->deleteProviderKey(
            $request->withAttribute('provider', $args['provider'] ?? ''),
            $response
        );
    }, 'admin');
});