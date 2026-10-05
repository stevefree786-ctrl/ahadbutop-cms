<?php
/**
 * Agent pipeline test — the security contract of the AI layer.
 *
 *   1. Role enforcement through the agent (author rejected, editor allowed)
 *   2. Unknown actions, smuggled params and injection attempts rejected
 *   3. Context round-trips user_id / user_role
 *   4. Orchestrator actually carries the role through to the agent
 *   5. Sitemap is real, parseable, XML-escaped XML
 *   6. Scoring is deterministic and never calls a model
 *   7. No API key -> clean failure, never a fatal
 *   8. Prose instead of JSON -> clean failure, never a parse fatal
 *   9. enqueueJob() is idempotent on a repeated idempotency_key
 *
 * No API key is needed for any of it: role checks go through
 * IntentRegistry directly, and the model seam is stubbed by subclassing.
 */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use CMS\Agents\ActionRequest;
use CMS\Agents\Actions;
use CMS\Agents\AdminAgent;
use CMS\Agents\Context;
use CMS\Agents\DesignAgent;
use CMS\Agents\IntentEncoder;
use CMS\Agents\IntentRegistry;
use CMS\Agents\JobTypes;
use CMS\Agents\SeoAgent;
use CMS\Agents\Tokens;
use CMS\Orchestrator;

if (is_file(dirname(__DIR__) . '/.env')) {
    Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
}

$db = new \CMS\Database\Connection(require __DIR__ . '/../config/database.php');
$pdo = $db->getPdo();

$built    = Actions::build($db);
$registry = new IntentRegistry($db, $built['schema'], $built['writers']);

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    if ($ok) { $pass++; echo "  PASS  {$label}\n"; }
    else     { $fail++; echo "  FAIL  {$label}" . ($detail !== '' ? "  ({$detail})" : '') . "\n"; }
}

// Rows this file creates, removed at the end whatever happens.
$created = ['posts' => [], 'seo_meta' => [], 'design_tokens' => [], 'themes' => [], 'jobs' => []];

function makePost(\PDO $pdo, array $over = []): int
{
    $row = $over + [
        'uuid'       => bin2hex(random_bytes(16)),
        'slug'       => 'agent-test-' . bin2hex(random_bytes(6)),
        'type'       => 'post',
        'title'      => 'Agent Test Post',
        'excerpt'    => '',
        'body_md'    => 'Body',
        'status'     => 'draft',
        'origin'     => 'manual',
        'author_id'  => 1,
    ];
    $cols = array_keys($row);
    $sql = 'INSERT INTO posts (' . implode(', ', $cols) . ') VALUES ('
        . implode(', ', array_fill(0, count($cols), '?')) . ')';
    $st = $pdo->prepare($sql);
    $st->execute(array_values($row));
    return (int) $pdo->lastInsertId();
}

/** Unique-per-run fixed slugs, so a crashed previous run never collides. */
function runSlug(string $suffix): string
{
    return 'agents-test-' . substr(bin2hex(random_bytes(6)), 0, 8) . '-' . $suffix;
}

/**
 * Read one scalar and fully drain the cursor.
 *
 * A reusable PDOStatement is the wrong tool for this: these assertions read
 * a post, then have an agent WRITE to it, then read again. PDO keeps the
 * SQLite statement cursor open until the row set is drained, and SQLite
 * refuses the write while it is — "database is locked", thrown from inside
 * the agent's handler. A fresh fully-drained query per read never holds a
 * lock across the write.
 */
function scalar(\PDO $pdo, string $sql, array $args = [])
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);
    return $stmt->fetchColumn();
}

/** scalar(), but drains the row set first so the statement cursor closes. */
function row(\PDO $pdo, string $sql, array $args = [])
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);
    $out = $stmt->fetch();
    $stmt->fetchAll();
    return $out === false ? null : $out;
}

/** All rows, fully drained. */
function rows(\PDO $pdo, string $sql, array $args = [])
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);
    return $stmt->fetchAll();
}

/** Test double for the LLM seam — no network, no key. */
class StubSeoAgent extends SeoAgent
{
    public function __construct(private ?string $reply, \CMS\Database\Connection $db)
    {
        parent::__construct($db);
    }
    protected function callLLM(string $systemPrompt, string $userPrompt, array $opts = []): ?string
    {
        return $this->reply;
    }
}

class StubDesignAgent extends DesignAgent
{
    public function __construct(private ?string $reply, \CMS\Database\Connection $db)
    {
        parent::__construct($db);
    }
    protected function callLLM(string $systemPrompt, string $userPrompt, array $opts = []): ?string
    {
        return $this->reply;
    }
}

/**
 * Runs a real agent class with callLLM() forced to return null — the state
 * BaseAgent is in when config/api.php has no api_key for the provider.
 *
 * This is how the "no provider configured" path is tested without depending on
 * whether the machine running the suite happens to have a key in .env: the
 * agent under test is the genuine class, only its network seam is replaced.
 *
 * One subclass per agent rather than a reflective wrapper, because each
 * agent declares its own execute() signature and PHP checks those at compile
 * time — a generic forwarder would have to guess the wrong one.
 */
trait Keyless
{
    protected function callLLM(string $systemPrompt, string $userPrompt, array $opts = []): ?string
    {
        return null;
    }
}

final class KeylessSeoAgent extends SeoAgent
{
    use Keyless;
}

final class KeylessDesignAgent extends DesignAgent
{
    use Keyless;
}

final class KeylessAdminAgent extends AdminAgent
{
    use Keyless;
}

/** Replaces the 'admin' agent with a recorder, via the real routing seam. */
class SwapOrchestrator extends \CMS\Orchestrator
{
    public RecordingAdminAgent $substitute;

    public function __construct(\CMS\Database\Connection $db)
    {
        parent::__construct();
        $this->substitute = new RecordingAdminAgent($db);
    }

    protected function resolveAgent(string $name): ?\CMS\Agents\BaseAgent
    {
        return $name === 'admin' ? $this->substitute : parent::resolveAgent($name);
    }
}

class RecordingAdminAgent extends AdminAgent
{
    public array $seenContexts = [];
    public function execute(string $task, array|Context $context = []): array
    {
        $ctx = $context instanceof Context ? $context : Context::fromArray($context);
        $this->seenContexts[] = $ctx->toArray();
        return parent::execute($task, $context);
    }
}

// =========================================================================
echo "\n== 1. Role enforcement through the agent ==\n";
// =========================================================================
$postId = makePost($pdo, ['title' => 'Role Enforcement Post', 'status' => 'draft']);
$created['posts'][] = $postId;

$authorErr = null;
try {
    $registry->execute(['action' => 'publish_post', 'params' => ['post_id' => $postId]], 'author');
} catch (Throwable $e) {
    $authorErr = $e->getMessage();
}
check('author publish_post is REJECTED', $authorErr !== null, 'no error thrown');
check('rejection names the role', $authorErr !== null && str_contains($authorErr, 'author'), (string) $authorErr);

$postStatus = fn(int $id) => scalar($pdo, 'SELECT status FROM posts WHERE id = ?', [$id]);
check('author attempt did NOT publish the post', $postStatus($postId) === 'draft');

$editorResult = $registry->execute(['action' => 'publish_post', 'params' => ['post_id' => $postId]], 'editor');
check('editor publish_post succeeds', ($editorResult['published'] ?? false) === true, json_encode($editorResult));
check('post is now published', $postStatus($postId) === 'published');

check('update_setting stays admin-only', ($built['writers']['update_setting'] ?? null) === 'admin');
$settingErr = null;
try {
    $registry->execute(['action' => 'update_setting', 'params' => ['key' => 'k', 'value' => 'v']], 'editor');
} catch (Throwable $e) {
    $settingErr = $e->getMessage();
}
check('editor cannot update_setting', $settingErr !== null, (string) $settingErr);

// =========================================================================
echo "\n== 2. Unknown action, smuggled params, injection ==\n";
// =========================================================================
$err = null;
try {
    $registry->execute(['action' => 'drop_all_tables', 'params' => []], 'admin');
} catch (Throwable $e) {
    $err = $e->getMessage();
}
check('unknown action is rejected', $err !== null && str_contains($err, 'Unknown action'), (string) $err);

$err = null;
try {
    $registry->execute(
        ['action' => 'publish_post', 'params' => ['post_id' => $postId, 'role' => 'admin']],
        'admin'
    );
} catch (Throwable $e) {
    $err = $e->getMessage();
}
check('smuggled extra param is rejected', $err !== null && str_contains($err, 'Unexpected parameter'), (string) $err);

// A canary row, so we can prove the injection attempt left it untouched.
// DELETE first: a previous run that died before cleanup must not poison this one.
$pdo->exec("DELETE FROM posts WHERE uuid = 'injection-canary-uuid'");
$canary = $pdo->prepare(
    "INSERT INTO posts (uuid, slug, type, title, status) VALUES (?, ?, 'post', 'Canary', 'draft')"
);
$canary->execute(['injection-canary-uuid', 'injection-canary']);
$canaryCount = fn(): int => (int) scalar($pdo, 'SELECT COUNT(*) FROM posts WHERE slug = ?', ['injection-canary']);
$canaryCountBefore = $canaryCount();
$totalBefore = (int) scalar($pdo, 'SELECT COUNT(*) FROM posts');

$err = null;
try {
    $registry->execute(['action' => 'publish_post', 'params' => ['post_id' => '1; DROP TABLE posts']], 'admin');
} catch (Throwable $e) {
    $err = $e->getMessage();
}
check('SQL-injected post_id is rejected', $err !== null && str_contains($err, 'integer'), (string) $err);

// The whole point: the table is still there and still has its rows.
$tables = rows($pdo, "SELECT name FROM sqlite_master WHERE type='table' AND name='posts'");
check('posts table still exists', count($tables) === 1, 'table is gone');
$totalAfter = (int) scalar($pdo, 'SELECT COUNT(*) FROM posts');
check('posts still has all its rows', $totalAfter === $totalBefore, "before={$totalBefore} after={$totalAfter}");
check('canary row survived untouched', $canaryCount() === $canaryCountBefore);

// =========================================================================
echo "\n== 3. Context round-trips identity ==\n";
// =========================================================================
$ctx = new Context(userId: 7, userRole: 'editor', siteTitle: 'Hermes', siteDescription: 'A CMS', userEmail: 'e@cms.local');
$arr = $ctx->toArray();
check('toArray preserves user_id', ($arr['user_id'] ?? null) === 7, json_encode($arr));
check('toArray preserves user_role', ($arr['user_role'] ?? null) === 'editor', json_encode($arr));

$back = Context::fromArray($arr);
check('fromArray preserves user_id', $back->userId === 7);
check('fromArray preserves user_role', $back->userRole === 'editor');
check('toArray/fromArray round-trips exactly', $back->toArray() === $arr, json_encode($back->toArray()));

$fakeRequest = new class {
    public function getAttribute($k, $default = null)
    {
        return ['user_id' => '42', 'user_role' => 'admin', 'user_email' => 'a@cms.local'][$k] ?? $default;
    }
};
// fromRequest() reads the AuthMiddleware attributes off a real PSR-7 request.
$psrRequest = (new \Slim\Psr7\Factory\ServerRequestFactory())
    ->createServerRequest('POST', '/')
    ->withAttribute('user_id', 42)
    ->withAttribute('user_role', 'admin')
    ->withAttribute('user_email', 'a@cms.local');
$fromReq = Context::fromRequest($psrRequest, null, ['title' => 'Site', 'description' => 'Desc']);
check('fromRequest reads user_id', $fromReq->userId === 42, json_encode($fromReq->toArray()));
check('fromRequest reads user_role', $fromReq->userRole === 'admin', json_encode($fromReq->toArray()));
check('fromRequest reads site title', $fromReq->siteTitle === 'Site');
check('anonymous request defaults to author', Context::fromRequest(
    (new \Slim\Psr7\Factory\ServerRequestFactory())->createServerRequest('GET', '/')
)->userRole === 'author');
check('a bogus role cannot become admin',
    Context::fromArray(['user_role' => 'superuser'])->userRole === 'author');
check('fromArray(toArray) matches fromRequest',
    Context::fromArray($fromReq->toArray())->toArray() === $fromReq->toArray());

// =========================================================================
echo "\n== 4. Orchestrator carries the role through ==\n";
// =========================================================================
$draftId = makePost($pdo, ['title' => 'Orchestrator Post', 'status' => 'draft']);
$created['posts'][] = $draftId;

$asAuthor = (new Orchestrator([], new Context(userId: 3, userRole: 'author')))
    ->process("publish post {$draftId}");

$adminSteps = array_values(array_filter($asAuthor['steps'], fn($s) => ($s['agent'] ?? '') === 'admin'));
check('orchestrator routed to the admin agent', count($adminSteps) === 1, json_encode(array_column($asAuthor['steps'], 'agent')));

// The agent has no API key, so it cannot produce an intent — but the ROLE it
// was about to execute as must have survived the trip.
check('orchestrator reports the acting role', ($asAuthor['context']['user_role'] ?? '') === 'author', json_encode($asAuthor['context'] ?? []));
check('orchestrator reports the acting user id', ($asAuthor['context']['user_id'] ?? null) === 3);

// Same prompt, same context array form: an author must still be an author.
$asAuthorArray = (new Orchestrator())->process("publish post {$draftId}", ['user_id' => 3, 'user_role' => 'author']);
check('array context keeps user_role', ($asAuthorArray['context']['user_role'] ?? '') === 'author');
check('missing user_role defaults to author (not admin)',
    ((new Orchestrator())->process('publish post 1')['context']['user_role'] ?? '') === 'author');

// Nothing above may have published: an author must not be able to publish
// through any spelling of the context. Asserted here, before the editor case
// below — an editor publishing is correct, so this check sat in the wrong
// place and only ever passed while the agents had no key to act with.
check('no author-context call published the post', $postStatus($draftId) === 'draft',
    'status=' . var_export($postStatus($draftId), true));

check('editor role survives the orchestrator',
    ((new Orchestrator([], new Context(userId: 3, userRole: 'editor')))->process("publish post {$draftId}")['context']['user_role'] ?? '') === 'editor');

// Direct proof that the orchestrator's admin agent enforces the role it was given.
$recorder = new RecordingAdminAgent($db);
$recorder->execute('publish post 1', ['user_id' => 3, 'user_role' => 'author']);
check('AdminAgent received user_role from the context array',
    ($recorder->seenContexts[0]['user_role'] ?? '') === 'author', json_encode($recorder->seenContexts[0] ?? []));

$recorder->execute('publish post 1', new Context(userId: 3, userRole: 'admin'));
check('AdminAgent accepts a Context object too',
    ($recorder->seenContexts[1]['user_role'] ?? '') === 'admin');

// resolveAgent() is the seam routing actually goes through. process() used to
// read the private $agents map directly, which left this override point inert:
// a subclass could swap an agent in here and routing would ignore it. This
// asserts the swap is honoured.
$swap = new SwapOrchestrator($db);
$swap->process('publish post 1', ['user_id' => 3, 'user_role' => 'author']);
check('resolveAgent override is honoured by process()', $swap->substitute->seenContexts !== []);
check('the substituted agent received the role',
    ($swap->substitute->seenContexts[0]['user_role'] ?? '') === 'author',
    json_encode($swap->substitute->seenContexts[0] ?? []));
check('getAgents still reports the real fleet',
    in_array('admin', (new Orchestrator())->getAgents(), true)
    && in_array('seo', (new Orchestrator())->getAgents(), true));

// Author cannot publish via the registry path the agent itself uses.
// Compared against the status the editor left behind, not against 'draft':
// the editor case above legitimately published this post, so asserting
// 'draft' here was checking a state that was never true at this point.
$beforeAuthorAttempt = $postStatus($draftId);
$agentErr = null;
try {
    $registry->execute(['action' => 'publish_post', 'params' => ['post_id' => $draftId]], 'author');
} catch (Throwable $e) {
    $agentErr = $e->getMessage();
}
check('author publish through the registry path is rejected', $agentErr !== null);
check('the refused author publish changed nothing',
    $postStatus($draftId) === $beforeAuthorAttempt,
    'before=' . var_export($beforeAuthorAttempt, true)
    . ' after=' . var_export($postStatus($draftId), true));

$registry->execute(['action' => 'publish_post', 'params' => ['post_id' => $draftId]], 'editor');
check('editor can publish the same post', $postStatus($draftId) === 'published');

// =========================================================================
echo "\n== 5. Sitemap is real, parseable, escaped XML ==\n";
// =========================================================================
$escapedSlug = runSlug('tom-jerry') . '&q=<b>bold</b>';
$draftSlug   = runSlug('unpublished');

$ampId = makePost($pdo, [
    'title'  => 'Tom & Jerry <script>alert(1)</script>',
    // A slug with markup in it: this is the only place a sitemap touches
    // post-derived text, so it is what must be XML-escaped.
    'slug'   => $escapedSlug,
    'status' => 'published',
    'excerpt'=> 'Tom & Jerry',
]);
$created['posts'][] = $ampId;
$pdo->prepare('UPDATE posts SET published_at = datetime(\'now\') WHERE id = ?')->execute([$ampId]);

$draftId2 = makePost($pdo, ['title' => 'Unpublished Draft', 'slug' => $draftSlug, 'status' => 'draft']);
$created['posts'][] = $draftId2;

$seo = new SeoAgent($db);
$xml = $seo->generateSitemap('https://example.com');

$prev = libxml_use_internal_errors(true);
$parsed = simplexml_load_string($xml);
libxml_use_internal_errors($prev);
check('sitemap is parseable XML', $parsed !== false);
// getDocNamespaces() returns [prefix => uri], so cast the URI, not the array.
check('sitemap declares the sitemaps.org namespace',
    $parsed !== false && str_contains(
        implode(' ', $parsed->getDocNamespaces()), 'http://www.sitemaps.org/schemas/sitemap/0.9'),
    $parsed === false ? 'unparseable' : json_encode($parsed->getDocNamespaces()));
check('sitemap contains the real published slug',
    $parsed !== false && str_contains($xml, htmlspecialchars($escapedSlug, ENT_XML1 | ENT_QUOTES)),
    'escaped slug not found: ' . $escapedSlug);
check('sitemap excludes unpublished posts', !str_contains($xml, $draftSlug));

$locNodes = $parsed === false ? [] : $parsed->xpath('//*[local-name()="loc"]');
$lastmodNodes = $parsed === false ? [] : $parsed->xpath('//*[local-name()="lastmod"]');
check('every entry has a <loc>', is_array($locNodes) && count($locNodes) === count($lastmodNodes) && count($locNodes) > 0,
    'loc=' . count((array) $locNodes) . ' lastmod=' . count((array) $lastmodNodes));
check('every <lastmod> is a bare date', is_array($lastmodNodes)
    && array_reduce($lastmodNodes, fn($c, $n) => $c && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $n) === 1, true));

check('XML escaping is applied to &', str_contains($xml, '&amp;'), 'no &amp; in output');
check('XML escaping is applied to <', str_contains($xml, '&lt;'), 'no &lt; in output');
check('no raw markup from post content leaks into the XML',
    !str_contains($xml, '<b>bold</b>') && !str_contains($xml, '<script>'), 'unescaped markup found in output');
// After parsing, the escaped entities are decoded back to the original
// characters — that round trip is the proof that escaping was applied and
// the value was not corrupted. The DB itself decodes the same way, so the
// query below is a second, independent witness.
$locs = array_map(fn($n) => (string) $n, (array) $locNodes);
$expectedLoc = 'https://example.com/' . $escapedSlug;
check('the parser saw the unescaped value, proving escaping is not corruption',
    count(array_filter($locs, fn($l) => str_contains($l, $expectedLoc))) === 1, json_encode($locs));
$parsedFromDb = scalar($pdo, 'SELECT slug FROM posts WHERE id = ?', [$ampId]);
check('escaping did not corrupt the stored slug',
    $parsedFromDb === $escapedSlug, (string) $parsedFromDb);

$trailing = $seo->generateSitemap('https://example.com/');
check('trailing slash on baseUrl is normalised', !str_contains($trailing, 'com//'));

// =========================================================================
echo "\n== 6. Scoring is deterministic and model-free ==\n";
// =========================================================================
// A post that clears every threshold: one H1, two H2s, 300+ words, an
// internal link, a 30-60 character title and a 70-160 character description.
$post = [
    'title'             => 'A Perfectly Reasonable Title For Search Engines',
    'excerpt'           => str_repeat('A decent meta description. ', 3),
    'body_md'           => "# Title\n\n## Section one\n\n" . str_repeat('word ', 320)
                          . "\n\n## Section two\n\n[internal](/posts/other-post)\n",
    'meta_description'  => '',
];
$first  = $seo->score($post);
$second = $seo->score($post);

check('score is an int', is_int($first['score']), gettype($first['score']));
check('score is within 0-100', $first['score'] >= 0 && $first['score'] <= 100, (string) $first['score']);
check('checks array is non-empty', is_array($first['checks']) && count($first['checks']) > 0);
check('every check has name/ok/detail',
    array_reduce($first['checks'], fn($c, $k) => $c && isset($k['name'], $k['ok']) && array_key_exists('detail', $k), true));
check('scoring is deterministic', $first === $second, json_encode([$first['score'], $second['score']]));
// The rubric is all-or-nothing per check (weights 20/25/20/20/15 = 100), so
// a post that clears every threshold scores exactly 100 and there is no
// drifting "high" band to tune.
check('scoring a good post scores 100', $first['score'] === 100, (string) $first['score']);

// An empty post has nothing at all — every signal scores zero.
$bad = $seo->score(['title' => '', 'excerpt' => '', 'body_md' => '']);
check('an empty post scores 0', $bad['score'] === 0, (string) $bad['score']);
check('an empty post still returns all five checks', count($bad['checks']) === 5);
check('no-LLM scoring never varies', $seo->score($post) === $first);

// Exactly one threshold met, four missed: the score must move off zero,
// stay well short of 100, and equal precisely the weight earned.
$partialPost = [
    'title'    => 'A Perfectly Reasonable Title For Search Engines',
    'excerpt'  => 'short',
    'body_md'  => str_repeat('word ', 60),
];
$partial = $seo->score($partialPost);
$passedWeights = array_sum(array_column(array_filter($partial['checks'], fn($k) => $k['ok']), 'weight'));
check('a partial post scores exactly the weights it earned',
    $partial['score'] === $passedWeights && $partial['score'] > 0 && $partial['score'] < 100,
    "score={$partial['score']} weights={$passedWeights} " . json_encode($partial['checks']));

// Real DB round-trip: audit() reads the actual posts row and persists via the registry.
$seedingPost = makePost($pdo, ['title' => 'Round Trip Post', 'status' => 'draft']);
$created['posts'][] = $seedingPost;
$audit = $seo->audit($seedingPost, new Context(userId: 1, userRole: 'editor'));
check('audit of a real post returns ok', ($audit['status'] ?? '') === 'ok', json_encode($audit));
check('audit returns a score and checks', isset($audit['score'], $audit['checks']) && is_int($audit['score']));
$storedScore = scalar($pdo, 'SELECT score FROM seo_meta WHERE entity_type = ? AND entity_id = ?', ['post', (string) $seedingPost]);
check('score was persisted to seo_meta',
    $storedScore !== false && $storedScore !== null && (int) $storedScore === $audit['score'],
    'stored=' . var_export($storedScore, true) . ' audit=' . $audit['score']);

$authorAudit = $seo->audit($seedingPost, new Context(userId: 3, userRole: 'author'));
check('an author cannot write seo_meta', ($authorAudit['status'] ?? '') === 'rejected', json_encode($authorAudit));

// =========================================================================
echo "\n== 7. No API key -> clean failure, never a fatal ==\n";
// =========================================================================
// Baseline counts: these tables are not exclusively this file's, so "wrote
// nothing" has to be measured against what was there before, not against 0.
$tokensBefore = (int) scalar($pdo, 'SELECT COUNT(*) FROM design_tokens');
$themesBefore = (int) scalar($pdo, 'SELECT COUNT(*) FROM themes');

// Every agent here is constructed through KeylessAgent, whose callLLM returns
// null — the exact state BaseAgent is in when config/api.php finds no api_key.
// Asserting against the ambient environment instead (as this file used to)
// made the whole section depend on whether the developer happened to have a
// key in .env, which is not a property of the code under test.
$seoKeyless     = fn() => new KeylessSeoAgent($db);
$designKeyless  = fn() => new KeylessDesignAgent($db);
$adminKeyless   = fn() => new KeylessAdminAgent($db);

$thrown = null;
$r = null;
try { $r = $seoKeyless()->execute('optimize post 1', ['user_id' => 1, 'user_role' => 'admin']); }
catch (Throwable $e) { $thrown = $e; }
check('SeoAgent without a key returns failed', is_array($r) && ($r['status'] ?? '') === 'failed', json_encode($r));
check('SeoAgent without a key carries an error', ($r['error'] ?? '') !== '');
check('SeoAgent without a key does NOT throw', $thrown === null, $thrown ? $thrown->getMessage() : '');

$thrown = null; $r = null;
try { $r = $seoKeyless()->execute('write me an article about SEO', []); }
catch (Throwable $e) { $thrown = $e; }
check('SeoAgent legacy path without a key returns failed',
    is_array($r) && ($r['status'] ?? '') === 'failed', json_encode($r));
check('SeoAgent legacy path does NOT throw', $thrown === null, $thrown ? $thrown->getMessage() : '');

$thrown = null; $r = null;
try { $r = $designKeyless()->execute('generate design tokens for a fintech brand', []); }
catch (Throwable $e) { $thrown = $e; }
check('DesignAgent without a key returns failed',
    is_array($r) && ($r['status'] ?? '') === 'failed', json_encode($r));
check('DesignAgent without a key does NOT throw', $thrown === null, $thrown ? $thrown->getMessage() : '');
check('DesignAgent without a key wrote no tokens',
    (int) scalar($pdo, 'SELECT COUNT(*) FROM design_tokens') === $tokensBefore);

$thrown = null; $r = null;
try { $r = $adminKeyless()->execute('publish post 1', ['user_role' => 'admin']); }
catch (Throwable $e) { $thrown = $e; }
check('AdminAgent without a key returns failed',
    is_array($r) && ($r['status'] ?? '') === 'failed', json_encode($r));
check('AdminAgent without a key does NOT throw', $thrown === null, $thrown ? $thrown->getMessage() : '');

// =========================================================================
echo "\n== 8. Prose instead of JSON is a clean failure ==\n";
// =========================================================================
$thrown = null; $r = null;
try {
    $r = (new StubDesignAgent("Sure! Here's a lovely palette for you.\nI think blue goes well.", $db))
        ->execute('generate design tokens for a fintech brand', ['user_role' => 'admin']);
} catch (Throwable $e) { $thrown = $e; }
check('prose from the model does NOT throw', $thrown === null, $thrown ? $thrown->getMessage() : '');
check('prose from the model returns failed', is_array($r) && ($r['status'] ?? '') === 'failed', json_encode($r));
check('prose failure names the problem',
    str_contains((string) ($r['error'] ?? ''), 'JSON'), (string) ($r['error'] ?? ''));
check('prose wrote no tokens and no theme',
    (int) scalar($pdo, 'SELECT COUNT(*) FROM design_tokens') === $tokensBefore
    && (int) scalar($pdo, 'SELECT COUNT(*) FROM themes') === $themesBefore);

// Partially-valid JSON is filtered, not trusted: bad colours are dropped,
// good ones are written, and nothing invalid reaches the table.
$partial = (new StubDesignAgent(json_encode([
    'name'   => 'Finch Bank',
    'slug'   => 'finch-bank',
    'colors' => ['primary' => '#1E88E5', 'secondary' => 'cornflower blue'],
    'space'  => ['md' => '8px'],
]), $db))->execute('generate design tokens', ['user_role' => 'admin']);

check('partial JSON still succeeds', ($partial['status'] ?? '') === 'ok', json_encode($partial));
$tokenCount = (int) scalar($pdo, 'SELECT COUNT(*) FROM design_tokens') - $tokensBefore;
check('only the valid tokens were written', $tokenCount === 2, "count={$tokenCount}");
$badToken = scalar($pdo, 'SELECT value FROM design_tokens WHERE key = ?', ['color.secondary']);
check('the invalid colour was dropped', $badToken === false, (string) $badToken);
$goodToken = row($pdo, 'SELECT value, category, css_var FROM design_tokens WHERE key = ?', ['color.primary']);
check('the valid colour was stored with its category and css var',
    is_array($goodToken) && $goodToken['value'] === '#1e88e5'
    && $goodToken['category'] === 'color' && $goodToken['css_var'] === '--color-primary',
    json_encode($goodToken));
check('the theme row was written',
    (int) scalar($pdo, 'SELECT COUNT(*) FROM themes WHERE slug = ?', ['finch-bank']) === 1);

$authorTokens = (new StubDesignAgent(json_encode([
    'colors' => ['primary' => '#000000'],
]), $db))->execute('generate design tokens', ['user_role' => 'author']);
check('an author cannot write design tokens', ($authorTokens['status'] ?? '') === 'rejected', json_encode($authorTokens));

$thrown = null; $r = null;
try {
    $r = (new StubSeoAgent('I would be happy to help with your SEO!', $db))
        ->optimize($seedingPost, new Context(userId: 1, userRole: 'admin'));
} catch (Throwable $e) { $thrown = $e; }
check('SeoAgent prose from the model does NOT throw', $thrown === null, $thrown ? $thrown->getMessage() : '');
check('SeoAgent prose returns failed', ($r['status'] ?? '') === 'failed', json_encode($r));

// Fenced JSON must still parse — models wrap output in ``` fences constantly.
$fenced = (new StubDesignAgent("Here you go:\n```json\n" . json_encode([
    'colors' => ['primary' => '#ABCDEF'],
]) . "\n```", $db))->execute('generate design tokens', ['user_role' => 'admin']);
check('fenced JSON is unwrapped and accepted', ($fenced['status'] ?? '') === 'ok', json_encode($fenced));

$opt = (new StubSeoAgent("```json\n" . json_encode([
    'meta_title'       => 'Optimised Title',
    'meta_description' => 'A tight, useful description of the post for search results.',
    'focus_keyword'    => 'testing',
]) . "\n```", $db))->optimize($seedingPost, new Context(userId: 1, userRole: 'admin'));
check('SeoAgent accepts fenced JSON', ($opt['status'] ?? '') === 'ok', json_encode($opt));
$stored = row($pdo, 'SELECT meta_title, focus_keyword FROM seo_meta WHERE entity_type = ? AND entity_id = ?',
    ['post', (string) $seedingPost]);
check('the suggestion was persisted as a bound parameter',
    is_array($stored) && $stored['meta_title'] === 'Optimised Title' && $stored['focus_keyword'] === 'testing',
    json_encode($stored));

// =========================================================================
echo "\n== 9. enqueueJob() is idempotent ==\n";
// =========================================================================
$enqueuer = new class extends \CMS\Agents\BaseAgent {
    public function execute(string $task, array $context = []): array { return []; }
    public function push(string $type, array $payload, int $delay = 0, ?string $key = null): int
    {
        return $this->enqueueJob($type, $payload, $delay, $key);
    }
};
$key = 'agents-test-' . bin2hex(random_bytes(6));
$jobsCount = fn(): int => (int) scalar($pdo, 'SELECT COUNT(*) FROM jobs');
$jobsBefore = $jobsCount();
$firstId  = $enqueuer->push('generate_daily_post', ['topic' => 'x'], 0, $key);
$secondId = $enqueuer->push('generate_daily_post', ['topic' => 'x'], 0, $key);
$thirdId  = $enqueuer->push('generate_daily_post', ['topic' => 'y'], 0, $key);

$rowsForKey = (int) scalar($pdo, 'SELECT COUNT(*) FROM jobs WHERE idempotency_key = ?', [$key]);
check('repeated idempotency_key enqueues exactly one row', $rowsForKey === 1, "rows={$rowsForKey}");
check('three pushes created exactly one row in total', $jobsCount() === $jobsBefore + 1,
    "before={$jobsBefore} after=" . $jobsCount());
check('the duplicate insert returns the original job id', $firstId === $secondId && $firstId === $thirdId,
    "ids: {$firstId}, {$secondId}, {$thirdId}");
check('the returned job id really is that job',
    (int) scalar($pdo, 'SELECT id FROM jobs WHERE id = ?', [$firstId]) === $firstId);

// A different key must NOT collide.
$enqueuer->push('generate_daily_post', ['topic' => 'z'], 0, $key . '-b');
$rowsForKeyB = (int) scalar($pdo, 'SELECT COUNT(*) FROM jobs WHERE idempotency_key = ?', [$key . '-b']);
check('a different idempotency_key enqueues its own row', $rowsForKeyB === 1, "rows={$rowsForKeyB}");

// A null key must not collide with itself either.
$nullA = $enqueuer->push('generate_daily_post', ['topic' => 'n']);
$nullB = $enqueuer->push('generate_daily_post', ['topic' => 'n']);
$nullRows = $jobsCount();
check('a null idempotency_key still enqueues every time',
    $nullRows === $jobsBefore + 4 && $nullA !== $nullB, "ids: {$nullA}, {$nullB} rows={$nullRows}");

$delayed = $enqueuer->push('generate_daily_post', ['topic' => 'later'], 3600, null);
$runAfter = scalar($pdo, 'SELECT run_after FROM jobs WHERE id = ?', [(int) $delayed]);
$inTenMinutes = scalar($pdo, "SELECT datetime('now', '+10 minutes')");
check('a delayed job runs in the future (UTC, like SQLite)', (string) $runAfter > (string) $inTenMinutes,
    "run_after={$runAfter} now+10m={$inTenMinutes}");
// Rows this section created, by id: ids rather than slugs because the
// null-key pushes (topic n / topic later) have no key to look up.
foreach ([$firstId, $secondId, $thirdId, $nullA, $nullB, $delayed] as $pushedId) {
    $created['jobs'][] = $pushedId;
}
$created['jobs'][] = (int) scalar($pdo, 'SELECT id FROM jobs WHERE idempotency_key = ?', [$key . '-b']);

// =========================================================================
echo "\n== 10. Job type registry ==\n";
// =========================================================================
$handlers = JobTypes::handlers($db);
foreach (['generate_daily_post', 'seo.persist', 'seo_score', 'design_tokens', 'generate_sitemap'] as $type) {
    check("job type '{$type}' is dispatchable", isset($handlers[$type]) && is_callable($handlers[$type]));
}
check('JobTypes reports the same type list', JobTypes::types() === array_keys($handlers));
check('unknown job types are reported, not thrown',
    JobTypes::supports('nope') === false
    && (JobTypes::validatePayload('nope', [])['ok'] ?? true) === false);

$bad = $handlers['seo_score']([], new Context(userRole: 'admin'));
check('a malformed payload fails cleanly',
    ($bad['status'] ?? '') === 'failed' && str_contains((string) $bad['error'], 'post_id'), json_encode($bad));

$scored = $handlers['seo_score'](['post_id' => $seedingPost], new Context(userRole: 'editor'));
check('the seo_score handler runs end to end',
    ($scored['status'] ?? '') === 'ok' && isset($scored['score']), json_encode($scored));

$persisted = $handlers['seo.persist']([
    'entity_type' => 'post',
    'entity_id'   => $seedingPost,
    'seo'         => ['meta_title' => 'From Job', 'keywords' => ['jobs', 'queue']],
], new Context(userRole: 'editor'));
check('the seo.persist handler runs end to end', ($persisted['status'] ?? '') === 'ok', json_encode($persisted));
$fromJob = scalar($pdo, 'SELECT meta_title FROM seo_meta WHERE entity_type = ? AND entity_id = ?',
    ['post', (string) $seedingPost]);
check('seo.persist wrote the meta title', $fromJob === 'From Job', (string) $fromJob);

$authorPersist = $handlers['seo.persist']([
    'entity_type' => 'post', 'entity_id' => $seedingPost, 'seo' => ['meta_title' => 'Nope'],
], new Context(userRole: 'author'));
check('the seo.persist handler respects the role',
    ($authorPersist['status'] ?? '') === 'rejected', json_encode($authorPersist));

$missingUrl = $handlers['generate_sitemap']([], new Context());
check('generate_sitemap without a base_url fails cleanly',
    ($missingUrl['status'] ?? '') === 'failed', json_encode($missingUrl));

// =========================================================================
echo "\n== 11. Intent encoding matches the registry wire format ==\n";
// =========================================================================
$encoder = new IntentEncoder($registry);
$json = $encoder->encode(new ActionRequest('publish_post', ['post_id' => $postId]));
check('encoder produces compact action/arguments JSON',
    $json === '{"action":"publish_post","params":{"post_id":' . $postId . '}}', $json);
check('encoded intent decodes back identically',
    $encoder->decode($json) === ['action' => 'publish_post', 'params' => ['post_id' => $postId]]);
check('decoder tolerates markdown fences',
    $encoder->decode("```json\n{$json}\n```") === ['action' => 'publish_post', 'params' => ['post_id' => $postId]]);
// Drive it through the registry for real — the proof that the encoder emits
// the shape IntentRegistry::decode() produces. A publishable post, because
// publish_post reports 'not_publishable' rather than 'published' otherwise.
$encodableId = makePost($pdo, ['title' => 'Encoder Round Trip', 'status' => 'draft']);
$created['posts'][] = $encodableId;
$encodedIntent = $encoder->decode($encoder->encode(new ActionRequest('publish_post', ['post_id' => $encodableId])));
check('encoded intent is executable by the registry',
    ($registry->execute($encodedIntent, 'editor')['published'] ?? false) === true, json_encode($encodedIntent));

check('encoder refuses an unknown action',
    $encoder->tryEncode(new ActionRequest('rm_rf', [])) === null);

// =========================================================================
echo "\n== 12. Token validation drops what it cannot store ==\n";
// =========================================================================
$norm = Tokens::normalize([
    'name'   => 'Test',
    'slug'   => 'Test Theme',
    'colors' => ['primary' => '#AABBCC', 'junk' => 'not-a-color'],
    'space'  => ['md' => '8px', 'weird' => 'eight pixels'],
    'radius' => ['lg' => '12'],
    'fonts'  => ['heading' => "Inter\nSans"],
    'badly'  => ['x' => 'y'],
]);
check('slug is kebab-cased', $norm['slug'] === 'test-theme', $norm['slug']);
check('valid colour kept', in_array('color.primary', array_column($norm['tokens'], 'key'), true));
check('invalid colour dropped', !in_array('color.junk', array_column($norm['tokens'], 'key'), true));
check('valid spacing kept', in_array('space.md', array_column($norm['tokens'], 'key'), true));
check('non-CSS-length spacing dropped', !in_array('space.weird', array_column($norm['tokens'], 'key'), true));
check('unitless radius dropped', !in_array('radius.lg', array_column($norm['tokens'], 'key'), true));
check('rejections are reported', count($norm['rejected']) >= 3, json_encode($norm['rejected']));
check('Tokens::normalize([]) yields nothing to store',
    Tokens::normalize(['note' => 'just prose'])['tokens'] === []);

// =========================================================================
echo "\n== Cleanup ==\n";
// =========================================================================
foreach ($created['posts'] as $id) {
    $pdo->prepare('DELETE FROM seo_meta WHERE entity_type = ? AND entity_id = ?')->execute(['post', (string) $id]);
}

// The id-addressed deletes below come first, then the pattern sweep.
//
// Two steps, and the order matters. Only five fixtures were ever tracked by
// id, and every other row this file creates is reachable only by its slug
// pattern — so the id deletes alone left ~11 rows behind on every run, and
// the leftover assertion below then failed forever on its own debris. The
// sweep is idempotent and pattern-scoped, so a run that dies mid-file still
// gets cleaned by the next one.
//
// The patterns are the same three the assertion uses, which is what makes the
// assertion mean what it says: "this file left nothing behind", not "nothing
// that looks like mine exists".
foreach ($created['posts'] as $id) {
    $pdo->prepare('DELETE FROM posts WHERE id = ?')->execute([$id]);
}
$pdo->exec("DELETE FROM posts WHERE uuid = 'injection-canary-uuid'");
$pdo->exec("DELETE FROM posts
            WHERE slug LIKE 'agent-test-%'
               OR slug LIKE 'agents-test-%'
               OR slug = 'tom-jerry-and-friends'
               OR slug = 'unpublished-draft'
               OR slug LIKE 'tom-jerry&q=%'");
$pdo->prepare('DELETE FROM design_tokens WHERE key IN (?, ?, ?)')
    ->execute(['color.primary', 'color.secondary', 'space.md']);
$pdo->exec("DELETE FROM themes WHERE slug = 'finch-bank'");
foreach ($created['jobs'] as $pushedId) {
    $pdo->prepare('DELETE FROM jobs WHERE id = ?')->execute([(int) $pushedId]);
}
$pdo->prepare("DELETE FROM seo_meta WHERE entity_type = 'post' AND meta_title IN (?, ?)")
    ->execute(['From Job', 'Optimised Title']);

// Leftovers are measured against the baselines captured before the file ran:
// 'did this test change the world' is the question, not 'is the world empty'.
$leftover = (int) scalar(
    $pdo,
    "SELECT COUNT(*) FROM posts WHERE uuid = 'injection-canary-uuid'
     OR slug LIKE 'agent-test-%' OR slug LIKE 'agents-test-%'
     OR slug = 'tom-jerry-and-friends' OR slug = 'unpublished-draft'
     OR slug LIKE 'tom-jerry&q=%'"
);
check('no test posts left behind', $leftover === 0, "left={$leftover}");
$leftoverJobs = (int) scalar($pdo, 'SELECT COUNT(*) FROM jobs');
check('no test jobs left behind', $leftoverJobs === $jobsBefore, "left={$leftoverJobs} before={$jobsBefore}");
$leftoverTokens = (int) scalar($pdo, 'SELECT COUNT(*) FROM design_tokens');
check('no test design tokens left behind', $leftoverTokens === $tokensBefore,
    "left={$leftoverTokens} before={$tokensBefore}");
echo "  cleaned\n";

echo "\n" . str_repeat('=', 46) . "\n";
echo "  PASSED: {$pass}   FAILED: {$fail}\n";
echo str_repeat('=', 46) . "\n";
exit($fail === 0 ? 0 : 1);