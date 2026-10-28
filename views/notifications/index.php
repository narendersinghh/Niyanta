<?= partial('partials/page_head', ['title' => 'Notifications', 'actions' => '<form method="post" action="'.e(url('/notifications/read')).'">'.csrf_field().'<button class="btn ghost sm">'.icon('check', 'sm').' Mark all read</button></form>']) ?>
<div class="card">
<?php if (!$p['items']): ?><?= empty_state('No notifications yet', 'Approvals, reminders and mentions will appear here.', 'mail-envelope') ?><?php else: ?>
  <div class="list"><?php foreach ($p['items'] as $n): ?>
    <a class="list-item" href="<?= e($n['url'] ? url($n['url']) : '#') ?>"><span class="notif-ic"><?= icon($n['icon'] ?: 'bell', 'sm') ?></span><div class="grow"><b><?= e($n['title']) ?></b><div class="meta"><?= e($n['body']) ?></div></div><?= $n['read_at'] ? '' : badge('pending', 'New') ?><span class="xs muted nowrap"><?= e(ago($n['created_at'])) ?></span></a>
  <?php endforeach ?></div><?= pager($p) ?>
<?php endif ?>
</div>
