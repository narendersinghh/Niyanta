<?php use Modules\Hr\Services\LeaveService as LS;
/* $balances, $year, optional $extra (html tiles appended) */
$tiles = array_values(array_filter($balances, static fn ($b) => (int) $b['paid'] === 1 || (float) $b['used'] > 0 || (float) $b['pending'] > 0));
usort($tiles, static fn ($a, $b) => ($b['code'] === LS::OPTIONAL_CODE) <=> ($a['code'] === LS::OPTIONAL_CODE)); ?>
<?php if ($tiles || !empty($extra)): ?>
<div class="grid g4 mb">
  <?php foreach ($tiles as $b): $paid = (int) $b['paid'] === 1; $oh = $b['code'] === LS::OPTIONAL_CODE; $c = in_array($b['color'], ['mint', 'peach', 'sky', 'lav', 'lemon', 'pink'], true) ? $b['color'] : 'lav'; ?>
  <div class="tile bg-<?= e($c) ?>">
    <span class="tile-ic"><?= icon($oh ? 'gift' : ($paid ? 'umbrella' : 'calendar')) ?></span>
    <div>
      <?php if ($paid && !$b['allocated_row']): ?>
        <div class="num">—</div><div class="lbl"><?= e($b['name']) ?></div><div class="hr-tsub">Not allocated for <?= (int) $year ?> yet</div>
      <?php elseif ($paid): $pct = (float) $b['allocated'] > 0 ? min(100, (int) round(((float) $b['used'] + (float) $b['pending']) / (float) $b['allocated'] * 100)) : 0; ?>
        <div class="num"><?= e(LS::num(max(0, (float) $b['available']))) ?><small>/ <?= e(LS::num($b['allocated'])) ?></small></div>
        <div class="lbl"><?= $oh ? e(LS::num(max(0, (float) $b['available']))).' of '.e(LS::num($b['allocated'])).' optional days left' : e($b['name']).' left' ?></div>
        <div class="hr-tbar" role="progressbar" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100" aria-label="<?= e($b['name']) ?> used"><span style="width:<?= $pct ?>%"></span></div>
        <div class="hr-tsub">Used <?= e(LS::num($b['used'])) ?><?= (float) $b['pending'] ? ' · '.e(LS::num($b['pending'])).' pending' : '' ?></div>
      <?php else: ?>
        <div class="num"><?= e(LS::num($b['used'])) ?></div><div class="lbl"><?= e($b['name']) ?> taken</div><div class="hr-tsub">Unpaid days in <?= (int) $year ?><?= (float) $b['pending'] ? ' · '.e(LS::num($b['pending'])).' pending' : '' ?></div>
      <?php endif ?>
    </div>
  </div>
  <?php endforeach ?>
  <?= $extra ?? '' ?>
</div>
<?php endif ?>
