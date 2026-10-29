<?php
use Modules\Admin\Services\SettingsSchema;
$pages = \Modules\Admin\Controllers\SettingsController::PAGES;
$byGroup = [];
foreach ($tabs as $k => $t) { $byGroup[$t[5]][$k] = $t; }
$warn = array_keys(array_filter($status, static fn ($s) => $s[1] === 'warn'));
?>
<div class="set-shell">
<?= partial('admin::settings/_nav', ['tabs' => $tabs, 'tab' => 'home']) ?>
<div class="set-main">
<?= partial('partials/page_head', ['title' => 'Settings', 'subtitle' => 'Everything that shapes your workspace, organised like the product. Only Super Admins see this area; every change is audit-logged.', 'crumbs' => [['System'], ['Settings']]]) ?>
<div class="card set-hero">
  <div class="grow">
    <span class="pill pill-<?= $warn ? 'lemon' : 'mint' ?>"><?= $warn ? count($warn).' item'.(count($warn) > 1 ? 's need' : ' needs').' attention' : 'Everything looks good' ?></span>
    <h2><?= e(setting('branding.app_name', 'Niyanta')) ?> workspace</h2>
    <p class="muted small mb0">Start with <b>General</b> and <b>Email</b>, then tune each product area. Need sample data to explore? Load the <a href="<?= e(url('/admin/settings/demo')) ?>">demo agency</a>.</p>
    <?php if ($warn): ?><div class="chips mt"><?php foreach ($warn as $k): ?><a class="chip" href="<?= e(url($pages[$k] ?? '/admin/settings/'.$k)) ?>"><span class="tone-dot warn"></span><?= e($tabs[$k][0]) ?> · <?= e($status[$k][0]) ?></a><?php endforeach ?></div><?php endif ?>
  </div>
  <?= illus('settings-gears', 'set-hero-illus') ?>
</div>
<?php foreach (SettingsSchema::GROUPS as $gk => [$gl, $gd, $gi, $gc]): if (empty($byGroup[$gk])) { continue; } ?>
<section class="set-group">
  <header class="set-group-head"><span class="ic-chip sm" style="--c:var(--<?= e($gc) ?>)"><?= icon($gi, 'sm') ?></span><div><h3><?= e($gl) ?></h3><p><?= e($gd) ?></p></div></header>
  <div class="set-tiles">
  <?php foreach ($byGroup[$gk] as $k => [$label, $ic, $desc, , $c, , $bundle]): [$st, $tone] = $status[$k] ?? ['', 'info']; $off = $bundle && !\Core\Features::bundleEnabled($bundle); ?>
    <a class="card set-tile<?= $off ? ' is-off' : '' ?>" href="<?= e(url($pages[$k] ?? '/admin/settings/'.$k)) ?>">
      <span class="ic-chip" style="--c:var(--<?= e($c) ?>)"><?= icon($ic) ?></span>
      <span class="st-body"><b><?= e($label) ?></b><span class="st-desc"><?= e($desc) ?></span></span>
      <span class="st-status"><span class="tone-dot <?= e($off ? 'off' : $tone) ?>"></span><?= e($off ? 'Bundle switched off' : $st) ?></span>
    </a>
  <?php endforeach ?>
  </div>
</section>
<?php endforeach ?>
</div>
</div>
