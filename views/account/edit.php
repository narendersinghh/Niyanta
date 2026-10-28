<?= partial('partials/page_head', ['title' => 'My account', 'subtitle' => 'Manage your profile, password and appearance.']) ?>
<div class="grid g-side">
  <div class="stack">
    <form class="card form" method="post" action="<?= e(url('/account')) ?>"><?= csrf_field() ?>
      <h3>Profile</h3>
      <div class="form-grid">
        <div class="field"><label>Name</label><input name="name" value="<?= e(old('name', $u['name'])) ?>" required><?= error_for('name') ?></div>
        <div class="field"><label>Phone</label><input name="phone" value="<?= e(old('phone', $u['phone'])) ?>"><?= error_for('phone') ?></div>
        <div class="field"><label>Email</label><input value="<?= e($u['email']) ?>" disabled><div class="hint">Contact an administrator to change your email.</div></div>
        <div class="field"><label>Role</label><input value="<?= e($u['role_name']) ?>" disabled></div>
      </div>
      <div class="form-actions"><button class="btn primary">Save profile</button></div>
    </form>
    <form class="card form" method="post" action="<?= e(url('/account/password')) ?>"><?= csrf_field() ?>
      <h3>Change password</h3>
      <div class="form-grid">
        <div class="field full"><label>Current password</label><input type="password" name="current_password" required autocomplete="current-password"></div>
        <div class="field"><label>New password</label><input type="password" name="password" required minlength="10" autocomplete="new-password"></div>
        <div class="field"><label>Confirm new password</label><input type="password" name="password_confirmation" required autocomplete="new-password"></div>
      </div>
      <div class="form-actions"><button class="btn">Update password</button></div>
    </form>
  </div>
  <div class="stack">
    <div class="card center">
      <div style="display:flex;justify-content:center"><?= avatar($u['name'], $u['avatar'], 'xl') ?></div>
      <h3 class="mt"><?= e($u['name']) ?></h3><p class="muted small"><?= e($u['designation'] ?: $u['role_name']) ?></p>
      <form method="post" action="<?= e(url('/account/avatar')) ?>" enctype="multipart/form-data" class="form"><?= csrf_field() ?><input type="file" name="avatar" accept="image/png,image/jpeg,image/webp" required><button class="btn sm ghost">Upload photo</button></form>
    </div>
    <div class="card"><h4>Appearance</h4><p class="small muted">Choose light, dark or follow your system. Use the switch in the top bar.</p><div class="theme-switch" style="display:inline-flex"><button type="button" data-theme-set="light"><?= icon('sun', 'sm') ?></button><button type="button" data-theme-set="system"><?= icon('monitor', 'sm') ?></button><button type="button" data-theme-set="dark"><?= icon('moon', 'sm') ?></button></div></div>
    <div class="card"><h4>Security</h4><dl class="kv small"><dt>Last sign in</dt><dd><?= e(fdatetime($u['last_login_at'])) ?></dd><dt>From IP</dt><dd><?= e($u['last_login_ip'] ?? '—') ?></dd></dl></div>
  </div>
</div>
