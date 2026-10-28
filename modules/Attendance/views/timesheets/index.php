<?php
use Modules\Attendance\Services\TimeService;
$prev = date('Y-m-d', strtotime($ws.' -7 days')); $next = date('Y-m-d', strtotime($ws.' +7 days'));
$open = (int) ($s['status']['draft'] ?? 0) + (int) ($s['status']['rejected'] ?? 0);
$non = $s['total'] - $s['billable'];
$actions = (can('attendance.timesheets.approve') ? '<a class="btn ghost" href="'.e(url('/timesheets/approvals')).'">'.icon('check-square', 'sm').' Approvals'.($pending ? ' <span class="pill pill-peach">'.(int) $pending.'</span>' : '').'</a>' : '').(can('attendance.track') ? '<a class="btn soft" href="'.e(url('/time?week='.$ws)).'">'.icon('timer', 'sm').' Edit entries</a>' : '');
?>
<?= partial('partials/page_head', ['title' => 'Timesheets', 'subtitle' => 'Review your week and submit it for approval.', 'crumbs' => [['Work'], ['Timesheets']], 'actions' => $actions]) ?>
<div class="card mb">
  <div class="card-head">
    <div class="wk-nav"><a class="btn icon sm ghost" href="<?= e(url('/timesheets?week='.$prev)) ?>" aria-label="Previous week"><?= icon('chevron-left', 'sm') ?></a><h3 class="range mb0"><?= e(fdate($ws, 'd M')) ?> – <?= e(fdate(date('Y-m-d', strtotime($ws.' +6 days')), 'd M Y')) ?></h3><?php if ($ws < TimeService::weekStart()): ?><a class="btn icon sm ghost" href="<?= e(url('/timesheets?week='.$next)) ?>" aria-label="Next week"><?= icon('chevron-right', 'sm') ?></a><a class="btn sm ghost" href="<?= e(url('/timesheets')) ?>">This week</a><?php endif ?></div>
    <div class="flex wrap"><?= $ts ? badge($ts['status']) : badge('draft', 'Not submitted') ?>
      <?php if (can('attendance.track') && $open && $s['total']): ?><form method="post" action="<?= e(url('/timesheets/submit')) ?>" data-confirm="Submit this week for approval? Submitted entries are locked until reviewed."><?= csrf_field() ?><input type="hidden" name="week" value="<?= e($ws) ?>"><button class="btn primary"<?= $s['running'] ? ' disabled title="Stop your running timer first"' : '' ?>><?= icon('send', 'sm') ?> Submit week</button></form><?php endif ?></div>
  </div>
  <?php if ($s['running']): ?><div class="alert warn"><?= icon('timer', 'sm') ?> A timer is running in this week — stop it before submitting.</div><?php endif ?>
  <?php if ($ts && $ts['status'] === 'rejected'): ?><div class="alert error"><?= icon('alert', 'sm') ?><div>Rejected by <?= e($ts['reviewer'] ?? 'reviewer') ?> on <?= e(fdatetime($ts['reviewed_at'])) ?>: <b><?= e($ts['review_note']) ?></b><div class="xs">Fix the entries in the Time Tracker and submit again.</div></div></div>
  <?php elseif ($ts && $ts['status'] === 'approved'): ?><div class="alert ok"><?= icon('check-circle', 'sm') ?><div>Approved by <?= e($ts['reviewer'] ?? 'reviewer') ?> on <?= e(fdatetime($ts['reviewed_at'])) ?><?= $ts['review_note'] ? ': '.e($ts['review_note']) : '' ?>. Approved entries are locked.<?= $open ? ' New draft entries can be submitted again.' : '' ?></div></div>
  <?php elseif ($ts && $ts['status'] === 'submitted'): ?><div class="alert info"><?= icon('clock', 'sm') ?> Submitted <?= e(fdatetime($ts['submitted_at'])) ?> — waiting for approval.</div><?php endif ?>
  <?= partial('attendance::partials/ts_summary', ['s' => $s, 'ws' => $ws]) ?>
</div>
<div class="grid g-main">
  <div class="card">
    <div class="card-head"><h3>Entries</h3><span class="muted small"><?= array_sum(array_map('count', $entries)) ?> entries</span></div>
    <?= partial('attendance::partials/entries', ['days' => $entries, 'ws' => $ws, 'editable' => false]) ?>
  </div>
  <div class="stack">
    <div class="tile bg-lav"><span class="tile-ic"><?= icon('clock') ?></span><div><div class="num"><?= e(sprintf('%.1f', $s['total'] / 3600)) ?><small class="tile-unit">h</small></div><div class="lbl">Tracked this week</div></div><?= illus('reports-chart', 'corner') ?></div>
    <div class="card">
      <div class="card-head"><h4>Billable split</h4><span class="small muted"><?= $s['total'] ? (int) round(100 * $s['billable'] / $s['total']) : 0 ?>% billable</span></div>
      <div class="ts-split"><i style="width:<?= $s['total'] ? round(100 * $s['billable'] / $s['total'], 1) : 0 ?>%"></i><i style="width:<?= $s['total'] ? round(100 * $non / $s['total'], 1) : 0 ?>%"></i></div>
      <div class="legend mt"><span><i class="lg-present"></i>Billable <?= e(hours($s['billable'])) ?></span><span><i class="lg-holiday"></i>Non-billable <?= e(hours($non)) ?></span></div>
    </div>
    <div class="card">
      <div class="card-head"><h4>Recent timesheets</h4></div>
      <?php if (!$recent): ?><p class="muted small mb0">No timesheets submitted yet.</p><?php else: ?><div class="list"><?php foreach ($recent as $r): ?>
        <a class="list-item" href="<?= e(url('/timesheets?week='.$r['week_start'])) ?>"><span class="grow"><b class="small">Week of <?= e(fdate($r['week_start'], 'd M')) ?></b><span class="meta d-block"><?= e(hours($r['total_seconds'])) ?> · <?= e(hours($r['billable_seconds'])) ?> billable</span></span><?= badge($r['status']) ?></a>
      <?php endforeach ?></div><?php endif ?>
    </div>
  </div>
</div>
