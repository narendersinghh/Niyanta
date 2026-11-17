<?= partial('attendance::partials/hub', ['tab' => 'weekly', 'title' => 'Time & Attendance', 'subtitle' => $canReview ? 'Time is approved as it is logged. Click an entry, or use a day\'s buttons, to approve or reject it.' : 'The week by project and day.', 'target' => (int) $person['id'], 'targetName' => $person['name'], 'people' => $people, 'base' => '/timesheets', 'nav' => '/timesheets/user/'.$person['id'], 'mode' => 'week', 'week' => $ws,
  'actions' => $manage && !$locked ? '<button class="btn primary" data-modal="entry-dialog" data-entry-new>'.icon('plus', 'sm').' Add time for '.e(explode(' ', $person['name'])[0]).'</button>' : '']) ?>
<?= partial('attendance::partials/ts_week', compact('ws', 's', 'person', 'own', 'locked', 'canReview')) ?>
<div class="ta-split wide">
  <section class="card"><div class="card-head"><h3>Entries</h3><span class="muted small"><?= $n = array_sum(array_map('count', $entries)) + count($added) ?> entr<?= $n === 1 ? 'y' : 'ies' ?></span></div><?= partial('attendance::partials/entries', ['days' => $entries, 'added' => $added, 'ws' => $ws, 'editable' => $manage, 'review' => $canReview, 'uid' => (int) $person['id'], 'who' => $person['name']]) ?></section>
  <section class="card">
    <div class="card-head"><h3>Review</h3><?= $s['rejected'] ? '<span class="pill pill-red">'.e(hours($s['rejected'])).' rejected</span>' : ($s['total'] ? badge('approved') : '') ?></div>
    <dl class="ta-kv">
      <div><dt>Total</dt><dd><?= e(hours($s['total'])) ?></dd></div>
      <div><dt>Approved</dt><dd><?= e(hours(max(0, $s['total'] - $s['rejected']))) ?></dd></div>
      <?php if ($s['rejected']): ?><div><dt>Rejected</dt><dd class="late-text"><?= e(hours($s['rejected'])) ?></dd></div><?php endif ?>
      <div><dt>Billable</dt><dd><?= e(hours($s['billable'])) ?></dd></div>
      <div><dt>Expected</dt><dd><?= e(hours($s['expected'])) ?></dd></div>
    </dl>
    <p class="ta-note"><?= $canReview ? 'Compare tracked time with attendance. Click an entry to reject it with a reason, or approve or reject a whole day from its header.' : 'Entries are approved as they are logged.' ?> <?= ($feeds = \Modules\Admin\Services\SettingsSchema::join([feature_on('payroll') ? 'payroll' : null, feature_on('projects') && feature_on('invoices') ? 'client billing' : null])) ? 'Approved entries feed '.e($feeds).'; rejected ones do not.' : '' ?></p>
  </section>
</div>
<?php if ($entries): ?><?= partial('attendance::partials/review_dialog') ?><?php endif ?>
<?php if ($canReview): ?><?= partial('attendance::partials/reject_dialog') ?><?php endif ?>
<?php if ($manage): ?><?= partial('attendance::partials/entry_dialog', ['projects' => $projects, 'date' => min(date('Y-m-d'), date('Y-m-d', strtotime($ws.' +6 days'))), 'for' => ['id' => (int) $person['id'], 'name' => $person['name']]]) ?><?php endif ?>
