<?php
/**
 * Remove leftover test fixtures from the dev database.
 *
 * Scoped to fixture naming (example.test emails, skills-fixture/bisect slugs,
 * byok-scoped settings), so it cannot touch real content.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
if (is_file(dirname(__DIR__) . '/.env')) {
    cms_env_load(dirname(__DIR__) . '/.env');
}

$pdo = (new \CMS\Database\Connection(require dirname(__DIR__) . '/config/database.php'))->getPdo();

$patterns = [
    'users' => ["email LIKE '%@example.test'"],
    'posts' => ["slug LIKE 'skills-fixture-%'", "slug LIKE 'bisect-fixture-%'"],
];

foreach ($patterns['users'] as $w) {
    $ids = $pdo->query("SELECT id FROM users WHERE {$w}")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
        $pdo->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM posts WHERE author_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
    }
    printf("users matching %-32s removed: %d\n", $w, count($ids));
}

foreach ($patterns['posts'] as $w) {
    $ids = $pdo->query("SELECT id FROM posts WHERE {$w}")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
        $pdo->prepare('DELETE FROM seo_meta WHERE entity_type = ? AND entity_id = ?')->execute(['post', $id]);
        $pdo->prepare('DELETE FROM posts WHERE id = ?')->execute([$id]);
    }
    printf("posts matching %-32s removed: %d\n", $w, count($ids));
}

$n = $pdo->query("DELETE FROM settings WHERE scope = 'byok'")->rowCount();
printf("byok settings rows removed: %d\n", $n);

echo "\n--- remaining users ---\n";
foreach ($pdo->query('SELECT id, email, role FROM users ORDER BY id')->fetchAll() as $u) {
    printf("  id=%-4s %-26s %s\n", $u['id'], $u['email'], $u['role']);
}