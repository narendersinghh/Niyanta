<?php
/* One header and tab bar for every task page (Tasks & routines + project tasks). $active: my|assigned|routines|projects|team; optional $teamAllowed (project team view), $actions (html), $subtitle */
$todo = feature_on('todos') && can('todos.use');
$proj = feature_on('projects') && (can('projects.view') || can('projects.view_all'));
$tabs = array_filter([
  'my' => $todo ? ['My tasks', 'check-square', '/todos'] : null,
  'assigned' => $todo && (can('todos.assign') || can('todos.assign_all')) ? ['Assigned by me', 'send', '/todos?view=assigned'] : null,
  'routines' => $todo ? ['Routines', 'refresh', '/todos?view=routines'] : null,
  'projects' => $proj ? [$todo ? 'Project tasks' : 'My tasks', 'kanban', '/tasks'] : null,
  'team' => $proj && !empty($teamAllowed) ? ['Team project tasks', 'users', '/tasks?view=team'] : null]);
$actions ??= $todo ? '<a class="btn primary" href="'.e(url('/todos?new=1')).'">'.icon('plus', 'sm').' New task</a>' : '';
?>
<?= partial('partials/page_head', ['title' => 'Tasks', 'subtitle' => $subtitle ?? 'Your to-dos, routines and project tasks in one place.', 'crumbs' => [['Work'], ['Tasks']], 'actions' => $actions]) ?>
<?php if (count($tabs) > 1): ?><nav class="tabs mb task-tabs" aria-label="Task lists"><?php foreach ($tabs as $k => [$l, $ic, $href]): ?><a href="<?= e(url($href)) ?>" class="<?= $k === $active ? 'active' : '' ?>"<?= $k === $active ? ' aria-current="page"' : '' ?>><?= icon($ic, 'sm') ?> <?= e($l) ?></a><?php endforeach ?></nav><?php endif ?>
