<?php /* Day summary strip: $sum (BaseController::day), $targetName; optional $chart (HubController::chart) with $targets (slot index => true: bars scroll to the
   screenshot card #slot-H-K) or $shots (slot index => gallery list: bars open the viewer). With a chart: summary on the left, activity bars on the right. */
$chart = $chart ?? null;
$a = (int) ($chart['avg'] ?? $sum['activity']);
$lv = $a >= 60 ? 'hi' : ($a >= 30 ? 'mid' : 'lo');
$span = $sum['clock_in'] ? ftime($sum['clock_in']).' - '.($sum['clock_out'] ? ftime($sum['clock_out']) : ($sum['running'] ? 'now' : '-')) : '-';
if (!$chart): ?>
<div class="day-sum">
  <div class="ds-who"><?= avatar($targetName, null) ?><b><?= e($targetName) ?></b></div>
  <div class="ds-k"><b><?= e(fdate($sum['date'])) ?></b><span><?= e(date('l', strtotime($sum['date']))) ?></span></div>
  <div class="ds-k"><b><?= e($span) ?></b><span>Start - end</span></div>
  <div class="ds-k"><b><?= e(hours($sum['total'])) ?></b><span>Total time</span></div>
  <i class="ds-sep" aria-hidden="true"></i>
  <div class="ds-k ds-act"><b><span class="ds-bar"><i class="<?= $lv ?>" style="width:<?= $a ?>%"></i></span><?= $a ?>%</b><span>Activity</span></div>
  <div class="ds-k"><b><?= e(hours($sum['active'])) ?></b><span>Active time</span></div>
  <div class="ds-k"><b><?= e(hours($sum['paused'])) ?></b><span>Paused</span></div>
  <div class="ds-k"><b class="<?= $sum['idle'] ? 'ds-idle' : '' ?>"><?= e(hours($sum['idle'])) ?></b><span>Idle time</span></div>
</div>
<?php else:
  $today = $sum['date'] === date('Y-m-d');
  [$stc, $stl] = $sum['running'] ? ['run', 'Tracking now'] : ($sum['clock_out'] ? ['done', 'Day ended'] : ($sum['clock_in'] && $today ? ['wait', 'Not tracking'] : ['off', $sum['total'] ? 'Tracked' : 'No time tracked'])); ?>
<div class="day-sum ds-wide">
  <div class="ds-side">
    <div class="ds-who"><?= avatar($targetName, null) ?><div class="grow"><b><?= e($targetName) ?></b><span class="ds-when"><?= e(date('D', strtotime($sum['date'])).', '.fdate($sum['date'])) ?> · <?= e($span) ?></span></div><span class="ds-st st-<?= $stc ?>"><i aria-hidden="true"></i><?= e($stl) ?></span></div>
    <div class="ds-tots">
      <div><b><?= e(hours($sum['total'])) ?></b><span>Total time</span></div>
      <div><b><?= e(hours($sum['active'])) ?></b><span>Active</span></div>
      <div><b><?= e(hours($sum['paused'])) ?></b><span>Paused</span></div>
      <div><b class="<?= $sum['idle'] ? 'ds-idle' : '' ?>"><?= e(hours($sum['idle'])) ?></b><span>Idle</span></div>
    </div>
    <div class="ds-avg"><b class="lv-<?= $lv ?>"><?= $a ?>%</b><span>Avg activity<?= $chart['meetings'] ? '<small>meetings not counted</small>' : '' ?></span><span class="ds-bar" aria-hidden="true"><i class="<?= $lv ?>" style="width:<?= $a ?>%"></i></span></div>
  </div>
  <?= partial('attendance::partials/activity_chart', ['chart' => $chart, 'avg' => $a, 'targets' => $targets ?? [], 'shots' => $shots ?? []]) ?>
</div>
<?php endif ?>
