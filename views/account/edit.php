<?php $hrProfile = profile_url() !== '/account'; ?>
<?= partial('partials/page_head', ['title' => 'My profile', 'subtitle' => 'Your sign-in details, password and appearance.', 'crumbs' => [['My profile']]]) ?>
<?php if ($hrProfile): ?><nav class="tabs mb hr-tabs" aria-label="Profile sections"><a href="<?= e(url(profile_url())) ?>"><?= icon('user', 'sm') ?> HR profile</a><a class="active" href="<?= e(url('/account')) ?>" aria-current="page"><?= icon('lock', 'sm') ?> Account &amp; security</a></nav><?php endif ?>
<section class="card profile-banner mb">
  <div class="profile-banner-bg"></div>
  <div class="profile-banner-body">
    <?= avatar($u['name'], $u['avatar'], 'xl') ?>
    <div class="grow"><h2 class="mb0"><?= e($u['name']) ?></h2><div class="muted"><?= e($u['designation'] ?: $u['role_name']) ?><?= $u['department'] ? ' · '.e($u['department']) : '' ?></div>
      <div class="chips mt"><span class="chip"><?= icon('mail', 'sm') ?> <?= e($u['email']) ?></span><?php if ($u['phone']): ?><span class="chip"><?= icon('phone', 'sm') ?> <?= e($u['phone']) ?></span><?php endif ?><span class="chip"><?= icon('shield', 'sm') ?> <?= e($u['role_name']) ?></span></div></div>
    <form method="post" action="<?= e(url('/account/avatar')) ?>" enctype="multipart/form-data" class="avatar-upload"><?= csrf_field() ?><label class="btn ghost sm"><?= icon('camera', 'sm') ?> Change photo<input type="file" name="avatar" accept="image/png,image/jpeg,image/webp" required data-autosubmit hidden></label></form>
  </div>
</section>
<div class="grid g-side">
  <div class="stack">
    <form class="card form" method="post" action="<?= e(url('/account')) ?>"><?= csrf_field() ?>
      <div class="card-head"><div><h3>Profile</h3><p class="small muted mb0">How colleagues see you.</p></div></div>
      <div class="form-grid">
        <div class="field"><label for="a-name">Full name</label><input id="a-name" type="text" name="name" value="<?= e(old('name', $u['name'])) ?>" required><?= error_for('name') ?></div>
        <div class="field"><label for="a-phone">Contact number</label><input id="a-phone" type="tel" name="phone" value="<?= e(old('phone', $u['phone'])) ?>"><?= error_for('phone') ?></div>
        <div class="field"><label for="a-wa">WhatsApp number</label><input id="a-wa" type="tel" name="whatsapp" value="<?= e(old('whatsapp', $u['whatsapp'] ?? '')) ?>" placeholder="Same as contact number"><?= error_for('whatsapp') ?></div>
        <div class="field"><label>Email</label><input type="email" value="<?= e($u['email']) ?>" disabled><div class="hint">Ask an administrator to change your sign-in email.</div></div>
        <div class="field"><label>Role</label><input type="text" value="<?= e($u['role_name']) ?>" disabled></div>
      </div>
      <div class="form-actions"><button class="btn primary"><?= icon('check', 'sm') ?> Save profile</button></div>
    </form>
    <form class="card form" method="post" action="<?= e(url('/account/password')) ?>"><?= csrf_field() ?>
      <div class="card-head"><div><h3>Password</h3><p class="small muted mb0">Changing it signs you out on other devices.</p></div></div>
      <div class="form-grid">
        <div class="field full"><label for="a-cur">Current password</label><input id="a-cur" type="password" name="current_password" required autocomplete="current-password"></div>
        <div class="field"><label for="a-new">New password</label><input id="a-new" type="password" name="password" required minlength="10" autocomplete="new-password"><div class="hint">At least 10 characters with letters and numbers.</div></div>
        <div class="field"><label for="a-conf">Confirm new password</label><input id="a-conf" type="password" name="password_confirmation" required autocomplete="new-password"></div>
      </div>
      <div class="form-actions"><button class="btn"><?= icon('lock', 'sm') ?> Update password</button></div>
    </form>
  </div>
  <div class="stack">
    <div class="card"><h4>Appearance</h4><p class="small muted">Light, dark, or follow your device.</p>
      <div class="theme-pick"><button type="button" data-theme-set="light"><span class="tp tp-light"></span>Light</button><button type="button" data-theme-set="dark"><span class="tp tp-dark"></span>Dark</button><button type="button" data-theme-set="system"><span class="tp tp-sys"></span>System</button></div></div>
    <div class="card"><h4>Security</h4><dl class="kv small"><dt>Last sign in</dt><dd><?= e(fdatetime($u['last_login_at'])) ?></dd><dt>From IP</dt><dd class="mono"><?= e($u['last_login_ip'] ?? '-') ?></dd><dt>Member since</dt><dd><?= e(fdate($u['created_at'])) ?></dd></dl></div>
  </div>
</div>
