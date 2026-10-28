<?php
use Modules\Projects\Services\ProjectService as PS;
$labels = ['overdue' => ['Overdue', 'red', 'alert'], 'today' => ['Due today', 'peach', 'zap'], 'week' => ['Later this week', 'lemon', 'calendar'], 'later' => ['Later & no due date', 'sky', 'layers']];
$today = date('Y-m-d');
$actions = $teamAllowed ? '<nav class="tabs" aria-label="View"><a href="'.e(url('/tasks')).'" class="'.($mode === 'mine' ? 'active' : '').'">'.icon('user', 'sm').' Mine</a><a href="'.e(url('/tasks?view=team')).'" class="'.($mode === 'team' ? 'active' : '').'">'.icon('users', 'sm').' Team</a></nav>' : '';
?>
<?= partial('partials/page_head', ['title' => $mode === 'team' ? 'Team tasks' : 'My tasks', 'subtitle' => $mode === 'team' ? 'Open tasks across the projects you manage.' : 'Everything assigned to you, ordered by what is due first.', 'crumbs' => [['Work'], ['Tasks']], 'actions' => $actions]) ?>
<div class="grid g-main mb">
  <div class="card week-card">
    <div class="card-head"><div><h3>This week</h3><p class="xs muted mb0"><?= e(date('F Y')) ?> · tasks due per day</p></div><span class="pill pill-lav"><?= icon('calendar', 'sm') ?> Week <?= (int) date('W') ?></span></div>
    <div class="week">
      <?php foreach ($week as $d => $n): ?>
      <div class="day<?= $d === $today ? ' on' : '' ?><?= $d < $today ? ' past' : '' ?>"><span><?= e(date('D', strtotime($d))) ?></span><b><?= e(date('j', strtotime($d))) ?></b><i class="wk-dots"><?php for ($i = 0; $i < min(3, $n); $i++): ?><em></em><?php endfor ?></i><small><?= $n ? $n.' due' : '&nbsp;' ?></small></div>
      <?php endforeach ?>
    </div>
    <?php $dueWeek = count($groups['overdue']) + count($groups['today']) + count($groups['week']); $wp = $doneWeek + $dueWeek ? (int) round($doneWeek / ($doneWeek + $dueWeek) * 100) : 0; ?>
    <div class="wk-foot"><div class="flex between small"><span class="muted">Weekly progress · <?= (int) $doneWeek ?> done, <?= (int) $dueWeek ?> still due</span><b><?= $wp ?>%</b></div><div class="progress mt-s"><span style="width:<?= $wp ?>%"></span></div></div>
  </div>
  <div class="grid g2 tiles-sm">
    <div class="tile bg-lav"><span class="tile-ic"><?= icon('check-square') ?></span><div><div class="num"><?= (int) $total ?></div><div class="lbl">Open</div></div></div>
    <div class="tile bg-peach"><span class="tile-ic"><?= icon('alert') ?></span><div><div class="num"><?= count($groups['overdue']) ?></div><div class="lbl">Overdue</div></div></div>
    <div class="tile bg-lemon"><span class="tile-ic"><?= icon('zap') ?></span><div><div class="num"><?= count($groups['today']) ?></div><div class="lbl">Due today</div></div></div>
    <div class="tile bg-mint"><span class="tile-ic"><?= icon('check-circle') ?></span><div><div class="num"><?= (int) $doneWeek ?></div><div class="lbl">Done this week</div></div></div>
  </div>
</div>
<form class="filters" method="get">
  <?php if ($mode === 'team'): ?><input type="hidden" name="view" value="team"><?php endif ?>
  <select name="project" data-autosubmit aria-label="Project"><option value="">All projects</option><?php foreach ($projects as $pid => $pn): ?><option value="<?= (int) $pid ?>"<?= selected($pid, $_GET['project'] ?? '') ?>><?= e($pn) ?></option><?php endforeach ?></select>
  <?php if ($mode === 'team'): ?><select name="assignee" data-autosubmit aria-label="Assignee"><option value="">Everyone</option><?php foreach ($people as $uid => $un): ?><option value="<?= (int) $uid ?>"<?= selected($uid, $_GET['assignee'] ?? '') ?>><?= e($un) ?></option><?php endforeach ?></select><?php endif ?>
  <noscript><button class="btn ghost sm">Filter</button></noscript>
</form>
<?php if (!$total): ?>
  <div class="card"><?= empty_state('All caught up!', $mode === 'team' ? 'No open tasks in the projects you manage.' : 'You have no open tasks. Enjoy the calm — or grab something from a project board.', 'celebrate', '<a class="btn primary mt" href="'.e(url('/projects')).'">'.icon('folder', 'sm').' Browse projects</a>') ?></div>
<?php else: ?>
<div class="stack">
  <?php foreach ($labels as $g => [$label, $color, $ic]): if (!$groups[$g]) { continue; } ?>
  <section class="card task-group">
    <div class="card-head"><h3 class="flex"><span class="g-ic soft-<?= $color === 'red' ? 'peach' : $color ?>"><?= icon($ic, 'sm') ?></span> <?= e($label) ?></h3><span class="pill pill-<?= $color ?>"><?= count($groups[$g]) ?></span></div>
    <div class="trows">
      <?php foreach ($groups[$g] as $t): ?>
      <div class="trow ac-<?= e($t['color']) ?>">
        <span class="trow-bar"></span>
        <div class="grow trow-main"><a class="b" href="<?= e(url('/tasks/'.$t['id'])) ?>"><?= e($t['title']) ?></a><div class="xs muted"><?= e($t['project']) ?><?= $t['parent_title'] ? ' · ↳ '.e($t['parent_title']) : '' ?></div></div>
        <?= badge($t['priority'], PS::PRIORITIES[$t['priority']]) ?>
        <?= PS::dueChip($t['due_date'], $t['status']) ?>
        <?php if ($mode === 'team'): ?><?= $t['assignee'] ? avatar($t['assignee'], $t['avatar'], 'sm') : '<span class="avatar sm empty-av" title="Unassigned">?</span>' ?><?php endif ?>
        <?php if ($t['editable']): ?>
        <form method="post" action="<?= e(url('/tasks/'.$t['id'].'/status')) ?>" class="status-form"><?= csrf_field() ?><?= PS::returnField() ?><select name="status" data-autosubmit class="status-select st-<?= e($t['status']) ?>" aria-label="Status"><?php foreach (PS::TASK_STATUSES as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($k, $t['status']) ?>><?= e($l) ?></option><?php endforeach ?></select></form>
        <?php else: ?><?= badge($t['status'], PS::TASK_STATUSES[$t['status']]) ?><?php endif ?>
      </div>
      <?php endforeach ?>
    </div>
  </section>
  <?php endforeach ?>
</div>
<?php if ($total >= 400): ?><p class="xs muted mt">Showing the first 400 open tasks — narrow down by project.</p><?php endif ?>
<?php endif ?>
