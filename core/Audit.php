<?php
namespace Core;

final class Audit {
  public static function log(string $action, ?string $type = null, ?int $id = null, array $meta = [], ?int $uid = null): void {
    try {
      $r = Request::current();
      DB::insert('audit_logs', ['user_id' => $uid ?? Auth::id(), 'action' => substr($action, 0, 100), 'entity_type' => $type, 'entity_id' => $id, 'meta' => $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null, 'ip' => PHP_SAPI === 'cli' ? 'cli' : $r->ip(), 'user_agent' => PHP_SAPI === 'cli' ? 'cron' : $r->userAgent(), 'created_at' => date('Y-m-d H:i:s')]); }
    catch (\Throwable $e) { error_log('Audit failed: '.$e->getMessage()); } }

  /** A repeatable security event (permission or file denials): logged at most $max times per 10 minutes per user and IP, so a script hammering a forbidden URL cannot fill the audit table. */
  public static function logLimited(string $action, ?string $type = null, ?int $id = null, array $meta = [], int $max = 20): void {
    try { if (!RateLimiter::hit('audit:'.$action.':'.(Auth::id() ?? 0).':'.Request::current()->ip(), $max, 600)) { return; } }
    catch (\Throwable) {}
    self::log($action, $type, $id, $meta); } }
