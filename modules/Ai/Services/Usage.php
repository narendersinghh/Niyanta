<?php
namespace Modules\Ai\Services;
use Core\{DB, RateLimiter, Settings};

/** On/off switch, budgets, per-user limits and the ai_usage log. */
final class Usage {
  public static function enabled(): bool { return Settings::bool('ai.enabled'); }
  public static function budget(): int { return max(0, (int) Settings::get('ai.monthly_token_budget', 500000)); }
  public static function dailyLimit(): int { return max(0, (int) Settings::get('ai.user_daily_requests', 50)); }
  public static function maxTokens(): int { return max(256, min(16000, (int) Settings::get('ai.max_output_tokens', 1500) ?: 1500)); }
  public static function redact(): bool { return Settings::bool('ai.redact_pii', true); }

  public static function monthUsed(): int { return (int) DB::value("SELECT COALESCE(SUM(input_tokens+output_tokens),0) FROM ai_usage WHERE status='ok' AND created_at>=?", [date('Y-m-01 00:00:00')]); }
  public static function userToday(int $uid): int { return (int) DB::value("SELECT COUNT(*) FROM ai_usage WHERE user_id=? AND status<>'blocked' AND feature='chat' AND created_at>=?", [$uid, date('Y-m-d 00:00:00')]); }

  /** Throws a friendly AiException (and logs a blocked row) when the user may not send another request now. */
  public static function guard(int $uid, string $provider, string $model): void {
    $budget = self::budget();
    if ($budget > 0 && self::monthUsed() >= $budget) { self::log($uid, $provider, $model, 'chat', 'blocked', 'Monthly token budget reached'); throw new AiException('This month\'s AI budget has been used up. It resets on the 1st, or an administrator can raise it.', 'budget', 'blocked'); }
    $daily = self::dailyLimit();
    if ($daily > 0 && self::userToday($uid) >= $daily) { self::log($uid, $provider, $model, 'chat', 'blocked', 'Daily request limit reached'); throw new AiException("You've reached today's limit of {$daily} questions. Please try again tomorrow.", 'daily', 'blocked'); }
    if (!RateLimiter::hit('ai:user:'.$uid, 8, 60)) { self::log($uid, $provider, $model, 'chat', 'blocked', 'Rate limited'); throw new AiException('You are sending messages too quickly. Please wait a moment.', 'rate', 'blocked'); } }

  public static function log(?int $uid, string $provider, string $model, string $feature, string $status, ?string $error = null, int $in = 0, int $out = 0): void {
    try { DB::insert('ai_usage', ['user_id' => $uid, 'provider' => mb_substr($provider, 0, 30), 'model' => mb_substr($model, 0, 100), 'feature' => mb_substr($feature, 0, 60), 'input_tokens' => max(0, $in), 'output_tokens' => max(0, $out), 'status' => $status, 'error' => $error === null ? null : mb_substr($error, 0, 500), 'created_at' => date('Y-m-d H:i:s')]); }
    catch (\Throwable $e) { error_log('AI usage log failed: '.$e->getMessage()); } } }
