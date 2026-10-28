<?php
namespace Core;

/** Key/value settings (dot keys like "branding.app_name"). Secret values are stored encrypted. */
final class Settings {
  private static ?array $all = null;
  public const SECRETS = ['email.smtp_password', 'ai.openai_key', 'ai.anthropic_key', 'ai.openrouter_key', 'updates.github_token', 'cron.web_token'];

  public static function all(): array {
    return self::$all ??= Cache::remember('settings', 0, static fn () => DB::pairs('SELECT `key`, `value` FROM settings')); }

  public static function get(string $k, mixed $d = null): mixed {
    try { $a = self::all(); } catch (\Throwable) { return $d; }
    if (!array_key_exists($k, $a) || $a[$k] === null) { return $d; }
    return in_array($k, self::SECRETS, true) ? (Crypto::decrypt($a[$k]) ?? $d) : $a[$k]; }

  public static function bool(string $k, bool $d = false): bool { $v = self::get($k); return $v === null ? $d : in_array((string) $v, ['1', 'true', 'on', 'yes'], true); }
  public static function json(string $k, array $d = []): array { $v = json_decode((string) self::get($k, ''), true); return is_array($v) ? $v : $d; }

  public static function set(string $k, mixed $v): void { self::setMany([$k => $v]); }

  public static function setMany(array $kv): void {
    foreach ($kv as $k => $v) {
      if (is_array($v)) { $v = json_encode($v); }
      elseif (is_bool($v)) { $v = $v ? '1' : '0'; }
      if ($v !== null && in_array($k, self::SECRETS, true)) { $v = Crypto::encrypt((string) $v); }
      DB::query('INSERT INTO settings (`key`, `value`, updated_at) VALUES (?,?,NOW()) ON DUPLICATE KEY UPDATE `value`=VALUES(`value`), updated_at=NOW()', [$k, $v === null ? null : (string) $v]); }
    self::$all = null;
    Cache::forget('settings'); } }
