<?php $f = $_GET['filter'] ?? ''; $n = static fn ($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.'); $dn = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun']; ?>
<?= partial('partials/page_head', ['title' => 'Compensation', 'subtitle' => 'Salaries, hourly rates and personal allowances for everyone on the payroll.', 'crumbs' => [['HR Admin'], ['Payroll', can('payroll.manage') ? '/payroll' : null], ['Compensation']], 'actions' => \Modules\Admin\Services\SettingsSchema::settingsLink('payroll')]) ?>
<?= partial('payroll::_tabs', ['active' => 'employees']) ?>
<div class="grid g4 mb">
  <div class="tile bg-lav"><span class="tile-ic"><?= icon('users') ?></span><div><div class="num"><?= (int) $stats['monthly'] + (int) $stats['hourly'] ?></div><div class="lbl">On payroll</div><div class="pay-sub">of <?= (int) $stats['active'] ?> active employees</div></div></div>
  <div class="tile bg-mint"><span class="tile-ic"><?= icon('calendar') ?></span><div><div class="num"><?= (int) $stats['monthly'] ?></div><div class="lbl">Monthly salaried</div></div></div>
  <div class="tile bg-sky"><span class="tile-ic"><?= icon('clock') ?></span><div><div class="num"><?= (int) $stats['hourly'] ?></div><div class="lbl">Hourly</div></div></div>
  <a class="tile bg-peach" href="<?= e(url('/payroll/employees?filter=missing')) ?>"><span class="tile-ic"><?= icon('alert') ?></span><div><div class="num"><?= (int) $stats['missing'] ?></div><div class="lbl">Not set up yet</div><div class="pay-sub">Salary base <?= e(money($stats['monthly_cost'])) ?>/mo</div></div></a>
</div>
<div class="card">
  <form class="pay-toolbar" method="get">
    <label class="pay-search"><span class="sr">Search</span><?= icon('search', 'sm') ?><input type="search" name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="Search name, email, code, department"></label>
    <select name="filter" data-autosubmit aria-label="Pay type"><option value="">Everyone</option><option value="monthly"<?= selected('monthly', $f) ?>>Monthly</option><option value="hourly"<?= selected('hourly', $f) ?>>Hourly</option><option value="missing"<?= selected('missing', $f) ?>>Not set up (<?= (int) $stats['missing'] ?>)</option></select>
    <?php if ($f !== '' || ($_GET['q'] ?? '') !== ''): ?><a class="btn sm ghost" href="<?= e(url('/payroll/employees')) ?>"><?= icon('x', 'sm') ?> Clear</a><?php endif ?>
  </form>
  <?php if (!$p['items']): ?><?= empty_state('No employees found', 'Try another search or filter.', 'empty-box', '<a class="btn ghost" href="'.e(url('/payroll/employees')).'">Show everyone</a>') ?><?php else: ?>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Employee</th><th>Pay type</th><th class="num">Current pay</th><th>Work week</th><th>Effective from</th><th class="num">Personal items</th><th><span class="sr">Actions</span></th></tr></thead>
    <tbody><?php foreach ($p['items'] as $u): $sc = $sched[(int) $u['id']] ?? null; ?>
      <tr>
        <td><div class="pay-who"><?= avatar($u['name'], $u['avatar'], 'sm') ?><div><a class="row-link small" href="<?= e(url('/payroll/employees/'.$u['id'])) ?>"><b><?= e($u['name']) ?></b></a><div class="xs muted"><?= e(implode(' · ', array_filter([$u['employee_code'] ?: $u['email'], $u['department']]))) ?></div></div></div></td>
        <td><?= $u['pay_type'] ? '<span class="pill pill-'.($u['pay_type'] === 'hourly' ? 'sky' : 'lav').'">'.e(ucfirst($u['pay_type'])).'</span>' : '<span class="pill pill-peach">Not set up</span>' ?></td>
        <td class="num"><?php if ($u['pay_type']): ?><b><?= e(money($u['amount'])) ?></b><span class="xs muted"><?= $u['pay_type'] === 'hourly' ? ' /h' : ' /mo' ?></span><?php else: ?><span class="muted">-</span><?php endif ?></td>
        <td class="small nowrap"><?php if ($sc): ?><?= e($n($sc['hours_per_day'])) ?> h/day<div class="xs muted">Off <?= e($sc['off_days'] ? implode(', ', array_map(static fn ($d) => $dn[$d], $sc['off_days'])) : 'none') ?></div><?php else: ?><span class="muted">-</span><?php endif ?></td>
        <td class="small nowrap"><?= e(fdate($u['effective_from'])) ?><?php if ($u['upcoming']): ?><div class="xs"><span class="pill pill-lemon">Revision <?= e(fdate($u['upcoming'])) ?></span></div><?php endif ?></td>
        <td class="num small"><?= (int) $u['comps'] ? (int) $u['comps'] : '<span class="muted">-</span>' ?></td>
        <td class="actions"><a class="btn sm <?= $u['pay_type'] ? 'ghost' : 'primary' ?>" href="<?= e(url('/payroll/employees/'.$u['id'])) ?>"><?= $u['pay_type'] ? 'Manage' : icon('plus', 'sm').' Set up' ?></a></td>
      </tr>
    <?php endforeach ?></tbody>
  </table></div>
  <?= pager($p) ?>
  <?php endif ?>
</div>
