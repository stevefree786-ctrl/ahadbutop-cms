<?php
/**
 * Post / page editor.
 *
 * @var ?array  $post
 * @var bool    $is_new
 * @var ?array  $seo
 * @var array   $tags @var array $categories
 * @var array   $post_tags @var array $post_cats
 */

$post   = $post ?? null;
$isNew  = $isNew || $post === null;
$action = $isNew ? 'create' : 'update';
$value  = static fn (string $k, $fallback = '') => (string) ($post[$k] ?? $fallback);

$rank    = $rank ?? 1;
$canPub  = $rank >= 2;   // editor
$canAdm  = $rank >= 3;   // admin
?>

<form class="editor" id="post-form"
      data-action="<?= e($action) ?>"
      data-id="<?= $isNew ? '' : (int) $post['id'] ?>">

  <div class="split">
    <div class="col-main">

      <div class="panel">
        <label class="field">
          <span class="label">Title</span>
          <input type="text" name="title" required maxlength="200"
                 placeholder="A title that earns the click"
                 value="<?= e($value('title')) ?>">
        </label>

        <label class="field">
          <span class="label">Slug</span>
          <div class="slug-row">
            <input type="text" name="slug" placeholder="auto-generated-from-title"
                   value="<?= e($value('slug')) ?>">
            <span class="hint">/<?= e($value('slug', '…')) ?></span>
          </div>
        </label>

        <label class="field">
          <span class="label">Excerpt <small>shown in lists and search results</small></span>
          <textarea name="excerpt" rows="2" maxlength="300"><?= e($value('excerpt')) ?></textarea>
        </label>

        <label class="field">
          <span class="label">Body <small>Markdown</small></span>
          <textarea name="body_md" id="body" rows="22" spellcheck="true"
                    placeholder="## Write here"><?= e($value('body_md')) ?></textarea>
        </label>

        <div class="editor-foot">
          <span class="hint" id="wordcount"></span>
          <button type="button" class="btn btn-ghost" id="preview">Preview</button>
        </div>
      </div>

    </div>

    <div class="col-side">

      <div class="panel">
        <h2>Publish</h2>
        <?php if (!$isNew): ?>
          <p class="hint">Currently <strong><?= e($value('status')) ?></strong>.</p>
        <?php endif; ?>

        <label class="field">
          <span class="label">Status</span>
          <select name="status">
            <?php foreach (['draft', 'review', 'published', 'scheduled'] as $s): ?>
              <option value="<?= e($s) ?>" <?= $value('status', 'draft') === $s ? 'selected' : '' ?>>
                <?= e(ucfirst($s)) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>

        <label class="field">
          <span class="label">Type</span>
          <select name="type">
            <option value="post" <?= $value('type', 'post') === 'post' ? 'selected' : '' ?>>Post</option>
            <option value="page" <?= $value('type', 'post') === 'page' ? 'selected' : '' ?>>Page</option>
          </select>
        </label>

        <?php if (!$canPub): ?>
          <p class="hint">Authors can save drafts. Publishing needs an editor.</p>
        <?php endif; ?>

        <button class="btn btn-primary btn-block" type="submit" <?= $canPub ? '' : 'data-needs-editor="1"' ?>>
          <?= $isNew ? 'Create' : 'Save changes' ?>
        </button>

        <?php if (!$isNew && $value('status') === 'published'): ?>
          <a class="btn btn-ghost btn-block" href="/<?= e($value('slug')) ?>" target="_blank" rel="noopener">
            View live ↗
          </a>
        <?php endif; ?>
      </div>

      <div class="panel">
        <h2>SEO</h2>
        <label class="field">
          <span class="label">Focus keyword</span>
          <input type="text" name="focus_keyword"
                 value="<?= e($seo['focus_keyword'] ?? '') ?>"
                 placeholder="the one phrase this page is for">
        </label>
        <label class="field">
          <span class="label">Meta description
            <small id="meta-len"><?= strlen((string) ($seo['meta_description'] ?? '')) ?>/160</small>
          </span>
          <textarea name="meta_description" rows="3"
                    maxlength="160"><?= e($seo['meta_description'] ?? '') ?></textarea>
        </label>
        <p class="hint">
          <button type="button" class="btn btn-sm btn-ghost" id="seo-suggest">
            Suggest with SEO agent
          </button>
        </p>
      </div>

      <div class="panel">
        <h2>Categories</h2>
        <?php foreach ($categories as $c): ?>
          <label class="check">
            <input type="checkbox" name="category_ids[]" value="<?= (int) $c['id'] ?>"
              <?= in_array((int) $c['id'], $post_cats, true) ? 'checked' : '' ?>>
            <span><?= e($c['name']) ?></span>
          </label>
        <?php endforeach; ?>
        <?php if ($categories === []) : ?>
          <p class="hint">None yet — <a href="/admin/taxonomy">create one</a>.</p>
        <?php endif; ?>
      </div>

      <div class="panel">
        <h2>Tags</h2>
        <?php foreach ($tags as $t): ?>
          <label class="check">
            <input type="checkbox" name="tag_ids[]" value="<?= (int) $t['id'] ?>"
              <?= in_array((int) $t['id'], $post_tags, true) ? 'checked' : '' ?>>
            <span><?= e($t['name']) ?></span>
          </label>
        <?php endforeach; ?>
        <?php if ($tags === []) : ?>
          <p class="hint">None yet — <a href="/admin/taxonomy">create one</a>.</p>
        <?php endif; ?>
      </div>

    </div>
  </div>
</form>

<div class="drawer" id="preview-drawer" hidden>
  <div class="drawer-panel drawer-wide" role="dialog" aria-modal="true" aria-label="Preview">
    <header class="drawer-head">
      <h2>Preview</h2>
      <button type="button" class="btn btn-icon" data-close="preview-drawer" aria-label="Close">✕</button>
    </header>
    <div class="drawer-body markdown" id="preview-body"></div>
  </div>
</div>