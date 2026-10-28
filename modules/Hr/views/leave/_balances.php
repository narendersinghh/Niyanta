<?php if ($balances): ?>
<div class="grid g4 mb">
  <?php foreach ($balances as $b): $paid = (int) $b['paid'] === 1; ?>
  <div class="tile bg-<?= e($b['color']) ?> hr-bal">
    <div class="flex between"><span class="lbl"><?= e($b['name']) ?></span><span class="pill hr-code"><?= e($b['code']) ?></span></div>
    <div>
      <?php if ($paid): ?>
        <?php if (!$b['allocated_row']): ?><div class="num">—</div><div class="xs">Not allocated for <?= (int) $year ?></div>
        <?php else: $pct = (float) $b['allocated'] > 0 ? min(100, (int) round(((float) $b['used'] + (float) $b['pending']) / (float) $b['allocated'] * 100)) : 0; ?>
        <div class="num"><?= e((float) $b['available']) ?><small class="hr-of">/<?= e((float) $b['allocated']) ?></small></div>
        <div class="hr-bar"><span style="width:<?= $pct ?>%"></span></div>
        <div class="xs">Used <?= e((float) $b['used']) ?><?= (float) $b['pending'] ? ' · '.e((float) $b['pending']).' pending' : '' ?></div>
        <?php endif ?>
      <?php else: ?><div class="num"><?= e((float) $b['used']) ?></div><div class="xs">Unpaid days taken in <?= (int) $year ?><?= (float) $b['pending'] ? ' · '.e((float) $b['pending']).' pending' : '' ?></div><?php endif ?>
    </div>
  </div>
  <?php endforeach ?>
</div>
<?php endif ?>
