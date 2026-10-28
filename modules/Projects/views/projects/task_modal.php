<?php use Modules\Projects\Services\ProjectService as PS; ?>
<dialog class="modal" id="task-new" aria-labelledby="task-new-title">
  <div class="modal-head"><h3 id="task-new-title">New task</h3><button type="button" class="btn icon sm ghost" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div>
  <form class="modal-body form" method="post" action="<?= e(url('/projects/'.$id.'/tasks')) ?>"><?= csrf_field() ?><?= PS::returnField() ?>
    <div class="field"><label>Title *</label><input type="text" name="title" required maxlength="255" placeholder="What needs to be done?" autofocus></div>
    <div class="field"><label>Description</label><textarea name="description" rows="3" placeholder="Details, links, acceptance criteria…"></textarea></div>
    <div class="form-grid">
      <div class="field"><label>Status</label><select name="status" data-task-status><?php foreach (PS::TASK_STATUSES as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach ?></select></div>
      <div class="field"><label>Priority</label><select name="priority"><?php foreach (PS::PRIORITIES as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($k, 'medium') ?>><?= e($l) ?></option><?php endforeach ?></select></div>
      <div class="field"><label>Assignee</label><select name="assignee_id"><option value="">Unassigned</option><?php foreach ($assignees as $uid => $un): ?><option value="<?= (int) $uid ?>"><?= e($un) ?></option><?php endforeach ?></select></div>
      <div class="field"><label>Milestone</label><select name="milestone_id"><option value="">None</option><?php foreach ($milestoneOpts as $mid => $mt): ?><option value="<?= (int) $mid ?>"><?= e($mt) ?></option><?php endforeach ?></select></div>
      <div class="field"><label>Start date</label><input type="date" name="start_date"></div>
      <div class="field"><label>Due date</label><input type="date" name="due_date"></div>
      <div class="field"><label>Estimated hours</label><input type="number" name="estimated_hours" step="0.25" min="0"></div>
      <div class="field"><label>&nbsp;</label><label class="check"><input type="checkbox" name="billable" value="1" checked> Billable to client</label></div>
    </div>
    <div class="form-actions"><button type="button" class="btn ghost" data-close>Cancel</button><button class="btn primary"><?= icon('plus', 'sm') ?> Create task</button></div>
  </form>
</dialog>
