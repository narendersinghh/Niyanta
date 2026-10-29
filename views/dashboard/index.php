<?php $u = user(); $h = (int) date('G'); $greet = $h < 12 ? 'Good morning' : ($h < 17 ? 'Good afternoon' : 'Good evening');
$quick = array_filter([
  can('projects.tasks.manage') ? ['/tasks', 'check-square', 'My tasks'] : (can('projects.view') ? ['/tasks', 'check-square', 'My tasks'] : null),
  can('attendance.track') ? ['/time', 'timer', 'Time tracking'] : null,
  can('crm.finance.manage') ? ['/invoices/create', 'receipt', 'New invoice'] : null,
  can('crm.clients.manage') ? ['/clients/create', 'briefcase', 'New client'] : null,
  can('hr.leave.request') ? ['/hr/leave', 'umbrella', 'Request leave'] : null], static fn ($q) => $q && route_on($q[0])); ?>
<section class="card hero dash-hero mb">
  <div>
    <span class="pill"><?= icon('calendar', 'sm') ?> <?= e(date('l, d F Y')) ?></span>
    <h1 class="mt"><?= e($greet) ?>, <?= e(explode(' ', $u['name'])[0]) ?>! 👋</h1>
    <p class="mb0">Here's what's happening across your workspace today.</p>
    <?php if ($quick): ?><div class="hero-actions"><?php foreach (array_slice($quick, 0, 4) as [$href, $ic, $label]): ?><a class="btn ghost sm" href="<?= e(url($href)) ?>"><?= icon($ic, 'sm') ?> <?= e($label) ?></a><?php endforeach ?></div><?php endif ?>
  </div>
  <?= illus('dashboard-hero') ?>
</section>
<?php if ($widgets['kpis']): ?><div class="grid g4 mb kpi-row"><?php foreach ($widgets['kpis'] as $w): ?><?= $w['html'] ?><?php endforeach ?></div><?php endif ?>
<div class="grid g-side">
  <div class="stack"><?php foreach ($widgets['main'] as $w): ?><?= $w['html'] ?><?php endforeach ?>
    <?php if (!$widgets['main'] && !$widgets['kpis']): ?><div class="card"><?= empty_state('Your dashboard is ready', 'Features you have access to will show their highlights here.', 'empty-box') ?></div><?php endif ?>
  </div>
  <div class="stack"><?php foreach ($widgets['side'] as $w): ?><?= $w['html'] ?><?php endforeach ?></div>
</div>
