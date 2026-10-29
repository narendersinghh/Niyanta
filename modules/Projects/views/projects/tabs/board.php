<?php
use Modules\Projects\Services\ProjectService as PS;
$wipLimit = max(3, count($team));
?>
<?php if (!array_filter($cols)): ?><div class="card mb"><?= empty_state('The board is empty', $canTasks ? 'Add the first task with the button above or type straight into a column below.' : 'No tasks have been created for this project yet.', 'kanban-board') ?></div><?php endif ?>
<div class="kanban board" data-board data-project="<?= (int) $pr['id'] ?>" data-billable="<?= $pr['billing_type'] === 'non_billable' ? 0 : 1 ?>">
  <?php foreach (PS::TASK_STATUSES as $st => $label): $list = $cols[$st]; $wip = in_array($st, ['in_progress', 'review'], true); ?>
  <section class="kcol st-<?= e($st) ?>" data-status="<?= e($st) ?>" aria-label="<?= e($label) ?>">
    <div class="kcol-head"><span><?= e($label) ?></span><span class="kc-n" data-count><?= count($list) ?></span><?php if ($wip): ?><span class="wip<?= count($list) > $wipLimit ? ' over' : '' ?>" data-wip="<?= $wipLimit ?>" title="Work-in-progress limit (team size)">WIP <span data-wip-n><?= count($list) ?></span>/<?= $wipLimit ?></span><?php endif ?></div>
    <div class="kdrop">
      <?php foreach ($list as $t): ?><?= partial('projects::projects/kcard', ['t' => $t]) ?><?php endforeach ?>
      <div class="kempty<?= $list ? ' hide' : '' ?>"><?= $canTasks || $st !== 'todo' ? 'Drop tasks here' : 'Nothing here yet' ?></div>
    </div>
    <?php if ($canTasks): ?>
    <div class="kadd" data-kadd>
      <button type="button" class="kadd-btn" data-kadd-open><?= icon('plus', 'sm') ?> Add task</button>
      <form class="kadd-form" data-kadd-form data-status="<?= e($st) ?>"><textarea name="title" maxlength="255" rows="2" placeholder="Task title — press Enter to add" aria-label="New task title in <?= e($label) ?>" required></textarea>
        <div class="flex"><button type="button" class="btn sm ghost" data-kadd-close>Cancel</button><button class="btn sm primary"><?= icon('plus', 'sm') ?> Add</button></div></form>
    </div>
    <?php endif ?>
  </section>
  <?php endforeach ?>
</div>
<p class="board-help"><?= icon('info', 'sm') ?> Drag cards between columns to change status. <?= $canTasks ? 'You can move any task in this project.' : 'You can move tasks assigned to you.' ?> Blocked tasks cannot move to Done until their dependencies are finished.</p>
