<?php
use Modules\Projects\Services\ProjectService as PS;
$id = $pr['id']; $v = static fn (string $k, $d = '') => old($k, $pr[$k] ?? $d);
$sel = array_map('intval', (array) old('members', $memberIds));
?>
<?= partial('partials/page_head', ['title' => $id ? 'Edit project' : 'New project', 'subtitle' => $id ? $pr['name'] : 'Set up the scope, team and budget — you can change everything later.', 'crumbs' => [['Projects', '/projects'], $id ? [$pr['name'], '/projects/'.$id] : ['New']]]) ?>
<form class="grid g-side" method="post" action="<?= e(url($id ? '/projects/'.$id : '/projects')) ?>"><?= csrf_field() ?>
  <div class="stack">
    <div class="card form">
      <h3>Basics</h3>
      <div class="form-grid">
        <div class="field full"><label>Project name *</label><input type="text" name="name" value="<?= e($v('name')) ?>" required maxlength="190" placeholder="e.g. Website redesign"><?= error_for('name') ?></div>
        <div class="field"><label>Client</label><select name="client_id"><option value="">Internal / no client</option><?php foreach ($clients as $cid => $cn): ?><option value="<?= (int) $cid ?>"<?= selected($cid, $v('client_id')) ?>><?= e($cn) ?></option><?php endforeach ?></select><?= error_for('client_id') ?></div>
        <div class="field"><label>Project code</label><input type="text" name="code" value="<?= e($v('code')) ?>" maxlength="30" placeholder="e.g. WEB-24"><div class="hint">Letters, numbers, dot, dash or underscore.</div><?= error_for('code') ?></div>
        <div class="field"><label>Status</label><select name="status"><?php foreach (PS::STATUSES as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($k, $v('status')) ?>><?= e($l) ?></option><?php endforeach ?></select></div>
        <div class="field"><label>Priority</label><select name="priority"><?php foreach (PS::PRIORITIES as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($k, $v('priority')) ?>><?= e($l) ?></option><?php endforeach ?></select></div>
        <div class="field"><label>Start date</label><input type="date" name="start_date" value="<?= e($v('start_date')) ?>"><?= error_for('start_date') ?></div>
        <div class="field"><label>Deadline</label><input type="date" name="deadline" value="<?= e($v('deadline')) ?>"><?= error_for('deadline') ?></div>
        <div class="field full"><label>Description</label><textarea name="description" rows="3" placeholder="What is this project about?"><?= e($v('description')) ?></textarea></div>
        <div class="field"><label>Scope</label><textarea name="scope" rows="5" placeholder="What's in (and out of) scope"><?= e($v('scope')) ?></textarea></div>
        <div class="field"><label>Deliverables</label><textarea name="deliverables" rows="5" placeholder="One deliverable per line"><?= e($v('deliverables')) ?></textarea></div>
      </div>
    </div>
    <div class="card form">
      <h3>Budget &amp; billing</h3>
      <div class="form-grid g3">
        <div class="field"><label>Billing type</label><select name="billing_type"><?php foreach (PS::BILLING as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($k, $v('billing_type')) ?>><?= e($l) ?></option><?php endforeach ?></select></div>
        <div class="field"><label>Budget (<?= e(currency_symbol()) ?>)</label><input type="number" step="0.01" min="0" name="budget" value="<?= e($v('budget')) ?>"><?= error_for('budget') ?></div>
        <div class="field"><label>Hourly rate (<?= e(currency_symbol()) ?>)</label><input type="number" step="0.01" min="0" name="hourly_rate" value="<?= e($v('hourly_rate')) ?>"><?= error_for('hourly_rate') ?></div>
        <div class="field"><label>Estimated hours</label><input type="number" step="0.25" min="0" name="estimated_hours" value="<?= e($v('estimated_hours')) ?>"><?= error_for('estimated_hours') ?></div>
      </div>
    </div>
    <div class="card form">
      <div class="card-head"><h3>Team</h3><input type="search" class="member-search" placeholder="Filter people…" data-filter-list="#member-pick" aria-label="Filter people"></div>
      <div class="field"><label>Project manager</label><select name="manager_id"><option value="">None</option><?php foreach ($users as $uid => $un): ?><option value="<?= (int) $uid ?>"<?= selected($uid, $v('manager_id')) ?>><?= e($un) ?></option><?php endforeach ?></select><?= error_for('manager_id') ?></div>
      <div><label class="lbl">Members</label>
        <div class="member-pick" id="member-pick"><?php foreach ($users as $uid => $un): ?>
          <label class="mp-item" data-name="<?= e(mb_strtolower($un)) ?>"><input type="checkbox" name="members[]" value="<?= (int) $uid ?>"<?= checked(in_array((int) $uid, $sel, true)) ?>><?= avatar($un, null, 'sm') ?><span class="truncate"><?= e($un) ?></span></label>
        <?php endforeach ?></div>
        <div class="hint">Members see the project and can be assigned tasks. The manager always has access.</div>
      </div>
    </div>
    <div class="form-actions"><a class="btn ghost" href="<?= e(url($id ? '/projects/'.$id : '/projects')) ?>">Cancel</a><button class="btn primary"><?= icon('check', 'sm') ?> <?= $id ? 'Save changes' : 'Create project' ?></button></div>
  </div>
  <div class="stack">
    <div class="card">
      <h4>Colour</h4>
      <p class="xs muted">Used for the project card, board and calendars.</p>
      <div class="swatches"><?php foreach (PS::COLORS as $c): ?><label class="swatch bg-<?= e($c) ?>" title="<?= e(ucfirst($c)) ?>"><input type="radio" name="color" value="<?= e($c) ?>"<?= checked($v('color') === $c) ?>><span><?= icon('check', 'sm') ?></span></label><?php endforeach ?></div>
    </div>
    <div class="card soft-lav side-tip"><?= illus('kanban-board') ?><h4 class="mt">Tips</h4><p class="small mb0">Break work into <b>milestones</b>, then add tasks on the <b>Board</b>. Progress updates automatically as tasks move to <b>Done</b>, and logged time from timesheets shows up as actual hours.</p></div>
  </div>
</form>
