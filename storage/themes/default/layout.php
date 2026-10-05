<?php
/** @var \CMS\Render\TemplateEngine $this */
/** @var string $content */
/** @var array $site */
/** @var string $title */
/** @var string $bodyClass */

$site     = $site ?? [];
$title    = $title ?? ($site['title'] ?? 'Untitled Site');
$tagline  = $site['description'] ?? '';
$bodyClass = $bodyClass ?? '';

$tokens = json_decode((string) file_get_contents(__DIR__ . '/theme.json'), true)['tokens'] ?? [];

// Resolve the theme's own tokens together with any per-site design_tokens rows
// (AI edits) into the ten custom properties assets/css/site.css actually reads.
// Previously this hand-wrote all ten inline, so a token written by DesignAgent
// landed in the database and changed nothing on screen.
$designVars = \CMS\Render\DesignVars::toStyleAttribute($tokens);

// A post sets its own meta description; fall back to the site tagline.
$metaDesc = $metaDescription ?? $tagline;
?>
<!doctype html>
<html lang="<?= $this->e($lang ?? 'en') ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $this->e($title) ?><?= $title === ($site['title'] ?? '') ? '' : ' — ' . $this->e($site['title'] ?? '') ?></title>
<?php if ($metaDesc !== ''): ?>
<meta name="description" content="<?= $this->e($metaDesc) ?>">
<?php endif; ?>
<?php if (!empty($canonical)): ?>
<link rel="canonical" href="<?= $this->e($canonical) ?>">
<?php endif; ?>
<meta property="og:type" content="<?= $this->e($ogType ?? 'website') ?>">
<meta property="og:title" content="<?= $this->e($title) ?>">
<?php if ($metaDesc !== ''): ?>
<meta property="og:description" content="<?= $this->e($metaDesc) ?>">
<?php endif; ?>
<?php if (!empty($ogImage)): ?>
<meta property="og:image" content="<?= $this->e($ogImage) ?>">
<?php endif; ?>
<?php if (!empty($jsonLd)): ?>
<script type="application/ld+json"><?= json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
<?php endif; ?>
<link rel="alternate" type="application/rss+xml" title="<?= $this->e($site['title'] ?? '') ?>" href="<?= $this->url('feed.xml') ?>">
<link rel="stylesheet" href="<?= $this->url('assets/css/site.css') ?>">
</head>
<body class="<?= $this->e($bodyClass) ?>" style="<?= $this->e($designVars) ?>">

<a class="skip-link" href="#main">Skip to content</a>

<header class="site-header">
  <div class="wrap">
    <a class="site-title" href="<?= $this->url('') ?>"><?= $this->e($site['title'] ?? 'Untitled Site') ?></a>
    <nav aria-label="Primary">
      <a href="<?= $this->url('') ?>">Home</a>
      <?php foreach (($navItems ?? []) as $item): ?>
        <a href="<?= $this->e($item['url'] ?? '#') ?>"><?= $this->e($item['label'] ?? '') ?></a>
      <?php endforeach; ?>
      <a href="<?= $this->url('feed.xml') ?>">RSS</a>
    </nav>
  </div>
</header>

<main id="main" class="wrap">
<?= $content ?>
</main>

<footer class="site-footer">
  <div class="wrap">
    <p>&copy; <?= date('Y') ?> <?= $this->e($site['title'] ?? '') ?><?= $tagline !== '' ? ' — ' . $this->e($tagline) : '' ?></p>
  </div>
</footer>
</body>
</html>