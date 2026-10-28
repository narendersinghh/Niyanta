<?php use Modules\Ai\Controllers\ActionController; ?>
<?= partial('partials/page_head', ['title' => 'My AI actions', 'subtitle' => 'Suggestions waiting for your approval and everything you have approved, sent or rejected.', 'crumbs' => [['AI Assistant', '/ai'], ['Actions']], 'actions' => '<a class="btn ghost" href="'.e(url('/ai')).'">'.icon('sparkles', 'sm').' AI tools</a>']) ?>
<div class="card">
  <div class="flex between wrap mb">
    <nav class="tabs"><?php foreach (['' => 'All', 'pending' => 'Pending', 'executed' => 'Done', 'rejected' => 'Rejected', 'failed' => 'Failed'] as $k => $l): ?><a class="<?= $status === $k ? 'active' : '' ?>" href="<?= e(url('/ai/actions').($k ? '?status='.$k : '')) ?>"><?= e($l) ?><?= $k && !empty($counts[$k]) ? ' · '.(int) $counts[$k] : '' ?></a><?php endforeach ?></nav>
  </div>
  <?php if (!$p['items']): ?><?= empty_state('Nothing here yet', 'When the assistant suggests tasks, or you save or send something it drafted, it shows up here.', 'ai-robot', '<a class="btn primary" href="'.e(url('/ai')).'">'.icon('sparkles', 'sm').' Try a tool</a>') ?><?php else: ?>
  <div class="list ai-results">
    <?php foreach ($p['items'] as $a): $t = ActionController::TYPES[$a['type']] ?? [$a['type'], 'zap', 'lav']; ?>
    <a class="list-item" href="<?= e(url('/ai/actions/'.$a['id'])) ?>">
      <span class="notif-ic soft-<?= e($t[2]) ?>"><?= icon($t[1], 'sm') ?></span>
      <div class="grow"><b><?= e($a['title']) ?></b><div class="meta"><?= e($t[0]) ?> · <?= e(fdatetime($a['created_at'])) ?><?= $a['result'] ? ' · '.e($a['result']) : '' ?></div></div>
      <?= badge($a['status'] === 'executed' ? 'completed' : $a['status'], $a['status'] === 'executed' ? 'Done' : null) ?><?= icon('chevron-right', 'sm') ?>
    </a>
    <?php endforeach ?>
  </div>
  <?= pager($p) ?>
  <?php endif ?>
</div>
