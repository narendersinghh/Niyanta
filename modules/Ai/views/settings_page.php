<?= partial('partials/page_head', ['title' => 'AI settings', 'subtitle' => 'Choose a provider, add keys and set cost & privacy guard-rails.', 'crumbs' => [['System'], ['AI settings']], 'actions' => '<a class="btn ghost" href="'.e(url('/ai/usage')).'">'.icon('activity', 'sm').' Usage</a><a class="btn ghost" href="'.e(url('/ai')).'">'.icon('sparkles', 'sm').' Assistant</a>']) ?>
<div class="grid g-side">
  <form class="card" method="post" action="<?= e(url('/ai/settings')) ?>"><?= csrf_field() ?>
    <div class="card-head"><h3>Configuration</h3><?= Modules\Ai\Services\AiClient::enabled() ? badge('active', 'Enabled') : badge('inactive', 'Disabled') ?></div>
    <?= partial('ai::settings') ?>
    <div class="form-actions mt"><button class="btn primary" type="submit"><?= icon('check', 'sm') ?> Save settings</button></div>
  </form>
  <div class="stack">
    <div class="card ai-widget"><?= illus('ai-robot') ?><h3 class="mb0">Test connection</h3><p class="small muted">Sends a tiny prompt with the <b>saved</b> provider, model and key.</p>
      <form method="post" action="<?= e(url('/ai/settings/test')) ?>" data-ai-test><?= csrf_field() ?><button class="btn primary" type="submit"><?= icon('zap', 'sm') ?> Test connection</button></form>
      <div class="ai-out ai-test-out hide" data-ai-test-out aria-live="polite"></div>
    </div>
    <div class="card"><div class="card-head"><h4>How your data is protected</h4></div>
      <div class="list small">
        <div class="list-item"><?= icon('shield', 'sm') ?><span>Only records the requesting user can already see are used.</span></div>
        <div class="list-item"><?= icon('lock', 'sm') ?><span>Keys are encrypted at rest and never shown again.</span></div>
        <div class="list-item"><?= icon('eye', 'sm') ?><span>No salaries, bank/tax IDs or HR documents are ever sent.</span></div>
        <div class="list-item"><?= icon('check-circle', 'sm') ?><span>Nothing is created or emailed without explicit approval.</span></div>
      </div>
    </div>
  </div>
</div>
