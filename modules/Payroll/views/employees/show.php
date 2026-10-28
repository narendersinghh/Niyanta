<?php
$num = static fn ($v) => rtrim(rtrim(number_format((float) $v, 2), '0'), '.');
?>
<?= partial('partials/page_head', ['title' => $emp['name'], 'subtitle' => trim(($emp['designation'] ?? '').($emp['department'] ? ' · '.$emp['department'] : ''), ' ·') ?: 'Compensation & pay components', 'crumbs' => [['Compensation', '/payroll/employees'], [$emp['name']]]]) ?>
<div class="grid g-side pay-top">
  <div class="stack">
    <div class="card pay-profile">
      <div class="profile-head"><?= avatar($emp['name'], $emp['avatar'], 'lg') ?><div class="grow"><h2 class="mb0"><?= e($emp['name']) ?></h2><div class="muted small"><?= e($emp['email']) ?><?= $emp['employee_code'] ? ' · '.e($emp['employee_code']) : '' ?> · <?= e($emp['role_name']) ?></div>
        <div class="chips mt"><?= badge($emp['status']) ?><?php if ($emp['joined_on']): ?><span class="chip"><?= icon('calendar', 'sm') ?> Joined <?= e(fdate($emp['joined_on'])) ?></span><?php endif ?><span class="chip"><?= icon('credit-card', 'sm') ?> <?= $emp['bank_name'] ? e($emp['bank_name']).($emp['has_account'] ? '' : ' · no account no.') : 'No bank details' ?></span></div></div>
        <div class="pay-current <?= $current ? '' : 'none' ?>"><?php if ($current): ?><span class="xs muted">Current <?= $current['pay_type'] === 'hourly' ? 'hourly rate' : 'monthly salary' ?></span><b><?= e(money($current['amount'])) ?></b><span class="xs muted">since <?= e(fdate($current['effective_from'])) ?></span><?php else: ?><span class="xs muted">Not on payroll yet</span><b>—</b><span class="xs muted">Add a revision</span><?php endif ?></div>
      </div>
    </div>
    <div class="card">
      <div class="card-head"><h3>Compensation history</h3><span class="muted small"><?= count($history) ?> revision<?= count($history) === 1 ? '' : 's' ?></span></div>
      <?php if (!$history): ?><?= empty_state('No compensation yet', 'Add a monthly salary or an hourly rate to include this person in payroll runs.', 'money-wallet') ?><?php else: ?>
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
    <div class="card">
      <div class="card-head"><h3>Add revision</h3></div>
      <form class="form" method="post" action="<?= e(url('/payroll/employees/'.$emp['id'].'/compensation')) ?>"><?= csrf_field() ?>
        <div class="field"><label for="pay_type">Pay type</label><select id="pay_type" name="pay_type"><option value="monthly"<?= selected('monthly', old('pay_type', $current['pay_type'] ?? 'monthly')) ?>>Monthly salary</option><option value="hourly"<?= selected('hourly', old('pay_type', $current['pay_type'] ?? '')) ?>>Hourly rate</option></select><?= error_for('pay_type') ?></div>
        <div class="field"><label for="amount">Amount *</label><input id="amount" type="number" name="amount" step="0.01" min="0.01" value="<?= e(old('amount')) ?>" placeholder="<?= e($current ? $num($current['amount']) : '0.00') ?>" required><div class="hint">Gross monthly salary, or rate per approved hour.</div><?= error_for('amount') ?></div>
        <div class="field"><label for="effective_from">Effective from *</label><input id="effective_from" type="date" name="effective_from" value="<?= e(old('effective_from', date('Y-m-01'))) ?>" required><?= error_for('effective_from') ?></div>
        <div class="field"><label for="note">Note</label><input id="note" type="text" name="note" value="<?= e(old('note')) ?>" maxlength="255" placeholder="e.g. Annual appraisal"><?= error_for('note') ?></div>
        <div class="form-actions"><button class="btn primary"><?= icon('check', 'sm') ?> Save revision</button></div>
      </form>
    </div>
    <div class="card">
      <div class="card-head"><h3>Recent payslips</h3></div>
      <?php if (!$slips): ?><p class="muted small mb0">No payslips yet.</p><?php else: ?><div class="list"><?php foreach ($slips as $s): ?>
        <a class="list-item" href="<?= e(url('/payslips/'.$s['id'])) ?>"><span class="pay-dot soft-peach"><?= icon('file-text', 'sm') ?></span><div class="grow"><b class="small"><?= e(date('F Y', strtotime($s['period_month']))) ?></b><div class="meta">Gross <?= e(money($s['gross'])) ?></div></div><div class="right"><b class="small"><?= e(money($s['net'])) ?></b><div><?= badge($s['status']) ?></div></div></a>
      <?php endforeach ?></div><?php endif ?>
    </div>
  </div>
</div>
<dialog class="modal" id="add-personal"><div class="modal-head"><h3>Add item for <?= e($emp['name']) ?></h3><button class="btn icon sm ghost" type="button" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div><div class="modal-body"><?= partial('payroll::components/_form', ['c' => null, 'uid' => (int) $emp['id']]) ?></div></dialog>
