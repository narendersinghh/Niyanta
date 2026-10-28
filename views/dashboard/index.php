<?php $u = user(); $h = (int) date('G'); $greet = $h < 12 ? 'Good morning' : ($h < 17 ? 'Good afternoon' : 'Good evening'); ?>
<div class="card hero mb">
  <div>
    <span class="pill pill-lav"><?= e(date('l, d F Y')) ?></span>
    <h1 class="mt"><?= e($greet) ?>, <?= e(explode(' ', $u['name'])[0]) ?>!</h1>
    <p class="muted mb0">Here's a quick overview of what's happening across your workspace today.</p>
  </div>
  <?= illus('dashboard-hero') ?>
</div>
<?php if ($widgets['kpis']): ?><div class="grid g4 mb"><?php foreach ($widgets['kpis'] as $w): ?><?= $w['html'] ?><?php endforeach ?></div><?php endif ?>
<div class="grid g-side">
  <div class="stack"><?php foreach ($widgets['main'] as $w): ?><?= $w['html'] ?><?php endforeach ?>
    <?php if (!$widgets['main'] && !$widgets['kpis']): ?><div class="card"><?= empty_state('Your dashboard is ready', 'Modules you have access to will show their highlights here.', 'empty-box') ?></div><?php endif ?>
  </div>
  <div class="stack"><?php foreach ($widgets['side'] as $w): ?><?= $w['html'] ?><?php endforeach ?></div>
</div>
