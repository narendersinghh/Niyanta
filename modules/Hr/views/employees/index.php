<?php
$types = Modules\Hr\Controllers\EmployeeController::TYPES;
$actions = (can('hr.employees.manage') ? '<a class="btn ghost" href="'.e(url('/hr/checklists')).'">'.icon('clipboard', 'sm').' Checklist templates</a>' : '').(can('admin.users.manage') ? '<a class="btn primary" href="'.e(url('/admin/users/create')).'">'.icon('user-plus', 'sm').' Add employee</a>' : '');
?>
<?= partial('partials/page_head', ['title' => 'Employees', 'subtitle' => 'Profiles, onboarding progress and who is around today.', 'crumbs' => [['People'], ['Employees']], 'actions' => $actions]) ?>
<div class="grid g4 mb">
  <div class="tile bg-lav"><span class="tile-ic"><?= icon('users') ?></span><div><div class="num"><?= (int) $kpi['headcount'] ?></div><div class="lbl">Headcount</div></div><?= illus('team', 'corner') ?></div>
  <div class="tile bg-mint"><span class="tile-ic"><?= icon('user-plus') ?></span><div><div class="num"><?= (int) $kpi['joined'] ?></div><div class="lbl">Joined this month</div></div></div>
  <div class="tile bg-peach"><span class="tile-ic"><?= icon('plane') ?></span><div><div class="num"><?= (int) $kpi['out'] ?></div><div class="lbl">On leave today</div></div></div>
  <div class="tile bg-sky"><span class="tile-ic"><?= icon('clipboard') ?></span><div><div class="num"><?= (int) $kpi['onboarding'] ?></div><div class="lbl">Onboarding in progress</div></div></div>
</div>
<div class="card">
  <form class="filters" method="get">
    <input type="search" name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="Search name, email, code, role">
    <select name="department" data-autosubmit><option value="">All departments</option><?php foreach ($departments as $d): ?><option<?= selected($d, $_GET['department'] ?? '') ?>><?= e($d) ?></option><?php endforeach ?></select>
    <select name="type" data-autosubmit><option value="">Any employment</option><?php foreach ($types as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($k, $_GET['type'] ?? '') ?>><?= e($l) ?></option><?php endforeach ?></select>
    <select name="status" data-autosubmit><option value="">Any status</option><?php foreach (['active', 'inactive', 'invited'] as $s): ?><option value="<?= $s ?>"<?= selected($s, $_GET['status'] ?? '') ?>><?= ucfirst($s) ?></option><?php endforeach ?></select>
    <button class="btn ghost sm"><?= icon('filter', 'sm') ?> Filter</button>
    <?php if (array_filter([$_GET['q'] ?? '', $_GET['department'] ?? '', $_GET['type'] ?? '', $_GET['status'] ?? ''])): ?><a class="btn sm soft" href="<?= e(url('/hr/employees')) ?>"><?= icon('x', 'sm') ?> Clear</a><?php endif ?>
  </form>
  <?php if (!$p['items']): ?><?= empty_state('No employees found', 'Try a different search or filter.', 'team') ?><?php else: ?>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Employee</th><th>Code</th><th>Department</th><th>Type</th><th>Joined</th><th>Reports to</th><th>Onboarding</th><th>Status</th></tr></thead>
    <tbody><?php foreach ($p['items'] as $u): $pr = $prog[(int) $u['id']]['onboarding'] ?? null; $pct = $pr ? (int) round($pr['done'] / max(1, $pr['total']) * 100) : null; ?>
      <tr>
        <td><a class="flex" href="<?= e(url('/hr/employees/'.$u['id'])) ?>"><?= avatar($u['name'], $u['avatar'], 'sm') ?><div><b class="row-link"><?= e($u['name']) ?></b><div class="xs muted"><?= e($u['email']) ?></div></div></a></td>
        <td class="mono small"><?= e($u['employee_code'] ?: '—') ?></td>
        <td><?= e($u['department'] ?: '—') ?><div class="xs muted"><?= e($u['designation'] ?? '') ?></div></td>
        <td><span class="pill pill-gray"><?= e($types[$u['employment_type'] ?? 'full_time'] ?? 'Full-time') ?></span></td>
        <td class="small nowrap"><?= fdate($u['joined_on']) ?></td>
        <td class="small"><?= e($u['manager_name'] ?? '—') ?></td>
        <td style="min-width:120px"><?php if ($pct === null): ?><span class="xs muted">Not started</span><?php else: ?><div class="flex"><div class="progress grow"><span style="width:<?= $pct ?>%"></span></div><span class="xs b"><?= $pct ?>%</span></div><?php endif ?></td>
        <td class="nowrap"><?= badge($u['status']) ?><?php if (isset($out[(int) $u['id']])): ?> <span class="pill pill-peach"><?= icon('plane', 'sm') ?> Out</span><?php endif ?><?php if ($u['exit_date']): ?><div class="xs muted">Exit <?= fdate($u['exit_date']) ?></div><?php endif ?></td>
      </tr>
    <?php endforeach ?></tbody>
  </table></div>
  <?= pager($p) ?>
  <?php endif ?>
</div>
