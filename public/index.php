<?php
/**
 * CMS Application Entry Point
 * Slim Framework 4 application with PSR-15 middleware stack.
 */
declare(strict_types=1);

use Slim\Factory\AppFactory;
use Tuupola\Middleware\CorsMiddleware;

require_once __DIR__ . '/../vendor/autoload.php';

// Load .env if present (safe no-op when absent).
//
// cms_env_load(), not Dotenv: phpdotenv v5 populates only its own repository
// object, which exposes no getter, and its default adapters write to neither
// $_ENV, $_SERVER nor getenv(). Loading it that way left env() returning the
// default for every value in the file — CMS_ENCRYPTION_KEY read as unset, and
// the BYOK screen refused to save a key that was sitting right there in .env.
//
// Dotenv still runs after it, for anything that reads the repository directly.
if (is_file(base_path('.env'))) {
    cms_env_load(base_path('.env'));
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

// Turn a fatal into a readable answer instead of a wall of HTML.
//
// Execution-time and memory fatals are not catchable — PHP tears the request
// down mid-statement and prints its own error, which lands in an API client
// as unparseable HTML. That is exactly what an agent run returned when a
// provider call outlasted max_execution_time: the UI showed a <br /><b>Fatal
// error</b> block instead of "the request took too long".
//
// A shutdown handler is the only place that still runs afterwards. It only
// speaks when output has NOT begun, so it can never corrupt a response that is
// already streaming — a half-sent page is worse than a slow one.
register_shutdown_function(static function (): void {
    $error = error_get_last();

    if ($error === null
        || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        return;
    }

    // Headers already sent => a response is in flight. Appending anything now
    // would produce a body that is half JSON and half HTML.
    if (headers_sent()) {
        return;
    }

    $isTimeout = $error['type'] === E_ERROR
        && stripos($error['message'], 'Maximum execution time') !== false;

    http_response_code($isTimeout ? 504 : 500);

    // An API or admin client wants JSON; a browser can take either, and JSON is
    // still the safer of the two here because it cannot inject markup.
    $wantsJson = str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
        || strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
        || str_starts_with((string) ($_SERVER['REQUEST_URI'] ?? ''), '/api/');

    if ($wantsJson) {
        header('Content-Type: application/json');
        echo json_encode([
            'error'  => $isTimeout
                ? 'The request took too long and was stopped. An AI provider that slow usually means the key is wrong or the endpoint is unreachable — check the AI Providers screen.'
                : 'The request failed unexpectedly.',
            'status' => $isTimeout ? 504 : 500,
        ], JSON_UNESCAPED_SLASHES);
        return;
    }

    header('Content-Type: text/html; charset=utf-8');
    $message = $isTimeout
        ? 'This request took too long and was stopped.'
        : 'Something went wrong handling this request.';
    $detail  = htmlspecialchars($error['message'], ENT_QUOTES, 'UTF-8');

    echo '<!doctype html><meta charset="utf-8">'
        . '<title>Request failed</title>'
        . '<div style="font:16px/1.5 system-ui,sans-serif;max-width:40rem;margin:15vh auto;padding:0 1.5rem">'
        . '<h1 style="font-size:1.25rem;margin:0 0 .5rem">Request failed</h1>'
        . '<p style="margin:0 0 1rem;color:#444">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>'
        . ($debug ? '<pre style="white-space:pre-wrap;font-size:13px;color:#666">'
            . $detail . '</pre>' : '')
        . '<p><a href="/">Back to the site</a></p></div>';
});

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

    // Reported because the web SAPI's execution limit is invisible from the CLI
    // and is the single most common cause of an agent run failing with no
    // useful error. `php -i` on a dev box prints 0 while requests still die at
    // 30s, so this is the only place the real number can be read.
    $data['limits'] = [
        'sapi'              => PHP_SAPI,
        'max_execution_time' => (int) ini_get('max_execution_time'),
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