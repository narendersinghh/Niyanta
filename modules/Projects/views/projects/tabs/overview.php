<?php
use Modules\Projects\Services\ProjectService as PS;
$est = $pr['estimated_hours'] !== null ? (float) $pr['estimated_hours'] : ($estTasks ?: null);
$actualH = $sec['total'] / 3600; $billH = $sec['billable'] / 3600;
$rate = (float) ($pr['hourly_rate'] ?? 0);
$cost = $rate ? $billH * $rate : null;
$hrsPct = $est ? min(100, (int) round($actualH / $est * 100)) : 0;
$budPct = $cost !== null && (float) $pr['budget'] > 0 ? min(100, (int) round($cost / (float) $pr['budget'] * 100)) : null;
$deliv = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', (string) $pr['deliverables']))));
?>
<div class="grid g-main">
  <div class="stack">
    <div class="card">
      <div class="card-head"><h3>About this project</h3><?php if ($openCr): ?><a class="pill pill-lemon" href="<?= e(url('/projects/'.$pr['id'].'?tab=changes')) ?>"><?= icon('refresh', 'sm') ?> <?= (int) $openCr ?> pending change request<?= $openCr > 1 ? 's' : '' ?></a><?php endif ?></div>
      <?php if (!$pr['description'] && !$pr['scope'] && !$deliv): ?><p class="muted mb0">No description yet.<?= $canManage ? ' Add scope and deliverables from Edit project.' : '' ?></p><?php endif ?>
      <?php if ($pr['description']): ?><div class="prose"><?= nl2br(e($pr['description'])) ?></div><?php endif ?>
      <?php if ($pr['scope'] || $deliv): ?>
      <div class="grid g2 mt">
        <?php if ($pr['scope']): ?><div class="soft-box soft-sky"><h4><?= icon('target', 'sm') ?> Scope</h4><div class="small prose"><?= nl2br(e($pr['scope'])) ?></div></div><?php endif ?>
        <?php if ($deliv): ?><div class="soft-box soft-mint"><h4><?= icon('package', 'sm') ?> Deliverables</h4><ul class="checklist small"><?php foreach ($deliv as $d): ?><li><?= icon('check', 'sm') ?> <?= e(ltrim($d, "-*• \t")) ?></li><?php endforeach ?></ul></div><?php endif ?>
      </div>
      <?php endif ?>
    </div>
    <div class="card">
      <div class="card-head"><h3>Milestones</h3><a class="btn sm ghost" href="<?= e(url('/projects/'.$pr['id'].'?tab=milestones')) ?>">Manage <?= icon('arrow-right', 'sm') ?></a></div>
      <?php if (!$milestones): ?><?= empty_state('No milestones yet', 'Milestones split the project into checkpoints.', 'calendar-person') ?><?php else: ?>
      <div class="timeline">
        <?php foreach ($milestones as $m): $pct = $m['total'] ? (int) round($m['done'] / $m['total'] * 100) : ($m['status'] === 'completed' ? 100 : 0); $mlate = $m['status'] !== 'completed' && $m['due_date'] && $m['due_date'] < date('Y-m-d'); ?>
        <div class="tl-item">
          <span class="tl-dot <?= $m['status'] === 'completed' ? 'bg-mint' : ($mlate ? 'bg-peach' : 'bg-lav') ?>"><?= icon($m['status'] === 'completed' ? 'check' : 'flag') ?></span>
          <div class="tl-card">
            <div class="flex between wrap"><b><?= e($m['title']) ?></b><span class="flex"><?= badge($mlate ? 'overdue' : $m['status']) ?><span class="due<?= $mlate ? ' late' : '' ?>"><?= icon('calendar', 'sm') ?> <?= e(fdate($m['due_date'])) ?></span></span></div>
            <div class="flex mt-s"><div class="progress grow"><span style="width:<?= $pct ?>%"></span></div><span class="xs muted nowrap"><?= (int) $m['done'] ?>/<?= (int) $m['total'] ?> tasks</span></div>
          </div>
        </div>
        <?php endforeach ?>
      </div>
      <?php endif ?>
    </div>
    <div class="card">
      <div class="card-head"><h3>Upcoming deadlines</h3><a class="btn sm ghost" href="<?= e(url('/projects/'.$pr['id'].'?tab=list&status=open')) ?>">All tasks <?= icon('arrow-right', 'sm') ?></a></div>
      <?php if (!$upcoming): ?><p class="muted small mb0">No open tasks with due dates.</p><?php else: ?>
      <div class="list"><?php foreach ($upcoming as $t): ?>
        <a class="list-item" href="<?= e(url('/tasks/'.$t['id'])) ?>"><span class="dotc bg-<?= e(PS::TASK_COLORS[$t['status']]) ?>"></span><div class="grow"><b class="truncate"><?= e($t['title']) ?></b><div class="meta"><?= e(PS::TASK_STATUSES[$t['status']]) ?> · <?= e(PS::PRIORITIES[$t['priority']]) ?></div></div><?= PS::dueChip($t['due_date'], $t['status']) ?><?= $t['assignee'] ? avatar($t['assignee'], $t['avatar'], 'sm') : '' ?></a>
      <?php endforeach ?></div>
      <?php endif ?>
    </div>
  </div>
  <div class="stack">
    <div class="card">
      <div class="card-head"><h3>Budget &amp; hours</h3><span class="pill pill-gray"><?= e(PS::BILLING[$pr['billing_type']]) ?></span></div>
      <div class="mini-stats">
        <div class="soft-lav"><span>Budget</span><b><?= $pr['budget'] !== null ? e(money($pr['budget'])) : '—' ?></b></div>
        <div class="soft-mint"><span>Cost to date</span><b><?= $cost !== null ? e(money($cost)) : '—' ?></b></div>
        <div class="soft-sky"><span>Estimated</span><b><?= $est !== null ? e(rtrim(rtrim(number_format($est, 2), '0'), '.')).'h' : '—' ?></b></div>
        <div class="soft-peach"><span>Actual</span><b><?= e(hours($sec['total'])) ?></b></div>
      </div>
      <?php if ($est): ?><div class="mt"><div class="flex between xs"><span class="muted">Hours used</span><b><?= $hrsPct ?>%</b></div><div class="progress<?= $actualH > $est ? ' over' : '' ?>"><span style="width:<?= $hrsPct ?>%"></span></div></div><?php endif ?>
      <?php if ($budPct !== null): ?><div class="mt"><div class="flex between xs"><span class="muted">Budget consumed</span><b><?= $budPct ?>%</b></div><div class="progress<?= $budPct >= 100 ? ' over' : '' ?>"><span style="width:<?= $budPct ?>%"></span></div></div><?php endif ?>
      <div class="split-bar mt" title="Billable vs non-billable"><span class="bg-mint" style="flex:<?= max(1, $sec['billable']) ?>"></span><span class="bg-gray" style="flex:<?= max($sec['total'] ? 0 : 1, $sec['total'] - $sec['billable']) ?>"></span></div>
      <div class="flex between xs mt-s"><span><span class="dotc bg-mint"></span> Billable <?= e(hours($sec['billable'])) ?></span><span><span class="dotc bg-gray"></span> Non-billable <?= e(hours($sec['total'] - $sec['billable'])) ?></span></div>
      <?php if ($rate): ?><p class="xs muted mt mb0">Cost uses billable hours × <?= e(money($rate)) ?>/h.</p><?php endif ?>
    </div>
    <div class="card">
      <div class="card-head"><h3>Team</h3><span class="pill pill-gray"><?= count($team) + ($pr['manager_id'] && !in_array((int) $pr['manager_id'], array_map('intval', array_column($team, 'id')), true) ? 1 : 0) ?></span></div>
      <div class="list">
        <?php if ($pr['manager_name']): ?><div class="list-item"><?= avatar($pr['manager_name'], $pr['manager_avatar'], 'sm') ?><div class="grow"><b><?= e($pr['manager_name']) ?></b><div class="meta">Project manager</div></div><span class="pill pill-lav"><?= (int) ($load[$pr['manager_id']] ?? 0) ?> open</span></div><?php endif ?>
        <?php foreach ($team as $m): if ((int) $m['id'] === (int) $pr['manager_id']) { continue; } ?>
        <div class="list-item"><?= avatar($m['name'], $m['avatar'], 'sm') ?><div class="grow"><b><?= e($m['name']) ?></b><div class="meta"><?= e($m['role'] ?: ($m['designation'] ?: 'Member')) ?></div></div><span class="pill pill-gray"><?= (int) ($load[$m['id']] ?? 0) ?> open</span></div>
        <?php endforeach ?>
        <?php if (!$team && !$pr['manager_name']): ?><p class="muted small mb0">No one on this project yet.</p><?php endif ?>
      </div>
    </div>
  </div>
</div>
