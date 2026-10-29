<?php
use Modules\Admin\Services\DemoData;
$total = array_sum($demo);
$L = DemoData::LABELS;
?>
<?= partial('admin::settings/_head', ['tabs' => $tabs, 'tab' => 'demo', 'actions' => $total ? '<button class="btn danger" data-modal="demo-delete">'.icon('trash', 'sm').' Delete demo data</button>' : '<form method="post" action="'.e(url('/admin/settings/demo/import')).'" data-no-lock="1" data-busy="Importing…" data-confirm="Import the demo agency (8 people, 6 clients, 5 projects and more) into this workspace?">'.csrf_field().'<button class="btn primary">'.icon('download', 'sm').' Import demo data</button></form>']) ?>
<?php if ($password): ?><div class="alert ok demo-pass"><?= icon('key', 'sm') ?><div><b>Demo data is ready.</b> Every demo user signs in with the password <code class="mono"><?= e($password) ?></code> — e.g. <code>aarav@<?= e(DemoData::DOMAIN) ?></code> (manager), <code>priya@<?= e(DemoData::DOMAIN) ?></code> (HR), <code>vikram@<?= e(DemoData::DOMAIN) ?></code> (employee). This password is shown only once.</div></div><?php endif ?>
<div class="card set-hero<?= $total ? ' is-loaded' : '' ?>">
  <div class="grow">
    <span class="pill pill-<?= $total ? 'lemon' : 'gray' ?>"><?= $total ? number_format($total).' demo records loaded' : 'No demo data' ?></span>
    <h2><?= $total ? 'Exploring with the demo agency' : 'Try Niyanta with a realistic sample agency' ?></h2>
    <p class="muted small"><?= $total ? 'Imported '.e(!empty($demoMeta['at']) ? ago($demoMeta['at']) : 'earlier').'. Demo rows are tracked one by one, so deleting removes exactly them — anything you created yourself stays.' : 'A coherent Indian digital agency across every enabled bundle: 8 people with schedules and leave, holidays, company documents, 6 clients with their journey, quotations, invoices and payments, 5 projects with ~40 tasks, three weeks of attendance and time entries, payroll and reimbursements.' ?></p>
    <ul class="demo-facts small"><li><?= icon('users', 'sm') ?> Users <code>…@<?= e(DemoData::DOMAIN) ?></code></li><li><?= icon('puzzle', 'sm') ?> Only enabled features</li><li><?= icon('shield', 'sm') ?> Real data is never touched</li></ul>
  </div>
  <?= illus('demo-data', 'set-hero-illus') ?>
</div>
<?php if ($total): ?>
<div class="card"><div class="card-head"><h3>What was imported</h3><span class="xs muted">Rows recorded in demo_records</span></div>
  <div class="demo-grid"><?php foreach ($demo as $t => $n): [$label, $ic, $c] = $L[$t] ?? [ucwords(str_replace('_', ' ', $t)), 'layers', 'lav']; ?><div class="demo-c"><span class="ic-chip sm" style="--c:var(--<?= e($c) ?>)"><?= icon($ic, 'sm') ?></span><div><b><?= number_format($n) ?></b><span class="xs muted"><?= e($label) ?></span></div></div><?php endforeach ?></div>
  <p class="xs muted mt mb0">Memberships, client managers & tags, task dependencies, leave balances and document acknowledgements belong to these records and are removed with them.</p></div>
<?php else: ?>
<div class="grid g3">
  <div class="card info-card"><span class="ic-chip" style="--c:var(--mint)"><?= icon('users') ?></span><div><h4>HR & Employee Management</h4><p class="small muted mb0">Profiles with 8–9 h days, a six-day week, optional holidays, leave requests, attendance, compensation, a draft payroll run and expense claims.</p></div></div>
  <div class="card info-card"><span class="ic-chip" style="--c:var(--lav)"><?= icon('kanban') ?></span><div><h4>Team & Work</h4><p class="small muted mb0">Five projects in different stages with members, milestones, tasks across every status, dependencies, comments, change requests and work reports.</p></div></div>
  <div class="card info-card"><span class="ic-chip" style="--c:var(--peach)"><?= icon('briefcase') ?></span><div><h4>Client Management</h4><p class="small muted mb0">Six clients with contacts, managers, tags and follow-ups; quotations; invoices with partial payments and overdue balances.</p></div></div>
</div>
<?php endif ?>
<dialog class="modal" id="demo-delete"><div class="modal-head"><h3>Delete demo data</h3><button class="btn icon sm ghost" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div>
<form class="modal-body form" method="post" action="<?= e(url('/admin/settings/demo/delete')) ?>" data-typed="DELETE"><?= csrf_field() ?>
  <div class="alert error mb0"><?= icon('alert', 'sm') ?><div>Permanently removes the <b><?= number_format($total) ?></b> demo records and their generated files. Anything you added to demo clients or projects (comments, payments, files) is removed with them.</div></div>
  <div class="field"><label for="demo-confirm">Type <b>DELETE</b> to confirm</label><input id="demo-confirm" type="text" class="confirm-input" name="confirm" autocomplete="off" data-typed-input placeholder="DELETE"></div>
  <div class="form-actions"><button type="button" class="btn ghost" data-close>Cancel</button><button type="submit" class="btn danger"><?= icon('trash', 'sm') ?> Delete demo data</button></div>
</form></dialog>
