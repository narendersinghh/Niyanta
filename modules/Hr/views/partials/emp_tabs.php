<?php
/* Employees | Onboarding & exit tabs under the page header. $active: employees|processes */
$tabs = array_filter([
  'employees' => can('hr.employees.view') ? ['Employees', 'id-card', '/hr/employees'] : null,
  'processes' => feature_on('employees') && (can('hr.employees.manage') || can('hr.employees.review_team') || can('hr.employees.view')) ? ['Onboarding & exit', 'clipboard', '/hr/processes'] : null]);
?>
<?php if (count($tabs) > 1): ?><div class="mb"><nav class="tabs emp-tabs" aria-label="Employees sections"><?php foreach ($tabs as $k => [$l, $ic, $href]): ?><a href="<?= e(url($href)) ?>" class="<?= $k === $active ? 'active' : '' ?>"<?= $k === $active ? ' aria-current="page"' : '' ?>><?= icon($ic, 'sm') ?> <?= e($l) ?></a><?php endforeach ?></nav></div><?php endif ?>
