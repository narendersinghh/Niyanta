<?php
use Modules\Attendance\Services\AttendanceService;
$target = AttendanceService::workSeconds();
$chip = ['out' => ['dot-out', 'Not started'], 'in' => ['dot-in pulse', $tracker['state'] === 'tracking' ? 'Tracking' : 'Not tracking'], 'break' => ['dot-break pulse', 'Paused'], 'done' => ['dot-done', 'Day ended']][$state];
$t = $month['totals'];
$leaveOn = feature_on('leave'); /* leave & holidays switched off: such days show as a neutral "Day off" */
$off = static fn (string $st): bool => !$leaveOn && in_array($st, ['leave', 'holiday'], true);
?>
<?= partial('attendance::partials/hub', ['tab' => 'attendance', 'title' => 'Time & Attendance', 'subtitle' => 'Your attendance month. Days open in the desktop app and close by themselves at midnight.', 'mode' => 'month', 'month' => $ym, 'base' => '/attendance']) ?>
<?php $isNow = $ym === date('Y-m'); $wpct = min(100, (int) round(100 * $live['worked'] / $target)); ?>
<section class="ta-stats" aria-label="<?= e(date('F Y', strtotime($ym.'-01'))) ?> summary">
  <?php if (can('attendance.track') && $isNow): ?><a class="ta-stat c-mint" href="<?= e(url('/time')) ?>"><span class="k"><span class="dotc <?= e($chip[0]) ?>"></span><?= e($chip[1]) ?> · today</span><span class="v" data-tick data-fmt="hm" data-base="<?= (int) $live['worked'] ?>"<?= $state === 'in' ? '' : ' data-paused' ?>><?= e(hours($live['worked'])) ?></span><span class="ta-meter" aria-hidden="true"><i style="width:<?= $wpct ?>%"></i></span><span class="s">of <?= e(hours($target)) ?> target<?= $holiday && $leaveOn ? ' · holiday' : '' ?><?= $onLeave && $leaveOn ? ' · on leave' : '' ?></span></a><?php endif ?>
  <div class="ta-stat c-mint"><span class="k"><i></i>Present</span><span class="v"><?= e(rtrim(rtrim(number_format($t['present'] + $t['half_day'] * 0.5, 1), '0'), '.')) ?><small>days</small></span><span class="s"><?= (int) $t['half_day'] ?> half day<?= (int) $t['half_day'] === 1 ? '' : 's' ?></span></div>
  <div class="ta-stat c-lav"><span class="k"><i></i>Worked</span><span class="v"><?= e(hours($t['worked'])) ?></span><span class="s">avg <?= e(hours($t['days_worked'] ? intdiv($t['worked'], $t['days_worked']) : 0)) ?> a day</span></div>
  <?php if ($leaveOn): ?><div class="ta-stat c-sky<?= $t['leave'] ? '' : ' is-zero' ?>"><span class="k"><i></i>Leave</span><span class="v"><?= e((string) $t['leave']) ?><small>days</small></span><span class="s"><?= (int) $t['holiday'] ?> holiday<?= (int) $t['holiday'] === 1 ? '' : 's' ?></span></div><?php endif ?>
  <div class="ta-stat c-red<?= $t['absent'] ? ' is-alert' : ' is-zero' ?>"><span class="k"><i></i>Absent</span><span class="v"><?= (int) $t['absent'] ?><small>days</small></span><span class="s"><?= $t['absent'] ? 'check the calendar' : 'none this month' ?></span></div>
</section>
<div class="ta-split wide">
  <div class="ta-col">
    <section class="card" aria-labelledby="cal-h">
      <div class="card-head"><h3 id="cal-h"><?= e(date('F Y', strtotime($ym.'-01'))) ?></h3></div>
      <div class="att-cal">
        <?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $dw): ?><div class="dow"><?= $dw ?></div><?php endforeach ?>
        <?php for ($i = 1; $i < (int) date('N', strtotime($month['from'])); $i++): ?><div class="att-day blank"></div><?php endfor ?>
        <?php foreach ($month['days'] as $d => $day): $lbl = $off($day['status']) ? 'Day off' : match ($day['status']) { 'holiday' => $day['holiday'] ?? 'Holiday', 'leave' => ($day['leave'] === 0.5 ? 'Half-day leave' : 'Leave'), 'weekend' => 'Weekend', 'absent' => 'Absent', '' => '', default => hours($day['worked']) }; ?>
          <div class="att-day <?= $day['status'] ? 'st-'.e($day['status']) : '' ?><?= $d === date('Y-m-d') ? ' today' : '' ?>" title="<?= e(fdate($d).($lbl ? ' · '.$lbl : '').($day['row']['note'] ?? '' ? ' · '.$day['row']['note'] : '')) ?>"><span class="n"><?= (int) substr($d, 8) ?></span><span class="h"><?= e($lbl) ?></span></div>
        <?php endforeach ?>
      </div>
      <div class="legend ta-legend"><?php foreach (['present' => 'Present', 'half_day' => 'Half day', 'absent' => 'Absent'] + ($leaveOn ? ['leave' => 'Leave', 'holiday' => 'Holiday'] : []) + ['weekend' => 'Weekend'] as $k => $l): ?><span><i class="lg-<?= $k ?>"></i><?= $l ?></span><?php endforeach ?></div>
    </section>
    <section class="card">
      <div class="card-head"><h3>Daily log</h3><span class="muted small"><?= count($records) ?> record<?= count($records) === 1 ? '' : 's' ?></span></div>
      <?php if (!$records): ?><?= empty_state('No attendance yet this month', 'Start your day in the Niyanta Tracker desktop app to create your first record.', 'calendar-person') ?><?php else: ?>
      <div class="table-wrap"><table class="table att-log ta-rtable">
        <thead><tr><th>Date</th><th>Clock in</th><th>Clock out</th><th>Breaks</th><th>Worked</th><th>Status</th><th>Note</th></tr></thead>
        <tbody><?php foreach ($records as $r): ?><tr>
          <td class="rt-main"><b><?= e(fdate($r['work_date'], 'D, d M')) ?></b></td>
          <td data-l="In"><?= e($r['clock_in'] ? ftime($r['clock_in']) : '-') ?></td><td data-l="Out"><?= e($r['clock_out'] ? ftime($r['clock_out']) : '-') ?></td>
          <td class="muted" data-l="Breaks"><?= e(hours($r['break_seconds'])) ?></td><td data-l="Worked"><b><?= e($r['clock_out'] ? hours($r['worked_seconds']) : ($r['clock_in'] ? 'In progress' : '-')) ?></b></td>
          <td class="rt-st"><?= $r['clock_in'] && !$r['clock_out'] ? badge('in_progress', 'Working') : ($off((string) $r['status']) ? badge('weekend', 'Day off') : badge($r['status'])) ?></td>
          <td class="small muted rt-note"><?= e($r['note'] ?? '') ?><?php if ($r['editor']): ?><div class="xs">Edited by <?= e($r['editor']) ?></div><?php endif ?></td>
        </tr><?php endforeach ?></tbody>
      </table></div><?php endif ?>
    </section>
  </div>
  <div class="ta-col">
    <section class="card" aria-labelledby="aw-h">
      <div class="card-head"><h3 id="aw-h">This week</h3><span class="muted small"><?= e(hours(array_sum($week))) ?></span></div>
      <?php $max = max(1, $target, ...array_values($week)); ?>
      <div class="bars att-bars"><?php for ($i = 0; $i < 7; $i++): $d = date('Y-m-d', strtotime($ws." +$i days")); $sv = (int) ($week[$d] ?? 0); ?>
        <div class="bar<?= $d === date('Y-m-d') ? ' hi' : '' ?>"><i style="height:<?= max(4, (int) round(100 * $sv / $max)) ?>%" data-v="<?= $sv ? e(hours($sv)) : '' ?>"></i><small><?= e(date('D', strtotime($d))) ?></small></div>
      <?php endfor ?></div>
    </section>
    <?php if (can('attendance.track') && $isNow): ?>
    <section class="card" aria-labelledby="at-h">
      <div class="card-head"><h3 id="at-h">Today</h3><span class="muted small"><?= e(date('D, d M')) ?></span></div>
      <dl class="ta-kv">
        <div><dt>Started</dt><dd><?= e($today && $today['clock_in'] ? ftime($today['clock_in']) : '-') ?></dd></div>
        <div><dt>Ended</dt><dd><?= e($today && $today['clock_out'] ? ftime($today['clock_out']) : '-') ?></dd></div>
        <div><dt>Paused</dt><dd><span data-tick data-fmt="hm" data-base="<?= (int) $live['break'] ?>"<?= $state === 'break' ? '' : ' data-paused' ?>><?= e(hours($live['break'])) ?></span></dd></div>
        <div><dt>Timer</dt><dd><?php if ($running): ?><a href="<?= e(url('/time')) ?>"><?= e(str_limit(feature_on('projects') ? ($running['project_name'] ?? 'No project') : 'Tracking', 22)) ?></a><?php else: ?>Off<?php endif ?></dd></div>
      </dl>
      <p class="ta-note"><?= $state === 'done' ? 'Nice work today'.($today['note'] ? ' · '.e($today['note']) : '').'.' : 'Start, pause and resume in the Niyanta Tracker app.' ?></p>
    </section>
    <?php endif ?>
  </div>
</div>
