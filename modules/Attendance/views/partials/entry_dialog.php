<?php /* Add / edit time entry dialog: $projects (id => name), optional $date default */ ?>
<dialog class="modal" id="entry-dialog"><form method="post" action="<?= e(url('/time/entries')) ?>" data-entry-form data-new-action="<?= e(url('/time/entries')) ?>"><?= csrf_field() ?>
  <div class="modal-head"><h3 data-entry-title>Add time</h3><button type="button" class="btn icon sm ghost" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div>
  <div class="modal-body form">
    <p class="small muted mb0">Saved time is approved automatically. You can change the current and previous week.</p>
    <div class="form-grid g3">
      <div class="field"><label>Date *</label><input type="date" name="date" value="<?= e($date ?? date('Y-m-d')) ?>" max="<?= e(date('Y-m-d')) ?>" required></div>
      <div class="field"><label>Start *</label><input type="time" name="start_time" required></div>
      <div class="field"><label>End</label><input type="time" name="end_time"></div>
    </div>
    <div class="form-grid">
      <div class="field"><label>Or duration</label><input type="text" name="duration" placeholder="1:30 or 1.5" maxlength="10"><div class="hint">Used when no end time is given.</div></div>
      <input type="hidden" name="billable" value="1">
      <?php if (feature_on('projects')): ?><div class="field"><label>Project</label><select name="project_id" data-project-select data-task-target="entry-task"><option value="">No project</option><?php foreach ($projects as $id => $n): ?><option value="<?= (int) $id ?>"><?= e($n) ?></option><?php endforeach ?></select></div>
      <div class="field"><label>Task</label><select name="task_id" id="entry-task" disabled><option value="">Select a project first</option></select></div><?php endif ?>
      <div class="field full"><label>Description</label><input type="text" name="description" maxlength="500" placeholder="What did you work on?"></div>
    </div>
    <div class="form-actions"><button type="button" class="btn ghost" data-close>Cancel</button><button class="btn primary"><?= icon('check', 'sm') ?> Save entry</button></div>
  </div>
</form></dialog>
