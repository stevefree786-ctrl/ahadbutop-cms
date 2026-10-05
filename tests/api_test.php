<?php
/**
 * End-to-end API check: boots the real Slim app in-process and drives it
 * with synthetic PSR-7 requests. No server required.
 */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../public/index.php';

use Slim\Psr7\Factory\ServerRequestFactory;
use Nyholm\Psr7Factory;

$factory = new ServerRequestFactory();
$psr7    = new \Slim\Psr7\Factory\StreamFactory();

function req(string $method, string $path, array $body = [], array $headers = []): \Psr\Http\Message\ServerRequestInterface
{
    global $factory, $psr7;
    $json = $body === [] ? '' : json_encode($body);
    $request = $factory->createServerRequest($method, $path)
        ->withBody($psr7->createStream($json));

    $request = $request->withHeader('Content-Type', 'application/json');
    foreach ($headers as $k => $v) {
        $request = $request->withHeader($k, $v);
    }
    if ($json !== '') {
        $request = $request->withParsedBody($body);
    }
    return $request;
}

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  PASS  {$label}\n"; }
    else    { $fail++; echo "  FAIL  {$label} {$detail}\n"; }
}

function call($app, $request): array
{
    $response = $app->handle($request);
    $raw = (string) $response->getBody();
    return [
        'status' => $response->getStatusCode(),
        'body'   => $raw === '' ? [] : (json_decode($raw, true) ?: ['raw' => $raw]),
    ];
}

/**
 * Provision the admin this suite authenticates as.
 *
 * The suite used to assume `admin@cms.local` with a hardcoded password was
 * already present, so it silently failed on any database that had not been
 * seeded with exactly those credentials — and every later assertion failed
 * behind it, reading like a broken auth stack. Creating the user here makes the
 * test self-contained: it tests the API, not the seeder's output.
 */
function provision_admin(): array
{
    $config = require __DIR__ . '/../config/database.php';
    $db     = new \CMS\Database\Connection($config);
    $users  = new \CMS\Repository\UserRepository($db);

    $email    = 'api-test-admin@example.test';
    $password = 'ApiTest!2345';

    // Keep the address stable so a re-run finds the existing row rather than
    // tripping the UNIQUE constraint on email.
    $existing = $users->findByEmail($email);
    if ($existing === null) {
        $users->create([
            'email'         => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'role'          => 'admin',
            'status'        => 'active',
            'display_name'  => 'API Test Admin',
            'username'      => 'api-test-admin',
        ]);
        $existing = $users->findByEmail($email);
    } else {
        $users->setPassword((int) $existing['id'], password_hash($password, PASSWORD_DEFAULT));
        $db->query("UPDATE users SET role = 'admin', status = 'active' WHERE id = ?", [(int) $existing['id']]);
    }

    // The shutdown handler only deletes a row this run created, so it needs
    // to know which id that was.
    return ['id' => (int) $existing['id'], 'email' => $email, 'password' => $password];
}

$admin = provision_admin();

register_shutdown_function(static function () use ($admin): void {
    $db = new \CMS\Database\Connection(require __DIR__ . '/../config/database.php');
    $users = new \CMS\Repository\UserRepository($db);
    $row = $users->findByEmail($admin['email']);
    // Only ever remove the row this file created. provision_admin() reuses
    // the address across runs so it does not trip the UNIQUE constraint on
    // email — which means the row can predate this run, and deleting a
    // pre-existing row would quietly destroy the account every later run
    // (and the next developer) depends on.
    if ($row !== null && (int) $row['id'] === $admin['id']) {
        $db->query('DELETE FROM sessions WHERE user_id = ?', [$admin['id']]);
        $db->query('DELETE FROM users WHERE id = ?', [$admin['id']]);
    }
});

echo "\n== 1. Protected routes reject anonymous callers ==\n";
$r = call($app, req('GET', '/api/v1/posts'));
check('GET /posts without token -> 401', $r['status'] === 401, '(got ' . $r['status'] . ')');

$r = call($app, req('GET', '/api/v1/auth/me'));
check('GET /auth/me without token -> 401', $r['status'] === 401, '(got ' . $r['status'] . ')');

$r = call($app, req('GET', '/api/v1/posts', [], ['Authorization' => 'Bearer garbage.jwt.value']));
check('GET /posts with forged token -> 401', $r['status'] === 401, '(got ' . $r['status'] . ')');

echo "\n== 2. Login ==\n";
$r = call($app, req('POST', '/api/v1/auth/login', ['email' => $admin['email'], 'password' => 'wrong-password']));
check('wrong password -> 401', $r['status'] === 401, '(got ' . $r['status'] . ')');
check('no user enumeration', ($r['body']['error'] ?? '') === 'Invalid credentials');

$r = call($app, req('POST', '/api/v1/auth/login', ['email' => $admin['email'], 'password' => $admin['password']]));
check('valid login -> 200', $r['status'] === 200, json_encode($r['body']));
check('returns access_token', !empty($r['body']['access_token']));
check('returns refresh_token', !empty($r['body']['refresh_token']));

$access  = $r['body']['access_token']  ?? '';
$refresh = $r['body']['refresh_token'] ?? '';

echo "\n== 3. Authenticated reads ==\n";
$r = call($app, req('GET', '/api/v1/auth/me', [], ['Authorization' => "Bearer $access"]));
check('GET /auth/me -> 200', $r['status'] === 200, json_encode($r['body']));
check('me returns email', ($r['body']['user']['email'] ?? '') === $admin['email']);
check('me returns role admin', ($r['body']['user']['role'] ?? '') === 'admin');

$r = call($app, req('GET', '/api/v1/posts', [], ['Authorization' => "Bearer $access"]));
check('GET /posts -> 200', $r['status'] === 200, json_encode($r['body']));
check('posts is a list', isset($r['body']['posts']) && is_array($r['body']['posts']));

echo "\n== 4. Not-found is 404, not 200 ==\n";
$r = call($app, req('GET', '/api/v1/posts/definitely-not-real', [], ['Authorization' => "Bearer $access"]));
check('missing post -> 404', $r['status'] === 404, '(got ' . $r['status'] . ')');

$r = call($app, req('GET', '/api/v1/jobs/999999', [], ['Authorization' => "Bearer $access"]));
check('missing job -> 404', $r['status'] === 404, '(got ' . $r['status'] . ')');

echo "\n== 5. Bound-parameter path (the old PDO::query 2-arg bug) ==\n";
$r = call($app, req('POST', '/api/v1/posts', ['title' => 'Bound Param Test Post'], ['Authorization' => "Bearer $access"]));
check('POST /posts -> 201', $r['status'] === 201, json_encode($r['body']));
$newId = $r['body']['id'] ?? null;

$r = call($app, req('POST', '/api/v1/posts', ['title' => 'Second Test Post'], ['Authorization' => "Bearer $access"]));
check('second create -> 201', $r['status'] === 201, json_encode($r['body']));

$r = call($app, req('GET', '/api/v1/posts/bound-param-test-post', [], ['Authorization' => "Bearer $access"]));
check('slug lookup returns the row', ($r['body']['post']['title'] ?? '') === 'Bound Param Test Post', json_encode($r['body']));

echo "\n== 6. Validation ==\n";
$r = call($app, req('POST', '/api/v1/posts', [], ['Authorization' => "Bearer $access"]));
check('create without title -> 400', $r['status'] === 400, '(got ' . $r['status'] . ')');

$r = call($app, req('POST', '/api/v1/auth/login', ['email' => $admin['email']]));
check('login without password -> 400', $r['status'] === 400, '(got ' . $r['status'] . ')');

echo "\n== 7. Refresh rotation ==\n";
$r = call($app, req('POST', '/api/v1/auth/refresh', ['refresh_token' => $refresh]));
check('refresh -> 200', $r['status'] === 200, json_encode($r['body']));
$access2  = $r['body']['access_token'] ?? '';
$refresh2 = $r['body']['refresh_token'] ?? '';
check('new access token issued', !empty($access2));
check('refresh token rotated', !empty($refresh2) && $refresh2 !== $refresh);

$r = call($app, req('POST', '/api/v1/auth/refresh', ['refresh_token' => $refresh]));
check('reused refresh token -> 401', $r['status'] === 401, '(got ' . $r['status'] . ')');

$r = call($app, req('GET', '/api/v1/posts', [], ['Authorization' => "Bearer $access2"]));
check('refreshed access token works', $r['status'] === 200, '(got ' . $r['status'] . ')');

echo "\n== 8. Logout revokes ==\n";
$r = call($app, req('POST', '/api/v1/auth/logout', ['refresh_token' => $refresh2]));
check('logout -> 200', $r['status'] === 200, json_encode($r['body']));

$r = call($app, req('POST', '/api/v1/auth/refresh', ['refresh_token' => $refresh2]));
check('revoked refresh -> 401', $r['status'] === 401, '(got ' . $r['status'] . ')');

echo "\n== 9. Public crawl endpoints ==\n";
$r = call($app, req('GET', '/api/v1/seo/robots.txt'));
check('robots.txt -> 200', $r['status'] === 200, '(got ' . $r['status'] . ')');
$r = call($app, req('GET', '/api/v1/seo/sitemap.xml'));
check('sitemap.xml -> 200', $r['status'] === 200, '(got ' . $r['status'] . ')');

echo "\n== 10. Health ==\n";
$r = call($app, req('GET', '/health'));
check('health -> 200', $r['status'] === 200);
check('health reports db connected', ($r['body']['db'] ?? '') === 'connected', json_encode($r['body']));

// Cleanup the rows this test created.
$pdo = (new \CMS\Database\Connection(require __DIR__ . '/../config/database.php'))->getPdo();
$pdo->exec("DELETE FROM posts WHERE slug IN ('bound-param-test-post','second-test-post')");
$pdo->exec('DELETE FROM sessions');

echo "\n" . str_repeat('=', 46) . "\n";
echo "  PASSED: {$pass}   FAILED: {$fail}\n";
echo str_repeat('=', 46) . "\n";
exit($fail === 0 ? 0 : 1);