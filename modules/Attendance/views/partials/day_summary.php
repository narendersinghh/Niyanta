<?php /* Day summary strip: $sum (BaseController::day), $targetName; optional $chart (HubController::chart) with $targets (slot index => true: bars scroll to the
   screenshot card #slot-H-K) or $shots (slot index => gallery index: bars open the day gallery there); $own (own day: the date heads the card, no name); $devName (one device picked: its numbers, labelled). With a chart: summary on the left, activity bars on the right. */
$chart = $chart ?? null; $own = !empty($own);
$only = !empty($devName) ? '<p class="ds-dev">'.icon('monitor', 'sm').'<span>Showing <b>'.e($devName).'</b> only</span></p>' : '';
$a = (int) ($chart['avg'] ?? $sum['activity']);
$lv = $a >= 60 ? 'hi' : ($a >= 30 ? 'mid' : 'lo');
$span = $sum['clock_in'] ? ftime($sum['clock_in']).' - '.($sum['clock_out'] ? ftime($sum['clock_out']) : ($sum['running'] ? 'now' : '-')) : '-';
if (!$chart): ?>
<div class="day-sum">
  <div class="ds-who"><?= avatar($targetName, null) ?><b><?= e($targetName) ?></b></div>
  <?= $only ?>
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
    <div class="ds-who"><?php if (!$own): ?><?= avatar($targetName, null) ?><?php endif ?><div class="grow"><b><?= e($own ? date('l', strtotime($sum['date'])).', '.fdate($sum['date'], 'd M') : $targetName) ?></b><span class="ds-when"><?= $own ? '' : e(date('D', strtotime($sum['date'])).', '.fdate($sum['date'], 'd M')).' · ' ?><?= e($span) ?></span></div><span class="ds-st st-<?= $stc ?>"><i aria-hidden="true"></i><?= e($stl) ?></span></div>
    <?= $only ?>
    <div class="ds-total"><b><?= e(hours($sum['total'])) ?></b><span>total time</span></div>
    <dl class="ds-tots">
      <div><dt>Active</dt><dd><?= e(hours($sum['active'])) ?></dd></div>
      <div><dt>Paused</dt><dd><?= e(hours($sum['paused'])) ?></dd></div>
      <div><dt>Idle</dt><dd class="<?= $sum['idle'] ? 'ds-idle' : '' ?>"><?= e(hours($sum['idle'])) ?></dd></div>
      <div><dt>Activity<?= $chart['meetings'] ? '<span class="sr"> (meetings not counted)</span>' : '' ?></dt><dd class="lv-<?= $lv ?>"<?= $chart['meetings'] ? ' title="Meetings not counted"' : '' ?>><?= $a ?>%<span class="ds-bar" aria-hidden="true"><i class="<?= $lv ?>" style="width:<?= $a ?>%"></i></span></dd></div>
    </dl>
  </div>
  <?= partial('attendance::partials/activity_chart', ['chart' => $chart, 'avg' => $a, 'targets' => $targets ?? [], 'shots' => $shots ?? []]) ?>
</div>
<?php endif ?>
