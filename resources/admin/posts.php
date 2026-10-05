<?php
/** @var array $result @var array $filters @var int $page @var array $authors @var array $statuses */

$items = $result['items'] ?? [];
$total = (int) ($result['total'] ?? 0);
$query = $_GET;
?>

<div class="panel">
  <form class="filters" method="get" action="/admin/posts">
    <input type="search" name="q" placeholder="Search title or excerpt"
           value="<?= e($filters['search'] ?? '') ?>" aria-label="Search posts">

    <select name="status" aria-label="Status">
      <option value="">Any status</option>
      <?php foreach ($statuses as $s): ?>
        <option value="<?= e($s) ?>" <?= ($filters['status'] ?? '') === $s ? 'selected' : '' ?>>
          <?= e(ucfirst($s)) ?>
        </option>
      <?php endforeach; ?>
    </select>

    <select name="type" aria-label="Type">
      <option value="">Posts and pages</option>
      <option value="post" <?= ($filters['type'] ?? '') === 'post' ? 'selected' : '' ?>>Posts only</option>
      <option value="page" <?= ($filters['type'] ?? '') === 'page' ? 'selected' : '' ?>>Pages only</option>
    </select>

    <select name="author" aria-label="Author">
      <option value="">Any author</option>
      <?php foreach ($authors as $a): ?>
        <option value="<?= (int) $a['id'] ?>" <?= (string) ($filters['author_id'] ?? '') === (string) $a['id'] ? 'selected' : '' ?>>
          <?= e($a['display_name'] ?: $a['username']) ?>
        </option>
      <?php endforeach; ?>
    </select>

    <button class="btn" type="submit">Filter</button>
    <a class="btn btn-ghost" href="/admin/posts">Reset</a>
    <span class="spacer"></span>
    <a class="btn btn-primary" href="/admin/editor/new">New post</a>
  </form>
</div>

<div class="panel">
  <div class="panel-head">
    <h2><?= (int) $total ?> post<?= $total === 1 ? '' : 's' ?></h2>
  </div>

  <?php if ($items === []): ?>
    <?= empty_state(
        'Nothing matches.',
        'Try a wider filter, or start something new.',
        '<a class="btn btn-primary" href="/admin/editor/new">New post</a>'
    ) ?>
  <?php else: ?>
    <table class="table">
      <thead>
        <tr>
          <th scope="col">Title</th>
          <th scope="col">Status</th>
          <th scope="col">Origin</th>
          <th scope="col" class="num">Words</th>
          <th scope="col">Published</th>
          <th scope="col"><span class="sr-only">Actions</span></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($items as $p): ?>
        <tr data-id="<?= (int) $p['id'] ?>">
          <td>
            <a href="/admin/editor/<?= (int) $p['id'] ?>"><?= e($p['title']) ?></a>
            <small class="muted">/<?= e($p['slug']) ?> · <?= e($p['type']) ?></small>
          </td>
          <td><?= badge((string) $p['status']) ?></td>
          <td><span class="origin origin-<?= e($p['origin']) ?>"><?= e($p['origin']) ?></span></td>
          <td class="num"><?= (int) $p['word_count'] ?></td>
          <td class="muted"><?= e(ago($p['published_at'])) ?></td>
          <td class="actions">
            <?php if ($p['status'] === 'published'): ?>
              <a href="/<?= e($p['slug']) ?>" target="_blank" rel="noopener" class="btn btn-ghost btn-sm">View</a>
            <?php endif; ?>
            <button type="button"
                    class="btn btn-sm"
                    data-action="toggle-publish"
                    data-id="<?= (int) $p['id'] ?>"
                    data-status="<?= e($p['status']) ?>">
              <?= in_array($p['status'], ['published'], true) ? 'Unpublish' : 'Publish' ?>
            </button>
            <a class="btn btn-sm btn-ghost" href="/admin/editor/<?= (int) $p['id'] ?>">Edit</a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <?= pagination($page, $total, 20, '/admin/posts?' . http_build_query($filters)) ?>
  <?php endif; ?>
</div>