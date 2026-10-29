<?php use Modules\Projects\Services\ProjectService as PS; ?>
<section class="card pj"><?= $css ?>
  <div class="card-head"><div><h3>My tasks</h3><p class="xs muted mb0"><?= (int) $counts['open'] ?> open<?= $counts['late'] ? ' · <span class="late-text">'.(int) $counts['late'].' overdue</span>' : '' ?></p></div><a class="btn sm ghost" href="<?= e(url('/tasks')) ?>">View all <?= icon('arrow-right', 'sm') ?></a></div>
  <?php if (!$tasks): ?><?= empty_state('All caught up!', 'No open tasks assigned to you.', 'celebrate') ?><?php else: ?>
  <div class="wtasks">
    <?php foreach ($tasks as $t): ?>
    <a class="wtask p-<?= e($t['priority']) ?>" href="<?= e(url('/tasks/'.$t['id'])) ?>">
      <span class="bar" title="<?= e(PS::PRIORITIES[$t['priority']]) ?> priority"></span>
      <span class="grow"><b><?= e($t['title']) ?></b><span class="xs muted"><?= e($t['project']) ?> · <?= e(PS::TASK_STATUSES[$t['status']]) ?></span></span>
      <?= PS::dueChip($t['due_date'], $t['status']) ?>
    </a>
    <?php endforeach ?>
  </div>
  <?php endif ?>
</section>
