<?php
/* HR form to start an onboarding or exit. $emp, $kind, $list (templates of that kind) */
use Modules\Hr\Services\ProcessService as PS;
$eid = (int) $emp['id']; $exit = $kind === 'exit'; $f = 'st-'.$kind;
$def = array_values(array_filter($list, static fn ($t) => (int) $t['is_default']))[0]['id'] ?? ($list[0]['id'] ?? null);
?>
<form class="form hr-start" method="post" action="<?= e(url('/hr/employees/'.$eid.'/process/'.$kind)) ?>" data-confirm="<?= e($exit ? 'Start the exit of '.$emp['name'].'? They are notified and see their exit tasks. With automatic deactivation ticked, the account is deactivated at the end of the last working day.' : 'Start onboarding for '.$emp['name'].'? They are notified and can upload their documents right away.') ?>"><?= csrf_field() ?>
  <?php if (!$list): ?><div class="alert warn mb0"><?= icon('alert', 'sm') ?> No <?= $exit ? 'exit' : 'onboarding' ?> template yet. <a href="<?= e(url('/hr/process-templates')) ?>">Create one</a>.</div><?php else: ?>
  <div class="form-grid">
    <div class="field"><label for="<?= $f ?>-t">Template</label><select id="<?= $f ?>-t" name="template_id" required><?php foreach ($list as $t): ?><option value="<?= (int) $t['id'] ?>"<?= selected($t['id'], old('template_id', $def)) ?>><?= e($t['name']) ?> (<?= (int) $t['tasks'] ?> tasks)</option><?php endforeach ?></select><?= error_for('template_id') ?></div>
    <?php if ($exit): ?>
    <div class="field"><label for="<?= $f ?>-x">Exit type *</label><select id="<?= $f ?>-x" name="exit_type" required><?php foreach (PS::EXIT_TYPES as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($k, old('exit_type', 'resignation')) ?>><?= e($l) ?></option><?php endforeach ?></select><?= error_for('exit_type') ?></div>
    <div class="field"><label for="<?= $f ?>-l">Last working day *</label><input id="<?= $f ?>-l" type="date" name="last_working_day" value="<?= e(old('last_working_day', date('Y-m-d', strtotime('+30 days')))) ?>" required><div class="hint">Task due dates are counted from this day.</div><?= error_for('last_working_day') ?></div>
    <div class="field"><label for="<?= $f ?>-n">Reason / note</label><input id="<?= $f ?>-n" name="note" maxlength="1000" value="<?= e(old('note')) ?>" placeholder="e.g. Moving to another city"></div>
    <label class="check small full"><input type="checkbox" name="deactivate_on_lwd" value="1"<?= checked(\Core\Settings::bool('hr.exit_auto_deactivate', true)) ?>> Deactivate the account automatically at the end of the last working day</label>
    <?php else: ?>
    <div class="field"><label for="<?= $f ?>-s">Start date</label><input id="<?= $f ?>-s" type="date" name="start_date" value="<?= e(old('start_date', $emp['joined_on'] ?: date('Y-m-d'))) ?>"><div class="hint">Task due dates are counted from this day.</div><?= error_for('start_date') ?></div>
    <div class="field full"><label for="<?= $f ?>-n">Note</label><input id="<?= $f ?>-n" name="note" maxlength="1000" value="<?= e(old('note')) ?>" placeholder="Optional, visible to HR and the manager"></div>
    <?php endif ?>
  </div>
  <div class="form-actions"><button class="btn primary"><?= icon($exit ? 'log-out' : 'flag', 'sm') ?> Start <?= $exit ? 'exit' : 'onboarding' ?></button></div>
  <?php endif ?>
</form>
