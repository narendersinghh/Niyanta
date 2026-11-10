<?php
/* Sidebar bottom card for people with a short menu (no HR admin / System sections): what they work on now and their next
   assigned project tasks by due date (overdue first). Two small indexed queries. $u (current user) */
if (!feature_on('projects') || !can('projects.view')) { return; }
$uid = (int) $u['id']; $today = date('Y-m-d');
$run = feature_on('attendance') && can('attendance.track') && class_exists(\Modules\Attendance\Services\TimeService::class) ? \Modules\Attendance\Services\TimeService::running($uid, false) : null;
$open = (int) Core\DB::value("SELECT COUNT(*) FROM tasks WHERE assignee_id=? AND status<>'done'", [$uid]);
$next = $open ? Core\DB::all("SELECT t.id, t.title, t.due_date, p.name AS project FROM tasks t JOIN projects p ON p.id=t.project_id WHERE t.assignee_id=? AND t.status<>'done' ORDER BY t.due_date IS NULL, t.due_date, t.priority='high' DESC, t.id LIMIT 3", [$uid]) : [];
if (!$run && !$next) { return; }
?>
<section class="side-work" aria-label="My work">
  <div class="sw-head"><b>My work</b><?php if ($open): ?><span class="sw-n"><?= $open ?> open</span><?php endif ?></div>
  <?php if ($run): ?><a class="sw-now" href="<?= e(url('/time')) ?>"><span class="sw-dot" aria-hidden="true"></span><span class="sw-t"><span class="sw-k">Working on</span><b class="truncate"><?= e($run['task_title'] ?: ($run['project_name'] ?: 'Tracked time')) ?></b><?php if ($run['task_title'] && $run['project_name']): ?><span class="sw-p truncate"><?= e($run['project_name']) ?></span><?php endif ?></span></a><?php endif ?>
  <?php foreach ($next as $t): $late = $t['due_date'] && $t['due_date'] < $today; $isT = $t['due_date'] === $today; ?>
  <a class="sw-task" href="<?= e(url('/tasks/'.$t['id'])) ?>"><span class="sw-t"><b class="truncate"><?= e($t['title']) ?></b><span class="sw-p truncate"><?= e($t['project']) ?></span></span><?php if ($t['due_date']): ?><span class="sw-due<?= $late ? ' late' : ($isT ? ' today' : '') ?>"><?= $late ? 'Overdue' : ($isT ? 'Today' : e(fdate($t['due_date'], 'd M'))) ?></span><?php endif ?></a>
  <?php endforeach ?>
  <?php if ($open > count($next)): ?><a class="sw-all" href="<?= e(url(feature_on('todos') ? '/todos' : '/tasks')) ?>">All my tasks <?= icon('chevron-right', 'sm') ?></a><?php endif ?>
</section>
