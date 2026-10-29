<?php $v = static fn (string $k) => old($k, $prof[$k] ?? ''); $id = (int) $emp['id']; ?>
<?= partial('partials/page_head', ['title' => 'Edit HR profile', 'subtitle' => $emp['name'].' · '.($emp['designation'] ?: $emp['email']), 'crumbs' => [['HR Admin'], ['Employees', '/hr/employees'], [$emp['name'], '/hr/employees/'.$id], ['Edit']],
  'actions' => can('admin.users.manage') ? '<a class="btn ghost" href="'.e(url('/admin/users/'.$id.'/edit')).'">'.icon('settings', 'sm').' Name, role & manager</a>' : '']) ?>
<form class="hr-form" method="post" action="<?= e(url('/hr/employees/'.$id)) ?>" autocomplete="off"><?= csrf_field() ?>
  <div class="card pad0">
    <div class="hr-sec">
      <div class="hr-sec-t"><h3><?= icon('briefcase', 'sm') ?> Employment</h3><p>Contract type and probation. Work hours & weekly off days are on the <a class="b" href="<?= e(url('/hr/employees/'.$id.'?tab=schedule')) ?>">Work schedule</a> tab.</p></div>
      <div class="form-grid">
        <div class="field"><label for="f-type">Employment type *</label><select id="f-type" name="employment_type" required><?php foreach ($types as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($k, $v('employment_type') ?: 'full_time') ?>><?= e($l) ?></option><?php endforeach ?></select><?= error_for('employment_type') ?></div>
        <div class="field"><label for="f-prob">Probation ends</label><input id="f-prob" type="date" name="probation_end" value="<?= e($v('probation_end')) ?>"><div class="hint">Shown as a badge on the profile until this date.</div><?= error_for('probation_end') ?></div>
      </div>
    </div>
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
