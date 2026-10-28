<?php $f = $_GET['filter'] ?? ''; ?>
<?= partial('partials/page_head', ['title' => 'Compensation', 'subtitle' => 'Salaries, hourly rates and personal allowances for everyone on the payroll.', 'crumbs' => [['Payroll', can('payroll.manage') ? '/payroll' : null], ['Compensation']], 'actions' => '<a class="btn ghost" href="'.e(url('/payroll/components')).'">'.icon('sliders', 'sm').' Allowances & deductions</a>']) ?>
<?= partial('payroll::_tabs', ['active' => 'employees']) ?>
<div class="grid g4 mb">
  <div class="tile bg-lav"><span class="tile-ic"><?= icon('users') ?></span><div><div class="num"><?= (int) $stats['monthly'] + (int) $stats['hourly'] ?></div><div class="lbl">On payroll of <?= (int) $stats['active'] ?> active</div></div></div>
  <div class="tile bg-mint"><span class="tile-ic"><?= icon('calendar') ?></span><div><div class="num"><?= (int) $stats['monthly'] ?></div><div class="lbl">Monthly salaried</div></div></div>
  <div class="tile bg-sky"><span class="tile-ic"><?= icon('clock') ?></span><div><div class="num"><?= (int) $stats['hourly'] ?></div><div class="lbl">Hourly</div></div></div>
  <div class="tile dark pay-tile"><span class="tile-ic"><?= icon('wallet') ?></span><div><div class="num pay-num"><?= e(money($stats['monthly_cost'])) ?></div><div class="lbl">Monthly salary base</div></div><?= illus('money-wallet', 'corner') ?></div>
</div>
<div class="card">
  <form class="filters" method="get">
    <input type="search" name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="Search name, email, code, department">
    <select name="filter" data-autosubmit><option value="">Everyone</option><option value="monthly"<?= selected('monthly', $f) ?>>Monthly</option><option value="hourly"<?= selected('hourly', $f) ?>>Hourly</option><option value="missing"<?= selected('missing', $f) ?>>Not set up (<?= (int) $stats['missing'] ?>)</option></select>
    <button class="btn ghost sm"><?= icon('filter', 'sm') ?> Filter</button>
  </form>
  <?php if (!$p['items']): ?><?= empty_state('No employees found', 'Try another search or filter.', 'empty-box') ?><?php else: ?>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Employee</th><th>Department</th><th>Pay type</th><th class="r">Current pay</th><th>Effective from</th><th>Personal items</th><th></th></tr></thead>
    <tbody><?php foreach ($p['items'] as $u): ?>
      <tr>
        <td><div class="flex"><?= avatar($u['name'], $u['avatar'], 'sm') ?><div><a class="row-link" href="<?= e(url('/payroll/employees/'.$u['id'])) ?>"><?= e($u['name']) ?></a><div class="xs muted"><?= e($u['employee_code'] ?: $u['email']) ?></div></div></div></td>
        <td><?= e($u['department'] ?: '—') ?><div class="xs muted"><?= e($u['designation'] ?? '') ?></div></td>
        <td><?= $u['pay_type'] ? badge($u['pay_type'] === 'hourly' ? 'in_progress' : 'review', ucfirst($u['pay_type'])) : '<span class="pill pill-peach">Not set up</span>' ?></td>
        <td class="r nowrap"><?php if ($u['pay_type']): ?><b><?= e(money($u['amount'])) ?></b><span class="xs muted"><?= $u['pay_type'] === 'hourly' ? ' /h' : ' /mo' ?></span><?php else: ?><span class="muted">—</span><?php endif ?></td>
        <td class="small"><?= e(fdate($u['effective_from'])) ?><?php if ($u['upcoming']): ?><div class="xs"><span class="pill pill-lemon">Revision <?= e(fdate($u['upcoming'])) ?></span></div><?php endif ?></td>
        <td class="small"><?= (int) $u['comps'] ? (int) $u['comps'].' active' : '<span class="muted">—</span>' ?></td>
        <td class="actions"><a class="btn sm <?= $u['pay_type'] ? 'ghost' : 'soft' ?>" href="<?= e(url('/payroll/employees/'.$u['id'])) ?>"><?= $u['pay_type'] ? 'Manage' : icon('plus', 'sm').' Set up' ?></a></td>
      </tr>
    <?php endforeach ?></tbody>
  </table></div>
  <?= pager($p) ?>
  <?php endif ?>
</div>
