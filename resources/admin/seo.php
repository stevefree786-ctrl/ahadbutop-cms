<?php
/** @var array $health @var array $issues @var array $coverage @var array $recent */

$health   = $health ?? [];
$issues   = $issues ?? [];
$coverage = $coverage ?? [];
$recent   = $recent ?? [];

$fields = [
    'meta_title'       => 'Meta title',
    'meta_description' => 'Meta description',
    'focus_keyword'    => 'Focus keyword',
    'canonical_url'    => 'Canonical URL',
    'og_image'         => 'Open Graph image',
];
$total = (int) ($coverage['total'] ?? 0);
?>

<section class="tiles">
  <?php foreach ($fields as $key => $label):
    $done = (int) ($coverage[$key] ?? 0);
    $pct  = $total > 0 ? (int) round($done / $total * 100) : 0;
  ?>
    <div class="tile <?= $pct >= 80 ? 'ok' : ($pct >= 40 ? 'warn' : 'bad') ?>">
      <span class="tile-value"><?= $pct ?>%</span>
      <span class="tile-label"><?= e($label) ?></span>
      <small><?= $done ?> of <?= $total ?></small>
    </div>
  <?php endforeach; ?>
</section>

<div class="split">
  <div class="col-main">

    <div class="panel">
      <div class="panel-head">
        <h2>Issues</h2>
        <span class="muted"><?= count($issues) ?> post<?= count($issues) === 1 ? '' : 's' ?></span>
      </div>

      <?php if ($issues === []): ?>
        <?= empty_state('Nothing flagged.', 'Every published post clears the mechanical checks.') ?>
      <?php else: ?>
        <table class="table">
          <thead>
            <tr>
              <th scope="col">Post</th>
              <th scope="col">Problems</th>
              <th scope="col"><span class="sr-only">Actions</span></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($issues as $row): ?>
            <tr>
              <td>
                <a href="/admin/editor/<?= (int) $row['post']['id'] ?>"><?= e($row['post']['title']) ?></a>
                <small class="muted">/<?= e($row['post']['slug']) ?></small>
              </td>
              <td>
                <ul class="problem-list">
                  <?php foreach ($row['problems'] as $p): ?>
                    <li><?= e($p) ?></li>
                  <?php endforeach; ?>
                </ul>
              </td>
              <td class="actions">
                <button type="button" class="btn btn-sm btn-primary"
                        data-action="seo-fix" data-id="<?= (int) $row['post']['id'] ?>">
                  Let the agent fix it
                </button>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

    <div class="panel">
      <div class="panel-head"><h2>Needs meta</h2></div>
      <?php if ($recent === []): ?>
        <p class="hint">Every published post has a meta description.</p>
      <?php else: ?>
        <ul class="link-list">
          <?php foreach ($recent as $p): ?>
            <li>
              <a href="/admin/editor/<?= (int) $p['id'] ?>"><?= e($p['title']) ?></a>
              <code class="muted">/<?= e($p['slug']) ?></code>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>

  </div>

  <div class="col-side">
    <div class="panel">
      <h2>Site surface</h2>
      <ul class="link-list">
        <li><a href="/api/v1/seo/sitemap.xml" target="_blank" rel="noopener">sitemap.xml ↗</a></li>
        <li><a href="/api/v1/seo/robots.txt" target="_blank" rel="noopener">robots.txt ↗</a></li>
        <li><a href="/feed.xml" target="_blank" rel="noopener">RSS feed ↗</a></li>
      </ul>
      <button type="button" class="btn btn-ghost btn-block" id="crawl-seo">
        Crawl and audit
      </button>
      <p class="hint">Queues one <code>seo.audit</code> job per published post.</p>
    </div>

    <div class="panel">
      <h2>What is checked</h2>
      <p class="hint">
        The checks here are mechanical — missing fields, thin content, titles
        that truncate. Judgement calls (does this actually serve the intent?)
        need the SEO agent, which you can run per post from the table above.
      </p>
    </div>
  </div>
</div>