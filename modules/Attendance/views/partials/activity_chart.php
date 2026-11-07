<?php /* Activity per 10-minute slot: one thin bar per slot (height = activity %, colour by level: mint 60+, lemon 30-59, peach under 30, lav meeting, gray no data),
   grouped by hour with hour labels and the current-hour marker. Hover shows the tooltip (attendance.js); a click scrolls to the slot's screenshot card
   ($targets: slot index => true) or opens the day gallery at the slot's first screenshot ($shots: slot index => gallery index). Screen readers get the aria-label summary and a table. $chart, $avg */
$targets = $targets ?? []; $shots = $shots ?? [];
$tip = static function (array $s): string {
  $t = $s['pct'] !== null ? $s['pct'].'% activity' : ($s['meet'] ? 'Meeting' : ($s['future'] ? 'Later today' : 'No activity data'));
  if ($s['meet'] && $s['pct'] !== null) { $t .= ' · Meeting'; }
  return $s['range']."\n".$t.($s['min'] ? ' · '.$s['min'].' min tracked' : ''); };
$withData = array_values(array_filter($chart['slots'], static fn ($s) => $s['pct'] !== null || $s['meet'] || $s['min']));
$summary = 'Activity per 10 minutes, '.$chart['from'].' to '.$chart['to'].': average '.$avg.'%, '.count($withData).' slots with tracked time'.($chart['meetings'] ? ', '.$chart['meetings'].' in meetings' : '').'.';
?>
<figure class="ac" aria-label="<?= e($summary) ?>">
  <figcaption class="ac-cap"><b>Activity</b><span>per 10 minutes</span></figcaption>
  <div class="ac-scroll"><div class="ac-plot" aria-hidden="true" data-ac style="--hours:<?= count($chart['hours']) ?>">
    <?php foreach ($chart['hours'] as $n => $hr): ?><div class="ac-hour<?= $hr['now'] ? ' now' : '' ?>"><div class="ac-bars">
      <?php foreach (array_slice($chart['slots'], $n * 6, 6) as $s): $go = isset($targets[$s['i']]) || isset($shots[$s['i']]); $v = $s['pct'] ?? ($s['meet'] ? $s['mpct'] : 0); ?><span class="ac-bar <?= $s['lvl'] ?><?= $s['future'] ? ' later' : '' ?><?= $go ? ' go' : '' ?>" style="--v:<?= (int) $v ?>" data-tip="<?= e($tip($s).($go ? "\n".'Click to see the screenshots' : '')) ?>"<?= isset($targets[$s['i']]) ? ' data-ac-slot="'.$s['h'].'-'.$s['k'].'"' : '' ?><?= isset($shots[$s['i']]) ? ' data-shot-at="'.(int) $shots[$s['i']].'"' : '' ?>></span><?php endforeach ?>
      <?php if ($hr['now'] && $chart['now'] !== null): ?><i class="ac-now" style="--m:<?= (int) $chart['now'] ?>"></i><?php endif ?></div>
      <span class="ac-hl"><?= e($hr['label']) ?><?= $hr['now'] ? '<small>now</small>' : '' ?></span></div><?php endforeach ?>
  </div></div>
  <div class="ac-legend" aria-hidden="true"><span><i class="hi"></i>60%+</span><span><i class="mid"></i>30-59%</span><span><i class="lo"></i>under 30%</span><?php if ($chart['meetings']): ?><span><i class="meet"></i>Meeting</span><?php endif ?><span><i class="none"></i>No data</span></div>
  <div class="sr"><table><caption>Activity per 10-minute slot</caption><thead><tr><th scope="col">Time</th><th scope="col">Activity</th><th scope="col">Tracked</th></tr></thead>
    <tbody><?php foreach ($withData as $s): ?><tr><th scope="row"><?= e($s['range']) ?></th><td><?= $s['pct'] !== null ? $s['pct'].'%' : '-' ?><?= $s['meet'] ? ' (meeting)' : '' ?></td><td><?= (int) $s['min'] ?> min</td></tr><?php endforeach ?></tbody></table></div>
</figure>
