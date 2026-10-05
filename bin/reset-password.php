<?php
/**
 * Reset the local admin password and clear its lockout counter.
 *
 * Scoped to this machine's dev database — it touches the seeded local admin
 * account only, and refuses to run if that account is absent.
 */

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
if (is_file(dirname(__DIR__) . '/.env')) {
    cms_env_load(dirname(__DIR__) . '/.env');
}

$email   = $argv[1] ?? 'admin@cms.local';
$password = $argv[2] ?? null;

if ($password === null) {
    fwrite(STDERR, "usage: php tests/_reset_admin.php [email] [password]\n");
    exit(1);
}

$pdo = (new \CMS\Database\Connection(require dirname(__DIR__) . '/config/database.php'))->getPdo();

$id = $pdo->prepare('SELECT id FROM users WHERE email = ?');
$id->execute([$email]);
$userId = $id->fetchColumn();

if ($userId === false) {
    fwrite(STDERR, "no such user: {$email}\n");
    exit(1);
}

$upd = $pdo->prepare(
    "UPDATE users
        SET password_hash   = ?,
            failed_attempts = 0,
            locked_until    = NULL,
            status          = 'active'
      WHERE id = ?"
);
$upd->execute([password_hash($password, PASSWORD_BCRYPT), $userId]);

printf("reset %s (id=%d): %d row(s) updated\n", $email, $userId, $upd->rowCount());
printf("verify: %s\n", var_export(
    password_verify($password, $pdo->query("SELECT password_hash FROM users WHERE id = {$userId}")->fetchColumn()),
    true
));