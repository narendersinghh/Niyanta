<div class="card">
  <div class="card-head"><h3>Who's out today</h3><a class="xs muted" href="<?= e(url('/hr/leave/calendar')) ?>">Calendar <?= icon('arrow-right', 'sm') ?></a></div>
  <?php if (!$out): ?>
    <div class="list-item"><span class="avatar bg-mint"><?= icon('check', 'sm') ?></span><div class="grow"><b class="small">Full house</b><div class="meta">Everyone is in today.</div></div></div>
  <?php else: ?>
  <div class="list"><?php foreach ($out as $o): ?><div class="list-item"><?= avatar($o['name'], $o['avatar'], 'sm') ?><div class="grow"><b class="small"><?= e($o['name']) ?></b><div class="meta"><?= $o['half_day'] ? 'Half day today' : 'Back after '.e(fdate($o['end_date'], 'd M')) ?></div></div><span class="pill pill-<?= e($o['color']) ?>"><?= e($o['type_name']) ?></span></div><?php endforeach ?></div>
  <?php endif ?>
  <?php if ($holidays): ?>
  <div class="card-head mt"><h4><?= icon('gift', 'sm') ?> Upcoming holidays</h4><a class="xs muted" href="<?= e(url('/hr/holidays')) ?>">All <?= icon('arrow-right', 'sm') ?></a></div>
  <div class="list"><?php foreach ($holidays as $h): ?><div class="list-item"><span class="avatar <?= $h['optional'] ? 'bg-sky' : 'bg-peach' ?>"><?= e(date('d', strtotime($h['holiday_date']))) ?></span><div class="grow"><b class="small"><?= e($h['name']) ?></b><div class="meta"><?= e(fdate($h['holiday_date'], 'D, d M')) ?></div></div><span class="pill pill-<?= $h['optional'] ? 'sky' : 'peach' ?>"><?= $h['optional'] ? 'Optional' : 'Fixed' ?></span></div><?php endforeach ?></div>
  <?php endif ?>
</div>
