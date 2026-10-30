<?php
/* Monthly timesheet: calendar with tracked time per day. $month (Y-m), $days (date => seconds), $att (date => status), $hol (date => name), $target, $targetName, $people */
$me = (int) user()['id'];
$m0 = $month.'-01'; $n = (int) date('t', strtotime($m0));
$lead = (int) date('N', strtotime($m0)) - 1;
$today = date('Y-m-d');
$total = array_sum($days);
$worked = count(array_filter($days));
$weekend = array_map('intval', explode(',', (string) setting('general.weekend_days', '6,7')));
$uq = $target !== $me ? '&user='.$target : '';
$attLbl = ['leave' => 'Leave', 'holiday' => 'Holiday', 'absent' => 'Absent', 'half_day' => 'Half day'];
?>
<?= partial('attendance::partials/hub', ['tab' => 'monthly', 'title' => 'Time & Attendance', 'subtitle' => 'Tracked time for every day of the month. Open a day to see its timeline.', 'target' => $target, 'targetName' => $targetName, 'people' => $people, 'mode' => 'month', 'month' => $month, 'base' => '/time/monthly',
  'extra' => '<div class="hub-total"><span>Total</span><b>'.e(hours($total)).'</b><span class="muted">'.$worked.' day'.($worked === 1 ? '' : 's').' tracked</span></div>']) ?>
<div class="card mcal-card">
  <div class="mcal" role="grid" aria-label="<?= e(date('F Y', strtotime($m0))) ?>">
    <?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $i => $dw): ?><div class="mcal-dw<?= in_array($i + 1, $weekend, true) ? ' we' : '' ?>" role="columnheader"><?= $dw ?></div><?php endforeach ?>
    <?php for ($i = 0; $i < $lead; $i++): ?><div class="mcal-d out" aria-hidden="true"></div><?php endfor ?>
    <?php for ($d = 1; $d <= $n; $d++): $ds = sprintf('%s-%02d', $month, $d); $s = $days[$ds] ?? 0; $fut = $ds > $today; $we = in_array((int) date('N', strtotime($ds)), $weekend, true);
      $note = $hol[$ds] ?? (isset($att[$ds]) ? $attLbl[$att[$ds]] ?? '' : ''); ?>
    <<?= $fut ? 'div' : 'a href="'.e(url('/time/daily?date='.$ds.$uq)).'"' ?> class="mcal-d<?= $ds === $today ? ' today' : '' ?><?= $we ? ' we' : '' ?><?= $fut ? ' fut' : '' ?>" role="gridcell"<?= $fut ? '' : ' title="Open '.e(fdate($ds)).'"' ?>>
      <span class="mcal-n"><?= $d ?></span>
      <?php if ($s): ?><span class="mcal-v"><?= e(hours($s)) ?></span><?php elseif (!$fut): ?><span class="mcal-nil">-</span><?php endif ?>
      <?php if ($note !== ''): ?><span class="mcal-note truncate"><?= e($note) ?></span><?php endif ?>
    </<?= $fut ? 'div' : 'a' ?>>
    <?php endfor ?>
    <?php for ($i = ($lead + $n) % 7; $i && $i < 7; $i++): ?><div class="mcal-d out" aria-hidden="true"></div><?php endfor ?>
  </div>
</div>
