<?php
use Modules\Payroll\Services\PayrollService;
?>
<?= partial('partials/page_head', ['title' => 'Team payslips', 'subtitle' => 'Review and edit the draft payslips of your direct reports before HR finalizes payroll.', 'crumbs' => [['HR Admin'], ['Team payslips']]]) ?>
<div class="grid g-side pay-top">
  <div class="card">
    <div class="card-head"><h3>Drafts to review</h3><span class="muted small"><?= (int) $team ?> direct report<?= (int) $team === 1 ? '' : 's' ?></span></div>
    <?php if (!$runs): ?>
      <?= empty_state('Nothing to review right now', $team ? 'When a payroll draft is prepared, the payslips of your team appear here until HR finalizes the run.' : 'Nobody reports to you yet. Payslips of your direct reports appear here while a payroll run is a draft.', 'money-wallet') ?>
    <?php else: ?>
    <div class="list">
      <?php foreach ($runs as $r): ?>
      <a class="list-item pay-team-run" href="<?= e(url('/payroll/runs/'.$r['id'])) ?>"><span class="pay-ic lav"><?= icon('wallet', 'sm') ?></span>
        <div class="grow"><b class="small"><?= e(PayrollService::monthLabel($r['period_month'])) ?></b> <?= badge('draft') ?><?php if ((int) $r['auto_created']): ?> <span class="pill pill-sky pay-mini">Auto</span><?php endif ?>
          <div class="meta"><?= (int) $r['slips'] ?> payslip<?= (int) $r['slips'] === 1 ? '' : 's' ?> of your team<?= (int) $r['edited'] ? ' · '.(int) $r['edited'].' edited' : '' ?> · net <?= e(money($r['net'])) ?></div></div>
        <span class="btn sm soft"><?= icon('edit', 'sm') ?> Review</span></a>
      <?php endforeach ?>
    </div>
    <?php endif ?>
  </div>
  <div class="card">
    <div class="card-head"><h4>How it works</h4></div>
    <ol class="pay-steps">
      <li><div><b>Draft</b>, created <?= PayrollService::autoDraftOn() ? 'automatically on the 1st of each month, or earlier by HR' : 'by HR' ?>. Employees cannot see it.</div></li>
      <li><div><b>Review and edit</b> the payslips of your direct reports: add a bonus or deduction, or correct hours. Every change needs a note and is audit-logged.</div></li>
      <li><div><b>Finalize &amp; notify</b>, done by HR: payslips are locked and each employee is notified.</div></li>
    </ol>
  </div>
</div>
