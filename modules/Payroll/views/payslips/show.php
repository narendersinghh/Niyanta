<?php
use Modules\Payroll\Services\PayrollService;
$earn = json_decode((string) $s['earnings'], true) ?: [];
$ded = json_decode((string) $s['deductions'], true) ?: [];
$label = PayrollService::monthLabel($s['period_month']);
$own = (int) $s['user_id'] === (int) user()['id'];
$n1 = static fn ($v) => rtrim(rtrim(number_format((float) $v, 1), '0'), '.');
$crumbs = $own ? [['My HR'], ['My Payslips', '/payslips'], [$label]] : [['HR Admin'], ['Payroll', '/payroll'], [$label, '/payroll/runs/'.$s['run_id']], [$s['name']]];
?>
<?= partial('partials/page_head', ['title' => 'Payslip · '.$label, 'subtitle' => $own ? 'Your salary statement for '.$label.'.' : 'Salary statement of '.$s['name'].'.', 'crumbs' => $crumbs, 'actions' => '<a class="btn primary" href="'.e(url('/payslips/'.$s['id'].'/pdf')).'">'.icon('download', 'sm').' Download PDF</a>']) ?>
<?php if ($s['run_status'] === 'draft'): ?><div class="alert warn"><?= icon('alert') ?><div>This payslip belongs to a <b>draft</b> run and is not visible to the employee yet. Figures may change on regeneration.</div></div><?php endif ?>
<div class="grid g-side pay-top">
  <div class="card pay-slip">
    <div class="pay-slip-head">
      <div><div class="xs pay-up">Payslip</div><h2 class="mb0"><?= e($label) ?></h2><div class="small muted"><?= e(setting('branding.app_name', 'Niyanta')) ?> · Slip #<?= e(str_pad((string) $s['id'], 6, '0', STR_PAD_LEFT)) ?></div></div>
      <?= badge($s['run_status'] === 'paid' ? 'paid' : ($s['run_status'] === 'draft' ? 'draft' : 'finalized'), $s['run_status'] === 'paid' ? 'Paid' : ($s['run_status'] === 'draft' ? 'Draft' : 'Published')) ?>
    </div>
    <div class="grid g2 pay-info">
      <dl class="kv"><dt>Employee</dt><dd><?= e($s['name']) ?></dd><dt>Employee code</dt><dd><?= e($s['employee_code'] ?: '—') ?></dd><dt>Designation</dt><dd><?= e($s['designation'] ?: '—') ?></dd><dt>Department</dt><dd><?= e($s['department'] ?: '—') ?></dd></dl>
      <dl class="kv"><?php if ($s['pay_type'] === 'hourly'): ?><dt>Pay type</dt><dd>Hourly</dd><dt>Hourly rate</dt><dd><?= e(money($s['rate'])) ?></dd><dt>Hours worked</dt><dd><?= e(number_format((float) $s['worked_hours'], 2)) ?> h</dd><?php else: ?><dt>Pay type</dt><dd>Monthly · <?= e(money($s['rate'])) ?></dd><dt>Working days</dt><dd><?= e($n1($s['working_days'])) ?></dd><dt>Paid days</dt><dd><?= e($n1($s['paid_days'])) ?></dd><dt>Unpaid leave</dt><dd><?= e($n1($s['unpaid_leave_days'])) ?></dd><?php endif ?></dl>
    </div>
    <div class="grid g2 mt pay-top">
      <?php foreach ([['Earnings', $earn, 'Gross earnings', $s['gross'], ''], ['Deductions', $ded, 'Total deductions', $s['total_deductions'], ' ded']] as [$t, $rows, $tl, $tot, $c]): ?>
      <div class="pay-box<?= $c ?>"><div class="pay-box-head"><b><?= e($t) ?></b><span class="xs">Amount</span></div>
        <?php if (!$rows): ?><div class="pay-line muted"><span>None</span><span>—</span></div><?php endif ?>
        <?php foreach ($rows as $r): ?><div class="pay-line"><span><?= e($r['name']) ?></span><span><?= e(money($r['amount'])) ?></span></div><?php endforeach ?>
        <div class="pay-line total"><span><?= e($tl) ?></span><span><?= e(money($tot)) ?></span></div>
      </div>
      <?php endforeach ?>
    </div>
    <div class="pay-net mt">
      <div><div class="xs pay-up">Net pay</div><div class="small"><?= e(PayrollService::inWords((float) $s['net'])) ?></div></div>
      <div class="pay-net-amt"><?= e(money($s['net'])) ?></div>
    </div>
    <?php if ((float) $s['total_deductions'] > (float) $s['gross']): ?><div class="alert error mt mb0"><?= icon('alert') ?><div>Deductions exceeded gross earnings this period; net pay was capped at zero.</div></div><?php endif ?>
    <?php $foot = trim((string) setting('payroll.payslip_footer', '')); if ($foot !== ''): ?><p class="small muted mt mb0"><?= nl2br(e($foot)) ?></p><?php endif ?>
    <p class="xs muted mt mb0">This is a computer-generated payslip and does not require a signature.</p>
  </div>
  <div class="stack">
    <div class="card"><div class="flex"><span class="pay-ic lav"><?= icon('printer') ?></span><div><h4 class="mb0">Need a copy?</h4><p class="small muted mb0">Print-ready PDF for loans, visas or your records.</p></div></div><a class="btn ghost sm mt" href="<?= e(url('/payslips/'.$s['id'].'/pdf')) ?>"><?= icon('download', 'sm') ?> Download PDF</a></div>
    <div class="card">
      <div class="card-head"><h4>Reimbursements included</h4><b class="small"><?= e(money($s['reimbursement_total'])) ?></b></div>
      <?php if (!$claims): ?><p class="muted small mb0">No claims were paid with this payslip.</p><?php else: ?><div class="list"><?php foreach ($claims as $c): ?>
        <div class="list-item"><span class="pay-ic sky"><?= icon('receipt', 'sm') ?></span><div class="grow"><b class="small"><?= e($c['title']) ?></b><div class="meta"><?= e(fdate($c['expense_date'])) ?> · <?= e(ucfirst($c['status'])) ?></div></div><b class="small"><?= e(money($c['amount'])) ?></b></div>
      <?php endforeach ?></div><?php endif ?>
    </div>
  </div>
</div>
