<?php
namespace Modules\Ai\Controllers;
use Core\{App, Audit, Controller, DB, Response, Session, Settings, ValidationException, Validator};
use Modules\Ai\Services\{AiException, Http, ModelRegistry, Provider, Usage};

/** Settings › AI assistant (Super Admin): on/off, models (keys are write-only and encrypted), budgets and privacy. */
final class SettingsController extends Controller {
  public function index(): Response {
    $models = ModelRegistry::all();
    $used = Usage::monthUsed(); $budget = Usage::budget();
    return $this->view('ai::settings', ['title' => 'AI assistant · Settings', 'tab' => 'ai', 'tabs' => \Modules\Admin\Services\SettingsSchema::tabs(), 'models' => $models, 'presets' => ModelRegistry::presets(), 'providers' => ModelRegistry::providers(),
      'enabled' => Usage::enabled(), 'current' => ModelRegistry::current(), 'used' => $used, 'budget' => $budget, 'requestsToday' => (int) DB::value("SELECT COUNT(*) FROM ai_usage WHERE feature='chat' AND status='ok' AND created_at>=?", [date('Y-m-d 00:00:00')]),
      'edit' => Session::getFlash('ai_edit'), 'styles' => ['css/admin.css'], 'scripts' => ['js/admin.js']]); }

  /** Switch the assistant on or off (also used by the card in Settings › Features). */
  public function toggle(): Response {
    $on = !Usage::enabled();
    Settings::set('ai.enabled', $on ? '1' : '0');
    Audit::log($on ? 'feature.enable' : 'feature.disable', 'feature', null, ['key' => 'ai']);
    $this->flash($on ? 'AI assistant turned on.'.(ModelRegistry::usable(ModelRegistry::current()) ? '' : ' Add a model so people can start chatting.') : 'AI assistant turned off. Conversations are kept.');
    return $this->back(); }

  /** Budgets, limits and privacy. */
  public function save(): Response {
    $d = $this->validate(['monthly_token_budget' => 'required|integer|min:0|max:1000000000', 'user_daily_requests' => 'required|integer|min:0|max:100000', 'max_output_tokens' => 'required|integer|min:256|max:16000', 'redact_pii' => 'nullable'],
      ['monthly_token_budget' => 'Monthly token budget', 'user_daily_requests' => 'Daily questions per user', 'max_output_tokens' => 'Max answer length']);
    $save = ['ai.monthly_token_budget' => (string) $d['monthly_token_budget'], 'ai.user_daily_requests' => (string) $d['user_daily_requests'], 'ai.max_output_tokens' => (string) $d['max_output_tokens'], 'ai.redact_pii' => $this->request->input('redact_pii') ? '1' : '0'];
    $changed = array_keys(array_filter($save, static fn ($v, $k) => (string) Settings::get($k, '') !== $v, ARRAY_FILTER_USE_BOTH));
    Settings::setMany($save);
    if ($changed) { Audit::log('ai.settings.update', 'settings', null, ['changed' => $changed]); }
    $this->flash($changed ? 'AI settings saved.' : 'No changes to save.');
    return $this->redirect('/ai/settings'); }

  public function store(): Response { return $this->persist(null); }
  public function update(int $id): Response { $this->findOr404('ai_models', $id); return $this->persist($id); }

  private function persist(?int $id): Response {
    $in = $this->request->all();
    try { $row = self::validated($in, $id); }
    catch (ValidationException $e) {
      Session::flash('errors', $e->errors);
      Session::flash('old', array_diff_key($in, array_flip(['api_key', '_token'])));
      Session::flash('ai_edit', $id ?? 'new');
      return $this->redirect('/ai/settings'); }
    $now = date('Y-m-d H:i:s');
    $key = trim((string) ($in['api_key'] ?? ''));
    if ($key !== '') { $row['api_key_enc'] = ModelRegistry::encrypt($key); }
    elseif (!empty($in['clear_key'])) { $row['api_key_enc'] = null; }
    $isNew = $id === null;
    $def = !empty($in['is_default']) || !(int) DB::value('SELECT COUNT(*) FROM ai_models WHERE is_default=1'.($id ? ' AND id<>'.(int) $id : ''));
    DB::transaction(static function () use (&$id, $row, $now, $def) {
      if ($def) { DB::query('UPDATE ai_models SET is_default=0'); }
      $row['is_default'] = (int) $def;
      if ($id) { DB::update('ai_models', $row + ['updated_at' => $now], 'id=?', [$id]); }
      else { $id = DB::insert('ai_models', $row + ['created_at' => $now]); } });
    Audit::log($isNew ? 'ai.model.create' : 'ai.model.update', 'ai_model', $id, ['label' => $row['label'], 'provider' => $row['provider'], 'model' => $row['model'], 'key_changed' => $key !== '' || !empty($in['clear_key'])]);
    $this->flash('Model "'.$row['label'].'" saved.'.($def ? ' It is now the default.' : ''));
    return $this->redirect('/ai/settings'); }

  /** Validate a model form. Preset decides the protocol; custom servers pick it. */
  public static function validated(array $in, ?int $id): array {
    $presets = ModelRegistry::presets();
    $d = Validator::make($in, ['label' => 'required|string|max:100', 'preset' => 'required|in:'.implode(',', array_keys($presets)), 'model' => ['required', 'max:150', 'regex:/^[A-Za-z0-9._:\/@+\-]+$/'], 'base_url' => 'nullable|max:255',
      'api_key' => 'nullable|max:500', 'max_tokens' => 'nullable|integer|min:256|max:32000', 'temperature' => 'nullable|numeric|min:0|max:2'], ['label' => 'Name', 'model' => 'Model ID', 'base_url' => 'Base URL', 'api_key' => 'API key', 'max_tokens' => 'Max answer tokens']);
    $pr = $presets[$d['preset']];
    $provider = $d['preset'] === 'custom' ? (string) ($in['provider'] ?? 'openai_compatible') : $pr[1];
    if (!isset(ModelRegistry::providers()[$provider])) { throw new ValidationException(['provider' => 'Choose a supported API type.']); }
    $url = trim((string) ($d['base_url'] ?? '')) ?: (string) $pr[2];
    $errors = [];
    if ($provider !== 'mock') {
      if ($url === '') { $errors['base_url'] = 'Enter the base URL of the server, e.g. https://example.com/v1.'; }
      elseif (!Http::allowedUrl($url)) { $errors['base_url'] = 'Use an https:// address (plain http:// only for a local server such as Ollama).'; } }
    if ($provider === 'mock' && !in_array($d['model'], ['mock-openai', 'mock-anthropic'], true)) { $errors['model'] = 'The mock model is mock-openai or mock-anthropic.'; }
    $key = (string) ($in['api_key'] ?? '');
    if ($key !== '' && preg_match('/\s/', trim($key))) { $errors['api_key'] = 'That API key does not look valid.'; }
    if ($errors) { throw new ValidationException($errors); }
    return ['label' => $d['label'], 'preset' => $d['preset'], 'provider' => $provider, 'base_url' => $provider === 'mock' ? null : rtrim($url, '/'), 'model' => $d['model'], 'max_tokens' => ($d['max_tokens'] ?? null) ?: null, 'temperature' => ($d['temperature'] ?? null) === null || $d['temperature'] === '' ? null : (float) $d['temperature'], 'active' => empty($in['inactive']) ? 1 : 0]; }

  public function makeDefault(int $id): Response {
    $m = $this->findOr404('ai_models', $id);
    DB::transaction(static function () use ($id) { DB::query('UPDATE ai_models SET is_default=0'); DB::update('ai_models', ['is_default' => 1, 'active' => 1], 'id=?', [$id]); });
    Audit::log('ai.model.default', 'ai_model', $id, ['label' => $m['label']]);
    $this->flash('"'.$m['label'].'" is now the default model.');
    return $this->redirect('/ai/settings'); }

  public function destroy(int $id): Response {
    $m = $this->findOr404('ai_models', $id);
    DB::delete('ai_models', 'id=?', [$id]);
    if ($m['is_default']) { DB::query('UPDATE ai_models SET is_default=1 WHERE active=1 ORDER BY id LIMIT 1'); }
    Audit::log('ai.model.delete', 'ai_model', $id, ['label' => $m['label']]);
    $this->flash('Model "'.$m['label'].'" removed.');
    return $this->redirect('/ai/settings'); }

  /** Tiny round trip with the saved model (including a tool definition, so tool support is checked too). */
  public function test(int $id): Response {
    $m = ModelRegistry::withKey($this->findOr404('ai_models', $id));
    $t0 = microtime(true);
    try {
      $tool = ['name' => 'ping', 'description' => 'Connectivity check tool. Do not call it.', 'schema' => ['type' => 'object', 'properties' => new \stdClass()]];
      $r = Provider::make($m)->chat('You are a connectivity check. Reply with exactly: OK', [['role' => 'user', 'content' => 'Reply with OK.']], [$tool], ['max_tokens' => 1024, 'timeout' => 30]);
      Usage::log($this->uid(), $m['provider'], $r['model'], 'test', 'ok', null, $r['in'], $r['out']);
      Audit::log('ai.model.test', 'ai_model', $id, ['ok' => true]);
      return $this->json(['ok' => true, 'message' => 'Connected to '.$m['label'].' ('.$r['model'].') in '.round(microtime(true) - $t0, 1).'s. Reply: '.mb_substr($r['text'] ?: '(tool call)', 0, 80)]); }
    catch (AiException $e) {
      Usage::log($this->uid(), $m['provider'], $m['model'], 'test', 'error', $e->detail ?: $e->getMessage());
      Audit::log('ai.model.test', 'ai_model', $id, ['ok' => false]);
      return $this->json(['ok' => false, 'error' => $e->getMessage().(App::config('debug') && $e->detail ? ' ('.$e->detail.')' : '')], 502); } } }
