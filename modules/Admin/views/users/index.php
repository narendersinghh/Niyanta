<?php
$manage = can('admin.users.manage'); $sa = (user()['role_slug'] ?? '') === 'super_admin';
$filtered = $f['q'] !== '' || $f['role'] || $f['status'] !== '';
$colors = ['super_admin' => 'primary', 'manager' => 'lav', 'hr' => 'mint', 'employee' => 'lemon'];
$actions = ($sa ? '<a class="btn ghost" href="'.e(url('/admin/roles')).'">'.icon('key', 'sm').' Roles & access</a>' : '').($manage ? '<a class="btn primary" href="'.e(url('/admin/users/create')).'">'.icon('user-plus', 'sm').' Add user</a>' : '');
?>
<?= partial('partials/page_head', ['title' => 'Users', 'subtitle' => 'Everyone who can sign in to your workspace, their role and reporting line.', 'crumbs' => [['System'], ['Users']], 'actions' => $actions]) ?>
<nav class="role-chips" aria-label="Filter by role">
  <a class="role-chip<?= !$f['role'] ? ' on' : '' ?>" href="<?= e(url('/admin/users').query_merge(['role' => null, 'page' => null])) ?>"><span class="ic-chip sm" style="--c:var(--sky)"><?= icon('users', 'sm') ?></span>All <b><?= array_sum(array_column($roleStats, 'n')) ?></b></a>
  <?php foreach ($roleStats as $r): ?><a class="role-chip<?= (int) $f['role'] === (int) $r['id'] ? ' on' : '' ?>" href="<?= e(url('/admin/users').query_merge(['role' => $r['id'], 'page' => null])) ?>"><span class="ic-chip sm" style="--c:var(--<?= e($colors[$r['slug']] ?? 'pink') ?>)"><?= icon($r['slug'] === 'super_admin' ? 'shield' : ($r['slug'] === 'hr' ? 'id-card' : ($r['slug'] === 'manager' ? 'briefcase' : 'user')), 'sm') ?></span><?= e($r['name']) ?> <b><?= (int) $r['n'] ?></b></a><?php endforeach ?>
</nav>
<div class="card">
  <form class="toolbar" method="get" role="search">
    <?php if ($f['role']): ?><input type="hidden" name="role" value="<?= (int) $f['role'] ?>"><?php endif ?>
    <div class="tb-search grow-input"><?= icon('search', 'sm') ?><input type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Search name, email or employee code" aria-label="Search users"></div>
    <select name="status" data-autosubmit aria-label="Status"><option value="">Any status</option><?php foreach (['active' => 'Active', 'inactive' => 'Inactive', 'invited' => 'Invited'] as $s => $sl): ?><option value="<?= $s ?>"<?= selected($s, $f['status']) ?>><?= $sl ?></option><?php endforeach ?></select>
    <?php if ($filtered): ?><a class="btn ghost sm" href="<?= e(url('/admin/users')) ?>"><?= icon('x', 'sm') ?> Clear</a><?php endif ?>
    <span class="count"><?= number_format((int) $p['total']) ?> <?= (int) $p['total'] === 1 ? 'person' : 'people' ?></span>
  </form>
  <?php if (!$p['items']): ?><?= empty_state($filtered ? 'No users match' : 'No users yet', $filtered ? 'Try another search or clear the filters.' : 'Add your team to get started.', 'team', $manage && !$filtered ? '<a class="btn primary" href="'.e(url('/admin/users/create')).'">'.icon('user-plus', 'sm').' Add user</a>' : '') ?><?php else: ?>
  <div class="table-wrap"><table class="table users-table">
    <thead><tr><th>User</th><th>Role</th><th class="hide-sm">Team</th><th class="hide-sm">Reports to</th><th class="hide-sm">Last sign-in</th><th>Status</th><th aria-label="Actions"></th></tr></thead>
    <tbody><?php foreach ($p['items'] as $u): $me = (int) $u['id'] === (int) user()['id']; ?>
      <tr>
        <td><div class="user-cell"><?= avatar($u['name'], $u['avatar'], 'sm') ?><div class="who"><b><?= e($u['name']) ?><?= $me ? ' <span class="pill pill-lav">You</span>' : '' ?></b><div class="xs muted truncate"><?= e($u['email']) ?></div></div></div></td>
        <td><?= badge($u['role_slug'] === 'super_admin' ? 'urgent' : 'review', $u['role_name']) ?></td>
        <td class="hide-sm"><?= $u['designation'] ? e($u['designation']) : '<span class="muted">—</span>' ?><?= $u['department'] ? '<div class="xs muted">'.e($u['department']).'</div>' : '' ?></td>
        <td class="hide-sm small"><?= e($u['manager_name'] ?? '—') ?></td>
        <td class="hide-sm small muted nowrap"><?= e($u['last_login_at'] ? ago($u['last_login_at']) : 'Never') ?></td>
        <td><?= badge($u['status']) ?></td>
        <td class="actions"><?php if ($manage && ($u['role_slug'] !== 'super_admin' || $sa)): ?>
          <details class="dd"><summary class="btn icon sm ghost" aria-label="Actions for <?= e($u['name']) ?>"><?= icon('more', 'sm') ?></summary><div class="dd-menu">
            <a href="<?= e(url('/admin/users/'.$u['id'].'/edit')) ?>"><?= icon('edit', 'sm') ?> Edit</a>
            <?php if (!$me): ?>
            <form method="post" action="<?= e(url('/admin/users/'.$u['id'].'/status')) ?>" data-confirm="<?= $u['status'] === 'active' ? 'Deactivate '.e($u['name']).'? They are signed out and can no longer sign in.' : 'Activate '.e($u['name']).'?' ?>"><?= csrf_field() ?><button><?= icon($u['status'] === 'active' ? 'lock' : 'check', 'sm') ?> <?= $u['status'] === 'active' ? 'Deactivate' : 'Activate' ?></button></form>
            <form method="post" action="<?= e(url('/admin/users/'.$u['id'].'/sessions')) ?>" data-confirm="Sign <?= e($u['name']) ?> out on every device?"><?= csrf_field() ?><button><?= icon('log-out', 'sm') ?> Sign out everywhere</button></form>
            <?php endif ?>
          </div></details><?php endif ?></td>
      </tr>
    <?php endforeach ?></tbody>
  </table></div>
  <?= pager($p) ?>
  <?php endif ?>
</div>
