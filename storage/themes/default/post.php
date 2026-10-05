<?php /** @var \CMS\Render\TemplateEngine $this */ ?>
<article class="post h-entry">
  <header class="post-header">
    <h1 class="p-name"><?= $this->e($post['title']) ?></h1>

    <p class="meta">
      <time class="dt-published" datetime="<?= $this->e($this->date($post['published_at'], 'c')) ?>">
        <?= $this->e($this->date($post['published_at'])) ?>
      </time>
      <?php if (!empty($post['author_name'])): ?>
        <span>· <?= $this->e($post['author_name']) ?></span>
      <?php endif; ?>
      <?php if (!empty($post['reading_time'])): ?>
        <span>· <?= (int) $post['reading_time'] ?> min read</span>
      <?php endif; ?>
    </p>

    <?php if (!empty($post['tags'])): ?>
      <p class="tags">
        <?php foreach ($post['tags'] as $tag): ?>
          <a class="p-category" href="<?= $this->url('tag/' . $tag['slug']) ?>"><?= $this->e($tag['name']) ?></a>
        <?php endforeach; ?>
      </p>
    <?php endif; ?>
  </header>

  <?php if (!empty($post['featured_image_url'])): ?>
    <figure>
      <img src="<?= $this->e($post['featured_image_url']) ?>"
           alt="<?= $this->e($post['featured_alt'] ?? '') ?>" loading="eager">
      <?php if (!empty($post['featured_caption'])): ?>
        <figcaption><?= $this->e($post['featured_caption']) ?></figcaption>
      <?php endif; ?>
    </figure>
  <?php endif; ?>

  <?php
  // body_html is the cached render of body_md and is already escaped by
  // Markdown::render() with raw HTML disabled — echo it unescaped.
  $bodyHtml = $post['body_html'] ?? '';
  if ($bodyHtml === '' && !empty($post['body_md'])) {
      $bodyHtml = $this->md($post['body_md']);
  }
  ?>
  <div class="post-body e-content">
    <?= $bodyHtml ?>
  </div>

  <footer class="post-footer">
    <a href="<?= $this->url('') ?>">&larr; Back</a>
  </footer>
</article>