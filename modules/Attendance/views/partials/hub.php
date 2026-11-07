<?php
/* Time & Attendance header, shared by every page of the hub, in this order:
   1. Me | Organization switch: first and large, for people who may see others' time. Team pages, someone else's page and ?scope=org count as Organization.
   2. Title row with the page actions.
   3. Tabs, most used first. Me: Today (live tracker), Timesheet, Screenshots, Attendance. Organization: Team today, Timesheets, Screenshots, Approvals.
      Badges: own rejected weeks (Me > Timesheet), teammates tracking now (Team today), team weeks rejected and awaiting fixes (Approvals).
   4. One toolbar row: member picker, Day / Week / Month, the date, week or month navigator, then $extra. Pages do not repeat these controls.
   $tab: tracker|daily|weekly|monthly|screenshots|attendance|team-attendance|review; $title, $subtitle; optional $actions (html), $target + $targetName + $people
   (member picker), $hidden (GET fields kept by the toolbar forms and links), $base (toolbar path), $nav (path for the navigator links, default $base),
   $mode ('date'|'week'|'month'|null) with $date, $week (Monday) or $month, $extra (html at the end of the toolbar) */
use Modules\Attendance\Controllers\TeamNowController;
use Modules\Attendance\Services\{Monitoring, Timesheets};
$u = user(); $me = (int) $u['id'];
$target = (int) ($target ?? $me);
$people = $people ?? []; $hidden = array_filter($hidden ?? [], static fn ($v) => $v !== null && $v !== '');
$track = can('attendance.track'); $all = can('attendance.view_all'); $rev = can('attendance.timesheets.approve'); $shotsAll = can('attendance.screenshots.view');
$hasOrg = $all || $rev || $shotsAll;
$org = $hasOrg && (in_array($tab, ['team-attendance', 'review'], true) || $target !== $me || ($_GET['scope'] ?? '') === 'org');
$uq = $target !== $me ? '?user='.$target : '?scope=org';
if ($org) {
  $people = array_diff_key($people, [$me => 1]);
  $tabs = array_filter([
    'team' => $all ? ['Team today', 'activity', '/attendance/team', TeamNowController::count($u), 'live', 'tracking now'] : null,
    'timesheet' => $all ? ['Timesheets', 'calendar', '/time/daily'.$uq] : null,
    'screenshots' => $shotsAll ? ['Screenshots', 'image', '/time/screenshots'.$uq] : null,
    'review' => $rev ? ['Approvals', 'check-square', '/timesheets/approvals', Timesheets::rejectedCount($u, true), 'warn', 'rejected, awaiting fixes'] : null]);
  $group = ['daily' => 'timesheet', 'weekly' => 'timesheet', 'monthly' => 'timesheet', 'team-attendance' => 'team'][$tab] ?? $tab;
  if ($group === 'timesheet' && !$all) { $group = 'review'; }
  $subs = $group === 'timesheet' && $target !== $me ? ['daily' => ['Day', '/time/daily'.$uq], 'weekly' => ['Week', '/timesheets/user/'.$target], 'monthly' => ['Month', '/time/monthly'.$uq]] : []; }
else {
  $people = [];
  $tabs = array_filter([
    'tracker' => $track ? ['Today', 'timer', '/time'] : null,
    'timesheet' => ['Timesheet', 'calendar', '/time/daily', $track ? Timesheets::rejectedCount($u) : 0, 'warn', 'rejected, to fix'],
    'screenshots' => Monitoring::enabled() || $shotsAll ? ['Screenshots', 'image', '/time/screenshots'] : null,
    'attendance' => $track ? ['Attendance', 'clock', '/attendance'] : null]);
  $group = ['daily' => 'timesheet', 'weekly' => 'timesheet', 'monthly' => 'timesheet'][$tab] ?? $tab;
  $subs = $group === 'timesheet' ? ['daily' => ['Day', '/time/daily'], 'weekly' => ['Week', '/timesheets'], 'monthly' => ['Month', '/time/monthly']] : []; }
$scopes = $hasOrg ? ['me' => ['Me', 'My time', 'user', $track ? '/time' : '/time/daily'], 'org' => ['Organization', 'My team', 'users', $all ? '/attendance/team' : ($rev ? '/timesheets/approvals' : '/time/screenshots?scope=org')]] : [];
$mode = $mode ?? null; $base = $base ?? ''; $navBase = $nav ?? $base;
/* query for toolbar links: the period, the shown member (unless the path names them) and $hidden; null drops a key */
$keep = static fn (array $q): string => ($q = array_filter($q + $hidden + ($target !== $me && !isset($nav) ? ['user' => $target] : []), static fn ($v) => $v !== null && $v !== '')) ? '?'.http_build_query($q) : '';
$fields = static function (array $skip) use ($hidden): string { $h = ''; foreach (array_diff_key($hidden, array_flip($skip)) as $k => $v) { $h .= '<input type="hidden" name="'.e($k).'" value="'.e($v).'">'; } return $h; };
$today = date('Y-m-d');
?>
<?php if ($scopes): ?>
<nav class="hub-scope" aria-label="Whose time"><?php foreach ($scopes as $k => [$l, $sub, $ic, $href]): $on = ($k === 'org') === $org; ?><a href="<?= e(url($href)) ?>" class="<?= $on ? 'on' : '' ?>"<?= $on ? ' aria-current="page"' : '' ?>><span class="hs-ic"><?= icon($ic) ?></span><span class="hs-t"><b><?= e($l) ?></b><small><?= e($sub) ?></small></span></a><?php endforeach ?></nav>
<?php endif ?>
<div class="hub-head">
  <div class="grow"><h1><?= e($title) ?></h1><?php if (!empty($subtitle)): ?><p class="muted mb0"><?= e($subtitle) ?></p><?php endif ?></div>
  <?php if (!empty($actions)): ?><div class="hub-actions"><?= $actions ?></div><?php endif ?>
</div>
<nav class="tabs hub-tabs" aria-label="<?= $org ? 'Organization time and attendance' : 'My time and attendance' ?>"><?php foreach ($tabs as $k => $t): [$l, $ic, $href] = $t; $n = (int) ($t[3] ?? 0); ?><a href="<?= e(url($href)) ?>" class="<?= $k === $group ? 'active' : '' ?>"<?= $k === $group ? ' aria-current="page"' : '' ?>><?= icon($ic, 'sm') ?> <?= e($l) ?><?php if ($n): ?> <span class="hub-badge <?= e($t[4]) ?>" title="<?= $n ?> <?= e($t[5]) ?>"><?= $n ?><span class="sr"> <?= e($t[5]) ?></span></span><?php endif ?></a><?php endforeach ?></nav>
<?php if ($people || $mode || $subs || !empty($extra)): ?>
<div class="hub-bar" role="toolbar" aria-label="Member and period">
  <?php if ($people): ?><form method="get" action="<?= e(url($base)) ?>" class="hub-member"><label class="sr" for="hub-member">Team member</label><?= avatar($targetName ?? '', null, 'sm') ?>
    <select id="hub-member" name="user" data-autosubmit><?php foreach ($people as $id => $nm): ?><option value="<?= (int) $id ?>"<?= selected($id, $target) ?>><?= e($nm) ?></option><?php endforeach ?></select>
    <?php if ($mode === 'date'): ?><input type="hidden" name="date" value="<?= e($date) ?>"><?php elseif ($mode === 'month'): ?><input type="hidden" name="month" value="<?= e($month) ?>"><?php elseif ($mode === 'week'): ?><input type="hidden" name="week" value="<?= e($week) ?>"><?php endif ?><?= $fields(['date', 'month', 'week', 'user']) ?></form><?php endif ?>
  <?php if ($subs): ?><nav class="seg hub-period" aria-label="Timesheet period"><?php foreach ($subs as $k => [$l, $href]): ?><a href="<?= e(url($href)) ?>" class="<?= $k === $tab ? 'on' : '' ?>"<?= $k === $tab ? ' aria-current="page"' : '' ?>><?= e($l) ?></a><?php endforeach ?></nav><?php endif ?>
  <?php if ($mode === 'date'): $prev = date('Y-m-d', strtotime($date.' -1 day')); $next = date('Y-m-d', strtotime($date.' +1 day')); ?>
  <form method="get" action="<?= e(url($navBase)) ?>" class="hub-nav"><?php if ($target !== $me && !isset($nav)): ?><input type="hidden" name="user" value="<?= $target ?>"><?php endif ?><?= $fields(['date', 'user']) ?>
    <a class="btn icon ghost" href="<?= e(url($navBase.$keep(['date' => $prev]))) ?>" aria-label="Previous day" title="Previous day"><?= icon('chevron-left', 'sm') ?></a>
    <label class="sr" for="hub-date">Date</label><input id="hub-date" type="date" name="date" value="<?= e($date) ?>" max="<?= e($today) ?>" data-autosubmit>
    <?php if ($date < $today): ?><a class="btn icon ghost" href="<?= e(url($navBase.$keep(['date' => $next]))) ?>" aria-label="Next day" title="Next day"><?= icon('chevron-right', 'sm') ?></a><?php else: ?><span class="btn icon ghost is-disabled" aria-hidden="true"><?= icon('chevron-right', 'sm') ?></span><?php endif ?>
    <?php if ($date !== $today): ?><a class="btn ghost hub-now" href="<?= e(url($navBase.$keep(['date' => null]))) ?>">Today</a><?php else: ?><span class="hub-now is-on">Today</span><?php endif ?></form>
  <?php elseif ($mode === 'week'): $prev = date('Y-m-d', strtotime($week.' -7 days')); $next = date('Y-m-d', strtotime($week.' +7 days')); $cur = \Modules\Attendance\Services\TimeService::weekStart(); ?>
  <form method="get" action="<?= e(url($navBase)) ?>" class="hub-nav"><?php if ($target !== $me && !isset($nav)): ?><input type="hidden" name="user" value="<?= $target ?>"><?php endif ?><?= $fields(['week', 'user']) ?>
    <a class="btn icon ghost" href="<?= e(url($navBase.$keep(['week' => $prev]))) ?>" aria-label="Previous week" title="Previous week"><?= icon('chevron-left', 'sm') ?></a>
    <label class="sr" for="hub-week">Any day of the week</label><input id="hub-week" type="date" name="week" value="<?= e($week) ?>" max="<?= e($today) ?>" data-autosubmit title="Pick any day to open its week">
    <?php if ($week < $cur): ?><a class="btn icon ghost" href="<?= e(url($navBase.$keep(['week' => $next]))) ?>" aria-label="Next week" title="Next week"><?= icon('chevron-right', 'sm') ?></a><?php else: ?><span class="btn icon ghost is-disabled" aria-hidden="true"><?= icon('chevron-right', 'sm') ?></span><?php endif ?>
    <?php if ($week !== $cur): ?><a class="btn ghost hub-now" href="<?= e(url($navBase.$keep(['week' => null]))) ?>">This week</a><?php else: ?><span class="hub-now is-on">This week</span><?php endif ?></form>
  <?php elseif ($mode === 'month'): $m0 = $month.'-01'; $prev = date('Y-m', strtotime($m0.' -1 month')); $next = date('Y-m', strtotime($m0.' +1 month')); $cur = date('Y-m'); ?>
  <div class="hub-nav">
    <a class="btn icon ghost" href="<?= e(url($navBase.$keep(['month' => $prev]))) ?>" aria-label="Previous month" title="Previous month"><?= icon('chevron-left', 'sm') ?></a><b class="hub-month"><?= e(date('F Y', strtotime($m0))) ?></b>
    <?php if ($month < $cur): ?><a class="btn icon ghost" href="<?= e(url($navBase.$keep(['month' => $next]))) ?>" aria-label="Next month" title="Next month"><?= icon('chevron-right', 'sm') ?></a><?php else: ?><span class="btn icon ghost is-disabled" aria-hidden="true"><?= icon('chevron-right', 'sm') ?></span><?php endif ?>
    <?php if ($month !== $cur): ?><a class="btn ghost hub-now" href="<?= e(url($navBase.$keep(['month' => null]))) ?>">This month</a><?php else: ?><span class="hub-now is-on">This month</span><?php endif ?></div>
  <?php endif ?>
  <?= $extra ?? '' ?>
</div>
<?php endif ?>
