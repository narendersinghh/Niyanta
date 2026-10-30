<?php /* Day summary strip: $sum (BaseController::day), $targetName */
$a = (int) $sum['activity']; ?>
<div class="day-sum">
  <div class="ds-who"><?= avatar($targetName, null) ?><b><?= e($targetName) ?></b></div>
  <div class="ds-k"><b><?= e(fdate($sum['date'])) ?></b><span><?= e(date('l', strtotime($sum['date']))) ?></span></div>
  <div class="ds-k"><b><?= $sum['clock_in'] ? e(ftime($sum['clock_in'])).' - '.($sum['clock_out'] ? e(ftime($sum['clock_out'])) : ($sum['running'] ? 'now' : '-')) : '-' ?></b><span>Start - end</span></div>
  <div class="ds-k"><b><?= e(hours($sum['total'])) ?></b><span>Total time</span></div>
  <i class="ds-sep" aria-hidden="true"></i>
  <div class="ds-k ds-act"><b><span class="ds-bar"><i class="<?= $a >= 60 ? 'hi' : ($a >= 30 ? 'mid' : 'lo') ?>" style="width:<?= $a ?>%"></i></span><?= $a ?>%</b><span>Activity</span></div>
  <div class="ds-k"><b><?= e(hours($sum['active'])) ?></b><span>Active time</span></div>
  <div class="ds-k"><b><?= e(hours($sum['paused'])) ?></b><span>Paused</span></div>
  <div class="ds-k"><b class="<?= $sum['idle'] ? 'ds-idle' : '' ?>"><?= e(hours($sum['idle'])) ?></b><span>Idle time</span></div>
</div>
