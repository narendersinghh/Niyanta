<?= partial('partials/page_head', ['title' => 'Users', 'subtitle' => 'Everyone who can sign in to your workspace.', 'crumbs' => [['System'], ['Users']], 'actions' => can('admin.users.manage') ? '<a class="btn primary" href="'.e(url('/admin/users/create')).'">'.icon('user-plus', 'sm').' Add user</a>' : '']) ?>
<div class="grid g4 mb">
  <?php foreach ([['super_admin', 'Super Admins', 'dark', 'shield'], ['manager', 'Managers', 'lav', 'briefcase'], ['hr', 'HR', 'mint', 'id-card'], ['employee', 'Employees', 'lemon', 'users']] as [$slug, $label, $c, $ic]): ?>
    <div class="tile <?= $c === 'dark' ? 'dark' : 'bg-'.$c ?>"><span class="tile-ic"><?= icon($ic) ?></span><div><div class="num"><?= (int) ($stats[$slug] ?? 0) ?></div><div class="lbl"><?= e($label) ?></div></div></div>
  <?php endforeach ?>
</div>
<div class="card">
  <form class="filters" method="get">
    <input type="search" name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="Search name, email, code">
    <select name="role" data-autosubmit><option value="">All roles</option><?php foreach ($roles as $id => $n): ?><option value="<?= $id ?>"<?= selected($id, $_GET['role'] ?? '') ?>><?= e($n) ?></option><?php endforeach ?></select>
    <select name="status" data-autosubmit><option value="">Any status</option><?php foreach (['active', 'inactive', 'invited'] as $s): ?><option<?= selected($s, $_GET['status'] ?? '') ?>><?= $s ?></option><?php endforeach ?></select>
    <button class="btn ghost sm"><?= icon('filter', 'sm') ?> Filter</button>
  </form>
  <?php if (!$p['items']): ?><?= empty_state('No users found', 'Try another filter.', 'team') ?><?php else: ?>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>User</th><th>Role</th><th>Department</th><th>Reports to</th><th>Last sign in</th><th>Status</th><th></th></tr></thead>
    <tbody><?php foreach ($p['items'] as $u): ?>
      <tr>
        <td><div class="flex"><?= avatar($u['name'], $u['avatar'], 'sm') ?><div><b><?= e($u['name']) ?></b><div class="xs muted"><?= e($u['email']) ?></div></div></div></td>
        <td><?= badge($u['role_slug'] === 'super_admin' ? 'urgent' : 'review', $u['role_name']) ?></td>
        <td><?= e($u['department'] ?: '—') ?><div class="xs muted"><?= e($u['designation'] ?? '') ?></div></td>
        <td><?= e($u['manager_name'] ?? '—') ?></td>
        <td class="small muted"><?= e($u['last_login_at'] ? ago($u['last_login_at']) : 'Never') ?></td>
        <td><?= badge($u['status']) ?></td>
        <td class="actions"><?php if (can('admin.users.manage') && ($u['role_slug'] !== 'super_admin' || user()['role_slug'] === 'super_admin')): ?>
          <details class="dd"><summary class="btn icon sm ghost"><?= icon('more', 'sm') ?></summary><div class="dd-menu">
            <a href="<?= e(url('/admin/users/'.$u['id'].'/edit')) ?>"><?= icon('edit', 'sm') ?> Edit</a>
            <?php if ((int) $u['id'] !== (int) user()['id']): ?>
            <form method="post" action="<?= e(url('/admin/users/'.$u['id'].'/status')) ?>" data-confirm="Change this user's status?"><?= csrf_field() ?><button><?= icon($u['status'] === 'active' ? 'lock' : 'check', 'sm') ?> <?= $u['status'] === 'active' ? 'Deactivate' : 'Activate' ?></button></form>
            <form method="post" action="<?= e(url('/admin/users/'.$u['id'].'/sessions')) ?>"><?= csrf_field() ?><button><?= icon('log-out', 'sm') ?> Sign out everywhere</button></form>
            <?php endif ?>
          </div></details><?php endif ?></td>
      </tr>
    <?php endforeach ?></tbody>
  </table></div>
  <?= pager($p) ?>
  <?php endif ?>
</div>
