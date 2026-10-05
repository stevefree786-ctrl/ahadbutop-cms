<?php
/** @var array $stats @var array $job_stats @var array $recent @var array $seo_health @var array $agents */

$byStatus = $stats['by_status'] ?? [];
$tiles    = [
    ['Published', $byStatus['published'] ?? 0, 'live now', 'ok'],
    ['Drafts',    $byStatus['draft'] ?? 0, 'awaiting work', ''],
    ['In review', $byStatus['review'] ?? 0, 'needs an editor', 'warn'],
    ['Scheduled', $byStatus['scheduled'] ?? 0, 'queued for later', ''],
    ['AI-written', $stats['by_origin']['ai'] ?? 0, 'last 30 days: ' . ($stats['published_30d'] ?? 0), 'info'],
];
?>

<section class="tiles">
  <?php foreach ($tiles as [$label, $value, $sub, $tone]): ?>
    <div class="tile <?= e($tone) ?>">
      <span class="tile-value"><?= e((string) $value) ?></span>
      <span class="tile-label"><?= e($label) ?></span>
      <small><?= e($sub) ?></small>
    </div>
  <?php endforeach; ?>
</section>

<div class="split">
  <div class="col-main">

    <div class="panel">
      <div class="panel-head">
        <h2>Recent posts</h2>
        <a class="btn btn-ghost" href="/admin/posts">All posts</a>
      </div>

      <?php if (($recent['items'] ?? []) === []): ?>
        <?= empty_state(
            'No content yet.',
            'Ask an agent to write the first one, or write it yourself.',
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
              <th scope="col">Updated</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($recent['items'] as $p): ?>
            <tr>
              <td>
                <a href="/admin/editor/<?= (int) $p['id'] ?>"><?= e($p['title']) ?></a>
                <small class="muted">/<?= e($p['slug']) ?></small>
              </td>
              <td><?= badge((string) $p['status']) ?></td>
              <td><span class="origin origin-<?= e($p['origin']) ?>"><?= e($p['origin']) ?></span></td>
              <td class="num"><?= (int) $p['word_count'] ?></td>
              <td class="muted"><?= e(ago($p['updated_at'])) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

    <div class="panel">
      <div class="panel-head">
        <h2>Ask the fleet</h2>
        <a class="btn btn-ghost" href="/admin/agents">All agents</a>
      </div>
      <p class="hint">
        Every action an agent can take is on an allowlist — nothing it writes
        reaches the database as a query.
      </p>
      <ul class="agent-strip">
        <?php foreach (array_slice($agents, 0, 6) as $a): ?>
          <li>
            <button type="button" class="agent-chip" data-agent="<?= e($a['class']) ?>">
              <strong><?= e($a['class']) ?></strong>
              <span><?= e(mb_substr($a['summary'], 0, 78)) ?></span>
            </button>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>

  </div>

  <div class="col-side">

    <div class="panel">
      <div class="panel-head"><h2>SEO health</h2></div>
      <?php if (($seo_health['published'] ?? 0) === 0): ?>
        <p class="hint">Nothing published yet.</p>
      <?php else: ?>
        <div class="meter" role="img" aria-label="Meta description coverage <?= (int) $seo_health['meta_pct'] ?> percent">
          <div class="meter-fill" style="width: <?= (int) $seo_health['meta_pct'] ?>%"></div>
        </div>
        <p class="hint">
          <?= (int) $seo_health['with_meta'] ?> of <?= (int) $seo_health['published'] ?>
          published posts have a meta description
          (<?= (int) $seo_health['meta_pct'] ?>%).
        </p>
        <div class="meter" role="img" aria-label="Focus keyword coverage <?= (int) $seo_health['focus_pct'] ?> percent">
          <div class="meter-fill alt" style="width: <?= (int) $seo_health['focus_pct'] ?>%"></div>
        </div>
        <p class="hint">
          <?= (int) $seo_health['with_focus'] ?> carry a focus keyword
          (<?= (int) $seo_health['focus_pct'] ?>%).
        </p>
        <a class="btn btn-ghost btn-block" href="/admin/seo">Open SEO report</a>
      <?php endif; ?>
    </div>

    <div class="panel">
      <div class="panel-head">
        <h2>Job queue</h2>
        <a class="btn btn-ghost" href="/admin/jobs">Queue</a>
      </div>
      <ul class="kv">
        <li><span>Queued</span>  <strong><?= (int) ($job_stats['queued'] ?? 0) ?></strong></li>
        <li><span>Running</span> <strong><?= (int) ($job_stats['running'] ?? 0) ?></strong></li>
        <li><span>Done</span>    <strong><?= (int) ($job_stats['succeeded'] ?? 0) ?></strong></li>
        <li><span>Failed</span>  <strong class="<?= ($job_stats['failed'] ?? 0) > 0 ? 'bad-text' : '' ?>"><?= (int) ($job_stats['failed'] ?? 0) ?></strong></li>
        <li><span>Dead</span>    <strong class="<?= ($job_stats['dead'] ?? 0) > 0 ? 'bad-text' : '' ?>"><?= (int) ($job_stats['dead'] ?? 0) ?></strong></li>
      </ul>
    </div>

    <div class="panel">
      <div class="panel-head">
        <h2>Top tags</h2>
        <a class="btn btn-ghost" href="/admin/taxonomy">Manage</a>
      </div>
      <?php if (($top_tags ?? []) === []): ?>
        <p class="hint">No tags used yet.</p>
      <?php else: ?>
        <ul class="tag-cloud">
          <?php foreach ($top_tags as $t): ?>
            <li>
              <a href="/admin/taxonomy"><?= e($t['name']) ?>
                <span class="count"><?= (int) ($t['usage_count'] ?? 0) ?></span>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>

  </div>
</div>