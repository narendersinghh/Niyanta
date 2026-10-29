<?php
/* Settings › AI assistant: on/off, models (presets), budgets & privacy. Vars: $tabs, $tab, $models, $presets, $providers, $enabled, $current, $used, $budget, $requestsToday, $edit */
use Modules\Ai\Services\{ModelRegistry, Usage};
$pct = $budget ? min(100, (int) round($used * 100 / $budget)) : 0;
$ready = $enabled && ModelRegistry::usable($current);
$o = static fn (string $k, mixed $d = '') => old($k, $d);
$editing = $edit !== null;
$editRow = $editing && $edit !== 'new' ? array_values(array_filter($models, static fn ($m) => (int) $m['id'] === (int) $edit))[0] ?? null : null;
$curPreset = (string) $o('preset', 'openai');
$tone = static fn (array $m) => ModelRegistry::presets()[$m['preset']][4] ?? 'lav';
?>
<div class="set-shell">
<?= partial('admin::settings/_nav', ['tabs' => $tabs, 'tab' => $tab]) ?>
<div class="set-main">
<?= partial('admin::settings/_head', ['tabs' => $tabs, 'tab' => $tab, 'actions' => '<a class="btn ghost" href="'.e(url('/ai/usage')).'">'.icon('cpu', 'sm').' Usage & costs</a>']) ?>

<section class="card ai-set-hero<?= $ready ? ' is-on' : '' ?>">
  <span class="ai-ava xl"><img src="<?= e(asset('img/logo.svg')) ?>" alt=""></span>
  <div class="grow">
    <span class="pill pill-<?= $ready ? 'mint' : ($enabled ? 'lemon' : 'gray') ?>"><?= $ready ? 'Live for everyone with access' : ($enabled ? 'On, but no model yet' : 'Off') ?></span>
    <h2>Niyu, your AI assistant</h2>
    <p class="muted small mb0">A chat drawer behind the <b>Ask Niyu</b> button in the top bar. Niyu answers from live workspace data using read-only tools that apply each person's own role and record access, so employees only see their own information and managers their team.</p>
  </div>
  <form method="post" action="<?= e(url('/ai/settings/toggle')) ?>" class="ai-set-switch" data-confirm="<?= $enabled ? 'Turn the AI assistant off for everyone? Conversations are kept.' : 'Turn the AI assistant on?' ?>"><?= csrf_field() ?>
    <label class="switch lg" title="<?= $enabled ? 'Turn off' : 'Turn on' ?>"><input type="checkbox" data-autosubmit<?= checked($enabled) ?> aria-label="AI assistant on"><span></span></label>
  </form>
</section>

<div class="grid g3 mini-stats ai-set-stats">
  <div class="tile bg-lav"><span class="tile-ic"><?= icon('cpu') ?></span><div><div class="num"><?= e(number_format($used)) ?></div><div class="lbl">Tokens this month<?= $budget ? ' · '.$pct.'% of budget' : '' ?></div></div></div>
  <div class="tile bg-mint"><span class="tile-ic"><?= icon('message') ?></span><div><div class="num"><?= (int) $requestsToday ?></div><div class="lbl">Questions answered today</div></div></div>
  <div class="tile bg-peach"><span class="tile-ic"><?= icon('layers') ?></span><div><div class="num"><?= count($models) ?></div><div class="lbl">Models configured</div></div></div>
</div>

<section class="card set-card">
  <header class="set-card-head flex between"><div><h3>Models</h3><p>Niyu uses the default model. Keys are stored encrypted and never shown again.</p></div><button type="button" class="btn primary sm" data-model-add><?= icon('plus', 'sm') ?> Add model</button></header>
  <?php if (!$models): ?>
    <?= empty_state('No model yet', 'Add ChatGPT, Claude, Gemini, OpenRouter, Groq, Mistral, DeepSeek, a local Ollama model or any OpenAI-compatible server.', 'ai-robot', '<button type="button" class="btn primary" data-model-add>'.icon('plus', 'sm').' Add your first model</button>') ?>
  <?php else: ?>
  <div class="ai-models">
  <?php foreach ($models as $m): $p = $presets[$m['preset']] ?? ModelRegistry::MOCK; ?>
    <div class="ai-model<?= $m['active'] ? '' : ' is-off' ?>">
      <span class="ic-chip sm" style="--c:var(--<?= e($tone($m)) ?>)"><?= icon($m['provider'] === 'anthropic' ? 'sparkles' : ($m['preset'] === 'ollama' ? 'server' : 'bot'), 'sm') ?></span>
      <div class="grow">
        <div class="flex wrap" style="gap:.4rem"><b><?= e($m['label']) ?></b><?php if ($m['is_default']): ?><span class="pill pill-mint">Default</span><?php endif ?><?php if (!$m['active']): ?><span class="pill pill-gray">Disabled</span><?php endif ?></div>
        <div class="xs muted"><?= e($p[0]) ?> · <span class="mono"><?= e($m['model']) ?></span><?= $m['base_url'] && $m['preset'] === 'custom' ? ' · '.e(parse_url($m['base_url'], PHP_URL_HOST) ?: '') : '' ?> · <?= $m['has_key'] ? icon('lock', 'sm').' key saved' : ($p[5] ? '<span class="ai-warn">no key</span>' : 'no key needed') ?></div>
        <div class="ai-test small muted" data-test-out="<?= (int) $m['id'] ?>"></div>
      </div>
      <div class="ai-model-act">
        <button type="button" class="btn ghost sm" data-model-test="<?= (int) $m['id'] ?>"><?= icon('zap', 'sm') ?> Test</button>
        <button type="button" class="btn ghost sm" data-model-edit="<?= e(json_encode(['id' => (int) $m['id'], 'label' => $m['label'], 'preset' => isset($presets[$m['preset']]) ? $m['preset'] : 'custom', 'provider' => $m['provider'], 'base_url' => $m['base_url'], 'model' => $m['model'], 'max_tokens' => $m['max_tokens'], 'temperature' => $m['temperature'], 'active' => (int) $m['active'], 'is_default' => (int) $m['is_default'], 'has_key' => $m['has_key']])) ?>"><?= icon('edit', 'sm') ?> Edit</button>
        <details class="dd"><summary class="btn icon sm ghost" aria-label="More actions"><?= icon('more', 'sm') ?></summary><div class="dd-menu">
          <?php if (!$m['is_default']): ?><form method="post" action="<?= e(url('/ai/models/'.$m['id'].'/default')) ?>"><?= csrf_field() ?><button type="submit"><?= icon('star') ?> Make default</button></form><?php endif ?>
          <form method="post" action="<?= e(url('/ai/models/'.$m['id'].'/delete')) ?>" data-confirm="Remove <?= e($m['label']) ?>? Its API key is deleted too."><?= csrf_field() ?><button type="submit" class="danger"><?= icon('trash') ?> Remove</button></form>
        </div></details>
      </div>
    </div>
  <?php endforeach ?>
  </div>
  <?php endif ?>
</section>

<form class="set-form" method="post" action="<?= e(url('/ai/settings')) ?>" data-dirty-watch><?= csrf_field() ?>
<section class="card set-card">
  <header class="set-card-head"><h3>Budgets &amp; limits</h3><p>Keep costs predictable. 0 means no limit.</p></header>
  <div class="form-grid">
    <div class="field<?= error_for('monthly_token_budget') ? ' has-error' : '' ?>"><label for="ai-budget">Monthly token budget</label><div class="input-unit"><input type="number" id="ai-budget" name="monthly_token_budget" min="0" max="1000000000" value="<?= e($o('monthly_token_budget', (string) Usage::budget())) ?>" required><span>tokens</span></div><div class="hint">Chats stop for everyone once reached; resets on the 1st.</div><?= error_for('monthly_token_budget') ?></div>
    <div class="field<?= error_for('user_daily_requests') ? ' has-error' : '' ?>"><label for="ai-daily">Questions per person per day</label><input type="number" id="ai-daily" name="user_daily_requests" min="0" max="100000" value="<?= e($o('user_daily_requests', (string) Usage::dailyLimit())) ?>" required><?= error_for('user_daily_requests') ?></div>
    <div class="field<?= error_for('max_output_tokens') ? ' has-error' : '' ?>"><label for="ai-max">Max answer length</label><div class="input-unit"><input type="number" id="ai-max" name="max_output_tokens" min="256" max="16000" value="<?= e($o('max_output_tokens', (string) Usage::maxTokens())) ?>" required><span>tokens</span></div><div class="hint">Default for models without their own limit.</div><?= error_for('max_output_tokens') ?></div>
  </div>
</section>
<section class="card set-card">
  <header class="set-card-head"><h3>Privacy</h3><p>Niyu never sees passwords, API keys, salaries, bank or tax details, ID documents or HR notes, and only reads what the person asking may open.</p></header>
  <div class="form-grid"><div class="field full"><label class="switch-row" for="ai-redact"><span class="switch"><input type="checkbox" id="ai-redact" name="redact_pii" value="1"<?= checked(Usage::redact()) ?>><span></span></span><span class="t">Mask emails, phone numbers and account numbers before sending to cloud providers</span></label>
  <div class="hint">Local models (Ollama, LM Studio on your network) always receive unmasked text.</div></div></div>
</section>
<div class="save-bar"><span class="sb-note"><span class="sb-dot"></span><span data-dirty-text>All changes saved</span></span><span class="xs muted sb-sub">Applies to everyone · audit-logged</span><button type="submit" class="btn primary"><?= icon('check', 'sm') ?> Save changes</button></div>
</form>

<div class="grid g2 mt">
  <div class="card info-card"><span class="ic-chip" style="--c:var(--lav)"><?= icon('shield') ?></span><div class="grow"><h4>Role-based answers</h4><div class="small muted">Employees get their own tasks, timesheets, attendance, leave and payslips. Managers can ask about their direct reports and project teams; HR about anyone. Who may use Niyu is the <b>Chat with the AI assistant</b> permission in <a href="<?= e(url('/admin/roles')) ?>">Roles &amp; Access</a>.</div></div></div>
  <div class="card info-card"><span class="ic-chip" style="--c:var(--mint)"><?= icon('puzzle') ?></span><div class="grow"><h4>Pick a model with tool calling</h4><div class="small muted">Niyu looks data up through function calls, so choose a model that supports tools. Use <b>Test</b> after adding a model; it checks the key, the address and tool support in one go.</div></div></div>
</div>
</div>
</div>

<dialog class="modal wide" id="ai-model-dialog"<?= $editing ? ' data-open' : '' ?>>
  <div class="modal-head"><h3 data-model-title><?= $editRow ? 'Edit model' : 'Add a model' ?></h3><button type="button" class="btn icon sm ghost" data-close aria-label="Close"><?= icon('x', 'sm') ?></button></div>
  <form class="modal-body form" method="post" action="<?= e(url($editRow ? '/ai/models/'.$editRow['id'] : '/ai/models')) ?>" data-ai-model-form autocomplete="off"><?= csrf_field() ?>
    <input type="hidden" name="preset" value="<?= e($curPreset) ?>">
    <div class="ai-presets" role="group" aria-label="Provider">
    <?php foreach ($presets as $k => [$name, $prov, $purl, $list, $color, $needsKey, $hint]): ?>
      <button type="button" class="ai-preset<?= $k === $curPreset ? ' on' : '' ?>" style="--c:var(--<?= e($color) ?>)" data-preset="<?= e($k) ?>" data-provider="<?= e($prov) ?>" data-url="<?= e($purl) ?>" data-models="<?= e(json_encode($list)) ?>" data-name="<?= e(preg_replace('/\s*\(.*\)$/', '', $name)) ?>" data-hint="<?= e($hint) ?>" data-needs-key="<?= $needsKey ? '1' : '0' ?>">
        <span class="ai-preset-mark"><?= e(mb_strtoupper(mb_substr($name, 0, 1))) ?></span><span><b><?= e($name) ?></b><small><?= e($prov === 'anthropic' ? 'Messages API' : ($prov === 'mock' ? 'Offline test' : 'OpenAI-compatible')) ?></small></span>
      </button>
    <?php endforeach ?>
    </div>
    <p class="ai-preset-hint" data-preset-hint><?= e($presets[$curPreset][6] ?? '') ?></p>
    <div class="form-grid">
      <div class="field<?= error_for('label') ? ' has-error' : '' ?>"><label for="aim-label">Display name</label><input id="aim-label" name="label" maxlength="100" required value="<?= e($o('label')) ?>" placeholder="e.g. ChatGPT"><?= error_for('label') ?></div>
      <div class="field<?= error_for('model') ? ' has-error' : '' ?>"><label for="aim-model">Model ID</label><input id="aim-model" name="model" list="ai-model-list" maxlength="150" required value="<?= e($o('model')) ?>" placeholder="Pick a suggestion or type any model id"><datalist id="ai-model-list"></datalist><?= error_for('model') ?></div>
      <div class="field full<?= error_for('base_url') ? ' has-error' : '' ?>"><label for="aim-url">Base URL</label><input id="aim-url" name="base_url" maxlength="255" value="<?= e($o('base_url')) ?>" placeholder="https://example.com/v1"><div class="hint">Filled in from the provider. Change it for proxies, gateways or self-hosted servers.</div><?= error_for('base_url') ?></div>
      <div class="field full" data-custom-only<?= $curPreset === 'custom' ? '' : ' hidden' ?>><label for="aim-prov">API type</label><select id="aim-prov" name="provider"><?php foreach (ModelRegistry::PROVIDERS as $pk => $pl): ?><option value="<?= e($pk) ?>"<?= selected($pk, (string) $o('provider', 'openai_compatible')) ?>><?= e($pl) ?></option><?php endforeach ?></select><?= error_for('provider') ?></div>
      <div class="field full<?= error_for('api_key') ? ' has-error' : '' ?>" data-key-row><label for="aim-key">API key</label><input type="password" id="aim-key" name="api_key" autocomplete="new-password" maxlength="500" data-saved="<?= $editRow && $editRow['has_key'] ? '1' : '' ?>" placeholder="<?= $editRow && $editRow['has_key'] ? '•••• saved, leave blank to keep' : 'Paste your API key' ?>">
        <div class="flex between"><span class="secret-set xs"><?= icon('lock', 'sm') ?> Stored encrypted, never shown again</span><label class="check xs" data-clear-key<?= $editRow && $editRow['has_key'] ? '' : ' hidden' ?>><input type="checkbox" name="clear_key" value="1"> Remove saved key</label></div><?= error_for('api_key') ?></div>
      <div class="field<?= error_for('max_tokens') ? ' has-error' : '' ?>"><label for="aim-max">Max answer tokens <span class="muted">(optional)</span></label><input type="number" id="aim-max" name="max_tokens" min="256" max="32000" value="<?= e($o('max_tokens')) ?>" placeholder="<?= (int) Usage::maxTokens() ?>"><?= error_for('max_tokens') ?></div>
      <div class="field<?= error_for('temperature') ? ' has-error' : '' ?>"><label for="aim-temp">Temperature <span class="muted">(optional)</span></label><input type="number" id="aim-temp" name="temperature" min="0" max="2" step="0.1" value="<?= e($o('temperature')) ?>" placeholder="Provider default"><div class="hint">Ignored for Claude, which sets its own.</div><?= error_for('temperature') ?></div>
      <div class="field full flex wrap" style="gap:1.4rem">
        <label class="switch-row"><span class="switch"><input type="checkbox" name="is_default" value="1"<?= checked((bool) $o('is_default', $models ? '' : '1')) ?>><span></span></span><span class="t">Use as default</span></label>
        <label class="switch-row"><span class="switch"><input type="checkbox" name="inactive" value="1"<?= checked((bool) $o('inactive')) ?>><span></span></span><span class="t">Disabled</span></label>
      </div>
    </div>
    <div class="form-actions"><button type="button" class="btn ghost" data-close>Cancel</button><button type="submit" class="btn primary"><?= icon('check', 'sm') ?> Save model</button></div>
  </form>
</dialog>
