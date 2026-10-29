<?php use Modules\Projects\Services\ProjectService as PS; ?>
<dialog class="modal" id="task-new" aria-labelledby="task-new-title">
  <div class="modal-head"><div><h3 id="task-new-title">New task</h3><p class="xs muted mb0">Only the title is required, you can fill in the rest later.</p></div><button type="button" class="btn icon sm ghost" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div>
  <form class="modal-body form" method="post" action="<?= e(url('/projects/'.$id.'/tasks')) ?>"><?= csrf_field() ?><?= PS::returnField() ?>
    <div class="field"><label for="tn-title">Title *</label><input id="tn-title" type="text" name="title" required maxlength="255" placeholder="What needs to be done?" autofocus></div>
    <div class="field"><label for="tn-desc">Description</label><textarea id="tn-desc" name="description" rows="3" placeholder="Details, links, acceptance criteria…"></textarea></div>
    <div class="form-grid">
      <div class="field"><label for="tn-status">Status</label><select id="tn-status" name="status" data-task-status><?php foreach (PS::TASK_STATUSES as $k => $l): ?><option value="<?= e($k) ?>"><?= e($l) ?></option><?php endforeach ?></select></div>
      <div class="field"><label for="tn-prio">Priority</label><select id="tn-prio" name="priority"><?php foreach (PS::PRIORITIES as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($k, 'medium') ?>><?= e($l) ?></option><?php endforeach ?></select></div>
      <div class="field"><label for="tn-assignee">Assignee</label><select id="tn-assignee" name="assignee_id"><option value="">Unassigned</option><?php foreach ($assignees as $uid => $un): ?><option value="<?= (int) $uid ?>"><?= e($un) ?></option><?php endforeach ?></select><div class="hint">Project members and the manager.</div></div>
      <div class="field"><label for="tn-ms">Milestone</label><select id="tn-ms" name="milestone_id"><option value="">None</option><?php foreach ($milestoneOpts as $mid => $mt): ?><option value="<?= (int) $mid ?>"><?= e($mt) ?></option><?php endforeach ?></select></div>
      <div class="field"><label for="tn-start">Start date</label><input id="tn-start" type="date" name="start_date"></div>
      <div class="field"><label for="tn-due">Due date</label><input id="tn-due" type="date" name="due_date"></div>
      <div class="field"><label for="tn-est">Estimated hours</label><input id="tn-est" type="number" name="estimated_hours" step="0.25" min="0" placeholder="e.g. 4"></div>
      <div class="field"><label>Billing</label><label class="check"><input type="checkbox" name="billable" value="1"<?= checked($billable ?? true) ?>> Billable to client</label></div>
    </div>
    <div class="form-actions"><button type="button" class="btn ghost" data-close>Cancel</button><button class="btn primary"><?= icon('plus', 'sm') ?> Create task</button></div>
  </form>
</dialog>
