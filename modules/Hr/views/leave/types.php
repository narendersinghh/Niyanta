<?php use Modules\Hr\Services\LeaveService as LS;
$typeForm = static function (array $t, array $colors): string { ob_start(); $isOh = ($t['code'] ?? '') === LS::OPTIONAL_CODE; ?>
  <div class="form-grid">
    <div class="field"><label>Name *</label><input type="text" name="name" maxlength="100" value="<?= e($t['name'] ?? '') ?>" required></div>
    <div class="field"><label>Code *</label><input type="text" name="code" maxlength="20" value="<?= e($t['code'] ?? '') ?>" class="mono" placeholder="e.g. WFH" required<?= $isOh ? ' readonly' : '' ?>><?php if ($isOh): ?><div class="hint">The OH code powers optional festival days and cannot change.</div><?php endif ?></div>
    <div class="field"><label>Yearly quota (days) *</label><input type="number" name="annual_quota" step="0.5" min="0" max="365" value="<?= e(LS::num($t['annual_quota'] ?? 0)) ?>" required><div class="hint">Default for everyone; HR can override per employee.</div></div>
    <div class="field"><label>Colour</label><select name="color"><?php foreach ($colors as $c): ?><option value="<?= $c ?>"<?= selected($c, $t['color'] ?? 'mint') ?>><?= ucfirst($c) ?></option><?php endforeach ?></select></div>
    <label class="check"><input type="checkbox" name="paid" value="1"<?= checked($t['paid'] ?? 1) ?>> Paid leave (needs balance)</label>
    <label class="check"><input type="checkbox" name="active" value="1"<?= checked($t['active'] ?? 1) ?>> Active</label>
  </div>
<?php return (string) ob_get_clean(); }; ?>
<?= partial('partials/page_head', ['title' => 'Leave types', 'subtitle' => 'Keep it simple: fixed holidays + one optional-day pool. Add other types only if you really need them.', 'crumbs' => [['HR Admin'], ['Leave', '/hr/leave'], ['Types']], 'actions' => '<button type="button" class="btn primary" data-modal="type-new">'.icon('plus', 'sm').' New leave type</button>']) ?>
<?= partial('hr::leave/_tabs', ['tab' => $tab, 'pendingCount' => $pendingCount]) ?>
<?php if (errors()): ?><div class="alert error"><?= icon('alert', 'sm') ?> <?= e(implode(' ', errors())) ?></div><?php endif ?>
<div class="card mb hr-oh-card">
  <span class="hr-ic sky"><?= icon('gift', 'lg') ?></span>
  <div><h3 class="mb0">Optional holidays per year</h3><p class="small muted mb0">One pool employees use for optional festivals (<?= (int) $optionalDays ?> suggested this year), personal errands or medical days. Per-employee overrides live on each profile’s <b>Work schedule & leave</b> tab.</p></div>
  <?php if ($optional): ?>
  <form class="hr-oh-form" method="post" action="<?= e(url('/hr/leave/optional-quota')) ?>"><?= csrf_field() ?>
    <div class="field"><label for="oh-q">Days per year</label><input id="oh-q" type="number" name="quota" step="0.5" min="0" max="365" value="<?= e(LS::num(old('quota', $ohDefault))) ?>" required></div>
    <label class="check small"><input type="checkbox" name="apply_current" value="1" checked> Also update <?= date('Y') ?> balances</label>
    <button class="btn primary"><?= icon('check', 'sm') ?> Save</button>
  </form>
  <?php else: ?><div class="alert warn mb0"><?= icon('alert', 'sm') ?> Create an active leave type with code <b>OH</b> to enable optional holidays.</div><?php endif ?>
</div>
<div class="hr-types">
  <?php foreach ($types as $t): $isOh = $t['code'] === LS::OPTIONAL_CODE; ?>
  <div class="card hr-type<?= (int) $t['active'] ? '' : ' off' ?>">
    <div class="flex between"><span class="hr-type-ic hr-c-<?= e($t['color']) ?>"><?= e($t['code']) ?></span><div class="flex"><?php if ($isOh): ?><span class="pill pill-sky">Optional pool</span><?php endif ?><?php if (!(int) $t['active']): ?><span class="pill pill-gray">Inactive</span><?php endif ?><span class="pill pill-<?= (int) $t['paid'] ? 'mint' : 'gray' ?>"><?= (int) $t['paid'] ? 'Paid' : 'Unpaid' ?></span></div></div>
    <div><h3 class="mb0"><?= e($t['name']) ?></h3><div class="small muted"><?= (int) $t['uses'] ?> request(s)<?= (int) $t['overrides'] ? ' · '.(int) $t['overrides'].' personal override(s)' : '' ?></div></div>
    <div class="stat"><b><?= e(LS::num($t['annual_quota'])) ?></b><span class="small muted">days per year by default</span></div>
    <div class="hr-type-foot">
      <button type="button" class="btn sm ghost" data-modal="type-<?= (int) $t['id'] ?>"><?= icon('edit', 'sm') ?> Edit</button>
      <?php if (!$isOh): ?><form method="post" action="<?= e(url('/hr/leave/types/'.$t['id'].'/delete')) ?>" data-confirm="<?= (int) $t['uses'] ? 'This type has requests, so it will be deactivated. Continue?' : 'Delete this leave type and its balances?' ?>"><?= csrf_field() ?><button class="btn sm danger"><?= icon('trash', 'sm') ?> <?= (int) $t['uses'] ? 'Deactivate' : 'Delete' ?></button></form><?php endif ?>
    </div>
  </div>
  <dialog class="modal" id="type-<?= (int) $t['id'] ?>"><div class="modal-head"><h3>Edit <?= e($t['name']) ?></h3><button type="button" class="btn icon sm ghost" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div>
    <form class="modal-body form" method="post" action="<?= e(url('/hr/leave/types/'.$t['id'])) ?>"><?= csrf_field() ?><?= $typeForm($t, $colors) ?><div class="form-actions"><button type="button" class="btn ghost" data-close>Cancel</button><button class="btn primary"><?= icon('check', 'sm') ?> Save</button></div></form></dialog>
  <?php endforeach ?>
</div>
<dialog class="modal" id="type-new"><div class="modal-head"><h3>New leave type</h3><button type="button" class="btn icon sm ghost" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div>
  <form class="modal-body form" method="post" action="<?= e(url('/hr/leave/types')) ?>"><?= csrf_field() ?><?= $typeForm(['paid' => 1, 'active' => 1, 'color' => 'lav'], $colors) ?><div class="form-actions"><button type="button" class="btn ghost" data-close>Cancel</button><button class="btn primary"><?= icon('plus', 'sm') ?> Add type</button></div></form></dialog>
