<?php $ws = $t['week_start']; $we = date('Y-m-d', strtotime($ws.' +6 days')); ?>
<?= partial('partials/page_head', ['title' => $t['name'], 'subtitle' => 'Timesheet for '.fdate($ws, 'd M').' – '.fdate($we, 'd M Y'), 'crumbs' => [['Timesheets', '/timesheets'], ['Approvals', can('attendance.timesheets.approve') ? '/timesheets/approvals' : null], ['Week of '.fdate($ws, 'd M')]]]) ?>
<div class="grid g-main">
  <div class="stack">
    <div class="card">
      <div class="card-head"><div class="flex"><?= avatar($t['name'], $t['avatar']) ?><div><h3 class="mb0"><?= e($t['name']) ?></h3><div class="small muted"><?= e($t['designation'] ?? '') ?></div></div></div><?= badge($t['status']) ?></div>
      <?= partial('attendance::partials/ts_summary', ['s' => $s, 'ws' => $ws]) ?>
    </div>
    <div class="card"><div class="card-head"><h3>Entries</h3></div><?= partial('attendance::partials/entries', ['days' => $entries, 'ws' => $ws, 'editable' => false]) ?></div>
  </div>
  <div class="stack">
    <div class="card">
      <dl class="kv">
        <dt>Total</dt><dd><?= e(hours($t['total_seconds'])) ?></dd>
        <dt>Billable</dt><dd><?= e(hours($t['billable_seconds'])) ?></dd>
        <dt>Submitted</dt><dd><?= e(fdatetime($t['submitted_at'])) ?></dd>
        <?php if ($t['reviewed_at']): ?><dt>Reviewed</dt><dd><?= e(fdatetime($t['reviewed_at'])) ?> by <?= e($t['reviewer'] ?? '—') ?></dd><?php endif ?>
        <?php if ($t['review_note']): ?><dt>Note</dt><dd><?= e($t['review_note']) ?></dd><?php endif ?>
      </dl>
      <?php if ($t['can_review'] && $t['status'] === 'submitted'): ?>
        <hr>
        <form method="post" action="<?= e(url('/timesheets/'.$t['id'].'/approve')) ?>" class="form"><?= csrf_field() ?>
          <div class="field"><label>Note (optional)</label><input type="text" name="note" maxlength="500" placeholder="Looks good!"></div>
          <div class="flex wrap"><button class="btn mint"><?= icon('check', 'sm') ?> Approve</button><button type="button" class="btn danger" data-modal="reject-dialog"><?= icon('x', 'sm') ?> Reject</button></div>
        </form>
      <?php endif ?>
    </div>
    <div class="card soft-lav" style="border:0"><?= illus('reports-chart') ?><p class="small mb0 mt">Compare tracked time with attendance hours before approving. Approved entries are locked and used for payroll and client billing.</p></div>
  </div>
</div>
<?php if ($t['can_review'] && $t['status'] === 'submitted'): ?>
<dialog class="modal" id="reject-dialog"><form method="post" action="<?= e(url('/timesheets/'.$t['id'].'/reject')) ?>"><?= csrf_field() ?>
  <div class="modal-head"><h3>Reject timesheet</h3><button type="button" class="btn icon sm ghost" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div>
  <div class="modal-body form"><div class="field"><label>Reason *</label><textarea name="note" maxlength="500" required placeholder="Tell <?= e(explode(' ', $t['name'])[0]) ?> what to fix"></textarea></div>
    <div class="form-actions"><button type="button" class="btn ghost" data-close>Cancel</button><button class="btn danger"><?= icon('x', 'sm') ?> Reject</button></div></div>
</form></dialog>
<?php endif ?>
