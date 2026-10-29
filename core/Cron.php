<?php
namespace Core;

/**
 * Scheduler. A single system cron (every minute or every 5 minutes) runs cron.php, or the secured
 * web endpoint is pinged. A file lock prevents overlapping runs; each job records its last run.
 */
final class Cron {
  public static function jobs(): array {
    $jobs = [
      'core.mail_queue' => ['every' => 1, 'run' => static fn () => 'sent '.Mailer::processQueue(30)],
      'core.cleanup' => ['daily' => '03:10', 'run' => [self::class, 'cleanup']]];
    foreach (Modules::hooks('cron') as $mod => $list) { foreach ($list as $name => $def) { $jobs["$mod.$name"] = $def; } }
    return $jobs; }

  public static function run(bool $force = false, ?string $only = null): array {
    $lock = fopen(NY_ROOT.'/storage/cache/cron.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { return ['skipped' => 'Another cron run is in progress.']; }
    @set_time_limit(300);
    $results = [];
    $last = DB::pairs('SELECT name, last_run_at FROM cron_jobs');
    $now = time();
    foreach (self::jobs() as $name => $j) {
      if ($only && $only !== $name) { continue; }
      if (!$force && !self::due($j, isset($last[$name]) ? strtotime($last[$name]) : 0, $now)) { continue; }
      $t = microtime(true);
      try { $out = (string) (is_callable($j['run']) ? call_user_func($j['run']) : ''); $status = 'success'; }
      catch (\Throwable $e) { $out = $e->getMessage(); $status = 'failed'; error_log("Cron $name failed: ".$e); }
      $ms = (int) ((microtime(true) - $t) * 1000);
      DB::query('INSERT INTO cron_jobs (name, last_run_at, last_status, last_output) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE last_run_at=VALUES(last_run_at), last_status=VALUES(last_status), last_output=VALUES(last_output)', [$name, date('Y-m-d H:i:s', $now), $status, mb_substr($out, 0, 1000)]);
      DB::insert('cron_logs', ['job' => $name, 'status' => $status, 'output' => mb_substr($out, 0, 2000), 'duration_ms' => $ms, 'ran_at' => date('Y-m-d H:i:s')]);
      $results[$name] = $status; }
    Settings::set('cron.last_heartbeat', date('Y-m-d H:i:s'));
    flock($lock, LOCK_UN);
    fclose($lock);
    return $results; }

  private static function due(array $j, int $last, int $now): bool {
    if (isset($j['every'])) { return $now - $last >= (int) $j['every'] * 60 - 5; }
    if (isset($j['daily'])) {
      $at = strtotime(date('Y-m-d').' '.$j['daily'], $now);
      return $now >= $at && $last < $at; }
    if (isset($j['weekly'])) { [$dow, $time] = explode(' ', $j['weekly']); $at = strtotime(date('Y-m-d').' '.$time, $now); return (int) date('N', $now) === (int) $dow && $now >= $at && $last < $at; }
    if (isset($j['monthly'])) { [$day, $time] = explode(' ', $j['monthly']); $at = strtotime(date('Y-m-').str_pad($day, 2, '0', STR_PAD_LEFT).' '.$time, $now); return $now >= $at && $last < $at; }
    return false; }

  /** Nightly cleanup. Retention settings are in days; 0 keeps forever. Returns a summary and remembers the counts (retention.last_cleanup). */
  public static function cleanup(): string {
    $c = self::prune();
    $total = array_sum($c);
    Settings::set('retention.last_cleanup', ['at' => date('Y-m-d H:i:s'), 'total' => $total, 'counts' => $c]);
    $parts = array_map(static fn ($k, $v) => "$k $v", array_keys(array_filter($c)), array_filter($c));
    return "removed $total".($parts ? ' ('.implode(', ', $parts).')' : ''); }

  /** Delete expired rows per category; returns [category => rows removed]. */
  public static function prune(): array {
    $days = static function (string $key, int $def, ?string $legacy = null): int { $v = Settings::get($key); if (($v === null || $v === '') && $legacy) { $v = Settings::get($legacy); } return max(0, (int) ($v ?? $def)); };
    $cut = static fn (int $d) => date('Y-m-d H:i:s', strtotime("-$d days"));
    $has = static function (string $t): bool { try { return (bool) DB::value('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?', [$t]); } catch (\Throwable) { return false; } };
    $c = ['rate_limits' => RateLimiter::prune(), 'password_resets' => DB::delete('password_resets', 'expires_at < ?', [date('Y-m-d H:i:s')])];
    if ($d = $days('retention.audit_days', 365, 'security.audit_retention_days')) { $c['audit'] = DB::delete('audit_logs', 'created_at < ?', [$cut($d)]); }
    if ($d = $days('retention.notifications_days', 90)) { $c['notifications'] = DB::delete('notifications', 'read_at IS NOT NULL AND read_at < ?', [$cut($d)]); }
    if ($d = $days('retention.mail_queue_days', 30)) { $c['mail_queue'] = DB::delete('mail_queue', "status IN ('sent','failed') AND COALESCE(sent_at, created_at) < ?", [$cut($d)]); }
    if ($d = $days('retention.cron_logs_days', 30)) { $c['cron_logs'] = DB::delete('cron_logs', 'ran_at < ?', [$cut($d)]); }
    if (($d = $days('retention.ai_usage_days', 180)) && $has('ai_usage')) { $c['ai_usage'] = DB::delete('ai_usage', 'created_at < ?', [$cut($d)]); }
    if ($d = $days('retention.sessions_days', 2)) {
      $c['sessions'] = 0;
      foreach (glob(NY_ROOT.'/storage/sessions/sess_*') ?: [] as $f) { if (filemtime($f) < time() - 86400 * $d && @unlink($f)) { $c['sessions']++; } } }
    return $c; } }
