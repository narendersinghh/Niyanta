<?= partial('partials/page_head', ['title' => 'AI Assistant', 'crumbs' => [['Intelligence'], ['AI Assistant']]]) ?>
<div class="card ai-disabled">
  <?= illus('ai-robot') ?>
  <h2>The AI assistant is taking a nap</h2>
  <p class="muted">AI features are currently turned off for this workspace. Your data is not shared with any AI provider while it is off.</p>
  <?php if (user()['role_slug'] === 'super_admin'): ?>
  <a class="btn primary" href="<?= e(url('/ai/settings')) ?>"><?= icon('settings', 'sm') ?> Open AI settings</a>
  <?php else: ?>
  <p class="small muted mb0">Ask your administrator if you would like to use AI summaries, drafts and search.</p>
  <?php endif ?>
</div>
