<?php
use Modules\Todos\Services\Todos as T;
$q = (string) ($_SERVER['QUERY_STRING'] ?? '');
$return = '/todos'.($q !== '' ? '?'.preg_replace('/(^|&)(edit|edit_routine)=\d+/', '', $q) : '');
$return = rtrim($return, '?&');
$tabIcons = ['my' => 'check-square', 'assigned' => 'send', 'routines' => 'refresh'];
$canCreate = T::canCreate();
$oldForm = errors() ? (string) old('_form') : '';
$today = date('Y-m-d');
$newBtn = $canCreate ? '<button type="button" class="btn primary" data-modal="todo-new">'.icon('plus', 'sm').' New task</button>' : '';
?>
<div class="td">
<?= partial('partials/page_head', ['title' => 'Tasks', 'subtitle' => 'One-time to-dos and repeating routines, for you and your team.', 'crumbs' => [['Work'], ['Tasks']], 'actions' => $newBtn]) ?>
<nav class="tabs td-tabs" aria-label="Task lists">
  <?php foreach ($views as $k => $l): ?><a href="<?= e(url('/todos'.($k === 'my' ? '' : '?view='.$k))) ?>" class="<?= $view === $k ? 'active' : '' ?>"<?= $view === $k ? ' aria-current="page"' : '' ?>><?= icon($tabIcons[$k], 'sm') ?> <?= e($l) ?></a><?php endforeach ?>
  <?php if ($projTab): ?><a href="<?= e(url('/tasks')) ?>"><?= icon('kanban', 'sm') ?> Project tasks</a><?php endif ?>
</nav>

<?php if ($view === 'my'): ?>
<?php $labels = ['overdue' => ['Overdue', 'peach', 'alert'], 'today' => ['Today', 'lemon', 'zap'], 'upcoming' => ['Upcoming', 'sky', 'calendar'], 'none' => ['No due date', 'lav', 'minus']]; ?>
<div class="td-groups" data-todo-groups>
  <?php foreach ($labels as $g => [$label, $c, $ic]): if (!$groups[$g]) { continue; } ?>
  <section class="card td-group ac-<?= $c ?>" data-todo-group>
    <div class="td-ghead"><span class="td-gic"><?= icon($ic, 'sm') ?></span><h3><?= e($label) ?></h3><span class="td-n" data-todo-count><?= count($groups[$g]) ?></span></div>
    <?php foreach ($groups[$g] as $t): ?><?= partial('todos::partials/row', ['t' => $t, 'mode' => 'my', 'uid' => $uid, 'manage' => $all || (int) $t['created_by'] === $uid, 'return' => $return]) ?><?php endforeach ?>
  </section>
  <?php endforeach ?>
  <div class="card<?= $open ? ' hide' : '' ?>" data-todo-clear><?= empty_state('All clear!', 'Nothing open on your list. Add a task or set up a routine for things you do every day, week or month.', 'celebrate', $canCreate ? '<button type="button" class="btn primary mt" data-modal="todo-new">'.icon('plus', 'sm').' New task</button>' : '') ?></div>
</div>
<details class="card td-done" data-todo-done<?= $doneRows ? '' : ' hidden' ?>>
  <summary><span class="td-gic"><?= icon('check-circle', 'sm') ?></span><h3>Done</h3><span class="xs muted">last 7 days</span><span class="td-n" data-todo-done-count><?= count($doneRows) ?></span><?= icon('chevron-down', 'sm td-chev') ?></summary>
  <div data-todo-done-list><?php foreach ($doneRows as $t): ?><?= partial('todos::partials/row', ['t' => $t, 'mode' => 'my', 'uid' => $uid, 'manage' => $all || (int) $t['created_by'] === $uid, 'return' => $return]) ?><?php endforeach ?></div>
</details>

<?php elseif ($view === 'assigned'): ?>
<?php $st = ['open' => ['Open', 'lav', $counts['open']], 'overdue' => ['Overdue', 'peach', $counts['overdue']], 'done' => ['Done', 'mint', $counts['done']], 'all' => ['All', 'gray', $counts['total']]]; ?>
<div class="td-stats">
  <?php foreach ($st as $k => [$l, $c, $n]): ?><a class="td-stat ac-<?= $c ?><?= $status === $k ? ' on' : '' ?>" href="<?= e(url('/todos?'.http_build_query(array_filter(['view' => 'assigned', 'who' => $who === 'team' ? 'team' : null, 'person' => $person ?: null, 'status' => $k])))) ?>"<?= $status === $k ? ' aria-current="true"' : '' ?>><b><?= (int) $n ?></b><span><?= e($l) ?></span></a><?php endforeach ?>
</div>
<form class="filters" method="get" action="<?= e(url('/todos')) ?>">
  <input type="hidden" name="view" value="assigned"><input type="hidden" name="status" value="<?= e($status) ?>">
  <select name="who" data-autosubmit aria-label="Whose tasks"><option value="mine"<?= selected($who, 'mine') ?>>Created by me</option><option value="team"<?= selected($who, 'team') ?>><?= $all ? 'Everyone' : 'My team' ?></option></select>
  <select name="person" data-autosubmit aria-label="Person"><option value="">All people</option><?php foreach ($people as $pid => $pn): ?><option value="<?= (int) $pid ?>"<?= selected($pid, $person ?: '') ?>><?= e($pn) ?></option><?php endforeach ?></select>
  <noscript><button class="btn ghost sm">Apply</button></noscript>
  <span class="xs muted"><?= (int) $page['total'] ?> task<?= $page['total'] == 1 ? '' : 's' ?></span>
</form>
<section class="card td-list">
  <?php if (!$page['items']): ?><?= empty_state($counts['total'] ? 'Nothing here' : 'No assigned tasks yet', $counts['total'] ? 'No tasks match this filter.' : 'Tasks you give to your team show up here so you can follow them up.', 'team', $counts['total'] ? '' : '<button type="button" class="btn primary mt" data-modal="todo-new">'.icon('plus', 'sm').' Assign a task</button>') ?>
  <?php else: foreach ($page['items'] as $t): ?><?= partial('todos::partials/row', ['t' => $t, 'mode' => 'assigned', 'uid' => $uid, 'manage' => true, 'return' => $return]) ?><?php endforeach; endif ?>
</section>
<?= pager($page) ?>

<?php else: ?>
<section class="card td-list">
  <?php if (!$routines): ?><?= empty_state('No routines yet', 'Routines create a task automatically every day, on chosen weekdays or once a month.', 'calendar-person', $canCreate ? '<button type="button" class="btn primary mt" data-modal="todo-new">'.icon('plus', 'sm').' New routine</button>' : '') ?>
  <?php else: foreach ($routines as $s): $state = T::state($s, $today); $next = $state === 'active' ? T::nextOccurrence($s, $today) : null; ?>
  <div class="td-row td-routine<?= $state !== 'active' ? ' is-off' : '' ?>" id="routine-<?= (int) $s['id'] ?>">
    <span class="td-ric ac-<?= ['active' => 'mint', 'paused' => 'lemon', 'ended' => 'lav'][$state] ?>"><?= icon('refresh', 'sm') ?></span>
    <div class="td-main">
      <span class="td-title"><?= e($s['title']) ?></span>
      <div class="td-meta">
        <span class="pill pill-lav td-rp"><?= e(T::repeatLabel($s)) ?></span>
        <span class="td-who"><?= avatar($s['assignee'], $s['assignee_avatar'], 'sm') ?> <?= (int) $s['assignee_id'] === $uid ? 'Me' : e($s['assignee']) ?></span>
        <span><?= icon('calendar', 'sm') ?> <?= $next ? 'Next '.e(T::due($next)[0]) : ($state === 'active' ? 'No upcoming date' : '-') ?></span>
        <?php if ($s['end_date']): ?><span>until <?= e(fdate($s['end_date'])) ?></span><?php endif ?>
      </div>
    </div>
    <div class="td-side">
      <span class="pill pill-<?= e(T::PRIO_PILL[$s['priority']] ?? 'gray') ?>"><?= e(T::PRIORITIES[$s['priority']] ?? '') ?></span>
      <span class="pill pill-<?= ['active' => 'mint', 'paused' => 'lemon', 'ended' => 'gray'][$state] ?>"><?= e(ucfirst($state)) ?></span>
      <details class="dd"><summary class="btn icon sm ghost" aria-label="Routine actions"><?= icon('more', 'sm') ?></summary>
        <div class="dd-menu">
          <a href="<?= e(url('/todos?view=routines&edit_routine='.(int) $s['id'])) ?>"><?= icon('edit', 'sm') ?> Edit</a>
          <?php if ($state !== 'ended'): ?><form method="post" action="<?= e(url('/todos/routines/'.$s['id'].'/toggle')) ?>"><?= csrf_field() ?><input type="hidden" name="return" value="/todos?view=routines"><button><?= icon((int) $s['active'] ? 'pause' : 'play', 'sm') ?> <?= (int) $s['active'] ? 'Pause' : 'Resume' ?></button></form><?php endif ?>
          <button type="button" class="td-del" data-modal="routine-del-<?= (int) $s['id'] ?>"><?= icon('trash', 'sm') ?> Delete</button>
        </div>
      </details>
    </div>
  </div>
  <dialog class="modal" id="routine-del-<?= (int) $s['id'] ?>" aria-labelledby="routine-del-<?= (int) $s['id'] ?>-t">
    <div class="modal-head"><h3 id="routine-del-<?= (int) $s['id'] ?>-t">Delete routine</h3><button type="button" class="btn icon sm ghost" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div>
    <form class="modal-body form" method="post" action="<?= e(url('/todos/routines/'.$s['id'].'/delete')) ?>"><?= csrf_field() ?><input type="hidden" name="return" value="/todos?view=routines">
      <p class="small">No new tasks will be created for <b><?= e($s['title']) ?></b>. Tasks already created stay, including completed ones.</p>
      <label class="check"><input type="checkbox" name="purge" value="1"> Also delete open occurrences</label>
      <div class="form-actions mt"><button type="button" class="btn ghost" data-close>Cancel</button><button class="btn danger"><?= icon('trash', 'sm') ?> Delete routine</button></div>
    </form>
  </dialog>
  <?php endforeach; endif ?>
</section>
<?php endif ?>
</div>
<?php if ($canCreate): ?>
<?= partial('todos::partials/form', ['id' => 'todo-new', 'heading' => 'New task', 'mode' => 'new', 'action' => '/todos', 'return' => $return, 'assignees' => $assignees, 'submit' => 'Create',
  'open' => $oldForm === 'todo-new', 'v' => $oldForm === 'todo-new' ? ['title' => old('title'), 'description' => old('description'), 'assignee_id' => old('assignee_id'), 'priority' => old('priority', 'medium'), 'repeat' => old('repeat', 'once'), 'due_date' => old('due_date'), 'workdays_only' => old('workdays_only'), 'weekdays' => old('weekdays', []), 'month_day' => old('month_day'), 'start_date' => old('start_date'), 'end_date' => old('end_date')]
    : ['assignee_id' => $uid, 'priority' => 'medium', 'repeat' => 'once', 'weekdays' => [(int) date('N')], 'month_day' => (int) date('j'), 'start_date' => $today]]) ?>
<?php endif ?>
<?php if ($edit): $k = 'todo-edit'; $o = $oldForm === $k; $as = $assignees + [(int) $edit['assignee_id'] => $edit['assignee']]; ?>
<?= partial('todos::partials/form', ['id' => $k, 'heading' => 'Edit task', 'mode' => 'todo', 'action' => '/todos/'.$edit['id'], 'return' => $return, 'assignees' => $as, 'submit' => 'Save', 'open' => true,
  'v' => $o ? ['title' => old('title'), 'description' => old('description'), 'assignee_id' => old('assignee_id'), 'priority' => old('priority'), 'due_date' => old('due_date'), 'occurrence' => $edit['occurrence']] : $edit]) ?>
<?php endif ?>
<?php if ($editSeries): $k = 'routine-edit'; $o = $oldForm === $k; $as = $assignees + [(int) $editSeries['assignee_id'] => $editSeries['assignee']]; ?>
<?= partial('todos::partials/form', ['id' => $k, 'heading' => 'Edit routine', 'mode' => 'series', 'action' => '/todos/routines/'.$editSeries['id'], 'return' => '/todos?view=routines', 'assignees' => $as, 'submit' => 'Save routine', 'open' => true,
  'v' => $o ? ['title' => old('title'), 'description' => old('description'), 'assignee_id' => old('assignee_id'), 'priority' => old('priority'), 'repeat' => old('repeat'), 'workdays_only' => old('workdays_only'), 'weekdays' => old('weekdays', []), 'month_day' => old('month_day'), 'start_date' => old('start_date'), 'end_date' => old('end_date')] : ['repeat' => $editSeries['frequency']] + $editSeries]) ?>
<?php endif ?>
