<?php $v = static fn (string $k) => old($k, $prof[$k] ?? ''); $id = (int) $emp['id']; ?>
<?= partial('partials/page_head', ['title' => 'Edit HR profile', 'subtitle' => $emp['name'].' · '.($emp['designation'] ?: $emp['email']), 'crumbs' => [['Employees', '/hr/employees'], [$emp['name'], '/hr/employees/'.$id], ['Edit']]]) ?>
<form class="grid g-side" method="post" action="<?= e(url('/hr/employees/'.$id)) ?>" autocomplete="off"><?= csrf_field() ?>
  <div class="card form">
    <h3>Employment</h3>
    <div class="form-grid">
      <div class="field"><label>Employment type *</label><select name="employment_type" required><?php foreach ($types as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($k, $v('employment_type') ?: 'full_time') ?>><?= e($l) ?></option><?php endforeach ?></select><?= error_for('employment_type') ?></div>
      <div class="field"><label>Probation ends</label><input type="date" name="probation_end" value="<?= e($v('probation_end')) ?>"><?= error_for('probation_end') ?></div>
    </div>
    <h3 class="mt">Personal</h3>
    <div class="form-grid">
      <div class="field"><label>Date of birth</label><input type="date" name="date_of_birth" value="<?= e($v('date_of_birth')) ?>" max="<?= date('Y-m-d') ?>"><?= error_for('date_of_birth') ?></div>
      <div class="field"><label>Gender</label><select name="gender"><option value="">Prefer not to say</option><?php foreach (['Female', 'Male', 'Non-binary', 'Other'] as $g): ?><option<?= selected($g, $v('gender')) ?>><?= $g ?></option><?php endforeach ?></select></div>
      <div class="field"><label>Personal email</label><input type="email" name="personal_email" value="<?= e($v('personal_email')) ?>"><?= error_for('personal_email') ?></div>
      <div class="field"><label>Emergency contact</label><input type="text" name="emergency_name" value="<?= e($v('emergency_name')) ?>" placeholder="Name & relation"><?= error_for('emergency_name') ?></div>
      <div class="field"><label>Emergency phone</label><input type="tel" name="emergency_phone" value="<?= e($v('emergency_phone')) ?>"><?= error_for('emergency_phone') ?></div>
      <div class="field full"><label>Address</label><textarea name="address" rows="2"><?= e($v('address')) ?></textarea><?= error_for('address') ?></div>
    </div>
    <h3 class="mt"><?= icon('lock', 'sm') ?> Bank & tax <span class="pill pill-mint">Encrypted</span></h3>
    <div class="form-grid">
      <div class="field"><label>Bank name</label><input type="text" name="bank_name" value="<?= e($v('bank_name')) ?>"><?= error_for('bank_name') ?></div>
      <div class="field"><label>IFSC / routing code</label><input type="text" name="bank_ifsc" value="<?= e($v('bank_ifsc')) ?>" class="mono"><?= error_for('bank_ifsc') ?></div>
      <div class="field"><label>Account number</label><input type="text" name="bank_account" value="" class="mono" autocomplete="off" placeholder="<?= e($masked['bank'] ?: 'Not set') ?>"><div class="hint"><?= $masked['bank'] ? 'Current: '.e($masked['bank']).'. Leave blank to keep it.' : 'Stored encrypted; shown masked afterwards.' ?></div><?php if ($masked['bank']): ?><label class="check xs mt"><input type="checkbox" name="clear_bank" value="1"> Remove saved account number</label><?php endif ?><?= error_for('bank_account') ?></div>
      <div class="field"><label>Tax ID (PAN / SSN)</label><input type="text" name="tax_id" value="" class="mono" autocomplete="off" placeholder="<?= e($masked['tax'] ?: 'Not set') ?>"><div class="hint"><?= $masked['tax'] ? 'Current: '.e($masked['tax']).'. Leave blank to keep it.' : 'Stored encrypted; shown masked afterwards.' ?></div><?php if ($masked['tax']): ?><label class="check xs mt"><input type="checkbox" name="clear_tax" value="1"> Remove saved tax ID</label><?php endif ?><?= error_for('tax_id') ?></div>
    </div>
    <h3 class="mt">HR notes</h3>
    <div class="field"><textarea name="notes" rows="4" placeholder="Visible to HR only"><?= e($v('notes')) ?></textarea><?= error_for('notes') ?></div>
    <div class="form-actions"><a class="btn ghost" href="<?= e(url('/hr/employees/'.$id)) ?>">Cancel</a><button class="btn primary"><?= icon('check', 'sm') ?> Save profile</button></div>
  </div>
  <div class="stack">
    <div class="card soft-lav hr-noborder"><?= illus('security-shield') ?><h4 class="mt">Sensitive data, handled carefully</h4><p class="small mb0">Bank account and tax numbers are encrypted at rest and shown masked everywhere. Revealing them is limited to HR managers and recorded in the audit log.</p></div>
    <div class="card"><h4>Work details</h4><p class="small muted">Name, email, department, designation, manager and joining date live on the user account.</p><?php if (can('admin.users.manage')): ?><a class="btn ghost sm" href="<?= e(url('/admin/users/'.$id.'/edit')) ?>"><?= icon('settings', 'sm') ?> Edit account</a><?php endif ?></div>
  </div>
</form>
