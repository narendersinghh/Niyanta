<?php use Modules\Hr\Services\LeaveService as LS;
$err = errors(); $dlgErr = $err ? (string) old('_dlg') : '';
$typeForm = static function (array $t, array $colors, string $dlg) use ($dlgErr): string {
  $re = $dlgErr === $dlg;
  $v = static fn (string $k, mixed $def = '') => $re ? old($k, '') : ($t[$k] ?? $def);
  $c = static fn (string $k, int $def = 0) => $re ? (int) (bool) old($k, 0) : (int) ($t[$k] ?? $def);
  $n = static fn ($x) => $x === null || $x === '' ? '' : LS::num($x);
  $isOh = ($t['code'] ?? '') === LS::OPTIONAL_CODE; $id = static fn (string $f) => e($dlg.'-'.$f);
  $et = $re ? (array) old('employment_types', []) : array_filter(explode(',', (string) ($t['employment_types'] ?? '')));
  ob_start(); ?>
  <input type="hidden" name="_dlg" value="<?= e($dlg) ?>">
  <div class="lv-sec"><h4><?= icon('tag', 'sm') ?> Basics</h4>
    <div class="form-grid">
      <div class="field"><label for="<?= $id('name') ?>">Name *</label><input id="<?= $id('name') ?>" type="text" name="name" maxlength="100" value="<?= e($v('name')) ?>" placeholder="e.g. Paid Leave" required><?= $re ? error_for('name') : '' ?></div>
      <div class="field"><label for="<?= $id('code') ?>">Code *</label><input id="<?= $id('code') ?>" type="text" name="code" maxlength="20" value="<?= e($v('code')) ?>" class="mono" placeholder="e.g. PL" required<?= $isOh ? ' readonly' : '' ?>><?php if ($isOh): ?><div class="hint">The OH code powers optional festival days and cannot change.</div><?php endif ?><?= $re ? error_for('code') : '' ?></div>
      <div class="field full"><label for="<?= $id('description') ?>">Description</label><input id="<?= $id('description') ?>" type="text" name="description" maxlength="255" value="<?= e($v('description')) ?>" placeholder="Shown to employees when they pick this type"></div>
      <div class="field"><label for="<?= $id('color') ?>">Colour</label><select id="<?= $id('color') ?>" name="color"><?php foreach ($colors as $col): ?><option value="<?= e($col) ?>"<?= selected($col, $v('color', 'mint')) ?>><?= e(ucfirst($col)) ?></option><?php endforeach ?></select></div>
      <div class="field lv-checks"><label class="check"><input type="checkbox" name="paid" value="1" data-lv-paid<?= checked($c('paid', 1)) ?>> Paid leave (uses a balance)</label><label class="check"><input type="checkbox" name="active" value="1"<?= checked($c('active', 1)) ?>> Active</label></div>
    </div></div>
  <div class="lv-sec" data-lv-when="paid"><h4><?= icon('gift', 'sm') ?> Allowance</h4>
    <div class="form-grid">
      <div class="field"><label for="<?= $id('allowance') ?>">How the balance builds up</label><select id="<?= $id('allowance') ?>" name="allowance" data-lv-allowance><?php foreach (LS::ALLOWANCES as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($k, $v('allowance', 'yearly')) ?>><?= e($l) ?></option><?php endforeach ?></select><div class="hint">None: HR credits days by hand (e.g. comp-off).</div></div>
      <div class="field" data-lv-show="yearly"><label for="<?= $id('annual_quota') ?>">Days per year</label><input id="<?= $id('annual_quota') ?>" type="number" name="annual_quota" step="0.5" min="0" max="365" value="<?= e($n($v('annual_quota', 0))) ?>"><div class="hint">Allocated on 1 January; HR can override per employee.</div><?= $re ? error_for('annual_quota') : '' ?></div>
      <div class="field" data-lv-show="monthly"><label for="<?= $id('monthly_accrual') ?>">Days per month</label><input id="<?= $id('monthly_accrual') ?>" type="number" name="monthly_accrual" step="0.25" min="0" max="31" value="<?= e($n($v('monthly_accrual', 0))) ?>" placeholder="2.5"><div class="hint">Credited on the 1st of every month.</div><?= $re ? error_for('monthly_accrual') : '' ?></div>
      <div class="field"><label for="<?= $id('carry_forward') ?>">At year end</label><select id="<?= $id('carry_forward') ?>" name="carry_forward" data-lv-carry><?php foreach (LS::CARRY as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($k, $v('carry_forward', 'none')) ?>><?= e($l) ?></option><?php endforeach ?></select></div>
      <div class="field" data-lv-carry-show="limit"><label for="<?= $id('carry_limit') ?>">Carry forward up to (days)</label><input id="<?= $id('carry_limit') ?>" type="number" name="carry_limit" step="0.5" min="0" max="365" value="<?= e($n($v('carry_limit'))) ?>"><?= $re ? error_for('carry_limit') : '' ?></div>
      <div class="field"><label for="<?= $id('max_balance') ?>">Maximum balance (days)</label><input id="<?= $id('max_balance') ?>" type="number" name="max_balance" step="0.5" min="0" max="999" value="<?= e($n($v('max_balance'))) ?>" placeholder="No cap"><div class="hint">Accrual and carry forward stop at this balance.</div></div>
    </div></div>
  <div class="lv-sec"><h4><?= icon('check-circle', 'sm') ?> Request rules</h4>
    <div class="form-grid">
      <div class="field"><label for="<?= $id('notice_days') ?>">Notice (days ahead)</label><input id="<?= $id('notice_days') ?>" type="number" name="notice_days" step="1" min="0" max="365" value="<?= (int) $v('notice_days', 0) ?>"><div class="hint">0 = can be taken on the day or afterwards (emergencies).</div></div>
      <div class="field"><label for="<?= $id('max_consecutive') ?>">Max days in one request</label><input id="<?= $id('max_consecutive') ?>" type="number" name="max_consecutive" step="0.5" min="0.5" max="365" value="<?= e($n($v('max_consecutive'))) ?>" placeholder="No limit"></div>
      <div class="field lv-checks full"><label class="check"><input type="checkbox" name="allow_half_day" value="1"<?= checked($c('allow_half_day', 1)) ?>> Half days allowed</label><label class="check"><input type="checkbox" name="needs_approval" value="1"<?= checked($c('needs_approval', 1)) ?>> Needs approval (off: booked straight away)</label></div>
    </div></div>
  <div class="lv-sec"><h4><?= icon('users', 'sm') ?> Who can use it</h4>
    <div class="form-grid">
      <div class="field"><label for="<?= $id('min_service_days') ?>">After days of service</label><input id="<?= $id('min_service_days') ?>" type="number" name="min_service_days" step="1" min="0" max="3650" value="<?= (int) $v('min_service_days', 0) ?>"><div class="hint">Counted from the joining date; 0 = from day one.</div></div>
      <div class="field"><span class="lbl-like">Employment types</span><div class="chips lv-et"><?php foreach (LS::EMPLOYMENT as $k => $l): ?><label class="chip"><input type="checkbox" name="employment_types[]" value="<?= e($k) ?>"<?= checked(!$et || in_array($k, $et, true)) ?>> <?= e($l) ?></label><?php endforeach ?></div><div class="hint">All ticked = everyone.</div></div>
    </div></div>
  <div class="lv-sec" data-lv-when="paid"><h4><?= icon('clock', 'sm') ?> Payroll</h4>
    <label class="check"><input type="checkbox" name="shortage_cover" value="1"<?= checked($c('shortage_cover', 0)) ?>> Covers a shortage of working hours</label>
    <div class="hint">If someone works fewer hours than expected in a month, the shortage (in half days) is taken from this balance so their pay is not cut.</div><?= $re ? error_for('shortage_cover') : '' ?></div>
<?php return (string) ob_get_clean(); }; ?>
<?= partial('partials/page_head', ['title' => 'Leave types', 'subtitle' => 'Create your own leave types and policies: how the balance builds up, what carries forward, notice, eligibility and hour shortage cover.', 'crumbs' => [['HR Admin'], ['Leave', '/hr/leave'], ['Types']], 'actions' => (can('__super_admin') ? '<a class="btn ghost" href="'.e(url('/admin/settings/leave')).'">'.icon('settings', 'sm').' Leave settings</a>' : '').'<button type="button" class="btn primary" data-modal="type-new">'.icon('plus', 'sm').' New leave type</button>']) ?>
<?= partial('hr::leave/_tabs', ['tab' => $tab, 'pendingCount' => $pendingCount]) ?>
<?php if ($err && $dlgErr === ''): ?><div class="alert error"><?= icon('alert', 'sm') ?> <?= e(implode(' ', $err)) ?></div><?php endif ?>
<div class="card mb lv-explain">
  <span class="hr-ic mint"><?= icon('info', 'lg') ?></span>
  <div><h3 class="mb0">How balances work</h3><p class="small muted mb0">Monthly types are credited on the 1st (people who join after day <?= (int) $cutoff ?> start the next month). On 1 January yearly quotas are allocated and each remainder is carried forward or lapses per the type's rule. Every change is listed in each person's ledger. Hour shortage cover is <b><?= $shortageOn ? 'on' : 'off' ?></b>.</p></div>
  <a class="btn sm soft" href="<?= e(url('/hr/leave/balances')) ?>"><?= icon('pie', 'sm') ?> Balances</a>
</div>
<div class="hr-types">
  <?php foreach ($types as $t): $isOh = $t['code'] === LS::OPTIONAL_CODE; ?>
  <div class="card hr-type<?= (int) $t['active'] ? '' : ' off' ?>">
    <div class="flex between"><span class="hr-type-ic hr-c-<?= e($t['color']) ?>"><?= e($t['code']) ?></span><div class="flex wrap"><?php if ($isOh): ?><span class="pill pill-sky">Optional pool</span><?php endif ?><?php if ((int) $t['shortage_cover']): ?><span class="pill pill-lav">Shortage cover</span><?php endif ?><?php if (!(int) $t['active']): ?><span class="pill pill-gray">Inactive</span><?php endif ?><span class="pill pill-<?= (int) $t['paid'] ? 'mint' : 'gray' ?>"><?= (int) $t['paid'] ? 'Paid' : 'Unpaid' ?></span></div></div>
    <div><h3 class="mb0"><?= e($t['name']) ?></h3><div class="small muted"><?= (int) $t['uses'] ?> request(s)<?= (int) $t['overrides'] ? ' · '.(int) $t['overrides'].' personal override(s)' : '' ?></div><?php if ($t['description']): ?><p class="small mb0 mt"><?= e($t['description']) ?></p><?php endif ?></div>
    <div class="stat"><?php if ((int) $t['paid'] && $t['allowance'] === 'monthly'): ?><b><?= e(LS::num($t['monthly_accrual'])) ?></b><span class="small muted">days every month</span><?php elseif ((int) $t['paid'] && $t['allowance'] === 'yearly'): ?><b><?= e(LS::num($t['annual_quota'])) ?></b><span class="small muted">days per year by default</span><?php elseif ((int) $t['paid']): ?><b>HR</b><span class="small muted">credits days by hand</span><?php else: ?><b>-</b><span class="small muted">no balance needed</span><?php endif ?></div>
    <ul class="lv-policy"><?php foreach (LS::policySummary($t) as $line): ?><li><?= icon('check', 'sm') ?> <?= e(ucfirst($line)) ?></li><?php endforeach ?></ul>
    <div class="hr-type-foot">
      <button type="button" class="btn sm ghost" data-modal="type-<?= (int) $t['id'] ?>"><?= icon('edit', 'sm') ?> Edit policy</button>
      <?php if (!$isOh): ?><form method="post" action="<?= e(url('/hr/leave/types/'.$t['id'].'/delete')) ?>" data-confirm="<?= (int) $t['uses'] ? 'This type has requests, so it will be deactivated. Continue?' : 'Delete this leave type and its balances?' ?>"><?= csrf_field() ?><button class="btn sm danger"><?= icon('trash', 'sm') ?> <?= (int) $t['uses'] ? 'Deactivate' : 'Delete' ?></button></form><?php endif ?>
    </div>
  </div>
  <dialog class="modal lv-modal" id="type-<?= (int) $t['id'] ?>"<?= $dlgErr === 'type-'.$t['id'] ? ' data-open' : '' ?> aria-labelledby="type-<?= (int) $t['id'] ?>-t"><div class="modal-head"><h3 id="type-<?= (int) $t['id'] ?>-t">Edit <?= e($t['name']) ?></h3><button type="button" class="btn icon sm ghost" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div>
    <form class="modal-body form" method="post" action="<?= e(url('/hr/leave/types/'.$t['id'])) ?>" data-lv-type><?= csrf_field() ?><?= $typeForm($t, $colors, 'type-'.$t['id']) ?><div class="form-actions"><button type="button" class="btn ghost" data-close>Cancel</button><button class="btn primary"><?= icon('check', 'sm') ?> Save policy</button></div></form></dialog>
  <?php endforeach ?>
</div>
<?php if ($optional): ?>
<div class="card mt hr-oh-card">
  <span class="hr-ic sky"><?= icon('gift', 'lg') ?></span>
  <div><h3 class="mb0">Optional holidays per year</h3><p class="small muted mb0">The OH pool employees use for optional festivals (<?= (int) $optionalDays ?> listed this year). Per-employee overrides live on each profile's <b>Work schedule & leave</b> tab.</p></div>
  <form class="hr-oh-form" method="post" action="<?= e(url('/hr/leave/optional-quota')) ?>"><?= csrf_field() ?>
    <div class="field"><label for="oh-q">Days per year</label><input id="oh-q" type="number" name="quota" step="0.5" min="0" max="365" value="<?= e(LS::num(old('quota', $ohDefault))) ?>" required></div>
    <label class="check small"><input type="checkbox" name="apply_current" value="1" checked> Also update <?= date('Y') ?> balances</label>
    <button class="btn primary"><?= icon('check', 'sm') ?> Save</button>
  </form>
</div>
<?php endif ?>
<dialog class="modal lv-modal" id="type-new"<?= $dlgErr === 'type-new' ? ' data-open' : '' ?> aria-labelledby="type-new-t"><div class="modal-head"><h3 id="type-new-t">New leave type</h3><button type="button" class="btn icon sm ghost" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div>
  <form class="modal-body form" method="post" action="<?= e(url('/hr/leave/types')) ?>" data-lv-type><?= csrf_field() ?><?= $typeForm(['paid' => 1, 'active' => 1, 'color' => 'lav', 'allowance' => 'yearly', 'carry_forward' => 'none', 'allow_half_day' => 1, 'needs_approval' => 1], $colors, 'type-new') ?><div class="form-actions"><button type="button" class="btn ghost" data-close>Cancel</button><button class="btn primary"><?= icon('plus', 'sm') ?> Add type</button></div></form></dialog>
