<?php
/** @var array $result @var int $page @var array $me */

$items = $result['items'] ?? [];
$total = (int) ($result['total'] ?? 0);
$meId  = (int) ($me['id'] ?? 0);
$q     = $_GET;
?>

<div class="panel">
  <form class="filters" method="get" action="/admin/users">
    <input type="search" name="q" placeholder="Search name, username or email"
           value="<?= e($q['q'] ?? '') ?>" aria-label="Search users">
    <select name="role" aria-label="Role">
      <option value="">Any role</option>
      <?php foreach (['author', 'editor', 'admin'] as $r): ?>
        <option value="<?= e($r) ?>" <?= ($q['role'] ?? '') === $r ? 'selected' : '' ?>><?= e(ucfirst($r)) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn" type="submit">Filter</button>
    <a class="btn btn-ghost" href="/admin/users">Reset</a>
    <span class="spacer"></span>
    <button type="button" class="btn btn-primary" id="invite">Invite user</button>
  </form>
</div>

<div class="panel">
  <div class="panel-head"><h2><?= (int) $total ?> user<?= $total === 1 ? '' : 's' ?></h2></div>

  <?php if ($items === []): ?>
    <?= empty_state('No users match.', 'Try a different search.') ?>
  <?php else: ?>
    <table class="table">
      <thead>
        <tr>
          <th scope="col">User</th>
          <th scope="col">Role</th>
          <th scope="col">Status</th>
          <th scope="col">Last seen</th>
          <th scope="col"><span class="sr-only">Actions</span></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($items as $u): ?>
        <tr data-id="<?= (int) $u['id'] ?>">
          <td>
            <strong><?= e($u['display_name'] ?: $u['username']) ?></strong>
            <?php if ((int) $u['id'] === $meId): ?><span class="you">you</span><?php endif; ?>
            <small class="muted"><?= e($u['email']) ?></small>
          </td>
          <td>
            <select class="inline" data-action="set-role" data-id="<?= (int) $u['id'] ?>"
                    aria-label="Role for <?= e($u['username']) ?>">
              <?php foreach (['author', 'editor', 'admin'] as $r): ?>
                <option value="<?= e($r) ?>" <?= $u['role'] === $r ? 'selected' : '' ?>><?= e(ucfirst($r)) ?></option>
              <?php endforeach; ?>
            </select>
          </td>
          <td><?= badge((string) $u['status']) ?></td>
          <td class="muted"><?= e(ago($u['last_login_at'])) ?></td>
          <td class="actions">
            <?php if ((int) $u['id'] !== $meId): ?>
              <button type="button" class="btn btn-sm btn-danger"
                      data-action="delete-user" data-id="<?= (int) $u['id'] ?>">Remove</button>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?= pagination($page, $total, 50, '/admin/users?' . http_build_query(array_filter($q))) ?>
  <?php endif; ?>
</div>