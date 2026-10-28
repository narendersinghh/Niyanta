<?php $c ??= null; $uid ??= null; $fid = 'cf'.($c['id'] ?? 'new'.($uid ?? 'g')); ?>
<form class="form" method="post" action="<?= e(url($c ? '/payroll/components/'.$c['id'] : '/payroll/components')) ?>"><?= csrf_field() ?>
  <?php if (!$c && $uid): ?><input type="hidden" name="user_id" value="<?= (int) $uid ?>"><?php endif ?>
  <div class="field"><label for="<?= $fid ?>-name">Name *</label><input id="<?= $fid ?>-name" type="text" name="name" value="<?= e($c['name'] ?? '') ?>" maxlength="120" placeholder="e.g. HRA, Professional Tax" required></div>
  <div class="form-grid">
    <div class="field"><label for="<?= $fid ?>-kind">Type</label><select id="<?= $fid ?>-kind" name="kind"><option value="allowance"<?= selected('allowance', $c['kind'] ?? 'allowance') ?>>Allowance (+)</option><option value="deduction"<?= selected('deduction', $c['kind'] ?? '') ?>>Deduction (−)</option></select></div>
    <div class="field"><label for="<?= $fid ?>-calc">Calculation</label><select id="<?= $fid ?>-calc" name="calc"><option value="fixed"<?= selected('fixed', $c['calc'] ?? 'fixed') ?>>Fixed amount</option><option value="percent"<?= selected('percent', $c['calc'] ?? '') ?>>% of base pay</option></select></div>
  </div>
  <div class="field"><label for="<?= $fid ?>-value">Value *</label><input id="<?= $fid ?>-value" type="number" name="value" step="0.01" min="0" value="<?= e($c['value'] ?? '') ?>" required><div class="hint">Amount per month, or percentage of the employee's base pay for the period.</div></div>
  <label class="check"><input type="checkbox" name="active" value="1"<?= checked($c['active'] ?? 1) ?>> Active</label>
  <div class="form-actions"><?php if ($c): ?><button class="btn ghost" type="button" data-close>Cancel</button><?php endif ?><button class="btn primary"><?= icon('check', 'sm') ?> <?= $c ? 'Save changes' : 'Add component' ?></button></div>
</form>
