<?php use Modules\Hr\Services\LeaveService as LS; $prof = feature_on('employees'); $cover = $stats['active'] ? (int) round($stats['users'] / $stats['active'] * 100) : 0; ?>
<?= partial('partials/page_head', ['title' => 'Leave balances', 'subtitle' => 'Balances per leave type and person: yearly quotas, monthly accruals, carry forward and adjustments. Open a person to see their ledger.', 'crumbs' => [['HR Admin'], ['Leave', '/hr/leave'], ['Balances']],
  'actions' => '<button type="button" class="btn ghost" data-modal="runs">'.icon('refresh', 'sm').' Accrual & year end</button><button type="button" class="btn ghost" data-modal="adjust">'.icon('sliders', 'sm').' Adjust balance</button><form method="post" action="'.e(url('/hr/leave/balances/allocate')).'" data-confirm="Allocate '.(int) $year.' balances to every active employee who does not have them yet? Personal quota overrides are respected.">'.csrf_field().'<input type="hidden" name="year" value="'.(int) $year.'"><button class="btn primary">'.icon('refresh', 'sm').' Allocate '.(int) $year.'</button></form>']) ?>
<?= partial('hr::leave/_tabs', ['tab' => $tab, 'pendingCount' => $pendingCount]) ?>
<div class="grid g4 mb">
  <div class="tile bg-lav"><span class="tile-ic"><?= icon('users') ?></span><div><div class="num"><?= (int) $stats['users'] ?><small>/ <?= (int) $stats['active'] ?></small></div><div class="lbl">Employees allocated</div></div></div>
  <div class="tile bg-mint"><span class="tile-ic"><?= icon('gift') ?></span><div><div class="num"><?= e(LS::num($stats['allocated'])) ?></div><div class="lbl">Days allocated</div></div></div>
  <div class="tile bg-peach"><span class="tile-ic"><?= icon('plane') ?></span><div><div class="num"><?= e(LS::num($stats['used'])) ?></div><div class="lbl">Days used</div></div></div>
  <div class="tile bg-sky"><span class="tile-ic"><?= icon('pie') ?></span><div><div class="num"><?= $cover ?>%</div><div class="lbl">Coverage</div><div class="hr-tbar"><span style="width:<?= $cover ?>%"></span></div></div></div>
</div>
<?php if ($cover < 100): ?><div class="alert warn"><?= icon('alert', 'sm') ?> <?= (int) $stats['active'] - (int) $stats['users'] ?> active employee(s) have no <?= (int) $year ?> balance yet. Click “Allocate <?= (int) $year ?>”, existing balances are never overwritten.</div><?php endif ?>
<div class="card">
  <form class="hr-toolbar" method="get">
    <label class="hr-search"><span class="sr">Search</span><?= icon('search', 'sm') ?><input type="search" name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="Search employee or code"></label>
    <select name="year" data-autosubmit aria-label="Year"><?php for ($y = (int) date('Y') + 1; $y >= (int) date('Y') - 3; $y--): ?><option<?= selected($y, $year) ?>><?= $y ?></option><?php endfor ?></select>
    <?php if (($_GET['q'] ?? '') !== ''): ?><a class="btn sm ghost" href="<?= e(url('/hr/leave/balances?year='.$year)) ?>"><?= icon('x', 'sm') ?> Clear</a><?php endif ?>
  </form>
  <?php if (!$p['items']): ?><?= empty_state('No employees found', 'Try another name or employee code.', 'people-hr') ?><?php else: ?>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Employee <span class="xs muted">(open for ledger)</span></th><?php foreach ($types as $t): ?><th class="c"><span class="pill pill-<?= e($t['color']) ?>"><?= e($t['code']) ?></span><div class="xs muted hr-th-sub"><?= (int) $t['paid'] ? ($t['allowance'] === 'monthly' ? 'left / credited' : 'left / allocated') : 'used' ?></div></th><?php endforeach ?></tr></thead>
    <tbody><?php foreach ($p['items'] as $u): ?><tr>
      <td><a class="hr-who" href="<?= e(url('/hr/leave/ledger?user='.$u['id'].'&year='.$year)) ?>" title="Ledger of <?= e($u['name']) ?>"><?= avatar($u['name'], $u['avatar'], 'sm') ?><div><b class="small"><?= e($u['name']) ?></b><div class="xs muted"><?= e($u['department'] ?: ($u['employee_code'] ?: '')) ?></div></div></a></td>
      <?php foreach ($types as $t): $b = $map[$u['id']][$t['id']] ?? null; ?>
      <td class="c nowrap"><?php if (!$b): ?><span class="muted">-</span><?php elseif ((int) $t['paid']): $left = (float) $b['allocated'] - (float) $b['used']; ?><a class="lv-cell" href="<?= e(url('/hr/leave/ledger?user='.$u['id'].'&year='.$year.'&type='.$t['id'])) ?>" title="Ledger"><b class="<?= $left <= 0 ? 'hr-neg' : '' ?>"><?= e(LS::num($left)) ?></b><span class="muted small"> / <?= e(LS::num($b['allocated'])) ?></span></a><?php else: ?><b><?= e(LS::num($b['used'])) ?></b><?php endif ?></td>
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
<dialog class="modal" id="runs" aria-labelledby="runs-t">
  <div class="modal-head"><h3 id="runs-t">Accrual & year end</h3><button type="button" class="btn icon sm ghost" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div>
  <div class="modal-body form">
    <p class="small muted">Both run automatically (accrual daily for the current month, year end on 1 January). Running them again never credits twice.</p>
    <form class="lv-run" method="post" action="<?= e(url('/hr/leave/balances/accrue')) ?>"><?= csrf_field() ?>
      <div class="field"><label for="acc-m">Credit monthly accrual for</label><input id="acc-m" type="month" name="month" value="<?= e(date('Y-m')) ?>" max="<?= e(date('Y-m')) ?>" required><div class="hint">Active people who joined by day <?= (int) LS::accrualCutoff() ?> of that month.</div></div>
      <button class="btn primary"><?= icon('plus', 'sm') ?> Credit accrual</button>
    </form>
    <form class="lv-run" method="post" action="<?= e(url('/hr/leave/balances/carry')) ?>" data-confirm="Close <?= (int) date('Y') - 1 ?>? Remainders move to <?= (int) date('Y') ?> per each leave type's carry-forward rule; the rest lapses."><?= csrf_field() ?>
      <div class="field"><label for="cf-y">Close a past year</label><select id="cf-y" name="year"><?php for ($y = (int) date('Y') - 1; $y >= (int) date('Y') - 3; $y--): ?><option><?= $y ?></option><?php endfor ?></select><div class="hint">Carry forward or lapse remainders into the next year.</div></div>
      <button class="btn ghost"><?= icon('refresh', 'sm') ?> Close year</button>
    </form>
  </div>
</dialog>
