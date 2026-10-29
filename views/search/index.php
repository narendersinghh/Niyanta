<?php $total = array_sum(array_column($groups, 'count')); $slug = static fn (string $s) => 'sg-'.preg_replace('/[^a-z0-9]+/', '-', strtolower($s)); ?>
<?= partial('partials/page_head', ['title' => 'Search', 'subtitle' => mb_strlen($q) >= 2 ? $total.' result'.($total === 1 ? '' : 's').' for “'.$q.'”. Only records you are allowed to see are shown.' : 'Search across clients, projects, tasks, invoices, people and documents.']) ?>
<form class="card search-page-bar mb" method="get" action="<?= e(url('/search')) ?>"><?= icon('search') ?><input type="search" name="q" value="<?= e($q) ?>" placeholder="Type at least 2 characters" autofocus class="grow" aria-label="Search"><button class="btn primary">Search</button></form>
<?php if ($groups): ?>
  <nav class="chips mb search-cats" aria-label="Categories"><?php foreach ($groups as $g): ?><a class="chip" href="#<?= e($slug($g['label'])) ?>"><span class="sr-dot" style="background:var(--<?= e($g['color']) ?>)"></span><?= e($g['label']) ?> <b><?= (int) $g['count'] ?></b></a><?php endforeach ?></nav>
  <div class="search-groups">
  <?php foreach ($groups as $g): ?>
    <section class="card search-group" id="<?= e($slug($g['label'])) ?>" style="--gc:var(--<?= e($g['color']) ?>)">
      <header class="card-head"><div class="flex"><span class="sg-ic"><?= icon($g['icon']) ?></span><h3><?= e($g['label']) ?></h3></div><span class="pill pill-gray"><?= (int) $g['count'] ?></span></header>
      <div class="list"><?php foreach ($g['items'] as $r): ?><a class="list-item" href="<?= e(url($r['url'])) ?>"><span class="notif-ic"><?= icon($r['icon'] ?? $g['icon'], 'sm') ?></span><div class="grow"><b class="truncate"><?= e($r['title']) ?></b><?php if (!empty($r['meta'])): ?><div class="meta truncate"><?= e($r['meta']) ?></div><?php endif ?></div><?= icon('chevron-right', 'sm') ?></a><?php endforeach ?></div>
    </section>
  <?php endforeach ?>
  </div>
<?php elseif (mb_strlen($q) >= 2): ?><div class="card"><?= empty_state('Nothing found', 'Try a different keyword, a client name, project code or invoice number.', 'empty-box') ?></div>
<?php else: ?><div class="card"><?= empty_state('What are you looking for?', 'Tip: press / anywhere to jump to search. Results appear as you type.', 'empty-box') ?></div><?php endif ?>
