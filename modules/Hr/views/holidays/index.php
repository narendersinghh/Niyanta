<?php use Modules\Hr\Services\LeaveService as LS;
$today = date('Y-m-d');
$dows = $weekStart === 7 ? ['S', 'M', 'T', 'W', 'T', 'F', 'S'] : ['M', 'T', 'W', 'T', 'F', 'S', 'S'];
$weekend = LS::weekendDays();
$left = $bal && $bal['allocated_row'] ? max(0, (float) $bal['available']) : 0.0;
$alloc = $bal ? (float) $bal['allocated'] : 0.0;
$pct = $alloc > 0 ? (int) round($left / $alloc * 100) : 0;
$yearNav = '<div class="flex"><a class="btn icon ghost" href="?year='.($year - 1).'" title="Previous year" aria-label="Previous year">'.icon('chevron-left', 'sm').'</a><span class="hr-year">'.(int) $year.'</span><a class="btn icon ghost" href="?year='.($year + 1).'" title="Next year" aria-label="Next year">'.icon('chevron-right', 'sm').'</a></div>';
$err = errors(); $holErr = isset($err['holiday_date']) || isset($err['name']);
$leaveErr = array_intersect_key($err, array_flip(['leave_type_id', 'start_date', 'end_date', 'half_day']));
$isOh = ($oh['code'] ?? '') === LS::OPTIONAL_CODE; $poolName = $oh ? ($isOh ? 'optional days' : $oh['name']) : 'optional days';
[$aiOk, $aiWhy] = $ai;
$holForm = static function (array $h, bool $opt): string { ob_start(); $mov = isset($h['movable']) ? (int) $h['movable'] : (isset($h['name']) && $h['name'] !== '' ? (int) Modules\Hr\Services\HolidayService::isMovable((string) $h['name']) : 0); ?>
  <div class="form-grid">
    <div class="field"><label>Date *</label><input type="date" name="holiday_date" value="<?= e($h['holiday_date'] ?? '') ?>" required></div>
    <div class="field"><label>Name *</label><input type="text" name="name" maxlength="150" value="<?= e($h['name'] ?? '') ?>" placeholder="<?= $opt ? 'e.g. Ganesh Chaturthi' : 'e.g. Diwali' ?>" required></div>
  </div>
  <div class="field"><label>Kind</label>
    <label class="check"><input type="radio" name="optional" value="0"<?= checked(!$opt) ?>> Fixed holiday, office closed for everyone</label>
    <label class="check mt"><input type="radio" name="optional" value="1"<?= checked($opt) ?>> Optional festival day, employees may take it from their leave balance</label>
  </div>
  <input type="hidden" name="movable" value="0"><label class="check"><input type="checkbox" name="movable" value="1"<?= checked($mov) ?>> Date changes every year (festival on a lunar calendar, e.g. Holi, Diwali, Eid)</label>
  <div class="hint">Fixed-date holidays are copied to the next year as they are; festival dates are looked up (AI when Goraiya is set up) or marked "Check date".</div>
<?php return (string) ob_get_clean(); };
?>
<?= partial('partials/page_head', ['title' => 'Holidays', 'subtitle' => 'Fixed holidays are off for everyone. Optional festival days come out of your optional-day pool.', 'crumbs' => [['My HR'], ['Holidays']],
  'actions' => $yearNav.($manage ? ($rows ? ($aiOk ? '<form method="post" action="'.e(url('/hr/holidays/ai-fix')).'" data-busy="Asking the AI…">'.csrf_field().'<input type="hidden" name="year" value="'.(int) $year.'"><button class="btn ghost" title="Ask the AI for the correct '.(int) $year.' dates of the holidays listed (India); you review the changes before anything is saved.">'.icon('sparkles', 'sm').' Fix dates with AI</button></form>'
      : '<span class="lv-ai-off" title="'.e((string) $aiWhy).'"><button type="button" class="btn ghost" disabled aria-describedby="ai-off-hint">'.icon('sparkles', 'sm').' Fix dates with AI</button><span id="ai-off-hint" class="sr">'.e((string) $aiWhy).'</span></span>') : '')
    .(!$nextCount && $rows ? '<form method="post" action="'.e(url('/hr/holidays/next-year')).'" data-busy="Creating…" data-confirm="Create the '.((int) $year + 1).' list from '.(int) $year.'? Fixed dates are copied; festival dates come from known dates'.($aiOk ? ' or the AI' : '').', otherwise they are marked Check date.">'.csrf_field().'<input type="hidden" name="year" value="'.((int) $year + 1).'"><button class="btn ghost">'.icon('calendar', 'sm').' Create '.((int) $year + 1).'</button></form>' : '')
    .'<button type="button" class="btn primary" data-modal="hol-new">'.icon('plus', 'sm').' Add holiday</button>' : '')]) ?>
<?php if (can('hr.leave.request') || can('hr.leave.approve')): ?><?= partial('hr::leave/_tabs', ['tab' => 'holidays', 'pendingCount' => 0]) ?><?php endif ?>
<?php if ($manage && !$aiOk && $rows): ?><div class="alert info lv-ai-hint"><?= icon('sparkles', 'sm') ?><div><?= e((string) $aiWhy) ?> You can still edit every date by hand.</div></div><?php endif ?>
<?php if ($auto && $auto['created']): ?><div class="alert ok"><?= icon('check-circle', 'sm') ?><div><b><?= (int) $year ?> list created from <?= (int) $year - 1 ?>.</b> <?= (int) $auto['created'] ?> holiday(s) added<?= $auto['unverified'] ? ', '.(int) $auto['unverified'].' festival date(s) copied from last year: check them below'.($manage && $aiOk ? ' or use Fix dates with AI' : '') : '' ?>.</div></div><?php endif ?>
<?php if ($unverified && (!$auto || !$auto['created'])): ?><div class="alert warn"><?= icon('alert', 'sm') ?><div><?= (int) $unverified ?> festival date(s) in <?= (int) $year ?> were copied from last year and may be wrong (marked <b>Check date</b>).<?= $manage ? ($aiOk ? ' Use <b>Fix dates with AI</b> or edit them.' : ' Edit them by hand.') : ' HR will confirm them.' ?></div></div><?php endif ?>
<?php if ($aiFix && $manage): $changed = count(array_filter($aiFix['rows'], static fn ($r) => $r['changed'])); ?>
<div class="card mb lv-aifix" id="ai-fix">
  <div class="card-head"><div><h3><?= icon('sparkles') ?> AI date check for <?= (int) $year ?></h3><p class="small muted mb0"><?= $changed ? (int) $changed.' date(s) differ from your list. Tick the ones to apply; nothing changes until you save.' : 'The AI agrees with every date on your list.' ?> AI answers can be wrong, please double-check festival dates with your local calendar.</p></div></div>
  <form method="post" action="<?= e(url('/hr/holidays/ai-apply')) ?>"><?= csrf_field() ?><input type="hidden" name="year" value="<?= (int) $year ?>">
    <div class="table-wrap"><table class="table">
      <thead><tr><th><span class="sr">Apply</span></th><th>Holiday</th><th>Current date</th><th>AI date</th><th></th></tr></thead>
      <tbody><?php foreach ($aiFix['rows'] as $r): ?><tr class="<?= $r['changed'] ? 'lv-diff' : '' ?>">
        <td><?php if ($r['changed']): ?><input type="checkbox" name="apply[]" value="<?= (int) $r['id'] ?>" checked aria-label="Apply new date for <?= e($r['name']) ?>"><?php endif ?></td>
        <td><b class="small"><?= e($r['name']) ?></b><?= $r['optional'] ? ' <span class="pill pill-sky">Optional</span>' : '' ?></td>
        <td class="nowrap small<?= $r['changed'] ? ' lv-old' : '' ?>"><?= e(fdate($r['old'], 'D, d M Y')) ?></td>
        <td class="nowrap small"><?= $r['new'] ? ($r['changed'] ? '<b>'.e(fdate($r['new'], 'D, d M Y')).'</b>' : e(fdate($r['new'], 'D, d M Y'))) : '<span class="muted">Not sure</span>' ?></td>
        <td><?= $r['changed'] ? '<span class="pill pill-lemon">Changed</span>' : ($r['new'] ? '<span class="pill pill-mint">Same</span>' : '') ?></td>
      </tr><?php endforeach ?></tbody>
    </table></div>
    <div class="form-actions"><button class="btn ghost" name="discard" value="1">Discard</button><?php if ($changed): ?><button class="btn primary"><?= icon('check', 'sm') ?> Apply ticked dates</button><?php endif ?></div>
  </form>
</div>
<?php endif ?>
<?php if ($leaveErr): ?><div class="alert error"><?= icon('alert', 'sm') ?><div><b>Could not request that day.</b> <?= e(implode(' ', $leaveErr)) ?></div></div><?php endif ?>
<div class="card hero hr-hero mb">
  <div>
    <?php if ($next): $dd = (int) round((strtotime($next['holiday_date']) - strtotime($today)) / 86400); ?>
      <span class="pill"><?= icon('gift', 'sm') ?> Next fixed holiday</span>
      <h2><?= e($next['name']) ?></h2>
      <p class="mb0"><?= e(fdate($next['holiday_date'], 'l, d F Y')) ?> · <?= $dd === 0 ? 'today' : ($dd === 1 ? 'tomorrow' : 'in '.$dd.' days') ?></p>
    <?php else: ?><span class="pill"><?= icon('gift', 'sm') ?> Holidays</span><h2>No upcoming fixed holidays</h2><p class="mb0">Plan a break with your optional days instead.</p><?php endif ?>
    <?php if ($canRequest && $oh): ?>
      <div class="hr-balance-hero">
        <span class="ring" style="--v:<?= $pct ?>" data-label="<?= e(LS::num($left)) ?>"></span>
        <div><b><?= $bal && $bal['allocated_row'] ? e(LS::num($left)).' of '.e(LS::num($alloc)).' '.e($poolName).' left' : e(ucfirst($poolName)).' not allocated yet' ?></b><p>For festivals, personal errands or medical emergencies<?= $bal && (float) $bal['pending'] ? ' · '.e(LS::num($bal['pending'])).' pending' : '' ?>.</p></div>
      </div>
    <?php endif ?>
  </div>
  <?= illus('holidays') ?>
</div>
<div class="grid g4 mb">
  <div class="tile bg-peach"><span class="tile-ic"><?= icon('calendar') ?></span><div><div class="num"><?= count($fixed) ?></div><div class="lbl">Fixed holidays in <?= (int) $year ?></div></div></div>
  <div class="tile bg-sky"><span class="tile-ic"><?= icon('gift') ?></span><div><div class="num"><?= count($optional) ?></div><div class="lbl">Optional festival days</div></div></div>
  <div class="tile bg-mint"><span class="tile-ic"><?= icon('umbrella') ?></span><div><div class="num"><?= e(LS::num($oh && ($oh['allowance'] ?? '') === 'monthly' ? $oh['monthly_accrual'] : ($oh['annual_quota'] ?? 0))) ?></div><div class="lbl"><?= $oh && ($oh['allowance'] ?? '') === 'monthly' ? e($oh['name']).' days per month' : 'Optional days per year' ?></div></div></div>
  <div class="tile bg-lav"><span class="tile-ic"><?= icon('clock') ?></span><div><div class="num"><?= count(array_filter($fixed, static fn ($h) => $h['holiday_date'] >= $today)) ?></div><div class="lbl">Fixed holidays still ahead</div></div></div>
</div>
<div class="card mb">
  <div class="card-head"><div><h3><?= icon('calendar') ?> Fixed holidays</h3><p class="small muted mb0">Office closed, never deducted from leave.</p></div><?php if ($manage && $fixed): ?><button type="button" class="btn sm ghost" data-modal="hol-new"><?= icon('plus', 'sm') ?> Add</button><?php endif ?></div>
  <?php if (!$fixed): ?><?= empty_state('No fixed holidays for '.$year, $manage ? 'Add company-wide days off such as Republic Day, Holi or Diwali.' : 'HR has not published the holiday list yet.', 'holidays', $manage ? '<button type="button" class="btn primary" data-modal="hol-new">'.icon('plus', 'sm').' Add fixed holiday</button>' : '') ?><?php else: ?>
  <div class="grid g-main hr-top">
    <div class="list hr-hlist">
      <?php foreach ($fixed as $h): $past = $h['holiday_date'] < $today; $we = in_array((int) date('N', strtotime($h['holiday_date'])), $weekend, true); ?>
      <div class="list-item<?= $past ? ' past' : '' ?>">
        <span class="hr-date<?= $past ? ' past' : '' ?>"><b><?= date('d', strtotime($h['holiday_date'])) ?></b><small><?= date('M', strtotime($h['holiday_date'])) ?></small></span>
        <div class="grow"><b><?= e($h['name']) ?></b><?= !(int) $h['verified'] ? ' <span class="pill pill-lemon">Check date</span>' : '' ?><div class="meta"><?= date('l', strtotime($h['holiday_date'])) ?><?= (int) $h['movable'] ? ' · festival date' : '' ?><?= $we ? ' · falls on a weekend' : '' ?><?= $past ? ' · passed' : '' ?></div></div>
        <?php if ($manage): ?>
          <button type="button" class="btn icon sm ghost" data-modal="hol-<?= (int) $h['id'] ?>" title="Edit <?= e($h['name']) ?>" aria-label="Edit <?= e($h['name']) ?>"><?= icon('edit', 'sm') ?></button>
          <form method="post" action="<?= e(url('/hr/holidays/'.$h['id'].'/delete')) ?>" data-confirm="Delete <?= e($h['name']) ?>?"><?= csrf_field() ?><button class="btn icon sm ghost" title="Delete" aria-label="Delete <?= e($h['name']) ?>"><?= icon('trash', 'sm') ?></button></form>
        <?php else: ?><span class="pill pill-peach">Fixed</span><?php endif ?>
      </div>
      <?php endforeach ?>
    </div>
    <div class="hr-glance">
      <div class="flex between mb"><b class="small">Year at a glance</b><span class="chips hr-legend"><span class="chip"><i class="dotc hr-dot-peach"></i> Fixed</span><span class="chip"><i class="dotc hr-dot-sky"></i> Optional</span></span></div>
      <div class="hr-months">
        <?php for ($m = 1; $m <= 12; $m++): $first = strtotime(sprintf('%04d-%02d-01', $year, $m)); $lead = ((int) date('N', $first) - $weekStart + 7) % 7; ?>
        <div class="hr-mini">
          <div class="b xs mb0"><?= date('M', $first) ?></div>
          <div class="cal">
            <?php foreach ($dows as $dw): ?><div class="dow"><?= $dw ?></div><?php endforeach ?>
            <?php for ($i = 0; $i < $lead; $i++): ?><div></div><?php endfor ?>
            <?php for ($n = 1; $n <= (int) date('t', $first); $n++): $d = sprintf('%04d-%02d-%02d', $year, $m, $n); $hs = $byDate[$d] ?? []; $isOpt = $hs && !array_filter($hs, static fn ($x) => !(int) $x['optional']);
              $cls = $hs ? ($isOpt ? 'hr-opt-d' : 'hol') : ($d === $today ? 'today' : (in_array((int) date('N', strtotime($d)), $offDays, true) ? 'off' : '')); ?>
              <div class="d <?= $cls ?>"<?= $hs ? ' title="'.e(implode(', ', array_column($hs, 'name'))).'"' : '' ?>><?= $n ?></div>
            <?php endfor ?>
          </div>
        </div>
        <?php endfor ?>
      </div>
    </div>
  </div>
  <?php endif ?>
</div>
<div class="card">
  <div class="card-head"><div><h3><?= icon('gift') ?> Optional festival days</h3><p class="small muted mb0">Suggested dates, take the ones that matter to you from your optional-day pool.</p></div><?php if ($manage && $optional): ?><button type="button" class="btn sm ghost" data-modal="hol-new-opt"><?= icon('plus', 'sm') ?> Add</button><?php endif ?></div>
  <?php if (!$optional): ?><?= empty_state('No optional festival days yet', $manage ? 'Suggest festivals like Ganesh Chaturthi, Onam or Eid that people can take from their optional days.' : 'You can still use your optional days for any personal reason from the Leave page.', 'calendar-person', $manage ? '<button type="button" class="btn primary" data-modal="hol-new-opt">'.icon('plus', 'sm').' Add optional day</button>' : (can('hr.leave.request') ? '<a class="btn primary" href="'.e(url('/hr/leave')).'">'.icon('plus', 'sm').' Request leave</a>' : '')) ?><?php else: ?>
  <div class="hr-opts">
    <?php foreach ($optional as $h): $d = $h['holiday_date']; $past = $d < $today; $mine = $taken[$d] ?? null; $off = in_array((int) date('N', strtotime($d)), $offDays, true) || LS::isHoliday($d); ?>
    <div class="hr-opt<?= $past ? ' past' : '' ?>">
      <div class="hr-opt-top"><span class="hr-date opt<?= $past ? ' past' : '' ?>"><b><?= date('d', strtotime($d)) ?></b><small><?= date('M', strtotime($d)) ?></small></span><div class="grow"><b><?= e($h['name']) ?></b><?= !(int) $h['verified'] ? ' <span class="pill pill-lemon">Check date</span>' : '' ?><div class="xs muted"><?= date('l', strtotime($d)) ?></div></div>
        <?php if ($manage): ?><button type="button" class="btn icon sm ghost" data-modal="hol-<?= (int) $h['id'] ?>" title="Edit" aria-label="Edit <?= e($h['name']) ?>"><?= icon('edit', 'sm') ?></button><form method="post" action="<?= e(url('/hr/holidays/'.$h['id'].'/delete')) ?>" data-confirm="Delete <?= e($h['name']) ?>?"><?= csrf_field() ?><button class="btn icon sm ghost" title="Delete" aria-label="Delete <?= e($h['name']) ?>"><?= icon('trash', 'sm') ?></button></form><?php endif ?>
      </div>
      <div class="hr-opt-foot">
        <?php if ($mine): ?><?= badge($mine, $mine === 'approved' ? 'You are off' : 'Requested') ?><a class="xs muted" href="<?= e(url('/hr/leave')) ?>">View in Leave</a>
        <?php elseif ($past): ?><span class="pill pill-gray">Passed</span>
        <?php elseif ($off): ?><span class="pill pill-gray">Already a day off for you</span>
        <?php elseif ($canRequest && $oh): ?>
          <span class="xs muted"><?= $left >= 1 ? 'Uses 1 day of '.e($poolName) : 'No '.e($poolName).' left' ?></span>
          <form method="post" action="<?= e(url('/hr/leave')) ?>" data-confirm="Request <?= e($h['name']) ?> (<?= e(fdate($d)) ?>) as <?= e($oh['name']) ?>? It uses 1 of your <?= e(LS::num($left)) ?> day(s) after approval."><?= csrf_field() ?><input type="hidden" name="leave_type_id" value="<?= (int) $oh['id'] ?>"><input type="hidden" name="start_date" value="<?= e($d) ?>"><input type="hidden" name="end_date" value="<?= e($d) ?>"><input type="hidden" name="reason" value="Festival: <?= e($h['name']) ?>"><input type="hidden" name="return" value="holidays"><button class="btn sm primary"<?= $left < 1 ? ' disabled' : '' ?>><?= icon('check', 'sm') ?> Take this day</button></form>
        <?php else: ?><span class="pill pill-sky">Optional</span><?php endif ?>
      </div>
    </div>
    <?php endforeach ?>
  </div>
  <?php endif ?>
</div>
<?php if ($manage): ?>
<dialog class="modal" id="hol-new"<?= $holErr && !old('optional') ? ' data-open' : '' ?>><div class="modal-head"><h3>Add fixed holiday</h3><button type="button" class="btn icon sm ghost" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div>
  <form class="modal-body form" method="post" action="<?= e(url('/hr/holidays')) ?>"><?= csrf_field() ?><?= $holForm(['holiday_date' => old('holiday_date', $year.'-01-01'), 'name' => old('name')], false) ?><?= error_for('holiday_date') ?><?= error_for('name') ?>
    <div class="form-actions"><button type="button" class="btn ghost" data-close>Cancel</button><button class="btn primary"><?= icon('plus', 'sm') ?> Add holiday</button></div></form></dialog>
<dialog class="modal" id="hol-new-opt"<?= $holErr && old('optional') ? ' data-open' : '' ?>><div class="modal-head"><h3>Add optional festival day</h3><button type="button" class="btn icon sm ghost" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div>
  <form class="modal-body form" method="post" action="<?= e(url('/hr/holidays')) ?>"><?= csrf_field() ?><?= $holForm(['holiday_date' => old('holiday_date', $year.'-01-01'), 'name' => old('name')], true) ?><?= error_for('holiday_date') ?><?= error_for('name') ?>
    <div class="form-actions"><button type="button" class="btn ghost" data-close>Cancel</button><button class="btn primary"><?= icon('plus', 'sm') ?> Add optional day</button></div></form></dialog>
<?php foreach ($rows as $h): ?>
<dialog class="modal" id="hol-<?= (int) $h['id'] ?>"><div class="modal-head"><h3>Edit <?= e($h['name']) ?></h3><button type="button" class="btn icon sm ghost" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div>
  <form class="modal-body form" method="post" action="<?= e(url('/hr/holidays/'.$h['id'])) ?>"><?= csrf_field() ?><?= $holForm($h, (bool) (int) $h['optional']) ?>
    <div class="form-actions"><button type="button" class="btn ghost" data-close>Cancel</button><button class="btn primary"><?= icon('check', 'sm') ?> Save</button></div></form></dialog>
<?php endforeach ?>
<?php endif ?>
