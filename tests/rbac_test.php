<?php
/**
 * RBAC test — verifies the role hierarchy actually gates writes.
 *
 * GET    -> author  (any signed-in user)
 * POST   -> editor  (author must be blocked)
 * DELETE -> admin   (editor must be blocked)
 */
declare(strict_types=1);

$app = require __DIR__ . '/../public/index.php';

use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

$factory = new ServerRequestFactory();
$stream  = new StreamFactory();

function req(string $method, string $path, array $body = [], array $headers = []): \Psr\Http\Message\ServerRequestInterface
{
    global $factory, $stream;
    $json = $body === [] ? '' : json_encode($body);
    $request = $factory->createServerRequest($method, $path)->withBody($stream->createStream($json));
    $request = $request->withHeader('Content-Type', 'application/json');
    foreach ($headers as $k => $v) {
        $request = $request->withHeader($k, $v);
    }
    if ($json !== '') {
        $request = $request->withParsedBody($body);
    }
    return $request;
}

function call($app, $request): array
{
    $response = $app->handle($request);
    $raw = (string) $response->getBody();
    return ['status' => $response->getStatusCode(),
            'body'   => $raw === '' ? [] : (json_decode($raw, true) ?: ['raw' => substr($raw, 0, 120)])];
}

function login($app, string $email, string $password): string
{
    $r = call($app, req('POST', '/api/v1/auth/login', ['email' => $email, 'password' => $password]));
    return $r['body']['access_token'] ?? '';
}

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  PASS  {$label}\n"; }
    else    { $fail++; echo "  FAIL  {$label} {$detail}\n"; }
}

/**
 * Provision the three roles this suite exercises.
 *
 * Previously these logins assumed seeded accounts existed with matching
 * passwords; on a database that had not been seeded exactly that way the admin
 * token came back empty and every admin assertion failed behind it. The test
 * should exercise the role hierarchy, not the seeder.
 */
$rbacUsers = [];
foreach ([['author', 'RbacAuthor!23'], ['editor', 'RbacEditor!23'], ['admin', 'RbacAdmin!2345']] as [$role, $pw]) {
    $email = "rbac-test-{$role}@example.test";
    $users = new \CMS\Repository\UserRepository(
        new \CMS\Database\Connection(require __DIR__ . '/../config/database.php')
    );
    $row = $users->findByEmail($email);
    if ($row === null) {
        $users->create([
            'email' => $email, 'password_hash' => password_hash($pw, PASSWORD_DEFAULT),
            'role' => $role, 'status' => 'active', 'display_name' => "RBAC $role",
            'username' => "rbac-test-$role",
        ]);
    } else {
        $users->setPassword((int) $row['id'], password_hash($pw, PASSWORD_DEFAULT));
    }
    $rbacUsers[] = ['role' => $role, 'email' => $email, 'password' => $pw];
}

register_shutdown_function(static function () use ($rbacUsers): void {
    $db    = new \CMS\Database\Connection(require __DIR__ . '/../config/database.php');
    $users = new \CMS\Repository\UserRepository($db);
    foreach ($rbacUsers as $u) {
        $row = $users->findByEmail($u['email']);
        if ($row !== null) {
            $db->query('DELETE FROM sessions WHERE user_id = ?', [(int) $row['id']]);
            $db->query('DELETE FROM users WHERE id = ?', [(int) $row['id']]);
        }
    }
});

$authorTok = login($app, $rbacUsers[0]['email'], $rbacUsers[0]['password']);
$editorTok = login($app, $rbacUsers[1]['email'], $rbacUsers[1]['password']);
$adminTok  = login($app, $rbacUsers[2]['email'], $rbacUsers[2]['password']);

check('author logged in',  $authorTok !== '');
check('editor logged in',  $editorTok !== '');
check('admin logged in',   $adminTok  !== '');

echo "\n== Reads are open to any signed-in role ==\n";
check('author can GET /posts',  call($app, req('GET', '/api/v1/posts', [], ['Authorization' => "Bearer $authorTok"]))['status'] === 200);
check('editor can GET /posts',  call($app, req('GET', '/api/v1/posts', [], ['Authorization' => "Bearer $editorTok"]))['status'] === 200);
check('author can GET /settings', call($app, req('GET', '/api/v1/settings', [], ['Authorization' => "Bearer $authorTok"]))['status'] === 200);

echo "\n== Writes require editor+ ==\n";
$r = call($app, req('POST', '/api/v1/posts', ['title' => 'Author Should Not Create'], ['Authorization' => "Bearer $authorTok"]));
check('author POST /posts -> 403', $r['status'] === 403, '(got ' . $r['status'] . ')');

$r = call($app, req('POST', '/api/v1/posts', ['title' => 'Editor Can Create This'], ['Authorization' => "Bearer $editorTok"]));
check('editor POST /posts -> 201', $r['status'] === 201, json_encode($r['body']));

$r = call($app, req('POST', '/api/v1/posts', ['title' => 'Admin Can Create This'], ['Authorization' => "Bearer $adminTok"]));
check('admin POST /posts -> 201', $r['status'] === 201, json_encode($r['body']));

echo "\n== Admin-only operations ==\n";
$logoutAll = function (string $token) use ($app) {
    return call($app, req('POST', '/api/v1/auth/logout-all', [], ['Authorization' => "Bearer $token"]));
};
$r = $logoutAll($editorTok);
check('editor logout-all -> 403', $r['status'] === 403, '(got ' . $r['status'] . ')');

$r = $logoutAll($adminTok);
check('admin logout-all -> 200', $r['status'] === 200, '(got ' . $r['status'] . ')');

echo "\n== Token claims cannot be escalated ==\n";
// Forge a token that claims admin, signed with the wrong key.
$forged = base64_encode(json_encode(['typ' => 'JWT', 'alg' => 'HS256'])) . '.' .
          base64_encode(json_encode([
              'sub' => 1, 'uuid' => 'x', 'role' => 'admin',
              'email' => 'author@cms.local', 'exp' => time() + 3600,
          ])) . '.' . base64_encode('wrong-signature');
$r = call($app, req('GET', '/api/v1/posts', [], ['Authorization' => "Bearer $forged"]));
check('forged admin claim -> 401', $r['status'] === 401, '(got ' . $r['status'] . ')');

echo "\n== Cleanup ==\n";
$pdo = (new \CMS\Database\Connection(require __DIR__ . '/../config/database.php'))->getPdo();
$pdo->exec("DELETE FROM posts WHERE title IN ('Author Should Not Create','Editor Can Create This','Admin Can Create This')");
$pdo->exec('DELETE FROM sessions');
echo "  cleaned\n";

echo "\n" . str_repeat('=', 46) . "\n";
echo "  PASSED: {$pass}   FAILED: {$fail}\n";
echo str_repeat('=', 46) . "\n";
exit($fail === 0 ? 0 : 1);