<?php
use Modules\Payroll\Services\PayrollService;
$num = static fn ($v) => rtrim(rtrim(number_format((float) $v, 2), '0'), '.');
?>
<?php $dn = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun']; ?>
<?= partial('partials/page_head', ['title' => $emp['name'], 'subtitle' => trim(($emp['designation'] ?? '').($emp['department'] ? ' · '.$emp['department'] : ''), ' ·') ?: 'Compensation & pay components', 'crumbs' => [['HR Admin'], ['Compensation', '/payroll/employees'], [$emp['name']]],
  'actions' => (can('hr.employees.view') ? '<a class="btn ghost" href="'.e(url('/hr/employees/'.$emp['id'].'?tab=schedule')).'">'.icon('clock', 'sm').' Work schedule</a>' : '').'<button class="btn primary" type="button" data-modal="add-rev">'.icon('plus', 'sm').' Add revision</button>']) ?>
<div class="card pay-banner mb">
  <div class="pay-banner-bg"></div>
  <div class="pay-banner-body">
    <?= avatar($emp['name'], $emp['avatar'], 'lg') ?>
    <div class="pay-banner-id"><h2><?= e($emp['name']) ?></h2><div class="muted small"><?= e($emp['email']) ?><?= $emp['employee_code'] ? ' · '.e($emp['employee_code']) : '' ?> · <?= e($emp['role_name']) ?></div></div>
    <div class="pay-current <?= $current ? '' : 'none' ?>"><?php if ($current): ?><span class="xs muted">Current <?= $current['pay_type'] === 'hourly' ? 'hourly rate' : 'monthly salary' ?></span><b><?= e(money($current['amount'])) ?></b><span class="xs muted">since <?= e(fdate($current['effective_from'])) ?></span><?php else: ?><span class="xs muted">Not on payroll yet</span><b>-</b><span class="xs muted">Add a revision to start</span><?php endif ?></div>
  </div>
  <div class="pay-facts">
    <div><span class="xs">Status</span><b><?= badge($emp['status']) ?></b></div>
    <div><span class="xs">Joined</span><b><?= e(fdate($emp['joined_on'])) ?></b></div>
    <div><span class="xs">Bank</span><b><?= $emp['bank_name'] ? e($emp['bank_name']).($emp['has_account'] ? '' : ' · no acc. no.') : '<span class="muted">Not provided</span>' ?></b></div>
    <div><span class="xs">Work week</span><b><?php if ($sched): ?><?= e(rtrim(rtrim(number_format((float) $sched['hours_per_day'], 2, '.', ''), '0'), '.')) ?> h/day · <?= e(rtrim(rtrim(number_format((float) $days['own'], 1, '.', ''), '0'), '.')) ?> days in <?= e(date('M')) ?><?php else: ?>-<?php endif ?></b></div>
  </div>
</div>
<?php if ($sched && $days && $days['own'] != $days['company']): ?><div class="alert info"><?= icon('info', 'sm') ?><div><?= e(explode(' ', $emp['name'])[0]) ?> has a personal work week (off <?= e($sched['off_days'] ? implode(', ', array_map(static fn ($d) => $dn[$d], $sched['off_days'])) : 'none') ?>): monthly salary is prorated on <b><?= e(rtrim(rtrim(number_format((float) $days['own'], 1, '.', ''), '0'), '.')) ?></b> working days this month instead of the company’s <?= e(rtrim(rtrim(number_format((float) $days['company'], 1, '.', ''), '0'), '.')) ?>.</div></div><?php endif ?>
<div class="grid g-side pay-top">
  <div class="stack">
    <div class="card">
      <div class="card-head"><h3>Compensation history</h3><span class="muted small"><?= count($history) ?> revision<?= count($history) === 1 ? '' : 's' ?></span></div>
      <?php if (!$history): ?><?= empty_state('No compensation yet', 'Add a monthly salary or an hourly rate to include this person in payroll runs.', 'money-wallet', '<button class="btn primary" type="button" data-modal="add-rev">'.icon('plus', 'sm').' Add revision</button>') ?><?php else: ?>
      <div class="timeline">
        <?php foreach ($history as $h): $isCur = $current && (int) $current['id'] === (int) $h['id']; $future = $h['effective_from'] > date('Y-m-d'); ?>
        <div class="tl-item"><span class="tl-dot <?= $isCur ? 'bg-mint' : ($future ? 'bg-lemon' : 'bg-gray') ?>"><?= icon($future ? 'clock' : 'check') ?></span>
          <div class="tl-card flex between wrap"><div><b><?= e(money($h['amount'])) ?></b> <span class="muted small"><?= $h['pay_type'] === 'hourly' ? 'per hour' : 'per month' ?></span> <?= $isCur ? '<span class="pill pill-mint">Current</span>' : ($future ? '<span class="pill pill-lemon">Scheduled</span>' : '') ?>
            <div class="xs muted">Effective <?= e(fdate($h['effective_from'])) ?><?= $h['creator'] ? ' · by '.e($h['creator']) : '' ?><?= $h['note'] ? ' · '.e($h['note']) : '' ?></div></div>
            <form method="post" action="<?= e(url('/payroll/compensations/'.$h['id'].'/delete')) ?>" data-confirm="Delete this revision? Past payslips are not changed."><?= csrf_field() ?><button class="btn icon sm ghost" title="Delete revision"><?= icon('trash', 'sm') ?></button></form></div></div>
        <?php endforeach ?>
      </div><?php endif ?>
    </div>
    <div class="card">
      <div class="card-head"><h3>Personal allowances & deductions</h3><button class="btn sm soft" type="button" data-modal="add-personal"><?= icon('plus', 'sm') ?> Add</button></div>
      <?= partial('payroll::components/_rows', ['rows' => $personal, 'empty' => 'No employee-specific items. Company-wide components still apply.']) ?>
    </div>
    <div class="card">
      <div class="card-head"><h3>Company-wide components</h3><?php if ($global): ?><a class="small muted" href="<?= e(url('/payroll/components')) ?>">Manage <?= icon('arrow-right', 'sm') ?></a><?php endif ?></div>
      <?php if (!$global): ?><p class="muted small mb0">None configured.</p><?php else: ?><div class="chips"><?php foreach ($global as $g): ?><span class="chip"><span class="dotc <?= $g['kind'] === 'allowance' ? 'bg-mint' : 'bg-pink' ?>"></span><?= e($g['name']) ?> · <?= $g['calc'] === 'percent' ? e($num($g['value'])).'%' : e(money($g['value'])) ?></span><?php endforeach ?></div><?php endif ?>
    </div>
  </div>
  <div class="stack">
    <?php if ($est): $hl = $est['expected_hours'] !== null; $hn = static fn ($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.').' h'; $dn1 = static fn ($v) => rtrim(rtrim(number_format((float) $v, 1, '.', ''), '0'), '.'); ?>
    <div class="card">
      <div class="card-head"><h3>Pay calculation</h3><span class="muted small"><?= e(date('F Y')) ?> so far</span></div>
      <div class="pay-calc">
        <div class="pay-line"><span>Pay basis</span><span><?= $current['pay_type'] === 'hourly' ? 'Hourly rate × hours' : ($hl ? 'Salary from tracked time' : 'Monthly salary by days') ?></span></div>
        <?php if ($hl): ?>
        <div class="pay-line"><span>Flexible hours</span><span><?= e(PayrollService::FLEX_SHORT[$est['flex_mode']] ?? '') ?></span></div>
        <div class="pay-line"><span>Overtime</span><span><?= ($m = PayrollService::overtimeMultiplier()) ? 'Paid at '.e(PayrollService::multLabel($m)) : 'Not paid' ?></span></div>
        <div class="pay-line"><span>Expected hours</span><span><?= e($hn($est['expected_hours'])) ?></span></div>
        <div class="pay-line"><span><?= $current['pay_type'] === 'hourly' ? 'Hourly rate' : 'Hourly rate this month' ?></span><span><?= e(money(round((float) $est['hour_rate'], 2))) ?></span></div>
        <div class="pay-line"><span>Worked so far (approved)</span><span><?= e($hn($est['worked_hours'])) ?></span></div>
        <div class="pay-line"><span>Payable so far</span><span><?= e($hn($est['payable_hours'])) ?><?= (float) $est['overtime_hours'] > 0 ? ' + '.e($hn($est['overtime_hours'])).' OT' : '' ?></span></div>
        <?php else: ?>
        <div class="pay-line"><span>Working days</span><span><?= e($dn1($est['working_days'])) ?></span></div>
        <?php if ((float) $est['unpaid_leave_days'] > 0): ?><div class="pay-line"><span>Unpaid leave</span><span><?= e($dn1($est['unpaid_leave_days'])) ?> days</span></div><?php endif ?>
        <?php endif ?>
        <div class="pay-line total"><span>Base pay so far</span><span><?= e(money((float) $est['base_pay'] + (float) $est['overtime_pay'])) ?></span></div>
      </div>
      <p class="xs muted mt mb0"><?= $hl ? 'Expected hours: hours per day on the work schedule for each working day (Saturday rule and fixed holidays applied) minus approved paid leave. Allowances and deductions are added in the payroll run.' : (PayrollService::hoursPay() ? 'Salary from tracked time is off in Payroll settings: the salary is paid in full minus unpaid leave days.' : 'Attendance & time tracking is switched off: the salary is paid in full minus unpaid leave days.') ?><?php if (can('__super_admin')): ?> <a href="<?= e(url('/admin/settings/payroll')) ?>">Payroll settings</a><?php endif ?></p>
    </div>
    <?php endif ?>
    <div class="card">
      <div class="card-head"><h3>Recent payslips</h3></div>
      <?php if (!$slips): ?><p class="muted small mb0">No payslips yet.</p><?php else: ?><div class="list"><?php foreach ($slips as $s): ?>
        <a class="list-item" href="<?= e(url('/payslips/'.$s['id'])) ?>"><span class="pay-ic peach"><?= icon('file-text', 'sm') ?></span><div class="grow"><b class="small"><?= e(date('F Y', strtotime($s['period_month']))) ?></b><div class="meta">Gross <?= e(money($s['gross'])) ?></div></div><div class="right"><b class="small"><?= e(money($s['net'])) ?></b><div><?= badge($s['status']) ?></div></div></a>
      <?php endforeach ?></div><?php endif ?>
    </div>
  </div>
</div>
<dialog class="modal" id="add-personal"><div class="modal-head"><h3>Add item for <?= e($emp['name']) ?></h3><button class="btn icon sm ghost" type="button" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div><div class="modal-body"><?= partial('payroll::components/_form', ['c' => null, 'uid' => (int) $emp['id']]) ?></div></dialog>
<dialog class="modal" id="add-rev"<?= errors() && old('amount', null) !== null ? ' data-open' : '' ?>><div class="modal-head"><h3>New compensation revision</h3><button class="btn icon sm ghost" type="button" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div>
  <form class="modal-body form" method="post" action="<?= e(url('/payroll/employees/'.$emp['id'].'/compensation')) ?>"><?= csrf_field() ?>
        <?php $pt = old('pay_type', $current['pay_type'] ?? 'monthly') === 'hourly' ? 'hourly' : 'monthly'; $byHours = PayrollService::hoursPay(); ?>
        <div class="field"><label for="pay_type">Pay type</label><select id="pay_type" name="pay_type" data-pay-type><option value="monthly"<?= selected('monthly', $pt) ?>>Monthly salary</option><option value="hourly"<?= selected('hourly', $pt) ?>>Hourly rate</option></select><?= error_for('pay_type') ?></div>
        <div class="field"><label for="amount"><span data-pay-label="monthly"<?= $pt === 'hourly' ? ' class="hide"' : '' ?>>Monthly salary *</span><span data-pay-label="hourly"<?= $pt === 'monthly' ? ' class="hide"' : '' ?>>Hourly rate *</span></label><div class="pay-unit"><input id="amount" type="number" name="amount" step="0.01" min="0.01" value="<?= e(old('amount')) ?>" placeholder="<?= e($current ? $num($current['amount']) : '0.00') ?>" required><span data-pay-unit><?= $pt === 'hourly' ? 'per hour' : 'per month' ?></span></div>
          <div class="hint<?= $pt === 'hourly' ? ' hide' : '' ?>" data-pay-label="monthly">Gross salary for a full month.<?= $byHours && PayrollService::trackedSalary() ? ' Each month it becomes an hourly rate (salary ÷ that month\'s expected hours), paid for the hours worked.' : ' Unpaid leave days are deducted.' ?></div>
          <div class="hint<?= $pt === 'monthly' ? ' hide' : '' ?>" data-pay-label="hourly">Paid for each approved hour worked<?= $byHours ? ', up to the expected hours (overtime only when enabled in Payroll settings)' : '' ?>.</div><?= error_for('amount') ?></div>
        <div class="field"><label for="effective_from">Effective from *</label><input id="effective_from" type="date" name="effective_from" value="<?= e(old('effective_from', date('Y-m-01'))) ?>" required><?= error_for('effective_from') ?></div>
        <div class="field"><label for="note">Note</label><input id="note" type="text" name="note" value="<?= e(old('note')) ?>" maxlength="255" placeholder="e.g. Annual appraisal"><?= error_for('note') ?></div>
        <div class="form-actions"><button class="btn ghost" type="button" data-close>Cancel</button><button class="btn primary"><?= icon('check', 'sm') ?> Save revision</button></div>
  </form>
</dialog>
