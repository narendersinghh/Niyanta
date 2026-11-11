<?php
/* Device split of one person's day (shown only when 2+ devices tracked it): a segmented toggle "All devices | device ..." with each option's tracked time,
   and one small metrics tile per device (tracked time, screenshots, activity). Options link to $base with ?device= and keep the date and member.
   $devices (Services\Devices::day), $device (picked item or null), $base, $date, $target */
if (count($devices ?? []) < 2) { return; }
$q = array_filter(['user' => $target !== (int) user()['id'] ? $target : null, 'scope' => ($_GET['scope'] ?? '') === 'org' ? 'org' : null, 'date' => $date !== date('Y-m-d') ? $date : null], static fn ($v) => $v !== null);
$href = static fn (?string $d): string => url($base.(($x = $q + array_filter(['device' => $d])) ? '?'.http_build_query($x) : ''));
$cur = $device['param'] ?? null;
?>
<div class="dev-split">
  <nav class="seg dev-seg" aria-label="Device"><a href="<?= e($href(null)) ?>" class="<?= $cur === null ? 'on' : '' ?>"<?= $cur === null ? ' aria-current="page"' : '' ?>><?= icon('layers', 'sm') ?>All devices<small><?= e(hours(array_sum(array_column($devices, 'secs')))) ?></small></a><?php foreach ($devices as $d): $on = $cur === $d['param']; ?><a href="<?= e($href($d['param'])) ?>" class="<?= $on ? 'on' : '' ?>"<?= $on ? ' aria-current="page"' : '' ?> title="<?= e($d['name']) ?>"><?= icon($d['key'] ? 'monitor' : 'edit', 'sm') ?><span class="truncate"><?= e($d['name']) ?></span><small><?= e(hours($d['secs'])) ?></small></a><?php endforeach ?></nav>
  <ul class="dev-stats" aria-label="Per device"><?php foreach ($devices as $d): $a = $d['act']; ?><li class="<?= $cur === $d['param'] ? 'on' : '' ?>"><b class="truncate" title="<?= e($d['name']) ?>"><?= e($d['name']) ?></b><span><?= e(hours($d['secs'])) ?><small>tracked</small></span><span><?= (int) $d['shots'] ?><small>screenshot<?= $d['shots'] === 1 ? '' : 's' ?></small></span><span class="<?= $a === null ? '' : 'lv-'.($a >= 60 ? 'hi' : ($a >= 30 ? 'mid' : 'lo')) ?>"><?= $a === null ? '-' : $a.'%' ?><small>activity</small></span></li><?php endforeach ?></ul>
</div>
