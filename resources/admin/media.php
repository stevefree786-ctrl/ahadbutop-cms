<?php
/** @var array $media @var array $totals @var int $page */

$items = $media['items'] ?? [];
$total = (int) ($media['total'] ?? 0);
$q     = $_GET;
?>

<div class="panel">
  <form class="filters" method="get" action="/admin/media">
    <input type="search" name="q" placeholder="Search by name or type"
           value="<?= e($q['q'] ?? '') ?>" aria-label="Search media">
    <button class="btn" type="submit">Search</button>
    <a class="btn btn-ghost" href="/admin/media">Reset</a>
    <span class="spacer"></span>
    <span class="hint"><?= (int) $totals['count'] ?> files · <?= e(bytes((int) $totals['bytes'])) ?></span>
  </form>
</div>

<div class="panel">
  <?php if ($items === []): ?>
    <?= empty_state(
        'No media yet.',
        'Upload images and documents; the media agent can attach them to posts.',
        '<a class="btn btn-primary" href="/admin/agents">Ask the media agent</a>'
    ) ?>
  <?php else: ?>
    <div class="media-grid">
      <?php foreach ($items as $m): ?>
        <figure class="media-card" data-id="<?= (int) $m['id'] ?>">
          <?php if (str_starts_with((string) $m['mime'], 'image/')): ?>
            <img src="/<?= e($m['key']) ?>" alt="<?= e($m['original_name']) ?>" loading="lazy">
          <?php else: ?>
            <div class="media-thumb media-thumb-<?= e(pathinfo((string) $m['mime'], PATHINFO_EXTENSION) ?: 'file') ?>">
              <?= e(strtoupper(pathinfo((string) $m['mime'], PATHINFO_EXTENSION) ?: 'FILE')) ?>
            </div>
          <?php endif; ?>
          <figcaption>
            <strong title="<?= e($m['original_name']) ?>"><?= e($m['original_name']) ?></strong>
            <small><?= e(bytes((int) $m['bytes'])) ?> · <?= e($m['mime']) ?></small>
            <div class="media-actions">
              <button type="button" class="btn btn-sm btn-ghost"
                      data-copy="<?= e('/' . $m['key']) ?>">Copy path</button>
              <button type="button" class="btn btn-sm btn-danger"
                      data-action="delete-media" data-id="<?= (int) $m['id'] ?>">Delete</button>
            </div>
          </figcaption>
        </figure>
      <?php endforeach; ?>
    </div>

    <?= pagination($page, $total, 48, '/admin/media') ?>
  <?php endif; ?>
</div>