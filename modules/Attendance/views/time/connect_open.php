<?php /* Opens the app with a one-time code: a normal link plus js/tt-connect.js (no inline JS). $link (deep link), $mode (web|browser) */ ?>
<div class="cx-wrap"><?= partial('partials/auth_tools') ?>
  <div class="card cx-card">
    <div class="cx-brand"><span class="brand-logo"><img src="<?= e(asset('img/logo.svg')) ?>" alt=""></span><b><?= e(company_name()) ?></b></div>
    <span class="cx-ic cx-ok"><?= icon('check') ?></span>
    <h1><?= $mode === 'browser' ? 'You can close this tab' : 'Opening Niyanta Tracker' ?></h1>
    <p class="muted"><?= $mode === 'browser' ? 'Niyanta Tracker is signing you in. If your browser asks, choose <b>Open Niyanta Tracker</b>.' : 'Your browser opens the desktop app and signs it in. If it asks, choose <b>Open Niyanta Tracker</b>.' ?></p>
    <div class="cx-actions"><a class="btn primary" href="<?= e($link) ?>" data-deeplink><?= icon('external', 'sm') ?> Open Niyanta Tracker</a></div>
    <p class="small muted">This link works once, for 2 minutes. The app stays signed in even when you sign out of the website.</p>
    <p class="small mb0">Nothing happened? <a href="<?= e(url('/time#desktop')) ?>">Install Niyanta Tracker first</a>, then click <b>Connect the desktop app</b> again.</p>
  </div>
</div>
