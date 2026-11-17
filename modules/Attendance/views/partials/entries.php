<?php /* $days (date => entries), $ws, $editable (bool: owner or HR may change entries), optional $added (AttendanceService::addedTime: attendance records beyond the tracked time),
   optional $review (viewer may review: Approve day / Reject day per day, entry dialog actions), $uid + $who (the person shown, for review forms and the dialog).
   Every row opens its details (partials/review_dialog). */
use Modules\Attendance\Services\{EntryReview, Timesheets, TimeService};
$srcIc = ['timer' => 'timer', 'manual' => 'edit', 'agent' => 'monitor', 'desktop' => 'monitor'];
$srcLbl = ['timer' => 'Web timer', 'manual' => 'Imported or manually added', 'agent' => 'Agent', 'desktop' => 'Desktop app'];
$added = $added ?? []; $review = !empty($review); $who = $who ?? ''; $uid = (int) ($uid ?? 0);
foreach ($added as $d => $_) { $days[$d] ??= []; }
krsort($days);
if (!$days): ?><?= empty_state('No time logged this week', $editable ? 'Track your day with the Niyanta Tracker desktop app.' : 'Nothing was tracked in this week.', 'clock-worker') ?><?php else: foreach ($days as $d => $list): $sum = 0; $rs = 0; foreach ($list as $x) { $sum += TimeService::elapsed($x); if ($x['status'] === 'rejected' && !$x['is_running']) { $rs += (int) $x['duration_seconds']; } } $sum += $added[$d]['secs'] ?? 0; $rv = $review && !Timesheets::payrollLocked($d); ?>
<div class="day-group">
  <div class="day-head"><b><?= e(fdate($d, 'l, d M')) ?></b><span class="dh-r"><?php if ($rs): ?><span class="pill pill-red" title="Rejected time, not counted as approved"><?= e(hours($rs)) ?> rejected</span><?php endif ?><span class="pill pill-gray"><?= e(hours($sum)) ?></span><?php if ($rv && $list): ?><?= partial('attendance::partials/day_review', ['uid' => $uid, 'date' => $d, 'name' => $who, 'list' => $list, 'sm' => true]) ?><?php endif ?></span></div>
  <?php foreach ($list as $en): $dur = TimeService::elapsed($en); $rej = $en['status'] === 'rejected' && !$en['is_running'];
    $json = $editable && Timesheets::entryChangeable(user(), $en) ? ['id' => (int) $en['id'], 'date' => substr($en['started_at'], 0, 10), 'start_time' => substr($en['started_at'], 11, 5), 'end_time' => substr((string) $en['ended_at'], 11, 5), 'project_id' => $en['project_id'], 'task_id' => $en['task_id'], 'description' => $en['description'] ?? '', 'billable' => (int) $en['billable']] : null; ?>
  <div class="entry is-click<?= $rej ? ' is-rej' : '' ?>" data-entry-view="<?= e(json_encode(EntryReview::view($en, $json, $rv, $who) + ['color' => feature_on('projects') ? $en['project_color'] ?? 'gray' : 'gray'])) ?>" tabindex="0" role="button" aria-label="<?= e(TimeService::label($en).', '.fdate($d, 'D d M').' '.ftime($en['started_at']).($rej ? ', rejected' : '').'. Open details') ?>">
    <span class="bar c-<?= e(feature_on('projects') ? $en['project_color'] ?? 'gray' : 'gray') ?>"></span>
    <div class="grow">
      <div class="ttl"><?= e(TimeService::label($en)) ?><?php if ($en['task_title'] && feature_on('projects')): ?><span class="muted small">· <?= e(str_limit($en['task_title'], 50)) ?></span><?php endif ?></div>
      <div class="meta"><span><?= e(ftime($en['started_at'])) ?> - <?= $en['is_running'] || !$en['ended_at'] ? 'now' : e(ftime($en['ended_at'])) ?></span><?php if ($en['description']): ?><span>· <?= e(str_limit($en['description'], 80)) ?></span><?php endif ?>
        <span class="pill pill-gray"><?= icon($srcIc[$en['source']] ?? 'clock', 'sm') ?> <?= e($srcLbl[$en['source']] ?? ucfirst($en['source'])) ?></span><?= $en['billable'] ? '<span class="pill pill-lemon">Billable</span>' : '' ?>
        <?php if ((int) $en['idle_seconds'] > 0): ?><span class="pill pill-peach" title="Idle detected<?= (int) $en['discarded_seconds'] ? ', '.e(hours($en['discarded_seconds'])).' discarded' : '' ?>">Idle <?= e(hours($en['idle_seconds'])) ?></span><?php endif ?>
        <?= $en['is_running'] ? '<span class="rec">Running</span>' : ($rej ? '<span class="pill pill-red">'.icon('alert', 'sm').' Rejected</span>' : '') ?></div>
      <?php if ($rej): ?><div class="rv-line"><?= $en['reviewer_name'] ? '<b>'.e($en['reviewer_name']).':</b> ' : '' ?><?= e(str_limit((string) ($en['review_note'] ?: 'No reason given.'), 160)) ?></div><?php endif ?>
    </div>
    <div class="dur<?= $rej ? ' rej' : '' ?>"><?= e(hours($dur)) ?></div>
    <div class="acts"><?php if ($json): ?>
      <button class="btn icon sm ghost" data-modal="entry-dialog" data-entry-edit="<?= e(json_encode($json)) ?>" aria-label="Edit entry"><?= icon('edit', 'sm') ?></button>
      <form method="post" action="<?= e(url('/time/entries/'.$en['id'].'/delete')) ?>" data-confirm="Delete this time entry?"><?= csrf_field() ?><button class="btn icon sm ghost" aria-label="Delete entry"><?= icon('trash', 'sm') ?></button></form>
    <?php elseif ($editable && !$en['is_running']): ?><span class="muted" title="Locked: the week is closed or its payroll is finalized"><?= icon('lock', 'sm') ?></span><?php endif ?></div>
  </div>
  <?php endforeach ?>
  <?php if (isset($added[$d])): $a = $added[$d]; ?>
  <div class="entry is-added">
    <span class="bar c-sky"></span>
    <div class="grow">
      <div class="ttl">Attendance record<?= $a['status'] !== 'present' ? '<span class="muted small">· '.e(ucfirst(str_replace('_', ' ', $a['status']))).'</span>' : '' ?></div>
      <div class="meta"><?php if ($a['clock_in']): ?><span><?= e(ftime($a['clock_in'])) ?> - <?= $a['clock_out'] ? e(ftime($a['clock_out'])) : '-' ?></span><?php endif ?><?php if ($a['note']): ?><span>· <?= e(str_limit($a['note'], 80)) ?></span><?php endif ?>
        <span class="pill pill-sky" title="Added, edited or imported<?= $a['editor'] ? ' by '.e($a['editor']) : '' ?> in attendance; counted beyond the tracked time"><?= icon('edit', 'sm') ?> Imported or manually added</span><?php if ($a['editor']): ?><span class="muted">by <?= e($a['editor']) ?></span><?php endif ?></div>
    </div>
    <div class="dur"><?= e(hours($a['secs'])) ?></div>
    <div class="acts"></div>
  </div>
  <?php endif ?>
</div>
<?php endforeach; endif ?>
