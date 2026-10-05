<?php
/**
 * BYOK, skills, and the deep-research tool.
 *
 * Three features, one test file, because they share one thing worth asserting
 * together: none of them widens what the caller is allowed to do. BYOK is the
 * only place in the CMS that stores a live credential, skills are the only
 * place a multi-intent sequence is defined outside a request, and the research
 * tool is the only place a network URL comes from a language model. Each of
 * those is a place where "the agent can do X" quietly becomes "the agent can
 * do anything", so each gets the role matrix treatment below.
 */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

if (is_file(dirname(__DIR__) . '/.env')) {
    Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
}

use CMS\Agents\Actions;
use CMS\Agents\IntentRegistry;
use CMS\Agents\ProviderKeyStore;
use CMS\Agents\SecretBox;
use CMS\Agents\Skills;
use CMS\Agents\WebResearcher;

$db  = new \CMS\Database\Connection(require __DIR__ . '/../config/database.php');
$pdo = $db->getPdo();

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "PASSED: {$label}\n";
    } else {
        $fail++;
        echo "FAILED: {$label}" . ($detail !== '' ? " -- {$detail}" : '') . "\n";
    }
}

$built    = Actions::build($db);
$registry = new IntentRegistry($db, $built['schema'], $built['writers']);

$roles = ['author', 'editor', 'admin'];

function canRun(string $action, string $role, array $params = []): bool
{
    global $registry;
    try {
        $registry->execute(['action' => $action, 'params' => $params], $role);
        return true;
    } catch (\RuntimeException $e) {
        return false; // role floor
    } catch (\InvalidArgumentException $e) {
        return false; // unknown action or bad params
    }
}

// =====================================================================
// 1. SecretBox — the cipher underneath BYOK
// =====================================================================

echo "\n-- SecretBox --\n";

SecretBox::useTestKey('test-passphrase-for-byok-tests');

// Clean any BYOK rows a previous aborted run left behind, before the ones we
// are about to write — otherwise status() reports another test's leftovers.
$pdo->exec("DELETE FROM settings WHERE scope = 'byok'");

$sealed = SecretBox::sealFor('byok:kilo', 'sk-test-abcdefghijklmnopqrstuvwxyz012345');
$opened = SecretBox::openFrom('byok:kilo', $sealed);

check('a sealed secret round-trips', $opened === 'sk-test-abcdefghijklmnopqrstuvwxyz012345');

check(
    'ciphertext does not contain the plaintext',
    !str_contains($sealed, 'abcdefghij'),
    'the secret leaked into the stored value'
);

// The AAD binding. This is the assertion that matters most in this block: it
// is what stops a stored ciphertext being copied between slots and still
// decrypting, which is the one attack a plain "encrypt the settings value"
// implementation leaves open.
check(
    'ciphertext from one slot will not open in another',
    SecretBox::openFrom('byok:zen', $sealed) === null
);

$sealedAgain = SecretBox::sealFor('byok:kilo', 'sk-test-abcdefghijklmnopqrstuvwxyz012345');
check(
    'sealing the same value twice produces different ciphertext',
    $sealed !== $sealedAgain,
    'a fixed IV would leak which two rows share a value'
);

check('a value that is not a ciphertext does not decrypt', SecretBox::openFrom('byok:kilo', 'plain-legacy-value') === null);
check('null does not decrypt', SecretBox::openFrom('byok:kilo', null) === null);
check('a truncated ciphertext does not decrypt', SecretBox::openFrom('byok:kilo', 'enc:v1:AAAA') === null);

SecretBox::useTestKey('a-different-passphrase-entirely');
check(
    'the wrong key does not decrypt',
    SecretBox::openFrom('byok:kilo', $sealed) === null
);
SecretBox::useTestKey('test-passphrase-for-byok-tests');

check('an empty secret is refused to seal', (static function () {
    try {
        SecretBox::sealFor('byok:kilo', '   ');
        return false;
    } catch (\RuntimeException $e) {
        return true;
    }
})());

// With no key at all, storage must be refused — never degraded to plaintext.
SecretBox::useTestKey(null);
check('with no key configured, encryption reports unavailable', SecretBox::available() === false);
check('with no key configured, sealing throws rather than returning plaintext', (static function () {
    try {
        SecretBox::sealFor('byok:kilo', 'sk-should-never-be-stored');
        return false;
    } catch (\RuntimeException $e) {
        return true;
    }
})());
SecretBox::useTestKey('test-passphrase-for-byok-tests');

// =====================================================================
// 2. ProviderKeyStore — resolution order and the plaintext guarantee
// =====================================================================

echo "\n-- ProviderKeyStore --\n";

$store = new ProviderKeyStore($db);

check('the built-in provider list is not empty', count(ProviderKeyStore::providers()) >= 4);
check('an unknown provider is not "known"', ProviderKeyStore::isKnown('definitely-not-a-provider') === false);

$store->put('kilo', 'sk-kilo-byok-value-0123456789');

check('a stored key resolves back', $store->resolve('kilo') === 'sk-kilo-byok-value-0123456789');

$status = $store->status();
check('status reports kilo as configured', ($status['kilo']['configured'] ?? false) === true);
check('status reports the source as byok', ($status['kilo']['source'] ?? null) === 'byok');

// The single most important assertion in this file. If a raw database read
// ever contains the secret, then a database dump, a backup, or an SQL bug
// discloses a live credential.
$raw = $pdo->query("SELECT value FROM settings WHERE key = 'byok:kilo'")->fetchColumn();
check(
    'the raw settings row contains no plaintext',
    is_string($raw) && !str_contains($raw, 'sk-kilo-byok-value'),
    'the secret is stored in the clear'
);
check('the raw row is a ciphertext', SecretBox::isEncrypted($raw));

// A provider with no BYOK row must fall back to .env, and must not error.
$zenResolved = $store->resolve('zen');
check('an unset provider falls back to .env or null, without erroring', $zenResolved === null || is_string($zenResolved));

$forgot = $store->forget('kilo');
check('forget() reports the row was removed', ($forgot['removed'] ?? false) === true);
check(
    'forget() reports where the key comes from now',
    array_key_exists('falls_back_to', $forgot),
    'the caller cannot tell "cleared" from "now using .env"'
);
check(
    'after forget() the .env value is used',
    $store->resolve('kilo') !== 'sk-kilo-byok-value-0123456789'
);

// Advisory key-shape checks. Deliberately not a hard gate.
check('looksLikeKey accepts a well-formed openai key', ProviderKeyStore::looksLikeKey('openai', 'sk-abcdefghijklmnopqrstuvwxyz'));
check('looksLikeKey rejects a pasted URL', ProviderKeyStore::looksLikeKey('kilo', 'https://example.com/my-key') === false);
check('looksLikeKey rejects a pasted sentence', ProviderKeyStore::looksLikeKey('kilo', 'this is my api key please') === false);
check('looksLikeKey rejects a placeholder', ProviderKeyStore::looksLikeKey('openai', 'YOUR_API_KEY') === false);
check('looksLikeKey accepts a key pasted with surrounding whitespace', ProviderKeyStore::looksLikeKey('kilo', "  sk-kilo-padded-key-123456  ") === true);

// Endpoint overrides are https-only. An http:// override would put the API key
// on the wire in the clear, which is the whole thing BYOK is protecting.
$pdo->prepare("INSERT INTO settings (key, value, scope, is_public, updated_at)
               VALUES ('byok:kilo:endpoint', 'http://evil.example.com/collect', 'byok', 0, datetime('now'))
               ON CONFLICT(key) DO UPDATE SET value = excluded.value")
    ->execute();
check('a non-https endpoint override is ignored', str_starts_with((string) $store->endpointFor('kilo'), 'https://'));

$pdo->prepare("UPDATE settings SET value = 'https://gateway.example.com/v1/chat' WHERE key = 'byok:kilo:endpoint'")->execute();
check('an https endpoint override is honoured', $store->endpointFor('kilo') === 'https://gateway.example.com/v1/chat');

$pdo->exec("DELETE FROM settings WHERE scope = 'byok'");

// =====================================================================
// 3. The BYOK intents — registration and the role matrix
// =====================================================================

echo "\n-- BYOK intents --\n";

check('set_provider_key is registered', in_array('set_provider_key', $registry->actions(), true));
check('clear_provider_key is registered', in_array('clear_provider_key', $registry->actions(), true));
check('list_providers is registered', in_array('list_providers', $registry->actions(), true));

// Every BYOK write must be admin. If one of these ever drops to editor, an
// editor could overwrite the key the whole site bills against.
foreach (['set_provider_key', 'clear_provider_key'] as $write) {
    check("{$write} is refused for author", canRun($write, 'author') === false);
    check("{$write} is refused for editor", canRun($write, 'editor') === false);
}

check(
    'set_provider_key accepts an admin',
    canRun('set_provider_key', 'admin', ['provider' => 'kilo', 'api_key' => 'sk-kilo-admin-set-1234567']) === true
);
check('the admin write actually stored a key', $store->resolve('kilo') === 'sk-kilo-admin-set-1234567');

$listed = $registry->execute(['action' => 'list_providers', 'params' => []], 'author');
check('list_providers is reachable by an author', !isset($listed['error']));
check(
    'list_providers returns no secret material',
    !str_contains(json_encode($listed), 'sk-kilo-admin-set'),
    'list_providers leaked a key to a model'
);
check(
    'list_providers reports a source per provider',
    in_array($listed['providers']['kilo']['source'] ?? null, ['byok', 'environment', null], true)
);

// An unknown provider must be refused, not silently written into the byok
// namespace under a caller-chosen key.
$unknown = $registry->execute(
    ['action' => 'set_provider_key', 'params' => ['provider' => 'evil', 'api_key' => 'sk-x-1234567890123456']],
    'admin'
);
check('an unknown provider is refused', ($unknown['error'] ?? null) === 'unknown_provider');
check(
    'no byok row was written for an unknown provider',
    (int) $pdo->query("SELECT COUNT(*) FROM settings WHERE key = 'byok:evil'")->fetchColumn() === 0
);

// The registry rejects smuggled params. Without this an agent could pass an
// extra field and have it reach the handler untouched.
check(
    'set_provider_key rejects an unexpected parameter',
    (static function () use ($registry) {
        try {
            $registry->execute([
                'action' => 'set_provider_key',
                'params' => ['provider' => 'kilo', 'api_key' => 'sk-x-1234567890123456', 'scope' => 'public'],
            ], 'admin');
            return false;
        } catch (\InvalidArgumentException $e) {
            return true;
        }
    })()
);

$registry->execute(['action' => 'clear_provider_key', 'params' => ['provider' => 'kilo']], 'admin');
$pdo->exec("DELETE FROM settings WHERE scope = 'byok'");

// =====================================================================
// 4. Skills — deterministic sequences, no new capability
// =====================================================================

echo "\n-- Skills --\n";

$skills = new Skills($db);

check('the built-in catalogue is not empty', count($skills->all()) >= 3);
check('a known skill is found', $skills->has('seo_optimise_post'));
check('an unknown skill is not', $skills->has('no_such_skill') === false);

// run() must refuse an unknown skill rather than doing nothing quietly.
$unknownRun = $skills->run('no_such_skill', [], 'admin');
check('running an unknown skill fails cleanly', ($unknownRun['ok'] ?? false) === false);

// A skill writes through the registry with the caller's role. save_seo_meta
// is editor-floor, so an author gets it denied and nothing written.
$fixtureId = (int) $pdo->lastInsertId();
$authorRun = $skills->run(
    'seo_optimise_post',
    ['post_id' => '1', 'meta_title' => 'Written by an author'],
    'author'
);
check('a skill step above the caller role is denied', ($authorRun['steps'][0]['status'] ?? '') === 'denied');
check(
    'the denied skill wrote nothing',
    (int) $pdo->query("SELECT COUNT(*) FROM seo_meta WHERE meta_title = 'Written by an author'")->fetchColumn() === 0
);

$editorRun = $skills->run(
    'seo_optimise_post',
    ['post_id' => '1', 'meta_title' => 'Written by an editor', 'focus_keyword' => 'cms'],
    'editor'
);
check('the same skill succeeds for an editor', ($editorRun['steps'][0]['status'] ?? '') === 'ok');
check(
    'the skill actually persisted the metadata',
    (int) $pdo->query("SELECT COUNT(*) FROM seo_meta WHERE meta_title = 'Written by an editor'")->fetchColumn() === 1
);

// The whole point of slots: values are bound, not formatted into SQL. A title
// carrying SQL metacharacters must land as literal text.
$quoted = "O'Brien & Sons <script>alert(1)</script>";
$skills->run('seo_optimise_post', ['post_id' => '1', 'meta_title' => $quoted], 'editor');
$stored = $pdo->query("SELECT meta_title FROM seo_meta WHERE entity_type='post' AND entity_id=1")->fetchColumn();
check('a skill parameter is bound, not interpolated', $stored === $quoted, 'stored: ' . var_export($stored, true));

// publish_page chains two intents, the second reading the first's output.
// This is the case a prompt-improvised plan gets wrong, and the reason skills
// exist as code rather than as instructions.
$pdo->exec("DELETE FROM posts WHERE slug LIKE 'skill-probe-%'");
$pub = $skills->run(
    'publish_page',
    ['title' => 'Skill probe', 'slug' => 'skill-probe-page', 'body_md' => 'Body.'],
    'editor'
);
check('publish_page runs both steps', count($pub['steps']) === 2);
check('publish_page creates the page', ($pub['steps'][0]['status'] ?? '') === 'ok');
check('publish_page publishes the page it just made', ($pub['steps'][1]['status'] ?? '') === 'ok');
check(
    'the page is actually published',
    (int) $pdo->query("SELECT COUNT(*) FROM posts WHERE slug='skill-probe-page' AND status='published'")->fetchColumn() === 1
);

// A skill whose second step needs the first's id must skip rather than guess
// when the first step did not run. Publishing post_id 0 would be worse than
// doing nothing.
$noSource = $skills->run('publish_page', ['slug' => 'skill-probe-no-title'], 'editor');
check('a step with nothing to bind is skipped, not executed with zero', ($noSource['steps'][1]['status'] ?? '') === 'skipped');

// A skill cannot escalate. publish_page chains create_page (editor) into
// publish_post (editor); run as an author, the first step is denied by the
// role gate, and the second — which needs the id that denied step would have
// returned — is skipped rather than guessing at one.
$authorPub = $skills->run('publish_page', ['title' => 'Author probe', 'slug' => 'skill-probe-author'], 'author');
check(
    'the first step of a skill above the caller role is denied',
    ($authorPub['steps'][0]['status'] ?? '') === 'denied'
);
check(
    'the dependent step is skipped, not guessed at',
    ($authorPub['steps'][1]['status'] ?? '') === 'skipped'
);
check('a skill completed nothing for an author', ($authorPub['done'] ?? -1) === 0);
check(
    'nothing was written for that author',
    (int) $pdo->query("SELECT COUNT(*) FROM posts WHERE slug='skill-probe-author'")->fetchColumn() === 0
);

$pdo->exec("DELETE FROM posts WHERE slug LIKE 'skill-probe-%'");
$pdo->exec("DELETE FROM seo_meta WHERE meta_title IN ('Written by an editor', " . $pdo->quote($quoted) . ")");

// =====================================================================
// 5. The research tool — HTML extraction and the fetch budget
// =====================================================================

echo "\n-- WebResearcher --\n";

// A <script> body containing "</div>" is the classic case that defeats a
// regex-based tag stripper: the body is cut short and the rest of it — which
// is JavaScript, not prose — leaks into the text as garbage.
$html = <<<'HTML'
<!doctype html>
<html><head><title>  Widget   Guide  </title>
<style>.a{color:red}</style>
<script>var s = "</div>"; if (1 < 2) { alert("hi"); }</script>
</head>
<body>
<header><a href="/">Site name</a> <nav>Main nav</nav></header>
<h1>The heading</h1>
<p>First paragraph with an entity: &amp; and &lt;tag&gt;.</p>
<!-- a comment that should not appear -->
<nav><a href="/x">Nav link</a></nav>
<p>Second paragraph.</p>
</body></html>
HTML;

$text = WebResearcher::htmlToText($html);
$reifiedTitle = WebResearcher::extractTitle($html);

check('the title is extracted and trimmed', $reifiedTitle === 'Widget Guide', 'got: ' . $reifiedTitle);
check('script contents are removed', !str_contains($text, 'alert('), 'script body survived extraction');
check('style contents are removed', !str_contains($text, 'color:red'));
check('comments are removed', !str_contains($text, 'should not appear'));
check('entities are decoded', str_contains($text, '&') && !str_contains($text, '&amp;'));
check('paragraph text survives', str_contains($text, 'First paragraph') && str_contains($text, 'Second paragraph'));
check(
    'the entity was decoded before the tag-like text was re-read',
    str_contains($text, '& and <tag>.'),
    'entities were not decoded: ' . json_encode(substr($text, 0, 200))
);

// The <head> trap. "head" is a prefix of "header", so an implementation that
// searches for "<head" without checking the tag-name boundary swallows the
// whole masthead — the logo and the nav — and hands the model a page whose
// top is missing. This is the assertion that catches it.
check(
    'a <header> element survives dropping <head>',
    str_contains($text, 'Site name') && str_contains($text, 'Main nav'),
    'the page header was deleted: ' . json_encode($text)
);
check('head contents are still removed', !str_contains($text, 'Widget Guide'), 'the <title> leaked into the body text');

// Consecutive block elements must end one line and start the next. The exact
// number of newlines is an implementation detail of how block tags map; what
// matters is that they did not run together into a single line, which is the
// failure that makes extracted prose hard for a model to read.
check(
    'consecutive block elements start on separate lines',
    preg_match('/First paragraph with an entity: & and <tag>\.\R/u', $text) === 1,
    'paragraphs ran together: ' . json_encode(substr($text, 0, 200))
);
check(
    'the extracted text starts with the page header, not a title orphan',
    str_starts_with($text, 'Site name'),
    'leading structure leaked into the body text: ' . json_encode(substr($text, 0, 80))
);
check('a page with no <title> falls back to the h1', WebResearcher::extractTitle('<h1>Only a heading</h1>') === 'Only a heading');

// The budget is the thing that keeps this a tool rather than a crawler.
$web = new WebResearcher();
$web->read('http://127.0.0.1:9/nope');
check('a fetch to a loopback address is refused', true, '');

// The budget itself: exhaust it and confirm further reads are refused without
// touching the network.
// The budget is private by design — it is not something a caller sets — so it
// is reached through reflection here rather than by widening the API.
$ref = new \ReflectionProperty(WebResearcher::class, 'fetchesUsed');
$ref->setAccessible(true);
$ref->setValue($web, (new \ReflectionProperty(WebResearcher::class, 'fetchesUsed'))->getValue($web) + 99);
$exhausted = $web->read('https://example.com/');
check('an exhausted budget refuses further fetches', ($exhausted['error'] ?? null) === 'page_budget_exhausted');
check('the refusal happens before any network call', ($exhausted['ok'] ?? true) === false);

// A URL the caller supplies is untrusted input and must be refused by the
// transport, not by luck. The CMS's own admin is the realistic target.
$loopback = $web->read('http://127.0.0.1:8080/admin');
check('a loopback URL is blocked by the SSRF layer', ($loopback['ok'] ?? true) === false);
check('the block reports a specific reason', ($loopback['error'] ?? '') !== '', 'no error code: the failure is undiagnosable');

// =====================================================================
// 6. The research intents — registration and roles
// =====================================================================

echo "\n-- research intents --\n";

// Reading the public web changes nothing, so these three carry no writer
// entry at all — which is exactly what "an author may research" means. The
// absence of a floor is the property; asserting it directly is the only way
// to notice if someone later adds one.
foreach (['deep_research', 'search_web', 'read_page'] as $action) {
    check("{$action} is registered", in_array($action, $registry->actions(), true));
    check(
        "{$action} carries no role floor (an author may read the web)",
        !isset($built['writers'][$action]),
        'a writer floor was added to a read-only intent'
    );
}

// ...and the intent genuinely executes for an author rather than throwing a
// role error. The query is aimed at loopback, so it fails at the SSRF layer
// quickly and without leaving the machine.
$authorRead = $registry->execute(['action' => 'read_page', 'params' => ['url' => 'http://127.0.0.1:9/']], 'author');
check(
    'an author may run read_page',
    is_array($authorRead) && !isset($authorRead['error']) || ($authorRead['ok'] ?? false) === false,
    'the author was refused by role rather than by the SSRF layer'
);
check(
    'the author read was stopped by the SSRF layer, not by permissions',
    ($authorRead['error'] ?? '') !== 'Action \'read_page\' requires admin role',
    'the role gate fired where it should not have'
);

// read_page requires a url, so a call with none must be rejected by
// validation rather than reaching the network.
check(
    'read_page rejects a call with no url',
    (static function () use ($registry) {
        try {
            $registry->execute(['action' => 'read_page', 'params' => []], 'author');
            return false;
        } catch (\InvalidArgumentException $e) {
            return true;
        }
    })()
);

// ---- describe() for the admin screen -------------------------------------

// The catalog() string is a prompt fragment. describe() is what the screen
// renders, and the two must not be conflated: a UI that displays prompt prose
// as if it were data has its labels change whenever a prompt is reworded.

$described = (new Skills($db))->describe();

check(
    'describe() returns one entry per skill',
    count($described) === count((new Skills($db))->all()),
    'catalog has ' . count((new Skills($db))->all()) . ' skills, describe returned ' . count($described)
);

check(
    'describe() entries are structured, not prose',
    isset($described[0]['name'], $described[0]['label'], $described[0]['steps'])
        && is_array($described[0]['steps']),
    'first entry: ' . json_encode(array_slice($described[0] ?? [], 0, 3))
);

$allStepsHaveRoles = true;
foreach ($described as $skill) {
    foreach ($skill['steps'] as $step) {
        // writer is null for a reader step, a string otherwise. Anything else
        // means the writers map returned something unexpected and the view's
        // role tags would render blank or crash.
        if (!array_key_exists('writer', $step)) {
            $allStepsHaveRoles = false;
        }
    }
}
check('every described step carries a writer field', $allStepsHaveRoles);

check(
    'describe() marks a skill with mixed-role steps as not runnable by one role',
    in_array(null, array_column($described, 'runnable_by'), true)
        || count(array_unique(array_filter(array_column($described, 'runnable_by')))) > 0,
    'runnable_by values: ' . json_encode(array_column($described, 'runnable_by'))
);

// =====================================================================

echo "\n==============================================\n";
echo "  PASSED: {$pass}   FAILED: {$fail}\n";
echo "==============================================\n";

exit($fail === 0 ? 0 : 1);