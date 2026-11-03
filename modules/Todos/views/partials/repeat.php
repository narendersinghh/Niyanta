<?php
use Modules\Todos\Services\Todos as T;
/* Repeat choice + its options (shared by the New task dialog and the edit dialogs). $p id prefix, $v values, $mode new|todo|series, $f error renderer fn(string $field): string */
$rep = (string) ($v['repeat'] ?? 'once');
$days = T::days($v['weekdays'] ?? '');
$reps = ($mode === 'new' ? ['once' => 'Once'] : []) + T::FREQ;
?>
<?php if ($mode !== 'todo'): ?>
<fieldset class="field td-fs"><legend>Repeat</legend>
  <div class="td-seg"><?php foreach ($reps as $k => $l): ?><label><input type="radio" name="repeat" value="<?= e($k) ?>"<?= checked($rep === $k) ?>><span><?= icon($k === 'once' ? 'check' : 'refresh', 'sm') ?> <?= e($l) ?></span></label><?php endforeach ?></div>
</fieldset>
<?php endif ?>
<div class="td-rep" data-rep="once"><div class="field"><label for="<?= $p ?>-due">Due date</label><input id="<?= $p ?>-due" type="date" name="due_date" value="<?= e($v['due_date'] ?? '') ?>"><?= $f('due_date') ?><?php if ($mode === 'todo' && !empty($v['occurrence'])): ?><div class="hint">Part of a routine.</div><?php endif ?></div></div>
<div class="td-rep" data-rep="daily"><label class="check"><input type="checkbox" name="workdays_only" value="1"<?= checked(!empty($v['workdays_only'])) ?>> Weekdays only (skip weekly off days)</label></div>
<div class="td-rep" data-rep="weekly"><div class="field"><span class="td-lbl">On these days</span><div class="td-days"><?php foreach (T::DAYS as $n => $dn): ?><label><input type="checkbox" name="weekdays[]" value="<?= $n ?>"<?= checked(in_array($n, $days, true)) ?>><span><?= e($dn) ?></span></label><?php endforeach ?></div><?= $f('weekdays') ?></div></div>
<div class="td-rep" data-rep="monthly"><div class="field"><label for="<?= $p ?>-md">Day of month</label><input id="<?= $p ?>-md" type="number" name="month_day" min="1" max="31" value="<?= (int) (($v['month_day'] ?? '') ?: date('j')) ?>"><div class="hint">In shorter months it falls on the last day.</div><?= $f('month_day') ?></div></div>
<div class="td-rep form-grid" data-rep="daily weekly monthly">
  <div class="field"><label for="<?= $p ?>-start">Start date</label><input id="<?= $p ?>-start" type="date" name="start_date" value="<?= e(($v['start_date'] ?? '') ?: date('Y-m-d')) ?>"><?= $f('start_date') ?></div>
  <div class="field"><label for="<?= $p ?>-end">End date</label><input id="<?= $p ?>-end" type="date" name="end_date" value="<?= e($v['end_date'] ?? '') ?>"><div class="hint">Optional, leave empty to repeat until paused.</div><?= $f('end_date') ?></div>
</div>
