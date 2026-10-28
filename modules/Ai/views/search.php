<?php
$labels = ['entity' => 'Looking in', 'keywords' => 'Matching', 'status' => 'Status', 'date_from' => 'From', 'date_to' => 'To', 'assignee_me' => 'Assigned to me', 'overdue' => 'Overdue only'];
$examples = ['My overdue tasks', 'Active clients in healthcare', 'Projects with a deadline this month', 'Unpaid invoices from last month', 'Designers in the marketing team'];
?>
<?= partial('partials/page_head', ['title' => 'Ask your workspace', 'subtitle' => 'Ask in plain English. The assistant only interprets your question — results come straight from records you are allowed to see.', 'crumbs' => [['AI Assistant', '/ai'], ['Search']]]) ?>
<div class="card hero ai-hero mb">
  <div>
    <form class="ai-ask" method="get" action="<?= e(url('/ai/search')) ?>" role="search" style="margin-top:0"><?= icon('sparkles', 'sm') ?><input type="search" name="q" value="<?= e($q) ?>" maxlength="300" placeholder="e.g. overdue invoices for Acme" aria-label="Ask your workspace" autofocus><button class="btn primary sm"><?= icon('search', 'sm') ?> Ask</button></form>
    <div class="ai-chips"><?php foreach ($examples as $ex): ?><a href="<?= e(url('/ai/search').'?q='.rawurlencode($ex)) ?>"><?= e($ex) ?></a><?php endforeach ?></div>
    <?php if ($entities): ?><p class="xs muted mt mb0">You can search: <?= e(implode(', ', $entities)) ?>.</p><?php endif ?>
  </div>
  <?= illus('ai-robot') ?>
</div>
<?php if ($notice): ?><div class="alert info"><?= icon('info', 'sm') ?> <div><?= e($notice) ?></div></div><?php endif ?>
<?php if ($spec): ?>
<div class="card mb">
  <div class="card-head"><h4 class="flex"><?= icon('filter', 'sm') ?> How I understood your question</h4><span class="xs muted">Not right? Rephrase and ask again.</span></div>
  <div class="ai-filters">
    <?php foreach ($spec as $k => $v): if ($v === null || $v === '' || $v === false) { continue; } ?>
      <span class="chip"><?= e($labels[$k]) ?><?php if ($v !== true): ?>: <b><?= e(str_replace('_', ' ', (string) $v)) ?></b><?php endif ?></span>
    <?php endforeach ?>
  </div>
  <?php foreach ($result['notes'] ?? [] as $n): ?><p class="xs muted mt mb0"><?= e($n) ?></p><?php endforeach ?>
</div>
<div class="card">
  <div class="card-head"><h3><?= e($result['label']) ?></h3><span class="pill pill-gray"><?= count($result['rows']) ?><?= count($result['rows']) >= 30 ? '+' : '' ?></span></div>
  <?php if (!$result['rows']): ?><?= empty_state('No matching records', 'Try fewer words or a broader question.', 'empty-box') ?><?php else: ?>
  <div class="list ai-results"><?php foreach ($result['rows'] as $r): ?>
    <a class="list-item" href="<?= e(url($r['url'])) ?>"><span class="notif-ic"><?= icon($r['icon'], 'sm') ?></span><div class="grow"><b><?= e($r['title']) ?></b><?php if ($r['meta'] !== ''): ?><div class="meta"><?= e($r['meta']) ?></div><?php endif ?></div><?= $r['status'] ? badge($r['status']) : '' ?><?= icon('chevron-right', 'sm') ?></a>
  <?php endforeach ?></div>
  <?php endif ?>
</div>
<?php elseif ($q !== '' && mb_strlen($q) >= 2): ?>
  <?php if (!$groups): ?><div class="card"><?= empty_state('Nothing found', 'Try a different keyword.', 'empty-box') ?></div><?php endif ?>
  <div class="grid g2">
  <?php foreach ($groups as $label => $rows): ?>
    <div class="card"><div class="card-head"><h3><?= e($label) ?></h3><span class="pill pill-gray"><?= count($rows) ?></span></div><div class="list ai-results">
    <?php foreach ($rows as $r): ?><a class="list-item" href="<?= e(url($r['url'])) ?>"><span class="notif-ic"><?= icon($r['icon'] ?? 'file', 'sm') ?></span><div class="grow"><b><?= e($r['title']) ?></b><?php if (!empty($r['meta'])): ?><div class="meta"><?= e($r['meta']) ?></div><?php endif ?></div><?= icon('chevron-right', 'sm') ?></a><?php endforeach ?>
    </div></div>
  <?php endforeach ?>
  </div>
<?php endif ?>
