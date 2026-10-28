<?php $cover = $stats['active'] ? (int) round($stats['users'] / $stats['active'] * 100) : 0; ?>
<?= partial('partials/page_head', ['title' => 'Leave balances', 'subtitle' => 'Yearly allocations from leave type quotas, with manual adjustments.', 'crumbs' => [['Leave', '/hr/leave'], ['Balances']],
  'actions' => '<button type="button" class="btn ghost" data-modal="adjust">'.icon('sliders', 'sm').' Adjust balance</button><form method="post" action="'.e(url('/hr/leave/balances/allocate')).'" data-confirm="Allocate '.(int) $year.' balances from the leave type quotas to every active employee who does not have them yet?">'.csrf_field().'<input type="hidden" name="year" value="'.(int) $year.'"><button class="btn primary">'.icon('refresh', 'sm').' Allocate '.(int) $year.'</button></form>']) ?>
<?= partial('hr::leave/_tabs', ['tab' => $tab, 'pendingCount' => $pendingCount]) ?>
<div class="grid g4 mb">
  <div class="tile bg-lav"><span class="tile-ic"><?= icon('users') ?></span><div><div class="num"><?= (int) $stats['users'] ?><small class="hr-of">/<?= (int) $stats['active'] ?></small></div><div class="lbl">Employees allocated</div></div></div>
  <div class="tile bg-mint"><span class="tile-ic"><?= icon('gift') ?></span><div><div class="num"><?= e((float) $stats['allocated']) ?></div><div class="lbl">Days allocated</div></div></div>
  <div class="tile bg-peach"><span class="tile-ic"><?= icon('plane') ?></span><div><div class="num"><?= e((float) $stats['used']) ?></div><div class="lbl">Days used</div></div></div>
  <div class="tile dark"><span class="tile-ic"><?= icon('pie') ?></span><div><div class="num"><?= $cover ?>%</div><div class="lbl">Coverage</div></div><?= illus('calendar-person', 'corner') ?></div>
</div>
<?php if ($cover < 100): ?><div class="alert warn"><?= icon('alert', 'sm') ?> <?= (int) $stats['active'] - (int) $stats['users'] ?> active employee(s) have no <?= (int) $year ?> balance yet. Click “Allocate <?= (int) $year ?>” — existing balances are never overwritten.</div><?php endif ?>
<div class="card">
  <form class="filters" method="get">
    <select name="year" data-autosubmit><?php for ($y = (int) date('Y') + 1; $y >= (int) date('Y') - 3; $y--): ?><option<?= selected($y, $year) ?>><?= $y ?></option><?php endfor ?></select>
    <input type="search" name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="Search employee or code">
    <button class="btn ghost sm"><?= icon('filter', 'sm') ?> Filter</button>
  </form>
  <?php if (!$p['items']): ?><?= empty_state('No employees found', '', 'team') ?><?php else: ?>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Employee</th><?php foreach ($types as $t): ?><th class="center"><span class="pill pill-<?= e($t['color']) ?>"><?= e($t['code']) ?></span><div class="xs muted hr-th-sub"><?= (int) $t['paid'] ? 'left / allocated' : 'used' ?></div></th><?php endforeach ?></tr></thead>
    <tbody><?php foreach ($p['items'] as $u): ?><tr>
      <td><a class="flex" href="<?= e(url('/hr/employees/'.$u['id'].'?tab=leave')) ?>"><?= avatar($u['name'], $u['avatar'], 'sm') ?><div><b><?= e($u['name']) ?></b><div class="xs muted"><?= e($u['department'] ?: ($u['employee_code'] ?: '')) ?></div></div></a></td>
      <?php foreach ($types as $t): $b = $map[$u['id']][$t['id']] ?? null; ?>
      <td class="center"><?php if (!$b): ?><span class="muted">—</span><?php elseif ((int) $t['paid']): $left = (float) $b['allocated'] - (float) $b['used']; ?><b class="<?= $left <= 0 ? 'hr-neg' : '' ?>"><?= e($left) ?></b><span class="muted small"> / <?= e((float) $b['allocated']) ?></span><?php else: ?><b><?= e((float) $b['used']) ?></b><?php endif ?></td>
      <?php endforeach ?>
    </tr><?php endforeach ?></tbody>
  </table></div>
  <?= pager($p) ?>
  <?php endif ?>
</div>
<dialog class="modal" id="adjust"<?= errors() ? " data-open" : "" ?>>
  <div class="modal-head"><h3>Adjust leave balance</h3><button type="button" class="btn icon sm ghost" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div>
  <form class="modal-body form" method="post" action="<?= e(url('/hr/leave/balances/adjust')) ?>"><?= csrf_field() ?>
    <div class="form-grid">
      <div class="field full"><label>Employee *</label><select name="user_id" required><option value="">Choose…</option><?php foreach ($users as $uid => $un): ?><option value="<?= (int) $uid ?>"<?= selected($uid, old('user_id')) ?>><?= e($un) ?></option><?php endforeach ?></select><?= error_for('user_id') ?></div>
      <div class="field"><label>Leave type *</label><select name="leave_type_id" required><?php foreach ($types as $t): ?><option value="<?= (int) $t['id'] ?>"<?= selected($t['id'], old('leave_type_id')) ?>><?= e($t['name']) ?></option><?php endforeach ?></select></div>
      <div class="field"><label>Year *</label><input type="number" name="year" value="<?= (int) old('year', $year) ?>" min="2000" max="2100" required></div>
      <div class="field"><label>Adjust by (days) *</label><input type="number" name="delta" step="0.5" min="-365" max="365" value="<?= e(old('delta')) ?>" placeholder="e.g. 2 or -1.5" required><div class="hint">Positive adds days, negative removes them.</div><?= error_for('delta') ?></div>
      <div class="field"><label>Reason *</label><input type="text" name="reason" maxlength="255" value="<?= e(old('reason')) ?>" placeholder="Carry forward, comp-off…" required><?= error_for('reason') ?></div>
    </div>
    <div class="form-actions"><button type="button" class="btn ghost" data-close>Cancel</button><button class="btn primary"><?= icon('check', 'sm') ?> Apply adjustment</button></div>
  </form>
</dialog>
