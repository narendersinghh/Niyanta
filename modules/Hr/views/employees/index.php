<?php use Modules\Hr\Services\LeaveService as LS;
$types = Modules\Hr\Controllers\EmployeeController::TYPES;
$filtered = array_filter([$_GET['q'] ?? '', $_GET['department'] ?? '', $_GET['type'] ?? '', $_GET['status'] ?? '']);
$actions = (can('admin.users.manage') ? '<a class="btn primary" href="'.e(url('/admin/users/create')).'">'.icon('user-plus', 'sm').' Add employee</a>' : '');
?>
<?= partial('partials/page_head', ['title' => 'Employees', 'subtitle' => 'Profiles, work schedules, onboarding progress'.(feature_on('leave') ? ' and who is around today' : '').'.', 'crumbs' => [['HR Admin'], ['Employees']], 'actions' => '<a class="btn ghost" href="'.e(url(profile_url())).'">'.icon('user', 'sm').' My profile</a>'.$actions]) ?>
<?= partial('hr::partials/emp_tabs', ['active' => 'employees']) ?>
<div class="grid <?= feature_on('leave') ? 'g4' : 'g3' ?> mb">
  <div class="tile bg-lav"><span class="tile-ic"><?= icon('users') ?></span><div><div class="num"><?= (int) $kpi['headcount'] ?></div><div class="lbl">Active headcount</div></div></div>
  <div class="tile bg-mint"><span class="tile-ic"><?= icon('user-plus') ?></span><div><div class="num"><?= (int) $kpi['joined'] ?></div><div class="lbl">Joined this month</div></div></div>
  <?php if (feature_on('leave')): ?><div class="tile bg-peach"><span class="tile-ic"><?= icon('plane') ?></span><div><div class="num"><?= (int) $kpi['out'] ?></div><div class="lbl">On leave today</div></div></div><?php endif ?>
  <div class="tile bg-sky"><span class="tile-ic"><?= icon('clipboard') ?></span><div><div class="num"><?= (int) $kpi['onboarding'] ?></div><div class="lbl">Onboarding in progress</div></div></div>
</div>
<div class="card">
  <form class="hr-toolbar" method="get">
    <label class="hr-search"><span class="sr">Search</span><?= icon('search', 'sm') ?><input type="search" name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="Search name, email, code, role"></label>
    <select name="department" data-autosubmit aria-label="Department"><option value="">All departments</option><?php foreach ($departments as $d): ?><option<?= selected($d, $_GET['department'] ?? '') ?>><?= e($d) ?></option><?php endforeach ?></select>
    <select name="type" data-autosubmit aria-label="Employment type"><option value="">Any employment</option><?php foreach ($types as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($k, $_GET['type'] ?? '') ?>><?= e($l) ?></option><?php endforeach ?></select>
    <select name="status" data-autosubmit aria-label="Status"><option value="">Any status</option><?php foreach (['active', 'inactive', 'invited'] as $s): ?><option value="<?= $s ?>"<?= selected($s, $_GET['status'] ?? '') ?>><?= ucfirst($s) ?></option><?php endforeach ?></select>
    <?php if ($filtered): ?><a class="btn sm ghost" href="<?= e(url('/hr/employees')) ?>"><?= icon('x', 'sm') ?> Clear</a><?php endif ?>
    <span class="small muted hr-push"><?= (int) $p['total'] ?> people</span>
  </form>
  <?php if (!$p['items']): ?><?= empty_state($filtered ? 'No employees match' : 'No employees yet', $filtered ? 'Try a different search or clear the filters.' : 'Invite your first team member to get started.', 'people-hr', $filtered ? '<a class="btn ghost" href="'.e(url('/hr/employees')).'">Clear filters</a>' : (can('admin.users.manage') ? '<a class="btn primary" href="'.e(url('/admin/users/create')).'">'.icon('user-plus', 'sm').' Add employee</a>' : '')) ?><?php else: ?>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Employee</th><th>Department</th><th>Type</th><th>Work week</th><th>Joined</th><th>Onboarding</th><th>Status</th></tr></thead>
    <tbody><?php foreach ($p['items'] as $u): $uid = (int) $u['id']; $pr = $prog[$uid]['onboarding'] ?? null; $pct = $pr ? (int) round($pr['done'] / max(1, $pr['total']) * 100) : null; $sc = $sched[$uid] ?? null; ?>
      <tr>
        <td><a class="hr-who" href="<?= e(url('/hr/employees/'.$uid)) ?>"><?= avatar($u['name'], $u['avatar'], 'sm') ?><div><b class="small row-link"><?= e($u['name']) ?></b><div class="xs muted"><?= e($u['employee_code'] ? $u['employee_code'].' · '.$u['email'] : $u['email']) ?></div></div></a></td>
        <td class="small"><?= e($u['department'] ?: ($u['designation'] ?: '-')) ?><div class="xs muted"><?= e(implode(' · ', array_filter([$u['department'] ? $u['designation'] : null, $u['manager_name'] ? 'reports to '.$u['manager_name'] : null]))) ?></div></td>
        <td><span class="pill pill-gray"><?= e($types[$u['employment_type'] ?? 'full_time'] ?? 'Full-time') ?></span></td>
        <td class="small nowrap"><?php if ($sc): ?><?= e(LS::num($sc['hours_per_day'])) ?> h/day<div class="xs muted">Off <?= e(LS::daysLabel($sc['off_days'], $sc['saturday'] ?? null)) ?><?= $sc['custom_hours'] || $sc['custom_days'] ? ' · <span class="pill pill-lav">Custom</span>' : '' ?></div><?php endif ?></td>
        <td class="small nowrap"><?= fdate($u['joined_on']) ?></td>
        <td class="hr-prog"><?php if ($pct === null): ?><span class="xs muted">Not started</span><?php else: ?><div class="flex"><div class="progress grow" role="progressbar" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100"><span style="width:<?= $pct ?>%"></span></div><span class="xs b"><?= $pct ?>%</span></div><?php endif ?></td>
        <td class="nowrap"><?= badge($u['status']) ?><?php if (isset($out[$uid])): ?> <span class="pill pill-peach"><?= icon('plane', 'sm') ?> Out</span><?php endif ?><?php if ($u['exit_date']): ?><div class="xs muted">Exit <?= fdate($u['exit_date']) ?></div><?php endif ?></td>
      </tr>
    <?php endforeach ?></tbody>
  </table></div>
  <?= pager($p) ?>
  <?php endif ?>
</div>
