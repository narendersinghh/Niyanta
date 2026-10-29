<?php
$ts = strtotime($start); $prev = date('Y-m', strtotime('-1 month', $ts)); $next = date('Y-m', strtotime('+1 month', $ts));
$lead = ((int) date('N', $ts) - $weekStart + 7) % 7; $daysIn = (int) date('t', $ts);
$dows = $weekStart === 7 ? ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] : ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
$weekend = Modules\Hr\Services\LeaveService::weekendDays(); $today = date('Y-m-d');
?>
<?= partial('partials/page_head', ['title' => 'Team leave calendar', 'subtitle' => 'Who is away and when, plan around it.', 'crumbs' => [['My HR'], ['Leave', '/hr/leave'], ['Calendar']], 'actions' => can('hr.leave.request') ? '<a class="btn primary" href="'.e(url('/hr/leave')).'">'.icon('plus', 'sm').' Request leave</a>' : '']) ?>
<?= partial('hr::leave/_tabs', ['tab' => $tab, 'pendingCount' => $pendingCount]) ?>
<div class="card">
  <div class="card-head">
    <div class="flex"><a class="btn icon sm ghost" href="?month=<?= e($prev) ?>" title="Previous month" aria-label="Previous month"><?= icon('chevron-left', 'sm') ?></a><h3 class="hr-month"><?= e(date('F Y', $ts)) ?></h3><a class="btn icon sm ghost" href="?month=<?= e($next) ?>" title="Next month" aria-label="Next month"><?= icon('chevron-right', 'sm') ?></a><?php if (date('Y-m', $ts) !== date('Y-m')): ?><a class="btn sm soft" href="?">Today</a><?php endif ?></div>
    <div class="chips hr-legend"><span class="chip"><i class="dotc hr-dot-peach"></i> Fixed holiday</span><span class="chip"><i class="dotc hr-dot-sky"></i> Optional festival</span><?php if ($showTypes): foreach ($legend as $l): ?><span class="chip"><i class="dotc hr-dot-<?= e($l['color']) ?>"></i> <?= e($l['code']) ?></span><?php endforeach; else: ?><span class="chip"><i class="dotc hr-dot-mint"></i> On leave</span><?php endif ?><span class="chip"><i class="dotc hr-dot-pending"></i> Your pending</span></div>
  </div>
  <div class="hr-cal">
    <?php foreach ($dows as $dw): ?><div class="hr-dow"><?= $dw ?></div><?php endforeach ?>
    <?php for ($i = 0; $i < $lead; $i++): ?><div class="hr-day blank"></div><?php endfor ?>
    <?php for ($n = 1; $n <= $daysIn; $n++): $d = date('Y-m-', $ts).str_pad((string) $n, 2, '0', STR_PAD_LEFT); $isWe = in_array((int) date('N', strtotime($d)), $weekend, true); $people = $byDay[$d] ?? []; $hols = $hol[$d] ?? []; $optOnly = $hols && !array_filter($hols, static fn ($x) => !(int) $x['optional']); ?>
      <div class="hr-day<?= $isWe ? ' we' : '' ?><?= $d === $today ? ' today' : '' ?><?= $hols ? ($optOnly ? ' opt' : ' hol') : '' ?>">
        <div class="hr-dnum"><span><?= $n ?></span><?php if (count($people) > 0): ?><small><?= count($people) ?> out</small><?php endif ?></div>
        <?php foreach ($hols as $h): ?><div class="hr-ev <?= (int) $h['optional'] ? 'opt' : 'hol' ?>" title="<?= e($h['name']) ?>"><?= icon('gift', 'sm') ?> <?= e($h['name']) ?></div><?php endforeach ?>
        <?php foreach (array_slice($people, 0, 3) as $pp): ?><div class="hr-ev hr-ev-<?= e($showTypes ? $pp['color'] : 'mint') ?><?= $pp['status'] === 'pending' ? ' pending' : '' ?>" title="<?= e($pp['name'].($showTypes ? ' · '.$pp['type_name'] : '').($pp['half_day'] ? ' · half day' : '').($pp['status'] === 'pending' ? ' · pending' : '')) ?>"><?= e(explode(' ', $pp['name'])[0]) ?><?= $showTypes ? ' · '.e($pp['code']) : '' ?><?= $pp['half_day'] ? ' ½' : '' ?></div><?php endforeach ?>
        <?php if (count($people) > 3): ?><div class="hr-more" title="<?= e(implode(', ', array_column(array_slice($people, 3), 'name'))) ?>">+<?= count($people) - 3 ?> more</div><?php endif ?>
      </div>
    <?php endfor ?>
  </div>
</div>
