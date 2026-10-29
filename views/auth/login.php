<?php $app = company_name(); ?>
<div class="auth"><?= partial('partials/auth_tools') ?>
  <div class="auth-art">
    <div class="flex"><span class="brand-logo"><img src="<?= e(asset('img/logo.svg')) ?>" alt=""></span><b style="font-size:1.2rem"><?= e(app_name()) ?></b></div>
    <?= illus('login-hero') ?>
    <?php $quotes = [['Alone we can do so little; together we can do so much.', 'Helen Keller'], ['Great things are done by a series of small things brought together.', 'Vincent van Gogh'], ['Arise, awake, and stop not till the goal is reached.', 'Swami Vivekananda'], ['Coming together is a beginning, staying together is progress, and working together is success.', 'Henry Ford'], ['It always seems impossible until it is done.', 'Nelson Mandela'], ['Done is better than perfect.', 'Sheryl Sandberg'], ['The secret of getting ahead is getting started.', 'Mark Twain']];
    [$q, $who] = $quotes[array_rand($quotes)]; ?>
    <figure class="auth-quote"><blockquote>“<?= e($q) ?>”</blockquote><figcaption><?= e($who) ?></figcaption></figure>
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
