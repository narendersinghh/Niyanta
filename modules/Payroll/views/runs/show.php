<?php
use Modules\Payroll\Services\PayrollService;
$label = PayrollService::monthLabel($run['period_month']);
$st = $run['status'];
$claimsOn = feature_on('reimbursements');
$actions = '';
$edited = (int) $totals['edited'];
if ($st === 'draft') {
  $actions .= '<form method="post" action="'.e(url('/payroll/runs/'.$run['id'].'/delete')).'" data-confirm="Delete this draft run?'.($claimsOn ? ' Linked reimbursements are released for the next run.' : '').((int) $totals['covered'] ? ' Paid leave that covered missing hours is given back.' : '').'">'.csrf_field().'<button class="btn icon ghost" title="Delete draft" aria-label="Delete draft">'.icon('trash', 'sm').'</button></form>';
  $actions .= $edited ? '<button type="button" class="btn ghost" data-modal="pay-regen">'.icon('refresh', 'sm').' Regenerate</button>' : '<form method="post" action="'.e(url('/payroll/runs/'.$run['id'].'/generate')).'" data-confirm="Recalculate every payslip in this run from current data?">'.csrf_field().'<button class="btn ghost">'.icon('refresh', 'sm').' Regenerate</button></form>';
  if ($all) { $actions .= '<button type="button" class="btn ghost" data-modal="pay-bulk">'.icon('plus', 'sm').' Bonus or deduction</button>'; }
  $actions .= '<form method="post" action="'.e(url('/payroll/runs/'.$run['id'].'/finalize')).'" data-confirm="Finalize and publish '.e($label).' payroll? Payslips are locked, employees can see them and each employee is notified'.($run['notified_at'] ? ' that their payslip was updated' : '').'.">'.csrf_field().'<button class="btn primary">'.icon('lock', 'sm').' Finalize &amp; notify</button></form>'; }
else {
  $actions .= '<a class="btn ghost" href="'.e(url('/payroll/runs/'.$run['id'].'/export')).'">'.icon('download', 'sm').' Bank transfer CSV</a>';
  if ($st === 'finalized') { $actions .= '<button type="button" class="btn ghost" data-modal="pay-reopen">'.icon('history', 'sm').' Reopen</button><form method="post" action="'.e(url('/payroll/runs/'.$run['id'].'/paid')).'" data-confirm="Mark this run as paid? After that it can no longer be reopened.'.($claimsOn ? ' Linked reimbursements will be marked paid.' : '').'">'.csrf_field().'<button class="btn mint">'.icon('check-circle', 'sm').' Mark as paid</button></form>'; } }
$steps = ['draft' => ['Draft', 'Editable, hidden from employees'], 'finalized' => ['Published & notified', 'Locked, visible to employees'], 'paid' => ['Paid', 'Payment recorded']];
$idx = array_search($st, array_keys($steps), true);
$hn = static fn ($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
$n1 = static fn ($v) => rtrim(rtrim(number_format((float) $v, 1), '0'), '.');
?>
<?= partial('partials/page_head', ['title' => $label.' payroll', 'subtitle' => 'Working days: '.rtrim(rtrim(number_format((float) $run['working_days'], 1), '0'), '.').' · Created '.fdate($run['created_at']).($run['creator'] ? ' by '.$run['creator'] : '').($run['finalized_at'] ? ' · Finalized '.fdate($run['finalized_at']).($run['finalizer'] ? ' by '.$run['finalizer'] : '') : ''), 'crumbs' => [['HR Admin'], ['Payroll', '/payroll'], [$label]], 'actions' => $actions]) ?>
<div class="pay-stepper mb">
  <?php $i = 0; foreach ($steps as $k => $l): ?><?= $i ? '<span class="pay-step-sep'.($i <= $idx ? ' done' : '').'"></span>' : '' ?><span class="pay-step<?= $i < $idx || $st === 'paid' ? ' done' : ($i === $idx ? ' on' : '') ?>" title="<?= e($l[1]) ?>"><i><?= $i < $idx || $st === 'paid' ? icon('check', 'sm') : $i + 1 ?></i><span><?= e($l[0]) ?><small><?= e($l[1]) ?></small></span></span><?php $i++; endforeach ?>
</div>
<?php if ($st === 'draft'): ?><div class="alert info"><?= icon('info') ?><div>This run is a <b>draft</b>: employees cannot see these payslips and are not notified. Edit any payslip (bonus, one-time deduction, other lines, payable or overtime hours); every edit is audit-logged. Calculated figures update when you regenerate<?= $edited ? ', which keeps your manual edits unless you choose to discard them' : '' ?>. <b>Finalize &amp; notify</b> locks the run, publishes the payslips and notifies each employee.<?php if ($run['reopened_at']): ?> Reopened <?= e(fdatetime($run['reopened_at'])) ?><?= $reopener ? ' by '.e($reopener) : '' ?>.<?php endif ?></div></div>
<?php elseif ($st === 'finalized'): ?><div class="alert ok"><?= icon('check-circle') ?><div><b>Published</b><?= $run['notified_at'] ? ' and employees notified '.e(fdatetime($run['notified_at'])) : '' ?>. Payslips are locked. To correct one, <b>Reopen</b> the run (possible until it is marked as paid); employees then stop seeing it until you finalize again.</div></div>
<?php else: ?><div class="alert ok"><?= icon('lock') ?><div><b>Paid.</b> This run is closed and can no longer be reopened. Record corrections in the next run.</div></div><?php endif ?>
<?php if ((int) $totals['by_hours']): ?><div class="alert info"><?= icon('clock') ?><div><b>Pay from working hours</b> for <?= (int) $totals['by_hours'] ?> employee<?= (int) $totals['by_hours'] === 1 ? '' : 's' ?>: <?= e(PayrollService::FLEX_SHORT[$totals['flex_mode'] ?? 'monthly'] ?? PayrollService::FLEX_SHORT['monthly']) ?><?= (float) $totals['overtime'] > 0 ? ', overtime paid '.e(money($totals['overtime'])) : ', no overtime paid' ?>. Monthly salaries: hourly rate = salary ÷ expected hours of the month (paid leave and holidays excluded), pay = rate × payable hours. Open a payslip for the full breakdown.<?php if ($st === 'draft'): ?> Settings that changed since the last regeneration apply on the next one.<?php endif ?></div></div><?php endif ?>
<?php if ((int) $totals['covered']): ?><div class="alert info"><?= icon('umbrella') ?><div><b><?= (int) $totals['covered'] ?> employee<?= (int) $totals['covered'] === 1 ? '' : 's' ?></b> had missing hours covered by paid leave from their balance (counted as payable hours). Open a payslip to see the days used.</div></div><?php endif ?>
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
        <td><div class="pay-who"><?= avatar($s['name'], $s['avatar'], 'sm') ?><div><b class="small"><?= e($s['name']) ?></b><?php if ($s['edits'] !== null): ?> <span class="pill pill-lemon pay-mini" title="Edited by HR">Edited</span><?php endif ?><?php if ($s['shortage_cover'] !== null): ?> <span class="pill pill-sky pay-mini" title="Missing hours covered by paid leave">Leave cover</span><?php endif ?><div class="xs muted"><?= e(trim(($s['employee_code'] ?? '').' · '.($s['designation'] ?? ''), ' ·') ?: '-') ?></div></div></div></td>
        <td class="small"><?php $byH = $s['expected_hours'] !== null; $pay = $byH ? (float) ($s['payable_hours'] ?? min((float) $s['worked_hours'], (float) $s['expected_hours'])) : 0.0; $hr = $s['hour_rate'] !== null ? (float) $s['hour_rate'] : ($s['pay_type'] === 'hourly' ? (float) $s['rate'] : ((float) $s['expected_hours'] > 0 ? (float) $s['rate'] / (float) $s['expected_hours'] : 0.0)); ?>
          <?php if ($s['pay_type'] === 'hourly'): ?><span class="pill pill-sky">Hourly</span><span class="xs muted pay-basis"><?= e($hn($byH ? $pay : $s['worked_hours'])) ?> h × <?= e(money($s['rate'])) ?><?= $byH ? ' · '.e($hn($s['worked_hours'])).' worked of '.e($hn($s['expected_hours'])).' h expected' : '' ?></span>
          <?php elseif ($byH): ?><span class="pill pill-lav">Monthly</span><span class="xs muted pay-basis"><?= e($hn($pay)) ?> of <?= e($hn($s['expected_hours'])) ?> h payable · <?= e(money(round($hr, 2))) ?>/h<?= (float) $s['worked_hours'] > $pay + 0.004 ? ' · '.e($hn($s['worked_hours'])).' h worked' : '' ?><?= (float) $s['unpaid_leave_days'] > 0 ? ' · '.e($n1($s['unpaid_leave_days'])).' LOP' : '' ?></span>
          <?php else: ?><span class="pill pill-lav">Monthly</span><span class="xs muted pay-basis"><?= e($n1($s['paid_days'])) ?>/<?= e($n1($s['working_days'])) ?> days<?= (float) $s['unpaid_leave_days'] > 0 ? ' · '.e($n1($s['unpaid_leave_days'])).' LOP' : '' ?></span><?php endif ?>
          <?php if ((float) $s['overtime_pay'] > 0): ?><span class="xs pay-basis pay-ot">+ <?= e($hn($s['overtime_hours'])) ?> h overtime · <?= e(money($s['overtime_pay'])) ?></span><?php endif ?></td>
        <td class="r nowrap"><?= e(money($s['base_pay'])) ?></td>
        <td class="r nowrap"><?= e(money(round((float) $s['gross'] - (float) $s['base_pay'] - ($claimsOn ? (float) $s['reimbursement_total'] : 0), 2))) ?></td>
        <?php if ($claimsOn): ?><td class="r nowrap"><?= (float) $s['reimbursement_total'] > 0 ? e(money($s['reimbursement_total'])) : '<span class="muted">-</span>' ?></td><?php endif ?>
        <td class="r nowrap"><?= e(money($s['gross'])) ?></td>
        <td class="r nowrap"><?= e(money($s['total_deductions'])) ?></td>
        <td class="r nowrap"><b><?= e(money($s['net'])) ?></b><?php if ($flag): ?><div class="xs pay-red"><?= icon('alert', 'sm') ?> capped</div><?php endif ?></td>
        <td class="actions"><?php if ($st === 'draft'): ?><a class="btn icon sm ghost" href="<?= e(url('/payroll/payslips/'.$s['id'].'/edit')) ?>" title="Edit payslip" aria-label="Edit payslip of <?= e($s['name']) ?>"><?= icon('edit', 'sm') ?></a><?php endif ?><a class="btn icon sm ghost" href="<?= e(url('/payslips/'.$s['id'])) ?>" title="View payslip"><?= icon('eye', 'sm') ?></a><a class="btn icon sm ghost" href="<?= e(url('/payslips/'.$s['id'].'/pdf')) ?>" title="Download PDF"><?= icon('download', 'sm') ?></a></td>
      </tr>
    <?php endforeach ?></tbody>
    <tfoot><tr><td colspan="2">Run total · <?= (int) $totals['n'] ?> employees</td><td class="r nowrap"><?= e(money($totals['base'])) ?></td><td class="r nowrap"><?= e(money($claimsOn ? $totals['allowances'] : (float) $totals['allowances'] + (float) $totals['reimb'])) ?></td><?php if ($claimsOn): ?><td class="r nowrap"><?= e(money($totals['reimb'])) ?></td><?php endif ?><td class="r nowrap"><?= e(money($totals['gross'])) ?></td><td class="r nowrap"><?= e(money($totals['deductions'])) ?></td><td class="r nowrap"><?= e(money($totals['net'])) ?></td><td></td></tr></tfoot>
  </table></div>
  <?= pager($p) ?>
  <?php endif ?>
</div>
<div class="card mt"><div class="card-head"><h3>Run note</h3><span class="xs muted">Internal, never shown to employees</span></div>
  <form method="post" action="<?= e(url('/payroll/runs/'.$run['id'].'/notes')) ?>" class="form pay-note"><?= csrf_field() ?><textarea name="notes" rows="2" maxlength="2000" placeholder="e.g. Diwali bonus approved by the director on 3 Nov"><?= e($run['notes'] ?? '') ?></textarea><button class="btn ghost sm"><?= icon('check', 'sm') ?> Save note</button></form>
</div>
<?php if ($st === 'draft' && $edited): ?>
<dialog class="modal" id="pay-regen"><form method="post" action="<?= e(url('/payroll/runs/'.$run['id'].'/generate')) ?>" class="form"><?= csrf_field() ?>
  <div class="modal-head"><h3>Regenerate payslips</h3><button type="button" class="btn icon ghost" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div>
  <div class="modal-body"><p class="small">Every payslip is recalculated from current salaries, components, leave and hours. <?= $edited ?> payslip<?= $edited === 1 ? ' has' : 's have' ?> manual edits.</p>
    <label class="check"><input type="radio" name="discard_edits" value="0" checked> <span><b>Keep manual edits</b><br><span class="xs muted">Added lines, overridden amounts and hours are applied again.</span></span></label>
    <label class="check mt"><input type="radio" name="discard_edits" value="1"> <span><b>Discard manual edits</b><br><span class="xs muted">Back to the calculated figures. The audit log keeps the old edits.</span></span></label></div>
  <div class="form-actions pay-modal-foot"><button type="button" class="btn ghost" data-close>Cancel</button><button class="btn primary"><?= icon('refresh', 'sm') ?> Regenerate</button></div>
</form></dialog>
<?php endif ?>
<?php if ($st === 'draft' && $all): ?>
<dialog class="modal" id="pay-bulk"><form method="post" action="<?= e(url('/payroll/runs/'.$run['id'].'/lines')) ?>" class="form"><?= csrf_field() ?>
  <div class="modal-head"><h3>Add a bonus or deduction</h3><button type="button" class="btn icon ghost" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div>
  <div class="modal-body"><div class="form-grid">
    <div class="field"><label for="pb-type">Kind</label><select id="pb-type" name="type"><?php foreach ($types as $tk => [$tl]): ?><option value="<?= e($tk) ?>"><?= e($tl) ?></option><?php endforeach ?></select></div>
    <div class="field"><label for="pb-amt">Amount</label><input id="pb-amt" name="amount" type="number" min="0.01" step="0.01" required></div>
    <div class="field full"><label for="pb-name">Line name</label><input id="pb-name" name="name" maxlength="120" placeholder="e.g. Diwali bonus"></div>
    <div class="field full"><label for="pb-note">Note (why)</label><input id="pb-note" name="note" maxlength="255" required minlength="3" placeholder="Shown in the audit log"></div>
    <div class="field full"><label>Employees</label><div class="pay-picks" data-pay-picks><label class="check xs"><input type="checkbox" data-pay-all> <b>Select all</b></label><?php foreach ($all as $a): ?><label class="check small"><input type="checkbox" name="slips[]" value="<?= (int) $a['id'] ?>"> <?= e($a['name']) ?></label><?php endforeach ?></div></div>
  </div></div>
  <div class="form-actions pay-modal-foot"><button type="button" class="btn ghost" data-close>Cancel</button><button class="btn primary"><?= icon('plus', 'sm') ?> Add line</button></div>
</form></dialog>
<?php endif ?>
<?php if ($st === 'finalized'): ?>
<dialog class="modal" id="pay-reopen"><form method="post" action="<?= e(url('/payroll/runs/'.$run['id'].'/reopen')) ?>" class="form"><?= csrf_field() ?>
  <div class="modal-head"><h3>Reopen <?= e($label) ?> payroll</h3><button type="button" class="btn icon ghost" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div>
  <div class="modal-body"><p class="small">The run goes back to <b>draft</b> so you can edit payslips. Employees cannot see them until you finalize again, and are then told their payslip was updated. Reopening is audit-logged.</p>
    <div class="field"><label for="ro-reason">Reason</label><textarea id="ro-reason" name="reason" rows="2" required minlength="3" maxlength="500" placeholder="e.g. Missed overtime for two engineers"></textarea></div></div>
  <div class="form-actions pay-modal-foot"><button type="button" class="btn ghost" data-close>Cancel</button><button class="btn primary"><?= icon('history', 'sm') ?> Reopen as draft</button></div>
</form></dialog>
<?php endif ?>
