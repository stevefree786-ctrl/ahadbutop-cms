<?php
/** @var array $result @var array $stats @var array $types @var array $filters */

$items = $result['items'] ?? [];
$total = (int) ($result['total'] ?? 0);
$q     = $_GET;
?>

<section class="tiles tiles-sm">
  <?php foreach (['queued', 'running', 'succeeded', 'failed', 'dead', 'cancelled'] as $s): ?>
    <a class="tile <?= ($stats[$s] ?? 0) > 0 && in_array($s, ['failed', 'dead'], true) ? 'bad' : '' ?>"
       href="/admin/jobs?status=<?= e($s) ?>">
      <span class="tile-value"><?= (int) ($stats[$s] ?? 0) ?></span>
      <span class="tile-label"><?= e(ucfirst($s)) ?></span>
    </a>
  <?php endforeach; ?>
</section>

<div class="panel">
  <form class="filters" method="get" action="/admin/jobs">
    <select name="status" aria-label="Status">
      <option value="">Any status</option>
      <?php foreach (['queued', 'running', 'succeeded', 'failed', 'dead', 'cancelled'] as $s): ?>
        <option value="<?= e($s) ?>" <?= ($filters['status'] ?? '') === $s ? 'selected' : '' ?>><?= e(ucfirst($s)) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="type" aria-label="Type">
      <option value="">Any type</option>
      <?php foreach ($types as $t): ?>
        <option value="<?= e($t) ?>" <?= ($filters['type'] ?? '') === $t ? 'selected' : '' ?>><code><?= e($t) ?></code></option>
      <?php endforeach; ?>
    </select>
    <button class="btn" type="submit">Filter</button>
    <a class="btn btn-ghost" href="/admin/jobs">Reset</a>
    <span class="spacer"></span>
    <button type="button" class="btn btn-primary" id="enqueue">Queue a job</button>
  </form>
</div>

<div class="panel">
  <div class="panel-head"><h2><?= (int) $total ?> job<?= $total === 1 ? '' : 's' ?></h2></div>

  <?php if ($items === []): ?>
    <?= empty_state('No jobs match.', 'Queue work from the agents screen.') ?>
  <?php else: ?>
    <table class="table">
      <thead>
        <tr>
          <th scope="col" class="num">#</th>
          <th scope="col">Type</th>
          <th scope="col">Status</th>
          <th scope="col" class="num">Attempts</th>
          <th scope="col">Queued</th>
          <th scope="col">Last error</th>
          <th scope="col"><span class="sr-only">Actions</span></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($items as $j): ?>
        <tr data-id="<?= (int) $j['id'] ?>">
          <td class="num muted"><?= (int) $j['id'] ?></td>
          <td><code><?= e($j['type']) ?></code></td>
          <td><?= badge((string) $j['status']) ?></td>
          <td class="num"><?= (int) $j['attempts'] ?>/<?= (int) $j['max_attempts'] ?></td>
          <td class="muted"><?= e(ago($j['created_at'])) ?></td>
          <td class="truncate muted" title="<?= e($j['last_error'] ?? '') ?>">
            <?= e($j['last_error'] ?? '—') ?>
          </td>
          <td class="actions">
            <?php if (in_array($j['status'], ['queued', 'running'], true)): ?>
              <button type="button" class="btn btn-sm" data-action="cancel-job"
                      data-id="<?= (int) $j['id'] ?>">Cancel</button>
            <?php endif; ?>
            <?php if (in_array($j['status'], ['failed', 'dead', 'cancelled'], true)): ?>
              <button type="button" class="btn btn-sm" data-action="retry-job"
                      data-id="<?= (int) $j['id'] ?>">Retry</button>
            <?php endif; ?>
            <?php if ((int) $j['log_count'] > 0): ?>
              <button type="button" class="btn btn-sm btn-ghost" data-action="job-logs"
                      data-id="<?= (int) $j['id'] ?>">Logs</button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?= pagination((int) ($q['page'] ?? 1), $total, 40, '/admin/jobs?' . http_build_query($filters)) ?>
  <?php endif; ?>
</div>