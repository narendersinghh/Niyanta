<?php
/* Timesheet week: one summary row (range, status, tracked vs expected, billable, overtime, days; the week navigator lives in the hub toolbar), action banners and the project x day grid.
   $ws, $s (Timesheets::summary), $ts (row|null), $person, $own, $locked, $canReview */
use Modules\Attendance\Services\TimeService;
$we = date('Y-m-d', strtotime($ws.' +6 days'));
$thisWeek = TimeService::weekStart();
$hm = static fn (int $x): string => $x ? sprintf('%d:%02d', intdiv($x, 3600), intdiv($x % 3600, 60)) : '-';
$status = $ts['status'] ?? null;
$rejected = $status === 'rejected';
$first = e(explode(' ', $person['name'])[0]);
$pct = $s['total'] ? (int) round(100 * $s['billable'] / $s['total']) : 0;
$diff = $s['total'] - $s['expected'];
$range = fdate($ws, 'd M').' - '.fdate($we, 'd M Y');
?>
<section class="ta-stats ts-sum" aria-label="Week summary">
  <div class="ta-stat ts-wk"><span class="k">Week <?= (int) date('W', strtotime($ws)) ?> · <?= e($ws === $thisWeek ? 'This week' : ($ws === date('Y-m-d', strtotime($thisWeek.' -7 days')) ? 'Last week' : date('Y', strtotime($ws)))) ?></span><span class="v ts-r"><?= e($range) ?></span>
    <span class="ts-tags"><?php if (!$own): ?><span class="ts-person"><?= avatar($person['name'], $person['avatar'] ?? null, 'sm') ?><?= e($person['name']) ?></span><?php endif ?><?php if ($s['total'] || $ts): ?><?= $rejected ? '<span class="pill pill-red">'.icon('alert', 'sm').' Rejected</span>' : '<span class="pill pill-mint">'.icon('check-circle', 'sm').' Approved</span>' ?><?php endif ?><?php if ($s['running']): ?><span class="pill pill-sky" title="A timer is running in this week: its time is shown live"><span class="dotc dot-in pulse"></span> Live</span><?php endif ?></span></div>
  <div class="ta-stat c-lav"><span class="k"><i></i>Tracked</span><span class="v"><?= e(hours($s['total'])) ?></span><?php if ($s['expected']): ?><span class="ta-meter" aria-hidden="true"><i class="<?= $diff > 0 ? 'over' : '' ?>" style="width:<?= min(100, (int) round(100 * $s['total'] / $s['expected'])) ?>%"></i></span><?php endif ?><span class="s">of <?= e(hours($s['expected'])) ?> expected</span></div>
  <div class="ta-stat c-mint"><span class="k"><i></i>Billable</span><span class="v"><?= $pct ?>%</span><span class="s"><?= e(hours($s['billable'])) ?> billable</span></div>
  <div class="ta-stat <?= $diff > 0 ? 'c-peach' : 'c-lemon' ?><?= $diff < 0 && $ws < $thisWeek ? ' is-alert' : '' ?>"><span class="k"><i></i><?= $diff > 0 ? 'Overtime' : ($diff < 0 ? 'Below target' : 'On target') ?></span><span class="v"><?= $diff > 0 ? '+' : ($diff < 0 ? '-' : '') ?><?= e(hours(abs($diff))) ?></span><span class="s">against expected</span></div>
  <div class="ta-stat c-sky"><span class="k"><i></i>Days worked</span><span class="v"><?= (int) $s['days_worked'] ?><small>of <?= e(rtrim(rtrim(number_format($s['workdays'], 1), '0'), '.')) ?></small></span><span class="s">working days</span></div>
</section>

<?php if ($rejected): ?>
<div class="ts-banner bad">
  <span class="ic-wrap"><?= icon('alert', 'sm') ?></span>
  <div class="grow"><b>Rejected by <?= e($ts['reviewer'] ?? 'a reviewer') ?><?= $ts['reviewed_at'] ? ' on '.e(fdatetime($ts['reviewed_at'])) : '' ?></b>
    <?php if ($ts['review_note']): ?><div class="ts-note"><?= e($ts['review_note']) ?></div><?php endif ?>
    <p><?= $own ? 'Fix the entries below, then resubmit. Until then this week\'s hours are not counted as approved.' : 'Waiting for '.$first.' to fix and resubmit. You can also approve the week yourself.' ?></p></div>
  <?php if ($own && !$locked): ?><form method="post" action="<?= e(url('/timesheets/resubmit')) ?>" data-confirm="Resubmit this week? It will be approved again automatically."><?= csrf_field() ?><input type="hidden" name="week" value="<?= e($ws) ?>"><button class="btn primary"><?= icon('send', 'sm') ?> Resubmit</button></form><?php endif ?>
  <?php if ($canReview && !$locked): ?><form method="post" action="<?= e(url('/timesheets/'.$ts['id'].'/approve')) ?>"><?= csrf_field() ?><button class="btn mint"><?= icon('check', 'sm') ?> Approve</button></form><?php endif ?>
</div>
<?php elseif (($ts || $s['total']) && (!$own || ($ts && $ts['reviewed_by']))): ?>
<div class="ts-banner ok">
  <span class="ic-wrap"><?= icon('check-circle', 'sm') ?></span>
  <div class="grow"><?php if ($ts && $ts['reviewed_by']): ?><b>Approved by <?= e($ts['reviewer'] ?? 'a reviewer') ?> on <?= e(fdatetime($ts['reviewed_at'])) ?></b><p><?= $ts['review_note'] ? e($ts['review_note']) : 'Entries count toward payroll and billing.' ?></p>
    <?php else: ?><b>Approved automatically</b><p>Entries were approved as they were logged. Reject the week if something needs fixing.</p><?php endif ?></div>
  <?php if ($canReview && $ts && !$locked): ?><button type="button" class="btn danger" data-modal="reject-dialog" data-reject-url="<?= e(url('/timesheets/'.$ts['id'].'/reject')) ?>" data-reject-name="<?= e($person['name'].', '.$range) ?>"><?= icon('x', 'sm') ?> Reject week</button><?php endif ?>
</div>
<?php elseif ($ts || $s['total']): ?>
<?php else: ?>
<div class="ts-banner info"><span class="ic-wrap"><?= icon('clock', 'sm') ?></span><div class="grow"><b>Nothing tracked yet</b><p><?= $own ? 'Track your time with the Niyanta Tracker desktop app, or add time manually.' : $first.' has not logged time in this week.' ?></p></div></div>
<?php endif ?>
<?php if ($locked): ?><div class="ts-banner warn"><span class="ic-wrap"><?= icon('lock', 'sm') ?></span><div class="grow"><b>Payroll is finalized for this period</b><p>This week is read-only. Ask HR if something must change.</p></div></div><?php endif ?>

<section class="card ta-section">
  <div class="card-head"><h3>Week grid</h3><div class="legend"><span><i class="lg-bill"></i>Billable</span><span><i class="lg-non"></i>Non-billable</span></div></div>
  <?php if (!$s['rows']): ?><?= empty_state('Nothing tracked this week', $own ? 'Time you log appears here'.(feature_on('projects') ? ' grouped by project and task' : ' day by day').'.' : 'No time entries in this week.', 'calendar-person') ?><?php else: ?>
  <div class="ts-scroll"><table class="ts-grid">
    <thead><tr><th class="rh"><span class="xs muted"><?= feature_on('projects') ? 'Project / task' : 'Work' ?></span></th>
      <?php foreach ($s['days'] as $d => $_): ?><th class="<?= $s['off'][$d] ? 'off' : '' ?><?= $d === date('Y-m-d') ? ' today' : '' ?>"<?= $s['off'][$d] && $s['off'][$d] !== 'Off' ? ' title="'.e($s['off'][$d]).'"' : '' ?>><span class="dw"><?= e(date('D', strtotime($d))) ?></span><span class="dn"><?= e(date('j', strtotime($d))) ?></span></th><?php endforeach ?>
      <th class="tot"><span class="xs muted">Total</span></th></tr></thead>
    <tbody><?php foreach ($s['rows'] as $r): ?><tr class="<?= $r['billable'] ? 'bill' : 'non' ?>">
      <td class="rh"><div class="nm"><span class="ts-sw c-<?= e($r['color']) ?>"></span><div class="grow"><b class="truncate"><?= e($r['project']) ?></b><div class="sub"><?php if ($r['task']): ?><span class="truncate"><?= e(str_limit($r['task'], 40)) ?></span><?php endif ?><span class="mk <?= $r['billable'] ? 'bill' : 'non' ?>"><?= $r['billable'] ? 'Billable' : 'Non-billable' ?></span></div></div></div></td>
      <?php foreach ($s['days'] as $d => $_): $v = (int) ($r['days'][$d] ?? 0); ?><td class="<?= $s['off'][$d] ? 'off' : '' ?>"><?= $v ? '<span class="cell">'.e($hm($v)).'</span>' : '<span class="nil">-</span>' ?></td><?php endforeach ?>
      <td class="tot"><?= e($hm($r['total'])) ?></td>
    </tr><?php endforeach ?></tbody>
    <tfoot>
      <tr class="sum"><td class="rh">Daily total</td><?php foreach ($s['days'] as $d => $v): $v = (int) $v; $exp = $s['off'][$d] ? 0 : $s['per_day']; ?><td class="<?= $s['off'][$d] ? 'off' : '' ?>"><?= e($hm($v)) ?><?php if ($exp || $v): ?><span class="ts-dbar" title="<?= e(hours($v).' of '.hours($exp)) ?>"><i class="<?= $exp && $v > $exp ? 'over' : '' ?>" style="width:<?= $exp ? min(100, (int) round(100 * $v / $exp)) : 100 ?>%"></i></span><?php endif ?></td><?php endforeach ?><td class="tot"><?= e($hm($s['total'])) ?></td></tr>
      <tr class="sub"><td class="rh">Billable</td><?php foreach ($s['billable_days'] as $d => $v): ?><td class="<?= $s['off'][$d] ? 'off' : '' ?>"><?= e($hm((int) $v)) ?></td><?php endforeach ?><td class="tot"><?= e($hm($s['billable'])) ?></td></tr>
      <tr class="sub"><td class="rh">Attendance worked</td><?php foreach ($s['days'] as $d => $_): ?><td class="<?= $s['off'][$d] ? 'off' : '' ?>"><?= e($hm((int) ($s['attendance'][$d] ?? 0))) ?></td><?php endforeach ?><td class="tot"><?= e($hm((int) array_sum($s['attendance']))) ?></td></tr>
    </tfoot>
  </table></div>
  <?php endif ?>
</section>
