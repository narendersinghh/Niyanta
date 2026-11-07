<?php
/* Screenshots of one day: summary strip, then hour groups of 10-minute slot cards (click a card to view every capture of the slot).
   $hours (hour => slot => rows), $count, $date, $sum, $target, $targetName, $people, $retention, $enabled */
$own = $target === (int) user()['id'];
$pj = feature_on('projects');
$tf = (string) setting('general.time_format', 'h:i A');
$hm = static fn (int $h, int $m): string => date($tf, mktime($h, $m, 0));
?>
<?= partial('attendance::partials/hub', ['tab' => 'screenshots', 'title' => 'Time & Attendance', 'subtitle' => 'Captured only while tracking, with consent. Kept for '.$retention.' days.', 'target' => $target, 'targetName' => $targetName, 'people' => $people, 'mode' => 'date', 'date' => $date, 'base' => '/time/screenshots']) ?>
<?php if (!$enabled): ?><div class="alert warn"><?= icon('info', 'sm') ?> Screenshot monitoring is currently turned off. Existing images remain until their retention period ends.</div><?php endif ?>
<?php $targets = []; foreach ($hours as $h => $slots) { foreach ($slots as $k => $list) { $targets[$h * 6 + $k] = true; } } ?>
<?= partial('attendance::partials/day_summary', ['sum' => $sum, 'targetName' => $targetName, 'own' => $own, 'chart' => $chart ?? null, 'targets' => $targets]) ?>
<?php if (!$hours): ?><div class="card"><?= empty_state('No screenshots on this day', $own ? 'Screenshots appear here when the desktop app captures your screens while you track.' : 'Nothing was captured for '.e($targetName).' on this day.', 'security-shield') ?></div>
<?php else: foreach ($hours as $h => $slots): ?>
<section class="shot-hour">
  <h3 class="shot-hour-h"><span class="dot" aria-hidden="true"></span><?= e($hm($h, 0)) ?> - <?= e($hm(($h + 1) % 24, 0)) ?><span class="muted small"><?= $nc = array_sum(array_map('count', $slots)) ?> capture<?= $nc === 1 ? '' : 's' ?></span></h3>
  <div class="shot-grid">
  <?php foreach ($slots as $k => $list):
    $at = (int) array_key_first($list); $first = $list[$at];
    $acts = array_filter(array_column($list, 'activity_pct'), static fn ($v) => $v !== null);
    $a = $acts ? (int) round(array_sum($acts) / count($acts)) : null;
    $label = $pj ? ($first['project_name'] ?? 'No project') : 'Tracked time';
    $sub = ($pj ? $first['task_title'] : null) ?: $first['description'];
    $range = $hm($h, $k * 10).' - '.$hm($k === 5 ? ($h + 1) % 24 : $h, $k === 5 ? 0 : $k * 10 + 10); ?>
    <button type="button" class="shot-card" id="slot-<?= (int) $h ?>-<?= (int) $k ?>" data-shot-at="<?= $at ?>" aria-haspopup="dialog" aria-label="Open the day's screenshots at <?= e($range) ?> (<?= count($list) ?> in this slot)">
      <span class="sc-img<?= count($list) > 1 ? ' stack' : '' ?>"><img src="<?= e($gallery[$at]['th'] ?? $gallery[$at]['src']) ?>" alt="" loading="lazy" data-retry><?php if (count($list) > 1): ?><span class="sc-more"><?= count($list) - 1 ?> more</span><?php endif ?></span>
      <span class="sc-time"><b><?= e($range) ?></b><?php if (array_filter(array_column($list, 'meeting'))): ?> <span class="pill pill-sky" title="Meeting mode was on: this activity is not counted in the day's average">Meeting</span><?php endif ?><?php if ($a !== null): ?><span class="sc-act lv-<?= $a >= 60 ? 'hi' : ($a >= 30 ? 'mid' : 'lo') ?>" title="<?= $a ?>% activity, about <?= (int) round($a / 10) ?> active minutes"><span class="ds-bar"><i class="<?= $a >= 60 ? 'hi' : ($a >= 30 ? 'mid' : 'lo') ?>" style="width:<?= $a ?>%"></i></span><?= $a ?>%</span><?php endif ?></span>
      <span class="sc-head"><b class="truncate"><?= e($label) ?></b><?php if ($sub): ?><span class="truncate"><?= e(str_limit($sub, 50)) ?></span><?php endif ?></span>
    </button>
  <?php endforeach ?>
  </div>
</section>
<?php endforeach; endif ?>
<?= partial('attendance::partials/shot_viewer', ['gallery' => $gallery, 'who' => $targetName, 'date' => $date]) ?>
