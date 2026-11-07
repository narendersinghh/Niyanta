<?php use Modules\Hr\Services\LeaveService as LS;
$kindPill = ['opening' => 'gray', 'allocation' => 'mint', 'accrual' => 'mint', 'adjustment' => 'lav', 'usage' => 'peach', 'carry_forward' => 'sky', 'expiry' => 'red', 'shortage' => 'lemon'];
$q = static fn (array $x) => url('/hr/leave/ledger').'?'.http_build_query(array_filter($x + ['user' => $manage && !$self ? $emp['id'] : null, 'year' => $year, 'type' => $typeId], static fn ($v) => $v !== null && $v !== ''));
$paid = array_values(array_filter($balances, static fn ($b) => (int) $b['paid'] === 1));
?>
<?= partial('partials/page_head', ['title' => $self ? 'My leave ledger' : 'Leave ledger', 'subtitle' => 'Every credit and debit of '.($self ? 'your' : $emp['name'].'\'s').' leave balances in '.(int) $year.': accruals, allocations, leave taken, adjustments, carry forward and hour shortage cover.', 'crumbs' => $manage ? [['HR Admin'], ['Leave', '/hr/leave'], ['Balances', '/hr/leave/balances?year='.$year], [$emp['name']]] : [['My HR'], ['Leave', '/hr/leave'], ['Ledger']],
  'actions' => $manage ? '<button type="button" class="btn primary" data-modal="adjust">'.icon('sliders', 'sm').' Adjust balance</button>' : '']) ?>
<?= partial('hr::leave/_tabs', ['tab' => $manage ? 'balances' : 'index', 'pendingCount' => $pendingCount]) ?>
<div class="card mb lv-person">
  <div class="hr-who"><?= avatar($emp['name'], $emp['avatar']) ?><div><b><?= e($emp['name']) ?></b><div class="xs muted"><?= e(trim(($emp['designation'] ?? '').($emp['department'] ? ' · '.$emp['department'] : ''), ' ·') ?: 'Employee') ?><?= $emp['joined_on'] ? ' · joined '.e(fdate($emp['joined_on'])) : '' ?></div></div></div>
  <form class="hr-toolbar lv-filter" method="get">
    <?php if ($manage): ?><label class="sr" for="lg-u">Employee</label><select id="lg-u" name="user" data-autosubmit><?php foreach ($users as $id => $name): ?><option value="<?= (int) $id ?>"<?= selected($id, $emp['id']) ?>><?= e($name) ?></option><?php endforeach ?></select><?php endif ?>
    <label class="sr" for="lg-y">Year</label><select id="lg-y" name="year" data-autosubmit><?php for ($y = (int) date('Y') + 1; $y >= (int) date('Y') - 4; $y--): ?><option<?= selected($y, $year) ?>><?= $y ?></option><?php endfor ?></select>
    <label class="sr" for="lg-t">Leave type</label><select id="lg-t" name="type" data-autosubmit><option value="">All leave types</option><?php foreach ($types as $t): ?><option value="<?= (int) $t['id'] ?>"<?= selected($t['id'], $typeId) ?>><?= e($t['name']) ?></option><?php endforeach ?></select>
  </form>
</div>
<?php if ($paid): ?>
<div class="grid g4 mb">
  <?php foreach ($paid as $b): $c = in_array($b['color'], ['mint', 'peach', 'sky', 'lav', 'lemon', 'pink'], true) ? $b['color'] : 'lav'; $s = $sums[(int) $b['id']] ?? ['cr' => 0, 'dr' => 0]; $gap = round((float) $b['allocated'] - (float) $b['used'] - ((float) $s['cr'] + (float) $s['dr']), 2); ?>
  <a class="tile bg-<?= e($c) ?> lv-tile" href="<?= e($q(['type' => $b['id']])) ?>">
    <span class="tile-ic"><?= icon('umbrella') ?></span>
    <div><div class="num"><?= e(LS::num((float) $b['allocated'] - (float) $b['used'])) ?></div><div class="lbl"><?= e($b['name']) ?> balance</div>
      <div class="hr-tsub">+<?= e(LS::num($b['allocated'])) ?> credited · <?= e(LS::num($b['used'])) ?> used<?= (float) $b['pending'] ? ' · '.e(LS::num($b['pending'])).' pending' : '' ?></div>
      <?php if (abs($gap) >= 0.01): ?><div class="hr-tsub"><?= e(LS::num($gap)) ?> from changes before the ledger</div><?php endif ?></div>
  </a>
  <?php endforeach ?>
</div>
<?php endif ?>
<div class="card">
  <div class="card-head"><h3><?= icon('list') ?> Ledger <?= (int) $year ?></h3><span class="small muted"><?= count($rows) ?> entr<?= count($rows) === 1 ? 'y' : 'ies' ?></span></div>
  <?php if (!$rows): ?><?= empty_state('No entries yet', 'Accruals, allocations, leave taken and adjustments for '.(int) $year.' will appear here.', 'empty-box') ?><?php else: ?>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Date</th><th>Leave type</th><th>Entry</th><th>Details</th><th class="num">Days</th><th>By</th></tr></thead>
    <tbody><?php foreach ($rows as $r): $d = (float) $r['days']; ?><tr>
      <td class="nowrap small"><?= e(fdate($r['created_at'])) ?></td>
      <td><span class="pill pill-<?= e($r['color']) ?>"><?= e($r['code']) ?></span></td>
      <td><span class="pill pill-<?= e($kindPill[$r['kind']] ?? 'gray') ?>"><?= e(LS::KINDS[$r['kind']] ?? $r['kind']) ?></span><?php if ($r['period'] && preg_match('/^\d{4}-\d{2}$/', $r['period'])): ?> <span class="xs muted"><?= e(date('M Y', strtotime($r['period'].'-01'))) ?></span><?php endif ?></td>
      <td class="small"><?= e($r['note'] ?? '') ?></td>
      <td class="num b <?= $d < 0 ? 'hr-neg' : 'lv-pos' ?>"><?= $d > 0 ? '+' : '' ?><?= e(LS::num($d)) ?></td>
      <td class="small muted"><?= e($r['by_name'] ?? 'System') ?></td>
    </tr><?php endforeach ?></tbody>
  </table></div>
  <?php endif ?>
</div>
<?php if ($manage): ?>
<dialog class="modal" id="adjust"<?= errors() ? ' data-open' : '' ?> aria-labelledby="adjust-t">
  <div class="modal-head"><h3 id="adjust-t">Adjust <?= e($emp['name']) ?>'s balance</h3><button type="button" class="btn icon sm ghost" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div>
  <form class="modal-body form" method="post" action="<?= e(url('/hr/leave/balances/adjust')) ?>"><?= csrf_field() ?><input type="hidden" name="user_id" value="<?= (int) $emp['id'] ?>"><input type="hidden" name="return" value="ledger">
    <div class="form-grid">
      <div class="field"><label for="adj-t">Leave type *</label><select id="adj-t" name="leave_type_id" required><?php foreach ($types as $t): ?><option value="<?= (int) $t['id'] ?>"<?= selected($t['id'], old('leave_type_id', $typeId)) ?>><?= e($t['name']) ?></option><?php endforeach ?></select></div>
      <div class="field"><label for="adj-y">Year *</label><input id="adj-y" type="number" name="year" value="<?= (int) old('year', $year) ?>" min="2000" max="2100" required></div>
      <div class="field"><label for="adj-d">Adjust by (days) *</label><input id="adj-d" type="number" name="delta" step="0.5" min="-365" max="365" value="<?= e(old('delta')) ?>" placeholder="e.g. 2 or -1.5" required><div class="hint">Positive adds days, negative removes them.</div><?= error_for('delta') ?></div>
      <div class="field"><label for="adj-r">Reason *</label><input id="adj-r" type="text" name="reason" maxlength="255" value="<?= e(old('reason')) ?>" placeholder="Comp-off for Sunday work, correction…" required><?= error_for('reason') ?></div>
    </div>
    <div class="form-actions"><button type="button" class="btn ghost" data-close>Cancel</button><button class="btn primary"><?= icon('check', 'sm') ?> Apply adjustment</button></div>
  </form>
</dialog>
<?php endif ?>
