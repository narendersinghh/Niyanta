<?php use Modules\Hr\Services\LeaveService as LS;
$kv = static fn (?string $v): string => $v !== null && $v !== '' ? e($v) : '<span class="muted">-</span>';
$paidLeft = array_sum(array_map(static fn ($b) => (int) $b['paid'] && $b['allocated_row'] ? max(0, (float) $b['available']) : 0, $balances));
$oh = current(array_filter($balances, static fn ($b) => $b['code'] === LS::OPTIONAL_CODE)) ?: null; ?>
<div class="grid g-main hr-top">
  <div class="stack">
    <div class="card">
      <div class="card-head"><h3><?= icon('briefcase') ?> Work</h3></div>
      <dl class="kv">
        <dt>Work email</dt><dd><?= e($emp['email']) ?></dd>
        <dt>Phone</dt><dd><?= $kv($emp['phone']) ?></dd>
        <dt>Role</dt><dd><?= e($emp['role_name']) ?></dd>
        <dt>Department</dt><dd><?= $kv($emp['department']) ?></dd>
        <dt>Designation</dt><dd><?= $kv($emp['designation']) ?></dd>
        <dt>Employment type</dt><dd><?= e($types[$prof['employment_type'] ?? 'full_time'] ?? '-') ?></dd>
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
      <div class="card-head"><h3><?= icon('lock') ?> Bank & tax</h3><?php if (can('hr.employees.manage') && ($masked['bank'] || $masked['tax'])): ?><button type="button" class="btn sm soft" data-reveal="hr/employees/<?= (int) $emp['id'] ?>/reveal" data-confirm="Reveal the full numbers? This is recorded in the audit log."><?= icon('eye', 'sm') ?> Reveal</button><?php endif ?></div>
      <dl class="kv">
        <dt>Bank</dt><dd><?= $kv($prof['bank_name'] ?? null) ?></dd>
        <dt>Account no.</dt><dd class="mono" data-secret="bank"><?= $masked['bank'] !== '' ? e($masked['bank']) : '<span class="muted">-</span>' ?></dd>
        <dt>IFSC / routing</dt><dd class="mono"><?= $kv($prof['bank_ifsc'] ?? null) ?></dd>
        <dt>Tax ID</dt><dd class="mono" data-secret="tax"><?= $masked['tax'] !== '' ? e($masked['tax']) : '<span class="muted">-</span>' ?></dd>
      </dl>
      <p class="xs muted mb0 mt"><?= icon('shield', 'sm') ?> Stored encrypted. <?= can('hr.employees.manage') ? 'Revealing full numbers is recorded in the audit log.' : 'Contact HR to update these details.' ?></p>
    </div>
    <?php if ($hr && !empty($prof['notes'])): ?>
    <div class="card"><div class="card-head"><h3><?= icon('file-text') ?> HR notes</h3><span class="pill pill-gray"><?= icon('lock', 'sm') ?> HR only</span></div><p class="mb0"><?= nl2br(e($prof['notes'])) ?></p></div>
    <?php endif ?>
  </div>
  <div class="stack">
    <?php if (feature_on('leave')): ?><a class="tile bg-sky" href="<?= e(url('/hr/employees/'.$emp['id'].'?tab=schedule')) ?>"><span class="tile-ic"><?= icon('gift') ?></span><div><div class="num"><?= e(LS::num($paidLeft)) ?></div><div class="lbl">Paid days left in <?= (int) $year ?></div><div class="hr-tsub"><?= $oh && $oh['allocated_row'] ? e(LS::num(max(0, (float) $oh['available']))).' of '.e(LS::num($oh['allocated'])).' optional days' : 'See work schedule & leave' ?></div></div></a><?php endif ?>
    <?php if ($self): ?>
    <form class="card form" method="post" action="<?= e(url('/hr/employees/'.$emp['id'].'/self')) ?>"><?= csrf_field() ?>
      <div class="hr-side-head"><span class="hr-ic mint"><?= icon('phone') ?></span><div><h4 class="mb0">Keep in touch</h4><div class="xs muted">You can update these yourself</div></div></div>
      <div class="field"><label for="pe">Personal email</label><input id="pe" type="email" name="personal_email" value="<?= e(old('personal_email', $prof['personal_email'] ?? '')) ?>"><?= error_for('personal_email') ?></div>
      <div class="field"><label for="en">Emergency contact name</label><input id="en" type="text" name="emergency_name" value="<?= e(old('emergency_name', $prof['emergency_name'] ?? '')) ?>" placeholder="Name & relation"><?= error_for('emergency_name') ?></div>
      <div class="field"><label for="ep">Emergency contact phone</label><input id="ep" type="tel" name="emergency_phone" value="<?= e(old('emergency_phone', $prof['emergency_phone'] ?? '')) ?>"><?= error_for('emergency_phone') ?></div>
      <div class="form-actions"><button class="btn primary"><?= icon('check', 'sm') ?> Save</button></div>
    </form>
    <?php endif ?>
    <?php $seen = []; foreach ($procs ?? [] as $pr): if (isset($seen[$pr['kind']]) || $pr['status'] === 'cancelled') { continue; } $seen[$pr['kind']] = true; $pc = (int) $pr['total'] ? (int) round($pr['done'] / $pr['total'] * 100) : 0; [$psl, $psc] = \Modules\Hr\Services\ProcessService::STATUS[$pr['status']]; ?>
    <a class="card flex" href="<?= e(url('/hr/employees/'.$emp['id'].'?tab=checklists')) ?>"><span class="ring" style="--v:<?= $pc ?>;--c:var(--<?= $pr['kind'] === 'onboarding' ? 'mint' : 'peach' ?>)" data-label="<?= $pc ?>%"></span><div class="grow"><b><?= $pr['kind'] === 'onboarding' ? 'Onboarding' : 'Exit' ?></b> <span class="pill pill-<?= e($psc) ?>"><?= e($psl) ?></span><div class="small muted"><?= (int) $pr['done'] ?> of <?= (int) $pr['total'] ?> tasks done</div></div><?= icon('chevron-right', 'sm') ?></a>
    <?php endforeach ?>
    <?php foreach (['onboarding' => 'Onboarding', 'offboarding' => 'Offboarding'] as $ct => $cl): $items = $checklists[$ct]; if (!$items || isset($seen[$ct === 'offboarding' ? 'exit' : $ct])) { continue; } $dn = count(array_filter($items, static fn ($i) => $i['done_at'] !== null)); $pc = (int) round($dn / count($items) * 100); ?>
    <a class="card flex" href="<?= e(url('/hr/employees/'.$emp['id'].'?tab=checklists')) ?>"><span class="ring" style="--v:<?= $pc ?>;--c:var(--<?= $ct === 'onboarding' ? 'mint' : 'peach' ?>)" data-label="<?= $pc ?>%"></span><div class="grow"><b><?= e($cl) ?></b><div class="small muted"><?= $dn ?> of <?= count($items) ?> steps done</div></div><?= icon('chevron-right', 'sm') ?></a>
    <?php endforeach ?>
    <?php if ($reports): ?>
    <div class="card"><div class="card-head"><h3>Direct reports</h3><span class="pill pill-lav"><?= count($reports) ?></span></div>
      <div class="list"><?php foreach ($reports as $r): ?><div class="list-item"><?= avatar($r['name'], $r['avatar'], 'sm') ?><div class="grow"><?php if (can('hr.employees.view')): ?><a class="b" href="<?= e(url('/hr/employees/'.$r['id'])) ?>"><?= e($r['name']) ?></a><?php else: ?><b><?= e($r['name']) ?></b><?php endif ?><div class="meta"><?= e($r['designation'] ?? '') ?></div></div></div><?php endforeach ?></div>
    </div>
    <?php endif ?>
    <?php if (!empty($prof['exit_date'])): ?>
    <div class="card hr-danger-zone"><div class="hr-side-head"><span class="hr-ic peach"><?= icon('log-out') ?></span><h4 class="mb0">Exit recorded</h4></div><dl class="kv"><dt>Last day</dt><dd><?= fdate($prof['exit_date']) ?></dd><dt>Reason</dt><dd><?= e($prof['exit_reason'] ?? '-') ?></dd></dl></div>
    <?php endif ?>
  </div>
</div>
