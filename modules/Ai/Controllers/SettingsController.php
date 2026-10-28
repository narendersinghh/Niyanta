<?php
namespace Modules\Ai\Controllers;
use Core\{Audit, Controller, Response, Session, Settings, ValidationException, Validator};
use Modules\Ai\Services\{AiClient, AiException};

/** Super-admin AI configuration. API keys are write-only and stored encrypted (Settings::SECRETS). */
final class SettingsController extends Controller {
  public const KEYS = ['openai', 'anthropic', 'openrouter'];

  public function index(): Response { return $this->view('ai::settings_page', ['title' => 'AI settings', 'scripts' => ['js/ai.js'], 'styles' => ['css/ai.css']]); }

  public function save(): Response {
    try { return $this->persist($this->request->arr('ai')); }
    catch (ValidationException $e) {
      // Handle validation ourselves so API keys are never flashed into the session.
      $old = array_diff_key($this->request->arr('ai'), array_flip(array_merge(array_map(static fn ($p) => $p.'_key', self::KEYS), array_map(static fn ($p) => 'clear_'.$p.'_key', self::KEYS))));
      Session::flash('errors', $e->errors);
      Session::flash('old', ['ai' => $old]);
      return $this->redirect('/ai/settings'); } }

  private function persist(array $in): Response {
    $model = 'nullable|max:100|regex:/^[A-Za-z0-9._:\/@\-]+$/';
    $d = Validator::make($in, ['enabled' => 'boolean', 'provider' => 'required|in:'.implode(',', array_keys(AiClient::providers())), 'model_openai' => $model, 'model_anthropic' => $model, 'model_openrouter' => $model,
      'max_output_tokens' => 'required|integer|min:64|max:8000', 'monthly_token_budget' => 'required|integer|min:0|max:1000000000', 'user_daily_requests' => 'required|integer|min:0|max:100000', 'redact_pii' => 'boolean', 'temperature' => 'nullable|numeric|min:0|max:2'],
      ['provider' => 'Provider', 'max_output_tokens' => 'Max output tokens', 'monthly_token_budget' => 'Monthly token budget', 'user_daily_requests' => 'Daily requests per user', 'model_openai' => 'OpenAI model', 'model_anthropic' => 'Anthropic model', 'model_openrouter' => 'OpenRouter model']);
    $save = [];
    foreach ($d as $k => $v) { $save['ai.'.$k] = $v === null ? '' : (string) $v; }
    $keys = [];
    foreach (self::KEYS as $p) {
      $new = trim((string) ($in[$p.'_key'] ?? ''));
      if (!empty($in['clear_'.$p.'_key'])) { $save['ai.'.$p.'_key'] = null; $keys[] = $p.':cleared'; }
      elseif ($new !== '') {
        if (mb_strlen($new) > 300 || preg_match('/\s/', $new)) { throw new ValidationException([$p.'_key' => 'That API key does not look valid.']); }
        $save['ai.'.$p.'_key'] = $new; $keys[] = $p.':updated'; } }
    $before = array_map(static fn ($k) => (string) Settings::get($k, ''), array_combine(array_keys($save), array_keys($save)));
    Settings::setMany($save);
    $changed = array_keys(array_filter($save, static fn ($v, $k) => !str_ends_with($k, '_key') && (string) $v !== $before[$k], ARRAY_FILTER_USE_BOTH));
    Audit::log('ai.settings.update', 'settings', null, ['changed' => $changed, 'keys' => $keys]);
    $this->flash('AI settings saved.');
    return $this->redirect('/ai/settings'); }

  /** POST /ai/settings/test — tiny prompt against the saved provider. */
  public function test(): Response {
    try {
      $r = AiClient::ask('test', 'You are a connectivity check. Reply with exactly: OK', 'Reply with OK.', ['test' => true, 'max_tokens' => 200]);
      Audit::log('ai.settings.test', 'settings', null, ['ok' => true, 'provider' => AiClient::provider()]);
      return $this->json(['ok' => true, 'text' => 'Connected to '.(AiClient::providers()[AiClient::provider()] ?? AiClient::provider()).' ('.$r['model'].'). Reply: '.mb_substr($r['text'], 0, 200), 'tokens' => $r['input_tokens'] + $r['output_tokens']]); }
    catch (AiException $e) {
      Audit::log('ai.settings.test', 'settings', null, ['ok' => false, 'provider' => AiClient::provider()]);
      return $this->json(['ok' => false, 'error' => $e->getMessage()], 502); } } }
