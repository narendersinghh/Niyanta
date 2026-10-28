<div class="err-page">
  <?= illus('error-500') ?>
  <div class="code">500</div>
  <h2>Something broke on our side</h2>
  <p class="muted">The error has been logged. Reference: <span class="mono"><?= e($ref) ?></span></p>
  <?php if (!empty($debug)): ?><pre class="card" style="text-align:left;overflow:auto;font-size:12px"><?= e($debug::class.': '.$debug->getMessage()."\n".$debug->getFile().':'.$debug->getLine()."\n\n".$debug->getTraceAsString()) ?></pre><?php endif ?>
  <a class="btn primary" href="<?= e(url('/')) ?>">Back to dashboard</a>
</div>
