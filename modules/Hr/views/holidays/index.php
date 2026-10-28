<?php
$weekend = Modules\Hr\Services\LeaveService::weekendDays(); $today = date('Y-m-d');
$dows = $weekStart === 7 ? ['S', 'M', 'T', 'W', 'T', 'F', 'S'] : ['M', 'T', 'W', 'T', 'F', 'S', 'S'];
$public = count(array_filter($rows, static fn ($h) => !(int) $h['optional']));
?>
<?= partial('partials/page_head', ['title' => 'Holidays', 'subtitle' => 'Public and optional holidays. Public holidays are excluded from leave days.', 'crumbs' => [['People'], ['Holidays']],
  'actions' => '<div class="flex"><a class="btn icon ghost" href="?year='.($year - 1).'" title="Previous year">'.icon('chevron-left', 'sm').'</a><span class="hr-year">'.(int) $year.'</span><a class="btn icon ghost" href="?year='.($year + 1).'" title="Next year">'.icon('chevron-right', 'sm').'</a></div>'.($manage ? '<button type="button" class="btn primary" data-modal="hol-new">'.icon('plus', 'sm').' Add holiday</button>' : '')]) ?>
<div class="card hero mb hr-hol-hero">
  <div>
    <?php if ($next): $dd = (int) ((strtotime($next['holiday_date']) - strtotime($today)) / 86400); ?>
      <span class="pill pill-peach"><?= icon('gift', 'sm') ?> Next holiday</span>
      <h2 class="mt mb0"><?= e($next['name']) ?></h2>
      <p class="muted"><?= e(fdate($next['holiday_date'], 'l, d F Y')) ?> · <?= $dd === 0 ? 'today 🎉' : ($dd === 1 ? 'tomorrow' : 'in '.$dd.' days') ?></p>
    <?php else: ?><h2>No upcoming holidays</h2><p class="muted">Time to plan a break with a leave request?</p><?php endif ?>
    <div class="chips"><span class="chip"><?= (int) $public ?> public</span><span class="chip"><?= count($rows) - $public ?> optional</span><span class="chip">in <?= (int) $year ?></span></div>
  </div>
  <?= illus('leave-beach') ?>
</div>
<div class="grid g-main">
  <div class="card">
    <div class="card-head"><h3><?= (int) $year ?> holiday list</h3></div>
    <?php if (!$rows): ?><?= empty_state('No holidays for '.$year, $manage ? 'Add your organisation\'s public holidays to exclude them from leave.' : 'HR has not published the holiday list yet.', 'calendar-person', $manage ? '<button type="button" class="btn primary" data-modal="hol-new">'.icon('plus', 'sm').' Add holiday</button>' : '') ?><?php else: ?>
    <div class="list">
      <?php foreach ($rows as $h): $past = $h['holiday_date'] < $today; ?>
      <div class="list-item<?= $past ? ' hr-past' : '' ?>">
        <span class="hr-date <?= (int) $h['optional'] ? 'soft-sky' : 'soft-peach' ?>"><b><?= date('d', strtotime($h['holiday_date'])) ?></b><small><?= date('M', strtotime($h['holiday_date'])) ?></small></span>
        <div class="grow"><b><?= e($h['name']) ?></b><div class="meta"><?= date('l', strtotime($h['holiday_date'])) ?><?= in_array((int) date('N', strtotime($h['holiday_date'])), $weekend, true) ? ' · falls on a weekend' : '' ?></div></div>
        <?= (int) $h['optional'] ? '<span class="pill pill-sky">Optional</span>' : '<span class="pill pill-peach">Public</span>' ?>
        <?php if ($manage): ?>
          <button type="button" class="btn icon sm ghost" data-modal="hol-<?= (int) $h['id'] ?>" title="Edit"><?= icon('edit', 'sm') ?></button>
          <form method="post" action="<?= e(url('/hr/holidays/'.$h['id'].'/delete')) ?>" data-confirm="Delete <?= e($h['name']) ?>?"><?= csrf_field() ?><button class="btn icon sm danger" title="Delete"><?= icon('trash', 'sm') ?></button></form>
          <dialog class="modal" id="hol-<?= (int) $h['id'] ?>"><div class="modal-head"><h3>Edit holiday</h3><button type="button" class="btn icon sm ghost" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div>
            <form class="modal-body form" method="post" action="<?= e(url('/hr/holidays/'.$h['id'])) ?>"><?= csrf_field() ?>
              <div class="form-grid"><div class="field"><label>Date *</label><input type="date" name="holiday_date" value="<?= e($h['holiday_date']) ?>" required></div><div class="field"><label>Name *</label><input type="text" name="name" maxlength="150" value="<?= e($h['name']) ?>" required></div></div>
              <label class="check"><input type="checkbox" name="optional" value="1"<?= checked($h['optional']) ?>> Optional holiday (still counted as a working day)</label>
              <div class="form-actions"><button type="button" class="btn ghost" data-close>Cancel</button><button class="btn primary">Save</button></div>
            </form></dialog>
        <?php endif ?>
      </div>
      <?php endforeach ?>
    </div>
    <?php endif ?>
  </div>
  <div class="card">
    <div class="card-head"><h3>Year at a glance</h3><span class="chips"><span class="chip"><i class="dotc hr-dot-peach"></i> Public</span><span class="chip"><i class="dotc hr-dot-sky"></i> Optional</span></span></div>
    <div class="hr-months">
      <?php for ($m = 1; $m <= 12; $m++): $first = strtotime(sprintf('%04d-%02d-01', $year, $m)); $lead = ((int) date('N', $first) - $weekStart + 7) % 7; ?>
      <div class="hr-mini">
        <div class="b small mb0"><?= date('F', $first) ?></div>
        <div class="cal">
          <?php foreach ($dows as $dw): ?><div class="dow"><?= $dw ?></div><?php endforeach ?>
          <?php for ($i = 0; $i < $lead; $i++): ?><div></div><?php endfor ?>
          <?php for ($n = 1; $n <= (int) date('t', $first); $n++): $d = sprintf('%04d-%02d-%02d', $year, $m, $n); $hs = $byDate[$d] ?? []; $isOpt = $hs && !array_filter($hs, static fn ($x) => !(int) $x['optional']);
            $cls = $d === $today ? 'today' : ($hs ? ($isOpt ? 'hr-opt' : 'hol') : (in_array((int) date('N', strtotime($d)), $weekend, true) ? 'off' : '')); ?>
            <div class="d <?= $cls ?>"<?= $hs ? ' title="'.e(implode(', ', array_column($hs, 'name'))).'"' : '' ?>><?= $n ?></div>
          <?php endfor ?>
        </div>
      </div>
      <?php endfor ?>
    </div>
  </div>
</div>
<?php if ($manage): ?>
<dialog class="modal" id="hol-new"<?= errors() ? ' data-open' : '' ?>><div class="modal-head"><h3>Add holiday</h3><button type="button" class="btn icon sm ghost" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div>
  <form class="modal-body form" method="post" action="<?= e(url('/hr/holidays')) ?>"><?= csrf_field() ?>
    <div class="form-grid"><div class="field"><label>Date *</label><input type="date" name="holiday_date" value="<?= e(old('holiday_date', $year.'-01-01')) ?>" required><?= error_for('holiday_date') ?></div><div class="field"><label>Name *</label><input type="text" name="name" maxlength="150" value="<?= e(old('name')) ?>" placeholder="e.g. Diwali" required><?= error_for('name') ?></div></div>
    <label class="check"><input type="checkbox" name="optional" value="1"<?= checked(old('optional')) ?>> Optional holiday (still counted as a working day)</label>
    <div class="form-actions"><button type="button" class="btn ghost" data-close>Cancel</button><button class="btn primary"><?= icon('plus', 'sm') ?> Add holiday</button></div>
  </form></dialog>
<?php endif ?>
