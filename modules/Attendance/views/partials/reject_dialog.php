<?php /* Reject a whole day: buttons carry data-modal="day-reject" data-reject-user data-reject-date data-reject-name (attendance.js fills the form, partials/day_review) */ ?>
<dialog class="modal" id="day-reject"><form method="post" action="<?= e(url('/time/review/day')) ?>" data-reject-form><?= csrf_field() ?><input type="hidden" name="user_id" value=""><input type="hidden" name="date" value=""><input type="hidden" name="decision" value="rejected">
  <div class="modal-head"><h3>Reject day</h3><button type="button" class="btn icon sm ghost" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div>
  <div class="modal-body form">
    <div class="rej-who"><?= icon('calendar', 'sm') ?><b class="small" data-reject-name></b></div>
    <div class="field"><label for="rej-note">What needs fixing? *</label><textarea id="rej-note" name="note" maxlength="500" rows="3" required placeholder="For example: the afternoon was a training, not client work."></textarea><div class="hint">Every stopped entry of the day is rejected with this reason and stops counting as approved. The employee gets one notification.</div></div>
    <div class="form-actions"><button type="button" class="btn ghost" data-close>Cancel</button><button class="btn danger"><?= icon('x', 'sm') ?> Reject day</button></div>
  </div>
</form></dialog>
