<?php $id = $u['id']; $v = static fn (string $k, $d = '') => old($k, $u[$k] ?? $d); ?>
<?= partial('partials/page_head', ['title' => $id ? 'Edit user' : 'Add user', 'crumbs' => [['Users', '/admin/users'], [$id ? $u['name'] : 'New']]]) ?>
<form class="grid g-side" method="post" action="<?= e(url($id ? '/admin/users/'.$id : '/admin/users')) ?>"><?= csrf_field() ?>
  <div class="card form">
    <h3>Account</h3>
    <div class="form-grid">
      <div class="field"><label>Full name *</label><input name="name" value="<?= e($v('name')) ?>" required><?= error_for('name') ?></div>
      <div class="field"><label>Email *</label><input type="email" name="email" value="<?= e($v('email')) ?>" required><?= error_for('email') ?></div>
      <div class="field"><label>Phone</label><input name="phone" value="<?= e($v('phone')) ?>"><?= error_for('phone') ?></div>
      <div class="field"><label>Role *</label><select name="role_id" required><option value="">Select…</option><?php foreach ($roles as $rid => $rn): ?><option value="<?= $rid ?>"<?= selected($rid, $v('role_id')) ?>><?= e($rn) ?></option><?php endforeach ?></select><?= error_for('role_id') ?></div>
      <div class="field"><label>Status</label><select name="status"><option value="active"<?= selected('active', $v('status')) ?>>Active</option><option value="inactive"<?= selected('inactive', $v('status')) ?>>Inactive</option></select></div>
      <div class="field"><label><?= $id ? 'New password' : 'Password' ?></label><input type="password" name="password" autocomplete="new-password" minlength="10"><div class="hint"><?= $id ? 'Leave blank to keep the current password.' : 'Leave blank to email an invitation link instead.' ?></div><?= error_for('password') ?></div>
    </div>
    <h3 class="mt">Work details</h3>
    <div class="form-grid">
      <div class="field"><label>Employee code</label><input name="employee_code" value="<?= e($v('employee_code')) ?>"><?= error_for('employee_code') ?></div>
      <div class="field"><label>Joined on</label><input type="date" name="joined_on" value="<?= e($v('joined_on')) ?>"></div>
      <div class="field"><label>Designation</label><input name="designation" value="<?= e($v('designation')) ?>"></div>
      <div class="field"><label>Department</label><input name="department" value="<?= e($v('department')) ?>"></div>
      <div class="field full"><label>Reporting manager</label><select name="manager_id"><option value="">None</option><?php foreach ($managers as $mid => $mn): if ((int) $mid === (int) $id) { continue; } ?><option value="<?= $mid ?>"<?= selected($mid, $v('manager_id')) ?>><?= e($mn) ?></option><?php endforeach ?></select><div class="hint">Managers approve leave and timesheets of their direct reports.</div></div>
    </div>
    <div class="form-actions"><a class="btn ghost" href="<?= e(url('/admin/users')) ?>">Cancel</a><button class="btn primary"><?= icon('check', 'sm') ?> Save user</button></div>
  </div>
  <div class="stack">
    <div class="card soft-lav" style="border:0"><?= illus('team') ?><h4 class="mt">Roles at a glance</h4><p class="small mb0"><b>Super Admin</b> controls everything. <b>Manager</b> runs clients, projects & approvals. <b>HR</b> handles people, leave & payroll. <b>Employee</b> works on assigned tasks. Fine-tune in Roles & Access.</p></div>
  </div>
</form>
