<?php
use Modules\Todos\Services\Todos as T;
/* One task row. $t (Todos::ROW_SQL row), $mode my|assigned|widget, $uid, $manage (may edit/delete), $return (local path) */
$isDone = $t['status'] === 'done';
[$dl, $tone] = T::due($t['due_date'], $t['status']);
$routine = $t['series_id'] && $t['frequency'];
$verb = $isDone ? 'Reopen' : 'Mark as done';
$editUrl = url('/todos?'.http_build_query(['view' => $mode === 'assigned' ? 'assigned' : 'my', 'edit' => (int) $t['id']]));
?>
<div class="td-row<?= $isDone ? ' is-done' : '' ?>" id="todo-<?= (int) $t['id'] ?>" data-todo>
  <form method="post" action="<?= e(url('/todos/'.$t['id'].'/'.($isDone ? 'reopen' : 'complete'))) ?>" data-todo-tick="<?= $isDone ? 'reopen' : 'done' ?>" data-no-lock="1"><?= csrf_field() ?><input type="hidden" name="return" value="<?= e($return) ?>"><input type="hidden" name="mode" value="<?= e($mode) ?>"><button class="td-tick" aria-pressed="<?= $isDone ? 'true' : 'false' ?>" aria-label="<?= e($verb.': '.$t['title']) ?>" title="<?= e($verb) ?>"><?= icon('check', 'sm') ?></button></form>
  <div class="td-main">
    <span class="td-title"><?= e($t['title']) ?></span>
    <div class="td-meta">
      <?php if ($isDone && $t['completed_at']): ?><span><?= icon('check-circle', 'sm') ?> Done <?= e(ago($t['completed_at'])) ?></span><?php elseif ($dl): ?><span class="td-due <?= e($tone) ?>"><?= icon('calendar', 'sm') ?> <?= e($dl) ?></span><?php endif ?>
      <?php if (!$isDone && !empty($t['stage']) && $t['stage'] !== 'todo'): ?><span class="pill pill-<?= $t['stage'] === 'review' ? 'lav' : 'sky' ?> td-rp"><?= e(T::STAGES[$t['stage']] ?? '') ?></span><?php endif ?>
      <?php if (!$isDone && !empty($t['start_date']) && $t['start_date'] !== $t['due_date']): ?><span title="Start date"><?= icon('play', 'sm') ?> Starts <?= e(T::due($t['start_date'], 'done')[0]) ?></span><?php endif ?>
      <?php if (($t['estimate_hours'] ?? null) !== null): ?><span title="Estimate"><?= icon('clock', 'sm') ?> <?= e(rtrim(rtrim(number_format((float) $t['estimate_hours'], 2, '.', ''), '0'), '.')) ?>h</span><?php endif ?>
      <?php if ($routine): ?><span class="pill pill-lav td-rp" title="Routine"><?= icon('refresh', 'sm') ?> <?= e(T::repeatLabel($t)) ?></span><?php endif ?>
      <?php if ($mode === 'assigned'): ?><span class="td-who"><?= avatar($t['assignee'], $t['assignee_avatar'], 'xs') ?> <?= e($t['assignee']) ?></span><?php if ((int) $t['created_by'] !== (int) $uid): ?><span>by <?= e($t['creator']) ?></span><?php endif ?>
      <?php elseif ((int) $t['created_by'] !== (int) $t['assignee_id']): ?><span><?= icon('user', 'sm') ?> From <?= e($t['creator']) ?></span><?php endif ?>
    </div>
    <?php if ($t['description'] && $mode !== 'widget'): ?><p class="td-desc"><?= e(str_limit($t['description'], 160)) ?></p><?php endif ?>
  </div>
  <div class="td-side">
    <span class="pill pill-<?= e(T::PRIO_PILL[$t['priority']] ?? 'gray') ?>"><?= e(T::PRIORITIES[$t['priority']] ?? $t['priority']) ?></span>
    <?php if ($manage && $mode !== 'widget'): ?>
    <details class="dd"><summary class="btn icon sm ghost" aria-label="Task actions"><?= icon('more', 'sm') ?></summary>
      <div class="dd-menu">
        <?php if (!$t['occurrence']): ?><a href="<?= e($editUrl) ?>"><?= icon('edit', 'sm') ?> Edit</a><?php elseif ($t['series_id']): ?><a href="<?= e(url('/todos?view=routines&edit_routine='.(int) $t['series_id'])) ?>"><?= icon('refresh', 'sm') ?> Edit routine</a><?php endif ?>
        <form method="post" action="<?= e(url('/todos/'.$t['id'].'/delete')) ?>" data-confirm="Delete this task?"><?= csrf_field() ?><input type="hidden" name="return" value="<?= e($return) ?>"><button class="td-del"><?= icon('trash', 'sm') ?> Delete</button></form>
      </div>
    </details>
    <?php endif ?>
  </div>
</div>
