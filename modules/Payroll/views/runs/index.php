<?php
use Modules\Payroll\Services\PayrollService;
$nextLabel = PayrollService::monthLabel($next);
?>
<?= partial('partials/page_head', ['title' => 'Payroll', 'subtitle' => 'Prepare monthly runs, review every payslip and pay your team on time.', 'crumbs' => [['HR Admin'], ['Payroll']], 'actions' => '<button class="btn primary" type="button" data-modal="newRun">'.icon('plus', 'sm').' New payroll run</button>']) ?>
<?= partial('payroll::_tabs', ['active' => 'runs']) ?>
<div class="grid g4 mb">
  <div class="tile bg-lav"><span class="tile-ic"><?= icon('wallet') ?></span><div><div class="num pay-num"><?= e($last ? money($last['total_net']) : '—') ?></div><div class="lbl">Last net payout<?= $last ? ' · '.e(date('M Y', strtotime($last['period_month']))) : '' ?></div></div></div>
  <div class="tile bg-mint"><span class="tile-ic"><?= icon('users') ?></span><div><div class="num"><?= (int) ($last['slips'] ?? 0) ?></div><div class="lbl">Employees paid last run</div><div class="pay-sub"><?= (int) $staff ?> with pay set up</div></div></div>
  <a class="tile bg-lemon" href="<?= e(url(can('payroll.reimbursements.approve') ? '/reimbursements?tab=queue' : '/payroll')) ?>"><span class="tile-ic"><?= icon('receipt') ?></span><div><div class="num"><?= (int) ($reimb['pending'] ?? 0) ?></div><div class="lbl">Claims to review</div><div class="pay-sub"><?= e(money($reimb['pending_amt'] ?? 0)) ?> pending</div></div></a>
  <div class="tile bg-sky"><span class="tile-ic"><?= icon('calendar') ?></span><div><div class="num pay-num"><?= e(date('M Y', strtotime($next))) ?></div><div class="lbl">Next run month</div><div class="pay-sub"><?= (int) ($reimb['queued'] ?? 0) ?> approved claim(s) to include</div></div></div>
</div>
<div class="grid g-side pay-top">
  <div class="card">
    <div class="card-head"><h3>Payroll runs</h3><span class="muted small"><?= (int) $p['total'] ?> total</span></div>
    <?php if (!$p['items']): ?>
      <?= empty_state('No payroll runs yet', 'Create your first monthly run. Payslips are generated automatically for everyone with a salary or hourly rate.', 'money-wallet', '<button class="btn primary" type="button" data-modal="newRun">'.icon('plus', 'sm').' Create '.e($nextLabel).' run</button>') ?>
    <?php else: ?>
    <div class="table-wrap"><table class="table">
      <thead><tr><th>Month</th><th>Status</th><th class="num">Employees</th><th class="num">Gross</th><th class="num">Deductions</th><th class="num">Net payout</th><th><span class="sr">Open</span></th></tr></thead>
      <tbody><?php foreach ($p['items'] as $r): ?>
        <tr>
          <td class="nowrap"><a class="row-link" href="<?= e(url('/payroll/runs/'.$r['id'])) ?>"><?= e(PayrollService::monthLabel($r['period_month'])) ?></a><div class="xs muted">Created <?= e(fdate($r['created_at'])) ?><?= $r['creator'] ? ' · '.e($r['creator']) : '' ?></div></td>
          <td class="nowrap"><?= badge($r['status']) ?><?php if ((int) $r['flagged']): ?> <span class="pill pill-red" title="Deductions exceed gross"><?= icon('alert', 'sm') ?> <?= (int) $r['flagged'] ?></span><?php endif ?></td>
          <td class="num"><?= (int) $r['slips'] ?></td>
          <td class="num"><?= e(money($r['gross'])) ?></td>
          <td class="num"><?= e(money($r['deductions'])) ?></td>
          <td class="num"><b><?= e(money($r['total_net'])) ?></b></td>
          <td class="actions"><a class="btn sm ghost" href="<?= e(url('/payroll/runs/'.$r['id'])) ?>">Open <?= icon('arrow-right', 'sm') ?></a></td>
        </tr>
      <?php endforeach ?></tbody>
    </table></div>
    <?= pager($p) ?>
    <?php endif ?>
  </div>
  <div class="stack">
    <div class="card">
      <div class="card-head"><h4>How a run works</h4></div>
      <ol class="pay-steps">
        <li><div><b>Draft</b> — payslips use each person’s own working days, unpaid leave, approved hours, components and approved claims. Regenerate anytime.</div></li>
        <li><div><b>Finalize</b> — numbers are locked and every employee is notified that their payslip is ready.</div></li>
        <li><div><b>Paid</b> — export the bank transfer sheet, pay, then mark the run as paid to settle reimbursements.</div></li>
      </ol>
    </div>
    <?php if (can('payroll.compensation.manage')): ?>
    <div class="card">
      <div class="list">
        <a class="list-item" href="<?= e(url('/payroll/employees')) ?>"><span class="pay-ic mint"><?= icon('users', 'sm') ?></span><div class="grow"><b class="small">Compensation</b><div class="meta">Salaries & hourly rates</div></div><?= icon('chevron-right', 'sm') ?></a>
        <a class="list-item" href="<?= e(url('/payroll/components')) ?>"><span class="pay-ic sky"><?= icon('sliders', 'sm') ?></span><div class="grow"><b class="small">Allowances & deductions</b><div class="meta">Company-wide components</div></div><?= icon('chevron-right', 'sm') ?></a>
      </div>
    </div>
    <?php endif ?>
  </div>
</div>
<dialog class="modal" id="newRun"<?= error_for('month') ? ' data-open' : '' ?>>
  <div class="modal-head"><h3>New payroll run</h3><button class="btn icon sm ghost" type="button" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div>
  <form class="modal-body form" method="post" action="<?= e(url('/payroll/runs')) ?>"><?= csrf_field() ?>
    <p class="muted small mb0">A draft run is created and payslips are generated for every active employee with a compensation record effective in that month. You can regenerate until you finalize.</p>
    <div class="field"><label for="run-month">Payroll month *</label><input type="month" id="run-month" name="month" value="<?= e(old('month', date('Y-m', strtotime($next)))) ?>" max="<?= e(date('Y-m', strtotime('+1 month'))) ?>" required><?= error_for('month') ?></div>
    <div class="form-actions"><button class="btn ghost" type="button" data-close>Cancel</button><button class="btn primary"><?= icon('zap', 'sm') ?> Create & generate</button></div>
  </form>
</dialog>
