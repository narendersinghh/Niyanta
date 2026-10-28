<?php use Modules\Projects\Services\ProjectService as PS; ?>
<div class="card"><?= $css ?>
  <div class="card-head"><div><h3>My tasks</h3><p class="xs muted mb0"><?= (int) $counts['open'] ?> open<?= $counts['late'] ? ' · <span class="late-text">'.(int) $counts['late'].' overdue</span>' : '' ?></p></div><a class="btn sm ghost" href="<?= e(url('/tasks')) ?>">View all <?= icon('arrow-right', 'sm') ?></a></div>
  <?php if (!$tasks): ?><?= empty_state('All caught up!', 'No open tasks assigned to you.', 'celebrate') ?><?php else: ?>
  <div class="wtasks">
    <?php foreach ($tasks as $t): ?>
    <a class="wtask ac-<?= e($t['color']) ?>" href="<?= e(url('/tasks/'.$t['id'])) ?>">
      <span class="wt-ic"><?= icon($t['status'] === 'review' ? 'eye' : ($t['status'] === 'in_progress' ? 'activity' : 'check-square'), 'sm') ?></span>
      <span class="grow"><b class="truncate"><?= e($t['title']) ?></b><span class="xs muted truncate"><?= e($t['project']) ?></span></span>
      <?= badge($t['status'], PS::TASK_STATUSES[$t['status']]) ?>
      <?= PS::dueChip($t['due_date'], $t['status']) ?>
    </a>
    <?php endforeach ?>
  </div>
  <?php endif ?>
</div>
