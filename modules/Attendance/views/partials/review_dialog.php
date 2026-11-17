<?php /* Entry details and review: [data-entry-view] blocks and rows (JSON from EntryReview::view) open it (attendance.js fills it with textContent).
   Reviewers approve a rejected entry or reject an approved one with a reason; the owner (or HR) gets Edit. */ ?>
<dialog class="modal rv-modal" id="entry-view" data-rv-base="<?= e(url('/time/entries/')) ?>" aria-labelledby="rv-h">
  <div class="modal-head"><h3 id="rv-h">Time entry</h3><button type="button" class="btn icon sm ghost" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div>
  <div class="modal-body form">
    <div class="rv-top"><span class="rv-bar" data-rv-bar></span><div class="grow"><b class="d-block" data-rv="title"></b><span class="small muted d-block" data-rv="task" data-rv-row="task"></span></div><span class="pill" data-rv-pill></span></div>
    <dl class="ta-kv rv-kv">
      <div data-rv-row="who"><dt><?= icon('user', 'sm') ?> Person</dt><dd data-rv="who"></dd></div>
      <div><dt><?= icon('calendar', 'sm') ?> Date</dt><dd data-rv="date"></dd></div>
      <div><dt><?= icon('clock', 'sm') ?> Time</dt><dd data-rv="range"></dd></div>
      <div><dt><?= icon('timer', 'sm') ?> Duration</dt><dd><b data-rv="dur"></b></dd></div>
      <div data-rv-row="desc" class="rv-desc"><dt><?= icon('edit', 'sm') ?> Description</dt><dd data-rv="desc"></dd></div>
    </dl>
    <div class="rv-why" data-rv-row="note"><?= icon('alert', 'sm') ?><div class="grow"><b class="d-block">Rejected<span data-rv-row="by"> by <span data-rv="by"></span></span><span class="xs muted" data-rv="at"></span></b><span data-rv="note"></span></div></div>
    <p class="small muted mb0" data-rv-row="fix">Fix the entry and save it: it is approved again automatically and the reviewer is told.</p>
    <p class="small muted mb0" data-rv-row="run">This entry is still being tracked. It can be reviewed once it stops.</p>
    <form method="post" action="" id="rv-reject" data-rv-reject class="rv-reject"><?= csrf_field() ?>
      <div class="field"><label for="rv-note">Reason for rejecting *</label><textarea id="rv-note" name="note" maxlength="500" rows="3" required placeholder="For example: this call was with another client, please move it to the right project."></textarea><div class="hint">The employee is notified. The entry stops counting as approved until it is fixed.</div></div>
    </form>
    <div class="form-actions rv-acts">
      <button type="button" class="btn ghost" data-close>Close</button>
      <button type="button" class="btn" data-rv-edit data-modal="entry-dialog" data-entry-edit=""><?= icon('edit', 'sm') ?> Edit entry</button>
      <form method="post" action="" data-rv-approve><?= csrf_field() ?><button class="btn mint"><?= icon('check', 'sm') ?> Approve</button></form>
      <button class="btn danger" form="rv-reject" data-rv-reject-btn><?= icon('x', 'sm') ?> Reject entry</button>
    </div>
  </div>
</dialog>
