<?php
/** @var array $tags @var array $categories */

$tagList = $tags ?? [];
$catList = $categories ?? [];
?>

<div class="split">
  <div class="col-main">

    <div class="panel">
      <div class="panel-head">
        <h2>Tags <small class="muted"><?= count($tagList) ?></small></h2>
      </div>

      <form class="inline-add" data-action="create-tag">
        <input type="text" name="name" placeholder="New tag" required maxlength="80" aria-label="New tag name">
        <button class="btn btn-primary" type="submit">Add</button>
      </form>

      <?php if ($tagList === []): ?>
        <?= empty_state('No tags yet.', 'Tags are free-form and are what the search agent indexes.') ?>
      <?php else: ?>
        <table class="table">
          <thead>
            <tr>
              <th scope="col">Name</th>
              <th scope="col">Slug</th>
              <th scope="col" class="num">Used</th>
              <th scope="col"><span class="sr-only">Actions</span></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($tagList as $t): ?>
            <tr data-id="<?= (int) $t['id'] ?>">
              <td><?= e($t['name']) ?></td>
              <td class="muted"><code><?= e($t['slug']) ?></code></td>
              <td class="num"><?= (int) ($t['usage_count'] ?? 0) ?></td>
              <td class="actions">
                <button type="button" class="btn btn-sm btn-danger"
                        data-action="delete-tag" data-id="<?= (int) $t['id'] ?>">Delete</button>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

    <div class="panel">
      <div class="panel-head">
        <h2>Categories <small class="muted"><?= count($catList) ?></small></h2>
      </div>

      <form class="inline-add" data-action="create-category">
        <input type="text" name="name" placeholder="New category" required maxlength="80" aria-label="New category name">
        <button class="btn btn-primary" type="submit">Add</button>
      </form>

      <?php if ($catList === []): ?>
        <?= empty_state('No categories yet.', 'Categories are hierarchical and drive the main navigation.') ?>
      <?php else: ?>
        <table class="table">
          <thead>
            <tr>
              <th scope="col">Name</th>
              <th scope="col">Slug</th>
              <th scope="col" class="num">Order</th>
              <th scope="col"><span class="sr-only">Actions</span></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($catList as $c): ?>
            <tr data-id="<?= (int) $c['id'] ?>">
              <td><?= e($c['name']) ?></td>
              <td class="muted"><code><?= e($c['slug']) ?></code></td>
              <td class="num muted"><?= (int) ($c['sort_order'] ?? 0) ?></td>
              <td class="actions">
                <button type="button" class="btn btn-sm btn-danger"
                        data-action="delete-category" data-id="<?= (int) $c['id'] ?>">Delete</button>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

  </div>

  <div class="col-side">
    <div class="panel">
      <h2>How these are used</h2>
      <p class="hint">
        <strong>Tags</strong> are flat and free-form. Their usage count is
        maintained on write and can be rebuilt if it ever drifts.
      </p>
      <p class="hint">
        <strong>Categories</strong> may nest. They drive the site navigation, so
        deleting one unlinks it from every post rather than removing the posts.
      </p>
      <button type="button" class="btn btn-ghost btn-block" id="recompute-tags">
        Recount tag usage
      </button>
    </div>
  </div>
</div>