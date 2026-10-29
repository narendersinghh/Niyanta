<?php
namespace Core;

/**
 * Zero-configuration scheduler. Every request does one cheap stat() on storage/cache/cron.tick; when
 * more than a minute has passed, due jobs run AFTER the response is delivered:
 * - PHP-FPM / LiteSpeed: fastcgi_finish_request()/litespeed_finish_request() then run in-process;
 * - other SAPIs: the page includes a meta flag and the browser sends a background beacon to /cron/tick.
 * Cron::run() holds a file lock, so overlapping triggers never run jobs twice. A system cron is optional.
 */
final class AutoCron {
  private const INTERVAL = 60;
  private static function file(): string { return NY_ROOT.'/storage/cache/cron.tick'; }

  public static function enabled(): bool { return App::installed() && Settings::bool('cron.auto', true); }

  public static function due(): bool {
    if (!self::enabled()) { return false; }
    $m = @filemtime(self::file());
    return $m === false || time() - $m >= self::INTERVAL; }

  /** True when this request can run jobs itself after the response (no browser beacon needed). */
  public static function canFinishRequest(): bool { return function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request'); }

  /** Claim the slot (touch first so parallel requests back off), then run due jobs. */
  public static function run(): array {
    if (!self::due()) { return []; }
    @touch(self::file());
    @set_time_limit(120);
    ignore_user_abort(true);
    try { return Cron::run(); }
    catch (\Throwable $e) { error_log('AutoCron: '.$e->getMessage()); return []; } }

  /** Called by App after the response has been sent. */
  public static function afterResponse(): void {
    if (!self::canFinishRequest() || !self::due()) { return; }
    if (function_exists('fastcgi_finish_request')) { fastcgi_finish_request(); }
    else { litespeed_finish_request(); }
    self::run(); } }
