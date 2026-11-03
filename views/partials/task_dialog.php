<?php
use Modules\Todos\Services\TaskDialog;
/*
 * The one "New task" dialog (#task-new) for /todos, /tasks and project pages. Needs css/todos.css and js/taskdialog.js.
 * Optional: $project (preselected project id), $status (preselected status), $return (local path to go back to), $open (bool).
 * Same fields in the same order wherever it is opened (views/partials/task_fields.php); choosing a project only switches the
 * assignee list to the project team and enables Milestone and Billable. With Tasks & routines on it always posts to /todos
 * (no project = to-do or routine, project = project task or project routine); otherwise to /projects/{id}/tasks (js follows the project).
 */
$dv = TaskDialog::vars(Core\Auth::user() ?? [], ['project' => $project ?? 0, 'status' => $status ?? null, 'return' => $return ?? '/todos', 'open' => $open ?? false]);
if (!$dv) { return; }
['todo' => $todo, 'projects' => $projects, 'pid' => $pid, 'pd' => $pd, 'people' => $people, 'v' => $v, 'open' => $open, 'return' => $return] = $dv;
$err = $open ? errors() : [];
$f = static fn (string $k) => isset($err[$k]) ? '<div class="field-error">'.e($err[$k]).'</div>' : '';
$p = 'task-new';
?>
<dialog class="modal tk-dialog" id="<?= $p ?>" aria-labelledby="<?= $p ?>-t"<?= $open ? ' data-open' : '' ?>>
  <div class="modal-head"><div><h3 id="<?= $p ?>-t">New task</h3><p class="xs muted mb0">Only the title is required, you can fill in the rest later.</p></div><button type="button" class="btn icon sm ghost" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div>
  <form class="modal-body form td-form tk-form" method="post" action="<?= e(url($todo ? '/todos' : '/projects/'.$pid.'/tasks')) ?>" data-task-form data-mode="<?= $pid ? 'project' : 'todo' ?>" data-repeat="<?= e($v['repeat']) ?>"<?= $todo ? '' : ' data-project-action="'.e(url('/projects/__ID__/tasks')).'"' ?> data-project-data="projects/__ID__/task-form">
    <?= csrf_field() ?><input type="hidden" name="_form" value="<?= $p ?>"><input type="hidden" name="return" value="<?= e($return) ?>">
    <?= partial('partials/task_fields', ['p' => $p, 'v' => $v, 'f' => $f, 'projects' => $projects ?: null, 'noProject' => $todo, 'showProject' => (bool) $projects, 'pid' => $pid,
      'people' => $pid ? $pd['team'] : $people, 'unassigned' => (bool) $pid, 'todoPeople' => $todo && $projects ? $people : null, 'milestones' => $pd['milestones'], 'repeat' => $todo ? 'new' : 'none']) ?>
    <div class="form-actions"><button type="button" class="btn ghost" data-close>Cancel</button><button class="btn primary"><?= icon('plus', 'sm') ?> Create task</button></div>
  </form>
</dialog>
