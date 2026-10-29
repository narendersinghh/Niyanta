<?= partial('partials/page_head', ['title' => 'Search', 'subtitle' => $q !== '' ? 'Results for “'.$q.'”, only records you are allowed to see are shown.' : 'Search across clients, projects, tasks, invoices and people.']) ?>
<form class="card flex mb" method="get" action="<?= e(url('/search')) ?>"><?= icon('search') ?><input type="search" name="q" value="<?= e($q) ?>" placeholder="Type at least 2 characters…" autofocus class="grow"><button class="btn primary">Search</button>
<?php if (\Core\Modules::enabled('ai') && can('ai.search')): ?><a class="btn soft" href="<?= e(url('/ai/search').'?q='.rawurlencode($q)) ?>"><?= icon('sparkles', 'sm') ?> Ask AI</a><?php endif ?></form>
<?php if ($q !== '' && !$groups): ?><div class="card"><?= empty_state('Nothing found', 'Try a different keyword.', 'empty-box') ?></div><?php endif ?>
<div class="grid g2">
<?php foreach ($groups as $label => $rows): ?>
  <div class="card"><div class="card-head"><h3><?= e($label) ?></h3><span class="pill pill-gray"><?= count($rows) ?></span></div><div class="list">
  <?php foreach ($rows as $r): ?><a class="list-item" href="<?= e(url($r['url'])) ?>"><span class="notif-ic"><?= icon($r['icon'] ?? 'file', 'sm') ?></span><div class="grow"><b><?= e($r['title']) ?></b><?php if (!empty($r['meta'])): ?><div class="meta"><?= e($r['meta']) ?></div><?php endif ?></div><?= icon('chevron-right', 'sm') ?></a><?php endforeach ?>
  </div></div>
<?php endforeach ?>
</div>
