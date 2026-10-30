<?php
use Modules\Admin\Services\BackupService;
$custom = in_array($tab, ['overview', 'appearance', 'email', 'retention', 'demo', 'features', 'users'], true);
$form = static fn (array $x = []) => partial('admin::settings/_form', $x + ['tab' => $tab, 'fields' => $fields, 'sections' => $sections]);
$info = static fn (string $ic, string $c, string $title, string $html) => '<div class="card info-card"><span class="ic-chip" style="--c:var(--'.$c.')">'.icon($ic).'</span><div class="grow"><h4>'.e($title).'</h4><div class="small muted">'.$html.'</div></div></div>';
?>
<div class="set-shell">
<?= partial('admin::settings/_nav', ['tabs' => $tabs, 'tab' => $tab]) ?>
<div class="set-main">
<?php if ($custom): ?><?= partial('admin::settings/'.$tab, get_defined_vars()) ?><?php else: ?>
<?php switch ($tab):
  case 'employees': ?>
  <?= partial('admin::settings/_head', ['tabs' => $tabs, 'tab' => $tab]) ?>
  <?= feature_on('employees') ? $info('id-card', 'mint', 'Per-employee schedules', 'Part-timers or six-day weeks? Open the employee\'s HR profile and set their own hours per day and weekly off days, those override the company defaults below. '.(can('hr.employees.view') ? '<a href="'.e(url('/hr/employees')).'">Open employees →</a>' : '')) : '' ?>
  <?= $form() ?>
<?php break; case 'leave': $fixed = (int) ($holidays[0] ?? 0); $opt = (int) ($holidays[1] ?? 0); ?>
  <?= partial('admin::settings/_head', ['tabs' => $tabs, 'tab' => $tab, 'actions' => '<a class="btn ghost" href="'.e(url('/hr/holidays')).'">'.icon('calendar', 'sm').' Holiday calendar</a>']) ?>
  <div class="grid g3 mini-stats">
    <div class="tile bg-peach"><span class="tile-ic"><?= icon('flag') ?></span><div><div class="num"><?= $fixed ?></div><div class="lbl">Fixed holidays in <?= date('Y') ?></div></div></div>
    <div class="tile bg-sky"><span class="tile-ic"><?= icon('umbrella') ?></span><div><div class="num"><?= $opt ?></div><div class="lbl">Optional festival days listed</div></div></div>
    <div class="tile bg-mint"><span class="tile-ic"><?= icon('gift') ?></span><div><div class="num"><?= $ohQuota !== null ? rtrim(rtrim((string) $ohQuota, '0'), '.') : '-' ?></div><div class="lbl">Optional holidays per person</div></div></div>
  </div>
  <?= $form() ?>
  <?= $info('info', 'sky', 'How holidays work', 'Fixed holidays (e.g. Republic Day, Diwali) are days off for everyone and skip working-day counts. Optional holidays are a personal pool: employees pick festival, personal or medical days and apply for them as <b>Optional Holiday (OH)</b> leave. Saving the allowance above also updates the OH leave type; per-employee quotas live on the employee profile.') ?>
<?php break; case 'attendance': ?>
  <?= partial('admin::settings/_head', ['tabs' => $tabs, 'tab' => $tab, 'actions' => '<a class="btn ghost" href="'.e(url('/time')).'">'.icon('timer', 'sm').' Time Tracking page</a>']) ?>
  <?= $form() ?>
<?php break; case 'payroll': ?>
  <?= partial('admin::settings/_head', ['tabs' => $tabs, 'tab' => $tab, 'actions' => can('payroll.manage') ? '<a class="btn ghost" href="'.e(url('/payroll/components')).'">'.icon('sliders', 'sm').' Pay components</a>' : '']) ?>
  <?= $form() ?>
  <?php if (feature_on('payroll')): ?><?= $info('wallet', 'pink', 'Salary structure lives in Payroll', 'Allowances, deductions and each employee\'s compensation are managed under Payroll.'.(feature_on('reimbursements') ? ' Reimbursement claims approved before a run are added to that month\'s payslips automatically.' : '')) ?><?php endif ?>
  <?php if (feature_on('reimbursements') && !feature_on('payroll')): ?><?= $info('receipt', 'pink', 'Expense claims', 'Everyone submits claims with a receipt under <b>Reimbursements</b>; HR approves or rejects them there. '.(can('payroll.reimbursements.approve') ? '<a href="'.e(url('/reimbursements?tab=queue')).'">Review claims →</a>' : '')) ?><?php endif ?>
<?php break; case 'projects': ?>
  <?= partial('admin::settings/_head', ['tabs' => $tabs, 'tab' => $tab, 'actions' => feature_on('projects') ? '<a class="btn ghost" href="'.e(url('/projects')).'">'.icon('folder', 'sm').' Open projects</a>' : '<a class="btn ghost" href="'.e(url('/work-reports')).'">'.icon('clipboard', 'sm').' Open work reports</a>']) ?>
  <div class="grid g2">
    <?= feature_on('projects') ? $info('bell', 'lav', 'Deadline reminders', 'Every day at <b>09:00</b> the scheduler sends assignees one digest of their tasks that are overdue, due today or due tomorrow. Make sure <a href="'.e(url('/admin/cron')).'">cron is running</a>.') : '' ?>
    <?= feature_on('work_reports') ? $info('clipboard', 'lemon', 'Work reports', 'Daily and weekly reports are reviewed by the reporting manager set on each user. Change managers under <a href="'.e(url('/admin/users')).'">Users</a>.') : '' ?>
    <?= feature_on('projects') ? $info('rupee', 'mint', 'Billing per project', 'Billing type (fixed, hourly, retainer or non-billable), hourly rate, budget and estimate are set on each project, so no global defaults are needed.') : '' ?>
    <?= $info('puzzle', 'sky', 'Turn parts on or off', (feature_on('projects') && feature_on('work_reports') ? 'Projects & tasks and Work reports are features' : (feature_on('projects') ? 'Projects & tasks is a feature' : 'Work reports is a feature')).' of the Team & Work bundle. <a href="'.e(url('/admin/settings/features')).'">Manage features →</a>') ?>
  </div>
<?php break; case 'invoicing': ?>
  <?= partial('admin::settings/_head', ['tabs' => $tabs, 'tab' => $tab, 'actions' => '<a class="btn ghost" href="'.e(url('/admin/settings/general')).'">'.icon('building', 'sm').' Company details</a>']) ?>
  <?= $form() ?>
  <div class="grid g2">
    <?= feature_on('invoices') ? $info('alert', 'peach', 'Overdue & reminders', 'At <b>00:20</b> unpaid invoices past their due date are marked overdue. At <b>09:10</b> each client\'s account managers get a digest of overdue invoices'.(feature_on('clients') ? ' and today\'s follow-ups' : '').'.') : '' ?>
    <?= $info('building', 'sky', 'Your company on documents', 'Address, billing email, phone and tax ID come from <a href="'.e(url('/admin/settings/general')).'">General › Company</a>; your logo from Branding.') ?>
  </div>
<?php break; default: ?>
  <?= partial('admin::settings/_head', ['tabs' => $tabs, 'tab' => $tab]) ?>
  <?= $form() ?>
<?php endswitch ?>
<?php endif ?>
</div>
</div>
