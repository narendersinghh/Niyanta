<div class="card">
  <div class="card-head"><h3>Who's out today</h3><a class="xs muted" href="<?= e(url('/hr/leave/calendar')) ?>">Calendar <?= icon('arrow-right', 'sm') ?></a></div>
  <?php if (!$out): ?>
    <div class="flex"><div style="width:92px;flex:none"><?= illus('calendar-person') ?></div><div><b>Full house</b><p class="small muted mb0">Everyone is in today.</p></div></div>
  <?php else: ?>
  <div class="list"><?php foreach ($out as $o): ?><div class="list-item"><?= avatar($o['name'], $o['avatar'], 'sm') ?><div class="grow"><b class="small"><?= e($o['name']) ?></b><div class="meta"><?= $o['half_day'] ? 'Half day today' : 'Back after '.e(fdate($o['end_date'], 'd M')) ?></div></div><span class="pill pill-<?= e($o['color']) ?>"><?= e($o['type_name']) ?></span></div><?php endforeach ?></div>
  <?php endif ?>
  <?php if ($holidays): ?>
  <div class="soft-peach mt" style="display:flex;align-items:center;gap:8px;border-radius:18px;padding:12px 14px;overflow:hidden">
    <div class="grow"><b class="small"><?= icon('gift', 'sm') ?> Upcoming holidays</b>
      <?php foreach ($holidays as $h): ?><div class="flex between xs" style="margin-top:6px"><span><?= e($h['name']) ?><?= $h['optional'] ? ' <span class="muted">(opt.)</span>' : '' ?></span><b class="nowrap"><?= e(fdate($h['holiday_date'], 'd M')) ?></b></div><?php endforeach ?>
    </div>
    <div style="width:86px;flex:none;margin:-6px -8px -14px 0"><?= illus('leave-beach') ?></div>
  </div>
  <?php endif ?>
</div>
