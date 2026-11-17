<?php
/* Leave action dialogs shared by the approvals list and the request detail page; leave.js fills them from the clicked button (data-lv JSON, data-lv-act / data-lv-edit url).
   $types (active types, approvers only), $approver (bool), $mine (bool: employee dialogs), $manager (approver name for the employee text) */
$form = old('lv_form'); $types ??= []; $approver ??= false; $mine ??= false;
$typeOpts = static function ($sel) use ($types): string { $o = ''; foreach ($types as $t) { $o .= '<option value="'.(int) $t['id'].'"'.selected($t['id'], $sel).' data-half="'.(int) $t['allow_half_day'].'">'.e($t['name']).((int) $t['paid'] ? '' : ', unpaid').'</option>'; } return $o; };
if ($approver): ?>
<dialog class="modal" id="lv-review" aria-labelledby="lv-review-t">
  <div class="modal-head"><div><h3 id="lv-review-t">Review leave of <span data-lv-name></span></h3><p class="xs muted mb0" data-lv-summary></p></div><button type="button" class="btn icon sm ghost" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div>
  <form class="modal-body form" method="post" action="#"><?= csrf_field() ?>
    <div class="hr-note" data-lv-whybox><?= icon('message', 'sm') ?><div><span class="xs muted">Their reason</span><div data-lv-why></div></div></div>
    <div class="lv-bal" data-lv-balline hidden></div>
    <div class="field"><label for="lv-rv-note">Note or reason</label><textarea id="lv-rv-note" name="review_note" rows="3" maxlength="500" placeholder="Optional when approving. Required when rejecting, e.g. Release week, please pick another week"></textarea><div class="hint">Shown to the employee in My leave and in their notification.</div></div>
    <div class="form-actions"><button class="btn danger" name="decision" value="reject" data-lv-need-note><?= icon('x', 'sm') ?> Reject</button><button class="btn primary" name="decision" value="approve"><?= icon('check', 'sm') ?> Approve</button></div>
  </form>
</dialog>
<dialog class="modal" id="lv-edit"<?= errors() && $form === 'edit' ? ' data-open' : '' ?> aria-labelledby="lv-edit-t">
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
    <div class="field"><label for="lv-r-note">Reason *</label><textarea id="lv-r-note" name="note" rows="3" maxlength="500" required placeholder="Why the leave is revoked, the employee sees it"></textarea></div>
    <div class="form-actions"><button type="button" class="btn ghost" data-close>Keep leave</button><button class="btn danger"><?= icon('x', 'sm') ?> Revoke leave</button></div>
  </form>
</dialog>
<dialog class="modal" id="lv-cxl" aria-labelledby="lv-cxl-t">
  <div class="modal-head"><div><h3 id="lv-cxl-t">Cancellation request</h3><p class="xs muted mb0" data-lv-summary></p></div><button type="button" class="btn icon sm ghost" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div>
  <form class="modal-body form" method="post" action="#"><?= csrf_field() ?>
    <div class="hr-note"><?= icon('message', 'sm') ?><div><span class="xs muted">Their reason</span><div data-lv-reason></div></div></div>
    <div class="field"><label for="lv-c-note">Note to the employee</label><textarea id="lv-c-note" name="note" rows="2" maxlength="500" placeholder="Optional"></textarea></div>
    <div class="form-actions"><button class="btn ghost" name="decision" value="decline"><?= icon('x', 'sm') ?> Keep leave</button><button class="btn primary" name="decision" value="approve"><?= icon('check', 'sm') ?> Cancel leave, return days</button></div>
  </form>
</dialog>
<?php endif;
if ($mine): ?>
<dialog class="modal" id="lv-askcxl" aria-labelledby="lv-askcxl-t">
  <div class="modal-head"><div><h3 id="lv-askcxl-t">Request cancellation</h3><p class="xs muted mb0" data-lv-summary></p></div><button type="button" class="btn icon sm ghost" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div>
  <form class="modal-body form" method="post" action="#"><?= csrf_field() ?>
    <p class="small muted mb0">Your leave stays approved until <?= e($manager ?? 'HR') ?> confirms. Once confirmed, the days go back to your balance.</p>
    <div class="field"><label for="lv-ac-reason">Reason *</label><textarea id="lv-ac-reason" name="cancel_reason" rows="3" maxlength="500" required placeholder="e.g. Trip postponed, I will be working these days"></textarea></div>
    <div class="form-actions"><button type="button" class="btn ghost" data-close>Keep leave</button><button class="btn primary"><?= icon('send', 'sm') ?> Send request</button></div>
  </form>
</dialog>
<?php endif ?>
