<?php
use Modules\Projects\Services\ProjectService as PS;
use Modules\Todos\Services\{TaskDialog, Todos as T};
/*
 * The one "New task" dialog (#task-new) for /todos, /tasks and project pages. Needs css/todos.css and js/taskdialog.js.
 * Optional: $project (preselected project id), $status (preselected project status), $return (local path to go back to), $open (bool).
 * No project: to-do or routine, posted to /todos. Project chosen: project task, posted to /projects/{id}/tasks (js switches the action;
 * /todos also hands a project_id over to the project task controller without js).
 */
$dv = TaskDialog::vars(Core\Auth::user() ?? [], ['project' => $project ?? 0, 'status' => $status ?? null, 'return' => $return ?? '/todos', 'open' => $open ?? false]);
if (!$dv) { return; }
['todo' => $todo, 'projects' => $projects, 'pid' => $pid, 'pd' => $pd, 'assignees' => $assignees, 'v' => $v, 'open' => $open, 'return' => $return] = $dv;
$err = $open ? errors() : [];
$f = static fn (string $k) => isset($err[$k]) ? '<div class="field-error">'.e($err[$k]).'</div>' : '';
$mode = $pid ? 'project' : 'todo';
$off = static fn (string $m) => $m === $mode ? '' : ' hidden disabled';
$p = 'task-new';
?>
<dialog class="modal tk-dialog" id="<?= $p ?>" aria-labelledby="<?= $p ?>-t"<?= $open ? ' data-open' : '' ?>>
  <div class="modal-head"><div><h3 id="<?= $p ?>-t">New task</h3><p class="xs muted mb0">Only the title is required, you can fill in the rest later.</p></div><button type="button" class="btn icon sm ghost" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div>
  <form class="modal-body form td-form tk-form" method="post" action="<?= e(url($pid ? '/projects/'.$pid.'/tasks' : '/todos')) ?>" data-task-form data-mode="<?= $mode ?>" data-repeat="<?= e($v['repeat']) ?>"<?= $todo ? ' data-todo-action="'.e(url('/todos')).'"' : '' ?> data-project-action="<?= e(url('/projects/__ID__/tasks')) ?>" data-project-data="projects/__ID__/task-form">
    <?= csrf_field() ?><input type="hidden" name="_form" value="<?= $p ?>"><input type="hidden" name="return" value="<?= e($return) ?>">
    <div class="field"><label for="<?= $p ?>-title">Title *</label><input id="<?= $p ?>-title" type="text" name="title" required maxlength="<?= $pid ? 255 : 190 ?>" value="<?= e($v['title'] ?? '') ?>" placeholder="What needs to be done?" autofocus><?= $f('title') ?></div>
    <div class="field"><label for="<?= $p ?>-desc">Description</label><textarea id="<?= $p ?>-desc" name="description" rows="3" maxlength="5000" placeholder="Details, links, checklist…"><?= e($v['description'] ?? '') ?></textarea><?= $f('description') ?></div>
    <?php if ($projects): ?>
    <div class="field"><label for="<?= $p ?>-proj">Project</label><select id="<?= $p ?>-proj" name="project_id" data-task-project><?php if ($todo): ?><option value="">No project</option><?php endif ?><?php foreach ($projects as $id => $name): ?><option value="<?= (int) $id ?>"<?= selected($id, $pid ?: '') ?>><?= e($name) ?></option><?php endforeach ?></select>
      <?php if ($todo): ?><div class="hint" data-when="todo"<?= $mode === 'todo' ? '' : ' hidden' ?>>A to-do for you or your team, once or repeating.</div><?php endif ?><div class="hint" data-when="project"<?= $mode === 'project' ? '' : ' hidden' ?>>Added to the project board, assign it to a project member.</div><?= $f('project_id') ?></div>
    <?php endif ?>
    <?php if ($todo): ?>
    <fieldset class="tk-fs" data-when="todo"<?= $off('todo') ?>>
      <div class="form-grid">
        <?php if (count($assignees) > 1): ?><div class="field"><label for="<?= $p ?>-who">Assign to</label><select id="<?= $p ?>-who" name="assignee_id"><?php foreach ($assignees as $aid => $an): ?><option value="<?= (int) $aid ?>"<?= selected($aid, $v['assignee_id'] ?? '') ?>><?= e($an) ?></option><?php endforeach ?></select><?= $mode === 'todo' ? $f('assignee_id') : '' ?></div>
        <?php else: ?><input type="hidden" name="assignee_id" value="<?= (int) array_key_first($assignees) ?>"><?php endif ?>
        <div class="field"><label for="<?= $p ?>-prio">Priority</label><select id="<?= $p ?>-prio" name="priority"><?php foreach (T::PRIORITIES as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($k, $v['priority']) ?>><?= e($l) ?></option><?php endforeach ?></select></div>
      </div>
      <?= partial('todos::partials/repeat', ['p' => $p, 'v' => $v, 'mode' => 'new', 'f' => $mode === 'todo' ? $f : static fn () => '']) ?>
    </fieldset>
    <?php endif ?>
    <?php if ($projects): ?>
    <fieldset class="tk-fs" data-when="project"<?= $off('project') ?>>
      <div class="form-grid">
        <div class="field"><label for="<?= $p ?>-pwho">Assign to</label><select id="<?= $p ?>-pwho" name="assignee_id" data-task-team><option value="">Unassigned</option><?php foreach ($pd['team'] as $aid => $an): ?><option value="<?= (int) $aid ?>"<?= selected($aid, $v['passignee_id']) ?>><?= e($an) ?></option><?php endforeach ?></select><div class="hint">Project members and the manager.</div><?= $mode === 'project' ? $f('assignee_id') : '' ?></div>
        <div class="field"><label for="<?= $p ?>-pprio">Priority</label><select id="<?= $p ?>-pprio" name="priority"><?php foreach (PS::PRIORITIES as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($k, $v['priority']) ?>><?= e($l) ?></option><?php endforeach ?></select></div>
        <div class="field"><label for="<?= $p ?>-status">Status</label><select id="<?= $p ?>-status" name="status" data-task-status><?php foreach (PS::TASK_STATUSES as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($k, $v['status']) ?>><?= e($l) ?></option><?php endforeach ?></select><?= $f('status') ?></div>
        <div class="field"><label for="<?= $p ?>-ms">Milestone</label><select id="<?= $p ?>-ms" name="milestone_id" data-task-ms><option value="">None</option><?php foreach ($pd['milestones'] as $mid => $mt): ?><option value="<?= (int) $mid ?>"<?= selected($mid, $v['milestone_id'] ?? '') ?>><?= e($mt) ?></option><?php endforeach ?></select><?= $f('milestone_id') ?></div>
        <div class="field"><label for="<?= $p ?>-pstart">Start date</label><input id="<?= $p ?>-pstart" type="date" name="start_date" value="<?= e($v['pstart_date']) ?>"><?= $mode === 'project' ? $f('start_date') : '' ?></div>
        <div class="field"><label for="<?= $p ?>-pdue">Due date</label><input id="<?= $p ?>-pdue" type="date" name="due_date" value="<?= e($v['pdue_date']) ?>"><?= $mode === 'project' ? $f('due_date') : '' ?></div>
        <div class="field"><label for="<?= $p ?>-est">Estimate (hours)</label><input id="<?= $p ?>-est" type="number" name="estimated_hours" step="0.25" min="0" placeholder="e.g. 4" value="<?= e($v['estimated_hours'] ?? '') ?>"><?= $f('estimated_hours') ?></div>
        <div class="field"><span class="td-lbl">Billing</span><label class="check"><input type="checkbox" name="billable" value="1" data-task-billable<?= checked($v['billable']) ?>> Billable to client</label></div>
      </div>
    </fieldset>
    <?php endif ?>
    <div class="form-actions"><button type="button" class="btn ghost" data-close>Cancel</button><button class="btn primary"><?= icon('plus', 'sm') ?> Create task</button></div>
  </form>
</dialog>
