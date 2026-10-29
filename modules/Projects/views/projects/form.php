<?php
use Core\Modules;
use Modules\Projects\Services\ProjectService as PS;
$id = $pr['id']; $v = static fn (string $k, $d = '') => old($k, $pr[$k] ?? $d);
$sel = array_map('intval', (array) old('members', $memberIds));
$crm = Modules::enabled('crm');
$cur = e(currency_symbol());
$sec = static fn (string $ic, string $color, string $title, string $help) => '<div class="fsec-head ac-'.$color.'"><span class="sec-ic">'.icon($ic).'</span><div><h3>'.e($title).'</h3><p>'.e($help).'</p></div></div>';
?>
<div class="pj">
<?= partial('partials/page_head', ['title' => $id ? 'Edit project' : 'New project', 'subtitle' => $id ? 'Update the plan, budget and team for '.$pr['name'].'.' : 'Set up the scope, team and budget — everything can be changed later.', 'crumbs' => [['Work'], ['Projects', '/projects'], $id ? [$pr['name'], '/projects/'.$id] : ['New project']]]) ?>
<form method="post" action="<?= e(url($id ? '/projects/'.$id : '/projects')) ?>"><?= csrf_field() ?>
  <div class="grid g-side">
    <div class="stack">
      <section class="card form">
        <?= $sec('folder', 'lav', 'Basics', 'Name the project and link it to a client.') ?>
        <div class="form-grid">
          <div class="field full<?= error_for('name') ? ' has-error' : '' ?>"><label for="p-name">Project name *</label><input id="p-name" type="text" name="name" value="<?= e($v('name')) ?>" required maxlength="190" placeholder="e.g. Website redesign" autofocus><?= error_for('name') ?></div>
          <?php if ($crm): ?>
          <div class="field<?= error_for('client_id') ? ' has-error' : '' ?>"><label for="p-client">Client</label><select id="p-client" name="client_id"><option value="">Internal / no client</option><?php foreach ($clients as $cid => $cn): ?><option value="<?= (int) $cid ?>"<?= selected($cid, $v('client_id')) ?>><?= e($cn) ?></option><?php endforeach ?></select><div class="hint">Leave empty for internal work.</div><?= error_for('client_id') ?></div>
          <?php else: ?><input type="hidden" name="client_id" value="<?= e($v('client_id')) ?>"><?php endif ?>
          <div class="field<?= error_for('code') ? ' has-error' : '' ?>"><label for="p-code">Project code</label><input id="p-code" type="text" name="code" value="<?= e($v('code')) ?>" maxlength="30" placeholder="e.g. WEB-24"><div class="hint">Short reference — letters, numbers, dot, dash or underscore.</div><?= error_for('code') ?></div>
        </div>
      </section>
      <section class="card form">
        <?= $sec('calendar', 'sky', 'Schedule & status', 'Dates drive deadline reminders and the countdown on project cards.') ?>
        <div class="form-grid">
          <div class="field"><label for="p-status">Status</label><select id="p-status" name="status"><?php foreach (PS::STATUSES as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($k, $v('status')) ?>><?= e($l) ?></option><?php endforeach ?></select></div>
          <div class="field"><label for="p-prio">Priority</label><select id="p-prio" name="priority"><?php foreach (PS::PRIORITIES as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($k, $v('priority')) ?>><?= e($l) ?></option><?php endforeach ?></select></div>
          <div class="field<?= error_for('start_date') ? ' has-error' : '' ?>"><label for="p-start">Start date</label><input id="p-start" type="date" name="start_date" value="<?= e($v('start_date')) ?>"><?= error_for('start_date') ?></div>
          <div class="field<?= error_for('deadline') ? ' has-error' : '' ?>"><label for="p-deadline">Deadline</label><input id="p-deadline" type="date" name="deadline" value="<?= e($v('deadline')) ?>"><?= error_for('deadline') ?></div>
        </div>
      </section>
      <section class="card form">
        <?= $sec('target', 'mint', 'Scope & deliverables', 'What the team is building — shown on the project overview.') ?>
        <div class="form-grid">
          <div class="field full"><label for="p-desc">Description</label><textarea id="p-desc" name="description" rows="3" placeholder="What is this project about?"><?= e($v('description')) ?></textarea></div>
          <div class="field"><label for="p-scope">Scope</label><textarea id="p-scope" name="scope" rows="5" placeholder="What's in (and out of) scope"><?= e($v('scope')) ?></textarea></div>
          <div class="field"><label for="p-deliv">Deliverables</label><textarea id="p-deliv" name="deliverables" rows="5" placeholder="One deliverable per line"><?= e($v('deliverables')) ?></textarea><div class="hint">Each line becomes a checklist item.</div></div>
        </div>
      </section>
      <section class="card form">
        <?= $sec('wallet', 'lemon', 'Budget & billing', 'Budget usage is calculated from billable hours × hourly rate.') ?>
        <div class="form-grid">
          <div class="field"><label for="p-bill">Billing type</label><select id="p-bill" name="billing_type"><?php foreach (PS::BILLING as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($k, $v('billing_type')) ?>><?= e($l) ?></option><?php endforeach ?></select></div>
          <div class="field<?= error_for('budget') ? ' has-error' : '' ?>"><label for="p-budget">Budget (<?= $cur ?>)</label><input id="p-budget" type="number" step="0.01" min="0" name="budget" value="<?= e($v('budget')) ?>" placeholder="0.00"><?= error_for('budget') ?></div>
          <div class="field<?= error_for('hourly_rate') ? ' has-error' : '' ?>"><label for="p-rate">Hourly rate (<?= $cur ?>)</label><input id="p-rate" type="number" step="0.01" min="0" name="hourly_rate" value="<?= e($v('hourly_rate')) ?>" placeholder="0.00"><?= error_for('hourly_rate') ?></div>
          <div class="field<?= error_for('estimated_hours') ? ' has-error' : '' ?>"><label for="p-est">Estimated hours</label><input id="p-est" type="number" step="0.25" min="0" name="estimated_hours" value="<?= e($v('estimated_hours')) ?>" placeholder="e.g. 120"><div class="hint">Leave empty to use the sum of task estimates.</div><?= error_for('estimated_hours') ?></div>
        </div>
      </section>
      <section class="card form">
        <div class="flex between wrap"><?= $sec('users', 'pink', 'Team', 'Members see the project and can be assigned tasks.') ?><input type="search" class="member-search" placeholder="Filter people…" data-filter-list="#member-pick" aria-label="Filter people"></div>
        <div class="field<?= error_for('manager_id') ? ' has-error' : '' ?>"><label for="p-mgr">Project manager</label><select id="p-mgr" name="manager_id"><option value="">None</option><?php foreach ($users as $uid => $un): ?><option value="<?= (int) $uid ?>"<?= selected($uid, $v('manager_id')) ?>><?= e($un) ?></option><?php endforeach ?></select><div class="hint">The manager always has access and reviews tasks sent for review.</div><?= error_for('manager_id') ?></div>
        <div><label class="lbl">Members <span class="muted">(<?= count($sel) ?> selected)</span></label>
          <div class="member-pick" id="member-pick"><?php foreach ($users as $uid => $un): ?>
            <label class="mp-item" data-name="<?= e(mb_strtolower($un)) ?>"><input type="checkbox" name="members[]" value="<?= (int) $uid ?>"<?= checked(in_array((int) $uid, $sel, true)) ?>><?= avatar($un, null, 'sm') ?><span class="truncate"><?= e($un) ?></span></label>
          <?php endforeach ?></div>
        </div>
      </section>
    </div>
    <aside class="stack">
      <section class="card">
        <h4>Colour</h4>
        <p class="xs muted">Used for the project card, board and dashboard.</p>
        <div class="swatches"><?php foreach (PS::COLORS as $c): ?><label class="swatch ac-<?= e($c) ?>" title="<?= e(ucfirst($c)) ?>"><input type="radio" name="color" value="<?= e($c) ?>"<?= checked($v('color') === $c) ?> aria-label="<?= e(ucfirst($c)) ?>"><span><?= icon('check', 'sm') ?></span></label><?php endforeach ?></div>
      </section>
      <section class="card side-tip">
        <?= illus('kanban-board') ?>
        <h4>How progress works</h4>
        <p class="small muted mb0">Break work into <b>milestones</b>, then add tasks on the <b>Board</b>. Progress updates automatically as tasks reach <b>Done</b><?= PS::on('time') ? ', and time tracked on tasks shows up as actual hours' : '' ?>.</p>
      </section>
    </aside>
  </div>
  <div class="form-bar">
    <span class="hint">Fields marked * are required.</span>
    <a class="btn ghost" href="<?= e(url($id ? '/projects/'.$id : '/projects')) ?>">Cancel</a>
    <button class="btn primary"><?= icon('check', 'sm') ?> <?= $id ? 'Save changes' : 'Create project' ?></button>
  </div>
</form>
</div>
