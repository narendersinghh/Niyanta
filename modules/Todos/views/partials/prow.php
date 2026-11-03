<?php
use Modules\Projects\Services\ProjectService as PS;
use Modules\Todos\Services\Todos as T;
/* A project task in "My tasks": tick to mark done (project status endpoint), title opens the task. $t (row with project, color, editable), $return */
[$dl, $tone] = T::due($t['due_date'], 'open');
?>
<div class="td-row td-proj" id="ptask-<?= (int) $t['id'] ?>">
  <?php if ($t['editable']): ?><form method="post" action="<?= e(url('/tasks/'.$t['id'].'/status')) ?>"><?= csrf_field() ?><input type="hidden" name="status" value="done"><input type="hidden" name="return" value="<?= e($return) ?>"><button class="td-tick" aria-label="Mark <?= e($t['title']) ?> as done" title="Mark as done"></button></form>
  <?php else: ?><span class="td-tick is-off" aria-hidden="true"></span><?php endif ?>
  <div class="td-main">
    <a class="td-title" href="<?= e(url('/tasks/'.$t['id'])) ?>"><?= e($t['title']) ?></a>
    <div class="td-meta">
      <?php if ($dl): ?><span class="td-due <?= e($tone) ?>"><?= icon('calendar', 'sm') ?> <?= e($dl) ?></span><?php endif ?>
      <span class="td-pj ac-<?= e($t['color'] ?: 'lav') ?>"><span class="dotc ac"></span><?= icon('kanban', 'sm') ?> <?= e($t['project']) ?><?= $t['parent_title'] ? ' · '.e(str_limit($t['parent_title'], 40)) : '' ?></span>
      <?php if ($t['status'] !== 'todo'): ?><?= badge($t['status'], PS::TASK_STATUSES[$t['status']] ?? $t['status']) ?><?php endif ?>
    </div>
  </div>
  <div class="td-side"><span class="pill pill-<?= e(['urgent' => 'red', 'high' => 'peach', 'medium' => 'lemon', 'low' => 'sky'][$t['priority']] ?? 'gray') ?>"><?= e(PS::PRIORITIES[$t['priority']] ?? $t['priority']) ?></span></div>
</div>
