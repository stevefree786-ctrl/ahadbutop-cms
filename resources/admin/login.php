<?php
/**
 * Login screen. Rendered bare — no admin layout, no sidebar, no navigation,
 * because the visitor here is not signed in and every nav link would 401.
 */
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Sign in · <?= e($site_title ?? 'CMS') ?></title>
<link rel="stylesheet" href="/assets/admin/admin.css">
<style>
  .auth-wrap {
    min-height: 100vh; display: grid; place-items: center; padding: 24px;
  }
  .auth-card {
    width: 100%; max-width: 360px; background: var(--surface);
    border: 1px solid var(--border); border-radius: var(--radius);
    box-shadow: var(--shadow); padding: 28px;
  }
  .auth-card h1 { font-size: 19px; margin-bottom: 4px; }
  .auth-card .sub { color: var(--muted); font-size: 13px; margin-bottom: 20px; }
  .auth-error {
    background: var(--bad-bg); color: var(--bad);
    border-radius: var(--radius-sm); padding: 9px 12px;
    font-size: 13px; margin-bottom: 14px;
  }
  .auth-foot { margin-top: 18px; text-align: center; font-size: 12px; }
</style>
</head>
<body>
<div class="auth-wrap">
  <main class="auth-card">
    <h1><?= e($site_title ?? 'CMS') ?></h1>
    <p class="sub">Sign in to the admin.</p>

    <form id="login-form" autocomplete="on">
      <div id="login-error" class="auth-error" role="alert" hidden></div>

      <!--
        Where to land after signing in. Set by AuthMiddleware when it
        redirected here, and validated on the server before it ever reaches
        this file — an unchecked value would make /login an open redirect.
      -->
      <input type="hidden" id="next-target" name="next"
             value="<?= e($next ?? '/admin') ?>">

      <label class="field">
        <span class="label">Email</span>
        <input type="email" name="email" required autocomplete="username"
               autofocus placeholder="you@example.com">
      </label>

      <label class="field">
        <span class="label">Password</span>
        <input type="password" name="password" required autocomplete="current-password">
      </label>

      <button class="btn btn-primary btn-block" type="submit">Sign in</button>
    </form>

    <p class="auth-foot muted"><a href="/">← Back to the site</a></p>
  </main>
</div>
<script src="/assets/admin/admin.js" defer></script>
</body>
</html>