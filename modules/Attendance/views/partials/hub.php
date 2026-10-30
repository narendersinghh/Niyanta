<?php
/* Time hub header: title, tabs (Tracker, Daily, Weekly, Monthly, Screenshots, Team review) and the member / date toolbar.
   $tab, $title, $subtitle; optional $hidden (extra GET fields for the member picker), $target + $targetName + $people (member picker), $mode ('date'|'month'|null) with $date or $month, $base (path for the toolbar), $actions (html), $extra (html in the toolbar) */
$me = (int) user()['id'];
$target = (int) ($target ?? $me);
$people = $people ?? [];
$uq = $target !== $me ? '?user='.$target : '';
$track = can('attendance.track');
$tabs = array_filter([
  'tracker' => $track ? ['Tracker', 'timer', '/time'] : null,
  'daily' => ['Daily', 'clock', '/time/daily'.$uq],
  'weekly' => ['Weekly', 'calendar', $target === $me ? '/timesheets' : '/timesheets/user/'.$target],
  'monthly' => ['Monthly', 'grid', '/time/monthly'.$uq],
  'screenshots' => \Modules\Attendance\Services\Monitoring::enabled() || can('attendance.screenshots.view') ? ['Screenshots', 'image', '/time/screenshots'.$uq] : null,
  'review' => can('attendance.timesheets.approve') ? ['Team review', 'check-square', '/timesheets/approvals'] : null]);
$mode = $mode ?? null;
$keep = static fn (array $q): string => ($q = array_filter($q + ($target !== $me ? ['user' => $target] : []), static fn ($v) => $v !== null && $v !== '')) ? '?'.http_build_query($q) : '';
?>
<div class="hub-head">
  <div class="grow"><h1><?= e($title) ?></h1><?php if (!empty($subtitle)): ?><p class="muted mb0"><?= e($subtitle) ?></p><?php endif ?></div>
  <?php if (!empty($actions)): ?><div class="hub-actions"><?= $actions ?></div><?php endif ?>
</div>
<nav class="tabs hub-tabs" aria-label="Time tracking views"><?php foreach ($tabs as $k => [$l, $ic, $href]): ?><a href="<?= e(url($href)) ?>" class="<?= $k === $tab ? 'active' : '' ?>"<?= $k === $tab ? ' aria-current="page"' : '' ?>><?= icon($ic, 'sm') ?> <?= e($l) ?></a><?php endforeach ?></nav>
<?php if ($people || $mode || !empty($extra)): ?>
<div class="hub-bar">
  <?php if ($people): ?><form method="get" action="<?= e(url($base)) ?>" class="hub-field"><label for="hub-member">Member</label>
    <div class="hub-member"><?= avatar($targetName ?? '', null, 'sm') ?><select id="hub-member" name="user" data-autosubmit><?php foreach ($people as $id => $n): ?><option value="<?= (int) $id ?>"<?= selected($id, $target) ?>><?= e($n) ?><?= (int) $id === $me ? ' (me)' : '' ?></option><?php endforeach ?></select></div>
    <?php if ($mode === 'date'): ?><input type="hidden" name="date" value="<?= e($date) ?>"><?php elseif ($mode === 'month'): ?><input type="hidden" name="month" value="<?= e($month) ?>"><?php endif ?><?php foreach ($hidden ?? [] as $hk => $hv): ?><input type="hidden" name="<?= e($hk) ?>" value="<?= e($hv) ?>"><?php endforeach ?></form><?php endif ?>
  <?php if ($mode === 'date'): $prev = date('Y-m-d', strtotime($date.' -1 day')); $next = date('Y-m-d', strtotime($date.' +1 day')); $today = date('Y-m-d'); ?>
  <form method="get" action="<?= e(url($base)) ?>" class="hub-field"><label for="hub-date">Date<?= $date === $today ? ' (today)' : '' ?></label>
    <div class="hub-nav"><?php if ($target !== $me): ?><input type="hidden" name="user" value="<?= $target ?>"><?php endif ?><input id="hub-date" type="date" name="date" value="<?= e($date) ?>" max="<?= e($today) ?>" data-autosubmit>
      <a class="btn icon ghost" href="<?= e(url($base.$keep(['date' => $prev]))) ?>" aria-label="Previous day" title="Previous day"><?= icon('chevron-left', 'sm') ?></a>
      <?php if ($date < $today): ?><a class="btn icon ghost" href="<?= e(url($base.$keep(['date' => $next]))) ?>" aria-label="Next day" title="Next day"><?= icon('chevron-right', 'sm') ?></a><?php else: ?><span class="btn icon ghost is-disabled" aria-disabled="true"><?= icon('chevron-right', 'sm') ?></span><?php endif ?>
      <?php if ($date !== $today): ?><a class="btn ghost" href="<?= e(url($base.$keep([]))) ?>">Today</a><?php endif ?></div></form>
  <?php elseif ($mode === 'month'): $m0 = $month.'-01'; $prev = date('Y-m', strtotime($m0.' -1 month')); $next = date('Y-m', strtotime($m0.' +1 month')); $cur = date('Y-m'); ?>
  <div class="hub-field"><span class="lbl">Month</span>
    <div class="hub-nav"><a class="btn icon ghost" href="<?= e(url($base.$keep(['month' => $prev]))) ?>" aria-label="Previous month" title="Previous month"><?= icon('chevron-left', 'sm') ?></a><b class="hub-month"><?= e(date('F Y', strtotime($m0))) ?></b>
      <?php if ($month < $cur): ?><a class="btn icon ghost" href="<?= e(url($base.$keep(['month' => $next]))) ?>" aria-label="Next month" title="Next month"><?= icon('chevron-right', 'sm') ?></a><a class="btn ghost" href="<?= e(url($base.$keep([]))) ?>">This month</a><?php else: ?><span class="btn icon ghost is-disabled" aria-disabled="true"><?= icon('chevron-right', 'sm') ?></span><?php endif ?></div></div>
  <?php endif ?>
  <?= $extra ?? '' ?>
</div>
<?php endif ?>
