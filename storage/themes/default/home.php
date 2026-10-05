<?php /** @var \CMS\Render\TemplateEngine $this */ ?>
<?php if (($intro ?? '') !== ''): ?>
<div class="page-intro">
  <h1><?= $this->e($introTitle ?? 'Latest') ?></h1>
  <?php if (($intro ?? '') !== '' && ($introTitle ?? '') !== 'Latest'): ?>
    <p><?= $this->e($intro) ?></p>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if (empty($posts)): ?>
  <p class="empty">No posts published yet.</p>
<?php else: ?>
  <div class="post-list">
    <?php foreach ($posts as $post): ?>
      <?= $this->partial('card', ['post' => $post, 'site' => $site ?? []]) ?>
    <?php endforeach; ?>
  </div>

  <?php if (($pagination['pages'] ?? 1) > 1): ?>
    <nav class="pagination" aria-label="Pagination">
      <?php if (($pagination['page'] ?? 1) > 1): ?>
        <a rel="prev" href="<?= $this->e($pagination['prev_url'] ?? '#') ?>">&larr; Newer</a>
      <?php endif; ?>
      <span>Page <?= (int) ($pagination['page'] ?? 1) ?> of <?= (int) ($pagination['pages'] ?? 1) ?></span>
      <?php if (($pagination['page'] ?? 1) < ($pagination['pages'] ?? 1)): ?>
        <a rel="next" href="<?= $this->e($pagination['next_url'] ?? '#') ?>">Older &rarr;</a>
      <?php endif; ?>
    </nav>
  <?php endif; ?>
<?php endif; ?>