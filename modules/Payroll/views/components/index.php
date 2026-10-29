<?php $allow = array_values(array_filter($rows, static fn ($r) => $r['kind'] === 'allowance')); $ded = array_values(array_filter($rows, static fn ($r) => $r['kind'] === 'deduction')); ?>
<?= partial('partials/page_head', ['title' => 'Allowances & deductions', 'subtitle' => 'Company-wide pay components applied to every employee on each payroll run.', 'crumbs' => [['HR Admin'], ['Payroll', can('payroll.manage') ? '/payroll' : null], ['Allowances & deductions']], 'actions' => '<button class="btn primary" type="button" data-modal="add-comp">'.icon('plus', 'sm').' Add component</button>']) ?>
<?= partial('payroll::_tabs', ['active' => 'components']) ?>
<div class="grid pay-top">
  <div class="stack">
    <div class="grid g2">
      <div class="card"><div class="card-head"><h3><?= icon('trend-up') ?> Allowances</h3><span class="pill pill-mint"><?= count($allow) ?></span></div><?= partial('payroll::components/_rows', ['rows' => $allow, 'empty' => 'No company-wide allowances yet, e.g. HRA at 40% of base pay.']) ?></div>
      <div class="card"><div class="card-head"><h3><?= icon('minus') ?> Deductions</h3><span class="pill pill-pink"><?= count($ded) ?></span></div><?= partial('payroll::components/_rows', ['rows' => $ded, 'empty' => 'No company-wide deductions yet, e.g. Professional Tax as a fixed amount.']) ?></div>
    </div>
    <div class="card flex pay-top"><span class="pay-ic sky"><?= icon('info') ?></span><div><h4>How components are calculated</h4><p class="small mb0">Percentages are applied to the <b>base pay of the period</b> (after unpaid leave for salaried staff, or hours × rate for hourly staff). Fixed amounts are added or deducted as-is. Employee-specific items (<?= (int) $personal ?> active) are managed from each employee's compensation page and stack on top of these.</p></div></div>
  </div>
</div>
<dialog class="modal" id="add-comp"<?= errors() ? ' data-open' : '' ?>><div class="modal-head"><h3>Add company-wide component</h3><button class="btn icon sm ghost" type="button" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div><div class="modal-body"><?= partial('payroll::components/_form', ['c' => null, 'uid' => null]) ?></div></dialog>
