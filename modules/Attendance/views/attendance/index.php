<?php
use Modules\Attendance\Services\AttendanceService;
$hms = static fn (int $s): string => sprintf('%02d:%02d:%02d', intdiv($s, 3600), intdiv($s % 3600, 60), $s % 60);
$target = AttendanceService::workSeconds();
$chip = ['out' => ['dot-out', 'Not started'], 'in' => ['dot-in pulse', $tracker['state'] === 'tracking' ? 'Tracking' : 'Not tracking'], 'break' => ['dot-break pulse', 'Paused'], 'done' => ['dot-done', 'Day ended']][$state];
$prev = date('Y-m', strtotime($ym.'-01 -1 month')); $next = date('Y-m', strtotime($ym.'-01 +1 month'));
$t = $month['totals'];
$leaveOn = feature_on('leave'); /* leave & holidays switched off: such days show as a neutral "Day off" */
$off = static fn (string $st): bool => !$leaveOn && in_array($st, ['leave', 'holiday'], true);
$actions = (can('attendance.view_all') ? '<a class="btn ghost" href="'.e(url('/attendance/team')).'">'.icon('users', 'sm').' Team view</a>' : '').(can('attendance.track') ? '<a class="btn soft" href="'.e(url('/time')).'">'.icon('timer', 'sm').' Time Tracking</a>' : '');
?>
<?= partial('partials/page_head', ['title' => 'Attendance', 'subtitle' => 'Your day starts in the Niyanta Tracker desktop app and closes by itself at midnight. Only tracked time counts as worked time.', 'crumbs' => [['People'], ['Attendance']], 'actions' => $actions]) ?>
<?php if (can('attendance.track')): ?>
<div class="card hero att-hero mb">
  <div>
    <div class="flex wrap"><span class="state-chip"><span class="dotc <?= e($chip[0]) ?>"></span><?= e($chip[1]) ?></span><span class="pill"><?= e(date('l, d M')) ?></span>
      <?php if ($holiday && $leaveOn): ?><span class="pill"><?= icon('gift', 'sm') ?> Holiday</span><?php endif ?><?php if ($onLeave && $leaveOn): ?><span class="pill"><?= icon('plane', 'sm') ?> On approved leave</span><?php endif ?></div>
    <div class="big mt" data-tick data-base="<?= (int) $live['worked'] ?>"<?= $state === 'in' ? '' : ' data-paused' ?>><?= e($hms((int) $live['worked'])) ?></div>
    <div class="muted small mt">worked today · target <?= e(hours($target)) ?></div>
    <div class="progress mt"><span style="width:<?= min(100, (int) round(100 * $live['worked'] / $target)) ?>%"></span></div>
    <div class="facts">
      <div><small>Started</small><b><?= e($today && $today['clock_in'] ? ftime($today['clock_in']) : '-') ?></b></div>
      <div><small>Ended</small><b><?= e($today && $today['clock_out'] ? ftime($today['clock_out']) : '-') ?></b></div>
      <div><small>Paused</small><b><span data-tick data-fmt="hm" data-base="<?= (int) $live['break'] ?>"<?= $state === 'break' ? '' : ' data-paused' ?>><?= e(hours($live['break'])) ?></span></b></div>
      <div><small>Timer</small><b><?php if ($running): ?><a href="<?= e(url('/time')) ?>"><?= e(str_limit(feature_on('projects') ? ($running['project_name'] ?? 'No project') : 'Tracking', 22)) ?></a><?php else: ?>Off<?php endif ?></b></div>
    </div>
    <div class="flex wrap">
      <span class="small muted"><?= icon('monitor', 'sm') ?> <?= $state === 'done' ? 'Nice work today'.($today['note'] ? ' · '.e($today['note']) : '').'.' : 'Start, pause and resume in the Niyanta Tracker app.' ?></span>
      <a class="btn sm ghost" href="<?= e(url('/time#desktop')) ?>"><?= icon('download', 'sm') ?> Desktop app</a>
    </div>
  </div>
  <?= illus($state === 'break' ? 'coffee-break' : ($state === 'done' ? 'celebrate' : 'clock-worker')) ?>
</div>
<?php endif ?>
<div class="grid g-main">
  <div class="stack">
    <div class="card">
      <div class="card-head"><h3><?= e(date('F Y', strtotime($ym.'-01'))) ?></h3>
        <div class="wk-nav"><a class="btn icon sm ghost" href="<?= e(url('/attendance?month='.$prev)) ?>" aria-label="Previous month"><?= icon('chevron-left', 'sm') ?></a><?php if ($ym !== date('Y-m')): ?><a class="btn sm ghost" href="<?= e(url('/attendance')) ?>">This month</a><?php endif ?><a class="btn icon sm ghost" href="<?= e(url('/attendance?month='.$next)) ?>" aria-label="Next month"><?= icon('chevron-right', 'sm') ?></a></div></div>
      <div class="att-cal">
        <?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $dw): ?><div class="dow"><?= $dw ?></div><?php endforeach ?>
        <?php for ($i = 1; $i < (int) date('N', strtotime($month['from'])); $i++): ?><div class="att-day blank"></div><?php endfor ?>
        <?php foreach ($month['days'] as $d => $day): $lbl = $off($day['status']) ? 'Day off' : match ($day['status']) { 'holiday' => $day['holiday'] ?? 'Holiday', 'leave' => ($day['leave'] === 0.5 ? 'Half-day leave' : 'Leave'), 'weekend' => 'Weekend', 'absent' => 'Absent', '' => '', default => hours($day['worked']) }; ?>
          <div class="att-day <?= $day['status'] ? 'st-'.e($day['status']) : '' ?><?= $d === date('Y-m-d') ? ' today' : '' ?>" title="<?= e(fdate($d).($lbl ? ' · '.$lbl : '').($day['row']['note'] ?? '' ? ' · '.$day['row']['note'] : '')) ?>"><span class="n"><?= (int) substr($d, 8) ?></span><span class="h"><?= e($lbl) ?></span></div>
        <?php endforeach ?>
      </div>
      <div class="legend mt"><?php foreach (['present' => 'Present', 'half_day' => 'Half day', 'absent' => 'Absent'] + ($leaveOn ? ['leave' => 'Leave', 'holiday' => 'Holiday'] : []) + ['weekend' => 'Weekend'] as $k => $l): ?><span><i class="lg-<?= $k ?>"></i><?= $l ?></span><?php endforeach ?></div>
    </div>
    <div class="card">
      <div class="card-head"><h3>Daily log</h3><span class="muted small"><?= count($records) ?> records</span></div>
      <?php if (!$records): ?><?= empty_state('No attendance yet this month', 'Start your day in the Niyanta Tracker desktop app to create your first record.', 'calendar-person') ?><?php else: ?>
      <div class="table-wrap"><table class="table att-log">
        <thead><tr><th>Date</th><th>Clock in</th><th>Clock out</th><th>Breaks</th><th>Worked</th><th>Status</th><th>Note</th></tr></thead>
        <tbody><?php foreach ($records as $r): ?><tr>
          <td><b><?= e(fdate($r['work_date'], 'D, d M')) ?></b></td>
          <td><?= e($r['clock_in'] ? ftime($r['clock_in']) : '-') ?></td><td><?= e($r['clock_out'] ? ftime($r['clock_out']) : '-') ?></td>
          <td class="muted"><?= e(hours($r['break_seconds'])) ?></td><td><b><?= e($r['clock_out'] ? hours($r['worked_seconds']) : ($r['clock_in'] ? 'In progress' : '-')) ?></b></td>
          <td><?= $r['clock_in'] && !$r['clock_out'] ? badge('in_progress', 'Working') : ($off((string) $r['status']) ? badge('weekend', 'Day off') : badge($r['status'])) ?></td>
          <td class="small muted"><?= e($r['note'] ?? '') ?><?php if ($r['editor']): ?><div class="xs">Edited by <?= e($r['editor']) ?></div><?php endif ?></td>
        </tr><?php endforeach ?></tbody>
      </table></div><?php endif ?>
    </div>
  </div>
  <div class="stack">
    <div class="card">
      <div class="card-head"><h3>This week</h3><span class="pill pill-lav"><?= e(hours(array_sum($week))) ?></span></div>
      <?php $max = max(1, $target, ...array_values($week)); ?>
      <div class="bars att-bars"><?php for ($i = 0; $i < 7; $i++): $d = date('Y-m-d', strtotime($ws." +$i days")); $s = (int) ($week[$d] ?? 0); ?>
        <div class="bar<?= $d === date('Y-m-d') ? ' hi' : '' ?>"><i style="height:<?= max(4, (int) round(100 * $s / $max)) ?>%" data-v="<?= $s ? e(sprintf('%.1fh', $s / 3600)) : '' ?>"></i><small><?= e(date('D', strtotime($d))) ?></small></div>
      <?php endfor ?></div>
    </div>
    <div class="card">
      <div class="card-head"><h3>Month summary</h3></div>
      <div class="mini-tiles">
        <div class="mini m-mint"><span class="lbl"><?= icon('check-circle', 'sm') ?> Present</span><span class="val"><?= e(rtrim(rtrim(number_format($t['present'] + $t['half_day'] * 0.5, 1), '0'), '.')) ?></span></div>
        <div class="mini m-lav"><span class="lbl"><?= icon('clock', 'sm') ?> Hours</span><span class="val"><?= e(sprintf('%.1f', $t['worked'] / 3600)) ?></span></div>
        <?php if ($leaveOn): ?><div class="mini m-sky"><span class="lbl"><?= icon('plane', 'sm') ?> Leave</span><span class="val"><?= e((string) $t['leave']) ?></span></div><?php endif ?>
        <div class="mini m-pink"><span class="lbl"><?= icon('alert', 'sm') ?> Absent</span><span class="val"><?= (int) $t['absent'] ?></span></div>
      </div>
      <dl class="kv mt">
        <dt>Half days</dt><dd><?= (int) $t['half_day'] ?></dd>
        <?php if ($leaveOn): ?><dt>Holidays</dt><dd><?= (int) $t['holiday'] ?></dd><?php endif ?>
        <dt>Avg / day worked</dt><dd><?= e(hours($t['days_worked'] ? intdiv($t['worked'], $t['days_worked']) : 0)) ?></dd>
      </dl>
    </div>
  </div>
</div>
