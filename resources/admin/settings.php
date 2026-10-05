<?php
/** @var array $settings @var array $admin_only @var array $me */

$adminOnly = $admin_only ?? [];
$isAdmin   = (string) ($me['role'] ?? '') === 'admin';
$rows      = $settings ?? [];
$providers = $providers ?? null;   // admin only; null means "not your screen"

/**
 * Group by the key's prefix so the form reads as sections rather than one
 * undifferentiated list of 40 inputs. A key with no underscore is "general".
 */
$groups = [];
foreach ($rows as $row) {
    $key = (string) $row['key'];
    $group = str_contains($key, '_') ? strtok($key, '_') : 'general';
    $groups[$group][] = $row;
}
ksort($groups);
?>

<?php if (!$isAdmin): ?>
  <div class="panel notice">
    <p>You are signed in as an <strong><?= e($me['role'] ?? 'author') ?></strong>.
       Settings are readable by everyone but only an admin can change them.</p>
  </div>
<?php endif; ?>

<?php if (empty($rows)): ?>
  <div class="panel">
    <?= empty_state('No settings stored yet.', 'Defaults are used until something is saved.') ?>
  </div>
<?php endif; ?>

<form id="settings-form" class="stacked">
  <?php foreach ($groups as $group => $items): ?>
    <div class="panel">
      <div class="panel-head">
        <h2><?= e(ucfirst($group)) ?></h2>
      </div>

      <?php foreach ($items as $row):
        $key   = (string) $row['key'];
        $val   = (string) $row['value'];
        $locked = in_array($key, $adminOnly, true) && !$isAdmin;
        $isLong = strlen($val) > 120 || str_starts_with($val, '{') || str_starts_with($val, '[');
      ?>
        <label class="field">
          <span class="label">
            <code><?= e($key) ?></code>
            <?php if (in_array($key, $adminOnly, true)): ?>
              <small class="admin-only">admin only</small>
            <?php endif; ?>
          </span>
          <?php if ($isLong): ?>
            <textarea name="settings[<?= e($key) ?>]" rows="6"
                      <?= $locked ? 'disabled' : '' ?>><?= e($val) ?></textarea>
          <?php else: ?>
            <input type="text" name="settings[<?= e($key) ?>]" value="<?= e($val) ?>"
                   <?= $locked ? 'disabled' : '' ?>>
          <?php endif; ?>
          <small class="hint">Last changed <?= e(ago($row['updated_at'] ?? null)) ?></small>
        </label>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>

  <?php if ($isAdmin): ?>
    <div class="panel">
      <button class="btn btn-primary" type="submit">Save settings</button>
    </div>
  <?php endif; ?>
</form>

<?php if ($isAdmin && $providers !== null): ?>
  <!--
    BYOK lives OUTSIDE the settings form above, and not as another row in it.

    A settings row is a key and a plaintext value, saved by the settings form,
    and would put a live credential through the same path as a site title —
    visible in the HTML, in the audit of who changed what, and in any future
    "export settings" feature. These values are encrypted before they are
    stored and are never rendered back, which the form above has no way to do.
  -->
  <div class="panel">
    <div class="panel-head">
      <h2>AI providers</h2>
    </div>

    <?php if (!$can_encrypt): ?>
      <div class="panel notice">
        <p>
          <strong>Keys cannot be saved yet.</strong> Set
          <code>CMS_ENCRYPTION_KEY</code> in <code>.env</code> and restart.
          There is deliberately no unencrypted fallback: a key stored in
          readable form is worse than no key at all.
        </p>
      </div>
    <?php endif; ?>

    <?php foreach ($providers as $name => $p): ?>
      <div class="field">
        <span class="label">
          <?= e($p['label']) ?>
          <code class="muted"><?= e($name) ?></code>
          <?php if ($p['undecryptable']): ?>
            <small class="admin-only">stored key unreadable</small>
          <?php elseif ($p['source'] === 'byok'): ?>
            <small class="admin-only">saved here</small>
          <?php elseif ($p['source'] === 'environment'): ?>
            <small class="hint">from .env</small>
          <?php else: ?>
            <small class="hint">not set</small>
          <?php endif; ?>
        </span>

        <div class="byok-row">
          <input type="password"
                 id="byok-<?= e($name) ?>"
                 placeholder="<?= $p['source'] === 'byok' ? '•••••••• (unchanged)' : 'Paste the API key' ?>"
                 autocomplete="off"
                 spellcheck="false"
                 <?= !$can_encrypt ? 'disabled' : '' ?>>
          <button type="button" class="btn btn-sm btn-primary"
                  data-action="save-key" data-provider="<?= e($name) ?>"
                  <?= !$can_encrypt ? 'disabled' : '' ?>>Save</button>
          <?php if ($p['source'] === 'byok'): ?>
            <button type="button" class="btn btn-sm"
                    data-action="clear-key" data-provider="<?= e($name) ?>">Remove</button>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>

    <p class="hint">
      A key saved here takes precedence over the same key in <code>.env</code>.
      Values are encrypted at rest and never displayed again — there is no
      "reveal", only "replace".
    </p>
    <output class="agent-output" id="byok-output"></output>
  </div>
<?php endif; ?>