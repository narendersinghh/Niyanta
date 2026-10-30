<?php /* Live tracking chip in the top bar, shown only while the desktop app tracks. $run (TimeService::running row) */
$el = \Modules\Attendance\Services\TimeService::elapsed($run);
$what = feature_on('projects') ? ($run['project_name'] ?? 'No project') : 'Tracking'; ?>
<a class="tt-live" href="<?= e(url('/time')) ?>" data-tt-live data-base="<?= $el ?>" title="Tracking: <?= e($what) ?>. Open Time tracking"><span class="tt-dot" aria-hidden="true"></span><b data-tt-clock><?= e(sprintf('%d:%02d:%02d', intdiv($el, 3600), intdiv($el % 3600, 60), $el % 60)) ?></b><span class="tt-what truncate"><?= e($what) ?></span></a>
