<?php
/**
 * Login test — the browser path, and the CSRF hole it opens.
 *
 * The API suite (api_test.php) authenticates every request with an
 * Authorization: Bearer header. That path has no cookie to be forged onto, so
 * it is structurally CSRF-safe and proves nothing about the cookie path.
 *
 * Part 4 added the cookie path so a person can reach /admin at all — which is
 * what makes `ahadbutop.com/login` work. That changes the threat model: the
 * browser now attaches the credential to any cross-site request automatically.
 * These tests cover both halves of that trade, because shipping the cookie
 * without a working CSRF check would have exchanged an unreachable admin for an
 * exploitable one.
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
    $json    = $body === [] ? '' : json_encode($body);
    $request = $factory->createServerRequest($method, $path)->withBody($stream->createStream($json));
    $request = $request->withHeader('Content-Type', 'application/json');
    foreach ($headers as $k => $v) {
        $request = $request->withHeader($k, $v);
    }
    // A real server populates getQueryParams() from the request line; the test
    // factory does not. Routes that read the bag rather than the raw URI would
    // otherwise see nothing, and the tests would fail against working code.
    $query = $request->getUri()->getQuery();
    if ($query !== '') {
        $params = [];
        parse_str($query, $params);
        $request = $request->withQueryParams($params);
    }
    if ($json !== '') {
        $request = $request->withParsedBody($body);
    }
    return $request;
}

/** A request that looks like a BROWSER: accepts HTML, no bearer header. */
function browser(string $method, string $path, array $body = [], array $headers = []): \Psr\Http\Message\ServerRequestInterface
{
    $request = req($method, $path, $body, $headers + ['Accept' => 'text/html,application/xhtml+xml']);

    // The Accept header is what makes AuthMiddleware answer a browser with a
    // redirect to /login instead of a JSON 401. Without it these assertions
    // would be testing the API path, which is the one that already worked.
    return $request;
}

function call($app, $request): array
{
    $response = $app->handle($request);
    $raw      = (string) $response->getBody();

    // Cookies matter here — they are the credential under test — so they are
    // returned rather than discarded the way the other suites do.
    $cookies = [];
    foreach ($response->getHeader('Set-Cookie') as $line) {
        $pair = explode(';', $line, 2)[0];
        if (str_contains($pair, '=')) {
            [$k, $v] = explode('=', $pair, 2);
            $cookies[trim($k)] = trim($v);
        }
    }

    return ['status'  => $response->getStatusCode(),
            'body'    => $raw === '' ? [] : (json_decode($raw, true) ?: ['raw' => substr($raw, 0, 200)]),
            'raw'     => $raw,
            'headers' => $response->getHeaders(),
            'cookies' => $cookies];
}

function cookieHeader(array $cookies): string
{
    $pairs = [];
    foreach ($cookies as $k => $v) {
        $pairs[] = $k . '=' . $v;
    }
    return implode('; ', $pairs);
}

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  PASS  {$label}\n"; }
    else    { $fail++; echo "  FAIL  {$label} {$detail}\n"; }
}

// --- users ----------------------------------------------------------------

$rbacUsers = [];
foreach ([['author', 'LoginAuthor!23'], ['editor', 'LoginEditor!23'], ['admin', 'LoginAdmin!2345']] as [$role, $pw]) {
    $email = "login-test-{$role}@example.test";
    $users = new \CMS\Repository\UserRepository(
        new \CMS\Database\Connection(require __DIR__ . '/../config/database.php')
    );
    $row = $users->findByEmail($email);
    if ($row === null) {
        $users->create([
            'email' => $email, 'password_hash' => password_hash($pw, PASSWORD_DEFAULT),
            'role' => $role, 'status' => 'active', 'display_name' => "Login $role",
            'username' => "login-test-$role",
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

// --- 1. the login screen exists at the URL a person types -------------------

echo "\n== The login screen ==\n";

$r = call($app, browser('GET', '/login'));
check('GET /login -> 200', $r['status'] === 200, '(got ' . $r['status'] . ')');
check('/login is HTML, not JSON', str_contains((string) ($r['headers']['Content-Type'][0] ?? ''), 'text/html'));
check('/login renders the form', str_contains($r['raw'], 'id="login-form"'));
check('/login is not cached', str_contains((string) ($r['headers']['Cache-Control'][0] ?? ''), 'no-store'),
      '(Cache-Control: ' . ($r['headers']['Cache-Control'][0] ?? 'absent') . ')');
check('/login is noindex', str_contains($r['raw'], 'noindex'));

// /admin/login is what admin.js's logout link points at. It must reach the
// same screen — a permanent redirect, not a 404, so an old bookmark still
// works and there is only one login URL to maintain.
$r = call($app, browser('GET', '/admin/login'));
check('GET /admin/login -> 301', $r['status'] === 301, '(got ' . $r['status'] . ')');
check('GET /admin/login -> /login',
      (string) ($r['headers']['Location'][0] ?? '') === '/login',
      '(Location: ' . ($r['headers']['Location'][0] ?? 'absent') . ')');
$r = call($app, browser('GET', '/admin/login?next=%2Fadmin%2Fchat'));
check('/admin/login preserves next=',
      str_contains((string) ($r['headers']['Location'][0] ?? ''), 'next='),
      '(Location: ' . ($r['headers']['Location'][0] ?? 'absent') . ')');

echo "\n== 'next' cannot become an open redirect ==\n";

// A validated next= survives into the form...
$r = call($app, browser('GET', '/login?next=%2Fadmin%2Fposts'));
check('valid next= is preserved', str_contains($r['raw'], '/admin/posts'),
      '(page: ' . substr(preg_replace('/\s+/', ' ', $r['raw']) ?: '', 0, 140) . ')');

// ...and every escaping attempt is replaced with the safe default. These are
// the shapes that turn `location.href = value` into an off-site redirect.
foreach ([
    'https://evil.test/steal' => 'absolute URL',
    '//evil.test/steal'      => 'protocol-relative URL',
    '/\\evil.test/steal'     => 'backslash protocol-relative',
] as $hostile => $label) {
    $r = call($app, browser('GET', '/login?next=' . rawurlencode($hostile)));
    check("hostile next= rejected ($label)", !str_contains($r['raw'], 'evil.test'),
          '(page still contained the host)');
}
$r = call($app, browser('GET', '/login?next=' . rawurlencode('javascript:alert(1)')));
check('javascript: next= rejected', !str_contains($r['raw'], 'javascript:'));

// --- 2. unauthenticated browsers are redirected, not JSON-errored ----------

echo "\n== Unauthenticated browsers land on /login ==\n";

foreach (['/admin', '/admin/', '/admin/posts', '/admin/chat', '/admin/agents'] as $screen) {
    $r = call($app, browser('GET', $screen));
    check("GET {$screen} -> 302", $r['status'] === 302, '(got ' . $r['status'] . ')');
    $loc = (string) ($r['headers']['Location'][0] ?? '');
    check("GET {$screen} -> /login", str_starts_with($loc, '/login'), "(Location: {$loc})");
}

// A redirect carrying `next` must keep it, or every deep link lands on the
// dashboard instead of the screen the person actually asked for.
$r = call($app, browser('GET', '/admin/chat'));
check('redirect carries next=', str_contains((string) ($r['headers']['Location'][0] ?? ''), 'next='),
      "(Location: " . ($r['headers']['Location'][0] ?? 'absent') . ')');

// The same request from a fetch client keeps the JSON 401. Redirecting an XHR
// to an HTML page returns a parse error instead of an actionable message.
$r = call($app, req('GET', '/admin/chat', [], ['Accept' => 'application/json']));
check('JSON client still gets 401 (not a redirect)', $r['status'] === 401, '(got ' . $r['status'] . ')');

// --- 3. logging in sets cookies --------------------------------------------

echo "\n== Login issues a session cookie ==\n";

$r = call($app, req('POST', '/api/v1/auth/login',
    ['email' => $rbacUsers[2]['email'], 'password' => $rbacUsers[2]['password']]));

check('login -> 200', $r['status'] === 200, '(got ' . $r['status'] . ')');
check('login sets cms_session', isset($r['cookies']['cms_session']));
check('login sets cms_csrf', isset($r['cookies']['cms_csrf']));

// HttpOnly is the property that stops a stolen session being read by script
// (including a successful XSS). Without it the cookie is just a bearer token
// pasted into every request the page makes.
$sessionAttrs = (string) ($r['headers']['Set-Cookie'][0] ?? '');
check('session cookie is HttpOnly', stripos($sessionAttrs, 'HttpOnly') !== false,
      "({$sessionAttrs})");
check('session cookie is SameSite', stripos($sessionAttrs, 'SameSite') !== false,
      "({$sessionAttrs})");

$adminCookies  = $r['cookies'];
$adminToken    = $r['body']['access_token'] ?? '';
$adminCsrf     = $adminToken; // filled in below from a real screen render

// The cookie alone must be enough — no Authorization header. This is the whole
// point of Part 4: before SessionCookie, /admin was unreachable by a person.
$r = call($app, browser('GET', '/admin', [], ['Cookie' => cookieHeader($adminCookies)]));
check('cookie alone authenticates /admin', $r['status'] === 200, '(got ' . $r['status'] . ')');
check('/admin renders the chrome (not a blank shell)', str_contains($r['raw'], '</html>'));

// Harvest the signed CSRF token the way admin.js does: off a rendered page.
preg_match('/window\.CSRF\s*=\s*["\']([^"\']+)["\']/', $r['raw'], $m);
$adminCsrf = $m[1] ?? '';
check('rendered admin page exposes window.CSRF', $adminCsrf !== '');

// A cookie with a valid shape but a broken signature must not authenticate.
$tampered = $adminCookies;
$tampered['cms_session'] = $tampered['cms_session'] . 'x';
$r = call($app, browser('GET', '/admin', [], ['Cookie' => cookieHeader($tampered)]));
check('tampered session cookie -> 302 to /login', $r['status'] === 302, '(got ' . $r['status'] . ')');

// --- 4. cookie-authenticated writes need the CSRF token --------------------

echo "\n== Cookie writes are CSRF-protected ==\n";

// No token. This is the classic CSRF shape: the browser attaches the session
// cookie automatically, so the ONLY thing standing between a cross-site form
// and a published post is this rejection.
$r = call($app, browser('POST', '/api/v1/posts',
    ['title' => 'CSRF Should Not Create This'],
    ['Cookie' => cookieHeader($adminCookies)]));
check('cookie write without CSRF token -> 419', $r['status'] === 419, '(got ' . $r['status'] . ')');

$db = new \CMS\Database\Connection(require __DIR__ . '/../config/database.php');
$pdo = $db->getPdo();
$made = $pdo->query("SELECT COUNT(*) FROM posts WHERE title = 'CSRF Should Not Create This'")->fetchColumn();
check('the rejected write created nothing', (int) $made === 0, "({$made} rows)");

// With the token. Same cookie, same session — now it goes through.
$r = call($app, browser('POST', '/api/v1/posts',
    ['title' => 'CSRF Token Lets This Through'],
    ['Cookie' => cookieHeader($adminCookies), 'X-CSRF-Token' => $adminCsrf]));
check('cookie write WITH CSRF token -> 201', $r['status'] === 201,
      '(got ' . $r['status'] . ' ' . json_encode($r['body']) . ')');

// A token that is well-signed but belongs to ANOTHER user must not authorise a
// write as this one. Otherwise "some rendered page said so" would be enough —
// the attacker would only need one victim's token from any context.
$editorLogin = call($app, req('POST', '/api/v1/auth/login',
    ['email' => $rbacUsers[1]['email'], 'password' => $rbacUsers[1]['password']]));
$editorCookies = $editorLogin['cookies'];
$r = call($app, browser('GET', '/admin', [], ['Cookie' => cookieHeader($editorCookies)]));
preg_match('/window\.CSRF\s*=\s*["\']([^"\']+)["\']/', $r['raw'], $em);
$editorCsrf = $em[1] ?? '';

$r = call($app, browser('POST', '/api/v1/posts',
    ['title' => 'Stolen Token Should Not Work'],
    ['Cookie' => cookieHeader($adminCookies), 'X-CSRF-Token' => $editorCsrf]));
check('another user\'s CSRF token -> 419', $r['status'] === 419, '(got ' . $r['status'] . ')');
check('the cross-session token is non-empty (test is meaningful)', $editorCsrf !== '');

// A token signed with the wrong key is rejected outright.
$r = call($app, browser('POST', '/api/v1/posts',
    ['title' => 'Forged Token Should Not Work'],
    ['Cookie' => cookieHeader($adminCookies),
     'X-CSRF-Token' => rtrim(strtr(base64_encode(json_encode([
         'typ' => 'JWT', 'alg' => 'HS256',
         'sub' => 1, 'csrf' => true, 'exp' => time() + 3600,
     ])), '+/', '-_') . '.x', '=')]));
check('forged CSRF token -> 419', $r['status'] === 419, '(got ' . $r['status'] . ')');

// Bearer callers are exempt, deliberately. A header credential is not ambient:
// no cross-site page can make a browser attach one, so there is nothing to
// forge. Exempting them is what keeps existing API clients working.
$r = call($app, req('POST', '/api/v1/posts',
    ['title' => 'Bearer Client Does Not Need CSRF'],
    ['Authorization' => "Bearer {$adminToken}"]));
check('bearer write without CSRF token -> 201', $r['status'] === 201,
      '(got ' . $r['status'] . ' ' . json_encode($r['body']) . ')');

// Reads stay open to a cookie session — CSRF cannot change state via GET.
$r = call($app, browser('GET', '/admin/posts', [], ['Cookie' => cookieHeader($adminCookies)]));
check('cookie GET /admin/posts -> 200 (no token needed)', $r['status'] === 200,
      '(got ' . $r['status'] . ')');

// --- 5. logout clears the cookies ------------------------------------------

echo "\n== Logout clears the session ==\n";

$r = call($app, browser('POST', '/api/v1/auth/logout', [], ['Cookie' => cookieHeader($adminCookies)]));
check('logout -> 200', $r['status'] === 200, '(got ' . $r['status'] . ')');
$cleared = false;
foreach ($r['headers']['Set-Cookie'] as $line) {
    if (str_contains($line, 'cms_session') && preg_match('/(?:Max-Age=0|Expires=)/i', $line) === 1) {
        $cleared = true;
    }
}
check('logout expires cms_session', $cleared);

// --- 6. the chat screen and the CEO endpoint -------------------------------

echo "\n== Ask AI ==\n";

$editorLogin2 = call($app, req('POST', '/api/v1/auth/login',
    ['email' => $rbacUsers[1]['email'], 'password' => $rbacUsers[1]['password']]));
$editorCookies2 = $editorLogin2['cookies'];
$editorToken2   = $editorLogin2['body']['access_token'] ?? '';

$r = call($app, browser('GET', '/admin/chat', [], ['Cookie' => cookieHeader($editorCookies2)]));
check('GET /admin/chat -> 200 for a signed-in editor', $r['status'] === 200, '(got ' . $r['status'] . ')');
check('/admin/chat renders the composer', str_contains($r['raw'], 'id="chat-form"'));
check('/admin/chat names the role every step runs under', str_contains($r['raw'], 'editor'));

// The dashboard must link to it, or the screen is unreachable by navigation.
$dash = call($app, browser('GET', '/admin', [], ['Cookie' => cookieHeader($editorCookies2)]));
check('dashboard nav links to /admin/chat', str_contains($dash['raw'], '/admin/chat'),
      '(no nav link found)');

// The CEO is editor+ and the agent is fixed, so the route floor is the gate —
// an author must never reach the handler at all.
$authorLogin = call($app, req('POST', '/api/v1/auth/login',
    ['email' => $rbacUsers[0]['email'], 'password' => $rbacUsers[0]['password']]));
$authorToken  = $authorLogin['body']['access_token'] ?? '';

$r = call($app, req('POST', '/api/v1/agents/ceo',
    ['prompt' => 'rename the About page to Company'], ['Authorization' => "Bearer {$authorToken}"]));
check('author POST /agents/ceo -> 403', $r['status'] === 403, '(got ' . $r['status'] . ')');

// A dry run for an editor: it must plan without writing, so this is safe to
// assert on a live database.
$r = call($app, req('POST', '/api/v1/agents/ceo',
    ['prompt' => 'rename the About page to Company', 'dry_run' => true],
    ['Authorization' => "Bearer {$editorToken2}"]));
check('editor POST /agents/ceo dry_run -> 200', $r['status'] === 200,
      '(got ' . $r['status'] . ' ' . json_encode($r['body']) . ')');
check('dry run is echoed back as dry_run=true', ($r['body']['dry_run'] ?? null) === true);
check('dry run returns a plan array', isset($r['body']['plan']) && is_array($r['body']['plan']));

// A dry run must not have executed anything: no per-step results means no
// intents were dispatched against the database.
check('dry run reported no step results',
      ($r['body']['done'] ?? null) === null && ($r['body']['failed'] ?? null) === null,
      '(done: ' . json_encode($r['body']['done'] ?? null)
      . ', failed: ' . json_encode($r['body']['failed'] ?? null) . ')');
check('dry run reports the caller\'s own role, not an escalated one',
      ($r['body']['role'] ?? '') === 'editor', '(role: ' . json_encode($r['body']['role'] ?? null) . ')');

// --- cleanup ---------------------------------------------------------------

$pdo->exec("DELETE FROM posts WHERE title IN (
    'CSRF Should Not Create This',
    'CSRF Token Lets This Through',
    'Stolen Token Should Not Work',
    'Forged Token Should Not Work',
    'Bearer Client Does Not Need CSRF')");

echo "\n== Result: {$pass} passed, {$fail} failed ==\n";
exit($fail === 0 ? 0 : 1);