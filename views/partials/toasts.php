<?php $t = \Core\Session::getFlash('toast'); $errs = errors(); ?>
<div class="toasts" aria-live="polite" data-toasts>
<?php if ($t): ?><div class="toast <?= $t['type'] === 'error' ? 'error' : '' ?>"><span class="t-ic"><?= icon($t['type'] === 'error' ? 'alert' : 'check', 'sm') ?></span><?= e($t['msg']) ?></div><?php endif ?>
<?php if ($errs && !$t): ?><div class="toast error"><span class="t-ic"><?= icon('alert', 'sm') ?></span><?= e(count($errs) > 1 ? 'Please fix the highlighted fields.' : reset($errs)) ?></div><?php endif ?>
</div>
