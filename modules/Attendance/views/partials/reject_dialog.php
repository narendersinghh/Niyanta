<?php /* Shared reject dialog: buttons carry data-modal="reject-dialog" data-reject-url data-reject-name (attendance.js sets the form) */ ?>
<dialog class="modal" id="reject-dialog"><form method="post" action="" data-reject-form><?= csrf_field() ?>
  <div class="modal-head"><h3>Reject week</h3><button type="button" class="btn icon sm ghost" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div>
  <div class="modal-body form">
    <div class="rej-who"><?= icon('calendar', 'sm') ?><b class="small" data-reject-name></b></div>
    <div class="field"><label for="rej-note">What needs fixing? *</label><textarea id="rej-note" name="note" maxlength="500" rows="3" required placeholder="For example: Thursday is missing the client call, and Friday should be non-billable."></textarea><div class="hint">The employee is notified with this note. Entries of the week stop counting as approved until they resubmit.</div></div>
    <div class="form-actions"><button type="button" class="btn ghost" data-close>Cancel</button><button class="btn danger"><?= icon('x', 'sm') ?> Reject week</button></div>
  </div>
</form></dialog>
