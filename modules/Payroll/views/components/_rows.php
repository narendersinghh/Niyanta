<?php /* $rows: pay_components; $empty: text */ ?>
<?php if (!$rows): ?><p class="muted small mb0"><?= e($empty) ?></p><?php else: ?>
<div class="list"><?php foreach ($rows as $c): ?>
  <div class="list-item<?= $c['active'] ? '' : ' pay-off' ?>">
    <span class="pay-ic <?= $c['kind'] === 'allowance' ? 'mint' : 'pink' ?>"><?= icon($c['kind'] === 'allowance' ? 'plus' : 'minus', 'sm') ?></span>
    <div class="grow"><b><?= e($c['name']) ?></b><div class="meta"><?= $c['calc'] === 'percent' ? e(rtrim(rtrim(number_format((float) $c['value'], 2), '0'), '.')).'% of base pay' : e(money($c['value'])).' fixed per month' ?><?= $c['active'] ? '' : ' · inactive' ?></div></div>
    <span class="pill pill-<?= $c['kind'] === 'allowance' ? 'mint' : 'pink' ?>"><?= e(ucfirst($c['kind'])) ?></span>
    <button class="btn icon sm ghost" type="button" data-modal="edit-comp-<?= (int) $c['id'] ?>" title="Edit"><?= icon('edit', 'sm') ?></button>
    <form method="post" action="<?= e(url('/payroll/components/'.$c['id'].'/delete')) ?>" data-confirm="Remove <?= e($c['name']) ?>? Existing payslips keep their amounts."><?= csrf_field() ?><button class="btn icon sm ghost" title="Delete" aria-label="Delete <?= e($c['name']) ?>"><?= icon('trash', 'sm') ?></button></form>
  </div>
  <dialog class="modal" id="edit-comp-<?= (int) $c['id'] ?>"><div class="modal-head"><h3>Edit <?= e($c['name']) ?></h3><button class="btn icon sm ghost" type="button" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div><div class="modal-body"><?= partial('payroll::components/_form', ['c' => $c]) ?></div></dialog>
<?php endforeach ?></div>
<?php endif ?>
