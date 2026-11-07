<?php $v = static fn (string $k) => old($k, $prof[$k] ?? ''); $id = (int) $emp['id']; $acct = can('admin.users.manage') && ($emp['role_slug'] !== 'super_admin' || (user()['role_slug'] ?? '') === 'super_admin'); ?>
<?= partial('partials/page_head', ['title' => 'Edit HR profile', 'subtitle' => $emp['name'].' · '.($emp['designation'] ?: $emp['email']), 'crumbs' => [['HR Admin'], ['Employees', '/hr/employees'], [$emp['name'], '/hr/employees/'.$id], ['Edit']],
  'actions' => $acct ? '<a class="btn ghost" href="'.e(url('/admin/users/'.$id.'/edit')).'">'.icon('settings', 'sm').' Account, role & manager</a>' : '']) ?>
<nav class="tabs mb" aria-label="Employee pages"><?php if ($acct): ?><a href="<?= e(url('/admin/users/'.$id.'/edit')) ?>"><?= icon('settings', 'sm') ?> Account, role &amp; manager</a><?php endif ?><a class="active" aria-current="page" href="<?= e(url('/hr/employees/'.$id.'/edit')) ?>"><?= icon('id-card', 'sm') ?> HR profile</a><a href="#work"><?= icon('clock', 'sm') ?> <?= $payEdit ? 'Work &amp; pay' : 'Work hours' ?></a><a href="<?= e(url('/hr/employees/'.$id)) ?>"><?= icon('user', 'sm') ?> View profile</a></nav>
<form class="hr-form" method="post" action="<?= e(url('/hr/employees/'.$id)) ?>" autocomplete="off"><?= csrf_field() ?>
  <div class="card pad0">
    <div class="hr-sec">
      <div class="hr-sec-t"><h3><?= icon('briefcase', 'sm') ?> Employment</h3><p>Contract type and probation.</p></div>
      <div class="form-grid">
        <div class="field"><label for="f-type">Employment type *</label><select id="f-type" name="employment_type" required><?php foreach ($types as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($k, $v('employment_type') ?: 'full_time') ?>><?= e($l) ?></option><?php endforeach ?></select><?= error_for('employment_type') ?></div>
        <div class="field"><label for="f-prob">Probation ends</label><input id="f-prob" type="date" name="probation_end" value="<?= e($v('probation_end')) ?>"><div class="hint">Shown as a badge on the profile until this date.</div><?= error_for('probation_end') ?></div>
      </div>
    </div>
    <?php $LS = \Modules\Hr\Services\LeaveService::class; $daysMode = old('days_mode', $schedule['custom_days'] ? 'custom' : 'default'); $offSel = array_map('intval', (array) old('off_days', $schedule['off_days'])); ?>
    <div class="hr-sec" id="work">
      <div class="hr-sec-t"><h3><?= icon('clock', 'sm') ?> Working hours</h3><p>Hours per day and weekly off days used for leave, attendance targets and pay. Leave hours empty to follow the company default.</p></div>
      <div class="form-grid">
        <div class="field"><label for="f-hpd">Hours per day</label><input id="f-hpd" type="number" name="work_hours_per_day" step="0.25" min="0.5" max="16" value="<?= e(old('work_hours_per_day', $schedule['custom_hours'] ? $LS::num($schedule['hours_per_day']) : '')) ?>" placeholder="Company default: <?= e($LS::num($companyHours)) ?> h"><?= error_for('work_hours_per_day') ?></div>
        <div class="field"><label for="f-dm">Weekly off days</label><select id="f-dm" name="days_mode" data-days-mode><option value="default"<?= selected('default', $daysMode) ?>>Company default (<?= e($LS::daysLabel($companyDays, \Core\WorkWeek::companyRule())) ?>)</option><option value="custom"<?= selected('custom', $daysMode) ?>>Custom for this employee</option></select></div>
        <div class="field full" data-days-custom<?= $daysMode === 'custom' ? '' : ' hidden' ?>><span class="lbl">Days off</span><div class="hr-days" role="group" aria-label="Weekly off days"><?php foreach ($LS::DAY_NAMES as $n => $dn): ?><label><input type="checkbox" name="off_days[]" value="<?= $n ?>"<?= checked(in_array($n, $offSel, true)) ?>><span><?= e($dn) ?></span></label><?php endforeach ?></div><?= error_for('off_days') ?>
          <div class="field mt"><label for="f-sat">Saturdays</label><select id="f-sat" name="saturday_rule"><?php foreach (\Core\WorkWeek::RULES as $rk => $rl): ?><option value="<?= e($rk) ?>"<?= selected($rk, old('saturday_rule', $schedule['saturday'] ?? 'full')) ?>><?= e($rl) ?></option><?php endforeach ?></select><div class="hint">Applies when Saturday is not ticked as a day off.</div><?= error_for('saturday_rule') ?></div></div>
      </div>
    </div>
    <?php if ($payEdit): ?>
    <div class="hr-sec">
      <div class="hr-sec-t"><h3><?= icon('wallet', 'sm') ?> Salary</h3><p><?= $pay ? 'Current: '.e(money($pay['amount'])).' '.($pay['pay_type'] === 'hourly' ? 'per hour' : 'per month').' since '.e(fdate($pay['effective_from'])).'. Changing it adds a new revision from the date you choose; earlier payslips are not changed.' : 'No pay set yet. Payroll uses this to calculate payslips.' ?></p></div>
      <div class="form-grid g3">
        <div class="field"><label for="f-pt">Pay type</label><select id="f-pt" name="pay_type"><option value="monthly"<?= selected('monthly', old('pay_type', $pay['pay_type'] ?? 'monthly')) ?>>Monthly salary</option><option value="hourly"<?= selected('hourly', old('pay_type', $pay['pay_type'] ?? 'monthly')) ?>>Hourly rate</option></select><?= error_for('pay_type') ?></div>
        <div class="field"><label for="f-pa">Amount</label><input id="f-pa" type="number" name="pay_amount" step="0.01" min="0" value="<?= e(old('pay_amount', $pay ? rtrim(rtrim(number_format((float) $pay['amount'], 2, '.', ''), '0'), '.') : '')) ?>" placeholder="e.g. 15700"><?= error_for('pay_amount') ?></div>
        <div class="field"><label for="f-pe2">Applies from</label><input id="f-pe2" type="date" name="pay_effective" value="<?= e(old('pay_effective', date('Y-m-01'))) ?>"><?= error_for('pay_effective') ?></div>
      </div>
    </div>
    <?php endif ?>
    <?php if ($leaveEdit && $balances): ?>
    <div class="hr-sec">
      <div class="hr-sec-t"><h3><?= icon('umbrella', 'sm') ?> Leave balance <?= (int) $year ?></h3><p>Set the days available this year for each leave type. Used days are kept; the employee is notified when a balance changes.</p></div>
      <div class="table-wrap"><table class="table">
        <thead><tr><th>Leave type</th><th class="num">Allocated</th><th class="num">Used</th><th class="num">Left</th></tr></thead>
        <tbody><?php foreach ($balances as $b): $tid = (int) $b['id']; ?><tr>
          <td><span class="pill pill-<?= e($b['color']) ?>"><?= e($b['name']) ?></span><?= (int) $b['paid'] ? '' : ' <span class="xs muted">unpaid</span>' ?></td>
          <td class="num"><label class="sr" for="la-<?= $tid ?>">Allocated <?= e($b['name']) ?></label><input id="la-<?= $tid ?>" class="hr-quota-in" type="number" step="0.5" min="0" max="999" name="leave_alloc[<?= $tid ?>]" value="<?= e(old('leave_alloc.'.$tid, $LS::num($b['allocated']))) ?>"><?= error_for('leave_alloc_'.$tid) ?></td>
          <td class="num"><?= e($LS::num($b['used'])) ?></td>
          <td class="num"><b><?= e($LS::num(max(0, (float) $b['allocated'] - (float) $b['used']))) ?></b></td>
        </tr><?php endforeach ?></tbody>
      </table></div>
    </div>
    <?php endif ?>
    <div class="hr-sec">
      <div class="hr-sec-t"><h3><?= icon('user', 'sm') ?> Personal</h3><p>Private details visible to HR and the employee only.</p></div>
      <div class="form-grid">
        <div class="field"><label for="f-dob">Date of birth</label><input id="f-dob" type="date" name="date_of_birth" value="<?= e($v('date_of_birth')) ?>" max="<?= date('Y-m-d') ?>"><?= error_for('date_of_birth') ?></div>
        <div class="field"><label for="f-g">Gender</label><select id="f-g" name="gender"><option value="">Prefer not to say</option><?php foreach (['Female', 'Male', 'Non-binary', 'Other'] as $g): ?><option<?= selected($g, $v('gender')) ?>><?= $g ?></option><?php endforeach ?></select></div>
        <div class="field"><label for="f-pe">Personal email</label><input id="f-pe" type="email" name="personal_email" value="<?= e($v('personal_email')) ?>"><?= error_for('personal_email') ?></div>
        <div class="field"><label for="f-en">Emergency contact</label><input id="f-en" type="text" name="emergency_name" value="<?= e($v('emergency_name')) ?>" placeholder="Name & relation"><?= error_for('emergency_name') ?></div>
        <div class="field"><label for="f-ep">Emergency phone</label><input id="f-ep" type="tel" name="emergency_phone" value="<?= e($v('emergency_phone')) ?>"><?= error_for('emergency_phone') ?></div>
        <div class="field full"><label for="f-ad">Address</label><textarea id="f-ad" name="address" rows="2"><?= e($v('address')) ?></textarea><?= error_for('address') ?></div>
      </div>
    </div>
    <div class="hr-sec">
      <div class="hr-sec-t"><h3><?= icon('lock', 'sm') ?> Bank & tax <span class="pill pill-mint">Encrypted</span></h3><p>Encrypted at rest and shown masked everywhere. Revealing is limited to HR and audit-logged.</p></div>
      <div class="form-grid">
        <div class="field"><label for="f-bn">Bank name</label><input id="f-bn" type="text" name="bank_name" value="<?= e($v('bank_name')) ?>"><?= error_for('bank_name') ?></div>
        <div class="field"><label for="f-ifsc">IFSC / routing code</label><input id="f-ifsc" type="text" name="bank_ifsc" value="<?= e($v('bank_ifsc')) ?>" class="mono"><?= error_for('bank_ifsc') ?></div>
        <div class="field"><label for="f-acc">Account number</label><input id="f-acc" type="text" name="bank_account" value="" class="mono" autocomplete="off" placeholder="<?= e($masked['bank'] ?: 'Not set') ?>"><div class="hint"><?= $masked['bank'] ? 'Current: '.e($masked['bank']).'. Leave blank to keep it.' : 'Stored encrypted; shown masked afterwards.' ?></div><?php if ($masked['bank']): ?><label class="check xs mt"><input type="checkbox" name="clear_bank" value="1"> Remove saved account number</label><?php endif ?><?= error_for('bank_account') ?></div>
        <div class="field"><label for="f-tax">Tax ID (PAN / SSN)</label><input id="f-tax" type="text" name="tax_id" value="" class="mono" autocomplete="off" placeholder="<?= e($masked['tax'] ?: 'Not set') ?>"><div class="hint"><?= $masked['tax'] ? 'Current: '.e($masked['tax']).'. Leave blank to keep it.' : 'Stored encrypted; shown masked afterwards.' ?></div><?php if ($masked['tax']): ?><label class="check xs mt"><input type="checkbox" name="clear_tax" value="1"> Remove saved tax ID</label><?php endif ?><?= error_for('tax_id') ?></div>
      </div>
    </div>
    <div class="hr-sec">
      <div class="hr-sec-t"><h3><?= icon('file-text', 'sm') ?> HR notes</h3><p>Internal notes. Never shown to the employee.</p></div>
      <div class="field"><label class="sr" for="f-notes">HR notes</label><textarea id="f-notes" name="notes" rows="4" placeholder="Visible to HR only"><?= e($v('notes')) ?></textarea><?= error_for('notes') ?></div>
    </div>
  </div>
  <div class="hr-sticky"><span class="hr-sticky-note"><?= icon('shield', 'sm') ?> Every save is recorded in the audit log.</span><a class="btn ghost" href="<?= e(url('/hr/employees/'.$id)) ?>">Cancel</a><button class="btn primary"><?= icon('check', 'sm') ?> Save profile</button></div>
</form>
