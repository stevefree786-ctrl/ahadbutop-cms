<?php
/** @var array $skills — structured skill descriptions from Skills::describe() */

$list = $skills ?? [];

/*
 * The parameter list is embedded rather than fetched. It is small, it is
 * already in the HTML that rendered these cards, and a fetch on drawer-open
 * would make the form appear a round-trip late for no benefit. The server
 * re-validates every value regardless — this is a rendering aid, not a
 * permission check, and a hand-edited field changes what is submitted, never
 * what is allowed.
 */
?>
<script>window.SKILLS = <?= json_encode(array_map(
    static fn (array $s): array => ['name' => $s['name'], 'params' => $s['params']],
    $list
)) ?>;</script>

<div class="panel notice">
  <p>
    A <strong>skill</strong> is a fixed sequence of actions, already written for
    you. An agent improvises each action; a skill runs exactly the steps below,
    in order, and stops at the first one you are not allowed to do. That is the
    whole difference: a skill is right by construction, and you can read it
    before running it.
  </p>
</div>

<?php if ($list === []): ?>
  <?= empty_state(
      'No skills are loaded.',
      'Built-in skills ship with the CMS. Operator-authored skills are JSON files in storage/skills.'
  ) ?>
<?php else: ?>
  <div class="agent-grid">
    <?php foreach ($list as $s): ?>
      <article class="agent-card" data-skill="<?= e($s['name']) ?>">
        <header>
          <h3><?= e($s['label']) ?></h3>
          <span class="file muted"><?= e($s['source']) ?></span>
        </header>

        <p><?= e($s['description']) ?></p>

        <?php if ($s['params'] !== []): ?>
          <p class="hint">
            Takes:
            <?php foreach ($s['params'] as $p): ?>
              <code><?= e($p) ?></code><?= $p === end($s['params']) ? '' : ', ' ?>
            <?php endforeach; ?>
          </p>
        <?php else: ?>
          <p class="hint">Takes no parameters.</p>
        <?php endif; ?>

        <ol class="intent-list skill-steps">
          <?php foreach ($s['steps'] as $i => $step): ?>
            <li>
              <code><?= e($step['intent']) ?></code>
              <?php if ($step['writer'] !== null): ?>
                <span class="role-tag"><?= e($step['writer']) ?> +</span>
              <?php else: ?>
                <span class="role-tag">any role</span>
              <?php endif; ?>
              <?php if ($step['to'] !== []): ?>
                <small class="muted">
                  binds <?= e(implode(', ', array_keys($step['to']))) ?>
                </small>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ol>

        <?php if ($s['runnable_by'] === null): ?>
          <p class="hint">
            No single role can run every step. Yours will stop at the first one
            you are not allowed to do — the tags above say which.
          </p>
        <?php endif; ?>

        <button type="button" class="btn btn-sm btn-primary" data-action="run-skill"
                data-skill="<?= e($s['name']) ?>">Run this skill</button>
      </article>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

<div class="drawer" id="skill-drawer" hidden>
  <div class="drawer-panel" role="dialog" aria-modal="true" aria-labelledby="skill-drawer-title">
    <header class="drawer-head">
      <h2 id="skill-drawer-title">Run a skill</h2>
      <button type="button" class="btn btn-icon" data-close="skill-drawer" aria-label="Close">✕</button>
    </header>
    <div class="drawer-body">
      <form id="skill-form">
        <label class="field">
          <span class="label">Skill</span>
          <select name="skill" id="skill-select">
            <?php foreach ($list as $s): ?>
              <option value="<?= e($s['name']) ?>"><?= e($s['label']) ?></option>
            <?php endforeach; ?>
          </select>
        </label>

        <div id="skill-params">
          <p class="hint">This skill takes no parameters.</p>
        </div>

        <p class="hint">
          Steps run in order. If your role does not allow one of them, that step
          is refused and anything after it is skipped — steps before it have
          already been written, and the result below says which.
        </p>

        <button class="btn btn-primary btn-block" type="submit">Run it</button>
        <output class="agent-output" id="skill-output"></output>
      </form>
    </div>
  </div>
</div>