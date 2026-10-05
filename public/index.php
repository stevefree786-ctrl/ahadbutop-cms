<?php
/**
 * CMS Application Entry Point
 * Slim Framework 4 application with PSR-15 middleware stack.
 */
declare(strict_types=1);

use Slim\Factory\AppFactory;
use Tuupola\Middleware\CorsMiddleware;

require_once __DIR__ . '/../vendor/autoload.php';

// Load .env if present (safe no-op when absent)
if (is_file(base_path('.env'))) {
    Dotenv\Dotenv::createImmutable(base_path())->safeLoad();
}

$config = require __DIR__ . '/../config/database.php';

// Build Slim app
$app = AppFactory::create();
$app->addBodyParsingMiddleware();

// CORS — permissive list comes from config, not a wildcard constant
$app->add(new CorsMiddleware([
    'origin' => (array) ($config['cors']['allowed_origins'] ?? ['*']),
    'headers' => ['Content-Type', 'Authorization'],
    'methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    'credentials' => (bool) ($config['cors']['credentials'] ?? false),
]));

// Error handling: details only when debug is on (never leak stack traces in prod)
$debug = (bool) env('APP_DEBUG', false);
$app->addErrorMiddleware($debug, true, $debug);

// Health check — actually verifies the database instead of asserting it
$app->get('/health', function ($request, $response) {
    $dbOk = false;
    try {
        $pdo = (new \CMS\Database\Connection(require __DIR__ . '/../config/database.php'))->getPdo();
        $pdo->query('SELECT 1');
        $dbOk = true;
    } catch (\Throwable $e) {
        $dbOk = false;
    }

    $data = [
        'status' => $dbOk ? 'ok' : 'degraded',
        'version' => '1.0.0',
        'php' => PHP_VERSION,
        'db' => $dbOk ? 'connected' : 'unavailable',
    ];
    $response->getBody()->write(json_encode($data));
    return $response->withHeader('Content-Type', 'application/json');
});

// Include API routes
require_once __DIR__ . '/../src/Api/Routes.php';

// --- Admin UI -------------------------------------------------------------
// Registered after /api/v1/* and before the public catch-all. The order matters
// twice over: an admin route must win over the site's catch-all (otherwise
// /admin/settings renders as a themed 404), and the catch-all must still lose
// to every API route above.
CMS\Api\Routes\AdminRoutes::register($app, require __DIR__ . '/../config/auth.php');

// --- Public site (catch-all, registered LAST) ------------------------------
// Slim matches routes in REGISTRATION ORDER, so this must come after
// /health and every /api/v1/* route above. Registered any earlier, the
// catch-all would win on those paths and turn a JSON API reply (and the
// health probe) into a themed HTML 404 — the API would appear to be broken
// while every other request looked fine. Putting it last is what makes it a
// fallback for "no API route claimed this" rather than a competitor to them.
require_once __DIR__ . '/../src/Render/Routes.php';

// When run directly (php -S / direct FPM hit) build AND run the app.
// When included by a test or another bootstrap, return it unrun.
if (PHP_SAPI !== 'cli' || basename((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === 'index.php') {
    $app->run();
}

return $app;