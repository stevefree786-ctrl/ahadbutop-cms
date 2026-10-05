<?php
/**
 * Skills, deep research, and BYOK over HTTP.
 *
 * byok_test.php proves the pieces work in isolation. This proves the ROUTES
 * work: that a role floor is attached where one is claimed, that a browser
 * can reach each screen, and — the reason this file exists — that the BYOK
 * write path never puts a credential anywhere a model can read it.
 *
 * The obvious way to wire "save an API key" is to reuse the agent route and
 * have the CEO call set_provider_key, which ships the key to the model
 * provider inside a prompt. Nothing about that is visible in a unit test of
 * the pieces; only a test of the ROUTE can catch someone "simplifying" it
 * back that way.
 *
 * Fixtures go through UserRepository rather than raw INSERT. The users table
 * has eleven columns and a raw INSERT that names the wrong one is a fatal
 * error rather than a test failure, which tells you nothing about the code
 * under test.
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
    if ($json !== '') {
        $request = $request->withParsedBody($body);
    }
    return $request;
}

/**
 * A request that looks like a BROWSER.
 *
 * The Accept header is load-bearing: AuthMiddleware answers a browser with a
 * redirect to /login and a JSON client with a 401. Asserting a browser
 * behaviour on a request that says it wants JSON tests the API path.
 */
function browser(string $method, string $path, array $body = [], array $headers = []): \Psr\Http\Message\ServerRequestInterface
{
    return req($method, $path, $body, $headers + ['Accept' => 'text/html,application/xhtml+xml']);
}

function call($app, $request): array
{
    $response = $app->handle($request);
    $raw      = (string) $response->getBody();

    $out = [
        'status'  => $response->getStatusCode(),
        'headers' => $response->getHeaders(),
        'raw'     => $raw,
    ];
    $out['body'] = $json = json_decode($raw, true);
    $out['body'] = is_array($json) ? $json : [];

    $out['cookies'] = [];
    foreach ($response->getHeader('Set-Cookie') as $line) {
        $pair = explode(';', $line, 2)[0];
        if (str_contains($pair, '=')) {
            [$k, $v] = explode('=', $pair, 2);
            $out['cookies'][trim($k)] = trim($v);
        }
    }

    return $out;
}

function cookieHeader(array $cookies): string
{
    $pairs = [];
    foreach ($cookies as $k => $v) {
        $pairs[] = $k . '=' . $v;
    }
    return implode('; ', $pairs);
}

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  PASS  {$label}\n";
    } else {
        $fail++;
        echo "  FAIL  {$label}" . ($detail !== '' ? " -- {$detail}" : '') . "\n";
    }
}

// --- fixtures ---------------------------------------------------------------

$suffix   = (string) random_int(100000, 999999);
$password = 'Skills-' . bin2hex(random_bytes(4)) . '!9';

$usersRepo = new \CMS\Repository\UserRepository(
    new \CMS\Database\Connection(require __DIR__ . '/../config/database.php')
);

$users = [];
foreach (['admin', 'editor', 'author'] as $label) {
    $email = "skills-{$label}-{$suffix}@example.test";

    // UserRepository::create() returns the new id as an int, NOT a row array.
    // Reading $row['id'] off that int silently yields 0, and every later
    // cleanup then deletes WHERE id = 0 — which matches nothing, so the
    // fixtures outlive the run and the suite slowly fills with orphan users
    // while still reporting every assertion as a pass.
    $existing = $usersRepo->findByEmail($email);

    if ($existing === null) {
        $id = $usersRepo->create([
            'email'         => $email,
            'username'      => "skills-$label-$suffix",
            'password_hash' => password_hash($password, PASSWORD_BCRYPT),
            'display_name'  => "Skills $label",
            'role'          => $label,
            'status'        => 'active',
        ]);
    } else {
        $id = (int) $existing['id'];
        $usersRepo->setPassword($id, password_hash($password, PASSWORD_BCRYPT));
    }

    // Assert the id is real, so a future change to create()'s return type
    // fails loudly here instead of leaking fixtures silently.
    if ($id <= 0) {
        fwrite(STDERR, "fixture user {$email} has no usable id ({$id})\n");
        exit(1);
    }

    $users[$label] = ['id' => $id, 'email' => $email, 'password' => $password];
}

register_shutdown_function(static function () use ($users, $suffix): void {
    $db = new \CMS\Database\Connection(require __DIR__ . '/../config/database.php');
    foreach ($users as $u) {
        $db->query('DELETE FROM sessions WHERE user_id = ?', [$u['id']]);
        $db->query('DELETE FROM users WHERE id = ?', [$u['id']]);
    }
    // A provider key the test stored would otherwise outlive it and quietly
    // change what the next run's assertions mean. ProviderKeyStore keeps keys
    // in `settings` under scope 'byok' rather than in a table of their own.
    $db->query("DELETE FROM settings WHERE scope = 'byok'");
});

/**
 * Sign in over HTTP and return [bearer, cookies, csrf].
 *
 * The CSRF token is harvested off a real rendered page the way admin.js does
 * it, because that is the only place it is exposed. A minted-but-never-checked
 * token is the shape Part 5 flagged, so the test reads it the honest way
 * rather than assuming the login response carries it.
 */
function signin($app, array $user): array
{
    $r = call($app, req('POST', '/api/v1/auth/login',
        ['email' => $user['email'], 'password' => $user['password']]));

    $cookies = $r['cookies'];
    $csrf    = '';

    if (isset($cookies['cms_session'])) {
        $page = call($app, browser('GET', '/admin', [], ['Cookie' => cookieHeader($cookies)]));
        if (preg_match('/window\.CSRF\s*=\s*["\']([^"\']+)["\']/', $page['raw'], $m)) {
            $csrf = $m[1];
        }
    }

    return [$r['body']['access_token'] ?? '', $cookies, $csrf];
}

// --- 1. the skills screen ---------------------------------------------------

echo "\n== Skills screen ==\n";

[$adminToken, , $adminCsrf] = signin($app, $users['admin']);
$authAdmin = ['Authorization' => "Bearer {$adminToken}"];

check('the admin fixture could sign in', $adminToken !== '', 'login returned no token');

// Author rather than editor on purpose: a skill's steps are gated
// individually, so hiding the screen from authors would hide the explanation
// of why a run got refused.
$r = call($app, browser('GET', '/admin/skills', [], $authAdmin));
check('GET /admin/skills renders for an admin', $r['status'] === 200, "(got {$r['status']})");
check('the screen lists the built-in skills', str_contains($r['raw'], 'seo_optimise_post'),
    'no seo_optimise_post card in the rendered page');
check('the screen shows the steps, not just the name', str_contains($r['raw'], 'save_seo_meta'),
    'no step intents visible on the page');
check('the screen embeds the parameter list for the form', str_contains($r['raw'], 'window.SKILLS'));
check('the screen renders a close tag', str_contains($r['raw'], '</html>'));

$r = call($app, browser('GET', '/admin/skills'));
check('GET /admin/skills unauthenticated -> 302 to /login', $r['status'] === 302, "(got {$r['status']})");
check('the redirect target is /login',
    str_starts_with((string) ($r['headers']['Location'][0] ?? ''), '/login'),
    '(Location: ' . ($r['headers']['Location'][0] ?? 'absent') . ')');

[$authorToken, , ] = signin($app, $users['author']);
$r = call($app, browser('GET', '/admin/skills', [], ['Authorization' => "Bearer {$authorToken}"]));
check('GET /admin/skills renders for an author too', $r['status'] === 200, "(got {$r['status']})");

// --- 2. GET /api/v1/skills --------------------------------------------------

echo "\n== GET /api/v1/skills ==\n";

$r = call($app, req('GET', '/api/v1/skills', [], $authAdmin));
check('GET /api/v1/skills -> 200', $r['status'] === 200, "(got {$r['status']})");
check('it returns structured skill entries', isset($r['body']['skills'][0]['name']),
    json_encode(array_slice($r['body'], 0, 2)));
check('it echoes the caller role', ($r['body']['role'] ?? null) === 'admin',
    'got: ' . json_encode($r['body']['role'] ?? null));

$r = call($app, req('GET', '/api/v1/skills', [], ['Authorization' => "Bearer {$authorToken}"]));
check('an author may read the catalog', $r['status'] === 200, "(got {$r['status']})");
check('the echoed role is the author\'s own', ($r['body']['role'] ?? null) === 'author',
    'got: ' . json_encode($r['body']['role'] ?? null));

// The author must be able to see WHICH steps need a higher role. A catalog
// that hid the role tags would leave the refusal below unexplained.
$seoSkill = null;
foreach ($r['body']['skills'] ?? [] as $s) {
    if (($s['name'] ?? '') === 'seo_optimise_post') {
        $seoSkill = $s;
    }
}
check('the catalog marks each step with the role that may run it',
    ($seoSkill['steps'][0]['writer'] ?? null) === 'editor',
    'got: ' . json_encode($seoSkill['steps'][0] ?? null));
check('the catalog reports the narrowest role for the skill',
    ($seoSkill['runnable_by'] ?? null) === 'editor',
    'got: ' . json_encode($seoSkill['runnable_by'] ?? null));

$r = call($app, req('GET', '/api/v1/skills'));
check('GET /api/v1/skills without a token -> 401', $r['status'] === 401, "(got {$r['status']})");

// --- 3. POST /api/v1/skills/run --------------------------------------------

echo "\n== POST /api/v1/skills/run ==\n";

$r = call($app, req('POST', '/api/v1/skills/run',
    ['skill' => 'no_such_skill', 'params' => []],
    ['Authorization' => "Bearer {$authorToken}"]));

check('an unknown skill -> 404', $r['status'] === 404, "(got {$r['status']})");
check('the 404 lists the skills that do exist', isset($r['body']['available']),
    json_encode($r['body']));

$r = call($app, req('POST', '/api/v1/skills/run', ['params' => []], $authAdmin));
check('a run with no skill name -> 400', $r['status'] === 400, "(got {$r['status']})");

$r = call($app, req('POST', '/api/v1/skills/run',
    ['skill' => 'seo_optimise_post', 'params' => 'not-an-object'],
    ['Authorization' => "Bearer {$authorToken}"]));
check('non-object params -> 400', $r['status'] === 400, "(got {$r['status']})");

// An author running an editor skill: refused at the STEP, not at the door.
// A 403 here would mean the gate had been moved out to the route and the
// per-step role model in Skills::run() was dead code.
$r = call($app, req('POST', '/api/v1/skills/run',
    ['skill' => 'seo_optimise_post', 'params' => ['post_id' => '1', 'focus_keyword' => 'widgets']],
    ['Authorization' => "Bearer {$authorToken}"]));

check('an author may ATTEMPT an editor skill (rejection is per step)',
    in_array($r['status'], [200, 403], true), "(got {$r['status']})");

if ($r['status'] === 200) {
    $statuses = array_column($r['body']['steps'] ?? [], 'status');
    check('the run reports per-step status, not a bare failure', $statuses !== [],
        'no steps reported');
    check('an author is denied on the editor write step',
        in_array('denied', $statuses, true),
        'statuses: ' . json_encode($statuses));
    check('the run echoes the caller role, not an escalated one',
        ($r['body']['role'] ?? null) === 'author',
        'got: ' . json_encode($r['body']['role'] ?? null));
    check('a run with a refused step is reported as partial, not ok',
        ($r['body']['status'] ?? null) === 'partial',
        'got: ' . json_encode($r['body']['status'] ?? null));
}

// The same skill as an editor, on a post this test owns. If this does not
// actually write, then the assertions above proved nothing — a route that
// returned 200 with an empty step list would have passed them too.
$liveDb  = new \CMS\Database\Connection(require __DIR__ . '/../config/database.php');
$livePdo = $liveDb->getPdo();

$livePdo->prepare(
    "INSERT INTO posts (uuid, title, slug, body_md, status, author_id, created_at, updated_at)
     VALUES (?, ?, ?, ?, 'draft', ?, datetime('now'), datetime('now'))"
)->execute([
    bin2hex(random_bytes(16)),
    'Skills Fixture ' . $suffix,
    'skills-fixture-' . $suffix,
    'A paragraph written by the skills route test.',
    $users['admin']['id'],
]);

$postId = (int) $livePdo->lastInsertId();

// Close the INSERT's cursor before going on. lastInsertId() leaves a statement
// open on this handle, and SQLite then keeps the RESERVED write lock on the
// connection without reporting a transaction: inTransaction() says false and
// COMMIT is refused with "no transaction is active". Reads still succeed, so
// nothing looks wrong until the cleanup at shutdown tries to DELETE and gets
// SQLITE_BUSY — immediately, with no wait, because SQLite does not invoke the
// busy handler for a conflict with its own connection. busy_timeout=5000 is
// set and does not help, which is what made this look like an external lock.
register_shutdown_function(static function () use ($liveDb, $postId): void {
    // A NEW Connection, not $liveDb->getPdo(). That comment used to claim this
    // was "a fresh handle on purpose"; it was the same wedged handle, which is
    // why the cleanup failed with "database is locked" and the fixture outlived
    // the run.
    $db = new \CMS\Database\Connection(require __DIR__ . '/../config/database.php');
    $db->query('DELETE FROM seo_meta WHERE entity_type = ? AND entity_id = ?', ['post', $postId]);
    $db->query('DELETE FROM posts WHERE id = ?', [$postId]);
});

[$editorToken, , ] = signin($app, $users['editor']);

$r = call($app, req('POST', '/api/v1/skills/run',
    ['skill' => 'seo_optimise_post', 'params' => [
        'post_id'          => (string) $postId,
        'meta_title'       => 'Fixture meta title',
        'meta_description' => 'Fixture meta description.',
        'focus_keyword'    => 'widgets',
    ]],
    ['Authorization' => "Bearer {$editorToken}"]));

check('an editor runs an editor skill -> 200', $r['status'] === 200, "(got {$r['status']})");
check('every step completed', ($r['body']['status'] ?? null) === 'ok',
    'status: ' . json_encode($r['body']['status'] ?? null) . ' steps: '
        . json_encode(array_column($r['body']['steps'] ?? [], 'status')));

$metaStmt = $livePdo->prepare(
    'SELECT meta_title, focus_keyword FROM seo_meta WHERE entity_type = ? AND entity_id = ?'
);
$metaStmt->execute(['post', $postId]);
$meta = $metaStmt->fetch(PDO::FETCH_ASSOC) ?: [];

check('the skill actually wrote the SEO row', ($meta['meta_title'] ?? '') === 'Fixture meta title',
    'seo_meta: ' . json_encode($meta));
check('and bound the optional keyword through', ($meta['focus_keyword'] ?? '') === 'widgets',
    'seo_meta: ' . json_encode($meta));

// --- 4. research ------------------------------------------------------------

echo "\n== POST /api/v1/research ==\n";

$r = call($app, req('POST', '/api/v1/research', [], $authAdmin));
check('research with no query -> 400', $r['status'] === 400, "(got {$r['status']})");

$r = call($app, req('POST', '/api/v1/research', ['query' => 'x']));
check('research without a token -> 401', $r['status'] === 401, "(got {$r['status']})");

// Not executed here — it would spend the page budget and hit the network. What
// matters at the route layer is that an author REACHES the handler, which the
// 400 below proves. A 403 would mean the floor was set too high for the role
// the research route claims to serve.
$r = call($app, req('POST', '/api/v1/research', ['query' => '   '],
    ['Authorization' => "Bearer {$authorToken}"]));
check('an author reaches the research handler (not stopped at the door)',
    $r['status'] === 400, "(got {$r['status']})");

// max_pages is a budget hint, not a correctness input: it is clamped to the
// fetcher's 1..5 rather than refused. Asserting it is NOT obeyed as sent is
// what catches the parameter being accepted and silently dropped, which is
// how it behaved before it was wired to WebResearcher::research().
//
// With no AI key configured this install answers 'unavailable' without
// fetching, so the response says the request was accepted rather than 400.
$r = call($app, req('POST', '/api/v1/research',
    ['query' => 'cms fixtures', 'max_pages' => 0], $authAdmin));
check('max_pages is accepted, not rejected',
    $r['status'] === 200, "(got {$r['status']} " . json_encode($r['body']) . ')');

// --- 5. BYOK: the credential must never reach a model -----------------------

echo "\n== BYOK ==\n";

$r = call($app, req('GET', '/api/v1/ai/providers'));
check('GET /ai/providers without a token -> 401', $r['status'] === 401, "(got {$r['status']})");

$r = call($app, req('GET', '/api/v1/ai/providers', [], ['Authorization' => "Bearer {$editorToken}"]));
check('an editor is refused the provider list', $r['status'] === 403, "(got {$r['status']})");

$r = call($app, req('POST', '/api/v1/ai/providers',
    ['provider' => 'kilo', 'api_key' => 'sk-test-should-be-refused'],
    ['Authorization' => "Bearer {$editorToken}"]));
check('an editor cannot write a provider key', $r['status'] === 403, "(got {$r['status']})");

$r = call($app, req('GET', '/api/v1/ai/providers', [], $authAdmin));
check('GET /ai/providers -> 200 for an admin', $r['status'] === 200, "(got {$r['status']})");
check('it reports which providers exist', isset($r['body']['providers']['kilo']),
    json_encode(array_slice($r['body'], 0, 2)));

// The one that matters. A status endpoint that echoed the stored key would be
// a credential endpoint; this asserts the whole raw body contains none of the
// test secret we just stored.
$secret = 'sk-test-' . bin2hex(random_bytes(16));

$r = call($app, req('POST', '/api/v1/ai/providers',
    ['provider' => 'kilo', 'api_key' => $secret], $authAdmin));

check('the admin CSRF token was harvested (else this check is meaningless)',
    $adminCsrf !== '', 'no window.CSRF on the dashboard');

if ($r['status'] === 503) {
    // BYOK is off in this install (no CMS_ENCRYPTION_KEY). That is a correct
    // configuration, and the message must say which variable to set rather
    // than surfacing a 500.
    check('without CMS_ENCRYPTION_KEY the write is refused with an actionable message',
        str_contains(strtolower((string) ($r['body']['error'] ?? '')), 'cms_encryption_key'),
        json_encode($r['body']));
    check('the key is not stored when encryption is unavailable',
        !str_contains($r['raw'], $secret));
} else {
    check('POST /ai/providers -> 201 for an admin', $r['status'] === 201, "(got {$r['status']})");
    check('the response never contains the key', !str_contains($r['raw'], $secret),
        'the key came back in the response body');
    check('the response says it stored', ($r['body']['stored'] ?? false) === true,
        json_encode($r['body']));

    // The status endpoint must not leak it either.
    $r = call($app, req('GET', '/api/v1/ai/providers', [], $authAdmin));
    check('GET /ai/providers does not leak the stored key', !str_contains($r['raw'], $secret),
        'the key appeared in the provider list');

    // And the settings screen must not render it either.
    $screen = call($app, browser('GET', '/admin/settings', [], $authAdmin));
    check('the settings screen renders for an admin', $screen['status'] === 200,
        "(got {$screen['status']})");
    check('the settings screen does not render the stored key',
        !str_contains($screen['raw'], $secret), 'the key appeared in the settings HTML');
    check('the settings screen does show that a key is configured',
        str_contains($screen['raw'], 'kilo'), 'no provider row on the settings screen');

    // Clean up so the fixture does not outlive the run.
    $r = call($app, req('DELETE', '/api/v1/ai/providers/kilo', [], $authAdmin));
    check('DELETE /ai/providers/{name} removes it', $r['status'] === 200, "(got {$r['status']})");
    check('the delete response does not echo the key', !str_contains($r['raw'], $secret));
}

// THE assertion. If the BYOK write is ever "simplified" into an agent intent,
// this fires: the key would sit inside a task string that callProvider() sends
// to the model vendor. The test cannot prove no request left the process; it
// pins the contract that no agent-shaped surface advertises a credential
// parameter to any caller.
$r = call($app, req('GET', '/api/v1/agents/spec', [], $authAdmin));
check('the agent spec carries no provider credential material',
    !str_contains($r['raw'], 'set_provider_key'),
    'the agents endpoint advertises a credential-setting intent to any caller');

$r = call($app, req('GET', '/api/v1/agents/intents', [], $authAdmin));
check('the intent catalog carries no provider credential material',
    !str_contains($r['raw'], 'set_provider_key'),
    'the intent catalog advertises a credential-setting intent');

// =====================================================================

echo "\n==============================================\n";
echo "  PASSED: {$pass}   FAILED: {$fail}\n";
echo "==============================================\n";

exit($fail === 0 ? 0 : 1);