<?php
use Modules\Payroll\Services\PayrollService;
$label = PayrollService::monthLabel($run['period_month']);
$st = $run['status'];
$claimsOn = feature_on('reimbursements');
$actions = '';
if ($st === 'draft') {
  $actions .= '<form method="post" action="'.e(url('/payroll/runs/'.$run['id'].'/delete')).'" data-confirm="Delete this draft run?'.($claimsOn ? ' Linked reimbursements are released for the next run.' : '').'">'.csrf_field().'<button class="btn icon ghost" title="Delete draft" aria-label="Delete draft">'.icon('trash', 'sm').'</button></form>';
  $actions .= '<form method="post" action="'.e(url('/payroll/runs/'.$run['id'].'/generate')).'" data-confirm="Recalculate every payslip in this run from current data?">'.csrf_field().'<button class="btn ghost">'.icon('refresh', 'sm').' Regenerate</button></form>';
  $actions .= '<form method="post" action="'.e(url('/payroll/runs/'.$run['id'].'/finalize')).'" data-confirm="Finalize '.e($label).' payroll? Payslips will be locked and employees notified.">'.csrf_field().'<button class="btn primary">'.icon('lock', 'sm').' Finalize & notify</button></form>'; }
else {
  $actions .= '<a class="btn ghost" href="'.e(url('/payroll/runs/'.$run['id'].'/export')).'">'.icon('download', 'sm').' Bank transfer CSV</a>';
  if ($st === 'finalized') { $actions .= '<form method="post" action="'.e(url('/payroll/runs/'.$run['id'].'/paid')).'" data-confirm="Mark this run as paid?'.($claimsOn ? ' Linked reimbursements will be marked paid.' : '').'">'.csrf_field().'<button class="btn mint">'.icon('check-circle', 'sm').' Mark as paid</button></form>'; } }
$steps = ['draft' => 'Draft', 'finalized' => 'Finalized', 'paid' => 'Paid'];
$idx = array_search($st, array_keys($steps), true);
?>
<?= partial('partials/page_head', ['title' => $label.' payroll', 'subtitle' => 'Working days: '.rtrim(rtrim(number_format((float) $run['working_days'], 1), '0'), '.').' · Created '.fdate($run['created_at']).($run['creator'] ? ' by '.$run['creator'] : '').($run['finalized_at'] ? ' · Finalized '.fdate($run['finalized_at']).($run['finalizer'] ? ' by '.$run['finalizer'] : '') : ''), 'crumbs' => [['HR Admin'], ['Payroll', '/payroll'], [$label]], 'actions' => $actions]) ?>
<div class="pay-stepper mb">
  <?php $i = 0; foreach ($steps as $k => $l): ?><?= $i ? '<span class="pay-step-sep'.($i <= $idx ? ' done' : '').'"></span>' : '' ?><span class="pay-step<?= $i < $idx || $st === 'paid' ? ' done' : ($i === $idx ? ' on' : '') ?>"><i><?= $i < $idx || $st === 'paid' ? icon('check', 'sm') : $i + 1 ?></i><?= e($l) ?></span><?php $i++; endforeach ?>
</div>
<?php if ($st === 'draft'): ?><div class="alert info"><?= icon('info') ?><div>This run is a <b>draft</b>. Numbers update only when you regenerate. Employees cannot see their payslips until you finalize.</div></div><?php endif ?>
<?php if ((int) $totals['flagged']): ?><div class="alert error"><?= icon('alert') ?><div><b><?= (int) $totals['flagged'] ?> payslip<?= (int) $totals['flagged'] === 1 ? '' : 's' ?></b> had deductions larger than gross pay. Net pay was capped at zero, review the highlighted rows.</div></div><?php endif ?>
<?php if ($st === 'draft' && $missing): ?><div class="alert warn"><?= icon('alert') ?><div>No compensation set up for: <?php foreach ($missing as $i => $m): ?><?= $i ? ', ' : '' ?><?php if (can('payroll.compensation.manage')): ?><a class="b" href="<?= e(url('/payroll/employees/'.$m['id'])) ?>"><?= e($m['name']) ?></a><?php else: ?><?= e($m['name']) ?><?php endif ?><?php endforeach ?><?= count($missing) >= 12 ? ' and others' : '' ?>, they are excluded from this run.</div></div><?php endif ?>
<div class="grid g4 mb">
  <div class="tile bg-sky"><span class="tile-ic"><?= icon('users') ?></span><div><div class="num"><?= (int) $totals['n'] ?></div><div class="lbl">Employees<?= (int) $totals['hourly'] ? ' · '.(int) $totals['hourly'].' hourly' : '' ?></div></div></div>
  <div class="tile bg-mint"><span class="tile-ic"><?= icon('trend-up') ?></span><div><div class="num pay-num"><?= e(money($totals['gross'])) ?></div><div class="lbl">Gross earnings</div></div></div>
  <div class="tile bg-pink"><span class="tile-ic"><?= icon('minus') ?></span><div><div class="num pay-num"><?= e(money($totals['deductions'])) ?></div><div class="lbl">Deductions</div></div></div>
  <div class="tile bg-lav"><span class="tile-ic"><?= icon('wallet') ?></span><div><div class="num pay-num"><?= e(money($totals['net'])) ?></div><div class="lbl">Net payout</div></div></div>
</div>
<div class="card">
  <div class="card-head"><h3>Payslips</h3><span class="small muted"><?= (int) $p['total'] ?> shown</span></div>
  <form class="pay-toolbar" method="get"><label class="pay-search"><span class="sr">Search</span><?= icon('search', 'sm') ?><input type="search" name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="Search employee, code, department"></label><?php if (($_GET['q'] ?? '') !== ''): ?><a class="btn sm ghost" href="?"><?= icon('x', 'sm') ?> Clear</a><?php endif ?></form>
  <?php if (!$p['items']): ?>
    <?= empty_state(($_GET['q'] ?? '') !== '' ? 'No matching employees' : 'No payslips in this run', ($_GET['q'] ?? '') !== '' ? 'Try a different search.' : 'Add a salary or hourly rate under Compensation, then regenerate this draft.', 'empty-box', can('payroll.compensation.manage') && ($_GET['q'] ?? '') === '' ? '<a class="btn primary" href="'.e(url('/payroll/employees')).'">'.icon('sliders', 'sm').' Set up compensation</a>' : '') ?>
  <?php else: ?>
  <div class="table-wrap"><table class="table pay-table">
    <thead><tr><th>Employee</th><th>Pay basis</th><th class="r">Base pay</th><th class="r">Allowances</th><?php if ($claimsOn): ?><th class="r">Reimb.</th><?php endif ?><th class="r">Gross</th><th class="r">Deductions</th><th class="r">Net pay</th><th></th></tr></thead>
    <tbody><?php foreach ($p['items'] as $s): $flag = (float) $s['total_deductions'] > (float) $s['gross']; ?>
      <tr class="<?= $flag ? 'pay-flag' : '' ?>">
        <td><div class="pay-who"><?= avatar($s['name'], $s['avatar'], 'sm') ?><div><b class="small"><?= e($s['name']) ?></b><div class="xs muted"><?= e(trim(($s['employee_code'] ?? '').' · '.($s['designation'] ?? ''), ' ·') ?: '-') ?></div></div></div></td>
        <td class="small"><?php if ($s['pay_type'] === 'hourly'): ?><span class="pill pill-sky">Hourly</span><div class="xs muted"><?= e(number_format((float) $s['worked_hours'], 2)) ?> h × <?= e(money($s['rate'])) ?></div><?php else: ?><span class="pill pill-lav">Monthly</span><div class="xs muted"><?= e(rtrim(rtrim(number_format((float) $s['paid_days'], 1), '0'), '.')) ?>/<?= e(rtrim(rtrim(number_format((float) $s['working_days'], 1), '0'), '.')) ?> days<?= $s['expected_hours'] !== null ? ' · '.e(number_format((float) $s['worked_hours'], 1)).'/'.e(number_format((float) $s['expected_hours'], 1)).' h tracked' : '' ?><?= (float) $s['unpaid_leave_days'] > 0 ? ' · '.e(rtrim(rtrim(number_format((float) $s['unpaid_leave_days'], 1), '0'), '.')).' LOP' : '' ?></div><?php endif ?></td>
        <td class="r nowrap"><?= e(money($s['base_pay'])) ?></td>
        <td class="r nowrap"><?= e(money(round((float) $s['gross'] - (float) $s['base_pay'] - ($claimsOn ? (float) $s['reimbursement_total'] : 0), 2))) ?></td>
        <?php if ($claimsOn): ?><td class="r nowrap"><?= (float) $s['reimbursement_total'] > 0 ? e(money($s['reimbursement_total'])) : '<span class="muted">-</span>' ?></td><?php endif ?>
        <td class="r nowrap"><?= e(money($s['gross'])) ?></td>
        <td class="r nowrap"><?= e(money($s['total_deductions'])) ?></td>
        <td class="r nowrap"><b><?= e(money($s['net'])) ?></b><?php if ($flag): ?><div class="xs pay-red"><?= icon('alert', 'sm') ?> capped</div><?php endif ?></td>
        <td class="actions"><a class="btn icon sm ghost" href="<?= e(url('/payslips/'.$s['id'])) ?>" title="View payslip"><?= icon('eye', 'sm') ?></a><a class="btn icon sm ghost" href="<?= e(url('/payslips/'.$s['id'].'/pdf')) ?>" title="Download PDF"><?= icon('download', 'sm') ?></a></td>
      </tr>
    <?php endforeach ?></tbody>
    <tfoot><tr><td colspan="2">Run total · <?= (int) $totals['n'] ?> employees</td><td class="r nowrap"><?= e(money($totals['base'])) ?></td><td class="r nowrap"><?= e(money($claimsOn ? $totals['allowances'] : (float) $totals['allowances'] + (float) $totals['reimb'])) ?></td><?php if ($claimsOn): ?><td class="r nowrap"><?= e(money($totals['reimb'])) ?></td><?php endif ?><td class="r nowrap"><?= e(money($totals['gross'])) ?></td><td class="r nowrap"><?= e(money($totals['deductions'])) ?></td><td class="r nowrap"><?= e(money($totals['net'])) ?></td><td></td></tr></tfoot>
  </table></div>
  <?= pager($p) ?>
  <?php endif ?>
</div>
