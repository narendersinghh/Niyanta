<?php
/* Dashboard: read-only tracker status ($st = Tracker::state). Tracking is controlled in the desktop app. */
$hms = static fn (int $s): string => sprintf('%02d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60);
$run = $st['running']; $tot = (int) $st['totals']['tracked'];
$dot = ['tracking' => 'dot-in pulse', 'paused' => 'dot-break', 'stopped' => 'dot-break', 'ended' => 'dot-done', 'not_started' => 'dot-out'][$st['state']];
?>
<div class="tt-card side-timer live-card<?= $run ? ' running' : '' ?>" data-live-status data-state="<?= e($st['state']) ?>">
  <div class="tt-top"><span class="tt-chip<?= $run ? ' live' : '' ?>" data-ls-chip><span class="dotc <?= e($dot) ?>"></span><span data-ls-label><?= e($st['label']) ?></span></span><a class="xs" href="<?= e(url('/time')) ?>">Time Tracking →</a></div>
  <div class="tt-main">
    <div class="grow"><div class="tt-digits" data-tick data-ls-total data-base="<?= $tot ?>"<?= $run ? '' : ' data-paused' ?>><?= e($hms($tot)) ?></div>
      <div class="tt-sub truncate" data-ls-line><?= $run ? ($run['device'] ? 'On <b>'.e($run['device']).'</b> · ' : '').'<b>'.e(feature_on('projects') ? ($run['project'] ?? 'No project') : 'Tracked time').'</b>' : e(['paused' => 'Paused in the desktop app', 'stopped' => 'Day open, not tracking', 'ended' => 'Day ended', 'not_started' => 'Start your day in Niyanta Tracker'][$st['state']]) ?></div></div>
  </div>
  <div class="side-att"><div class="small"><span class="muted">Tracked today</span><?php if ($st['day']['clock_in']): ?><br><span class="muted">Started <?= e(ftime($st['day']['clock_in'])) ?></span><?php endif ?></div>
    <a class="btn sm ghost side-dl" href="<?= e(url('/time#desktop')) ?>"><?= icon('download', 'sm') ?> Desktop app</a></div>
</div>
