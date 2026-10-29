<?php
use Modules\Projects\Services\ProjectService as PS;
$labels = ['overdue' => ['Overdue', 'red', 'alert'], 'today' => ['Today', 'lemon', 'zap'], 'week' => ['This week', 'sky', 'calendar'], 'later' => ['Later', 'lav', 'layers'], 'none' => ['No due date', 'gray', 'minus']];
$today = date('Y-m-d');
$team = $mode === 'team';
$filtered = $project || $assignee;
$actions = $teamAllowed ? '<nav class="tabs" aria-label="Whose tasks"><a href="'.e(url('/tasks')).'" class="'.(!$team ? 'active' : '').'"'.(!$team ? ' aria-current="page"' : '').'>'.icon('user', 'sm').' Mine</a><a href="'.e(url('/tasks?view=team')).'" class="'.($team ? 'active' : '').'"'.($team ? ' aria-current="page"' : '').'>'.icon('users', 'sm').' Team</a></nav>' : '';
$dueWeek = count($groups['overdue']) + count($groups['today']) + count($groups['week']);
$wp = $doneWeek + $dueWeek ? (int) round($doneWeek / ($doneWeek + $dueWeek) * 100) : 0;
?>
<div class="pj">
<?= partial('partials/page_head', ['title' => $team ? 'Team tasks' : 'My tasks', 'subtitle' => $team ? 'Open tasks across the projects you manage, grouped by due date.' : 'Everything assigned to you, grouped by when it is due.', 'crumbs' => [['Work'], [$team ? 'Team tasks' : 'My tasks']], 'actions' => $actions]) ?>
<div class="grid g4 mb">
  <div class="tile bg-lav"><span class="tile-ic"><?= icon('check-square') ?></span><div><div class="num"><?= (int) $total ?></div><div class="lbl">Open tasks</div></div></div>
  <div class="tile bg-peach"><span class="tile-ic"><?= icon('alert') ?></span><div><div class="num"><?= count($groups['overdue']) ?></div><div class="lbl">Overdue</div></div></div>
  <div class="tile bg-lemon"><span class="tile-ic"><?= icon('zap') ?></span><div><div class="num"><?= count($groups['today']) ?></div><div class="lbl">Due today</div></div></div>
  <div class="tile bg-mint"><span class="tile-ic"><?= icon('check-circle') ?></span><div><div class="num"><?= (int) $doneWeek ?></div><div class="lbl">Done this week</div></div></div>
</div>
<div class="grid g-side">
  <div>
    <form class="toolbar" method="get">
      <?php if ($team): ?><input type="hidden" name="view" value="team"><?php endif ?>
      <select name="project" data-autosubmit aria-label="Project"><option value="">All projects</option><?php foreach ($projects as $pid => $pn): ?><option value="<?= (int) $pid ?>"<?= selected($pid, $project ?: '') ?>><?= e($pn) ?></option><?php endforeach ?></select>
      <?php if ($team): ?><select name="assignee" data-autosubmit aria-label="Assignee"><option value="">Everyone</option><?php foreach ($people as $uid => $un): ?><option value="<?= (int) $uid ?>"<?= selected($uid, $assignee ?: '') ?>><?= e($un) ?></option><?php endforeach ?></select><?php endif ?>
      <?php if ($filtered): ?><a class="clear" href="<?= e(url($team ? '/tasks?view=team' : '/tasks')) ?>">Clear filters</a><?php endif ?>
      <noscript><button class="btn ghost sm">Apply</button></noscript>
      <span class="xs muted ml-a"><?= (int) $total ?> open</span>
    </form>
    <?php if (!$total): ?>
      <div class="card"><?= empty_state($filtered ? 'Nothing open here' : 'All caught up!', $filtered ? 'No open tasks match this filter.' : ($team ? 'No open tasks in the projects you manage.' : 'You have no open tasks. Enjoy the calm, or pick something up from a project board.'), 'celebrate', '<a class="btn primary mt" href="'.e(url('/projects')).'">'.icon('folder', 'sm').' Browse projects</a>') ?></div>
    <?php else: ?>
    <div class="tgroups">
      <?php foreach ($labels as $g => [$label, $color, $ic]): if (!$groups[$g]) { continue; } ?>
      <details class="tgroup ac-<?= $color ?>" data-group="<?= e($g) ?>" open>
        <summary><span class="tg-ic"><?= icon($ic, 'sm') ?></span><h3><?= e($label) ?></h3><span class="tg-n" data-group-count><?= count($groups[$g]) ?></span><?= icon('chevron-down', 'sm chev') ?></summary>
        <?php foreach ($groups[$g] as $t): ?>
        <div class="trow" data-task-row>
          <?php if ($t['editable']): ?><form method="post" action="<?= e(url('/tasks/'.$t['id'].'/status')) ?>" class="qcheck" data-quick-done="tasks/<?= (int) $t['id'] ?>/status" data-prev="<?= e($t['status']) ?>"><?= csrf_field() ?><?= PS::returnField() ?><input type="hidden" name="status" value="done"><button class="tick" aria-pressed="false" aria-label="Mark as done: <?= e($t['title']) ?>" title="Mark as done"><?= icon('check', 'sm') ?></button></form><?php else: ?><span class="tick" aria-hidden="true"></span><?php endif ?>
          <div class="trow-main">
            <a class="trow-title" href="<?= e(url('/tasks/'.$t['id'])) ?>"><?= e($t['title']) ?></a>
            <div class="trow-meta ac-<?= e($t['color']) ?>"><span class="dotc ac"></span><span><?= e($t['project']) ?><?= $t['parent_title'] ? ' · ↳ '.e($t['parent_title']) : '' ?></span></div>
          </div>
          <div class="trow-side">
            <span class="prio p-<?= e($t['priority']) ?>"><?= e(PS::PRIORITIES[$t['priority']]) ?></span>
            <?php if ($t['status'] !== 'todo'): ?><?= badge($t['status'], PS::TASK_STATUSES[$t['status']]) ?><?php endif ?>
            <?= PS::dueChip($t['due_date'], $t['status']) ?>
            <?php if ($team): ?><?= $t['assignee'] ? avatar($t['assignee'], $t['avatar'], 'sm') : '<span class="avatar sm empty-av" title="Unassigned">?</span>' ?><?php endif ?>
          </div>
        </div>
        <?php endforeach ?>
      </details>
      <?php endforeach ?>
    </div>
    <?php if ($total >= 400): ?><p class="xs muted mt">Showing the first 400 open tasks, narrow down by project.</p><?php endif ?>
    <?php endif ?>
  </div>
  <aside class="stack">
    <section class="card">
      <div class="card-head"><div><h3>This week</h3><p class="xs muted mb0"><?= e(date('F Y')) ?> · tasks due per day</p></div><span class="pill pill-lav">Week <?= (int) date('W') ?></span></div>
      <div class="week-strip">
        <?php foreach ($week as $dd => $n): ?>
        <div class="wd<?= $dd === $today ? ' on' : '' ?><?= $dd < $today ? ' past' : '' ?>" title="<?= $n ?> due"><span><?= e(date('D', strtotime($dd))) ?></span><b><?= e(date('j', strtotime($dd))) ?></b><i><?php for ($i = 0; $i < min(3, $n); $i++): ?><em></em><?php endfor ?></i></div>
        <?php endforeach ?>
      </div>
      <div class="metric"><div class="flex between"><span class="muted"><?= (int) $doneWeek ?> done · <?= (int) $dueWeek ?> still due</span><b><?= $wp ?>%</b></div><div class="progress"><span style="width:<?= $wp ?>%"></span></div></div>
    </section>
    <?php if (PS::on('report_submit') && !$team): ?>
    <section class="card side-cta ac-pink"><span class="sec-ic"><?= icon('clipboard') ?></span><div class="grow"><b>Wrapping up?</b><p class="xs muted mb0">Share today's progress in a quick work report.</p></div><a class="btn sm soft" href="<?= e(url('/work-reports/create')) ?>">Write</a></section>
    <?php endif ?>
    <section class="card">
      <h4>Tips</h4>
      <ul class="checklist small"><li><?= icon('check', 'sm') ?> Tick the circle to mark a task done, you can undo it right away.</li><li><?= icon('check', 'sm') ?> Click a group header to collapse it; the page remembers your choice.</li><li><?= icon('check', 'sm') ?> Tasks blocked by unfinished dependencies cannot be completed yet.</li></ul>
    </section>
  </aside>
</div>
</div>
