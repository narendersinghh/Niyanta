<?php use Core\Features; ?>
<?= partial('admin::settings/_head', ['tabs' => $tabs, 'tab' => 'features', 'actions' => '<a class="btn primary" href="'.e(url('/admin/modules')).'">'.icon('puzzle', 'sm').' Manage features</a>']) ?>
<div class="feat-sum">
<?php foreach ($bundles as $bk => $b): $bon = Features::bundleEnabled($bk); $on = 0; foreach ($b['features'] as $fk => $f) { $on += (int) ($bon && (int) ($raw['feature:'.$fk] ?? 1) === 1); } ?>
  <section class="card feat-card<?= $bon ? '' : ' is-off' ?>" style="--bc:<?= e($b['color']) ?>">
    <header><span class="bundle-ic"><?= icon($b['icon']) ?></span><div class="grow"><h3><?= e($b['name']) ?></h3><span class="xs muted"><?= $on ?> of <?= count($b['features']) ?> features on</span></div><?= $bon ? badge('active', 'On') : badge('inactive', 'Off') ?></header>
    <ul><?php foreach ($b['features'] as $fk => $f): $fon = $bon && (int) ($raw['feature:'.$fk] ?? 1) === 1; ?><li class="<?= $fon ? '' : 'off' ?>"><?= icon($fon ? 'check-circle' : 'x', 'sm') ?> <?= e($f['name']) ?></li><?php endforeach ?></ul>
  </section>
<?php endforeach ?>
</div>
<div class="card info-card"><span class="ic-chip" style="--c:var(--sky)"><?= icon('info') ?></span><div class="grow"><h4>Switching features off never deletes data</h4><p class="small muted mb0">A disabled bundle or feature is hidden from navigation, dashboards and search, and its pages and permissions are blocked for everyone. Turn it back on and everything returns as it was. AI Assistant is parked for now.</p></div></div>
