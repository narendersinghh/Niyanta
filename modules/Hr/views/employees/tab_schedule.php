<?php use Modules\Hr\Services\LeaveService as LS;
$manage = can('hr.employees.manage');
$first = e(explode(' ', $emp['name'])[0]);
$hoursMode = old('hours_mode', $schedule['custom_hours'] ? 'custom' : 'default');
$daysMode = old('days_mode', $schedule['custom_days'] ? 'custom' : 'default');
$offSel = array_map('intval', (array) old('off_days', $schedule['off_days']));
$err = errors();
?>
<div class="card mb">
  <div class="card-head"><div><h3><?= icon('clock') ?> Effective work schedule</h3><p class="small muted mb0">Used for leave day counts, salary proration, attendance targets and auto clock-out.</p></div><?= $schedule['custom_hours'] || $schedule['custom_days'] ? '<span class="pill pill-lav">Personal schedule</span>' : '<span class="pill pill-gray">Company default</span>' ?></div>
  <div class="hr-eff">
    <div><span class="xs">Hours per day</span><b><?= e(LS::num($schedule['hours_per_day'])) ?> h</b><span class="hr-src"><?= $schedule['custom_hours'] ? 'Personal · company default '.e(LS::num($companyHours)).' h' : 'Company default' ?></span></div>
    <div><span class="xs">Working days · <?= e(date('F')) ?></span><b><?= e(LS::num($monthDays)) ?> days</b><span class="hr-src"><?= $monthDays != $companyMonthDays ? 'Company calendar: '.e(LS::num($companyMonthDays)).' days' : 'Same as company calendar' ?> · excl. fixed holidays</span></div>
    <div><span class="xs">Pay rate</span><?php if ($pay): ?><b><?= e(money($pay['amount'])) ?><small class="muted"> /<?= $pay['pay_type'] === 'hourly' ? 'hour' : 'month' ?></small></b><span class="hr-src"><?= $pay['pay_type'] === 'hourly' ? 'Hourly · paid on approved hours' : 'Monthly · prorated on working days' ?> · since <?= e(fdate($pay['effective_from'])) ?></span><?php else: ?><b class="muted">-</b><span class="hr-src">No compensation set up yet</span><?php endif ?>
      <?php if (can('payroll.compensation.manage')): ?><a class="small b" href="<?= e(url('/payroll/employees/'.$emp['id'])) ?>">Manage in payroll <?= icon('arrow-right', 'sm') ?></a><?php elseif ($pay): ?><span class="hr-src"><?= icon('lock', 'sm') ?> Read-only · managed by payroll</span><?php endif ?></div>
  </div>
  <div class="mt"><span class="xs muted b">WEEKLY OFF</span><div class="hr-days ro mt"><?php foreach (LS::DAY_NAMES as $n => $dn): $off = in_array($n, $schedule['off_days'], true); ?><span class="<?= $off ? 'on' : 'wk' ?>" title="<?= $off ? 'Weekly off' : 'Working day' ?>"><?= e($dn) ?></span><?php endforeach ?></div></div>
</div>
<?php if ($manage): ?>
<form class="hr-form" method="post" action="<?= e(url('/hr/employees/'.$emp['id'].'/schedule')) ?>"><?= csrf_field() ?>
  <div class="card pad0">
    <div class="hr-sec">
      <div class="hr-sec-t"><h3><?= icon('clock', 'sm') ?> Working hours</h3><p>Daily target used for attendance, half-day detection and the “hours this week” goal.</p></div>
      <div>
        <div class="hr-mode" data-mode="hours" role="radiogroup" aria-label="Working hours source"><label><input type="radio" name="hours_mode" value="default"<?= checked($hoursMode !== 'custom') ?>><span>Company default (<?= e(LS::num($companyHours)) ?> h)</span></label><label><input type="radio" name="hours_mode" value="custom"<?= checked($hoursMode === 'custom') ?>><span>Custom</span></label></div>
        <div data-mode-default="hours" class="small muted">Follows Settings → HR. Changes there apply to <?= $first ?> automatically.</div>
        <div class="field" data-mode-panel="hours"><label for="sch-h">Hours per day</label><input id="sch-h" type="number" name="work_hours_per_day" step="0.25" min="0.5" max="16" value="<?= e(old('work_hours_per_day', LS::num($schedule['hours_per_day']))) ?>" class="hr-quota-in"><div class="hint">e.g. 4 for a part-timer, 9 for a longer shift.</div><?= error_for('work_hours_per_day') ?></div>
      </div>
    </div>
    <div class="hr-sec">
      <div class="hr-sec-t"><h3><?= icon('calendar', 'sm') ?> Weekly off days</h3><p>Days <?= $first ?> never works. They are skipped when counting leave and working days for salary.</p></div>
      <div>
        <div class="hr-mode" data-mode="days" role="radiogroup" aria-label="Weekly off source"><label><input type="radio" name="days_mode" value="default"<?= checked($daysMode !== 'custom') ?>><span>Company default (<?= e(LS::daysLabel($companyDays)) ?>)</span></label><label><input type="radio" name="days_mode" value="custom"<?= checked($daysMode === 'custom') ?>><span>Custom</span></label></div>
        <div data-mode-default="days" class="small muted">Uses the company work week.</div>
        <div data-mode-panel="days"><div class="hr-days" role="group" aria-label="Weekly off days"><?php foreach (LS::DAY_NAMES as $n => $dn): ?><label><input type="checkbox" name="off_days[]" value="<?= $n ?>"<?= checked(in_array($n, $offSel, true)) ?>><span><?= e($dn) ?></span></label><?php endforeach ?></div><div class="hint mt">Highlighted days are off. Leave all unselected for a 7-day week.</div><?= error_for('off_days') ?></div>
      </div>
    </div>
    <div class="hr-sec">
      <div class="hr-sec-t"><h3><?= icon('umbrella', 'sm') ?> Yearly leave quotas</h3><p>Leave a field empty to use the default. Changing a quota also adjusts <?= $first ?>’s <?= (int) $year ?> balance by the difference.</p></div>
      <div class="table-wrap"><table class="table">
        <thead><tr><th>Leave type</th><th class="num">Default</th><th class="num">Personal quota</th><th class="num"><?= (int) $year ?> left</th></tr></thead>
        <tbody><?php foreach ($quotas as $q): $b = $bal[(int) $q['id']] ?? null; ?><tr>
          <td><span class="pill pill-<?= e($q['color']) ?>"><?= e($q['name']) ?></span><?= (int) $q['paid'] ? '' : ' <span class="xs muted">unpaid</span>' ?></td>
          <td class="num"><?= e(LS::num($q['default'])) ?></td>
          <td class="num"><label class="sr" for="q-<?= (int) $q['id'] ?>">Personal quota for <?= e($q['name']) ?></label><input id="q-<?= (int) $q['id'] ?>" class="hr-quota-in" type="number" step="0.5" min="0" max="365" name="quota[<?= (int) $q['id'] ?>]" value="<?= e(old('quota', [])[$q['id']] ?? ($q['override'] !== null ? LS::num($q['override']) : '')) ?>" placeholder="<?= e(LS::num($q['default'])) ?>"><?= isset($err['quota_'.$q['id']]) ? '<div class="field-error">'.e($err['quota_'.$q['id']]).'</div>' : '' ?></td>
          <td class="num small"><?php if ($b && $b['allocated_row'] && (int) $q['paid']): ?><b><?= e(LS::num(max(0, (float) $b['available']))) ?></b> <span class="muted">/ <?= e(LS::num($b['allocated'])) ?></span><?php elseif ((int) $q['paid']): ?><span class="muted">Not allocated</span><?php else: ?><span class="muted"><?= e(LS::num($b['used'] ?? 0)) ?> used</span><?php endif ?></td>
        </tr><?php endforeach ?></tbody>
      </table></div>
    </div>
  </div>
  <div class="hr-sticky"><span class="hr-sticky-note"><?= icon('info', 'sm') ?> Changes apply immediately and are recorded in the audit log.</span><a class="btn ghost" href="<?= e(url('/hr/employees/'.$emp['id'].'?tab=schedule')) ?>">Discard</a><button class="btn primary"><?= icon('check', 'sm') ?> Save schedule</button></div>
</form>
<?php else: ?>
<div class="card">
  <div class="card-head"><h3><?= icon('umbrella') ?> Yearly leave quotas</h3><span class="small muted">Managed by HR</span></div>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Leave type</th><th class="num">Yearly quota</th><th class="num"><?= (int) $year ?> left</th></tr></thead>
    <tbody><?php foreach ($quotas as $q): $b = $bal[(int) $q['id']] ?? null; ?><tr>
      <td><span class="pill pill-<?= e($q['color']) ?>"><?= e($q['name']) ?></span><?= $q['override'] !== null ? ' <span class="pill pill-lav">Personal</span>' : '' ?></td>
      <td class="num b"><?= e(LS::num($q['effective'])) ?></td>
      <td class="num"><?php if ($b && $b['allocated_row'] && (int) $q['paid']): ?><?= e(LS::num(max(0, (float) $b['available']))) ?> <span class="muted small">/ <?= e(LS::num($b['allocated'])) ?></span><?php else: ?><span class="muted">-</span><?php endif ?></td>
    </tr><?php endforeach ?></tbody>
  </table></div>
</div>
<?php endif ?>
