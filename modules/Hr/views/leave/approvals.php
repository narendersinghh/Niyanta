<?php use Modules\Hr\Services\LeaveService as LS;
$chips = ['pending' => 'Pending', 'cancel' => 'Cancel requests', 'approved' => 'Approved', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled', 'all' => 'All'];
$cancelReq = (int) ($stats['cancel_req'] ?? 0);
$form = old('lv_form'); $errs = errors();
$rng = static fn (array $r) => fdate($r['start_date'], 'd M').($r['end_date'] !== $r['start_date'] ? ' - '.fdate($r['end_date'], 'd M Y') : fdate($r['start_date'], ' Y'));
$exportQ = http_build_query(array_filter(['status' => $f['status'], 'q' => $f['q'], 'user' => $f['user'] ?: null, 'type' => $f['type'] ?: null, 'from' => $f['from'], 'to' => $f['to']]));
$filtered = $f['q'] !== '' || $f['user'] || $f['type'] || $f['from'] !== '' || $f['to'] !== '';
$typeOpts = static function ($sel) use ($types): string { $o = ''; foreach ($types as $t) { $o .= '<option value="'.(int) $t['id'].'"'.selected($t['id'], $sel).' data-half="'.(int) $t['allow_half_day'].'">'.e($t['name']).((int) $t['paid'] ? '' : ', unpaid').'</option>'; } return $o; };
$actions = '<a class="btn ghost" href="'.e(url('/hr/leave/calendar')).'">'.icon('calendar', 'sm').' Team calendar</a><a class="btn ghost" href="'.e(url('/hr/leave/approvals/export').'?'.$exportQ).'">'.icon('download', 'sm').' Export CSV</a>'.($people ? '<button type="button" class="btn primary" data-modal="lv-behalf">'.icon('plus', 'sm').' Apply on behalf</button>' : '');
?>
<?= partial('partials/page_head', ['title' => 'Leave approvals', 'subtitle' => $all ? 'Review, correct and book leave for everyone in the organisation.' : 'Review, correct and book leave for your direct reports.', 'crumbs' => [['HR Admin'], ['Leave approvals']], 'actions' => $actions]) ?>
<?= partial('hr::leave/_tabs', ['tab' => $tab, 'pendingCount' => $pendingCount]) ?>
<div class="grid g4 mb">
  <a class="tile bg-lemon lv-tile" href="<?= e(url('/hr/leave/approvals?status=pending')) ?>"><span class="tile-ic"><?= icon('clock') ?></span><div><div class="num"><?= (int) $pendingCount ?></div><div class="lbl">Waiting for review</div></div></a>
  <a class="tile bg-peach lv-tile" href="<?= e(url('/hr/leave/approvals?status=cancel')) ?>"><span class="tile-ic"><?= icon('x') ?></span><div><div class="num"><?= $cancelReq ?></div><div class="lbl">Cancellation requests</div></div></a>
  <div class="tile bg-mint"><span class="tile-ic"><?= icon('check-circle') ?></span><div><div class="num"><?= (int) ($stats['approved'] ?? 0) ?></div><div class="lbl">Approved this month</div><div class="hr-tsub"><?= (int) ($stats['rejected'] ?? 0) ?> rejected</div></div></div>
  <div class="tile bg-sky"><span class="tile-ic"><?= icon('plane') ?></span><div><div class="num"><?= (int) ($stats['out_today'] ?? 0) ?></div><div class="lbl">On leave today</div></div></div>
</div>
<div class="card">
  <div class="hr-toolbar"><div class="chips"><?php foreach ($chips as $k => $l): ?><a class="chip<?= $status === $k ? ' on' : '' ?>" href="<?= e(url('/hr/leave/approvals').query_merge(['status' => $k, 'page' => 1])) ?>"><?= e($l) ?><?= $k === 'pending' && $pendingCount ? ' <span class="n">'.(int) $pendingCount.'</span>' : '' ?><?= $k === 'cancel' && $cancelReq ? ' <span class="n">'.$cancelReq.'</span>' : '' ?></a><?php endforeach ?></div></div>
  <form class="lv-filters" method="get" aria-label="Filter requests"><input type="hidden" name="status" value="<?= e($status) ?>">
    <label class="hr-search"><span class="sr">Search employee</span><?= icon('search', 'sm') ?><input type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Name or code"></label>
    <select name="user" aria-label="Employee" data-autosubmit><option value="">Everyone</option><?php foreach ($people as $pid => $pn): ?><option value="<?= (int) $pid ?>"<?= selected($pid, $f['user']) ?>><?= e($pn) ?></option><?php endforeach ?></select>
    <select name="type" aria-label="Leave type" data-autosubmit><option value="">All types</option><?php foreach ($allTypes as $tid => $tn): ?><option value="<?= (int) $tid ?>"<?= selected($tid, $f['type']) ?>><?= e($tn) ?></option><?php endforeach ?></select>
    <label class="lv-date"><span>From</span><input type="date" name="from" value="<?= e($f['from']) ?>"></label>
    <label class="lv-date"><span>To</span><input type="date" name="to" value="<?= e($f['to']) ?>"></label>
    <button class="btn sm soft"><?= icon('filter', 'sm') ?> Filter</button><?php if ($filtered): ?><a class="btn sm ghost" href="<?= e(url('/hr/leave/approvals?status='.$status)) ?>">Clear</a><?php endif ?>
  </form>
  <?php if (!$p['items']): ?><?= empty_state($status === 'pending' && !$filtered ? 'All caught up!' : 'Nothing here', $status === 'pending' && !$filtered ? 'No leave requests are waiting for your review.' : 'No requests match these filters.', $status === 'pending' && !$filtered ? 'celebrate' : 'empty-box') ?><?php else: ?>
  <div class="table-wrap"><table class="table lv-table">
    <thead><tr><th>Employee</th><th>Type</th><th>Dates</th><th class="num">Days</th><th class="num">Balance left</th><th>Status</th><th><span class="sr">Actions</span></th></tr></thead>
    <tbody><?php foreach ($p['items'] as $r): $key = $r['user_id'].'-'.$r['leave_type_id'].'-'.substr($r['start_date'], 0, 4); $left = $bal[$key] ?? null; $hist = $history[(int) $r['id']] ?? []; $edits = array_values(array_filter($hist, static fn ($h) => $h['action'] === 'edit')); $last = $edits ? end($edits) : null;
      $data = json_encode(['id' => (int) $r['id'], 'user_id' => (int) $r['user_id'], 'name' => $r['name'], 'leave_type_id' => (int) $r['leave_type_id'], 'start_date' => $r['start_date'], 'end_date' => $r['end_date'], 'half_day' => (int) $r['half_day'], 'reason' => (string) $r['reason'], 'status' => $r['status'], 'summary' => $r['type_name'].' · '.$rng($r).((int) $r['half_day'] ? ' (half day)' : '').' · '.LS::num($r['days']).' day(s)', 'cancel_reason' => (string) $r['cancel_reason']]); ?><tr>
      <td><div class="hr-who"><?= avatar($r['name'], $r['avatar'], 'sm') ?><div><b class="small"><?= e($r['name']) ?></b><div class="xs muted"><?= e(trim(($r['designation'] ?? '').($r['department'] ? ' · '.$r['department'] : ''), ' ·') ?: '-') ?></div></div></div></td>
      <td><span class="pill pill-<?= e($r['color']) ?>"><?= e($r['type_name']) ?></span><?php if ($r['reason']): ?><div class="xs muted mt hr-reason" title="<?= e($r['reason']) ?>"><?= e(str_limit($r['reason'], 60)) ?></div><?php endif ?></td>
      <td class="nowrap"><b class="small"><?= e($rng($r)) ?></b><?= $r['half_day'] ? ' <span class="pill pill-gray">½</span>' : '' ?><div class="xs muted"><?= $r['created_by'] && (int) $r['created_by'] !== (int) $r['user_id'] ? 'Booked by HR ' : 'Asked ' ?><?= e(ago($r['created_at'])) ?></div></td>
      <td class="num b"><?= e(LS::num($r['days'])) ?></td>
      <td class="num small"><?php if (!(int) $r['paid']): ?><span class="muted">Unpaid</span><?php elseif ($left === null): ?><?= $r['status'] === 'pending' ? '<span class="pill pill-red">Not allocated</span>' : '<span class="muted">-</span>' ?><?php elseif ($r['status'] === 'pending' && $left < (float) $r['days']): ?><span class="pill pill-red"><?= e(LS::num($left)) ?> days</span><?php else: ?><?= e(LS::num($left)) ?> days<?php endif ?></td>
      <td><?= badge($r['status']) ?><?php if ($r['status'] === 'approved' && $r['cancel_requested_at']): ?> <span class="pill pill-peach">Cancel requested</span><?php endif ?><?php if ($r['reviewer']): ?><div class="xs muted">by <?= e($r['reviewer']) ?></div><?php endif ?>
        <?php if ($last): ?><div class="xs lv-edited"><?= icon('edit', 'sm') ?> Edited by <?= e($last['actor'] ?? 'someone') ?> on <?= e(fdate($last['created_at'], 'd M')) ?></div><?php endif ?></td>
      <td class="actions"><div class="lv-acts">
        <?php if ($r['status'] === 'pending'): ?><button type="button" class="btn sm primary" data-modal="rv-<?= (int) $r['id'] ?>"><?= icon('check', 'sm') ?> Review</button><?php endif ?>
        <?php if ($r['status'] === 'approved' && $r['cancel_requested_at']): ?><button type="button" class="btn sm primary" data-modal="lv-cxl" data-lv-act="<?= e(url('/hr/leave/'.$r['id'].'/cancellation')) ?>" data-lv="<?= e($data) ?>"><?= icon('check', 'sm') ?> Decide</button><?php endif ?>
        <?php if (in_array($r['status'], ['pending', 'approved'], true)): ?><button type="button" class="btn sm ghost" data-modal="lv-edit" data-lv-edit="<?= e(url('/hr/leave/'.$r['id'].'/edit')) ?>" data-lv="<?= e($data) ?>"><?= icon('edit', 'sm') ?> Edit</button><?php endif ?>
        <?php if ($r['status'] === 'approved'): ?><button type="button" class="btn icon sm ghost" data-modal="lv-revoke" data-lv-act="<?= e(url('/hr/leave/'.$r['id'].'/revoke')) ?>" data-lv="<?= e($data) ?>" title="Revoke: cancel and return the days" aria-label="Revoke leave of <?= e($r['name']) ?>"><?= icon('x', 'sm') ?></button><?php endif ?>
        <?php if ($hist): ?><button type="button" class="btn icon sm ghost" data-modal="lh-<?= (int) $r['id'] ?>" title="History" aria-label="History of this request"><?= icon('history', 'sm') ?></button><?php elseif (!in_array($r['status'], ['pending', 'approved'], true) && $r['review_note']): ?><span class="xs muted" title="<?= e($r['review_note']) ?>"><?= e(str_limit($r['review_note'], 40)) ?></span><?php endif ?>
      </div></td>
    </tr><?php endforeach ?></tbody>
  </table></div>
  <?= pager($p) ?>
  <?php foreach ($p['items'] as $r): if (!empty($history[(int) $r['id']])): ?><?= partial('hr::leave/_history', ['r' => $r, 'events' => $history[(int) $r['id']]]) ?><?php endif; if ($r['status'] !== 'pending') { continue; } ?>
  <dialog class="modal" id="rv-<?= (int) $r['id'] ?>" aria-label="Review leave of <?= e($r['name']) ?>">
    <div class="modal-head"><h3>Review leave</h3><button type="button" class="btn icon sm ghost" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div>
    <form class="modal-body form" method="post" action="<?= e(url('/hr/leave/'.$r['id'].'/review')) ?>"><?= csrf_field() ?>
      <div class="hr-who"><?= avatar($r['name'], $r['avatar']) ?><div class="grow"><b><?= e($r['name']) ?></b><div class="xs muted"><?= e($r['type_name']) ?> · <?= fdate($r['start_date']) ?><?= $r['end_date'] !== $r['start_date'] ? ' - '.fdate($r['end_date']) : '' ?><?= $r['half_day'] ? ' · half day' : '' ?></div></div><span class="pill pill-<?= e($r['color']) ?>"><?= e(LS::num($r['days'])) ?> day(s)</span></div>
      <?php if ($r['reason']): ?><div class="hr-note"><?= icon('message', 'sm') ?><div><?= nl2br(e($r['reason'])) ?></div></div><?php endif ?>
      <div class="field"><label for="rn-<?= (int) $r['id'] ?>">Note to <?= e(explode(' ', $r['name'])[0]) ?></label><textarea id="rn-<?= (int) $r['id'] ?>" name="review_note" rows="3" maxlength="500" placeholder="Optional, shown in their notification"></textarea></div>
      <div class="form-actions"><button class="btn danger" name="decision" value="reject" data-confirm="Reject this leave request?"><?= icon('x', 'sm') ?> Reject</button><button class="btn primary" name="decision" value="approve"><?= icon('check', 'sm') ?> Approve</button></div>
    </form>
  </dialog>
  <?php endforeach ?>
  <?php endif ?>
</div>
<dialog class="modal" id="lv-edit"<?= $errs && $form === 'edit' ? ' data-open' : '' ?> aria-labelledby="lv-edit-t">
  <div class="modal-head"><div><h3 id="lv-edit-t">Edit leave</h3><p class="xs muted mb0" data-lv-summary><?= e(old('lv_summary')) ?></p></div><button type="button" class="btn icon sm ghost" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div>
  <form class="modal-body form" method="post" action="<?= e($form === 'edit' && (int) old('lv_id') ? url('/hr/leave/'.(int) old('lv_id').'/edit') : '#') ?>" data-leave-form data-preview="hr/leave/preview"><?= csrf_field() ?>
    <input type="hidden" name="lv_form" value="edit"><input type="hidden" name="lv_id" value="<?= (int) old('lv_id') ?>"><input type="hidden" name="user_id" value="<?= (int) old('user_id') ?>"><input type="hidden" name="lv_summary" value="<?= e(old('lv_summary')) ?>">
    <div class="hr-optbox"><?= icon('info') ?><p>Days are recounted on the employee's own work week and holidays. For approved leave the old days go back to the old type and the new days are charged, all recorded in the ledger.</p></div>
    <div class="field"><label for="lv-e-type">Leave type *</label><select id="lv-e-type" name="leave_type_id" required><?= $typeOpts($form === 'edit' ? old('leave_type_id') : '') ?></select><?= $form === 'edit' ? error_for('leave_type_id') : '' ?></div>
    <div class="form-grid">
      <div class="field"><label for="lv-e-from">From *</label><input id="lv-e-from" type="date" name="start_date" value="<?= e($form === 'edit' ? old('start_date') : '') ?>" required><?= $form === 'edit' ? error_for('start_date') : '' ?></div>
      <div class="field"><label for="lv-e-to">To</label><input id="lv-e-to" type="date" name="end_date" value="<?= e($form === 'edit' ? old('end_date') : '') ?>"><?= $form === 'edit' ? error_for('end_date') : '' ?></div>
    </div>
    <label class="check"><input type="checkbox" name="half_day" value="1"<?= checked($form === 'edit' && old('half_day')) ?>> Half day (single date)</label><?= $form === 'edit' ? error_for('half_day') : '' ?>
    <div class="hr-preview" data-leave-preview hidden aria-live="polite"></div>
    <div class="field"><label for="lv-e-reason">Reason</label><textarea id="lv-e-reason" name="reason" rows="2" maxlength="1000"><?= e($form === 'edit' ? old('reason') : '') ?></textarea></div>
    <div class="field"><label for="lv-e-note">Note to the employee *</label><textarea id="lv-e-note" name="note" rows="2" maxlength="500" required placeholder="Why this changed, e.g. approved as Paid Leave by mistake, should be Sick Leave"><?= e($form === 'edit' ? old('note') : '') ?></textarea><?= $form === 'edit' ? error_for('note') : '' ?><div class="hint">Saved in the history and sent with their notification.</div></div>
    <div class="form-actions"><button type="button" class="btn ghost" data-close>Cancel</button><button class="btn primary"><?= icon('check', 'sm') ?> Save changes</button></div>
  </form>
</dialog>
<dialog class="modal" id="lv-revoke" aria-labelledby="lv-revoke-t">
  <div class="modal-head"><div><h3 id="lv-revoke-t">Revoke approved leave</h3><p class="xs muted mb0" data-lv-summary></p></div><button type="button" class="btn icon sm ghost" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div>
  <form class="modal-body form" method="post" action="#"><?= csrf_field() ?>
    <div class="alert warn"><?= icon('alert') ?><div>The leave becomes cancelled and its days go back to the employee's balance.</div></div>
    <div class="field"><label for="lv-r-note">Note to the employee *</label><textarea id="lv-r-note" name="note" rows="3" maxlength="500" required placeholder="Why the leave is revoked"></textarea></div>
    <div class="form-actions"><button type="button" class="btn ghost" data-close>Keep leave</button><button class="btn danger"><?= icon('x', 'sm') ?> Revoke leave</button></div>
  </form>
</dialog>
<dialog class="modal" id="lv-cxl" aria-labelledby="lv-cxl-t">
  <div class="modal-head"><div><h3 id="lv-cxl-t">Cancellation request</h3><p class="xs muted mb0" data-lv-summary></p></div><button type="button" class="btn icon sm ghost" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div>
  <form class="modal-body form" method="post" action="#"><?= csrf_field() ?>
    <div class="hr-note"><?= icon('message', 'sm') ?><div data-lv-reason></div></div>
    <div class="field"><label for="lv-c-note">Note to the employee</label><textarea id="lv-c-note" name="note" rows="2" maxlength="500" placeholder="Optional"></textarea></div>
    <div class="form-actions"><button class="btn ghost" name="decision" value="decline"><?= icon('x', 'sm') ?> Keep leave</button><button class="btn primary" name="decision" value="approve"><?= icon('check', 'sm') ?> Cancel leave, return days</button></div>
  </form>
</dialog>
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
