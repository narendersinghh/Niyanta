<?php
$hms = static fn (int $s): string => sprintf('%02d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60);
$el = $running ? \Modules\Attendance\Services\TimeService::elapsed($running) : 0;
?>
<div class="tt-card side-timer<?= $running ? ' running' : '' ?>">
  <div class="tt-top"><?php if ($running): ?><span class="tt-chip live"><span class="dotc dot-in pulse"></span>Tracking</span><?php else: ?><span class="tt-chip"><span class="dotc dot-out"></span>Timer off</span><?php endif ?><a class="xs" href="<?= e(url('/time')) ?>">Open tracker →</a></div>
  <div class="tt-main">
    <div class="grow"><div class="tt-digits" data-tick data-base="<?= $el ?>"<?= $running ? '' : ' data-paused' ?>><?= e($hms($el)) ?></div>
      <div class="tt-sub truncate"><?= $running ? '<b>'.e($running['project_name'] ?? 'No project').'</b>'.($running['task_title'] ? ' · '.e(str_limit($running['task_title'], 30)) : '') : 'Ready when you are' ?></div></div>
    <?php if ($running): ?><form method="post" action="<?= e(url('/time/stop')) ?>"><?= csrf_field() ?><input type="hidden" name="back" value="dashboard"><button class="tt-btn stop sm" aria-label="Stop timer" title="Stop timer"><?= icon('stop') ?></button></form>
    <?php else: ?><a class="tt-btn go sm" href="<?= e(url('/time')) ?>" aria-label="Start a timer" title="Start a timer"><?= icon('play') ?></a><?php endif ?>
  </div>
  <div class="side-att">
    <div class="small"><?php if ($state === 'out'): ?><span class="muted">Not clocked in today</span><?php else: ?><span class="muted"><?= $state === 'break' ? 'On a break' : ($state === 'done' ? 'Clocked out' : 'Clocked in') ?> · <?= e(ftime($att['clock_in'])) ?></span><br><b><?= e(hours($live['worked'])) ?></b> <span class="muted">worked</span><?php endif ?></div>
    <?php if ($state === 'out'): ?><form method="post" action="<?= e(url('/attendance/clock-in')) ?>"><?= csrf_field() ?><button class="btn sm mint"><?= icon('log-in', 'sm') ?> Clock in</button></form>
    <?php elseif ($state === 'in'): ?><form method="post" action="<?= e(url('/attendance/clock-out')) ?>" data-confirm="Clock out for today?"><?= csrf_field() ?><button class="btn sm ghost"><?= icon('log-out', 'sm') ?> Clock out</button></form>
    <?php elseif ($state === 'break'): ?><form method="post" action="<?= e(url('/attendance/break/end')) ?>"><?= csrf_field() ?><button class="btn sm mint"><?= icon('play', 'sm') ?> End break</button></form>
    <?php else: ?><span class="pill pill-mint">Day complete</span><?php endif ?>
  </div>
</div>
