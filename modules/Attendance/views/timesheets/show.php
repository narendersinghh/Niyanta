<?= partial('attendance::partials/hub', ['tab' => 'weekly', 'title' => 'Time & Attendance', 'subtitle' => $canReview ? 'Weeks are approved automatically. Reject one to send it back for fixes.' : 'The week at a glance, by project and day.', 'target' => (int) $person['id'], 'targetName' => $person['name'], 'people' => $people, 'base' => '/timesheets', 'nav' => '/timesheets/user/'.$person['id'], 'mode' => 'week', 'week' => $ws]) ?>
<?= partial('attendance::partials/ts_week', compact('ws', 's', 'ts', 'person', 'own', 'locked', 'canReview')) ?>
<div class="grid g-main">
  <div class="card"><div class="card-head"><h3>Entries</h3><span class="muted small"><?= array_sum(array_map('count', $entries)) ?> entries</span></div><?= partial('attendance::partials/entries', ['days' => $entries, 'ws' => $ws, 'editable' => false]) ?></div>
  <div class="stack">
    <div class="card">
      <div class="card-head"><h4>Review</h4><?= $ts ? badge($ts['status'] === 'rejected' ? 'rejected' : 'approved') : '' ?></div>
      <dl class="kv">
        <dt>Total</dt><dd><?= e(hours($s['total'])) ?></dd>
        <dt>Billable</dt><dd><?= e(hours($s['billable'])) ?></dd>
        <dt>Expected</dt><dd><?= e(hours($s['expected'])) ?></dd>
        <?php if ($ts && $ts['reviewed_at']): ?><dt><?= $ts['status'] === 'rejected' ? 'Rejected' : 'Approved' ?></dt><dd><?= e(fdatetime($ts['reviewed_at'])) ?> by <?= e($ts['reviewer'] ?? '-') ?></dd><?php endif ?>
        <?php if ($ts && $ts['review_note']): ?><dt>Note</dt><dd><?= e($ts['review_note']) ?></dd><?php endif ?>
      </dl>
      <p class="small muted mb0 mt">Compare tracked time with attendance before rejecting. <?= ($feeds = \Modules\Admin\Services\SettingsSchema::join([feature_on('payroll') ? 'payroll' : null, feature_on('projects') && feature_on('invoices') ? 'client billing' : null])) ? 'Approved entries feed '.e($feeds).'.' : '' ?></p>
    </div>
  </div>
</div>
<?php if ($canReview && $ts): ?><?= partial('attendance::partials/reject_dialog') ?><?php endif ?>
