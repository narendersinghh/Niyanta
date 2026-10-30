<?php /* Settings navigation: category tabs on top (Overview + one per bundle), and a side panel listing only the selected category's pages. $tabs, $tab */
$pages = \Modules\Admin\Controllers\SettingsController::PAGES;
$cats = [];
foreach ($tabs as $k => $t) { $cats[$t[5]] ??= ['label' => $t[3], 'first' => $k, 'items' => []]; $cats[$t[5]]['items'][$k] = $t; }
$short = ['workspace' => ['Workspace', 'globe'], 'people' => ['HR & People', 'users'], 'work' => ['Team & Work', 'kanban'], 'clients' => ['Clients & Billing', 'briefcase'], 'system' => ['System', 'server']];
$cur = $tab === 'home' ? 'home' : ($tabs[$tab][5] ?? 'home');
$solo = $cur === 'home' || count($cats[$cur]['items'] ?? []) < 2;
$href = static fn (string $k): string => url($pages[$k] ?? '/admin/settings/'.$k); ?>
<nav class="tabs set-cats<?= $solo ? ' home' : '' ?>" aria-label="Settings categories">
  <a href="<?= e(url('/admin/settings')) ?>" class="<?= $cur === 'home' ? 'active' : '' ?>"<?= $cur === 'home' ? ' aria-current="page"' : '' ?>><?= icon('grid', 'sm') ?> Overview</a>
  <?php foreach ($cats as $ck => $c): [$cl, $ci] = $short[$ck] ?? [$c['label'], 'sliders']; ?><a href="<?= e($href($c['first'])) ?>" class="<?= $ck === $cur ? 'active' : '' ?>"<?= $ck === $cur ? ' aria-current="true"' : '' ?> title="<?= e($c['label']) ?>"><?= icon($ci, 'sm') ?> <?= e($cl) ?></a><?php endforeach ?>
</nav>
<?php if (!$solo): ?>
<nav class="set-nav" aria-label="<?= e($cats[$cur]['label']) ?> settings">
  <div class="grp"><?= e($cats[$cur]['label']) ?></div>
<?php foreach ($cats[$cur]['items'] as $k => [$label, $ic, , , $c]): ?>
  <a href="<?= e($href($k)) ?>" class="<?= $k === $tab ? 'active' : '' ?>"<?= $k === $tab ? ' aria-current="page"' : '' ?>><span class="sn-ic" style="--c:var(--<?= e($c) ?>)"><?= icon($ic, 'sm') ?></span> <span class="truncate"><?= e($label) ?></span></a>
<?php endforeach ?>
</nav>
<?php endif ?>
