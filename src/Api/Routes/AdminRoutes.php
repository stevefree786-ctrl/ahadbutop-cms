<?php
/**
 * Admin UI routes.
 *
 * Registered as a separate file because these are the only routes that render
 * HTML — everything else in /api/v1 answers JSON. They live under /admin so
 * the API prefix stays clean, and they are registered BEFORE the public site's
 * catch-all so /admin/... never falls through to a themed 404.
 *
 * Auth: every screen except the login page goes through AuthMiddleware. The
 * middleware is what supplies $args['user']; the controller reads it and never
 * trusts a role the browser sent.
 */

namespace CMS\Api\Routes;

use CMS\Api\Controllers\AdminController;
use CMS\Auth\AuthMiddleware;
use CMS\Auth\CsrfMiddleware;
use CMS\Auth\RoleMiddleware;
use CMS\Auth\JwtService;
use CMS\Database\Connection;
use Psr\Http\Message\ResponseInterface as Response;
use Slim\App;

final class AdminRoutes
{
    public static function register(App $app, array $authConfig): void
    {
        $db     = new Connection(require dirname(__DIR__, 3) . '/config/database.php');
        $jwt    = new JwtService($authConfig);
        $rf     = $app->getResponseFactory();
        $auth   = new AuthMiddleware($jwt, $authConfig, $rf, '/login');
        $csrf   = new CsrfMiddleware($rf, $jwt);
        $admin  = new AdminController($db, $authConfig);

        // ---- public ---------------------------------------------------------

        // The login screen, at the URL a person types: /login for a person, and
        // /admin/login for the logout link in admin.js. /admin/login redirects
        // rather than rendering a second copy, so there is one screen, one URL
        // to bookmark, and no way for the two to drift apart.

        // Declared BEFORE the public site's catch-all. Slim matches in
        // registration order, so a catch-all registered first would swallow
        // /login and answer with a themed 404.
        $app->get('/login', self::loginScreen($db));

        $app->get('/admin/login', function ($request, Response $response) {
            $next = (string) ($request->getQueryParams()['next'] ?? '');
            $safe = $next !== '' && str_starts_with($next, '/')
                 && !str_starts_with($next, '//') && !str_starts_with($next, '/\\');

            return $response
                ->withStatus(301)
                ->withHeader('Location', $safe ? '/login?next=' . rawurlencode($next) : '/login');
        });

        // ---- authenticated --------------------------------------------------

        /**
         * Register an admin screen behind auth.
         *
         * $minRole is null for "any signed-in user" — the author role is the
         * floor the role middleware already enforces.
         *
         * The wrapper lifts the JWT claims AuthMiddleware attached into a
         * 'user' array. AdminController reads $args['user'] for the display
         * name and role it renders, and AuthMiddleware only publishes flat
         * attributes — user_id, user_uuid, user_role, user_email — so the
         * shape has to be assembled here rather than guessed at in the
         * controller.
         */
        $screen = static function (string $method, string $path, callable $handler, ?string $minRole = null) use ($app, $admin, $auth, $rf, $csrf) {
            $wrapped = static function ($request, $response, $args) use ($handler) {
                return $handler(
                    $request,
                    $response,
                    $args + [
                        'user' => [
                            'id'    => (int) $request->getAttribute('user_id', 0),
                            'uuid'  => $request->getAttribute('user_uuid'),
                            'role'  => (string) $request->getAttribute('user_role', 'author'),
                            'email' => $request->getAttribute('user_email'),
                        ],
                    ]
                );
            };

            $route = $app->map([$method], $path, $wrapped);
            if ($minRole !== null) {
                $route->add(new RoleMiddleware($minRole, $rf));
            }
            // LIFO: auth is added last so it runs first, because CSRF needs
            // the user_id it attaches to bind the token to a session.
            $route->add($csrf);
            $route->add($auth);
        };

        /*
         * /admin is the dashboard AND the redirect target for /admin/.
         *
         * Both spellings are registered because only one of them can win:
         * Slim matches in registration order and '/admin' does not match a
         * request for '/admin/', which arrives with a trailing slash. Without
         * the second entry the nav's own "Dashboard" link renders a themed 404
         * — the admin controller's dashboard() and its template both existed
         * and were simply unreachable.
         */
        $screen('GET', '/admin',              fn($rq, $rs, $a) => $admin->dashboard($rq, $rs, $a));
        $screen('GET', '/admin/',             fn($rq, $rs, $a) => $admin->dashboard($rq, $rs, $a));
        $screen('GET', '/admin/posts',        fn($rq, $rs, $a) => $admin->posts($rq, $rs, $a));
        $screen('GET', '/admin/editor/new',   fn($rq, $rs, $a) => $admin->editor($rq, $rs, $a));
        $screen('GET', '/admin/editor/{id:[0-9]+}', fn($rq, $rs, $a) => $admin->editor($rq, $rs, $a));
        $screen('GET', '/admin/media',        fn($rq, $rs, $a) => $admin->media($rq, $rs, $a));
        $screen('GET', '/admin/taxonomy',     fn($rq, $rs, $a) => $admin->taxonomy($rq, $rs, $a));
        $screen('GET', '/admin/agents',       fn($rq, $rs, $a) => $admin->agents($rq, $rs, $a));
        $screen('GET', '/admin/skills',       fn($rq, $rs, $a) => $admin->skills($rq, $rs, $a));
        $screen('GET', '/admin/chat',         fn($rq, $rs, $a) => $admin->chat($rq, $rs, $a));
        $screen('GET', '/admin/jobs',         fn($rq, $rs, $a) => $admin->jobs($rq, $rs, $a));
        $screen('GET', '/admin/seo',          fn($rq, $rs, $a) => $admin->seo($rq, $rs, $a));

        // Users and settings are account-wide, so admin only — an editor gets a
        // 403 rather than a nav link that appears to work.
        $screen('GET', '/admin/users',    fn($rq, $rs, $a) => $admin->users($rq, $rs, $a),    'admin');
        $screen('GET', '/admin/settings', fn($rq, $rs, $a) => $admin->settings($rq, $rs, $a), 'admin');

        // ---- static assets --------------------------------------------------

        // admin.css and admin.js are served straight from public/. The route is
        // matched before the catch-all so they are never rendered as a post.
        //
        // The pattern uses a NON-CAPTURING group. FastRoute rejects any
        // capturing group inside a route pattern outright — it throws
        // BadRouteException at DISPATCH time, not registration time, which is
        // why the app booted fine and then failed every single request
        // including /health. `admin\.(?:css|js)` is accepted; `admin\.(css|js)`
        // takes the whole site down.
        $app->get('/assets/admin/{file:admin\.(?:css|js)}', function ($request, Response $response, $args) {
            $file = dirname(__DIR__, 3) . '/public/assets/admin/' . $args['file'];
            // The pattern already constrains the name; re-check anyway so a
            // future pattern change cannot turn this into a file read.
            if (!is_file($file) || basename($file) !== $args['file']) {
                return $response->withStatus(404);
            }
            $response->getBody()->write((string) file_get_contents($file));
            return $response->withHeader(
                'Content-Type',
                str_ends_with($args['file'], '.css') ? 'text/css; charset=utf-8' : 'application/javascript; charset=utf-8'
            );
        });
    }

    /**
     * Render the sign-in screen.
     *
     * The 'next' it honours is read from getQueryParams(), never from the URI
     * string. Slim matches on the path alone and leaves the query untouched, so
     * /login?next=/admin arrives with an EMPTY query-param bag; the value is
     * on $request->getUri()->getQuery() and has to be parsed here.
     *
     * Reading the raw URI instead would appear to work under the test factory
     * (which builds a bare path with no query) and return nothing under a real
     * server, so every deep link would silently land on /admin instead of the
     * screen it asked for. Reading the parsed bag is correct in both.
     *
     * @param Connection $db Injected rather than constructed so this can stay
     *                      a static method.
     */
    private static function loginScreen(Connection $db): callable
    {
        // A TEMPLATE requires a plain `require`, never require_once.
        //
        // require_once keys on the FILE, so the second render in the same
        // process is skipped outright and the output buffer comes back empty —
        // a 200 with no body. The first /login of a request looked perfect and
        // every later one was blank, which is the worst shape a bug can take:
        // it reproduces only under a long-lived worker, and never in a
        // single-request test.
        //
        // partials.php is the exception: it defines FUNCTIONS, and redefining
        // one would be a fatal error, so it stays require_once. Function
        // definitions live in the file's symbol table, not in the output, so
        // skipping it costs nothing.
        return static function ($request, Response $response) use ($db) {
            require_once dirname(__DIR__, 3) . '/resources/admin/partials.php';

            $siteTitle = 'CMS';
            try {
                $row = $db->selectOne('settings', ['key' => 'site.title']);
                if ($row && !empty($row['value'])) {
                    $siteTitle = (string) $row['value'];
                }
            } catch (\Throwable) {
                // A login page must render even when settings cannot be read.
                // Refusing to show the form over a missing title row would lock
                // everyone out of the very page that fixes it.
            }

            // 'next' is validated HERE, before it can reach a redirect. An
            // unchecked value would make /login an open redirect: an attacker
            // sends a victim a real-looking link that, after a genuine login,
            // continues to their site with the session cookie attached.
            $next = $request->getQueryParams()['next'] ?? '';
            if (!is_string($next)
                || !str_starts_with($next, '/')
                || str_starts_with($next, '//')
                || str_starts_with($next, '/\\')
            ) {
                $next = '/admin';
            }

            $site_title = $siteTitle;

            ob_start();
            require dirname(__DIR__, 3) . '/resources/admin/login.php';
            $html = (string) ob_get_clean();

            // The markup has to actually reach the socket. Capturing it into
            // $html and never writing it yields a 200 with an empty body — a
            // login page that exists, answers successfully, and shows a person
            // nothing at all.
            $response->getBody()->write($html);

            return $response
                ->withHeader('Content-Type', 'text/html; charset=utf-8')
                // Never cacheable: a shared cache holding a login page serves it
                // to the next person, and holds the previous user's 'next'.
                ->withHeader('Cache-Control', 'no-store');
        };
    }
}