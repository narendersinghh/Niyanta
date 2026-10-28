<?php
/* AI settings fields (names: ai[...]). Rendered by /ai/settings and usable as the module's settings_view. API keys are write-only. */
use Modules\Ai\Services\AiClient;
$o = old('ai', []);
$v = static fn (string $k, mixed $d = '') => is_array($o) && array_key_exists($k, $o) ? (string) $o[$k] : (string) setting('ai.'.$k, $d);
$prov = (string) $v('provider', 'anthropic');
$keyRow = static function (string $p, string $label, string $hint): string {
  $saved = AiClient::hasKey($p);
  return '<div class="field"><label for="ai-'.$p.'-key">'.e($label).' API key</label><div class="ai-key"><input id="ai-'.$p.'-key" type="password" name="ai['.$p.'_key]" autocomplete="new-password" spellcheck="false" placeholder="'.($saved ? '•••• saved — leave blank to keep' : 'Paste key').'">'.($saved ? '<span class="pill pill-mint">'.icon('check', 'sm').' saved</span>' : '<span class="pill pill-gray">not set</span>').'</div>'
    .($saved ? '<label class="check xs mt"><input type="checkbox" name="ai[clear_'.$p.'_key]" value="1"> Remove saved key</label>' : '').'<div class="hint">'.e($hint).'</div>'.error_for($p.'_key').'</div>'; };
?>
<div class="form">
  <div class="flex between wrap"><div><b>Enable AI assistant</b><div class="xs muted">When off, nothing is sent to any provider and AI pages show a friendly notice.</div></div><label class="switch"><input type="hidden" name="ai[enabled]" value="0"><input type="checkbox" name="ai[enabled]" value="1"<?= checked($v('enabled', '0') === '1') ?> aria-label="Enable AI assistant"><span></span></label></div>
  <hr>
  <div class="form-grid">
    <div class="field"><label for="ai-provider">Provider</label><select id="ai-provider" name="ai[provider]"><?php foreach (AiClient::providers() as $k => $l): ?><option value="<?= e($k) ?>"<?= selected($prov, $k) ?>><?= e($l) ?></option><?php endforeach ?></select><?= error_for('provider') ?></div>
    <div class="field"><label for="ai-temp">Temperature</label><input id="ai-temp" type="number" name="ai[temperature]" min="0" max="2" step="0.1" value="<?= e($v('temperature', '')) ?>" placeholder="Model default"><div class="hint">OpenAI / OpenRouter only. Anthropic uses model defaults.</div><?= error_for('temperature') ?></div>
    <div class="field"><label for="ai-m-anthropic">Anthropic model</label><input id="ai-m-anthropic" type="text" name="ai[model_anthropic]" maxlength="100" value="<?= e($v('model_anthropic', '')) ?>" placeholder="<?= e(AiClient::DEFAULT_MODELS['anthropic']) ?>"><?= error_for('model_anthropic') ?></div>
    <?= $keyRow('anthropic', 'Anthropic', 'From console.anthropic.com → API keys.') ?>
    <div class="field"><label for="ai-m-openai">OpenAI model</label><input id="ai-m-openai" type="text" name="ai[model_openai]" maxlength="100" value="<?= e($v('model_openai', '')) ?>" placeholder="<?= e(AiClient::DEFAULT_MODELS['openai']) ?>"><?= error_for('model_openai') ?></div>
    <?= $keyRow('openai', 'OpenAI', 'From platform.openai.com → API keys.') ?>
    <div class="field"><label for="ai-m-openrouter">OpenRouter model</label><input id="ai-m-openrouter" type="text" name="ai[model_openrouter]" maxlength="100" value="<?= e($v('model_openrouter', '')) ?>" placeholder="<?= e(AiClient::DEFAULT_MODELS['openrouter']) ?>"><?= error_for('model_openrouter') ?></div>
    <?= $keyRow('openrouter', 'OpenRouter', 'From openrouter.ai → Keys.') ?>
  </div>
  <hr>
  <div class="form-grid g3">
    <div class="field"><label for="ai-max">Max output tokens</label><input id="ai-max" type="number" name="ai[max_output_tokens]" min="64" max="8000" required value="<?= e($v('max_output_tokens', '800')) ?>"><div class="hint">Per answer. 800 suits summaries and drafts.</div><?= error_for('max_output_tokens') ?></div>
    <div class="field"><label for="ai-budget">Monthly token budget</label><input id="ai-budget" type="number" name="ai[monthly_token_budget]" min="0" required value="<?= e($v('monthly_token_budget', '500000')) ?>"><div class="hint">Whole workspace. 0 = unlimited.</div><?= error_for('monthly_token_budget') ?></div>
    <div class="field"><label for="ai-daily">Daily requests per user</label><input id="ai-daily" type="number" name="ai[user_daily_requests]" min="0" required value="<?= e($v('user_daily_requests', '50')) ?>"><div class="hint">0 = unlimited (a 10/minute burst limit still applies).</div><?= error_for('user_daily_requests') ?></div>
  </div>
  <div class="flex between wrap"><div><b>Redact personal data</b><div class="xs muted">Replace emails, phone numbers, card/account numbers and tax IDs with placeholders before anything is sent.</div></div><label class="switch"><input type="hidden" name="ai[redact_pii]" value="0"><input type="checkbox" name="ai[redact_pii]" value="1"<?= checked($v('redact_pii', '1') === '1') ?> aria-label="Redact personal data"><span></span></label></div>
</div>
