<?php
use Modules\Projects\Services\ProjectService as PS;
$time = PS::on('time');
$est = $pr['estimated_hours'] !== null ? (float) $pr['estimated_hours'] : ($estTasks ?: null);
$actualH = $sec['total'] / 3600; $billH = $sec['billable'] / 3600;
$rate = (float) ($pr['hourly_rate'] ?? 0);
$cost = $time && $rate ? $billH * $rate : null;
$hrsPct = $est ? (int) round($actualH / $est * 100) : 0;
$budPct = $cost !== null && (float) $pr['budget'] > 0 ? (int) round($cost / (float) $pr['budget'] * 100) : null;
$deliv = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string) $pr['deliverables']))));
$fmtH = static fn (?float $h) => $h === null ? '-' : rtrim(rtrim(number_format($h, 2), '0'), '.').'h';
$maxLoad = max([1, ...array_map('intval', $load)]);
$people = $team;
if ($pr['manager_id'] && !in_array((int) $pr['manager_id'], array_map('intval', array_column($team, 'id')), true) && $pr['manager_name']) { array_unshift($people, ['id' => $pr['manager_id'], 'name' => $pr['manager_name'], 'avatar' => $pr['manager_avatar'], 'role' => null, 'designation' => null]); }
?>
<div class="grid g4 mb">
  <div class="tile bg-lav"><span class="tile-ic"><?= icon('target') ?></span><div><div class="num"><?= (int) $pr['progress'] ?>%</div><div class="lbl">Complete · <?= $done ?>/<?= $total ?> tasks</div></div></div>
  <div class="tile bg-<?= $tone === 'red' ? 'peach' : 'lemon' ?>"><span class="tile-ic"><?= icon('calendar') ?></span><div><div class="num num-sm"><?= e($cd) ?></div><div class="lbl"><?= $pr['deadline'] ? 'Deadline '.e(fdate($pr['deadline'])) : 'Set a deadline in Edit project' ?></div></div></div>
  <a class="tile bg-mint" href="<?= e(url('/projects/'.$id.'?tab=milestones')) ?>"><span class="tile-ic"><?= icon('flag') ?></span><div><div class="num"><?= $nums['ms_done'] ?>/<?= $nums['ms'] ?></div><div class="lbl">Milestones completed</div></div></a>
  <?php if ($time): ?>
  <div class="tile bg-sky"><span class="tile-ic"><?= icon('timer') ?></span><div><div class="num num-sm"><?= e(hours($sec['total'])) ?></div><div class="lbl">Logged<?= $est ? ' of '.e($fmtH($est)).' estimated' : '' ?></div></div></div>
  <?php else: ?>
  <a class="tile bg-sky" href="<?= e(url('/projects/'.$id.'?tab=changes')) ?>"><span class="tile-ic"><?= icon('refresh') ?></span><div><div class="num"><?= $nums['cr'] ?></div><div class="lbl">Pending change requests</div></div></a>
  <?php endif ?>
</div>
<div class="grid g-main">
  <div class="stack">
    <section class="card ac-<?= e($pr['color']) ?>">
      <div class="sec-head"><span class="sec-ic"><?= icon('file-text') ?></span><h3>About this project</h3><?php if ($nums['cr'] && $time): ?><a class="pill pill-lemon ml-a" href="<?= e(url('/projects/'.$id.'?tab=changes')) ?>"><?= icon('refresh', 'sm') ?> <?= $nums['cr'] ?> pending change request<?= $nums['cr'] > 1 ? 's' : '' ?></a><?php endif ?></div>
      <?php if (!$pr['description'] && !$pr['scope'] && !$deliv): ?><p class="muted mb0">No description yet.<?= $canManage ? ' Add a description, scope and deliverables from <a href="'.e(url('/projects/'.$id.'/edit')).'">Edit project</a>.' : '' ?></p><?php endif ?>
      <?php if ($pr['description']): ?><div class="prose"><?= nl2br(e($pr['description'])) ?></div><?php endif ?>
      <?php if ($pr['scope'] || $deliv): ?>
      <div class="scope-grid">
        <?php if ($pr['scope']): ?><div class="scope-box"><h4 class="flex"><?= icon('target', 'sm') ?> Scope</h4><div class="small prose"><?= nl2br(e($pr['scope'])) ?></div></div><?php endif ?>
        <?php if ($deliv): ?><div class="scope-box"><h4 class="flex"><?= icon('package', 'sm') ?> Deliverables</h4><ul class="checklist small"><?php foreach ($deliv as $dl): ?><li><?= icon('check', 'sm') ?> <?= e(ltrim($dl, "-*• \t")) ?></li><?php endforeach ?></ul></div><?php endif ?>
      </div>
      <?php endif ?>
    </section>
    <section class="card">
      <div class="card-head"><div class="sec-head mb0"><span class="sec-ic"><?= icon('flag') ?></span><h3>Milestones</h3></div><a class="btn sm ghost" href="<?= e(url('/projects/'.$id.'?tab=milestones')) ?>"><?= $canManage ? 'Manage' : 'View all' ?> <?= icon('arrow-right', 'sm') ?></a></div>
      <?php if (!$milestones): ?><?= empty_state('No milestones yet', 'Milestones split the project into checkpoints and payment stages.', 'rocket', $canManage ? '<a class="btn soft mt" href="'.e(url('/projects/'.$id.'?tab=milestones')).'">'.icon('plus', 'sm').' Add milestone</a>' : '') ?><?php else: ?>
      <div class="ms-line">
        <?php foreach ($milestones as $i => $m): $pct = $m['total'] ? (int) round($m['done'] / $m['total'] * 100) : ($m['status'] === 'completed' ? 100 : 0); $isDone = $m['status'] === 'completed'; $mlate = !$isDone && $m['due_date'] && $m['due_date'] < date('Y-m-d'); ?>
        <div class="ms-row">
          <span class="ms-mark<?= $isDone ? ' done' : ($mlate ? ' late' : '') ?>"><?= $isDone ? icon('check', 'sm') : $i + 1 ?></span>
          <div class="grow"><div class="flex between wrap"><b class="truncate"><?= e($m['title']) ?></b><span class="flex"><?= $isDone ? badge('completed') : ($m['due_date'] ? PS::dueChip($m['due_date'], 'todo') : badge($m['status'])) ?></span></div>
            <div class="flex"><div class="progress grow"><span style="width:<?= $pct ?>%"></span></div><span class="xs muted nowrap"><?= (int) $m['done'] ?>/<?= (int) $m['total'] ?> tasks</span></div></div>
        </div>
        <?php endforeach ?>
      </div>
      <?php endif ?>
    </section>
    <section class="card">
      <div class="card-head"><div class="sec-head mb0"><span class="sec-ic"><?= icon('clock') ?></span><h3>Upcoming deadlines</h3></div><a class="btn sm ghost" href="<?= e(url('/projects/'.$id.'?tab=list&status=open')) ?>">All tasks <?= icon('arrow-right', 'sm') ?></a></div>
      <?php if (!$upcoming): ?><p class="muted small mb0">No open tasks with due dates. <?= $canTasks ? 'Give tasks a due date to see them here.' : '' ?></p><?php else: ?>
      <div class="urows"><?php foreach ($upcoming as $t): ?>
        <a class="urow" href="<?= e(url('/tasks/'.$t['id'])) ?>"><span class="dotc st-<?= e($t['status']) ?>" style="background:var(--sc)"></span><div class="grow"><b><?= e($t['title']) ?></b><div class="meta"><?= e(PS::TASK_STATUSES[$t['status']]) ?> · <span class="p-<?= e($t['priority']) ?> prio"><?= e(PS::PRIORITIES[$t['priority']]) ?></span></div></div><?= PS::dueChip($t['due_date'], $t['status']) ?><?= $t['assignee'] ? avatar($t['assignee'], $t['avatar'], 'sm') : '<span class="avatar sm empty-av" title="Unassigned">?</span>' ?></a>
      <?php endforeach ?></div>
      <?php endif ?>
    </section>
  </div>
  <div class="stack">
    <section class="card spotlight">
      <div class="card-head"><div class="sec-head mb0"><span class="sec-ic"><?= icon('wallet') ?></span><h3>Budget<?= $time ? ' &amp; hours' : '' ?></h3></div><span class="pill pill-gray"><?= e(PS::BILLING[$pr['billing_type']]) ?></span></div>
      <div class="kv-list">
        <div class="kv-row"><span>Budget</span><b><?= $pr['budget'] !== null ? e(money($pr['budget'])) : '-' ?></b></div>
        <?php if ($rate): ?><div class="kv-row"><span>Hourly rate</span><b><?= e(money($rate)) ?>/h</b></div><?php endif ?>
        <?php if ($time): ?><div class="kv-row"><span>Cost to date</span><b><?= $cost !== null ? e(money($cost)) : '-' ?></b></div><?php endif ?>
        <div class="kv-row"><span>Estimated effort</span><b><?= e($fmtH($est)) ?></b></div>
        <?php if ($time): ?><div class="kv-row"><span>Actual hours</span><b><?= e(hours($sec['total'])) ?></b></div><?php endif ?>
      </div>
      <?php if ($time && $est): ?><div class="metric"><div class="flex between"><span class="muted">Hours used</span><b><?= $hrsPct ?>%</b></div><div class="progress<?= $hrsPct >= 100 ? ' over' : ($hrsPct >= 80 ? ' warn' : '') ?>"><span style="width:<?= min(100, $hrsPct) ?>%"></span></div></div><?php endif ?>
      <?php if ($budPct !== null): ?><div class="metric"><div class="flex between"><span class="muted">Budget consumed</span><b><?= $budPct ?>%</b></div><div class="progress<?= $budPct >= 100 ? ' over' : ($budPct >= 80 ? ' warn' : '') ?>"><span style="width:<?= min(100, $budPct) ?>%"></span></div></div><?php endif ?>
      <?php if ($time && $sec['total']): ?>
      <div class="split-bar" title="Billable vs non-billable"><span class="bg-mint" style="flex:<?= max(1, $sec['billable']) ?>"></span><span class="bg-gray" style="flex:<?= max(0, $sec['total'] - $sec['billable']) ?>"></span></div>
      <div class="legend"><span><span class="dotc" style="background:var(--mint)"></span> Billable <?= e(hours($sec['billable'])) ?></span><span><span class="dotc" style="background:var(--surface-3)"></span> Non-billable <?= e(hours($sec['total'] - $sec['billable'])) ?></span></div>
      <?php elseif ($time): ?><p class="xs muted mt mb0">No time logged yet. Time tracked against this project's tasks shows up here.</p><?php endif ?>
    </section>
    <section class="card">
      <div class="card-head"><div class="sec-head mb0"><span class="sec-ic"><?= icon('users') ?></span><h3>Team</h3></div><span class="pill pill-gray"><?= count($people) ?></span></div>
      <?php if (!$people): ?><p class="muted small mb0">No one on this project yet.<?= $canManage ? ' Add members from Edit project.' : '' ?></p><?php else: ?>
      <div>
        <?php foreach ($people as $m): $n = (int) ($load[$m['id']] ?? 0); $isMgr = (int) $m['id'] === (int) $pr['manager_id']; ?>
        <div class="member"><?= avatar($m['name'], $m['avatar'], 'sm') ?><div class="grow"><b class="truncate db"><?= e($m['name']) ?></b><div class="meta"><?= $isMgr ? 'Project manager' : e($m['role'] ?: ($m['designation'] ?: 'Member')) ?></div></div>
          <div class="load" title="<?= $n ?> open task<?= $n === 1 ? '' : 's' ?>"><span><?= $n ?> open</span><div class="progress"><span style="width:<?= (int) round($n / $maxLoad * 100) ?>%"></span></div></div></div>
        <?php endforeach ?>
      </div>
      <?php endif ?>
    </section>
  </div>
</div>
