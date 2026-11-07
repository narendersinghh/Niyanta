<?php
$canAdd = can('attendance.track') && \Modules\Attendance\Services\Timesheets::canEditDate(min(date('Y-m-d'), date('Y-m-d', strtotime($ws.' +6 days'))), ($ts['status'] ?? '') === 'rejected');
?>
<?= partial('attendance::partials/hub', ['tab' => 'weekly', 'title' => 'Time & Attendance', 'subtitle' => 'Your week by project and day.', 'people' => $people, 'targetName' => $person['name'], 'base' => '/timesheets', 'mode' => 'week', 'week' => $ws,
  'actions' => $canAdd ? '<button class="btn primary" data-modal="entry-dialog" data-entry-new>'.icon('plus', 'sm').' Add manual time</button>' : '']) ?>
<?= partial('attendance::partials/ts_week', compact('ws', 's', 'ts', 'person', 'own', 'locked', 'canReview')) ?>
<div class="ta-split wide">
  <section class="card">
    <div class="card-head"><h3>Entries</h3><span class="muted small"><?= $n = array_sum(array_map('count', $entries)) ?> entr<?= $n === 1 ? 'y' : 'ies' ?></span></div>
    <?= partial('attendance::partials/entries', ['days' => $entries, 'ws' => $ws, 'editable' => can('attendance.track')]) ?>
  </section>
  <section class="card">
    <div class="card-head"><h3>Recent weeks</h3></div>
    <?php if (!$recent): ?><p class="muted small mb0">Your tracked weeks will appear here.</p><?php else: ?><div class="list"><?php foreach ($recent as $r): ?>
      <a class="list-item" href="<?= e(url('/timesheets?week='.$r['week_start'])) ?>"<?= $r['week_start'] === $ws ? ' aria-current="page"' : '' ?>><span class="grow"><b class="small">Week of <?= e(fdate($r['week_start'], 'd M')) ?></b><span class="meta d-block"><?= e(hours($r['total_seconds'])) ?> · <?= e(hours($r['billable_seconds'])) ?> billable</span></span><?= $r['status'] === 'rejected' ? badge('rejected') : '' ?></a>
    <?php endforeach ?></div><?php endif ?>
  </section>
</div>
<?php if (can('attendance.track')): ?><?= partial('attendance::partials/entry_dialog', ['projects' => $projects, 'date' => min(date('Y-m-d'), date('Y-m-d', strtotime($ws.' +6 days')))]) ?><?php endif ?>
