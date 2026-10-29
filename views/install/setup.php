<?php $guess = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http').'://'.($_SERVER['HTTP_HOST'] ?? 'localhost').\Core\Request::base(); ?>
<div class="install">
  <div class="card hero mb"><div><span class="pill pill-mint">Step 2 of 2</span><h1 class="mt">Set up your workspace</h1><p class="muted">Create the Super Admin account. You can change branding and everything else later in Settings.</p></div><?= illus('team') ?></div>
  <form class="card form" method="post" action="<?= e(url('/install/run')) ?>">
    <?= csrf_field() ?>
    <h3>Workspace</h3>
    <div class="form-grid">
      <div class="field"><label>Company name</label><input name="app_name" value="<?= e(old('app_name')) ?>" placeholder="e.g. Digital Nil Marketing Agency LLP" required><?= error_for('app_name') ?></div>
      <div class="field"><label>Application URL</label><input type="url" name="app_url" value="<?= e(old('app_url', $guess)) ?>" required><?= error_for('app_url') ?></div>
      <div class="field"><label>Timezone</label><select name="timezone"><?php foreach (DateTimeZone::listIdentifiers() as $tz): ?><option<?= selected($tz, old('timezone', 'Asia/Kolkata')) ?>><?= e($tz) ?></option><?php endforeach ?></select></div>
      <div class="field"><label>Currency symbol</label><input name="currency_symbol" value="<?= e(old('currency_symbol', '₹')) ?>" maxlength="5" required></div>
    </div>
    <h3 class="mt">Super Admin</h3>
    <div class="form-grid">
      <div class="field"><label>Full name</label><input name="name" value="<?= e(old('name')) ?>" required><?= error_for('name') ?></div>
      <div class="field"><label>Email</label><input type="email" name="email" value="<?= e(old('email')) ?>" required><?= error_for('email') ?></div>
      <div class="field"><label>Password</label><input type="password" name="password" required autocomplete="new-password" minlength="10"><div class="hint">At least 10 characters with letters and numbers.</div><?= error_for('password') ?></div>
      <div class="field"><label>Confirm password</label><input type="password" name="password_confirmation" required autocomplete="new-password"></div>
    </div>
    <div class="form-actions"><a class="btn ghost" href="<?= e(url('/install')) ?>">Back</a><button class="btn primary"><?= icon('zap', 'sm') ?> Install Niyanta</button></div>
  </form>
</div>
