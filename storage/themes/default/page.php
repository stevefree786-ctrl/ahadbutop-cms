<?php /** @var \CMS\Render\TemplateEngine $this */ ?>
<article class="post">
  <header class="post-header">
    <h1><?= $this->e($post['title']) ?></h1>
  </header>

  <?php if (!empty($post['featured_image_url'])): ?>
    <figure>
      <img src="<?= $this->e($post['featured_image_url']) ?>"
           alt="<?= $this->e($post['featured_alt'] ?? '') ?>" loading="eager">
    </figure>
  <?php endif; ?>

  <div class="post-body">
    <?= $post['body_html'] ?? ($post['body_md'] ?? '' ? $this->md($post['body_md']) : '') ?>
  </div>
</article>
