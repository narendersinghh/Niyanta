<?php
$tabs = ['all' => 'All weeks', 'approved' => 'Approved', 'rejected' => 'Rejected'];
$q = static fn (array $m): string => e(url('/timesheets/approvals?'.http_build_query(array_filter($m + ['status' => $status, 'week' => $week, 'user' => $person ?: null], static fn ($v) => $v !== null && $v !== '' && $v !== 'all'))));
$tot = (int) $stats['total']; $pct = $tot ? (int) round(100 * $stats['billable'] / $tot) : 0;
?>
<?= partial('attendance::partials/hub', ['tab' => 'review', 'title' => 'Time & Attendance', 'subtitle' => 'Team weeks are approved automatically. Reject a week to send it back with a note.']) ?>
<div class="grid g4 mb">
  <div class="tile bg-lav"><span class="tile-ic"><?= icon('clock') ?></span><div><div class="num"><?= e(rtrim(rtrim(number_format($tot / 3600, 1), '0'), '.')) ?><small class="tile-unit">h</small></div><div class="lbl">Hours logged</div></div></div>
  <div class="tile bg-mint"><span class="tile-ic"><?= icon('trend-up') ?></span><div><div class="num"><?= $pct ?><small class="tile-unit">%</small></div><div class="lbl">Billable</div></div></div>
  <div class="tile bg-peach"><span class="tile-ic"><?= icon('alert') ?></span><div><div class="num"><?= (int) $stats['rejected'] ?></div><div class="lbl">Rejected, awaiting fixes</div></div></div>
  <div class="tile bg-sky"><span class="tile-ic"><?= icon('users') ?></span><div><div class="num"><?= (int) $stats['people'] ?></div><div class="lbl"><?= (int) $stats['n'] ?> team week<?= (int) $stats['n'] === 1 ? '' : 's' ?></div></div></div>
</div>
<div class="card">
  <div class="ap-filters">
    <nav class="tabs"><?php foreach ($tabs as $k => $l): ?><a href="<?= $q(['status' => $k]) ?>" class="<?= $status === $k ? 'active' : '' ?>"><?= e($l) ?><?= $k === 'rejected' && (int) $stats['rejected'] ? ' ('.(int) $stats['rejected'].')' : '' ?></a><?php endforeach ?></nav>
    <form method="get" action="<?= e(url('/timesheets/approvals')) ?>"><?php if ($status !== 'all'): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif ?>
      <select name="week" data-autosubmit aria-label="Week"><option value="">All weeks</option><?php foreach ($weeks as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($k, $week) ?>><?= e($l) ?></option><?php endforeach ?></select>
      <select name="user" data-autosubmit aria-label="Person"><option value="">Everyone</option><?php foreach ($people as $id => $n): ?><option value="<?= (int) $id ?>"<?= selected($id, $person) ?>><?= e($n) ?></option><?php endforeach ?></select>
      <?php if ($week || $person): ?><a class="btn sm ghost" href="<?= $q(['week' => null, 'user' => null]) ?>"><?= icon('x', 'sm') ?> Clear</a><?php endif ?>
    </form>
  </div>
  <?php if (!$p['items']): ?><?= empty_state($status === 'rejected' ? 'No rejected weeks' : 'No team weeks here', $status === 'rejected' ? 'Every week in this view is approved.' : 'Weeks appear as your team logs time. Try another week or person.', $status === 'rejected' ? 'celebrate' : 'empty-box') ?><?php else: ?>
  <div class="table-wrap"><table class="table ap-table">
    <thead><tr><th>Person</th><th>Week</th><th class="right">Total</th><th>Billable</th><th>Status</th><th></th></tr></thead>
    <tbody><?php foreach ($p['items'] as $t): $we = date('Y-m-d', strtotime($t['week_start'].' +6 days')); $bp = $t['total_seconds'] ? round(100 * $t['billable_seconds'] / $t['total_seconds']) : 0; $range = fdate($t['week_start'], 'd M').' - '.fdate($we, 'd M'); $view = url('/timesheets/user/'.$t['user_id'].'?week='.$t['week_start']); ?><tr>
      <td class="ap-p"><div class="flex"><?= avatar($t['name'], $t['avatar'], 'sm') ?><div class="grow"><b class="truncate d-block"><?= e($t['name']) ?></b><div class="xs muted truncate"><?= e($t['designation'] ?: '-') ?></div></div></div></td>
      <td class="ap-week"><a href="<?= e($view) ?>"><b><?= e($range) ?></b></a><span class="xs muted">Week <?= (int) date('W', strtotime($t['week_start'])) ?></span></td>
      <td class="right ap-t"><b><?= e(hours($t['total_seconds'])) ?></b></td>
      <td class="ap-b"><div class="ap-bill"><span class="ts-split"><i style="width:<?= $bp ?>%"></i><i style="width:<?= 100 - $bp ?>%"></i></span><span class="xs muted"><?= (int) $bp ?>%</span></div></td>
      <td class="ap-s"><?= $t['status'] === 'rejected' ? badge('rejected', 'Rejected') : badge('approved', $t['reviewed_by'] ? 'Approved' : 'Auto-approved') ?><?php if ($t['reviewer']): ?><div class="xs muted">by <?= e($t['reviewer']) ?></div><?php endif ?></td>
      <td class="actions"><a class="btn icon sm ghost" href="<?= e($view) ?>" aria-label="View week" title="View week"><?= icon('eye', 'sm') ?></a>
        <?php if ($t['status'] === 'rejected'): ?><form method="post" action="<?= e(url('/timesheets/'.$t['id'].'/approve')) ?>"><?= csrf_field() ?><button class="btn sm mint"><?= icon('check', 'sm') ?> Approve</button></form>
        <?php else: ?><button type="button" class="btn sm danger" data-modal="reject-dialog" data-reject-url="<?= e(url('/timesheets/'.$t['id'].'/reject')) ?>" data-reject-name="<?= e($t['name'].', '.$range) ?>"><?= icon('x', 'sm') ?> Reject</button><?php endif ?></td>
    </tr><?php endforeach ?></tbody>
  </table></div>
  <?= pager($p) ?>
  <?php endif ?>
</div>
<?= partial('attendance::partials/reject_dialog') ?>
