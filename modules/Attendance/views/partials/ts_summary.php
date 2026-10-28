<?php /* $s summary, $ws week start */
$dates = array_keys($s['days']);
$h = static fn (int $x): string => $x ? sprintf('%d:%02d', intdiv($x, 3600), intdiv($x % 3600, 60)) : '–';
?>
<div class="week mb"><?php foreach ($s['days'] as $d => $sec): ?><div class="day<?= $d === date('Y-m-d') ? ' on' : '' ?>"><span class="muted"><?= e(date('D', strtotime($d))) ?></span><b><?= e(date('j', strtotime($d))) ?></b><span class="xs<?= $sec ? ' b' : ' muted' ?>"><?= e($h((int) $sec)) ?></span></div><?php endforeach ?></div>
<?php if (!$s['projects']): ?><?= empty_state('Nothing tracked this week', 'Time you log appears here grouped by project.', 'calendar-person') ?><?php else: ?>
<div class="table-wrap"><table class="table ts-table">
  <thead><tr><th>Project</th><?php foreach ($dates as $d): ?><th><?= e(date('D j', strtotime($d))) ?></th><?php endforeach ?><th>Total</th><th>Billable</th></tr></thead>
  <tbody><?php foreach ($s['projects'] as $pr): ?><tr>
    <td><span class="flex"><span class="dotc bg-<?= e($pr['color']) ?>"></span><b class="truncate"><?= e($pr['name']) ?></b></span></td>
    <?php foreach ($dates as $d): $v = (int) ($pr['days'][$d] ?? 0); ?><td class="<?= $v ? '' : 'zero' ?>"><?= e($h($v)) ?></td><?php endforeach ?>
    <td><b><?= e($h($pr['total'])) ?></b></td><td class="muted"><?= e($h($pr['billable'])) ?></td>
  </tr><?php endforeach ?></tbody>
  <tfoot>
    <tr><td>Total tracked</td><?php foreach ($dates as $d): ?><td><?= e($h((int) $s['days'][$d])) ?></td><?php endforeach ?><td><?= e($h($s['total'])) ?></td><td><?= e($h($s['billable'])) ?></td></tr>
    <tr class="muted"><td class="muted">Attendance worked</td><?php foreach ($dates as $d): ?><td class="muted"><?= e($h((int) ($s['attendance'][$d] ?? 0))) ?></td><?php endforeach ?><td class="muted"><?= e($h((int) array_sum($s['attendance']))) ?></td><td></td></tr>
  </tfoot>
</table></div>
<?php endif ?>
