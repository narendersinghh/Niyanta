<?= partial('attendance::partials/hub', ['tab' => 'weekly', 'title' => 'Time & Attendance', 'subtitle' => $canReview ? 'Weeks are approved automatically. Reject one to send it back for fixes.' : 'The week by project and day.', 'target' => (int) $person['id'], 'targetName' => $person['name'], 'people' => $people, 'base' => '/timesheets', 'nav' => '/timesheets/user/'.$person['id'], 'mode' => 'week', 'week' => $ws]) ?>
<?= partial('attendance::partials/ts_week', compact('ws', 's', 'ts', 'person', 'own', 'locked', 'canReview')) ?>
<div class="ta-split wide">
  <section class="card"><div class="card-head"><h3>Entries</h3><span class="muted small"><?= $n = array_sum(array_map('count', $entries)) ?> entr<?= $n === 1 ? 'y' : 'ies' ?></span></div><?= partial('attendance::partials/entries', ['days' => $entries, 'ws' => $ws, 'editable' => false]) ?></section>
  <section class="card">
    <div class="card-head"><h3>Review</h3><?= $ts ? badge($ts['status'] === 'rejected' ? 'rejected' : 'approved') : '' ?></div>
    <dl class="ta-kv">
      <div><dt>Total</dt><dd><?= e(hours($s['total'])) ?></dd></div>
      <div><dt>Billable</dt><dd><?= e(hours($s['billable'])) ?></dd></div>
      <div><dt>Expected</dt><dd><?= e(hours($s['expected'])) ?></dd></div>
      <?php if ($ts && $ts['reviewed_at']): ?><div><dt><?= $ts['status'] === 'rejected' ? 'Rejected' : 'Approved' ?></dt><dd class="small"><?= e(fdatetime($ts['reviewed_at'])) ?> by <?= e($ts['reviewer'] ?? '-') ?></dd></div><?php endif ?>
    </dl>
    <?php if ($ts && $ts['review_note']): ?><p class="small mb0"><b>Note:</b> <?= e($ts['review_note']) ?></p><?php endif ?>
    <p class="ta-note">Compare tracked time with attendance before rejecting. <?= ($feeds = \Modules\Admin\Services\SettingsSchema::join([feature_on('payroll') ? 'payroll' : null, feature_on('projects') && feature_on('invoices') ? 'client billing' : null])) ? 'Approved entries feed '.e($feeds).'.' : '' ?></p>
  </section>
</div>
<?php if ($canReview && $ts): ?><?= partial('attendance::partials/reject_dialog') ?><?php endif ?>
