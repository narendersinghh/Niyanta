<?php use Modules\Projects\Services\ProjectService as PS; ?>
<div class="kanban board" data-board data-project="<?= (int) $pr['id'] ?>">
  <?php foreach (PS::TASK_STATUSES as $st => $label): $list = $cols[$st]; ?>
  <section class="kcol" data-status="<?= e($st) ?>" aria-label="<?= e($label) ?>">
    <div class="kcol-head"><span class="flex"><span class="dotc bg-<?= e(PS::TASK_COLORS[$st]) ?>"></span> <?= e($label) ?> <span class="pill pill-gray" data-count><?= count($list) ?></span></span>
      <?php if ($canTasks): ?><button type="button" class="btn icon sm ghost" data-modal="task-new" data-status="<?= e($st) ?>" aria-label="Add task to <?= e($label) ?>"><?= icon('plus', 'sm') ?></button><?php endif ?></div>
    <div class="kdrop">
      <?php foreach ($list as $t): $late = $t['due_date'] && $t['due_date'] < date('Y-m-d') && $st !== 'done'; ?>
      <article class="kcard<?= $t['editable'] ? '' : ' locked' ?><?= $late ? ' is-late' : '' ?>" data-id="<?= (int) $t['id'] ?>"<?= $t['editable'] ? ' draggable="true"' : '' ?>>
        <div class="flex between"><?= badge($t['priority'], PS::PRIORITIES[$t['priority']]) ?><?php if ($t['open_deps']): ?><span class="pill pill-red" title="Waiting on <?= (int) $t['open_deps'] ?> unfinished dependenc<?= $t['open_deps'] > 1 ? 'ies' : 'y' ?>"><?= icon('lock', 'sm') ?> Blocked</span><?php endif ?></div>
        <a class="kc-title" href="<?= e(url('/tasks/'.$t['id'])) ?>"><?= e($t['title']) ?></a>
        <?php if ($t['sub_total']): ?><div class="flex xs muted"><?= icon('check-square', 'sm') ?> <?= (int) $t['sub_done'] ?>/<?= (int) $t['sub_total'] ?><div class="progress grow thin"><span style="width:<?= (int) round($t['sub_done'] / $t['sub_total'] * 100) ?>%"></span></div></div><?php endif ?>
        <div class="kc-foot">
          <?= PS::dueChip($t['due_date'], $st) ?>
          <span class="flex"><?php if ($t['comments']): ?><span class="xs muted flex"><?= icon('message', 'sm') ?><?= (int) $t['comments'] ?></span><?php endif ?><?= $t['assignee'] ? avatar($t['assignee'], $t['avatar'], 'sm') : '<span class="avatar sm empty-av" title="Unassigned">?</span>' ?></span>
        </div>
      </article>
      <?php endforeach ?>
      <?php if (!$list): ?><div class="kempty xs muted">Drop tasks here</div><?php endif ?>
    </div>
  </section>
  <?php endforeach ?>
</div>
<?php if (!array_filter($cols)): ?><div class="card mt"><?= empty_state('The board is empty', $canTasks ? 'Add the first task to get the ball rolling.' : 'No tasks have been created yet.', 'kanban-board', $canTasks ? '<button type="button" class="btn primary mt" data-modal="task-new" data-status="todo">'.icon('plus', 'sm').' Add task</button>' : '') ?></div><?php endif ?>
<p class="xs muted mt"><?= icon('info', 'sm') ?> Drag cards between columns to change status. <?= $canTasks ? 'You can move any task in this project.' : 'You can move tasks assigned to you.' ?> Tasks with unfinished dependencies cannot be moved to Done.</p>
