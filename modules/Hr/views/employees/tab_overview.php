<?php $kv = static fn (?string $v): string => $v !== null && $v !== '' ? e($v) : '<span class="muted">—</span>'; ?>
<div class="grid g-main">
  <div class="stack">
    <div class="card">
      <div class="card-head"><h3><?= icon('briefcase') ?> Work</h3></div>
      <dl class="kv">
        <dt>Work email</dt><dd><?= e($emp['email']) ?></dd>
        <dt>Phone</dt><dd><?= $kv($emp['phone']) ?></dd>
        <dt>Role</dt><dd><?= e($emp['role_name']) ?></dd>
        <dt>Department</dt><dd><?= $kv($emp['department']) ?></dd>
        <dt>Designation</dt><dd><?= $kv($emp['designation']) ?></dd>
        <dt>Employment type</dt><dd><?= e($types[$prof['employment_type'] ?? 'full_time'] ?? '—') ?></dd>
        <dt>Probation ends</dt><dd><?= fdate($prof['probation_end'] ?? null) ?></dd>
      </dl>
    </div>
    <div class="card">
      <div class="card-head"><h3><?= icon('user') ?> Personal</h3></div>
      <dl class="kv">
        <dt>Date of birth</dt><dd><?= fdate($prof['date_of_birth'] ?? null) ?></dd>
        <dt>Gender</dt><dd><?= $kv($prof['gender'] ?? null) ?></dd>
        <dt>Personal email</dt><dd><?= $kv($prof['personal_email'] ?? null) ?></dd>
        <dt>Address</dt><dd><?= nl2br($kv($prof['address'] ?? null)) ?></dd>
        <dt>Emergency contact</dt><dd><?= $kv(trim(($prof['emergency_name'] ?? '').(!empty($prof['emergency_phone']) ? ' · '.$prof['emergency_phone'] : ''), ' ·')) ?></dd>
      </dl>
    </div>
    <div class="card">
      <div class="card-head"><h3><?= icon('lock') ?> Bank & tax</h3><?php if (can('hr.employees.manage') && ($masked['bank'] || $masked['tax'])): ?><button type="button" class="btn sm soft" data-reveal="hr/employees/<?= (int) $emp['id'] ?>/reveal"><?= icon('eye', 'sm') ?> Reveal</button><?php endif ?></div>
      <dl class="kv">
        <dt>Bank</dt><dd><?= $kv($prof['bank_name'] ?? null) ?></dd>
        <dt>Account no.</dt><dd class="mono" data-secret="bank"><?= $masked['bank'] !== '' ? e($masked['bank']) : '<span class="muted">—</span>' ?></dd>
        <dt>IFSC / routing</dt><dd class="mono"><?= $kv($prof['bank_ifsc'] ?? null) ?></dd>
        <dt>Tax ID</dt><dd class="mono" data-secret="tax"><?= $masked['tax'] !== '' ? e($masked['tax']) : '<span class="muted">—</span>' ?></dd>
      </dl>
      <p class="xs muted mb0 mt"><?= icon('shield', 'sm') ?> Stored encrypted. <?= can('hr.employees.manage') ? 'Revealing full numbers is recorded in the audit log.' : 'Contact HR to update these details.' ?></p>
    </div>
    <?php if ($hr && !empty($prof['notes'])): ?>
    <div class="card soft-lemon hr-noborder"><div class="card-head"><h3><?= icon('file-text') ?> HR notes</h3><span class="pill pill-gray">HR only</span></div><p class="mb0"><?= nl2br(e($prof['notes'])) ?></p></div>
    <?php endif ?>
  </div>
  <div class="stack">
    <?php if ($self): ?>
    <form class="card form" method="post" action="<?= e(url('/hr/employees/'.$emp['id'].'/self')) ?>"><?= csrf_field() ?>
      <div class="card-head mb0"><h3>Keep in touch</h3><?= icon('phone') ?></div>
      <p class="small muted mb0">Update your personal email and emergency contact. Other details are managed by HR.</p>
      <div class="field"><label>Personal email</label><input type="email" name="personal_email" value="<?= e(old('personal_email', $prof['personal_email'] ?? '')) ?>"><?= error_for('personal_email') ?></div>
      <div class="field"><label>Emergency contact name</label><input type="text" name="emergency_name" value="<?= e(old('emergency_name', $prof['emergency_name'] ?? '')) ?>"><?= error_for('emergency_name') ?></div>
      <div class="field"><label>Emergency contact phone</label><input type="tel" name="emergency_phone" value="<?= e(old('emergency_phone', $prof['emergency_phone'] ?? '')) ?>"><?= error_for('emergency_phone') ?></div>
      <div class="form-actions"><button class="btn primary"><?= icon('check', 'sm') ?> Save</button></div>
    </form>
    <?php endif ?>
    <div class="card soft-mint hr-noborder hr-mini-hero">
      <div><h3 class="mb0"><?= e(array_sum(array_map(static fn ($b) => (int) $b['paid'] ? max(0, (float) $b['available']) : 0, $balances))) ?> days</h3><p class="small mb0">paid leave available in <?= (int) $year ?></p><a class="btn sm mt" href="<?= e(url('/hr/employees/'.$emp['id'].'?tab=leave')) ?>">View leave <?= icon('arrow-right', 'sm') ?></a></div>
      <?= illus('leave-beach') ?>
    </div>
    <?php foreach (['onboarding' => 'Onboarding', 'offboarding' => 'Offboarding'] as $ct => $cl): $items = $checklists[$ct]; if (!$items) { continue; } $dn = count(array_filter($items, static fn ($i) => $i['done_at'] !== null)); $pc = (int) round($dn / count($items) * 100); ?>
    <a class="card flex" href="<?= e(url('/hr/employees/'.$emp['id'].'?tab=checklists')) ?>"><span class="ring" style="--v:<?= $pc ?>;--c:var(--<?= $ct === 'onboarding' ? 'mint' : 'peach' ?>)" data-label="<?= $pc ?>%"></span><div class="grow"><b><?= e($cl) ?></b><div class="small muted"><?= $dn ?> of <?= count($items) ?> steps done</div></div><?= icon('chevron-right', 'sm') ?></a>
    <?php endforeach ?>
    <?php if ($reports): ?>
    <div class="card"><div class="card-head"><h3>Direct reports</h3><span class="pill pill-lav"><?= count($reports) ?></span></div>
      <div class="list"><?php foreach ($reports as $r): ?><div class="list-item"><?= avatar($r['name'], $r['avatar'], 'sm') ?><div class="grow"><?php if (can('hr.employees.view')): ?><a class="b" href="<?= e(url('/hr/employees/'.$r['id'])) ?>"><?= e($r['name']) ?></a><?php else: ?><b><?= e($r['name']) ?></b><?php endif ?><div class="meta"><?= e($r['designation'] ?? '') ?></div></div></div><?php endforeach ?></div>
    </div>
    <?php endif ?>
    <?php if (!empty($prof['exit_date'])): ?>
    <div class="card soft-peach hr-noborder"><h3><?= icon('log-out') ?> Exit</h3><dl class="kv"><dt>Exit date</dt><dd><?= fdate($prof['exit_date']) ?></dd><dt>Reason</dt><dd><?= e($prof['exit_reason'] ?? '—') ?></dd></dl></div>
    <?php endif ?>
  </div>
</div>
