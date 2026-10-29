<?php $app = setting('branding.app_name', 'Niyanta'); ?>
<div class="auth"><?= partial('partials/auth_tools') ?>
  <div class="auth-art">
    <div class="flex"><span class="brand-logo"><?php if ($l = setting('branding.logo')): ?><img src="<?= e(url('assets/uploads/branding/'.basename((string) $l))) ?>" alt=""><?php elseif (is_file(NY_ROOT.'/assets/img/logo.svg')): ?><img src="<?= e(asset('img/logo.svg')) ?>" alt=""><?php else: ?><?= e(mb_substr($app, 0, 1)) ?><?php endif ?></span><b style="font-size:1.2rem"><?= e($app) ?></b></div>
    <?= illus('login-hero') ?>
    <div>
      <h2><?= e(setting('branding.login_headline', 'Clients, projects, people & payroll — beautifully in one place.')) ?></h2>
      <div class="chips mt"><span class="chip">CRM</span><span class="chip">Projects</span><span class="chip">HR</span><span class="chip">Time</span><span class="chip">Payroll</span><span class="chip">AI</span></div>
    </div>
  </div>
  <div class="auth-form">
    <form class="auth-box form" method="post" action="<?= e(url('/login')) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="next" value="<?= e($_GET['next'] ?? '') ?>">
      <div><h1>Welcome back 👋</h1><p class="muted">Sign in to continue to <?= e($app) ?>.</p></div>
      <div class="field"><label for="email">Email</label><input id="email" type="email" name="email" value="<?= e(old('email')) ?>" required autofocus autocomplete="username"></div>
      <div class="field"><label for="password">Password</label><input id="password" type="password" name="password" required autocomplete="current-password"></div>
      <div class="flex between"><span></span><a class="small muted" href="<?= e(url('/forgot-password')) ?>">Forgot password?</a></div>
      <button class="btn primary" style="width:100%;padding:13px">Sign in <?= icon('arrow-right', 'sm') ?></button>
      <p class="xs muted center">Protected by rate limiting and session security.</p>
    </form>
  </div>
</div>
