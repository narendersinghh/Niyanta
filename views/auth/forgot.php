<div class="auth"><div class="auth-art"><span></span><?= illus('mail-envelope') ?><h2>We'll send you a secure reset link.</h2></div>
<div class="auth-form"><form class="auth-box form" method="post" action="<?= e(url('/forgot-password')) ?>"><?= csrf_field() ?>
<div><h1>Forgot password</h1><p class="muted">Enter your account email.</p></div>
<div class="field"><label>Email</label><input type="email" name="email" required autofocus></div>
<button class="btn primary">Send reset link</button><a class="small muted center" href="<?= e(url('/login')) ?>">Back to sign in</a></form></div></div>
