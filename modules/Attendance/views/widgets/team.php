<div class="card">
  <div class="card-head"><h3>Who's working now</h3><a class="btn sm ghost" href="<?= e(url('/attendance/team')) ?>">Team view <?= icon('arrow-right', 'sm') ?></a></div>
  <?php if (!$rows): ?><?= empty_state('Nobody is clocked in yet', 'Your team members will appear here as they start their day.', 'coffee-break') ?><?php else: ?>
  <div class="who"><?php foreach ($rows as $r): ?>
    <div class="who-row"><?= avatar($r['name'], $r['avatar'], 'sm') ?>
      <div class="grow"><b class="truncate"><?= e($r['name']) ?></b><span class="xs muted truncate d-block"><?= $r['timer_start'] ? icon('timer', 'sm').' '.e($r['project_name'] ?? 'Tracking time') : e($r['designation'] ?? '') ?></span></div>
      <span class="flex small"><span class="dotc <?= $r['on_break'] ? 'dot-break' : ($r['clock_in'] || $r['timer_start'] ? 'dot-in pulse' : 'dot-out') ?>"></span><?= $r['on_break'] ? 'Break' : ($r['clock_in'] ? 'Since '.e(ftime($r['clock_in'])) : 'Tracking') ?></span>
      <?php if ($r['timer_start']): ?><span class="t"><?= e(hours(max(0, time() - strtotime($r['timer_start']) - (int) $r['discarded_seconds']))) ?></span><?php endif ?>
    </div>
  <?php endforeach ?></div><?php endif ?>
</div>
