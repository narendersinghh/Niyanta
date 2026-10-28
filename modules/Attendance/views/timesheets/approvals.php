<?php $tabs = ['submitted' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'all' => 'All']; ?>
<?= partial('partials/page_head', ['title' => 'Timesheet approvals', 'subtitle' => 'Weekly timesheets from your team awaiting review.', 'crumbs' => [['Work'], ['Timesheets', '/timesheets'], ['Approvals']], 'actions' => '<a class="btn ghost" href="'.e(url('/timesheets')).'">'.icon('calendar', 'sm').' My timesheet</a>']) ?>
<div class="card">
  <div class="filters between">
    <nav class="tabs"><?php foreach ($tabs as $k => $l): ?><a href="<?= e(url('/timesheets/approvals?status='.$k)) ?>" class="<?= $status === $k ? 'active' : '' ?>"><?= e($l) ?><?= $k === 'submitted' && $pending ? ' ('.(int) $pending.')' : '' ?></a><?php endforeach ?></nav>
    <form method="get" class="flex"><input type="hidden" name="status" value="<?= e($status) ?>"><input type="search" name="q" value="<?= e($_GET['q'] ?? '') ?>" placeholder="Search people"></form>
  </div>
  <?php if (!$p['items']): ?><?= empty_state($status === 'submitted' ? 'All caught up!' : 'No timesheets here', $status === 'submitted' ? 'There are no timesheets waiting for your approval.' : 'Try another filter.', $status === 'submitted' ? 'celebrate' : 'empty-box') ?><?php else: ?>
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Person</th><th>Week</th><th>Total</th><th>Billable</th><th>Submitted</th><th>Status</th><th></th></tr></thead>
    <tbody><?php foreach ($p['items'] as $t): ?><tr>
      <td><div class="flex"><?= avatar($t['name'], $t['avatar'], 'sm') ?><div><b><?= e($t['name']) ?></b><div class="xs muted"><?= e($t['designation'] ?? '') ?></div></div></div></td>
      <td><a class="row-link" href="<?= e(url('/timesheets/'.$t['id'])) ?>"><?= e(fdate($t['week_start'], 'd M')) ?> – <?= e(fdate(date('Y-m-d', strtotime($t['week_start'].' +6 days')), 'd M')) ?></a></td>
      <td><b><?= e(hours($t['total_seconds'])) ?></b></td><td class="muted"><?= e(hours($t['billable_seconds'])) ?></td>
      <td class="small muted"><?= e($t['submitted_at'] ? ago($t['submitted_at']) : '—') ?></td>
      <td><?= badge($t['status']) ?><?php if ($t['reviewer'] && $t['status'] !== 'submitted'): ?><div class="xs muted">by <?= e($t['reviewer']) ?></div><?php endif ?></td>
      <td class="actions"><a class="btn sm ghost" href="<?= e(url('/timesheets/'.$t['id'])) ?>"><?= icon('eye', 'sm') ?> Review</a></td>
    </tr><?php endforeach ?></tbody>
  </table></div>
  <?= pager($p) ?>
  <?php endif ?>
</div>
