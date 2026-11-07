<?php
use Modules\Attendance\Services\DesktopConnect;
/* Flow B consent page: $p (challenge, state, device, os) or null, $error, $cancelled, $u (signed-in user), $self (this URL, for "Not you?") */
?>
<div class="cx-wrap"><?= partial('partials/auth_tools') ?>
  <div class="card cx-card">
    <div class="cx-brand"><span class="brand-logo"><img src="<?= e(asset('img/logo.svg')) ?>" alt=""></span><b><?= e(company_name()) ?></b></div>
    <?php if (!empty($error)): ?>
      <span class="cx-ic cx-warn"><?= icon('alert') ?></span>
      <h1>This link did not work</h1><p class="muted"><?= e($error) ?></p>
      <a class="btn ghost" href="<?= e(url('/time#desktop')) ?>"><?= icon('arrow-left', 'sm') ?> Back to Time Tracking</a>
    <?php elseif (!empty($cancelled)): ?>
      <span class="cx-ic"><?= icon('x') ?></span>
      <h1>Not connected</h1><p class="muted">Niyanta Tracker was not signed in. You can close this tab.</p>
    <?php else: ?>
      <span class="cx-ic"><?= icon('monitor') ?></span>
      <h1>Connect Niyanta Tracker?</h1>
      <p class="muted">The desktop app on this computer asks to sign in to <b><?= e(company_name()) ?></b> as you. Allow it only if you just clicked <i>Sign in with your browser</i> in the app.</p>
      <dl class="cx-facts">
        <div><dt>Signed in as</dt><dd><b><?= e($u['name']) ?></b><span class="xs muted"><?= e($u['email']) ?></span></dd></div>
        <div><dt>Device</dt><dd><b><?= e($p['device'] ?? 'This computer') ?></b><span class="xs muted"><?= e($p['os'] ? DesktopConnect::OS[$p['os']] : 'Desktop') ?></span></dd></div>
        <div><dt>Workspace</dt><dd><span class="mono xs"><?= e(absolute_url('/')) ?></span></dd></div>
      </dl>
      <form method="post" action="<?= e(url('/time/desktop/connect')) ?>" class="cx-actions"><?= csrf_field() ?>
        <?php foreach (['challenge', 'state', 'device', 'os'] as $k): ?><input type="hidden" name="<?= $k ?>" value="<?= e((string) ($p[$k] ?? '')) ?>"><?php endforeach ?>
        <button class="btn primary" name="decision" value="allow"><?= icon('check', 'sm') ?> Allow</button>
        <button class="btn ghost" name="decision" value="cancel">Cancel</button></form>
      <p class="xs muted mb0">The app stays signed in even when you sign out of the website. You can remove it any time under <a href="<?= e(url('/time#devices')) ?>">Your devices</a>.</p>
      <form method="post" action="<?= e(url('/logout')) ?>" class="cx-out"><?= csrf_field() ?><input type="hidden" name="next" value="<?= e($self) ?>"><span class="small muted">Not you?</span> <button type="submit" class="cx-link small">Sign out</button></form>
    <?php endif ?>
  </div>
</div>
