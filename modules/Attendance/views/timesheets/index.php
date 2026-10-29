<?php
$actions = (can('attendance.timesheets.approve') ? '<a class="btn ghost" href="'.e(url('/timesheets/approvals')).'">'.icon('check-square', 'sm').' Team approvals'.($rejectedTeam ? ' <span class="pill pill-red">'.(int) $rejectedTeam.' rejected</span>' : '').'</a>' : '')
  .(can('attendance.track') ? '<a class="btn soft" href="'.e(url('/time?week='.$ws)).'">'.icon('timer', 'sm').' Time Tracking</a>' : '');
$canAdd = can('attendance.track') && \Modules\Attendance\Services\Timesheets::canEditDate(min(date('Y-m-d'), date('Y-m-d', strtotime($ws.' +6 days'))), ($ts['status'] ?? '') === 'rejected');
?>
<?= partial('partials/page_head', ['title' => 'Timesheets', 'subtitle' => 'Your week at a glance. Time is approved automatically unless your manager rejects it.', 'crumbs' => [['Work'], ['Timesheets']], 'actions' => $actions]) ?>
<?= partial('attendance::partials/ts_week', compact('ws', 's', 'ts', 'person', 'own', 'locked', 'canReview') + ['nav' => '/timesheets']) ?>
<div class="grid g-main">
  <div class="card">
    <div class="card-head"><h3>Entries</h3><div class="flex"><span class="muted small"><?= array_sum(array_map('count', $entries)) ?> entries</span><?php if ($canAdd): ?><button class="btn sm soft" data-modal="entry-dialog" data-entry-new><?= icon('plus', 'sm') ?> Add time</button><?php endif ?></div></div>
    <?= partial('attendance::partials/entries', ['days' => $entries, 'ws' => $ws, 'editable' => can('attendance.track')]) ?>
  </div>
  <div class="stack">
    <div class="card">
      <div class="card-head"><h4>Billable split</h4><span class="small muted"><?= $s['total'] ? (int) round(100 * $s['billable'] / $s['total']) : 0 ?>% billable</span></div>
      <div class="ts-split"><i style="width:<?= $s['total'] ? round(100 * $s['billable'] / $s['total'], 1) : 0 ?>%"></i><i style="width:<?= $s['total'] ? round(100 * ($s['total'] - $s['billable']) / $s['total'], 1) : 0 ?>%"></i></div>
      <div class="legend mt"><span><i class="lg-bill"></i>Billable <?= e(hours($s['billable'])) ?></span><span><i class="lg-non"></i>Non-billable <?= e(hours($s['total'] - $s['billable'])) ?></span></div>
    </div>
    <div class="card">
      <div class="card-head"><h4>Recent weeks</h4></div>
      <?php if (!$recent): ?><p class="muted small mb0">Your tracked weeks will appear here.</p><?php else: ?><div class="list"><?php foreach ($recent as $r): ?>
        <a class="list-item" href="<?= e(url('/timesheets?week='.$r['week_start'])) ?>"><span class="grow"><b class="small">Week of <?= e(fdate($r['week_start'], 'd M')) ?></b><span class="meta d-block"><?= e(hours($r['total_seconds'])) ?> · <?= e(hours($r['billable_seconds'])) ?> billable</span></span><?= badge($r['status'] === 'rejected' ? 'rejected' : 'approved') ?></a>
      <?php endforeach ?></div><?php endif ?>
    </div>
  </div>
</div>
<?php if (can('attendance.track')): ?><?= partial('attendance::partials/entry_dialog', ['projects' => $projects, 'date' => min(date('Y-m-d'), date('Y-m-d', strtotime($ws.' +6 days')))]) ?><?php endif ?>
