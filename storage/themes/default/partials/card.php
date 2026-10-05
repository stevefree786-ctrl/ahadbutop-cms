<?php
/** Post summary card. Expects $post. */
/** @var \CMS\Render\TemplateEngine $this */
$url = $post['type'] === 'page'
    ? $this->url('page/' . $post['slug'])
    : $this->url($post['slug']);
?>
<article class="card">
  <?php if (!empty($post['featured_image_url'])): ?>
    <a href="<?= $this->e($url) ?>" tabindex="-1" aria-hidden="true">
      <img src="<?= $this->e($post['featured_image_url']) ?>" alt="" loading="lazy">
    </a>
  <?php endif; ?>

  <div class="card-body">
    <h2><a href="<?= $this->e($url) ?>"><?= $this->e($post['title']) ?></a></h2>

    <p class="meta">
      <time datetime="<?= $this->e($this->date($post['published_at'], 'c')) ?>">
        <?= $this->e($this->date($post['published_at'])) ?>
      </time>
      <?php if (!empty($post['author_name'])): ?>
        <span>· <?= $this->e($post['author_name']) ?></span>
      <?php endif; ?>
      <?php if (!empty($post['reading_time'])): ?>
        <span>· <?= (int) $post['reading_time'] ?> min read</span>
      <?php endif; ?>
    </p>

    <?php if (!empty($post['excerpt'])): ?>
      <p class="excerpt"><?= $this->e($post['excerpt']) ?></p>
    <?php endif; ?>
  </div>
</article>