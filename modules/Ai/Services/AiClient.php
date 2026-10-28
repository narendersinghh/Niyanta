<?php
namespace Modules\Ai\Services;
use Core\{App, Auth, DB, RateLimiter, Settings};

/** Entry point for every model call: guards (enabled, budget, daily quota, rate limit), redaction, provider dispatch and usage logging. */
final class AiClient {
  public const PROVIDERS = ['anthropic' => 'Anthropic Claude', 'openai' => 'OpenAI', 'openrouter' => 'OpenRouter'];
  public const DEFAULT_MODELS = ['anthropic' => 'claude-opus-5-5', 'openai' => 'gpt-4.1-mini', 'openrouter' => 'openai/gpt-4.1-mini', 'mock' => 'mock-1'];
  public const MAX_INPUT_CHARS = 16000;
  private static ?ProviderInterface $fake = null;

  /** Inject a provider (tests). Pass null to restore normal behaviour. */
  public static function fake(?ProviderInterface $p): void { self::$fake = $p; }

  /** Providers selectable in settings; the mock provider only exists in debug mode. */
  public static function providers(): array { return self::PROVIDERS + (App::config('debug') ? ['mock' => 'Mock (debug only)'] : []); }
  public static function enabled(): bool { return Settings::bool('ai.enabled'); }
  public static function provider(): string { $p = (string) Settings::get('ai.provider', 'anthropic'); return isset(self::providers()[$p]) ? $p : 'anthropic'; }
  public static function model(?string $p = null): string { $p ??= self::provider(); return $p === 'mock' ? 'mock-1' : (trim((string) Settings::get('ai.model_'.$p, '')) ?: self::DEFAULT_MODELS[$p]); }
  public static function hasKey(string $p): bool { return $p === 'mock' || (string) Settings::get('ai.'.$p.'_key', '') !== ''; }
  public static function ready(): bool { return self::$fake !== null || (self::enabled() && self::hasKey(self::provider())); }
  public static function budget(): int { return max(0, (int) Settings::get('ai.monthly_token_budget', 500000)); }
  public static function dailyLimit(): int { return max(0, (int) Settings::get('ai.user_daily_requests', 50)); }
  public static function maxTokens(): int { return max(64, min(8000, (int) Settings::get('ai.max_output_tokens', 800))); }

  public static function monthUsed(): int { return (int) DB::value("SELECT COALESCE(SUM(input_tokens+output_tokens),0) FROM ai_usage WHERE status='ok' AND created_at>=?", [date('Y-m-01 00:00:00')]); }
  public static function userToday(int $uid): int { return (int) DB::value("SELECT COUNT(*) FROM ai_usage WHERE user_id=? AND status<>'blocked' AND created_at>=?", [$uid, date('Y-m-d 00:00:00')]); }

  public static function make(?string $p = null): ProviderInterface {
    if (self::$fake) { return self::$fake; }
    $p ??= self::provider();
    $key = (string) Settings::get('ai.'.$p.'_key', '');
    return match ($p) {
      'openai' => new OpenAiProvider($key, self::model('openai')),
      'openrouter' => new OpenRouterProvider($key, self::model('openrouter')),
      'mock' => App::config('debug') ? new MockProvider() : throw new AiException('The AI provider is not configured.', 'Mock provider requested while debug is off'),
      default => new AnthropicProvider($key, self::model('anthropic')) }; }

  /**
   * Run one completion for $feature. Returns ['text','model','input_tokens','output_tokens'].
   * Options: max_tokens (capped by settings), test (bool: settings connection test — skips enabled/quota checks).
   * Throws AiException with a user-safe message; every attempt is logged in ai_usage.
   */
  public static function ask(string $feature, string $system, string $prompt, array $opts = []): array {
    $uid = Auth::id();
    $test = !empty($opts['test']);
    $pname = self::$fake ? self::$fake->name() : self::provider();
    $model = self::$fake ? self::$fake->model() : self::model($pname);
    if (!$test && !self::enabled() && !self::$fake) { throw new AiException('The AI assistant is turned off.', 'disabled', 'disabled'); }
    if (!$test) {
      $budget = self::budget();
      if ($budget > 0 && self::monthUsed() >= $budget) { self::log($uid, $pname, $model, $feature, 'blocked', 'Monthly token budget reached'); throw new AiException('This month\'s AI token budget has been used up. It resets on the 1st, or an administrator can raise it.', 'budget', 'blocked'); }
      $daily = self::dailyLimit();
      if ($uid && $daily > 0 && self::userToday($uid) >= $daily) { self::log($uid, $pname, $model, $feature, 'blocked', 'Daily request limit reached'); throw new AiException("You've reached today's limit of {$daily} AI requests. Please try again tomorrow.", 'daily', 'blocked'); }
      if ($uid && !RateLimiter::hit('ai:user:'.$uid, 10, 60)) { self::log($uid, $pname, $model, $feature, 'blocked', 'Rate limited'); throw new AiException('You are sending AI requests too quickly. Please wait a minute.', 'rate', 'blocked'); } }
    $redact = Settings::bool('ai.redact_pii', true);
    $prompt = mb_substr($prompt, 0, self::MAX_INPUT_CHARS);
    if ($redact) { $prompt = Redactor::redact($prompt); }
    $o = ['max_tokens' => min(self::maxTokens(), (int) ($opts['max_tokens'] ?? PHP_INT_MAX)), 'feature' => $feature];
    $t = Settings::get('ai.temperature');
    if ($t !== null && $t !== '' && is_numeric($t)) { $o['temperature'] = max(0, min(2, (float) $t)); }
    try {
      $r = self::make($pname)->complete([['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $prompt]], $o);
      self::log($uid, $pname, (string) ($r['model'] ?: $model), $feature, 'ok', null, (int) $r['input_tokens'], (int) $r['output_tokens']);
      return $r; }
    catch (AiException $e) { self::log($uid, $pname, $model, $feature, 'error', $e->detail ?: $e->getMessage()); throw $e; }
    catch (\Throwable $e) {
      error_log('AI '.$feature.': '.$e->getMessage());
      self::log($uid, $pname, $model, $feature, 'error', $e::class.': '.$e->getMessage());
      throw new AiException('Something went wrong while talking to the AI provider. Please try again.', $e->getMessage()); } }

  public static function log(?int $uid, string $provider, string $model, string $feature, string $status, ?string $error = null, int $in = 0, int $out = 0): void {
    try { DB::insert('ai_usage', ['user_id' => $uid, 'provider' => mb_substr($provider, 0, 30), 'model' => mb_substr($model, 0, 100), 'feature' => mb_substr($feature, 0, 60), 'input_tokens' => max(0, $in), 'output_tokens' => max(0, $out), 'status' => $status, 'error' => $error === null ? null : mb_substr($error, 0, 500), 'created_at' => date('Y-m-d H:i:s')]); }
    catch (\Throwable $e) { error_log('AI usage log failed: '.$e->getMessage()); } } }
