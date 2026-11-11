<?php
/* Daily timesheet: summary strip with the activity chart (bars open the slot's screenshots), and an hour timeline with one block per entry. $entries, $sum, $chart, $gallery (whole-day screenshots), $shots (slot index => gallery index), $date, $target, $targetName, $people, $own, $projects */
use Modules\Attendance\Services\TimeService;
$pj = feature_on('projects');
$canAdd = $own && can('attendance.track') && \Modules\Attendance\Services\Timesheets::canEditDate($date, \Modules\Attendance\Services\Timesheets::weekRejected($target, TimeService::weekStart($date)));
$srcLbl = ['timer' => 'Web', 'manual' => 'Manual', 'agent' => 'Agent', 'desktop' => 'Desktop'];
$min = static fn (string $t): int => (int) date('G', strtotime($t)) * 60 + (int) date('i', strtotime($t));
$h0 = 9; $h1 = 18;
if ($entries) { $h0 = intdiv($min($entries[0]['started_at']), 60); $h1 = 0; foreach ($entries as $en) { $h1 = max($h1, (int) ceil(($en['end'] > $date.' 23:59:59' ? 1440 : $min($en['end']) + 1) / 60)); } }
if ($h1 - $h0 < 4) { $h1 = min(24, $h0 + 4); $h0 = max(0, $h1 - 4); }
$ph = 72; /* px per hour */
?>
<?= partial('attendance::partials/hub', ['tab' => 'daily', 'title' => 'Time & Attendance', 'subtitle' => 'Every tracked block of the day, with activity and screenshots.', 'target' => $target, 'targetName' => $targetName, 'people' => $people, 'mode' => 'date', 'date' => $date, 'base' => '/time/daily', 'hidden' => ['device' => $device['param'] ?? null],
  'actions' => $canAdd ? '<button class="btn primary" data-modal="entry-dialog" data-entry-new>'.icon('plus', 'sm').' Add manual time</button>' : '']) ?>
<?= partial('attendance::partials/devices', ['devices' => $devices ?? [], 'device' => $device ?? null, 'base' => '/time/daily', 'date' => $date, 'target' => $target]) ?>
<?= partial('attendance::partials/day_summary', ['sum' => $sum, 'devName' => $device['name'] ?? null, 'targetName' => $targetName, 'own' => $own, 'chart' => $chart ?? null, 'shots' => $shots ?? []]) ?>
<div class="card tl-day">
  <?php if (!$entries): ?><?= empty_state('Nothing tracked on this day', $own ? 'Start the Niyanta Tracker desktop app to track your time, or add time manually.' : e($targetName).' has no tracked time on this day.', 'clock-worker') ?>
  <?php else: ?>
  <div class="tld-scroll"><div class="tld-head" aria-hidden="true"><span><?= $pj ? 'Project &amp; task' : 'Work' ?></span><span>Start</span><span>End</span><span>Total</span></div><div class="tld" style="height:<?= ($h1 - $h0) * $ph + 24 ?>px">
    <?php for ($h = $h0; $h <= $h1; $h++): ?><div class="tld-h" style="top:<?= ($h - $h0) * $ph + 12 ?>px"><span><?= e(date(str_contains((string) setting('general.time_format', 'h:i A'), 'H') ? 'H:00' : 'g A', mktime($h % 24, 0, 0))) ?></span></div><?php endfor ?>
    <?php foreach ($entries as $en):
      $s = $min($en['started_at']) - $h0 * 60; $len = max(1, (int) round((strtotime($en['end']) - strtotime($en['started_at'])) / 60));
      $top = (int) round($s * $ph / 60) + 12; $hgt = max(30, (int) round($len * $ph / 60) - 3);
      $edit = $own && TimeService::editable($en) ? ['id' => (int) $en['id'], 'date' => substr($en['started_at'], 0, 10), 'start_time' => substr($en['started_at'], 11, 5), 'end_time' => substr((string) $en['ended_at'], 11, 5), 'project_id' => $en['project_id'], 'task_id' => $en['task_id'], 'description' => $en['description'] ?? '', 'billable' => (int) $en['billable']] : null;
      $ipct = $en['dur'] ? min(100, (int) round(100 * $en['idle'] / $en['dur'])) : 0; ?>
    <div class="tld-b c-<?= e($pj ? ($en['project_color'] ?? 'gray') : 'gray') ?><?= $hgt < 46 ? ' slim' : '' ?><?= $en['is_running'] ? ' run' : '' ?>" style="top:<?= $top ?>px;height:<?= $hgt ?>px">
      <?php $src = $srcLbl[$en['source']] ?? ucfirst($en['source']); ?><span class="tld-src" title="<?= e($src.' entry') ?>"><?= e($hgt < 46 ? $src[0] : $src) ?></span>
      <div class="tld-name"><b class="truncate"><?= e(TimeService::label($en)) ?></b><span class="tld-range"><?= e(ftime($en['started_at'])) ?> - <?= $en['is_running'] ? 'now' : e(ftime($en['end'])) ?></span><?php if ($hgt >= 46 && (($pj && $en['task_title']) || $en['description'])): ?><span class="truncate"><?= e(str_limit(($pj ? $en['task_title'] : null) ?: $en['description'], 60)) ?></span><?php endif ?></div>
      <span class="tld-t"><?= e(ftime($en['started_at'])) ?></span><span class="tld-t"><?= $en['is_running'] ? '<span class="rec">Now</span>' : e(ftime($en['end'])) ?></span>
      <span class="tld-t tot"><?= e(hours($en['dur'])) ?><?php if ($en['idle']): ?><small title="Idle time in this block"><?= e(hours($en['idle'])) ?> idle</small><?php endif ?></span>
      <span class="tld-acts"><?php if ($edit): ?><button class="btn icon sm ghost" data-modal="entry-dialog" data-entry-edit="<?= e(json_encode($edit)) ?>" aria-label="Edit entry" title="Edit"><?= icon('edit', 'sm') ?></button><?php endif ?></span>
      <?php if ($ipct): ?><i class="tld-idle" style="height:<?= max(3, (int) round($hgt * $ipct / 100)) ?>px" title="<?= e(hours($en['idle'])) ?> idle"></i><?php endif ?>
    </div>
    <?php endforeach ?>
  </div></div>
  <div class="legend ta-legend"><span><i class="lg-track"></i>Tracked block</span><span><i class="lg-idle"></i>Idle part</span><?php if ($own): ?><span>Use the pencil on a block to fix it. Closed weeks are read-only.</span><?php endif ?></div>
  <?php endif ?>
</div>
<?php if ($canAdd || ($own && $entries)): ?><?= partial('attendance::partials/entry_dialog', ['projects' => $projects, 'date' => $date]) ?><?php endif ?>
<?= partial('attendance::partials/shot_viewer', ['gallery' => $gallery ?? [], 'who' => $targetName, 'date' => $date]) ?>
