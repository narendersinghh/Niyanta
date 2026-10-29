<?php $manage = can('hr.employees.manage'); ?>
<div class="grid g2 hr-top">
  <?php foreach (['onboarding' => ['Onboarding', 'rocket', 'mint'], 'offboarding' => ['Offboarding', 'log-out', 'peach']] as $type => [$label, $ill, $col]):
    $items = $checklists[$type]; $done = count(array_filter($items, static fn ($i) => $i['done_at'] !== null)); $pct = $items ? (int) round($done / count($items) * 100) : 0; ?>
  <div class="card">
    <div class="card-head"><h3><?= e($label) ?></h3><?php if ($items): ?><span class="pill pill-<?= $pct === 100 ? 'mint' : 'lemon' ?>"><?= $done ?>/<?= count($items) ?> done</span><?php endif ?></div>
    <?php if (!$items): ?>
      <div class="empty">
        <?= illus($type === 'onboarding' ? 'rocket' : 'coffee-break', 'empty-illus') ?>
        <h4><?= $type === 'onboarding' ? 'No onboarding checklist yet' : 'No offboarding checklist' ?></h4>
        <p class="small muted"><?= $type === 'onboarding' ? 'Generate one from your templates to track the new joiner\'s first days.' : 'Starts automatically when you record an exit below.' ?></p>
        <?php if ($manage && $type === 'onboarding'): ?><form method="post" action="<?= e(url('/hr/employees/'.$emp['id'].'/checklists/onboarding')) ?>"><?= csrf_field() ?><button class="btn primary sm"<?= empty($templateCount['onboarding']) ? ' disabled' : '' ?>><?= icon('plus', 'sm') ?> Generate (<?= (int) ($templateCount['onboarding'] ?? 0) ?> items)</button></form><?php endif ?>
      </div>
    <?php else: ?>
      <div class="flex mb"><div class="progress grow"><span style="width:<?= $pct ?>%"></span></div><b class="small"><?= $pct ?>%</b></div>
      <div class="hr-check">
        <?php foreach ($items as $i): $ok = $i['done_at'] !== null; ?>
        <div class="hr-check-item<?= $ok ? ' done' : '' ?>">
          <?php if ($manage): ?><form method="post" action="<?= e(url('/hr/checklist-items/'.$i['id'].'/toggle')) ?>"><?= csrf_field() ?><button class="hr-tick" title="<?= $ok ? 'Mark as not done' : 'Mark as done' ?>" aria-pressed="<?= $ok ? 'true' : 'false' ?>"><?= icon('check', 'sm') ?></button></form>
          <?php else: ?><span class="hr-tick" aria-hidden="true"><?= icon('check', 'sm') ?></span><?php endif ?>
          <div class="grow"><div class="b"><?= e($i['title']) ?></div><?php if ($ok): ?><div class="xs muted">Done <?= fdatetime($i['done_at']) ?><?= $i['done_by_name'] ? ' by '.e($i['done_by_name']) : '' ?></div><?php endif ?></div>
        </div>
        <?php endforeach ?>
      </div>
    <?php endif ?>
  </div>
  <?php endforeach ?>
</div>
<?php if ($manage): ?>
<div class="grid g-side mt hr-top">
  <form class="card form" method="post" action="<?= e(url('/hr/employees/'.$emp['id'].'/offboard')) ?>"><?= csrf_field() ?>
    <div class="card-head mb0"><h3><?= icon('log-out') ?> Record exit</h3><?php if (!empty($prof['exit_date'])): ?><span class="pill pill-peach">Exit <?= fdate($prof['exit_date']) ?></span><?php endif ?></div>
    <p class="small muted mb0">Saving the exit details creates the offboarding checklist from your templates (if it does not exist yet).</p>
    <div class="form-grid">
      <div class="field"><label>Last working day *</label><input type="date" name="exit_date" value="<?= e(old('exit_date', $prof['exit_date'] ?? '')) ?>" required><?= error_for('exit_date') ?></div>
      <div class="field"><label>Reason *</label><input type="text" name="exit_reason" maxlength="255" value="<?= e(old('exit_reason', $prof['exit_reason'] ?? '')) ?>" placeholder="Resignation, contract end…" required><?= error_for('exit_reason') ?></div>
    </div>
    <div class="form-actions"><button class="btn primary"><?= icon('check', 'sm') ?> Save exit details</button></div>
  </form>
  <div class="card hr-danger-zone">
    <h3><?= icon('lock') ?> Account access</h3>
    <?php if ($emp['status'] === 'inactive'): ?>
      <p class="small mb0">This account is <b>inactive</b>: the person can no longer sign in.</p>
    <?php elseif ((int) $emp['id'] === (int) user()['id']): ?>
      <p class="small mb0">You cannot deactivate your own account.</p>
    <?php else: ?>
      <p class="small">When the employee has left, deactivate the account. They are signed out everywhere and pending leave is cancelled. History is kept.</p>
      <form method="post" action="<?= e(url('/hr/employees/'.$emp['id'].'/deactivate')) ?>" data-confirm="Deactivate <?= e($emp['name']) ?>? They will be signed out immediately."><?= csrf_field() ?><button class="btn danger"><?= icon('lock', 'sm') ?> Deactivate account</button></form>
    <?php endif ?>
  </div>
</div>
<?php endif ?>
