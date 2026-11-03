<?php
use Modules\Projects\Services\ProjectService as PS;
use Modules\Todos\Services\Todos as T;
/*
 * The task form fields, same order and labels in the New task dialog and every edit dialog (to-do, routine, project task):
 * Title, Description / Project | Assign to / Priority | Status / Start date | Due date / Estimate (hours) | Milestone / Billable / Repeat.
 * $p id prefix, $v values, $f error renderer fn(string): string.
 * Project: $projects [id => name] (select, $noProject adds "No project") or null with $projectName (read-only); $showProject false hides
 * Project, Milestone and Billable (Projects not available to the user). $pid current project (0 = none): Milestone and Billable are
 * disabled without one, with $offHint. Assign to: $people [id => name] (+ "Unassigned" when $unassigned), $todoPeople for the js switch back to No project.
 * $milestones [id => name], $statuses [key => label]. Repeat: $repeat new | series | fixed ($repeatText) | none. $dueLock: hint when the due date is fixed.
 */
$projects ??= null; $noProject ??= false; $projectName ??= null; $showProject ??= true; $pid = (int) ($pid ?? 0); $unassigned ??= false; $todoPeople ??= null; $milestones ??= [];
$statuses ??= PS::TASK_STATUSES; $repeat ??= 'none'; $repeatText ??= 'Once'; $dueLock ??= null; $offHint ??= 'Choose a project first'; $descMax ??= 5000;
$off = $pid ? '' : ' disabled';
$hint = static fn (bool $on) => '<div class="hint" data-when="todo"'.($on ? '' : ' hidden').'>'.e($offHint).'</div>';
$opts = static fn (array $rows, mixed $sel) => implode('', array_map(static fn ($k, $l) => '<option value="'.e((string) $k).'"'.selected($k, $sel ?? '').'>'.e($l).'</option>', array_keys($rows), $rows));
?>
<div class="field"><label for="<?= $p ?>-title">Title *</label><input id="<?= $p ?>-title" type="text" name="title" required maxlength="255" value="<?= e($v['title'] ?? '') ?>" placeholder="What needs to be done?" autofocus><?= $f('title') ?></div>
<div class="field"><label for="<?= $p ?>-desc">Description</label><textarea id="<?= $p ?>-desc" name="description" rows="3" maxlength="<?= (int) $descMax ?>" placeholder="Details, links, checklist…"><?= e($v['description'] ?? '') ?></textarea><?= $f('description') ?></div>
<div class="form-grid">
  <?php if ($showProject): ?>
  <?php if ($projects !== null): ?>
  <div class="field"><label for="<?= $p ?>-proj">Project</label><select id="<?= $p ?>-proj" name="project_id" data-task-project><?php if ($noProject): ?><option value="">No project</option><?php endif ?><?= $opts($projects, $pid ?: '') ?></select><?= $f('project_id') ?></div>
  <?php else: ?>
  <div class="field"><span class="td-lbl">Project</span><div class="tk-ro"><?= icon('folder', 'sm') ?> <?= e($projectName ?? 'No project') ?></div></div>
  <?php endif ?>
  <?php endif ?>
  <div class="field"><label for="<?= $p ?>-who">Assign to</label><select id="<?= $p ?>-who" name="assignee_id" data-task-team<?= $todoPeople !== null ? ' data-people="'.e(json_encode(array_map(null, array_keys($todoPeople), array_values($todoPeople)))).'"' : '' ?>><?php if ($unassigned): ?><option value="">Unassigned</option><?php endif ?><?= $opts($people, $v['assignee_id'] ?? '') ?></select><?php if ($showProject): ?><div class="hint" data-when="project"<?= $pid ? '' : ' hidden' ?>>Project members and the manager.</div><?php endif ?><?= $f('assignee_id') ?></div>
  <div class="field"><label for="<?= $p ?>-prio">Priority</label><select id="<?= $p ?>-prio" name="priority"><?= $opts(T::PRIORITIES, ($v['priority'] ?? '') ?: 'medium') ?></select><?= $f('priority') ?></div>
  <div class="field"><label for="<?= $p ?>-status">Status</label><select id="<?= $p ?>-status" name="status" data-task-status><?= $opts($statuses, ($v['status'] ?? '') ?: 'todo') ?></select><?= $f('status') ?></div>
  <div class="field"><label for="<?= $p ?>-start">Start date</label><input id="<?= $p ?>-start" type="date" name="start_date" value="<?= e($v['start_date'] ?? '') ?>"><?= $f('start_date') ?></div>
  <div class="field"><label for="<?= $p ?>-due">Due date</label><input id="<?= $p ?>-due" type="date" name="due_date" value="<?= e($v['due_date'] ?? '') ?>"<?= $dueLock !== null ? ' disabled' : ' data-task-due' ?>><?php if ($dueLock !== null): ?><div class="hint"><?= e($dueLock) ?></div><?php elseif ($repeat === 'new'): ?><div class="hint tk-due-hint">Each repeat is due on its own day.</div><?php endif ?><?= $f('due_date') ?></div>
  <div class="field"><label for="<?= $p ?>-est">Estimate (hours)</label><input id="<?= $p ?>-est" type="number" name="estimated_hours" step="0.25" min="0" placeholder="e.g. 4" value="<?= e((string) ($v['estimated_hours'] ?? '')) ?>"><?= $f('estimated_hours') ?></div>
  <?php if ($showProject): ?>
  <div class="field"><label for="<?= $p ?>-ms">Milestone</label><select id="<?= $p ?>-ms" name="milestone_id" data-task-ms data-needs-project<?= $off ?>><option value="">None</option><?= $opts($milestones, $v['milestone_id'] ?? '') ?></select><?= $hint(!$pid) ?><?= $f('milestone_id') ?></div>
  <?php endif ?>
</div>
<?php if ($showProject): ?>
<div class="field tk-bill"><label class="check"><input type="checkbox" name="billable" value="1" data-task-billable data-needs-project<?= checked($pid && !empty($v['billable'])) ?><?= $off ?>> Billable to client</label><?= $hint(!$pid) ?></div>
<?php endif ?>
<?php if ($repeat === 'new' || $repeat === 'series'): ?>
<?= partial('todos::partials/repeat', ['p' => $p, 'v' => $v, 'mode' => $repeat, 'f' => $f]) ?>
<?php elseif ($repeat === 'fixed'): ?>
<div class="field"><span class="td-lbl">Repeat</span><div class="tk-ro"><?= icon($repeatText === 'Once' ? 'check' : 'refresh', 'sm') ?> <?= e($repeatText) ?></div></div>
<?php endif ?>
