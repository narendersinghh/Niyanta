<?php
/* Timesheet week: one summary row (range, rejected time, tracked vs expected, billable, overtime, days; the week navigator lives in the hub toolbar), banners and the project x day grid.
   Time is approved as it is logged; reviewers reject entries or days (EntryReview), never the week. Rejected time stays in the totals and is marked red.
   $ws, $s (Timesheets::summary), $person, $own, $locked, $canReview */
use Modules\Attendance\Services\TimeService;
$we = date('Y-m-d', strtotime($ws.' +6 days'));
$thisWeek = TimeService::weekStart();
$hm = static fn (int $x): string => $x ? sprintf('%d:%02d', intdiv($x, 3600), intdiv($x % 3600, 60)) : '-';
$rejected = (int) $s['rejected'];
$first = e(explode(' ', $person['name'])[0]);
$pct = $s['total'] ? (int) round(100 * $s['billable'] / $s['total']) : 0;
$diff = $s['total'] - $s['expected'];
$range = fdate($ws, 'd M').' - '.fdate($we, 'd M Y');
?>
<section class="ta-stats ts-sum" aria-label="Week summary">
  <div class="ta-stat ts-wk"><span class="k">Week <?= (int) date('W', strtotime($ws)) ?> · <?= e($ws === $thisWeek ? 'This week' : ($ws === date('Y-m-d', strtotime($thisWeek.' -7 days')) ? 'Last week' : date('Y', strtotime($ws)))) ?></span><span class="v ts-r"><?= e($range) ?></span>
    <span class="ts-tags"><?php if (!$own): ?><span class="ts-person"><?= avatar($person['name'], $person['avatar'] ?? null, 'sm') ?><?= e($person['name']) ?></span><?php endif ?><?php if ($s['total']): ?><?= $rejected ? '<span class="pill pill-red" title="Rejected time is not counted as approved">'.icon('alert', 'sm').' '.e(hours($rejected)).' rejected</span>' : '<span class="pill pill-mint">'.icon('check-circle', 'sm').' Approved</span>' ?><?php endif ?><?php if ($s['running']): ?><span class="pill pill-sky" title="A timer is running in this week: its time is shown live"><span class="dotc dot-in pulse"></span> Live</span><?php endif ?></span></div>
  <div class="ta-stat c-lav"><span class="k"><i></i><?= $s['added'] ? 'Logged' : 'Tracked' ?></span><span class="v"><?= e(hours($s['total'])) ?></span><?php if ($s['expected']): ?><span class="ta-meter" aria-hidden="true"><i class="<?= $diff > 0 ? 'over' : '' ?>" style="width:<?= min(100, (int) round(100 * $s['total'] / $s['expected'])) ?>%"></i></span><?php endif ?><span class="s">of <?= e(hours($s['expected'])) ?> expected</span><?php if ($s['added']): ?><span class="s ts-add">incl. <?= e(hours($s['added'])) ?> added</span><?php endif ?><?php if ($rejected): ?><span class="s ts-rej">incl. <?= e(hours($rejected)) ?> rejected</span><?php endif ?></div>
  <div class="ta-stat c-mint"><span class="k"><i></i>Billable</span><span class="v"><?= $pct ?>%</span><span class="s"><?= e(hours($s['billable'])) ?> billable</span></div>
  <div class="ta-stat <?= $diff > 0 ? 'c-peach' : 'c-lemon' ?><?= $diff < 0 && $ws < $thisWeek ? ' is-alert' : '' ?>"><span class="k"><i></i><?= $diff > 0 ? 'Overtime' : ($diff < 0 ? 'Below target' : 'On target') ?></span><span class="v"><?= $diff > 0 ? '+' : ($diff < 0 ? '-' : '') ?><?= e(hours(abs($diff))) ?></span><span class="s">against expected</span></div>
  <div class="ta-stat c-sky"><span class="k"><i></i>Days worked</span><span class="v"><?= (int) $s['days_worked'] ?><small>of <?= e(rtrim(rtrim(number_format($s['workdays'], 1), '0'), '.')) ?></small></span><span class="s">working days</span></div>
</section>

<?php if ($rejected): ?>
<div class="ts-banner bad">
  <span class="ic-wrap"><?= icon('alert', 'sm') ?></span>
  <div class="grow"><b><?= e(hours($rejected)) ?> of this week is rejected</b>
    <p><?= $own ? 'Rejected entries are marked in red below with the reason. Fix them and save: they are approved again automatically. Until then they do not count as approved.' : 'Waiting for '.$first.' to fix the entries marked in red.'.($canReview && !$locked ? ' Click an entry to approve it again, or use Approve day.' : '') ?></p></div>
</div>
<?php elseif (!$s['total'] && !$s['rows']): ?>
<div class="ts-banner info"><span class="ic-wrap"><?= icon('clock', 'sm') ?></span><div class="grow"><b>Nothing tracked yet</b><p><?= $own ? 'Track your time with the Niyanta Tracker desktop app, or add time manually.' : $first.' has not logged time in this week.' ?></p></div></div>
<?php endif ?>
<?php if ($locked): ?><div class="ts-banner warn"><span class="ic-wrap"><?= icon('lock', 'sm') ?></span><div class="grow"><b>Payroll is finalized for this period</b><p>This week is read-only. Ask HR if something must change.</p></div></div><?php endif ?>

<section class="card ta-section">
  <div class="card-head"><h3>Week grid</h3><div class="legend"><span><i class="lg-bill"></i>Billable</span><span><i class="lg-non"></i>Non-billable</span><?php if ($s['added']): ?><span><i class="lg-add"></i>Imported or manually added</span><?php endif ?><?php if ($rejected): ?><span><i class="lg-rej"></i>Rejected</span><?php endif ?></div></div>
  <?php if (!$s['rows']): ?><?= empty_state('Nothing tracked this week', $own ? 'Time you log appears here'.(feature_on('projects') ? ' grouped by project and task' : ' day by day').'.' : 'No time entries in this week.', 'calendar-person') ?><?php else: ?>
  <div class="ts-scroll"><table class="ts-grid">
    <thead><tr><th class="rh"><span class="xs muted"><?= feature_on('projects') ? 'Project / task' : 'Work' ?></span></th>
      <?php foreach ($s['days'] as $d => $_): ?><th class="<?= $s['off'][$d] ? 'off' : '' ?><?= $d === date('Y-m-d') ? ' today' : '' ?>"<?= $s['off'][$d] && $s['off'][$d] !== 'Off' ? ' title="'.e($s['off'][$d]).'"' : '' ?>><span class="dw"><?= e(date('D', strtotime($d))) ?></span><span class="dn"><?= e(date('j', strtotime($d))) ?></span></th><?php endforeach ?>
      <th class="tot"><span class="xs muted">Total</span></th></tr></thead>
    <tbody><?php foreach ($s['rows'] as $r): ?><tr class="<?= $r['billable'] ? 'bill' : 'non' ?><?= !empty($r['added']) ? ' added' : '' ?>">
      <td class="rh"><div class="nm"><span class="ts-sw c-<?= e($r['color']) ?>"></span><div class="grow"><b class="truncate"><?= e($r['project']) ?></b><div class="sub"><?php if ($r['task']): ?><span class="truncate"><?= e(str_limit($r['task'], 40)) ?></span><?php endif ?><span class="mk <?= $r['billable'] ? 'bill' : 'non' ?>"><?= $r['billable'] ? 'Billable' : 'Non-billable' ?></span><?php if (!empty($r['added'])): ?><span class="mk added" title="<?= !empty($r['record']) ? 'From an attendance record HR added or imported, beyond the tracked time' : 'Logged with the manual time form, not by the tracker' ?>">Imported or manually added</span><?php endif ?></div></div></div></td>
      <?php foreach ($s['days'] as $d => $_): $v = (int) ($r['days'][$d] ?? 0); $rj = (int) ($r['rej'][$d] ?? 0); ?><td class="<?= $s['off'][$d] ? 'off' : '' ?>"><?= $v ? '<span class="cell'.($rj ? ' rej" title="'.e(hours($rj).' rejected, not approved') : '').'">'.e($hm($v)).'</span>' : '<span class="nil">-</span>' ?></td><?php endforeach ?>
      <td class="tot"><?= e($hm($r['total'])) ?></td>
    </tr><?php endforeach ?></tbody>
    <tfoot>
      <tr class="sum"><td class="rh">Daily total</td><?php foreach ($s['days'] as $d => $v): $v = (int) $v; $exp = $s['off'][$d] ? 0 : $s['per_day']; ?><td class="<?= $s['off'][$d] ? 'off' : '' ?>"><?= e($hm($v)) ?><?php if ($exp || $v): ?><span class="ts-dbar" title="<?= e(hours($v).' of '.hours($exp)) ?>"><i class="<?= $exp && $v > $exp ? 'over' : '' ?>" style="width:<?= $exp ? min(100, (int) round(100 * $v / $exp)) : 100 ?>%"></i></span><?php endif ?></td><?php endforeach ?><td class="tot"><?= e($hm($s['total'])) ?></td></tr>
      <?php if ($rejected): ?><tr class="sub rej"><td class="rh">Rejected</td><?php foreach ($s['rejected_days'] as $d => $v): ?><td class="<?= $s['off'][$d] ? 'off' : '' ?>"><?= e($hm((int) $v)) ?></td><?php endforeach ?><td class="tot"><?= e($hm($rejected)) ?></td></tr>
      <tr class="sub"><td class="rh">Approved</td><?php foreach ($s['days'] as $d => $v): ?><td class="<?= $s['off'][$d] ? 'off' : '' ?>"><?= e($hm(max(0, (int) $v - (int) $s['rejected_days'][$d]))) ?></td><?php endforeach ?><td class="tot"><?= e($hm(max(0, $s['total'] - $rejected))) ?></td></tr><?php endif ?>
      <tr class="sub"><td class="rh">Billable</td><?php foreach ($s['billable_days'] as $d => $v): ?><td class="<?= $s['off'][$d] ? 'off' : '' ?>"><?= e($hm((int) $v)) ?></td><?php endforeach ?><td class="tot"><?= e($hm($s['billable'])) ?></td></tr>
      <tr class="sub"><td class="rh">Attendance worked</td><?php foreach ($s['days'] as $d => $_): ?><td class="<?= $s['off'][$d] ? 'off' : '' ?>"><?= e($hm((int) ($s['attendance'][$d] ?? 0))) ?></td><?php endforeach ?><td class="tot"><?= e($hm((int) array_sum($s['attendance']))) ?></td></tr>
    </tfoot>
  </table></div>
  <?php endif ?>
</section>
