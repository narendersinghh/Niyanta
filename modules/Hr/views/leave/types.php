<?php use Modules\Hr\Services\LeaveService as LS;
$err = errors(); $dlgErr = $err ? (string) old('_dlg') : '';
/* Leave type dialog (new and edit): a scrollable body of grouped sections (Basics, Allowance, Carry forward and limits, Rules, Hour shortages) above a
   sticky footer. leave.js shows only the fields that apply (paid, allowance, carry rule). Field names match LeaveController::typeData. */
$typeDialog = static function (string $dlg, string $title, string $sub, string $action, array $t, array $colors, string $btn) use ($dlgErr): string {
  $re = $dlgErr === $dlg;
  $v = static fn (string $k, mixed $def = '') => $re ? old($k, '') : ($t[$k] ?? $def);
  $c = static fn (string $k, int $def = 0) => $re ? (int) (bool) old($k, 0) : (int) ($t[$k] ?? $def);
  $n = static fn ($x) => $x === null || $x === '' ? '' : LS::num($x);
  $isOh = ($t['code'] ?? '') === LS::OPTIONAL_CODE; $id = static fn (string $f) => e($dlg.'-'.$f);
  $et = $re ? (array) old('employment_types', []) : array_filter(explode(',', (string) ($t['employment_types'] ?? '')));
  $err = static fn (string $k) => $re ? error_for($k) : '';
  $sec = static fn (string $ic, string $tone, string $h, string $p, string $hid = '') => '<header class="lv-sh"><span class="lv-si '.$tone.'">'.icon($ic, 'sm').'</span><div><h4 id="'.e($hid).'">'.e($h).'</h4><p>'.e($p).'</p></div></header>';
  $tog = static fn (string $name, bool $on, string $label, string $help, string $attr = '') => '<label class="lv-tog"><input type="checkbox" name="'.$name.'" value="1"'.$attr.($on ? ' checked' : '').'><span class="lv-sw" aria-hidden="true"></span><span class="lv-tt"><b>'.e($label).'</b><small>'.e($help).'</small></span></label>';
  $unit = static fn (string $input, string $u) => '<div class="lv-unit">'.$input.'<span aria-hidden="true">'.e($u).'</span></div>';
  $colorNames = ['lav' => 'Lavender'];
  $allow = ['yearly' => ['Yearly quota', 'Given on 1 January'], 'monthly' => ['Monthly accrual', 'Credited on the 1st'], 'none' => ['No allowance', 'HR adds days by hand']];
  $allowed = (string) $v('allowance', 'yearly');
  ob_start(); ?>
<dialog class="modal lv-modal" id="<?= e($dlg) ?>"<?= $re ? ' data-open' : '' ?> aria-labelledby="<?= $id('t') ?>">
  <form class="lv-form" method="post" action="<?= e(url($action)) ?>" data-lv-type><?= csrf_field() ?><input type="hidden" name="_dlg" value="<?= e($dlg) ?>">
    <div class="modal-head lv-head"><div class="grow"><h3 id="<?= $id('t') ?>"><?= e($title) ?></h3><p><?= e($sub) ?></p></div><button type="button" class="btn icon sm ghost" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div>
    <div class="lv-body">
      <section class="lv-sec" aria-labelledby="<?= $id('s1') ?>"><?= $sec('tag', 'lav', 'Basics', 'What people see when they request leave.', $dlg.'-s1') ?>
        <div class="lv-grid">
          <div class="field"><label for="<?= $id('name') ?>">Name *</label><input id="<?= $id('name') ?>" type="text" name="name" maxlength="100" value="<?= e($v('name')) ?>" placeholder="e.g. Paid Leave" required><?= $err('name') ?></div>
          <div class="field"><label for="<?= $id('code') ?>">Code *</label><input id="<?= $id('code') ?>" type="text" name="code" maxlength="20" value="<?= e($v('code')) ?>" class="mono" placeholder="e.g. PL" required<?= $isOh ? ' readonly' : '' ?>><div class="hint"><?= $isOh ? 'The OH code powers optional festival days and cannot change.' : 'Short label on calendars, letters and numbers only.' ?></div><?= $err('code') ?></div>
          <div class="field full"><label for="<?= $id('description') ?>">Description</label><input id="<?= $id('description') ?>" type="text" name="description" maxlength="255" value="<?= e($v('description')) ?>" placeholder="Shown to employees when they pick this type"></div>
          <fieldset class="field full lv-colors"><legend>Colour</legend><div class="lv-swatches"><?php foreach ($colors as $col): ?><label class="hr-c-<?= e($col) ?>" title="<?= e($colorNames[$col] ?? ucfirst($col)) ?>"><input type="radio" name="color" value="<?= e($col) ?>"<?= checked($col === $v('color', 'mint')) ?>><span aria-hidden="true"></span><span class="sr"><?= e($colorNames[$col] ?? ucfirst($col)) ?></span></label><?php endforeach ?></div></fieldset>
          <?= $tog('paid', (bool) $c('paid', 1), 'Paid leave', 'Uses a balance; pay is not cut.', ' data-lv-paid') ?>
          <?= $tog('active', (bool) $c('active', 1), 'Active', 'Employees can pick it in new requests.') ?>
        </div></section>
      <section class="lv-sec" data-lv-when="paid" aria-labelledby="<?= $id('s2') ?>"><?= $sec('gift', 'mint', 'Allowance', 'How the balance builds up.', $dlg.'-s2') ?>
        <div class="lv-opts" role="radiogroup" aria-labelledby="<?= $id('s2') ?>"><?php foreach ($allow + array_diff_key(LS::ALLOWANCES, $allow) as $k => $l): [$lt, $ls] = is_array($l) ? $l : [$l, '']; ?>
          <label class="lv-opt"><input type="radio" name="allowance" value="<?= e($k) ?>" data-lv-allowance<?= checked($k === $allowed) ?>><span><b><?= e($lt) ?></b><?php if ($ls): ?><small><?= e($ls) ?></small><?php endif ?></span></label><?php endforeach ?></div>
        <div class="lv-grid lv-amount">
          <div class="field" data-lv-show="yearly"><label for="<?= $id('annual_quota') ?>">Days per year</label><?= $unit('<input id="'.$id('annual_quota').'" type="number" name="annual_quota" step="0.5" min="0" max="365" value="'.e($n($v('annual_quota', 0))).'">', 'days') ?><div class="hint">Allocated on 1 January. HR can override it per employee.</div><?= $err('annual_quota') ?></div>
          <div class="field" data-lv-show="monthly"><label for="<?= $id('monthly_accrual') ?>">Days per month</label><?= $unit('<input id="'.$id('monthly_accrual').'" type="number" name="monthly_accrual" step="0.25" min="0" max="31" value="'.e($n($v('monthly_accrual', 0))).'" placeholder="2.5">', 'days') ?><div class="hint">Credited on the 1st of every month.</div><?= $err('monthly_accrual') ?></div>
          <p class="lv-note full" data-lv-show="none"><?= icon('info', 'sm') ?> No automatic balance. HR credits days by hand, for example comp-off.</p>
        </div></section>
      <section class="lv-sec" data-lv-when="paid" aria-labelledby="<?= $id('s3') ?>"><?= $sec('refresh', 'sky', 'Carry forward and limits', 'What happens to unused days.', $dlg.'-s3') ?>
        <div class="lv-grid">
          <div class="field"><label for="<?= $id('carry_forward') ?>">At year end</label><select id="<?= $id('carry_forward') ?>" name="carry_forward" data-lv-carry><?php foreach (LS::CARRY as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($k, $v('carry_forward', 'none')) ?>><?= e($l) ?></option><?php endforeach ?></select></div>
          <div class="field" data-lv-carry-show="limit"><label for="<?= $id('carry_limit') ?>">Carry forward up to</label><?= $unit('<input id="'.$id('carry_limit').'" type="number" name="carry_limit" step="0.5" min="0" max="365" value="'.e($n($v('carry_limit'))).'">', 'days') ?><?= $err('carry_limit') ?></div>
          <div class="field"><label for="<?= $id('max_balance') ?>">Maximum balance</label><?= $unit('<input id="'.$id('max_balance').'" type="number" name="max_balance" step="0.5" min="0" max="999" value="'.e($n($v('max_balance'))).'" placeholder="No cap">', 'days') ?><div class="hint">Accrual and carry forward stop here.</div></div>
        </div></section>
      <section class="lv-sec" aria-labelledby="<?= $id('s4') ?>"><?= $sec('check-circle', 'lemon', 'Rules', 'When and how people can book it.', $dlg.'-s4') ?>
        <div class="lv-grid">
          <?= $tog('allow_half_day', (bool) $c('allow_half_day', 1), 'Half days allowed', 'People can book a morning or afternoon.') ?>
          <?= $tog('needs_approval', (bool) $c('needs_approval', 1), 'Needs approval', 'Off: requests are booked straight away.') ?>
          <div class="field"><label for="<?= $id('notice_days') ?>">Notice</label><?= $unit('<input id="'.$id('notice_days').'" type="number" name="notice_days" step="1" min="0" max="365" value="'.(int) $v('notice_days', 0).'">', 'days ahead') ?><div class="hint">0 = can be taken on the day or after (emergencies).</div></div>
          <div class="field"><label for="<?= $id('max_consecutive') ?>">Longest single request</label><?= $unit('<input id="'.$id('max_consecutive').'" type="number" name="max_consecutive" step="0.5" min="0.5" max="365" value="'.e($n($v('max_consecutive'))).'" placeholder="No limit">', 'days') ?><div class="hint">Leave empty for no limit.</div></div>
          <h5 class="lv-sub full">Who can use it</h5>
          <div class="field"><label for="<?= $id('min_service_days') ?>">After days of service</label><?= $unit('<input id="'.$id('min_service_days').'" type="number" name="min_service_days" step="1" min="0" max="3650" value="'.(int) $v('min_service_days', 0).'">', 'days') ?><div class="hint">Counted from the joining date. 0 = from day one.</div></div>
          <fieldset class="field lv-et-f"><legend>Employment types</legend><div class="chips lv-et"><?php foreach (LS::EMPLOYMENT as $k => $l): ?><label class="chip"><input type="checkbox" name="employment_types[]" value="<?= e($k) ?>"<?= checked(!$et || in_array($k, $et, true)) ?>> <?= e($l) ?></label><?php endforeach ?></div><div class="hint">All ticked = everyone.</div></fieldset>
        </div></section>
      <section class="lv-sec" data-lv-when="paid" aria-labelledby="<?= $id('s5') ?>"><?= $sec('clock', 'peach', 'Hour shortages', 'Payroll cover when monthly hours fall short.', $dlg.'-s5') ?>
        <div class="lv-grid"><div class="full"><?= $tog('shortage_cover', (bool) $c('shortage_cover', 0), 'Covers a shortage of working hours', 'If someone works fewer hours than expected in a month, the shortage (in half days) is taken from this balance so their pay is not cut.') ?><?= $err('shortage_cover') ?></div></div></section>
    </div>
    <div class="lv-foot"><span class="xs muted">* Required</span><div class="form-actions"><button type="button" class="btn ghost" data-close>Cancel</button><button class="btn primary"><?= $btn ?></button></div></div>
  </form>
</dialog>
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
  <?= $typeDialog('type-'.$t['id'], 'Edit '.$t['name'], 'Changes apply to new requests and the next accrual.', '/hr/leave/types/'.$t['id'], $t, $colors, icon('check', 'sm').' Save policy') ?>
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
<?= $typeDialog('type-new', 'New leave type', 'Set the policy once. Balances and requests follow it.', '/hr/leave/types', ['paid' => 1, 'active' => 1, 'color' => 'lav', 'allowance' => 'yearly', 'carry_forward' => 'none', 'allow_half_day' => 1, 'needs_approval' => 1], $colors, icon('plus', 'sm').' Add type') ?>
