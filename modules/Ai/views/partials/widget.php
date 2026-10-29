<?php $pending = (int) Core\DB::value("SELECT COUNT(*) FROM ai_actions WHERE user_id=? AND status='pending'", [$u['id']]); ?>
<div class="card ai-widget">
  <?= illus('ai-robot') ?>
  <h3 class="mb0">AI Assistant</h3>
  <p class="small muted">Summaries, drafts and reports in seconds, always reviewed by you.</p>
  <div class="quick">
    <a href="<?= e(url('/ai')) ?>"><?= icon('sparkles', 'sm') ?> Open tools</a>
    <?php if (can('ai.search')): ?><a href="<?= e(url('/ai/search')) ?>"><?= icon('search', 'sm') ?> Ask</a><?php endif ?>
    <a href="<?= e(url('/ai/actions')) ?>"><?= icon('clipboard', 'sm') ?> Approvals<?= $pending ? ' · '.$pending : '' ?></a>
  </div>
</div>
