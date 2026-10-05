<?php
/**
 * Public site routes.
 *
 * Mounted by public/index.php AFTER /health and every /api/v1/* route.
 *
 * The ordering is the whole point of this file existing separately from
 * src/Api/Routes.php: Slim matches routes in registration order, so the
 * catch-all below would swallow every /api/v1/... request (and the health
 * probe) if it were declared before them, serving a themed HTML 404 where
 * the caller expects JSON. Declared last, it only ever runs when no API
 * route claimed the path — which is exactly what "no other route matched"
 * means to Slim's router.
 *
 * It is `any()` rather than `get()` on purpose: a HEAD to a page must get
 * the same 200/404 as the GET (handled inside Router::handle), and a
 * mismatched verb must still resolve to a 405 page rather than Slim's own
 * bare 404, so a client can tell "no such page" from "wrong verb here".
 */
declare(strict_types=1);

use CMS\Database\Connection;
use CMS\Render\Router;
use Slim\Psr7\Factory\ResponseFactory;

/** @var \Slim\App $app */

$app->any('/{path:.*}', function ($request) {
    static $router = null;

    // One router per process: the theme and the settings lookup are shared
    // across the requests a long-lived worker handles.
    $router ??= new Router(
        new Connection(require base_path('config/database.php')),
        null,
        (new ResponseFactory())
    );

    return $router->handle($request);
})->setName('site');