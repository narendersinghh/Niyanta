<?php
$ts = strtotime($start); $prev = date('Y-m', strtotime('-1 month', $ts)); $next = date('Y-m', strtotime('+1 month', $ts));
$lead = ((int) date('N', $ts) - $weekStart + 7) % 7; $daysIn = (int) date('t', $ts);
$dows = $weekStart === 7 ? ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] : ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
$weekend = Modules\Hr\Services\LeaveService::weekendDays(); $today = date('Y-m-d');
?>
<?= partial('partials/page_head', ['title' => 'Team leave calendar', 'subtitle' => $approver ? 'Who is out today, this week and this month. Dashed: pending requests waiting for a decision.' : 'Who is out today, this week and this month, plan around it.', 'crumbs' => [['My HR'], ['Leave', '/hr/leave'], ['Calendar']], 'actions' => can('hr.leave.request') ? '<a class="btn primary" href="'.e(url('/hr/leave')).'">'.icon('plus', 'sm').' Request leave</a>' : '']) ?>
<?= partial('hr::leave/_tabs', ['tab' => $tab, 'pendingCount' => $pendingCount]) ?>
<?php $sq = $team ? '&scope=team' : ''; $whoRow = static function (array $o, string $meta) use ($showTypes): string {
  return '<div class="list-item">'.avatar($o['name'], $o['avatar'], 'sm').'<div class="grow"><b class="small">'.e($o['name']).'</b><div class="meta">'.e($meta).'</div></div>'.($showTypes ? '<span class="pill pill-'.e($o['color']).'">'.e($o['type_name']).'</span>' : '').'</div>'; }; ?>
<?php if ($approver): ?><div class="chips mb lv-scope" aria-label="Whose leave"><a class="chip<?= $team ? '' : ' on' ?>" href="?month=<?= e(date('Y-m', strtotime($start))) ?>">Everyone</a><a class="chip<?= $team ? ' on' : '' ?>" href="?month=<?= e(date('Y-m', strtotime($start))) ?>&scope=team">My team</a></div><?php endif ?>
<div class="grid g2 mb lv-who-out">
  <div class="card">
    <div class="card-head"><h3><?= icon('plane') ?> Out today</h3><span class="small muted"><?= e(fdate(date('Y-m-d'), 'D, d M')) ?> · <?= count($who['today']) ?></span></div>
    <?php if (!$who['today']): ?><p class="small muted mb0">Everyone is in today.</p><?php else: ?><div class="list"><?php foreach ($who['today'] as $o): ?><?= $whoRow($o, (int) $o['half_day'] ? 'Half day today' : ($o['end_date'] === date('Y-m-d') ? 'Until today' : 'Back after '.fdate($o['end_date'], 'd M'))) ?><?php endforeach ?></div><?php endif ?>
  </div>
  <div class="card">
    <div class="card-head"><h3><?= icon('calendar') ?> This week</h3><span class="small muted"><?= e(fdate($wkFrom, 'd M')) ?> - <?= e(fdate($wkTo, 'd M')) ?> · <?= count(array_unique(array_column($who['week'], 'user_id'))) ?> people</span></div>
    <?php if (!$who['week']): ?><p class="small muted mb0">No approved leave this week.</p><?php else: ?><div class="list"><?php foreach ($who['week'] as $o): ?><?= $whoRow($o, ($o['from'] === $o['to'] ? fdate($o['from'], 'D d M') : fdate($o['from'], 'D d M').' - '.fdate($o['to'], 'D d M')).((int) $o['half_day'] ? ', half day' : '')) ?><?php endforeach ?></div><?php endif ?>
  </div>
</div>
<div class="card">
  <div class="card-head">
    <div class="flex"><a class="btn icon sm ghost" href="?month=<?= e($prev.$sq) ?>" title="Previous month" aria-label="Previous month"><?= icon('chevron-left', 'sm') ?></a><h3 class="hr-month"><?= e(date('F Y', $ts)) ?></h3><a class="btn icon sm ghost" href="?month=<?= e($next.$sq) ?>" title="Next month" aria-label="Next month"><?= icon('chevron-right', 'sm') ?></a><?php if (date('Y-m', $ts) !== date('Y-m')): ?><a class="btn sm soft" href="?<?= e(ltrim($sq, '&')) ?>">Today</a><?php endif ?></div>
    <div class="chips hr-legend"><span class="chip"><i class="dotc hr-dot-peach"></i> Fixed holiday</span><span class="chip"><i class="dotc hr-dot-sky"></i> Optional festival</span><?php if ($showTypes): foreach ($legend as $l): ?><span class="chip"><i class="dotc hr-dot-<?= e($l['color']) ?>"></i> <?= e($l['code']) ?></span><?php endforeach; else: ?><span class="chip"><i class="dotc hr-dot-mint"></i> On leave</span><?php endif ?><span class="chip"><i class="dotc hr-dot-pending"></i> <?= $approver ? 'Pending' : 'Your pending' ?></span></div>
  </div>
  <div class="hr-cal">
    <?php foreach ($dows as $dw): ?><div class="hr-dow"><?= $dw ?></div><?php endforeach ?>
    <?php for ($i = 0; $i < $lead; $i++): ?><div class="hr-day blank"></div><?php endfor ?>
    <?php for ($n = 1; $n <= $daysIn; $n++): $d = date('Y-m-', $ts).str_pad((string) $n, 2, '0', STR_PAD_LEFT); $isWe = in_array((int) date('N', strtotime($d)), $weekend, true); $people = $byDay[$d] ?? []; $hols = $hol[$d] ?? []; $optOnly = $hols && !array_filter($hols, static fn ($x) => !(int) $x['optional']); ?>
      <div class="hr-day<?= $isWe ? ' we' : '' ?><?= $d === $today ? ' today' : '' ?><?= $hols ? ($optOnly ? ' opt' : ' hol') : '' ?>">
        <div class="hr-dnum"><span><?= $n ?></span><?php if (count($people) > 0): ?><small><?= count($people) ?> out</small><?php endif ?></div>
        <?php foreach ($hols as $h): ?><div class="hr-ev <?= (int) $h['optional'] ? 'opt' : 'hol' ?>" title="<?= e($h['name']) ?>"><?= icon('gift', 'sm') ?> <?= e($h['name']) ?></div><?php endforeach ?>
        <?php foreach (array_slice($people, 0, 3) as $pp): $tag = (int) $pp['user_id'] === (int) user()['id'] || $pp['status'] === 'pending' ? 'a href="'.e(url('/hr/leave/requests/'.$pp['id'])).'"' : 'div'; ?><<?= $tag ?> class="hr-ev hr-ev-<?= e($showTypes ? $pp['color'] : 'mint') ?><?= $pp['status'] === 'pending' ? ' pending' : '' ?>" title="<?= e($pp['name'].($showTypes ? ' · '.$pp['type_name'] : '').($pp['half_day'] ? ' · half day' : '').($pp['status'] === 'pending' ? ' · pending' : '')) ?>"><?= e(explode(' ', $pp['name'])[0]) ?><?= $showTypes ? ' · '.e($pp['code']) : '' ?><?= $pp['half_day'] ? ' ½' : '' ?></<?= $tag === 'div' ? 'div' : 'a' ?>><?php endforeach ?>
        <?php if (count($people) > 3): ?><div class="hr-more" title="<?= e(implode(', ', array_column(array_slice($people, 3), 'name'))) ?>">+<?= count($people) - 3 ?> more</div><?php endif ?>
      </div>
    <?php endfor ?>
  </div>
</div>
