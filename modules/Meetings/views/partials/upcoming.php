<?php
/* Upcoming meetings list. $rows (Schedule::upcoming), $plan (may create: shows the New meeting button), $compact (card on client / project pages), $where all (client › project) | project (client page) | none (project page) */
use Modules\Meetings\Services\Schedule;
$compact ??= false; $where ??= 'all';
$today = date('Y-m-d'); $tomorrow = date('Y-m-d', strtotime('+1 day'));
$day = static fn (string $at) => ($d = date('Y-m-d', strtotime($at))) === $today ? 'Today' : ($d === $tomorrow ? 'Tomorrow' : fdate($at, 'D d M'));
$list = static function () use ($rows, $day, $where): string {
  $h = '<div class="mt-up">';
  foreach ($rows as $r) {
    $now = strtotime($r['occurred_at']) <= time();
    $h .= '<a class="mt-up-item" href="'.e(url('/meetings/event/'.(int) $r['id'])).'"><span class="mt-up-date"><b>'.e(date('d', strtotime($r['occurred_at']))).'</b><small>'.e(date('M', strtotime($r['occurred_at']))).'</small></span><span class="grow"><b class="small">'.e($r['title']).'</b>'
      .'<span class="meta">'.e($day($r['occurred_at'])).' · '.e(ftime($r['occurred_at'])).($r['ends_at'] && $r['ends_at'] > $r['occurred_at'] ? ' - '.e(ftime($r['ends_at'])) : '').($where === 'all' ? ' · '.e(Schedule::where($r)) : ($where === 'project' && $r['project_name'] ? ' · '.e($r['project_name']) : '')).'</span></span>'.((int) $r['people'] ? '<span class="mt-up-n" title="Attendees">'.icon('users', 'sm').(int) $r['people'].'</span>' : '')
      .($now ? '<span class="pill pill-mint">Now</span>' : '').'</a>'; }
  return $h.'</div>'; };
?>
<?php if ($compact): ?>
<div class="card mt-up-card">
  <div class="card-head"><h3>Upcoming meetings</h3><?php if ($plan): ?><button type="button" class="btn sm soft" data-modal="mt-new" data-mt-new><?= icon('plus', 'sm') ?> New</button><?php endif ?></div>
  <?php if (!$rows): ?><div class="list-item"><span class="mt-ic"><?= icon('calendar', 'sm') ?></span><p class="small muted mb0">Nothing planned. Use New meeting to schedule one and invite the team.</p></div><?php else: ?><?= $list() ?><?php endif ?>
</div>
<?php else: ?>
<?php if (!$rows): ?><?= empty_state('No upcoming meetings', 'Plan one with New meeting: pick the client and project, invite the team, add the agenda.', 'calendar-person', $plan ? '<button type="button" class="btn primary" data-modal="mt-new" data-mt-new>'.icon('calendar', 'sm').' New meeting</button>' : '') ?><?php else: ?><?= $list() ?><?php endif ?>
<?php endif ?>
