<?php use Modules\Hr\Services\{LeaveChanges as LC, LeaveService as LS};
$chips = ['pending' => 'Waiting for review', 'cancel' => 'Cancel requests', 'approved' => 'Approved', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled', 'all' => 'All'];
$cancelReq = (int) ($stats['cancel_req'] ?? 0);
$form = old('lv_form'); $errs = errors();
$exportQ = http_build_query(array_filter(['status' => $f['status'], 'q' => $f['q'], 'user' => $f['user'] ?: null, 'type' => $f['type'] ?: null, 'from' => $f['from'], 'to' => $f['to']]));
$filtered = $f['q'] !== '' || $f['user'] || $f['type'] || $f['from'] !== '' || $f['to'] !== '';
$typeOpts = static function ($sel) use ($types): string { $o = ''; foreach ($types as $t) { $o .= '<option value="'.(int) $t['id'].'"'.selected($t['id'], $sel).' data-half="'.(int) $t['allow_half_day'].'">'.e($t['name']).((int) $t['paid'] ? '' : ', unpaid').'</option>'; } return $o; };
$actions = '<a class="btn ghost" href="'.e(url('/hr/leave/calendar')).'">'.icon('calendar', 'sm').' Who is out</a><a class="btn ghost" href="'.e(url('/hr/leave/approvals/export').'?'.$exportQ).'">'.icon('download', 'sm').' Export CSV</a>'.($people ? '<button type="button" class="btn primary" data-modal="lv-behalf">'.icon('plus', 'sm').' Apply on behalf</button>' : '');
/* balance of the request's type and year: [text, warn] */
$balLine = static function (array $r) use ($bal): array {
  if (!(int) $r['paid']) { return ['Unpaid, no balance needed', false]; }
  $b = $bal[$r['user_id'].'-'.$r['leave_type_id'].'-'.substr($r['start_date'], 0, 4)] ?? null;
  if (!$b) { return ['No '.$r['type_name'].' balance allocated', $r['status'] === 'pending']; }
  if ($r['status'] !== 'pending') { return [LS::num($b['left']).' '.$r['type_name'].' left', false]; }
  $after = $b['left'] - (float) $r['days']; $more = $b['pending'] - (float) $r['days'];
  return [LS::num($b['left']).' left, '.($after < 0 ? 'not enough' : LS::num($after).' after approval').($more > 0 ? ' ('.LS::num($more).' more pending)' : ''), $after < 0]; };
$pendingRows = array_values(array_filter($p['items'], static fn ($r) => $r['status'] === 'pending'));
?>
<?= partial('partials/page_head', ['title' => 'Leave approvals', 'subtitle' => $all ? 'Everyone\'s leave requests: approve or reject with a note, correct, revoke and book leave.' : 'Leave requests of your direct reports: approve or reject with a note, correct, revoke and book leave.', 'crumbs' => [['HR Admin'], ['Leave approvals']], 'actions' => $actions]) ?>
<?= partial('hr::leave/_tabs', ['tab' => $tab, 'pendingCount' => $pendingCount]) ?>
<div class="grid g4 mb">
  <a class="tile bg-lemon lv-tile" href="<?= e(url('/hr/leave/approvals?status=pending')) ?>"><span class="tile-ic"><?= icon('clock') ?></span><div><div class="num"><?= (int) $pendingCount ?></div><div class="lbl">Waiting for review</div></div></a>
  <a class="tile bg-peach lv-tile" href="<?= e(url('/hr/leave/approvals?status=cancel')) ?>"><span class="tile-ic"><?= icon('x') ?></span><div><div class="num"><?= $cancelReq ?></div><div class="lbl">Cancellation requests</div></div></a>
  <a class="tile bg-mint lv-tile" href="<?= e(url('/hr/leave/approvals?status=approved')) ?>"><span class="tile-ic"><?= icon('check-circle') ?></span><div><div class="num"><?= (int) ($stats['approved'] ?? 0) ?></div><div class="lbl">Approved this month</div><div class="hr-tsub"><?= (int) ($stats['rejected'] ?? 0) ?> rejected</div></div></a>
  <a class="tile bg-sky lv-tile" href="<?= e(url('/hr/leave/calendar')) ?>"><span class="tile-ic"><?= icon('plane') ?></span><div><div class="num"><?= (int) ($stats['out_today'] ?? 0) ?></div><div class="lbl">On leave today</div><div class="hr-tsub">See who is out</div></div></a>
</div>
<div class="card">
  <div class="hr-toolbar"><div class="chips lv-chips"><?php foreach ($chips as $k => $l): ?><a class="chip<?= $status === $k ? ' on' : '' ?>" href="<?= e(url('/hr/leave/approvals').query_merge(['status' => $k, 'page' => 1])) ?>"<?= $status === $k ? ' aria-current="page"' : '' ?>><?= e($l) ?><?= $k === 'pending' && $pendingCount ? ' <span class="n">'.(int) $pendingCount.'</span>' : '' ?><?= $k === 'cancel' && $cancelReq ? ' <span class="n">'.$cancelReq.'</span>' : '' ?></a><?php endforeach ?></div></div>
  <form class="lv-filters" method="get" aria-label="Filter requests"><input type="hidden" name="status" value="<?= e($status) ?>">
    <label class="hr-search"><span class="sr">Search employee</span><?= icon('search', 'sm') ?><input type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Name or code"></label>
    <select name="user" aria-label="Employee" data-autosubmit><option value="">Everyone</option><?php foreach ($people as $pid => $pn): ?><option value="<?= (int) $pid ?>"<?= selected($pid, $f['user']) ?>><?= e($pn) ?></option><?php endforeach ?></select>
    <select name="type" aria-label="Leave type" data-autosubmit><option value="">All types</option><?php foreach ($allTypes as $tid => $tn): ?><option value="<?= (int) $tid ?>"<?= selected($tid, $f['type']) ?>><?= e($tn) ?></option><?php endforeach ?></select>
    <label class="lv-date"><span>From</span><input type="date" name="from" value="<?= e($f['from']) ?>"></label>
    <label class="lv-date"><span>To</span><input type="date" name="to" value="<?= e($f['to']) ?>"></label>
    <button class="btn sm soft"><?= icon('filter', 'sm') ?> Filter</button><?php if ($filtered): ?><a class="btn sm ghost" href="<?= e(url('/hr/leave/approvals?status='.$status)) ?>">Clear</a><?php endif ?>
  </form>
  <?php if (!$p['items']): ?><?= empty_state($status === 'pending' && !$filtered ? 'All caught up!' : 'Nothing here', $status === 'pending' && !$filtered ? 'No leave requests are waiting for your review.' : 'No requests match these filters.', $status === 'pending' && !$filtered ? 'celebrate' : 'empty-box') ?><?php else: ?>
  <?php if ($pendingRows): ?>
  <form class="lv-bulk" id="lv-bulk" method="post" action="<?= e(url('/hr/leave/bulk-review')) ?>" data-lv-bulk><?= csrf_field() ?>
    <label class="check"><input type="checkbox" data-lv-all aria-label="Select all pending requests on this page"> <span data-lv-count>Select all</span></label>
    <input type="text" name="review_note" maxlength="500" placeholder="Note or reason for the selected (required to reject)" aria-label="Note or reason for the selected requests">
    <div class="lv-bulk-acts"><button class="btn sm danger" name="decision" value="reject" data-lv-need-note disabled><?= icon('x', 'sm') ?> Reject</button><button class="btn sm primary" name="decision" value="approve" data-confirm="Approve the selected leave requests?" disabled><?= icon('check', 'sm') ?> Approve selected</button></div>
  </form>
  <?php endif ?>
  <ul class="lv-list" aria-label="Leave requests">
    <?php foreach ($p['items'] as $r): $id = (int) $r['id']; [$bt, $bw] = $balLine($r); $ov = $overlaps[$id] ?? []; $hist = $history[$id] ?? []; $edits = array_values(array_filter($hist, static fn ($h) => $h['action'] === 'edit')); $last = $edits ? end($edits) : null;
      $data = LC::dialogData($r, $r['status'] === 'pending' ? $bt : ''); $detail = url(LC::url($id)); $cxl = $r['status'] === 'approved' && $r['cancel_requested_at']; ?>
    <li class="lv-item<?= $r['status'] === 'pending' ? ' is-pending' : '' ?><?= $cxl ? ' is-cxl' : '' ?>">
      <?php if ($r['status'] === 'pending'): ?><label class="lv-pick"><input type="checkbox" name="ids[]" value="<?= $id ?>" form="lv-bulk" data-lv-pick><span class="sr">Select request of <?= e($r['name']) ?></span></label><?php else: ?><span class="lv-pick" aria-hidden="true"></span><?php endif ?>
      <div class="lv-who"><?= avatar($r['name'], $r['avatar']) ?><div class="lv-wt"><a class="b lv-name" href="<?= e($detail) ?>"><?= e($r['name']) ?></a><div class="xs muted truncate"><?= e(trim(($r['designation'] ?? '').($r['department'] ? ' · '.$r['department'] : ''), ' ·') ?: 'Employee') ?></div><div class="xs muted" title="<?= e(fdatetime($r['created_at'])) ?>"><?= $r['created_by'] && (int) $r['created_by'] !== (int) $r['user_id'] ? 'Booked by an approver' : 'Applied' ?> <?= e(fdate($r['created_at'], 'd M')) ?>, <?= e(ago($r['created_at'])) ?></div></div></div>
      <div class="lv-what">
        <div class="lv-line"><span class="pill pill-<?= e($r['color']) ?>"><?= e($r['type_name']) ?></span> <b><?= e(LC::range($r['start_date'], $r['end_date'])) ?></b><?= (int) $r['half_day'] ? ' <span class="pill pill-gray">Half day</span>' : '' ?> <span class="lv-days"><?= e(LS::num($r['days'])) ?> day<?= (float) $r['days'] == 1 ? '' : 's' ?></span></div>
        <?php if ($r['reason']): ?><p class="lv-reason"><?= icon('message', 'sm') ?> <span><?= e(str_limit($r['reason'], 160)) ?></span></p><?php endif ?>
        <?php if ($cxl): ?><p class="lv-reason lv-cxlwhy"><?= icon('x', 'sm') ?> <span><b>Wants to cancel:</b> <?= e(str_limit((string) $r['cancel_reason'], 160)) ?></span></p><?php endif ?>
        <div class="lv-meta">
          <span class="<?= $bw ? 'lv-warn' : '' ?>"><?= icon('pie', 'sm') ?> <?= e($bt) ?></span>
          <?php if ($ov): $names = array_map(static fn ($o) => $o['name'].' ('.($o['status'] === 'pending' ? 'pending, ' : '').LC::range($o['start_date'], $o['end_date']).')', $ov); ?><span class="lv-warn" title="<?= e(implode("\n", $names)) ?>"><?= icon('users', 'sm') ?> Also out: <?= e(implode(', ', array_slice(array_column($ov, 'name'), 0, 3))) ?><?= count($ov) > 3 ? ' +'.(count($ov) - 3) : '' ?></span><?php elseif ($r['status'] === 'pending'): ?><span><?= icon('users', 'sm') ?> No one else in the team is out</span><?php endif ?>
        </div>
        <?php if ($r['status'] !== 'pending' && ($r['reviewer'] || $r['review_note'])): ?><p class="lv-decision lv-d-<?= e($r['status']) ?>"><?= $r['reviewer'] ? e(ucfirst($r['status'] === 'cancelled' ? 'reviewed' : $r['status']).' by '.$r['reviewer']).' · ' : '' ?><?= $r['review_note'] ? '<q>'.e(str_limit($r['review_note'], 140)).'</q>' : '<span class="muted">no note</span>' ?></p><?php endif ?>
        <?php if ($last): ?><div class="xs lv-edited"><?= icon('edit', 'sm') ?> Edited by <?= e($last['actor'] ?? 'someone') ?> on <?= e(fdate($last['created_at'], 'd M')) ?></div><?php endif ?>
      </div>
      <div class="lv-side">
        <div><?= badge($r['status']) ?><?= $cxl ? ' <span class="pill pill-peach">Cancel requested</span>' : '' ?></div>
        <div class="lv-acts">
          <?php if ($r['status'] === 'pending'): ?>
            <form method="post" action="<?= e(url('/hr/leave/'.$id.'/review')) ?>"<?= $bw ? ' data-confirm="'.e($bt.'. Approve anyway? It will fail if the balance is short.').'"' : '' ?>><?= csrf_field() ?><input type="hidden" name="decision" value="approve"><button class="btn sm primary"><?= icon('check', 'sm') ?> Approve</button></form>
            <button type="button" class="btn sm danger-soft" data-modal="lv-review" data-lv-act="<?= e(url('/hr/leave/'.$id.'/review')) ?>" data-lv-focus="review_note" data-lv="<?= e($data) ?>"><?= icon('x', 'sm') ?> Reject</button>
          <?php endif ?>
          <?php if ($cxl): ?><button type="button" class="btn sm primary" data-modal="lv-cxl" data-lv-act="<?= e(url('/hr/leave/'.$id.'/cancellation')) ?>" data-lv="<?= e($data) ?>"><?= icon('check', 'sm') ?> Decide</button><?php endif ?>
          <a class="btn sm ghost" href="<?= e($detail) ?>"><?= icon('eye', 'sm') ?> Details</a>
          <?php if (in_array($r['status'], ['pending', 'approved'], true)): ?><button type="button" class="btn icon sm ghost" data-modal="lv-edit" data-lv-edit="<?= e(url('/hr/leave/'.$id.'/edit')) ?>" data-lv="<?= e($data) ?>" title="Edit" aria-label="Edit leave of <?= e($r['name']) ?>"><?= icon('edit', 'sm') ?></button><?php endif ?>
          <?php if ($r['status'] === 'approved'): ?><button type="button" class="btn icon sm ghost" data-modal="lv-revoke" data-lv-act="<?= e(url('/hr/leave/'.$id.'/revoke')) ?>" data-lv="<?= e($data) ?>" title="Revoke: cancel and return the days" aria-label="Revoke leave of <?= e($r['name']) ?>"><?= icon('trash', 'sm') ?></button><?php endif ?>
        </div>
      </div>
    </li>
    <?php endforeach ?>
  </ul>
  <?= pager($p) ?>
  <?php endif ?>
</div>
<?= partial('hr::leave/_dialogs', ['types' => $types, 'approver' => true]) ?>
<?php if ($people): ?>
<dialog class="modal" id="lv-behalf"<?= $errs && $form === 'behalf' ? ' data-open' : '' ?> aria-labelledby="lv-behalf-t">
  <div class="modal-head"><div><h3 id="lv-behalf-t">Apply leave on behalf</h3><p class="xs muted mb0">Booked as approved and charged to their balance. They are notified.</p></div><button type="button" class="btn icon sm ghost" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div>
  <form class="modal-body form" method="post" action="<?= e(url('/hr/leave/on-behalf')) ?>" data-leave-form data-preview="hr/leave/preview"><?= csrf_field() ?><input type="hidden" name="lv_form" value="behalf">
    <div class="field"><label for="lv-b-user">Employee *</label><select id="lv-b-user" name="user_id" required><option value="">Choose…</option><?php foreach ($people as $pid => $pn): ?><option value="<?= (int) $pid ?>"<?= selected($pid, $form === 'behalf' ? old('user_id') : '') ?>><?= e($pn) ?></option><?php endforeach ?></select><?= $form === 'behalf' ? error_for('user_id') : '' ?></div>
    <div class="field"><label for="lv-b-type">Leave type *</label><select id="lv-b-type" name="leave_type_id" required><option value="">Choose…</option><?= $typeOpts($form === 'behalf' ? old('leave_type_id') : '') ?></select><?= $form === 'behalf' ? error_for('leave_type_id') : '' ?></div>
    <div class="form-grid">
      <div class="field"><label for="lv-b-from">From *</label><input id="lv-b-from" type="date" name="start_date" value="<?= e($form === 'behalf' ? old('start_date') : '') ?>" required><?= $form === 'behalf' ? error_for('start_date') : '' ?></div>
      <div class="field"><label for="lv-b-to">To</label><input id="lv-b-to" type="date" name="end_date" value="<?= e($form === 'behalf' ? old('end_date') : '') ?>"><div class="hint">Leave empty for a single day.</div><?= $form === 'behalf' ? error_for('end_date') : '' ?></div>
    </div>
    <label class="check"><input type="checkbox" name="half_day" value="1"<?= checked($form === 'behalf' && old('half_day')) ?>> Half day (single date)</label><?= $form === 'behalf' ? error_for('half_day') : '' ?>
    <div class="hr-preview" data-leave-preview hidden aria-live="polite"></div>
    <div class="field"><label for="lv-b-reason">Reason</label><textarea id="lv-b-reason" name="reason" rows="2" maxlength="1000" placeholder="e.g. Called in sick, informed by phone"><?= e($form === 'behalf' ? old('reason') : '') ?></textarea></div>
    <div class="field"><label for="lv-b-note">Note to the employee</label><input id="lv-b-note" type="text" name="note" maxlength="500" value="<?= e($form === 'behalf' ? old('note') : '') ?>" placeholder="Optional"></div>
    <div class="form-actions"><button type="button" class="btn ghost" data-close>Cancel</button><button class="btn primary"><?= icon('check', 'sm') ?> Book leave</button></div>
  </form>
</dialog>
<?php endif ?>
