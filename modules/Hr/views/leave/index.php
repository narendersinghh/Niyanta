<?= partial('partials/page_head', ['title' => 'Leave', 'subtitle' => 'Plan time off, track your balance and follow approvals.', 'crumbs' => [['People'], ['Leave']]]) ?>
<?= partial('hr::leave/_tabs', ['tab' => $tab, 'pendingCount' => $pendingCount]) ?>
<?= partial('hr::leave/_balances', ['balances' => $balances, 'year' => $year]) ?>
<div class="grid g-side">
  <div class="card">
    <div class="card-head"><h3>My requests</h3>
      <form method="get" class="hr-inline"><select name="status" data-autosubmit><option value="">All statuses</option><?php foreach (['pending', 'approved', 'rejected', 'cancelled'] as $s): ?><option value="<?= $s ?>"<?= selected($s, $_GET['status'] ?? '') ?>><?= ucfirst($s) ?></option><?php endforeach ?></select></form>
    </div>
    <?php if (!$p['items']): ?><?= empty_state('No leave requests yet', 'Plan a break — your future self will thank you.', 'leave-beach') ?><?php else: ?>
    <div class="table-wrap"><table class="table">
      <thead><tr><th>Type</th><th>Dates</th><th class="right">Days</th><th>Status</th><th>Review</th><th></th></tr></thead>
      <tbody><?php foreach ($p['items'] as $r): ?><tr>
        <td><span class="pill pill-<?= e($r['color']) ?>"><?= e($r['type_name']) ?></span><?php if ($r['reason']): ?><div class="xs muted mt hr-reason"><?= e(str_limit($r['reason'], 50)) ?></div><?php endif ?></td>
        <td class="small nowrap"><?= fdate($r['start_date']) ?><?= $r['end_date'] !== $r['start_date'] ? ' – '.fdate($r['end_date']) : '' ?><?= $r['half_day'] ? ' <span class="pill pill-gray">½</span>' : '' ?><div class="xs muted">Requested <?= e(ago($r['created_at'])) ?></div></td>
        <td class="right b"><?= e((float) $r['days']) ?></td>
        <td><?= badge($r['status']) ?></td>
        <td class="small"><?php if ($r['reviewer']): ?><?= e($r['reviewer']) ?><div class="xs muted"><?= e(str_limit($r['review_note'] ?: fdate($r['reviewed_at']), 50)) ?></div><?php elseif ($r['status'] === 'pending'): ?><span class="muted">Awaiting <?= e($manager ?? 'HR') ?></span><?php else: ?><span class="muted"><?= e($r['review_note'] ?? '—') ?></span><?php endif ?></td>
        <td class="actions"><?php if ($r['status'] === 'pending'): ?><form method="post" action="<?= e(url('/hr/leave/'.$r['id'].'/cancel')) ?>" data-confirm="Cancel this leave request?"><?= csrf_field() ?><button class="btn sm ghost"><?= icon('x', 'sm') ?> Cancel</button></form><?php endif ?></td>
      </tr><?php endforeach ?></tbody>
    </table></div>
    <?= pager($p) ?>
    <?php endif ?>
  </div>
  <div class="stack">
    <form class="card form" method="post" action="<?= e(url('/hr/leave')) ?>" id="request" data-leave-form data-preview="hr/leave/preview"><?= csrf_field() ?>
      <div class="hr-req-head"><div><h3 class="mb0">Request leave</h3><p class="xs muted mb0">Approver: <?= e($manager ?? 'HR team') ?></p></div><?= illus('calendar-person') ?></div>
      <div class="field"><label>Leave type *</label><select name="leave_type_id" required><option value="">Choose…</option><?php foreach ($balances as $b): ?><option value="<?= (int) $b['id'] ?>"<?= selected($b['id'], old('leave_type_id')) ?>><?= e($b['name']) ?><?= (int) $b['paid'] ? ' · '.($b['allocated_row'] ? e((float) $b['available']).' left' : 'not allocated') : ' · unpaid' ?></option><?php endforeach ?></select><?= error_for('leave_type_id') ?></div>
      <div class="form-grid">
        <div class="field"><label>From *</label><input type="date" name="start_date" value="<?= e(old('start_date')) ?>" required><?= error_for('start_date') ?></div>
        <div class="field"><label>To</label><input type="date" name="end_date" value="<?= e(old('end_date')) ?>"><?= error_for('end_date') ?></div>
      </div>
      <label class="check"><input type="checkbox" name="half_day" value="1"<?= checked(old('half_day')) ?>> Half day (single date)</label><?= error_for('half_day') ?>
      <div class="hr-preview" data-leave-preview hidden></div>
      <div class="field"><label>Reason</label><textarea name="reason" rows="3" maxlength="1000" placeholder="Optional note for your approver"><?= e(old('reason')) ?></textarea><?= error_for('reason') ?></div>
      <button class="btn primary"><?= icon('send', 'sm') ?> Submit request</button>
      <p class="xs muted mb0">Weekends and public holidays are not counted.</p>
    </form>
    <?php if ($upcoming): ?>
    <div class="card"><div class="card-head"><h4>Upcoming holidays</h4><a class="xs muted" href="<?= e(url('/hr/holidays')) ?>">All</a></div>
      <div class="list"><?php foreach ($upcoming as $h): ?><div class="list-item"><span class="hr-date soft-peach"><b><?= date('d', strtotime($h['holiday_date'])) ?></b><small><?= date('M', strtotime($h['holiday_date'])) ?></small></span><div class="grow"><b class="small"><?= e($h['name']) ?></b><div class="meta"><?= date('l', strtotime($h['holiday_date'])) ?><?= $h['optional'] ? ' · optional' : '' ?></div></div></div><?php endforeach ?></div>
    </div>
    <?php endif ?>
  </div>
</div>
