<?php /** @var \CMS\Render\TemplateEngine $this */ ?>
<div class="page-intro">
  <h1>Search</h1>
  <form class="search-form" method="get" action="<?= $this->url('search') ?>" role="search">
    <label class="visually-hidden" for="q">Search posts</label>
    <input type="search" id="q" name="q" value="<?= $this->e($query ?? '') ?>"
           placeholder="Search posts&hellip;" autofocus>
    <button type="submit">Search</button>
  </form>
  <?php if (($query ?? '') !== ''): ?>
    <p class="meta">
      <?= (int) ($total ?? 0) ?> result<?= (int) ($total ?? 0) === 1 ? '' : 's' ?>
      for &ldquo;<?= $this->e($query) ?>&rdquo;
    </p>
  <?php endif; ?>
</div>

<?php if (empty($posts)): ?>
  <?php if (($query ?? '') !== ''): ?>
    <p class="empty">No posts matched &ldquo;<?= $this->e($query) ?>&rdquo;.</p>
  <?php endif; ?>
<?php else: ?>
  <div class="post-list">
    <?php foreach ($posts as $post): ?>
      <?= $this->partial('card', ['post' => $post, 'site' => $site ?? []]) ?>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
