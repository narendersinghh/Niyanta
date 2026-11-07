<?php
/* Time & Attendance header: title, the Me | Organization switch, tabs (Me: Tracker, Timesheet, Screenshots, Attendance; Organization:
   Attendance, Timesheets, Screenshots, Timesheet review), the Day/Week/Month switch and the member / date toolbar.
   $tab: tracker|daily|weekly|monthly|screenshots|attendance|team-attendance|review
   $tab, $title, $subtitle; optional $hidden (extra GET fields for the member picker), $target + $targetName + $people (member picker), $mode ('date'|'month'|null) with $date or $month, $base (path for the toolbar), $actions (html), $extra (html in the toolbar) */
$me = (int) user()['id'];
$target = (int) ($target ?? $me);
$people = $people ?? [];
$track = can('attendance.track'); $all = can('attendance.view_all'); $rev = can('attendance.timesheets.approve'); $shotsAll = can('attendance.screenshots.view');
/* Me | Organization: your own time, or your team's (attendance.view_all / timesheet approvers). Someone else's page is Organization. */
$hasOrg = $all || $rev || $shotsAll;
$org = $hasOrg && (in_array($tab, ['team-attendance', 'review'], true) || $target !== $me || ($_GET['scope'] ?? '') === 'org');
$uq = $target !== $me ? '?user='.$target : '?scope=org';
if ($org) {
  $people = array_diff_key($people, [$me => 1]);
  $tabs = array_filter([
    'team' => $all ? ['Attendance', 'clock', '/attendance/team'] : null,
    'timesheet' => $all ? ['Timesheets', 'calendar', '/time/daily'.$uq] : null,
    'screenshots' => $shotsAll ? ['Screenshots', 'image', '/time/screenshots'.$uq] : null,
    'review' => $rev ? ['Timesheet review', 'check-square', '/timesheets/approvals'] : null]);
  $group = ['daily' => 'timesheet', 'weekly' => 'timesheet', 'monthly' => 'timesheet', 'team-attendance' => 'team'][$tab] ?? $tab;
  if ($group === 'timesheet' && !$all) { $group = 'review'; }
  $subs = $group === 'timesheet' && $target !== $me ? ['daily' => ['Day', '/time/daily'.$uq], 'weekly' => ['Week', '/timesheets/user/'.$target], 'monthly' => ['Month', '/time/monthly'.$uq]] : []; }
else {
  $people = [];
  $tabs = array_filter([
    'tracker' => $track ? ['Tracker', 'timer', '/time'] : null,
    'timesheet' => ['Timesheet', 'calendar', '/time/daily'],
    'screenshots' => \Modules\Attendance\Services\Monitoring::enabled() || $shotsAll ? ['Screenshots', 'image', '/time/screenshots'] : null,
    'attendance' => $track ? ['Attendance', 'clock', '/attendance'] : null]);
  $group = ['daily' => 'timesheet', 'weekly' => 'timesheet', 'monthly' => 'timesheet'][$tab] ?? $tab;
  $subs = $group === 'timesheet' ? ['daily' => ['Day', '/time/daily'], 'weekly' => ['Week', '/timesheets'], 'monthly' => ['Month', '/time/monthly']] : []; }
$scopes = $hasOrg ? ['me' => ['Me', 'user', $track ? '/time' : '/time/daily'], 'org' => ['Organization', 'users', $all ? '/attendance/team' : ($rev ? '/timesheets/approvals' : '/time/screenshots?scope=org')]] : [];
$mode = $mode ?? null;
$keep = static fn (array $q): string => ($q = array_filter($q + ($target !== $me ? ['user' => $target] : []), static fn ($v) => $v !== null && $v !== '')) ? '?'.http_build_query($q) : '';
?>
<div class="hub-head">
  <div class="grow"><h1><?= e($title) ?></h1><?php if (!empty($subtitle)): ?><p class="muted mb0"><?= e($subtitle) ?></p><?php endif ?></div>
  <?php if ($scopes): ?><nav class="seg hub-scope" aria-label="Whose time"><?php foreach ($scopes as $k => [$l, $ic, $href]): $on = ($k === 'org') === $org; ?><a href="<?= e(url($href)) ?>" class="<?= $on ? 'on' : '' ?>"<?= $on ? ' aria-current="page"' : '' ?>><?= icon($ic, 'sm') ?> <?= e($l) ?></a><?php endforeach ?></nav><?php endif ?>
  <?php if (!empty($actions)): ?><div class="hub-actions"><?= $actions ?></div><?php endif ?>
</div>
<nav class="tabs hub-tabs" aria-label="<?= $org ? 'Organization time and attendance' : 'My time and attendance' ?>"><?php foreach ($tabs as $k => [$l, $ic, $href]): ?><a href="<?= e(url($href)) ?>" class="<?= $k === $group ? 'active' : '' ?>"<?= $k === $group ? ' aria-current="page"' : '' ?>><?= icon($ic, 'sm') ?> <?= e($l) ?></a><?php endforeach ?></nav>
<?php if ($people || $mode || $subs || !empty($extra)): ?>
<div class="hub-bar">
  <?php if ($subs): ?><div class="hub-field"><span class="lbl">View</span><nav class="seg" aria-label="Timesheet period"><?php foreach ($subs as $k => [$l, $href]): ?><a href="<?= e(url($href)) ?>" class="<?= $k === $tab ? 'on' : '' ?>"<?= $k === $tab ? ' aria-current="page"' : '' ?>><?= e($l) ?></a><?php endforeach ?></nav></div><?php endif ?>
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
