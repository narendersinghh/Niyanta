<?php
namespace Core;

final class Audit {
  public static function log(string $action, ?string $type = null, ?int $id = null, array $meta = [], ?int $uid = null): void {
    try {
      $r = Request::current();
      DB::insert('audit_logs', ['user_id' => $uid ?? Auth::id(), 'action' => substr($action, 0, 100), 'entity_type' => $type, 'entity_id' => $id, 'meta' => $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null, 'ip' => PHP_SAPI === 'cli' ? 'cli' : $r->ip(), 'user_agent' => PHP_SAPI === 'cli' ? 'cron' : $r->userAgent(), 'created_at' => date('Y-m-d H:i:s')]); }
    catch (\Throwable $e) { error_log('Audit failed: '.$e->getMessage()); } } }
