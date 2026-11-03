<?php
use Modules\Projects\Services\ProjectService as PS;
use Modules\Todos\Services\Todos as T;
/* A project task in "My tasks" / "Assigned by me": tick to mark done (project status endpoint), title opens the task. $t (row with project, color, editable; assignee, assignee_avatar in assigned mode), $return, optional $mode my|assigned */
$mode ??= 'my';
$isDone = $t['status'] === 'done';
[$dl, $tone] = T::due($t['due_date'], $isDone ? 'done' : 'open');
?>
<div class="td-row td-proj<?= $isDone ? ' is-done' : '' ?>" id="ptask-<?= (int) $t['id'] ?>">
  <?php if ($isDone): ?><span class="td-tick" aria-hidden="true"><?= icon('check', 'sm') ?></span>
  <?php elseif ($t['editable']): ?><form method="post" action="<?= e(url('/tasks/'.$t['id'].'/status')) ?>"><?= csrf_field() ?><input type="hidden" name="status" value="done"><input type="hidden" name="return" value="<?= e($return) ?>"><button class="td-tick" aria-label="Mark <?= e($t['title']) ?> as done" title="Mark as done"></button></form>
  <?php else: ?><span class="td-tick is-off" aria-hidden="true"></span><?php endif ?>
  <div class="td-main">
    <a class="td-title" href="<?= e(url('/tasks/'.$t['id'])) ?>"><?= e($t['title']) ?></a>
    <div class="td-meta">
      <?php if ($isDone && !empty($t['completed_at'])): ?><span><?= icon('check-circle', 'sm') ?> Done <?= e(ago($t['completed_at'])) ?></span><?php elseif ($dl): ?><span class="td-due <?= e($tone) ?>"><?= icon('calendar', 'sm') ?> <?= e($dl) ?></span><?php endif ?>
      <span class="td-pj ac-<?= e($t['color'] ?: 'lav') ?>"><span class="dotc ac"></span><?= icon('kanban', 'sm') ?> <?= e($t['project']) ?><?= $t['parent_title'] ? ' · '.e(str_limit($t['parent_title'], 40)) : '' ?></span>
      <?php if ($mode === 'assigned' && !empty($t['assignee'])): ?><span class="td-who"><?= avatar($t['assignee'], $t['assignee_avatar'] ?? null, 'xs') ?> <?= e($t['assignee']) ?></span><?php endif ?>
      <?php if (!in_array($t['status'], ['todo', 'done'], true)): ?><?= badge($t['status'], PS::TASK_STATUSES[$t['status']] ?? $t['status']) ?><?php endif ?>
    </div>
  </div>
  <div class="td-side"><span class="pill pill-<?= e(['urgent' => 'red', 'high' => 'peach', 'medium' => 'lemon', 'low' => 'sky'][$t['priority']] ?? 'gray') ?>"><?= e(PS::PRIORITIES[$t['priority']] ?? $t['priority']) ?></span></div>
</div>
