<?php $pages = \Modules\Admin\Controllers\SettingsController::PAGES; $grp = null; ?>
<nav class="card set-nav" aria-label="Settings sections">
<?php foreach ($tabs as $k => [$label, $ic, , $g]): ?><?php if ($g !== $grp): $grp = $g; ?><div class="grp"><?= e($g) ?></div><?php endif ?>
  <a href="<?= e(url($pages[$k] ?? ($k === 'overview' ? '/admin/settings' : '/admin/settings/'.$k))) ?>" class="<?= $k === $tab ? 'active' : '' ?>"<?= $k === $tab ? ' aria-current="page"' : '' ?>><?= icon($ic, 'sm') ?> <?= e($label) ?></a>
<?php endforeach ?>
</nav>
