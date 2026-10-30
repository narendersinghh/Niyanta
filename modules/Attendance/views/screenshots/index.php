<?php
/* Screenshots of one day: summary strip, then hour groups of 10-minute slot cards (click a card to view every capture of the slot).
   $hours (hour => slot => rows), $count, $date, $sum, $target, $targetName, $people, $retention, $enabled */
$own = $target === (int) user()['id'];
$pj = feature_on('projects');
$tf = (string) setting('general.time_format', 'h:i A');
$hm = static fn (int $h, int $m): string => date($tf, mktime($h, $m, 0));
?>
<?= partial('attendance::partials/hub', ['tab' => 'screenshots', 'title' => 'Time tracking', 'subtitle' => 'Captured by the desktop app only while tracking, with consent. Kept for '.$retention.' days.', 'target' => $target, 'targetName' => $targetName, 'people' => $people, 'mode' => 'date', 'date' => $date, 'base' => '/time/screenshots']) ?>
<?php if (!$enabled): ?><div class="alert warn"><?= icon('info', 'sm') ?> Screenshot monitoring is currently turned off. Existing images remain until their retention period ends.</div><?php endif ?>
<?= partial('attendance::partials/day_summary', ['sum' => $sum, 'targetName' => $targetName]) ?>
<?php if (!$hours): ?><div class="card"><?= empty_state('No screenshots on this day', $own ? 'Screenshots appear here when the desktop app captures your screens while you track.' : 'Nothing was captured for '.e($targetName).' on this day.', 'security-shield') ?></div>
<?php else: foreach ($hours as $h => $slots): ?>
<section class="shot-hour">
  <h3 class="shot-hour-h"><span class="dot" aria-hidden="true"></span><?= e($hm($h, 0)) ?> - <?= e($hm(($h + 1) % 24, 0)) ?><span class="muted small"><?= array_sum(array_map('count', $slots)) ?> captures</span></h3>
  <div class="shot-grid">
  <?php foreach ($slots as $k => $list):
    $first = $list[0];
    $acts = array_filter(array_column($list, 'activity_pct'), static fn ($v) => $v !== null);
    $a = $acts ? (int) round(array_sum($acts) / count($acts)) : null;
    $label = $pj ? ($first['project_name'] ?? 'No project') : 'Tracked time';
    $sub = ($pj ? $first['task_title'] : null) ?: $first['description'];
    $gallery = array_map(static fn ($r) => ['src' => url('/time/screenshots/'.$r['id'].'/image'), 't' => ftime($r['taken_at']), 'a' => $r['activity_pct'] === null ? null : (int) $r['activity_pct'], 'd' => $r['display_index'] === null ? null : (int) $r['display_index'] + 1], $list);
    $range = $hm($h, $k * 10).' - '.$hm($k === 5 ? ($h + 1) % 24 : $h, $k === 5 ? 0 : $k * 10 + 10); ?>
    <button type="button" class="shot-card" data-shots="<?= e(json_encode($gallery)) ?>" data-shots-title="<?= e($range.' · '.$label) ?>" aria-label="View <?= count($list) ?> screenshots, <?= e($range) ?>">
      <span class="sc-head"><b class="truncate"><?= e($label) ?></b><?php if ($sub): ?><span class="truncate"><?= e(str_limit($sub, 50)) ?></span><?php endif ?></span>
      <span class="sc-img<?= count($list) > 1 ? ' stack' : '' ?>"><img src="<?= e($gallery[0]['src']) ?>" alt="" loading="lazy"><?php if (count($list) > 1): ?><span class="sc-more"><?= count($list) - 1 ?> more</span><?php endif ?></span>
      <span class="sc-time"><?= e($range) ?></span>
      <?php if ($a !== null): ?><span class="sc-act"><span class="ds-bar"><i class="<?= $a >= 60 ? 'hi' : ($a >= 30 ? 'mid' : 'lo') ?>" style="width:<?= $a ?>%"></i></span><?= $a ?>%</span>
      <span class="sc-foot">Active time <b><?= (int) round($a / 10) ?> min</b></span><?php endif ?>
    </button>
  <?php endforeach ?>
  </div>
</section>
<?php endforeach; endif ?>
<dialog class="modal shot-viewer" id="shot-viewer"><div class="modal-head"><h3 data-sv-title>Screenshots</h3><button type="button" class="btn icon sm ghost" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div>
  <div class="modal-body"><div class="sv-main"><button type="button" class="btn icon ghost sv-prev" data-sv-step="-1" aria-label="Previous"><?= icon('chevron-left', 'sm') ?></button><a data-sv-link target="_blank" rel="noopener"><img data-sv-img alt=""></a><button type="button" class="btn icon ghost sv-next" data-sv-step="1" aria-label="Next"><?= icon('chevron-right', 'sm') ?></button></div>
    <div class="sv-meta small muted" data-sv-meta></div><div class="sv-thumbs" data-sv-thumbs></div></div></dialog>
