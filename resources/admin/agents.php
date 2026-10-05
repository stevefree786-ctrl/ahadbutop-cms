<?php
/** @var array $agents @var array $actions @var array $recent_runs */

$agentList = $agents ?? [];
$actionList = $actions ?? [];
$count      = count($agentList);
?>

<div class="panel notice">
  <p>
    <strong><?= (int) $count ?> agents</strong>, each scoped to one job, each acting
    only through an allowlist of <?= count($actionList) ?> validated actions.
    A model can request an action and its parameters; it cannot write SQL, and
    a request for something off the list is refused rather than executed.
  </p>
</div>

<div class="split">
  <div class="col-main">

    <div class="panel">
      <div class="panel-head"><h2>The fleet</h2></div>

      <div class="agent-grid">
        <?php foreach ($agentList as $a): ?>
          <article class="agent-card" data-agent="<?= e($a['class']) ?>">
            <header>
              <h3><?= e($a['class']) ?></h3>
              <span class="file muted"><?= e($a['file']) ?></span>
            </header>
            <p><?= e($a['summary']) ?></p>
            <?php if ($a['intents'] !== []): ?>
              <ul class="intent-list">
                <?php foreach ($a['intents'] as $intent): ?>
                  <li><code><?= e($intent) ?></code></li>
                <?php endforeach; ?>
              </ul>
            <?php else: ?>
              <p class="hint">Driven by job type rather than named intents.</p>
            <?php endif; ?>
            <button type="button" class="btn btn-sm btn-primary" data-action="ask-agent"
                    data-agent="<?= e($a['class']) ?>">Ask it to do something</button>
          </article>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="panel">
      <div class="panel-head"><h2>Recent runs</h2></div>
      <?php if (($recent_runs ?? []) === []): ?>
        <?= empty_state('No agent has run yet.', 'Queued work shows up here once it completes.') ?>
      <?php else: ?>
        <table class="table">
          <thead>
            <tr>
              <th scope="col">Agent</th>
              <th scope="col">Result</th>
              <th scope="col">Status</th>
              <th scope="col">Took</th>
              <th scope="col">Ran</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($recent_runs as $r): ?>
            <?php
              // agent_runs records the outcome, not the prompt: there is no
              // 'task' column to show, so the second cell carries whatever the
              // run reported — the error on a failure, the duration otherwise.
              $detail = ($r['error'] ?? '') !== ''
                  ? (string) $r['error']
                  : sprintf('%d ms', (int) ($r['duration_ms'] ?? 0));
            ?>
            <tr>
              <td><code><?= e($r['agent'] ?? '—') ?></code></td>
              <td class="truncate" title="<?= e($detail) ?>"><?= e($detail) ?></td>
              <td><?= badge((string) ($r['status'] ?? 'unknown')) ?></td>
              <td class="muted"><?= (int) ($r['duration_ms'] ?? 0) ?> ms</td>
              <td class="muted"><?= e(ago($r['created_at'] ?? null)) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>

  </div>

  <div class="col-side">
    <div class="panel">
      <div class="panel-head"><h2>Action catalogue</h2></div>
      <p class="hint">Everything an agent is permitted to do.</p>
      <ul class="action-list">
        <?php foreach ($actionList as $a): ?>
          <li>
            <code><?= e($a['name']) ?></code>
            <?php if ($a['params'] !== []): ?>
              <small class="muted">
                <?= e(implode(', ', array_map(
                    static fn ($p) => $p['name'] . ($p['required'] ? '' : '?'),
                    $a['params']
                ))) ?>
              </small>
            <?php endif; ?>
            <?php if ($a['writer'] !== null): ?>
              <span class="role-tag"><?= e($a['writer']) ?> +</span>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
</div>

<div class="drawer" id="agent-drawer" hidden>
  <div class="drawer-panel" role="dialog" aria-modal="true" aria-labelledby="agent-drawer-title">
    <header class="drawer-head">
      <h2 id="agent-drawer-title">Ask an agent</h2>
      <button type="button" class="btn btn-icon" data-close="agent-drawer" aria-label="Close">✕</button>
    </header>
    <div class="drawer-body">
      <form id="agent-form">
        <label class="field">
          <span class="label">Agent</span>
          <select name="agent" id="agent-select">
            <?php foreach ($agentList as $a): ?>
              <option value="<?= e($a['class']) ?>"><?= e($a['class']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>
        <label class="field">
          <span class="label">What should it do?</span>
          <textarea name="task" rows="4" required
                    placeholder="Write a post about X, aimed at Y"></textarea>
        </label>
        <p class="hint">
          The agent decides which allowlisted actions to request. Anything not
          on the list is refused and reported back to you.
        </p>
        <button class="btn btn-primary btn-block" type="submit">Run it</button>
        <output class="agent-output" id="agent-output"></output>
      </form>
    </div>
  </div>
</div>