<?php
use Modules\Todos\Services\Todos as T;
/* Task / routine dialog. $id dialog id, $heading, $mode new|todo|series, $action, $v values, $assignees [id => name], $return, $open (bool), $submit label */
$err = $open ? errors() : [];
$f = static fn (string $k) => $open && isset($err[$k]) ? '<div class="field-error">'.e($err[$k]).'</div>' : '';
$rep = (string) ($v['repeat'] ?? 'once');
$days = T::days($v['weekdays'] ?? '');
$p = e($id);
$reps = ($mode === 'new' ? ['once' => 'Once'] : []) + T::FREQ;
?>
<dialog class="modal" id="<?= $p ?>" aria-labelledby="<?= $p ?>-t"<?= $open ? ' data-open' : '' ?>>
  <div class="modal-head"><div><h3 id="<?= $p ?>-t"><?= e($heading) ?></h3><?php if ($mode === 'series'): ?><p class="xs muted mb0">Changes apply to upcoming tasks, earlier ones stay as they are.</p><?php endif ?></div><button type="button" class="btn icon sm ghost" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div>
  <form class="modal-body form td-form<?= $mode === 'todo' ? ' td-fixed' : '' ?>" method="post" action="<?= e(url($action)) ?>"><?= csrf_field() ?><input type="hidden" name="_form" value="<?= $p ?>"><input type="hidden" name="return" value="<?= e($return) ?>">
    <div class="field"><label for="<?= $p ?>-title">Title *</label><input id="<?= $p ?>-title" type="text" name="title" required maxlength="190" value="<?= e($v['title'] ?? '') ?>" placeholder="What needs to be done?" autofocus><?= $f('title') ?></div>
    <div class="field"><label for="<?= $p ?>-desc">Description</label><textarea id="<?= $p ?>-desc" name="description" rows="2" maxlength="5000" placeholder="Details, links, checklist…"><?= e($v['description'] ?? '') ?></textarea><?= $f('description') ?></div>
    <div class="form-grid">
      <?php if (count($assignees) > 1): ?><div class="field"><label for="<?= $p ?>-who">Assign to</label><select id="<?= $p ?>-who" name="assignee_id"><?php foreach ($assignees as $aid => $an): ?><option value="<?= (int) $aid ?>"<?= selected($aid, $v['assignee_id'] ?? '') ?>><?= e($an) ?></option><?php endforeach ?></select><?= $f('assignee_id') ?></div>
      <?php else: ?><input type="hidden" name="assignee_id" value="<?= (int) array_key_first($assignees) ?>"><?php endif ?>
      <div class="field"><label for="<?= $p ?>-prio">Priority</label><select id="<?= $p ?>-prio" name="priority"><?php foreach (T::PRIORITIES as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($k, $v['priority'] ?? 'medium') ?>><?= e($l) ?></option><?php endforeach ?></select></div>
    </div>
    <?php if ($mode !== 'todo'): ?>
    <fieldset class="field td-fs"><legend>Repeat</legend>
      <div class="td-seg"><?php foreach ($reps as $k => $l): ?><label><input type="radio" name="repeat" value="<?= e($k) ?>"<?= checked($rep === $k) ?>><span><?= icon($k === 'once' ? 'check' : 'refresh', 'sm') ?> <?= e($l) ?></span></label><?php endforeach ?></div>
    </fieldset>
    <?php endif ?>
    <div class="td-rep" data-rep="once"><div class="field"><label for="<?= $p ?>-due">Due date</label><input id="<?= $p ?>-due" type="date" name="due_date" value="<?= e($v['due_date'] ?? '') ?>"><?= $f('due_date') ?><?php if ($mode === 'todo' && !empty($v['occurrence'])): ?><div class="hint">Part of a routine.</div><?php endif ?></div></div>
    <div class="td-rep" data-rep="daily"><label class="check"><input type="checkbox" name="workdays_only" value="1"<?= checked(!empty($v['workdays_only'])) ?>> Weekdays only (skip weekly off days)</label></div>
    <div class="td-rep" data-rep="weekly"><div class="field"><span class="td-lbl">On these days</span><div class="td-days"><?php foreach (T::DAYS as $n => $dn): ?><label><input type="checkbox" name="weekdays[]" value="<?= $n ?>"<?= checked(in_array($n, $days, true)) ?>><span><?= e($dn) ?></span></label><?php endforeach ?></div><?= $f('weekdays') ?></div></div>
    <div class="td-rep" data-rep="monthly"><div class="field"><label for="<?= $p ?>-md">Day of month</label><input id="<?= $p ?>-md" type="number" name="month_day" min="1" max="31" value="<?= (int) ($v['month_day'] ?? date('j')) ?>"><div class="hint">In shorter months it falls on the last day.</div><?= $f('month_day') ?></div></div>
    <div class="td-rep form-grid" data-rep="daily weekly monthly">
      <div class="field"><label for="<?= $p ?>-start">Start date</label><input id="<?= $p ?>-start" type="date" name="start_date" value="<?= e($v['start_date'] ?? date('Y-m-d')) ?>"><?= $f('start_date') ?></div>
      <div class="field"><label for="<?= $p ?>-end">End date</label><input id="<?= $p ?>-end" type="date" name="end_date" value="<?= e($v['end_date'] ?? '') ?>"><div class="hint">Optional, leave empty to repeat until paused.</div><?= $f('end_date') ?></div>
    </div>
    <div class="form-actions"><button type="button" class="btn ghost" data-close>Cancel</button><button class="btn primary"><?= icon($mode === 'new' ? 'plus' : 'check', 'sm') ?> <?= e($submit) ?></button></div>
  </form>
</dialog>
