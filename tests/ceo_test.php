<?php
/**
 * CeoAgent delegation tests.
 *
 * The thing under test is not "can the CEO plan well" — that needs a model
 * and a judgement call. It is "does composing many intents into one request
 * quietly widen what the caller may do". Everything below attacks that.
 */
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

if (is_file(dirname(__DIR__) . '/.env')) {
    Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
}

use CMS\Agents\Actions;
use CMS\Agents\CeoAgent;
use CMS\Agents\Context;
use CMS\Agents\IntentRegistry;

$db   = new \CMS\Database\Connection(require __DIR__ . '/../config/database.php');
$pdo  = $db->getPdo();

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

// Fixtures -------------------------------------------------------------
// create_page makes status='draft' and origin='ai', so every fixture here is
// found by its ceo-test- prefix and needs no id bookkeeping.
$pdo->exec("DELETE FROM redirects WHERE source_path LIKE '/page/ceo-test-%'");
$pdo->exec("DELETE FROM posts WHERE slug LIKE 'ceo-test-%'");

$mk = static function (string $title, string $slug) use ($pdo): int {
    $pdo->prepare('INSERT INTO posts (uuid, slug, type, title, body_md, status, origin, menu_order, created_at, updated_at)
                   VALUES (:u, :s, :t, :ti, :b, :st, :o, 0, datetime(\'now\'), datetime(\'now\'))')
        ->execute([
            'u'  => bin2hex(random_bytes(16)),
            's'  => $slug,
            't'  => 'page',
            'ti' => $title,
            'b'  => 'Body.',
            'st' => 'published',
            'o'  => 'manual',
        ]);
    return (int) $pdo->lastInsertId();
};

$existingId = $mk('Existing', 'ceo-test-existing');
$otherId    = $mk('Other', 'ceo-test-other');

$built = Actions::build($db);
$ceo   = new CeoAgent($db);

$adminCtx   = Context::fromArray(['user_role' => 'admin',   'user_name' => 'a']);
$editorCtx  = Context::fromArray(['user_role' => 'editor',  'user_name' => 'e']);
$authorCtx  = Context::fromArray(['user_role' => 'author',  'user_name' => 'u']);

echo "\n--- 1. A denied step does not grant itself ---\n";

$run = $ceo->runPlan([
    ['specialist' => 'AdminAgent', 'intent' => 'create_page',
     'params' => ['title' => 'Allowed Page', 'body_md' => 'Fine.']],
    ['specialist' => 'AdminAgent', 'intent' => 'delete_page',
     'params' => ['post_id' => $existingId]],
], $editorCtx);

check('editor: create_page succeeds', ($run['steps'][0]['status'] ?? '') === 'ok',
    'got ' . ($run['steps'][0]['status'] ?? '?') . ' ' . json_encode($run['steps'][0]['result'] ?? $run['steps'][0]['error'] ?? null));
check('editor: delete_page denied', ($run['steps'][1]['status'] ?? '') === 'denied',
    'got ' . ($run['steps'][1]['status'] ?? '?'));
check('editor: target page survives', (int) $pdo->query("SELECT COUNT(*) FROM posts WHERE id = {$existingId}")->fetchColumn() === 1);
check('editor: counts are 1 done / 1 failed', $run['done'] === 1 && $run['failed'] === 1,
    "done={$run['done']} failed={$run['failed']}");

// The same plan as admin: the identical step that was denied above now runs.
// If this does not flip, the denial in the previous block came from the
// plan's contents rather than the role -- i.e. the test is measuring the
// wrong thing.
$runAdmin = $ceo->runPlan([
    ['specialist' => 'AdminAgent', 'intent' => 'delete_page',
     'params' => ['post_id' => $otherId]],
], $adminCtx);
check('admin: same delete_page succeeds', ($runAdmin['steps'][0]['status'] ?? '') === 'ok',
    'got ' . ($runAdmin['steps'][0]['status'] ?? '?') . ' ' . json_encode($runAdmin['steps'][0]['result'] ?? $runAdmin['steps'][0]['error'] ?? null));
check('admin: page actually trashed', (string) $pdo->query("SELECT status FROM posts WHERE id = {$otherId}")->fetchColumn() === 'trash');
$pdo->exec("DELETE FROM posts WHERE id = {$otherId}");

echo "\n--- 2. Author gets nothing an author lacks ---\n";

$runAuthor = $ceo->runPlan([
    ['specialist' => 'BlogAgent', 'intent' => 'create_page',
     'params' => ['title' => 'Author Page', 'body_md' => 'Nope.']],
], $authorCtx);
check('author: create_page denied', ($runAuthor['steps'][0]['status'] ?? '') === 'denied',
    'got ' . ($runAuthor['steps'][0]['status'] ?? '?'));
check('author: no page created', (int) $pdo->query("SELECT COUNT(*) FROM posts WHERE title = 'Author Page'")->fetchColumn() === 0);

echo "\n--- 3. The model cannot escape the registry ---\n";

$runEscape = $ceo->runPlan([
    ['specialist' => '../../System', 'intent' => 'create_page',
     'params' => ['title' => 'Escaped', 'body_md' => 'x']],
    ['specialist' => 'AdminAgent', 'intent' => 'DROP TABLE posts',
     'params' => []],
    ['specialist' => 'AdminAgent', 'intent' => 'create_page',
     'params' => ['title' => 'Smuggled', 'body_md' => 'x', 'admin' => true]],
], $editorCtx);

// A hostile specialist name must resolve to a real, known agent -- not throw,
// and not be passed through as an arbitrary class name.
check('hostile specialist name resolves to a known agent',
    $ceo->resolveSpecialist('../../System') === 'AdminAgent',
    'got ' . $ceo->resolveSpecialist('../../System'));
check('raw SQL as an intent is rejected', ($runEscape['steps'][1]['status'] ?? '') === 'rejected',
    'got ' . ($runEscape['steps'][1]['status'] ?? '?'));
check('smuggled extra param is rejected', ($runEscape['steps'][2]['status'] ?? '') === 'rejected',
    'got ' . ($runEscape['steps'][2]['status'] ?? '?'));
check('posts table intact after DROP attempt',
    (int) $pdo->query("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='posts'")->fetchColumn() === 1);
check('nothing named Smuggled was written',
    (int) $pdo->query("SELECT COUNT(*) FROM posts WHERE title = 'Smuggled'")->fetchColumn() === 0);

echo "\n--- 4. Specialist resolution ---\n";

foreach ([
    'BlogAgent'    => 'BlogAgent',
    'blog'         => 'BlogAgent',
    'Design'       => 'DesignAgent',
    'design agent' => 'DesignAgent',
    'seo'          => 'SeoAgent',
    ''             => 'AdminAgent',
    'OS'           => 'AdminAgent',
    '\\\\..\\\\etc' => 'AdminAgent',
] as $input => $expected) {
    check("resolveSpecialist({$input}) => {$expected}", $ceo->resolveSpecialist((string) $input) === $expected,
        'got ' . $ceo->resolveSpecialist((string) $input));
}

echo "\n--- 5. A handler error is not a success ---\n";

$runErr = $ceo->runPlan([
    ['specialist' => 'AdminAgent', 'intent' => 'rename_page',
     'params' => ['post_id' => 999999, 'new_slug' => 'nope']],
], $adminCtx);
check('renaming a missing post is counted as failed',
    ($runErr['steps'][0]['status'] ?? '') === 'failed' && $runErr['failed'] === 1,
    'got ' . ($runErr['steps'][0]['status'] ?? '?') . ' failed=' . $runErr['failed']);

echo "\n--- 6. A whole plan can fail without throwing ---\n";

$runAll = $ceo->runPlan([
    ['specialist' => 'AdminAgent', 'intent' => 'nonsense_intent', 'params' => []],
    ['specialist' => 'AdminAgent', 'intent' => 'delete_page', 'params' => ['post_id' => $existingId]],
], $authorCtx);
check('author: both steps denied, no exception', $runAll['failed'] === 2 && $runAll['done'] === 0,
    json_encode([$runAll['done'], $runAll['failed']]));
check('author: summary names the refusals', str_contains(strtolower($runAll['summary']), 'not permitted'),
    $runAll['summary']);

echo "\n--- 7. The action spec is the real registry ---\n";

$spec = $ceo->actionSpec();
$schema = $built['schema'];
$missing = [];
foreach (array_keys($schema) as $action) {
    if (!str_contains($spec, (string) $action)) {
        $missing[] = $action;
    }
}
check('every registered intent appears in the spec', $missing === [],
    'missing: ' . implode(', ', $missing));
check('spec advertises role floors', str_contains($spec, '[min role: admin]'),
    'no admin floor rendered');
check('Part 2 intents are plannable',
    str_contains($spec, 'rename_page') && str_contains($spec, 'apply_design_token') && str_contains($spec, 'activate_theme'));

// Cleanup -------------------------------------------------------------
$pdo->exec("DELETE FROM redirects WHERE source_path LIKE '/page/ceo-test-%'");
$pdo->exec("DELETE FROM posts WHERE slug LIKE 'ceo-test-%' OR title IN ('Allowed Page','Author Page','Smuggled','Escaped')");

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);