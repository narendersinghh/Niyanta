<?php
$isToday = $date === date('Y-m-d');
$leaveOn = feature_on('leave'); /* leave & holidays switched off: no leave/holiday labels, such days show as "Day off" */
$notIn = max(0, (int) $stats['total'] - (int) $stats['working'] - (int) $stats['on_break'] - (int) $stats['done'] - $leaveCount);
$actions = '<button class="btn ghost" data-modal="export-dialog">'.icon('download', 'sm').' Export CSV</button>'.(can('attendance.manage') ? '<button class="btn primary" data-att-new data-modal="att-dialog">'.icon('plus', 'sm').' Add record</button>' : '');
?>
<?= partial('attendance::partials/hub', ['tab' => 'team-attendance', 'title' => 'Time & Attendance', 'subtitle' => $isToday ? 'Who is working right now across your team.' : 'Team attendance for '.fdate($date, 'l, d M Y').'.', 'actions' => $actions,
  'mode' => 'date', 'date' => $date, 'base' => '/attendance/team', 'hidden' => ['q' => $q]]) ?>
<div class="grid g4 mb">
  <div class="tile bg-mint"><span class="tile-ic"><?= icon('activity') ?></span><div><div class="num"><?= (int) $stats['working'] ?></div><div class="lbl"><?= $isToday ? 'Working now' : 'Open (not clocked out)' ?></div></div></div>
  <div class="tile bg-lemon"><span class="tile-ic"><?= icon('coffee') ?></span><div><div class="num"><?= (int) $stats['on_break'] ?></div><div class="lbl">On a break</div></div></div>
  <div class="tile bg-lav"><span class="tile-ic"><?= icon('log-out') ?></span><div><div class="num"><?= (int) $stats['done'] ?></div><div class="lbl">Clocked out</div></div></div>
  <?php if ($leaveOn): ?><div class="tile bg-sky"><span class="tile-ic"><?= icon('plane') ?></span><div><div class="num"><?= (int) $leaveCount ?></div><div class="lbl">On leave · <?= $notIn ?> <?= $isToday ? 'not in yet' : 'no record' ?></div></div><?= illus('team', 'corner') ?></div>
  <?php else: ?><div class="tile bg-sky"><span class="tile-ic"><?= icon('clock') ?></span><div><div class="num"><?= $notIn + (int) $leaveCount ?></div><div class="lbl"><?= $isToday ? 'Not in yet' : 'No record' ?></div></div><?= illus('team', 'corner') ?></div><?php endif ?>
</div>
<div class="card">
  <form class="filters" method="get">
    <?php if (!$isToday): ?><input type="hidden" name="date" value="<?= e($date) ?>"><?php endif ?>
    <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search people" aria-label="Search people">
    <button class="btn ghost sm"><?= icon('filter', 'sm') ?> Filter</button>
    <?php if ($holiday && $leaveOn): ?><span class="pill pill-peach"><?= icon('gift', 'sm') ?> <?= e($holiday) ?></span><?php elseif ($weekend): ?><span class="pill pill-gray">Weekend</span><?php endif ?>
  </form>
  <?php if (!$p['items']): ?><?= empty_state('No team members found', 'People who report to you'.(feature_on('projects') ? ' or work on your projects' : '').' appear here.', 'team') ?><?php else: ?>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Person</th><th>Status</th><th>Clock in</th><th>Clock out</th><th>Breaks</th><th>Worked</th><th>Timer</th><th></th></tr></thead>
    <tbody><?php foreach ($p['items'] as $r):
      $lv = $pageLeave[(int) $r['id']][$date] ?? null;
      if ($r['clock_in'] && !$r['clock_out']) { [$st, $dot] = $r['break_start'] ? ['On break', 'dot-break pulse'] : ['Working', 'dot-in pulse']; }
      elseif ($r['clock_out']) { [$st, $dot] = ['Clocked out', 'dot-done']; }
      elseif ($r['att_id'] && !$leaveOn && in_array($r['status'], ['leave', 'holiday'], true)) { [$st, $dot] = ['Day off', 'dot-out']; }
      elseif ($r['att_id']) { [$st, $dot] = [ucwords(str_replace('_', ' ', $r['status'])), $r['status'] === 'leave' ? 'dot-leave' : 'dot-absent']; }
      elseif ($lv && $leaveOn) { [$st, $dot] = [$lv === 0.5 ? 'Half-day leave' : 'On leave', 'dot-leave']; }
      elseif ($lv || $holiday || $weekend) { [$st, $dot] = [$weekend && !$holiday && !$lv ? 'Weekend' : ($leaveOn ? 'Holiday' : 'Day off'), 'dot-out']; }
      else { [$st, $dot] = [$isToday ? 'Not in yet' : 'Absent', $isToday ? 'dot-out' : 'dot-absent']; }
      $worked = $r['clock_in'] && !$r['clock_out'] ? max(0, time() - strtotime($r['clock_in']) - (int) $r['break_seconds'] - ($r['break_start'] ? time() - strtotime($r['break_start']) : 0)) : (int) $r['worked_seconds'];
      [$tStart, $tProj] = $r['timer'] ? explode('|', $r['timer'], 2) : [null, null];
      $json = ['user_id' => (int) $r['id'], 'name' => $r['name'], 'work_date' => $date, 'clock_in' => $r['clock_in'] ? substr($r['clock_in'], 11, 5) : '', 'clock_out' => $r['clock_out'] ? substr($r['clock_out'], 11, 5) : '', 'break_minutes' => (int) round($r['break_seconds'] / 60), 'status' => $r['status'] ?: 'present', 'note' => $r['note'] ?? ''];
    ?><tr>
      <td><div class="flex"><?= avatar($r['name'], $r['avatar'], 'sm') ?><div><b><?= e($r['name']) ?></b><div class="xs muted"><?= e($r['designation'] ?: ($r['department'] ?? '')) ?></div></div></div></td>
      <td><span class="flex small b"><span class="dotc <?= e($dot) ?>"></span><?= e($st) ?></span><?php if ($r['note']): ?><div class="xs muted"><?= e(str_limit($r['note'], 40)) ?></div><?php endif ?></td>
      <td><?= e($r['clock_in'] ? ftime($r['clock_in']) : '-') ?></td>
      <td><?= e($r['clock_out'] ? ftime($r['clock_out']) : '-') ?></td>
      <td class="muted"><?= $r['att_id'] ? e(hours($r['break_seconds'])) : '-' ?></td>
      <td><b><?= $r['clock_in'] ? e(hours($worked)) : '-' ?></b></td>
      <td class="small"><?php if ($tStart): ?><span class="pill pill-mint"><?= icon('timer', 'sm') ?> <?= e(str_limit($tProj ?: 'Tracking', 18)) ?> · <?= e(hours(time() - strtotime($tStart))) ?></span><?php else: ?><span class="muted">-</span><?php endif ?></td>
      <td class="actions"><?php if (can('attendance.manage')): ?><button class="btn icon sm ghost" data-modal="att-dialog" data-att-edit="<?= e(json_encode($json)) ?>" aria-label="Edit"><?= icon('edit', 'sm') ?></button><?php endif ?>
        <?php if (can('attendance.screenshots.view') && setting('attendance.screenshots_enabled')): ?><a class="btn icon sm ghost" href="<?= e(url('/time/screenshots?user='.$r['id'].'&date='.$date)) ?>" aria-label="Screenshots" title="Screenshots"><?= icon('image', 'sm') ?></a><?php endif ?></td>
    </tr><?php endforeach ?></tbody>
  </table></div>
  <?= pager($p) ?>
  <?php endif ?>
</div>
<dialog class="modal" id="export-dialog"><form method="get" action="<?= e(url('/attendance/export')) ?>" data-no-lock>
  <div class="modal-head"><h3>Export attendance</h3><button type="button" class="btn icon sm ghost" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div>
  <div class="modal-body form"><p class="muted small mb0">Downloads a CSV of everyone you can see for the chosen range (up to 93 days).</p>
    <div class="form-grid"><div class="field"><label>From</label><input type="date" name="from" value="<?= e(date('Y-m-01', strtotime($date))) ?>" required></div><div class="field"><label>To</label><input type="date" name="to" value="<?= e($date) ?>" required></div></div>
    <div class="form-actions"><button type="button" class="btn ghost" data-close>Cancel</button><button class="btn primary" ><?= icon('download', 'sm') ?> Download CSV</button></div></div>
</form></dialog>
<?php if (can('attendance.manage')): ?>
<dialog class="modal" id="att-dialog"><form method="post" action="<?= e(url('/attendance/records')) ?>" data-att-form><?= csrf_field() ?>
  <div class="modal-head"><h3 data-att-title>Attendance record</h3><button type="button" class="btn icon sm ghost" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div>
  <div class="modal-body form">
    <div class="form-grid">
      <div class="field full"><label>Employee *</label><select name="user_id" required><option value="">Select…</option><?php foreach ($people as $id => $n): ?><option value="<?= (int) $id ?>"><?= e($n) ?></option><?php endforeach ?></select></div>
      <div class="field"><label>Date *</label><input type="date" name="work_date" value="<?= e($date) ?>" max="<?= e(date('Y-m-d')) ?>" required></div>
      <div class="field"><label>Status *</label><select name="status"><?php foreach (['present' => 'Present', 'half_day' => 'Half day', 'absent' => 'Absent'] + ($leaveOn ? ['leave' => 'Leave', 'holiday' => 'Holiday'] : []) + ['weekend' => 'Weekend'] as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach ?></select></div>
      <div class="field"><label>Clock in</label><input type="time" name="clock_in"></div>
      <div class="field"><label>Clock out</label><input type="time" name="clock_out"><div class="hint">Earlier than clock-in means the next day.</div></div>
      <div class="field"><label>Break minutes</label><input type="number" name="break_minutes" min="0" max="1440" value="0"></div>
      <div class="field"><label>Note</label><input type="text" name="note" maxlength="255" placeholder="Reason for the change"></div>
    </div>
    <div class="alert info mb0"><?= icon('info', 'sm') ?> Changes are recorded in the audit log with your name.</div>
    <div class="form-actions"><button type="button" class="btn ghost" data-close>Cancel</button><button class="btn primary"><?= icon('check', 'sm') ?> Save record</button></div>
  </div>
</form></dialog>
<?php endif ?>
