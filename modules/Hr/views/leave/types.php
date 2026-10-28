<?php $typeForm = static function (array $t, array $colors): string { ob_start(); ?>
  <div class="form-grid">
    <div class="field"><label>Name *</label><input type="text" name="name" maxlength="100" value="<?= e($t['name'] ?? '') ?>" required></div>
    <div class="field"><label>Code *</label><input type="text" name="code" maxlength="20" value="<?= e($t['code'] ?? '') ?>" class="mono" placeholder="CL" required></div>
    <div class="field"><label>Annual quota (days) *</label><input type="number" name="annual_quota" step="0.5" min="0" max="365" value="<?= e($t['annual_quota'] ?? 0) ?>" required></div>
    <div class="field"><label>Colour</label><select name="color"><?php foreach ($colors as $c): ?><option value="<?= $c ?>"<?= selected($c, $t['color'] ?? 'mint') ?>><?= ucfirst($c) ?></option><?php endforeach ?></select></div>
    <label class="check"><input type="checkbox" name="paid" value="1"<?= checked($t['paid'] ?? 1) ?>> Paid leave (needs balance)</label>
    <label class="check"><input type="checkbox" name="active" value="1"<?= checked($t['active'] ?? 1) ?>> Active</label>
  </div>
<?php return (string) ob_get_clean(); }; ?>
<?= partial('partials/page_head', ['title' => 'Leave types', 'subtitle' => 'Quotas are used when allocating yearly balances.', 'crumbs' => [['Leave', '/hr/leave'], ['Types']], 'actions' => '<button type="button" class="btn primary" data-modal="type-new">'.icon('plus', 'sm').' New leave type</button>']) ?>
<?= partial('hr::leave/_tabs', ['tab' => $tab, 'pendingCount' => $pendingCount]) ?>
<?php if (errors()): ?><div class="alert error"><?= icon('alert', 'sm') ?> <?= e(implode(' ', errors())) ?></div><?php endif ?>
<div class="hr-types">
  <?php foreach ($types as $t): ?>
  <div class="card hr-type<?= (int) $t['active'] ? '' : ' off' ?>">
    <div class="flex between"><span class="hr-type-ic bg-<?= e($t['color']) ?>"><?= e($t['code']) ?></span><div class="flex"><?php if (!(int) $t['active']): ?><span class="pill pill-gray">Inactive</span><?php endif ?><span class="pill pill-<?= (int) $t['paid'] ? 'mint' : 'gray' ?>"><?= (int) $t['paid'] ? 'Paid' : 'Unpaid' ?></span></div></div>
    <h3 class="mt"><?= e($t['name']) ?></h3>
    <div class="stat"><b><?= e((float) $t['annual_quota']) ?></b><span class="small muted">days per year · <?= (int) $t['uses'] ?> request(s)</span></div>
    <div class="flex mt">
      <button type="button" class="btn sm ghost" data-modal="type-<?= (int) $t['id'] ?>"><?= icon('edit', 'sm') ?> Edit</button>
      <form method="post" action="<?= e(url('/hr/leave/types/'.$t['id'].'/delete')) ?>" data-confirm="<?= (int) $t['uses'] ? 'This type has requests, so it will be deactivated. Continue?' : 'Delete this leave type and its balances?' ?>"><?= csrf_field() ?><button class="btn sm danger"><?= icon('trash', 'sm') ?> <?= (int) $t['uses'] ? 'Deactivate' : 'Delete' ?></button></form>
    </div>
  </div>
  <dialog class="modal" id="type-<?= (int) $t['id'] ?>"><div class="modal-head"><h3>Edit <?= e($t['name']) ?></h3><button type="button" class="btn icon sm ghost" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div>
    <form class="modal-body form" method="post" action="<?= e(url('/hr/leave/types/'.$t['id'])) ?>"><?= csrf_field() ?><?= $typeForm($t, $colors) ?><div class="form-actions"><button type="button" class="btn ghost" data-close>Cancel</button><button class="btn primary">Save</button></div></form></dialog>
  <?php endforeach ?>
</div>
<dialog class="modal" id="type-new"><div class="modal-head"><h3>New leave type</h3><button type="button" class="btn icon sm ghost" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div>
  <form class="modal-body form" method="post" action="<?= e(url('/hr/leave/types')) ?>"><?= csrf_field() ?><?= $typeForm(['paid' => 1, 'active' => 1, 'color' => 'lav'], $colors) ?><div class="form-actions"><button type="button" class="btn ghost" data-close>Cancel</button><button class="btn primary"><?= icon('plus', 'sm') ?> Add type</button></div></form></dialog>
