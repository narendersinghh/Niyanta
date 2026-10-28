<?php /* $days (date => entries), $ws, $editable (bool) */
$srcIc = ['timer' => 'timer', 'manual' => 'edit', 'agent' => 'monitor'];
if (!$days): ?><?= empty_state('No time logged this week', $editable ? 'Start the timer or add time manually.' : 'Nothing was tracked in this week.', 'clock-worker') ?><?php else: foreach ($days as $d => $list): $sum = 0; foreach ($list as $x) { $sum += \Modules\Attendance\Services\TimeService::elapsed($x); } ?>
<div class="day-group">
  <div class="day-head"><b><?= e(fdate($d, 'l, d M')) ?></b><span class="pill pill-gray"><?= e(hours($sum)) ?></span></div>
  <?php foreach ($list as $en): $dur = \Modules\Attendance\Services\TimeService::elapsed($en); ?>
  <div class="entry">
    <span class="bar c-<?= e($en['project_color'] ?? 'gray') ?>"></span>
    <div class="grow">
      <div class="ttl"><?= e($en['project_name'] ?? 'No project') ?><?php if ($en['task_title']): ?><span class="muted small">· <?= e(str_limit($en['task_title'], 50)) ?></span><?php endif ?></div>
      <div class="meta"><span><?= e(ftime($en['started_at'])) ?> – <?= $en['is_running'] ? 'now' : e(ftime($en['ended_at'])) ?></span><?php if ($en['description']): ?><span>· <?= e(str_limit($en['description'], 80)) ?></span><?php endif ?>
        <span class="pill pill-gray"><?= icon($srcIc[$en['source']] ?? 'clock', 'sm') ?> <?= e(ucfirst($en['source'])) ?></span><?= $en['billable'] ? '<span class="pill pill-lemon">Billable</span>' : '' ?>
        <?php if ((int) $en['idle_seconds'] > 0): ?><span class="pill pill-peach" title="Idle detected<?= (int) $en['discarded_seconds'] ? ', '.e(hours($en['discarded_seconds'])).' discarded' : '' ?>">Idle <?= e(hours($en['idle_seconds'])) ?></span><?php endif ?>
        <?= $en['is_running'] ? '<span class="rec">Running</span>' : badge($en['status']) ?></div>
    </div>
    <div class="dur"><?= e(hours($dur)) ?></div>
    <div class="acts"><?php if ($editable && \Modules\Attendance\Services\TimeService::editable($en)):
      $json = ['id' => (int) $en['id'], 'date' => substr($en['started_at'], 0, 10), 'start_time' => substr($en['started_at'], 11, 5), 'end_time' => substr((string) $en['ended_at'], 11, 5), 'project_id' => $en['project_id'], 'task_id' => $en['task_id'], 'description' => $en['description'] ?? '', 'billable' => (int) $en['billable']]; ?>
      <button class="btn icon sm ghost" data-modal="entry-dialog" data-entry-edit="<?= e(json_encode($json)) ?>" aria-label="Edit entry"><?= icon('edit', 'sm') ?></button>
      <form method="post" action="<?= e(url('/time/entries/'.$en['id'].'/delete')) ?>" data-confirm="Delete this time entry?"><?= csrf_field() ?><button class="btn icon sm ghost" aria-label="Delete entry"><?= icon('trash', 'sm') ?></button></form>
    <?php elseif ($editable && !$en['is_running']): ?><span class="muted" title="Locked after submission"><?= icon('lock', 'sm') ?></span><?php endif ?></div>
  </div>
  <?php endforeach ?>
</div>
<?php endforeach; endif ?>
