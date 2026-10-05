<?php
/**
 * Admin layout.
 *
 * Server-rendered, no framework. The one bit of JavaScript is the API client
 * (admin.js); everything else is plain forms and links.
 *
 * @var string      $content   rendered screen
 * @var string      $page_title
 * @var string      $site_title
 * @var array       $nav       already filtered by the viewer's role
 * @var array       $user      signed-in user
 * @var string      $role
 * @var string      $csrf      token the JS sends back on every write
 */

$nav      = $nav ?? [];
$user     = $user ?? [];
$siteTitle = $site_title ?? 'CMS';
$pageTitle = $page_title ?? 'Dashboard';
$csrf     = $csrf ?? '';

// Which nav entry is current, so the item can be marked for screen readers.
$current = strtok($_SERVER['REQUEST_URI'] ?? '/admin', '?');
$current = rtrim($current, '/') ?: '/admin';

$e = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= $e($pageTitle) ?> · <?= $e($siteTitle) ?> Admin</title>
<link rel="stylesheet" href="/assets/admin/admin.css">
</head>
<body data-role="<?= $e($role) ?>" data-user="<?= $e($user['email'] ?? '') ?>">

<a class="skip" href="#main">Skip to content</a>

<div class="shell">

  <aside class="sidebar">
    <a class="brand" href="/admin">
      <span class="brand-mark" aria-hidden="true">◧</span>
      <span class="brand-text">
        <strong><?= $e($siteTitle) ?></strong>
        <small>Admin</small>
      </span>
    </a>

    <nav class="nav" aria-label="Admin sections">
      <?php foreach ($nav as $item):
        $isCurrent = rtrim($item['url'], '/') === $current;
      ?>
        <a href="<?= $e($item['url']) ?>" <?= $isCurrent ? 'aria-current="page"' : '' ?>>
          <?= $e($item['label']) ?>
        </a>
      <?php endforeach; ?>
    </nav>

    <div class="sidebar-foot">
      <a class="view-site" href="/" target="_blank" rel="noopener">View site ↗</a>
      <div class="whoami">
        <span class="avatar" aria-hidden="true"><?= $e(strtoupper(substr((string) ($user['display_name'] ?: $user['email'] ?? '?'), 0, 1))) ?></span>
        <span class="whoami-text">
          <strong><?= $e($user['display_name'] ?? $user['username'] ?? '') ?></strong>
          <small><?= $e($role) ?></small>
        </span>
      </div>
      <button type="button" class="btn btn-ghost btn-block" id="logout">Sign out</button>
    </div>
  </aside>

  <main id="main" class="main">
    <header class="topbar">
      <h1><?= $e($pageTitle) ?></h1>
      <div class="topbar-actions" id="flash" role="status" aria-live="polite"></div>
    </header>

    <?= $content ?>
  </main>

</div>

<div class="drawer" id="drawer" hidden>
  <div class="drawer-panel" role="dialog" aria-modal="true" aria-labelledby="drawer-title">
    <header class="drawer-head">
      <h2 id="drawer-title">Detail</h2>
      <button type="button" class="btn btn-icon" id="drawer-close" aria-label="Close">✕</button>
    </header>
    <div class="drawer-body" id="drawer-body"></div>
  </div>
</div>

<script>window.CSRF = <?= json_encode($csrf) ?>;</script>
<script src="/assets/admin/admin.js" defer></script>
</body>
</html>