<?php $pages = \Modules\Admin\Controllers\SettingsController::PAGES; $grp = null; ?>
<nav class="set-nav" aria-label="Settings sections">
  <a href="<?= e(url('/admin/settings')) ?>" class="set-home<?= $tab === 'home' ? ' active' : '' ?>"><span class="sn-ic" style="--c:var(--primary)"><?= icon('grid', 'sm') ?></span> All settings</a>
<?php foreach ($tabs as $k => [$label, $ic, , $g, $c]): ?><?php if ($g !== $grp): $grp = $g; ?><div class="grp"><?= e($g) ?></div><?php endif ?>
  <a href="<?= e(url($pages[$k] ?? '/admin/settings/'.$k)) ?>" class="<?= $k === $tab ? 'active' : '' ?>"<?= $k === $tab ? ' aria-current="page"' : '' ?>><span class="sn-ic" style="--c:var(--<?= e($c) ?>)"><?= icon($ic, 'sm') ?></span> <span class="truncate"><?= e($label) ?></span></a>
<?php endforeach ?>
</nav>
