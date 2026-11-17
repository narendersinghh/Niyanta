<?php
/* Daily timesheet: summary strip with the activity chart (bars open the slot's screenshots), and an hour timeline with one block per entry.
   Clicking a block opens its details (partials/review_dialog); reviewers ($review) approve or reject it there, or the whole day with the buttons at the top.
   $entries, $sum, $chart, $gallery (whole-day screenshots), $shots (slot index => gallery index), $date, $target, $targetName, $people, $own, $projects, $manage, $review */
use Modules\Attendance\Services\{EntryReview, TimeService, Timesheets};
$pj = feature_on('projects');
$manage = !empty($manage); $review = !empty($review);
$canAdd = ($own && can('attendance.track') && Timesheets::canEditDate($date)) || ($manage && !Timesheets::payrollLocked($date));
$rejected = array_values(array_filter($entries, static fn ($x) => $x['status'] === 'rejected' && !$x['is_running']));
$first = e(explode(' ', $targetName)[0]);
$srcLbl = ['timer' => 'Web', 'manual' => 'Added', 'agent' => 'Agent', 'desktop' => 'Desktop'];
$srcTip = ['manual' => 'Imported or manually added'];
$added = $added ?? null;
$min = static fn (string $t): int => (int) date('G', strtotime($t)) * 60 + (int) date('i', strtotime($t));
$h0 = 9; $h1 = 18;
if ($entries) { $h0 = intdiv($min($entries[0]['started_at']), 60); $h1 = 0; foreach ($entries as $en) { $h1 = max($h1, (int) ceil(($en['end'] > $date.' 23:59:59' ? 1440 : $min($en['end']) + 1) / 60)); } }
if ($h1 - $h0 < 4) { $h1 = min(24, $h0 + 4); $h0 = max(0, $h1 - 4); }
$ph = 72; /* px per hour */
?>
<?= partial('attendance::partials/hub', ['tab' => 'daily', 'title' => 'Time & Attendance', 'subtitle' => 'Every tracked block of the day, with activity and screenshots.', 'target' => $target, 'targetName' => $targetName, 'people' => $people, 'mode' => 'date', 'date' => $date, 'base' => '/time/daily', 'hidden' => ['device' => $device['param'] ?? null],
  'actions' => ($review ? partial('attendance::partials/day_review', ['uid' => $target, 'date' => $date, 'name' => $targetName, 'list' => $entries]) : '').($canAdd ? '<button class="btn primary" data-modal="entry-dialog" data-entry-new>'.icon('plus', 'sm').($own ? ' Add manual time' : ' Add time for '.$first).'</button>' : '')]) ?>
<?= partial('attendance::partials/devices', ['devices' => $devices ?? [], 'device' => $device ?? null, 'base' => '/time/daily', 'date' => $date, 'target' => $target]) ?>
<?= partial('attendance::partials/day_summary', ['sum' => $sum, 'devName' => $device['name'] ?? null, 'targetName' => $targetName, 'own' => $own, 'chart' => $chart ?? null, 'shots' => $shots ?? []]) ?>
<?php if ($rejected): $notes = []; foreach ($rejected as $x) { $notes[$x['review_note'].'|'.$x['reviewer_name']] = $x; } $rs = array_sum(array_column($rejected, 'dur')); ?>
<div class="ts-banner bad">
  <span class="ic-wrap"><?= icon('alert', 'sm') ?></span>
  <div class="grow"><b><?= count($rejected) ?> rejected entr<?= count($rejected) === 1 ? 'y' : 'ies' ?> (<?= e(hours($rs)) ?>), not counted as approved</b>
    <?php foreach (array_slice($notes, 0, 3) as $x): ?><div class="ts-note"><?= $x['reviewer_name'] ? '<b>'.e($x['reviewer_name']).':</b> ' : '' ?><?= e($x['review_note'] ?: 'No reason given.') ?></div><?php endforeach ?>
    <p><?= $own ? 'Click a red block to see why, then fix it with Edit. Saving approves it again automatically.' : ($review ? 'Waiting for '.$first.' to fix '.(count($rejected) === 1 ? 'it' : 'them').'. Click a block to approve it again, or approve the whole day.' : 'Waiting for '.$first.' to fix '.(count($rejected) === 1 ? 'it' : 'them').'.') ?></p></div>
</div>
<?php endif ?>
<?php if ($added): ?>
<div class="card ta-added"><div class="entry is-added">
  <span class="bar c-sky"></span>
  <div class="grow"><div class="ttl">Attendance record<?= $added['status'] !== 'present' ? '<span class="muted small">· '.e(ucfirst(str_replace('_', ' ', $added['status']))).'</span>' : '' ?></div>
    <div class="meta"><?php if ($added['clock_in']): ?><span><?= e(ftime($added['clock_in'])) ?> - <?= $added['clock_out'] ? e(ftime($added['clock_out'])) : '-' ?></span><?php endif ?><?php if ($added['note']): ?><span>· <?= e(str_limit($added['note'], 80)) ?></span><?php endif ?>
      <span class="pill pill-sky"><?= icon('edit', 'sm') ?> Imported or manually added</span><?php if ($added['editor']): ?><span class="muted">by <?= e($added['editor']) ?></span><?php endif ?></div></div>
  <div class="dur" title="Counted beyond the tracked time"><?= e(hours($added['secs'])) ?></div>
</div></div>
<?php endif ?>
<?php if ($entries || !$added): ?>
<div class="card tl-day">
  <?php if (!$entries): ?><?= empty_state('Nothing tracked on this day', $own ? 'Start the Niyanta Tracker desktop app to track your time, or add time manually.' : e($targetName).' has no tracked time on this day.', 'clock-worker') ?>
  <?php else: ?>
  <div class="tld-scroll"><div class="tld-head" aria-hidden="true"><span><?= $pj ? 'Project &amp; task' : 'Work' ?></span><span>Start</span><span>End</span><span>Total</span></div><div class="tld" style="height:<?= ($h1 - $h0) * $ph + 24 ?>px">
    <?php for ($h = $h0; $h <= $h1; $h++): ?><div class="tld-h" style="top:<?= ($h - $h0) * $ph + 12 ?>px"><span><?= e(date(str_contains((string) setting('general.time_format', 'h:i A'), 'H') ? 'H:00' : 'g A', mktime($h % 24, 0, 0))) ?></span></div><?php endfor ?>
    <?php foreach ($entries as $en):
      $s = $min($en['started_at']) - $h0 * 60; $len = max(1, (int) round((strtotime($en['end']) - strtotime($en['started_at'])) / 60));
      $top = (int) round($s * $ph / 60) + 12; $hgt = max(30, (int) round($len * $ph / 60) - 3);
      $edit = Timesheets::entryChangeable(user(), $en) ? ['id' => (int) $en['id'], 'date' => substr($en['started_at'], 0, 10), 'start_time' => substr($en['started_at'], 11, 5), 'end_time' => substr((string) $en['ended_at'], 11, 5), 'project_id' => $en['project_id'], 'task_id' => $en['task_id'], 'description' => $en['description'] ?? '', 'billable' => (int) $en['billable']] : null;
      $ipct = $en['dur'] ? min(100, (int) round(100 * $en['idle'] / $en['dur'])) : 0; $rej = $en['status'] === 'rejected' && !$en['is_running'];
      $why = $rej ? 'Rejected'.($en['reviewer_name'] ? ' by '.$en['reviewer_name'] : '').($en['review_note'] ? ': '.$en['review_note'] : '') : ''; ?>
    <div class="tld-b c-<?= e($pj ? ($en['project_color'] ?? 'gray') : 'gray') ?><?= $hgt < 46 ? ' slim' : '' ?><?= $en['is_running'] ? ' run' : '' ?><?= $rej ? ' rej' : '' ?>" style="top:<?= $top ?>px;height:<?= $hgt ?>px" data-entry-view="<?= e(json_encode(EntryReview::view($en, $edit, $review, $own ? '' : $targetName) + ['color' => $pj ? ($en['project_color'] ?? 'gray') : 'gray'])) ?>" tabindex="0" role="button" aria-label="<?= e(TimeService::label($en).', '.ftime($en['started_at']).($rej ? ', rejected' : '').'. Open details') ?>"<?= $rej ? ' title="'.e($why).'"' : '' ?>>
      <?php $src = $srcLbl[$en['source']] ?? ucfirst($en['source']); ?><span class="tld-src<?= $en['source'] === 'manual' ? ' added' : '' ?>" title="<?= e(($srcTip[$en['source']] ?? $src).' entry') ?>"><?= e($hgt < 46 ? $src[0] : $src) ?></span>
      <div class="tld-name"><?php if ($rej): ?><span class="pill pill-red tld-rej"><?= icon('alert', 'sm') ?> Rejected</span><?php endif ?><b class="truncate"><?= e(TimeService::label($en)) ?></b><span class="tld-range"><?= e(ftime($en['started_at'])) ?> - <?= $en['is_running'] ? 'now' : e(ftime($en['end'])) ?></span><?php if ($hgt >= 46 && $rej): ?><span class="truncate tld-why"><?= e(str_limit($why, 90)) ?></span><?php elseif ($hgt >= 46 && (($pj && $en['task_title']) || $en['description'])): ?><span class="truncate"><?= e(str_limit(($pj ? $en['task_title'] : null) ?: $en['description'], 60)) ?></span><?php endif ?></div>
      <span class="tld-t"><?= e(ftime($en['started_at'])) ?></span><span class="tld-t"><?= $en['is_running'] ? '<span class="rec">Now</span>' : e(ftime($en['end'])) ?></span>
      <span class="tld-t tot"><?= e(hours($en['dur'])) ?><?php if ($en['idle']): ?><small title="Idle time in this block"><?= e(hours($en['idle'])) ?> idle</small><?php endif ?></span>
      <span class="tld-acts"><?php if ($edit): ?><button class="btn icon sm ghost" data-modal="entry-dialog" data-entry-edit="<?= e(json_encode($edit)) ?>" aria-label="Edit entry" title="Edit"><?= icon('edit', 'sm') ?></button><?php endif ?></span>
      <?php if ($ipct): ?><i class="tld-idle" style="height:<?= max(3, (int) round($hgt * $ipct / 100)) ?>px" title="<?= e(hours($en['idle'])) ?> idle"></i><?php endif ?>
    </div>
    <?php endforeach ?>
  </div></div>
  <div class="legend ta-legend"><span><i class="lg-track"></i>Tracked block</span><span><i class="lg-idle"></i>Idle part</span><?php if ($rejected): ?><span><i class="lg-rej"></i>Rejected</span><?php endif ?><span><?= $review ? 'Click a block to approve or reject it.' : 'Click a block for its details.' ?><?php if ($own || $manage): ?> Use the pencil to fix it. <?= $own ? 'Closed weeks are read-only, except rejected entries.' : 'Changes are tagged as manually added.' ?><?php endif ?></span></div>
  <?php endif ?>
</div>
<?php endif ?>
<?php if ($entries): ?><?= partial('attendance::partials/review_dialog') ?><?php endif ?>
<?php if ($review): ?><?= partial('attendance::partials/reject_dialog') ?><?php endif ?>
<?php if ($canAdd || (($own || $manage) && $entries)): ?><?= partial('attendance::partials/entry_dialog', ['projects' => $projects, 'date' => $date, 'for' => $own ? null : ['id' => $target, 'name' => $targetName]]) ?><?php endif ?>
<?= partial('attendance::partials/shot_viewer', ['gallery' => $gallery ?? [], 'who' => $targetName, 'date' => $date]) ?>
