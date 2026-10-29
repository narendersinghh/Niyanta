<?php
use Modules\Admin\Services\DemoData;
$total = array_sum($demo);
$L = DemoData::LABELS;
$on = static fn (string $f) => feature_on($f);
$J = [\Modules\Admin\Services\SettingsSchema::class, 'join'];
$about = $J(array_filter([$on('employees') || $on('leave') ? '8 people'.($on('employees') ? ' with schedules' : '').($on('leave') ? ' and leave, holidays' : '') : '8 people', $on('company_docs') ? 'company documents' : null,
  $on('clients') ? '6 clients with their journey'.($on('quotations') ? ', quotations' : '').($on('invoices') ? ', invoices and payments' : '') : null, $on('projects') ? '5 projects with ~40 tasks' : null, $on('attendance') ? 'three weeks of attendance and time entries' : null,
  $on('payroll') ? 'payroll' : null, $on('reimbursements') ? 'reimbursements' : null]));
$cards = [];
if (\Core\Features::bundleEnabled('people')) { $cards[] = ['mint', 'users', 'HR & Employee Management', ucfirst($J(array_filter(['profiles'.($on('employees') ? ' with 8–9 h days, a six-day week' : ''), $on('leave') ? 'optional holidays, leave requests' : null, $on('attendance') ? 'attendance' : null, $on('payroll') ? 'compensation, a draft payroll run' : null, $on('reimbursements') ? 'expense claims' : null]))).'.']; }
if (\Core\Features::bundleEnabled('work') && ($on('projects') || $on('work_reports'))) { $cards[] = ['lav', 'kanban', 'Team & Work', ucfirst($J(array_filter([$on('projects') ? 'five projects in different stages with members, milestones, tasks across every status, dependencies, comments, change requests' : null, $on('work_reports') ? 'work reports' : null]))).'.']; }
if ($on('clients')) { $cards[] = ['peach', 'briefcase', 'Client Management', ucfirst($J(array_filter(['six clients with contacts, managers, tags and follow-ups', $on('quotations') ? 'quotations' : null, $on('invoices') ? 'invoices with partial payments and overdue balances' : null]))).'.']; }
?>
<?= partial('admin::settings/_head', ['tabs' => $tabs, 'tab' => 'demo', 'actions' => $total ? '<button class="btn danger" data-modal="demo-delete">'.icon('trash', 'sm').' Delete demo data</button>' : '<form method="post" action="'.e(url('/admin/settings/demo/import')).'" data-no-lock="1" data-busy="Importing…" data-confirm="Import the demo agency (8 people, 6 clients, 5 projects and more) into this workspace?">'.csrf_field().'<button class="btn primary">'.icon('download', 'sm').' Import demo data</button></form>']) ?>
<?php if ($password): ?><div class="alert ok demo-pass"><?= icon('key', 'sm') ?><div><b>Demo data is ready.</b> Every demo user signs in with the password <code class="mono"><?= e($password) ?></code>, e.g. <code>aarav@<?= e(DemoData::DOMAIN) ?></code> (manager), <code>priya@<?= e(DemoData::DOMAIN) ?></code> (HR), <code>vikram@<?= e(DemoData::DOMAIN) ?></code> (employee). This password is shown only once.</div></div><?php endif ?>
<div class="card set-hero<?= $total ? ' is-loaded' : '' ?>">
  <div class="grow">
    <span class="pill pill-<?= $total ? 'lemon' : 'gray' ?>"><?= $total ? number_format($total).' demo records loaded' : 'No demo data' ?></span>
    <h2><?= $total ? 'Exploring with the demo agency' : 'Try Niyanta with a realistic sample agency' ?></h2>
    <p class="muted small"><?= $total ? 'Imported '.e(!empty($demoMeta['at']) ? ago($demoMeta['at']) : 'earlier').'. Demo rows are tracked one by one, so deleting removes exactly them, anything you created yourself stays.' : 'A coherent Indian digital agency across every enabled feature: '.e($about).'.' ?></p>
    <ul class="demo-facts small"><li><?= icon('users', 'sm') ?> Users <code>…@<?= e(DemoData::DOMAIN) ?></code></li><li><?= icon('puzzle', 'sm') ?> Only enabled features</li><li><?= icon('shield', 'sm') ?> Real data is never touched</li></ul>
  </div>
  <?= illus('demo-data', 'set-hero-illus') ?>
</div>
<?php if ($total): ?>
<div class="card"><div class="card-head"><h3>What was imported</h3><span class="xs muted">Rows recorded in demo_records</span></div>
  <div class="demo-grid"><?php foreach (array_filter($demo, static fn ($t) => !isset(DemoData::FEATURE[$t]) || feature_on(DemoData::FEATURE[$t]), ARRAY_FILTER_USE_KEY) as $t => $n): [$label, $ic, $c] = $L[$t] ?? [ucwords(str_replace('_', ' ', $t)), 'layers', 'lav']; ?><div class="demo-c"><span class="ic-chip sm" style="--c:var(--<?= e($c) ?>)"><?= icon($ic, 'sm') ?></span><div><b><?= number_format($n) ?></b><span class="xs muted"><?= e($label) ?></span></div></div><?php endforeach ?></div>
  <p class="xs muted mt mb0"><?= e(ucfirst($J(array_filter([$on('projects') ? 'memberships' : null, $on('clients') ? 'client managers & tags' : null, $on('projects') ? 'task dependencies' : null, $on('leave') ? 'leave balances' : null, $on('company_docs') ? 'document acknowledgements' : null])) ?: 'Linked rows')) ?> belong to these records and are removed with them.</p></div>
<?php else: ?>
<div class="grid g3">
  <?php foreach ($cards as [$c, $ic, $h, $txt]): ?><div class="card info-card"><span class="ic-chip" style="--c:var(--<?= e($c) ?>)"><?= icon($ic) ?></span><div><h4><?= e($h) ?></h4><p class="small muted mb0"><?= e($txt) ?></p></div></div><?php endforeach ?>
</div>
<?php endif ?>
<dialog class="modal" id="demo-delete"><div class="modal-head"><h3>Delete demo data</h3><button class="btn icon sm ghost" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div>
<form class="modal-body form" method="post" action="<?= e(url('/admin/settings/demo/delete')) ?>" data-typed="DELETE"><?= csrf_field() ?>
  <div class="alert error mb0"><?= icon('alert', 'sm') ?><div>Permanently removes the <b><?= number_format($total) ?></b> demo records and their generated files. Anything you added to demo <?= e($J(array_filter([$on('clients') ? 'clients' : null, $on('projects') ? 'projects' : null])) ?: 'records') ?> is removed with them.</div></div>
  <div class="field"><label for="demo-confirm">Type <b>DELETE</b> to confirm</label><input id="demo-confirm" type="text" class="confirm-input" name="confirm" autocomplete="off" data-typed-input placeholder="DELETE"></div>
  <div class="form-actions"><button type="button" class="btn ghost" data-close>Cancel</button><button type="submit" class="btn danger"><?= icon('trash', 'sm') ?> Delete demo data</button></div>
</form></dialog>
