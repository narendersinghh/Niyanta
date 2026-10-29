<div class="auth"><?= partial('partials/auth_tools') ?><div class="auth-art"><span></span><?= illus('security-shield') ?><h2>Choose a strong new password.</h2></div>
<div class="auth-form"><form class="auth-box form" method="post" action="<?= e(url('/reset-password')) ?>"><?= csrf_field() ?><input type="hidden" name="token" value="<?= e($token) ?>">
<div><h1>Reset password</h1><p class="muted">Use at least 10 characters with letters and numbers.</p></div>
<div class="field"><label>New password</label><input type="password" name="password" required minlength="10" autocomplete="new-password"></div>
<div class="field"><label>Confirm password</label><input type="password" name="password_confirmation" required autocomplete="new-password"></div>
<button class="btn primary">Update password</button></form></div></div>
