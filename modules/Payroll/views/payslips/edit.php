<?php
use Modules\Payroll\Services\{PayrollService, PayslipEditor};
$label = PayrollService::monthLabel($s['period_month']);
$draft = $s['run_status'] === 'draft';
$hx = $calc['hx'] ?? null;
$hourly = $s['pay_type'] === 'hourly';
$hn = static fn ($v) => rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
$lines = $edits['lines'] ?? []; $manual = $edits['manual'] ?? []; $ov = $edits['hours'] ?? [];
$cover = PayrollService::coverOf($s);
$types = PayslipEditor::TYPES;
$row = static function (string $i, array $m) use ($types): string {
  $o = '';
  foreach ($types as $tk => [$tl, $kind]) { if ($kind === ($m['kind'] ?? 'earning')) { $o .= '<option value="'.e($tk).'"'.selected($tk, $m['type'] ?? '').'>'.e($tl).'</option>'; } }
  return '<tr class="pay-manual" data-pay-manual><td><input type="hidden" name="manual['.$i.'][id]" value="'.e($m['id'] ?? '').'"><select name="manual['.$i.'][type]" aria-label="Kind">'.$o.'</select><input name="manual['.$i.'][name]" value="'.e($m['name'] ?? '').'" maxlength="120" placeholder="Line name" aria-label="Line name" class="mt-xs"></td>'
    .'<td class="r muted xs">Added</td><td><input type="number" name="manual['.$i.'][amount]" value="'.e(isset($m['amount']) ? $m['amount'] : '').'" min="0.01" step="0.01" placeholder="0.00" aria-label="Amount" class="pay-amt"></td>'
    .'<td><input name="manual['.$i.'][note]" value="'.e($m['note'] ?? '').'" maxlength="255" placeholder="Note" aria-label="Note"></td><td class="actions"><button type="button" class="btn icon sm ghost" data-pay-del title="Remove line" aria-label="Remove line">'.icon('trash', 'sm').'</button></td></tr>'; };
$side = static function (string $title, string $kind, array $auto, array $manualRows, string $tone) use ($lines, $row): string {
  $h = '<section class="card pay-edit-card'.$tone.'"><div class="card-head"><h3>'.e($title).'</h3></div><div class="table-wrap"><table class="table pay-edit-table" data-pay-side="'.e($kind).'"><thead><tr><th>Line</th><th class="r">Calculated</th><th>Amount</th><th>Note</th><th></th></tr></thead><tbody>';
  if (!$auto && !$manualRows) { $h .= '<tr class="muted" data-pay-empty><td colspan="5" class="small">No '.($kind === 'deduction' ? 'deductions' : 'earnings').' yet.</td></tr>'; }
  foreach ($auto as $l) {
    $code = (string) ($l['code'] ?? ''); $o = $lines[$code] ?? []; $lock = $code === '' || $code === 'reimb'; $n = 'lines['.e($code).']';
    $h .= '<tr'.(!empty($o['removed']) ? ' class="pay-removed"' : '').'><td><b class="small">'.e($l['name']).'</b>'.($lock ? '<div class="xs muted">'.($code === 'reimb' ? 'Approved claims, change them under Reimbursements' : 'Regenerate the run to edit this line').'</div>' : '').'</td><td class="r nowrap">'.e(money($l['amount'])).'</td>';
    if ($lock) { $h .= '<td colspan="3"></td></tr>'; continue; }
    $h .= '<td><input type="number" name="'.$n.'[amount]" value="'.e(isset($o['amount']) ? $o['amount'] : '').'" min="0" step="0.01" placeholder="'.e(number_format((float) $l['amount'], 2, '.', '')).'" aria-label="Amount of '.e($l['name']).'" class="pay-amt"></td>'
      .'<td><input name="'.$n.'[note]" value="'.e($o['note'] ?? '').'" maxlength="255" placeholder="Note" aria-label="Note for '.e($l['name']).'"></td>'
      .'<td class="actions"><label class="check xs" title="Leave this line out of the payslip"><input type="checkbox" name="'.$n.'[removed]" value="1"'.checked(!empty($o['removed'])).' data-pay-remove> Remove</label></td></tr>'; }
  foreach ($manualRows as $i => $m) { $h .= $row((string) $i, $m); }
  return $h.'</tbody></table></div><div class="pay-add" data-pay-add="'.e($kind).'">'.($kind === 'earning' ? '<button type="button" class="btn sm soft" data-pay-new="bonus">'.icon('gift', 'sm').' Add bonus</button><button type="button" class="btn sm ghost" data-pay-new="earning">'.icon('plus', 'sm').' Other earning</button>' : '<button type="button" class="btn sm soft" data-pay-new="one_time">'.icon('minus', 'sm').' One-time deduction</button><button type="button" class="btn sm ghost" data-pay-new="deduction">'.icon('plus', 'sm').' Other deduction</button>').'</div></section>'; };
$mE = []; $mD = [];
foreach (array_values($manual) as $i => $m) { if (($m['kind'] ?? '') === 'deduction') { $mD[$i] = $m; } else { $mE[$i] = $m; } }
$actions = '<a class="btn ghost" href="'.e(url('/payroll/runs/'.$s['run_id'])).'">'.icon('arrow-left', 'sm').' Back to run</a><a class="btn ghost" href="'.e(url('/payslips/'.$s['id'])).'">'.icon('eye', 'sm').' View payslip</a>';
?>
<?= partial('partials/page_head', ['title' => 'Edit payslip · '.$s['name'], 'subtitle' => $label.' · '.trim(($s['employee_code'] ?? '').' · '.($s['designation'] ?? ''), ' ·').($s['edited_at'] ? ' · Last edited '.fdatetime($s['edited_at']).($s['editor'] ? ' by '.$s['editor'] : '') : ''), 'crumbs' => [['HR Admin'], ['Payroll', '/payroll'], [$label, '/payroll/runs/'.$s['run_id']], [$s['name']]], 'actions' => $actions]) ?>
<?php if (!$draft): ?>
  <div class="alert warn"><?= icon('lock') ?><div>This payslip is <b><?= $s['run_status'] === 'paid' ? 'paid' : 'published' ?></b> and locked. <?= $s['run_status'] === 'paid' ? 'A paid run cannot be reopened; record corrections in the next run.' : 'Reopen the run to edit it.' ?> <a class="b" href="<?= e(url('/payroll/runs/'.$s['run_id'])) ?>">Open the run</a></div></div>
<?php elseif (!$calc): ?>
  <div class="alert warn"><?= icon('alert') ?><div>This payslip was calculated before editing was available. <b>Regenerate</b> the run once, then edit it here.</div></div>
<?php else: ?>
<div class="grid g-main pay-top">
  <form method="post" action="<?= e(url('/payroll/payslips/'.$s['id'].'/edit')) ?>" class="stack form" id="pay-edit" data-pay-edit data-preview="payroll/payslips/<?= (int) $s['id'] ?>/preview" data-next="<?= count($manual) ?>"><?= csrf_field() ?>
    <?php if ($hx || $hourly): ?>
    <section class="card"><div class="card-head"><h3>Hours</h3><span class="xs muted"><?= $hx ? e(PayrollService::FLEX_SHORT[$hx['mode'] ?? 'monthly'] ?? '') : 'Hourly pay without time tracking' ?></span></div>
      <div class="pay-hours">
        <?php if ($hx): ?>
        <div class="pay-hbox"><span class="xs muted">Expected</span><b><?= e($hn($hx['expected'])) ?> h</b></div>
        <div class="pay-hbox"><span class="xs muted">Worked (approved)</span><b><?= e($hn($hx['worked'])) ?> h</b></div>
        <div class="field"><label for="ph-pay">Payable hours</label><div class="pay-unit"><input id="ph-pay" type="number" name="hours[payable]" min="0" max="744" step="0.25" value="<?= e($ov['payable'] ?? '') ?>" placeholder="<?= e($hn($hx['payable'])) ?>"><span>h</span></div><div class="hint">Blank = calculated (<?= e($hn($hx['payable'])) ?> h<?= $cover ? ', before leave cover' : '' ?>)</div></div>
        <div class="field"><label for="ph-ot">Overtime hours</label><div class="pay-unit"><input id="ph-ot" type="number" name="hours[overtime]" min="0" max="744" step="0.25" value="<?= e($ov['overtime'] ?? '') ?>" placeholder="<?= e($hn($hx['overtime'])) ?>"><span>h</span></div><div class="hint"><?= ($hx['ot_multiplier'] ?? null) ? 'Paid at '.e(PayrollService::multLabel((float) $hx['ot_multiplier'])).' the hourly rate' : 'Overtime pay is off in Settings, so overtime hours are not paid' ?></div></div>
        <?php else: ?>
        <div class="pay-hbox"><span class="xs muted">Approved hours</span><b><?= e($hn($calc['hours'])) ?> h</b></div>
        <div class="field"><label for="ph-h">Hours to pay</label><div class="pay-unit"><input id="ph-h" type="number" name="hours[hours]" min="0" max="744" step="0.25" value="<?= e($ov['hours'] ?? '') ?>" placeholder="<?= e($hn($calc['hours'])) ?>"><span>h</span></div><div class="hint">Blank = approved hours</div></div>
        <?php endif ?>
      </div>
      <p class="small mb0 pay-cover<?= $cover ? '' : ' hide' ?>" data-pay-cover><?= icon('umbrella', 'sm') ?> <span><?= $cover ? 'Shortage covered by Paid Leave: '.e(PayrollService::coverLabel($cover)) : '' ?></span></p>
    </section>
    <?php endif ?>
    <?= $side('Earnings', 'earning', $auto['earnings'], $mE, '') ?>
    <?= $side('Deductions', 'deduction', $auto['deductions'], $mD, ' ded') ?>
    <section class="card"><div class="field"><label for="pe-reason">Note for this change</label><textarea id="pe-reason" name="reason" rows="2" minlength="3" maxlength="500" required placeholder="Why are you changing this payslip? Saved in the audit log with the before and after figures."></textarea></div>
      <div class="form-actions mt"><span class="xs muted grow">The employee sees the result only after you finalize the run.</span><button class="btn primary"><?= icon('check', 'sm') ?> Save payslip</button></div></section>
  </form>
  <div class="stack">
    <div class="card pay-live" data-pay-live aria-live="polite">
      <div class="card-head"><h3>Totals</h3><span class="pill pill-lemon pay-mini hide" data-pay-dirty>Not saved</span></div>
      <div class="pay-line"><span>Gross earnings</span><b data-live="gross"><?= e(money($s['gross'])) ?></b></div>
      <div class="pay-line"><span>Total deductions</span><b data-live="deductions"><?= e(money($s['total_deductions'])) ?></b></div>
      <div class="pay-net mt"><div><div class="xs pay-up">Net pay</div><div class="xs" data-live="delta"></div></div><div class="pay-net-amt" data-live="net"><?= e(money($s['net'])) ?></div></div>
      <p class="xs pay-red mt mb0<?= (float) $s['total_deductions'] > (float) $s['gross'] ? '' : ' hide' ?>" data-live="flag"><?= icon('alert', 'sm') ?> Deductions exceed gross pay; net pay is capped at zero.</p>
      <p class="xs muted mt mb0" data-live="error"></p>
    </div>
    <?php if ($s['edits'] !== null): ?>
    <div class="card"><h4>Manual edits</h4><p class="small muted">Regenerating the run keeps these edits unless you discard them there. Discard only this payslip's edits:</p>
      <form method="post" action="<?= e(url('/payroll/payslips/'.$s['id'].'/reset')) ?>" class="form" data-confirm="Discard all manual edits of this payslip?"><?= csrf_field() ?><input name="reason" maxlength="400" placeholder="Reason (optional)" aria-label="Reason"><button class="btn ghost sm mt"><?= icon('history', 'sm') ?> Discard edits</button></form></div>
    <?php endif ?>
    <div class="card"><h4>Edit history</h4>
      <?php if (!$history): ?><p class="small muted mb0">No edits yet.</p><?php else: ?><div class="timeline"><?php foreach ($history as $a): $m = json_decode((string) $a['meta'], true) ?: []; ?>
        <div class="tl-item"><span class="tl-dot"></span><div class="tl-card"><b class="small"><?= e($a['name'] ?? 'System') ?></b> <span class="xs muted"><?= e(fdatetime($a['created_at'])) ?></span>
          <div class="xs"><?= e(money($m['before']['net'] ?? 0)) ?> → <b><?= e(money($m['after']['net'] ?? 0)) ?></b><?= !empty($m['reason']) ? ' · '.e($m['reason']) : '' ?></div>
          <?php if (!empty($m['changes'])): ?><ul class="xs muted pay-changes"><?php foreach (array_slice((array) $m['changes'], 0, 6) as $c): ?><li><?= e($c) ?></li><?php endforeach ?></ul><?php endif ?></div></div>
      <?php endforeach ?></div><?php endif ?>
    </div>
  </div>
</div>
<template data-pay-tpl="earning"><?= $row('__i__', ['kind' => 'earning']) ?></template>
<template data-pay-tpl="deduction"><?= $row('__i__', ['kind' => 'deduction']) ?></template>
<?php endif ?>
