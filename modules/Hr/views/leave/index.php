<?php use Modules\Hr\Services\LeaveService as LS;
$ohId = (int) ($paidType['id'] ?? $optional['id'] ?? 0);
$defaultType = old('leave_type_id', $_GET['type'] ?? ($ohId ?: ''));
$statuses = ['' => 'All', 'pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled'];
$cur = $_GET['status'] ?? '';
$extra = '<div class="tile bg-lemon"><span class="tile-ic">'.icon('clock').'</span><div><div class="num">'.(int) ($stats['pending'] ?? 0).'</div><div class="lbl">Pending requests</div><div class="hr-tsub">'.($manager ? 'Approver: '.e($manager) : 'Reviewed by HR').'</div></div></div>'
  .'<div class="tile bg-mint"><span class="tile-ic">'.icon('plane').'</span><div><div class="num">'.e(LS::num($stats['taken'] ?? 0)).'</div><div class="lbl">Days off taken in '.(int) $year.'</div><div class="hr-tsub">'.($nextLeave ? 'Next: '.e(fdate($nextLeave['start_date'], 'd M')).' · '.e($nextLeave['type_name']) : 'No upcoming leave booked').'</div></div></div>';
?>
<?= partial('partials/page_head', ['title' => 'Leave', 'subtitle' => 'Plan time off, track your balance and follow approvals.', 'crumbs' => [['My HR'], ['Leave']], 'actions' => '<a class="btn ghost" href="'.e(url('/hr/leave/ledger')).'">'.icon('list', 'sm').' My ledger</a><button type="button" class="btn primary" data-modal="leave-req">'.icon('plus', 'sm').' Request leave</button>']) ?>
<?= partial('hr::leave/_tabs', ['tab' => $tab, 'pendingCount' => $pendingCount]) ?>
<?= partial('hr::leave/_balances', ['balances' => $balances, 'year' => $year, 'extra' => $extra]) ?>
<div class="grid g-side hr-top">
  <div class="card">
    <div class="card-head"><h3>My requests</h3><span class="small muted"><?= (int) $p['total'] ?> total</span></div>
    <div class="hr-toolbar"><div class="chips" role="tablist" aria-label="Filter by status"><?php foreach ($statuses as $k => $l): ?><a class="chip<?= $cur === $k ? ' on' : '' ?>" href="<?= e(url('/hr/leave').query_merge(['status' => $k, 'page' => 1])) ?>"><?= e($l) ?></a><?php endforeach ?></div></div>
    <?php if (!$p['items']): ?><?= empty_state($cur ? 'No '.$cur.' requests' : 'No leave requests yet', $cur ? 'Try another status filter.' : 'Plan a break, use optional days for festivals, personal errands or a sick day.', 'leave-beach', $cur ? '' : '<button type="button" class="btn primary" data-modal="leave-req">'.icon('plus', 'sm').' Request leave</button>') ?><?php else: ?>
    <div class="table-wrap"><table class="table">
      <thead><tr><th>Type</th><th>Dates</th><th class="num">Days</th><th>Status</th><th>Review</th><th><span class="sr">Actions</span></th></tr></thead>
      <tbody><?php foreach ($p['items'] as $r): ?><tr>
        <td><span class="pill pill-<?= e($r['color']) ?>"><?= e($r['type_name']) ?></span><?php if ($r['reason']): ?><div class="xs muted mt hr-reason"><?= e(str_limit($r['reason'], 60)) ?></div><?php endif ?></td>
        <td class="nowrap"><b class="small"><?= fdate($r['start_date'], 'd M Y') ?><?= $r['end_date'] !== $r['start_date'] ? ' – '.fdate($r['end_date'], 'd M Y') : '' ?></b><?= $r['half_day'] ? ' <span class="pill pill-gray">½ day</span>' : '' ?><div class="xs muted">Requested <?= e(ago($r['created_at'])) ?></div></td>
        <td class="num b"><?= e(LS::num($r['days'])) ?></td>
        <td><?= badge($r['status']) ?></td>
        <td class="small"><?php if ($r['reviewer']): ?><?= e($r['reviewer']) ?><div class="xs muted"><?= e(str_limit($r['review_note'] ?: fdate($r['reviewed_at']), 50)) ?></div><?php elseif ($r['status'] === 'pending'): ?><span class="muted">Awaiting <?= e($manager ?? 'HR') ?></span><?php else: ?><span class="muted"><?= e($r['review_note'] ?? '-') ?></span><?php endif ?></td>
        <td class="actions"><?php if ($r['status'] === 'pending'): ?><form method="post" action="<?= e(url('/hr/leave/'.$r['id'].'/cancel')) ?>" data-confirm="Cancel this leave request?"><?= csrf_field() ?><button class="btn sm ghost"><?= icon('x', 'sm') ?> Cancel</button></form><?php endif ?></td>
      </tr><?php endforeach ?></tbody>
    </table></div>
    <?= pager($p) ?>
    <?php endif ?>
  </div>
  <div class="stack">
    <?php if ($paidType): ?>
    <div class="card">
      <div class="hr-side-head"><span class="hr-ic mint"><?= icon('umbrella') ?></span><div><h4 class="mb0">How <?= e($paidType['name']) ?> works</h4><div class="xs muted">Builds up every month</div></div></div>
      <p class="small mb0">Fixed holidays are off for everyone. You also get <b><?= e(LS::num($paidType['monthly_accrual'] ?: $paidType['annual_quota'])) ?> day(s) of <?= e($paidType['name']) ?></b> <?= $paidType['allowance'] === 'monthly' ? 'every month' : 'a year' ?> for any festival (Eid, Christmas, New Year...), personal work or a medical emergency.<?= $shortageOn ? ' If you work fewer hours than expected in a month, the shortage is taken from this balance first so your pay is not cut.' : '' ?></p>
      <div class="flex wrap mt"><a class="btn sm soft" href="<?= e(url('/hr/leave/ledger')) ?>"><?= icon('list', 'sm') ?> See my ledger</a></div>
    </div>
    <?php endif ?>
    <?php if ($optional): ?>
    <div class="card">
      <div class="hr-side-head"><span class="hr-ic sky"><?= icon('gift') ?></span><div><h4 class="mb0">How optional days work</h4><div class="xs muted">A pool for festival days</div></div></div>
      <p class="small mb0">On top of fixed holidays you get <b><?= e(LS::num($optional['annual_quota'] ?? 0)) ?> optional days</b> a year, use them for festivals, personal errands or medical emergencies.</p>
      <div class="flex wrap mt"><a class="btn sm soft" href="<?= e(url('/hr/holidays')) ?>"><?= icon('calendar', 'sm') ?> See festival days</a></div>
    </div>
    <?php endif ?>
    <div class="card">
      <div class="hr-side-head"><span class="hr-ic mint"><?= icon('clock') ?></span><div><h4 class="mb0">My work week</h4><div class="xs muted"><?= e(LS::num($schedule['hours_per_day'])) ?> h per day<?= $schedule['custom_hours'] || $schedule['custom_days'] ? ' · personal schedule' : ' · company default' ?></div></div></div>
      <div class="hr-days ro"><?php foreach (LS::DAY_NAMES as $n => $dn): $off = in_array($n, $schedule['off_days'], true); ?><span class="<?= $off ? 'on' : 'wk' ?>" title="<?= $off ? 'Weekly off' : 'Working day' ?>"><?= e($dn) ?></span><?php endforeach ?></div>
      <p class="xs muted mb0 mt">Weekly off days and fixed holidays are never deducted from your balance.</p>
    </div>
    <?php if ($upcoming): ?>
    <div class="card">
      <div class="card-head"><h4>Upcoming holidays</h4><a class="xs muted" href="<?= e(url('/hr/holidays')) ?>">View all <?= icon('arrow-right', 'sm') ?></a></div>
      <div class="list"><?php foreach ($upcoming as $h): ?><div class="list-item"><span class="hr-date<?= $h['optional'] ? ' opt' : '' ?>"><b><?= date('d', strtotime($h['holiday_date'])) ?></b><small><?= date('M', strtotime($h['holiday_date'])) ?></small></span><div class="grow"><b class="small"><?= e($h['name']) ?></b><div class="meta"><?= date('l', strtotime($h['holiday_date'])) ?></div></div><?= $h['optional'] ? '<span class="pill pill-sky">Optional</span>' : '<span class="pill pill-peach">Fixed</span>' ?></div><?php endforeach ?></div>
    </div>
    <?php endif ?>
  </div>
</div>
<dialog class="modal" id="leave-req"<?= errors() ? ' data-open' : '' ?> aria-labelledby="leave-req-t">
  <div class="modal-head"><h3 id="leave-req-t">Request leave</h3><button type="button" class="btn icon sm ghost" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div>
  <form class="modal-body form" method="post" action="<?= e(url('/hr/leave')) ?>" data-leave-form data-preview="hr/leave/preview"><?= csrf_field() ?>
    <div class="hr-optbox"><?= icon('info') ?><p>Use <b><?= e($paidType['name'] ?? 'optional days') ?></b> for festivals, personal errands or medical emergencies. Your approver: <b><?= e($manager ?? 'HR team') ?></b>.</p></div>
    <div class="field"><label for="lr-type">Leave type *</label><select id="lr-type" name="leave_type_id" required><option value="">Choose…</option><?php foreach ($balances as $b): ?><option value="<?= (int) $b['id'] ?>"<?= selected($b['id'], $defaultType) ?>><?= e($b['name']) ?><?= (int) $b['paid'] ? ', '.($b['allocated_row'] ? e(LS::num(max(0, (float) $b['available']))).' days left' : 'no balance yet') : ', unpaid' ?><?= (int) $b['allow_half_day'] ? '' : ', full days only' ?></option><?php endforeach ?></select><?= error_for('leave_type_id') ?></div>
    <div class="form-grid">
      <div class="field"><label for="lr-from">From *</label><input id="lr-from" type="date" name="start_date" value="<?= e(old('start_date', $_GET['date'] ?? '')) ?>" required><?= error_for('start_date') ?></div>
      <div class="field"><label for="lr-to">To</label><input id="lr-to" type="date" name="end_date" value="<?= e(old('end_date', $_GET['date'] ?? '')) ?>"><div class="hint">Leave empty for a single day.</div><?= error_for('end_date') ?></div>
    </div>
    <label class="check"><input type="checkbox" name="half_day" value="1"<?= checked(old('half_day')) ?>> Half day (single date)</label><?= error_for('half_day') ?>
    <div class="hr-preview" data-leave-preview hidden aria-live="polite"></div>
    <div class="field"><label for="lr-reason">Reason</label><textarea id="lr-reason" name="reason" rows="3" maxlength="1000" placeholder="Optional note for your approver, e.g. Ganesh Chaturthi with family"><?= e(old('reason')) ?></textarea><?= error_for('reason') ?></div>
    <div class="form-actions"><button type="button" class="btn ghost" data-close>Cancel</button><button class="btn primary"><?= icon('send', 'sm') ?> Submit request</button></div>
  </form>
</dialog>
