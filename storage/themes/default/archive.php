<?php /** @var \CMS\Render\TemplateEngine $this */ ?>
<div class="page-intro">
  <h1><?= $this->e($heading ?? 'Archive') ?></h1>
  <?php if (($description ?? '') !== ''): ?>
    <p><?= $this->e($description) ?></p>
  <?php endif; ?>
  <?php if (!empty($count)): ?>
    <p class="meta"><?= (int) $count ?> post<?= (int) $count === 1 ? '' : 's' ?></p>
  <?php endif; ?>
</div>

<?php if (empty($posts)): ?>
  <p class="empty">Nothing here yet.</p>
<?php else: ?>
  <div class="post-list">
    <?php foreach ($posts as $post): ?>
      <?= $this->partial('card', ['post' => $post, 'site' => $site ?? []]) ?>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
