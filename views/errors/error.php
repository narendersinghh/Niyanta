<?php $art = ['404' => 'not-found', '403' => 'lock-door', '419' => 'lock-door', '429' => 'coffee-break'][$code] ?? 'error-500'; ?>
<div class="err-page">
  <?= illus($kind === 'module' ? 'lock-door' : $art) ?>
  <div class="code"><?= (int) $code ?></div>
  <h2><?= e(['404' => 'Lost in space', '403' => 'Access denied', '419' => 'Session expired', '429' => 'Take a breather'][$code] ?? 'Something went wrong') ?></h2>
  <p class="muted"><?= e($message) ?></p>
  <a class="btn primary" href="<?= e(url('/')) ?>"><?= icon('home', 'sm') ?> Back to dashboard</a>
</div>
