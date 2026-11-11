<?php
/* Sidebar bottom card for people with a short menu (no HR admin / System sections): the projects they are on (member,
   manager or with tasks assigned), nearest deadline first. Deadline = the earliest of the project deadline, the next open
   milestone and their own next open task; plus their open and overdue task counts. One query. $u (current user) */
if (!feature_on('projects') || !can('projects.view')) { return; }
$uid = (int) $u['id']; $today = date('Y-m-d');
$rows = Core\DB::all("SELECT p.id, p.name, p.color, p.deadline,
    (SELECT MIN(m.due_date) FROM milestones m WHERE m.project_id=p.id AND m.status<>'completed' AND m.due_date IS NOT NULL) AS ms_due,
    (SELECT m.title FROM milestones m WHERE m.project_id=p.id AND m.status<>'completed' AND m.due_date IS NOT NULL ORDER BY m.due_date LIMIT 1) AS ms_title,
    (SELECT MIN(t.due_date) FROM tasks t WHERE t.project_id=p.id AND t.assignee_id=? AND t.status<>'done' AND t.due_date IS NOT NULL) AS task_due,
    (SELECT COUNT(*) FROM tasks t WHERE t.project_id=p.id AND t.assignee_id=? AND t.status<>'done') AS open_n,
    (SELECT COUNT(*) FROM tasks t WHERE t.project_id=p.id AND t.assignee_id=? AND t.status<>'done' AND t.due_date<?) AS late_n
  FROM projects p WHERE p.status<>'completed' AND (p.manager_id=? OR p.id IN (SELECT pm.project_id FROM project_members pm WHERE pm.user_id=?) OR p.id IN (SELECT t.project_id FROM tasks t WHERE t.assignee_id=? AND t.status<>'done'))
  LIMIT 50", [$uid, $uid, $uid, $today, $uid, $uid, $uid]);
foreach ($rows as &$r) {
  $c = array_filter([['Project deadline', $r['deadline']], [$r['ms_title'] ? 'Milestone: '.$r['ms_title'] : 'Milestone', $r['ms_due']], ['Your next task', $r['task_due']]], static fn ($x) => $x[1]);
  usort($c, static fn ($a, $b) => strcmp($a[1], $b[1]));
  [$r['due_what'], $r['due']] = $c ? array_values($c)[0] : [null, null]; }
unset($r);
usort($rows, static fn ($a, $b) => [(int) $b['late_n'] > 0, $a['due'] === null, $a['due'], $a['name']] <=> [(int) $a['late_n'] > 0, $b['due'] === null, $b['due'], $b['name']]);
$total = count($rows); $rows = array_slice($rows, 0, 5);
$soon = strtotime('+3 days');
?>
<section class="side-work" aria-label="My projects">
  <div class="sw-head"><b>My projects</b><?php if ($total): ?><span class="sw-n"><?= $total ?></span><?php endif ?></div>
  <?php if (!$rows): ?><p class="sw-empty">You are not on any project yet.</p><?php endif ?>
  <?php foreach ($rows as $p): $d = $p['due']; $late = $d && $d < $today; $near = $d && !$late && strtotime($d) <= $soon; ?>
  <a class="sw-task" href="<?= e(url('/projects/'.$p['id'])) ?>" title="<?= e($p['due_what'] ? $p['due_what'].' · '.fdate($d) : $p['name']) ?>">
    <span class="sw-pc c-<?= e($p['color'] ?: 'lav') ?>" aria-hidden="true"></span>
    <span class="sw-t"><b class="truncate"><?= e($p['name']) ?></b><span class="sw-p truncate"><?= (int) $p['open_n'] ? (int) $p['open_n'].' task'.((int) $p['open_n'] === 1 ? '' : 's').((int) $p['late_n'] ? ' · <em>'.(int) $p['late_n'].' overdue</em>' : '') : 'No open tasks' ?><?= $p['due_what'] ? ' · '.e(str_starts_with($p['due_what'], 'Milestone') ? 'milestone' : ($p['due_what'] === 'Project deadline' ? 'deadline' : 'next task')) : '' ?></span></span>
    <?php if ($d): ?><span class="sw-due<?= $late ? ' late' : ($near ? ' today' : '') ?>"><?= $d === $today ? 'Today' : e(fdate($d, 'd M')) ?></span><?php endif ?></a>
  <?php endforeach ?>
  <?php if ($total > count($rows)): ?><a class="sw-all" href="<?= e(url('/projects')) ?>">All my projects <?= icon('chevron-right', 'sm') ?></a><?php endif ?>
</section>
