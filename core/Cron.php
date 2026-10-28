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

  public static function cleanup(): string {
    $n = RateLimiter::prune();
    $n += DB::delete('cron_logs', 'ran_at < ?', [date('Y-m-d H:i:s', strtotime('-30 days'))]);
    $n += DB::delete('mail_queue', "status='sent' AND sent_at < ?", [date('Y-m-d H:i:s', strtotime('-30 days'))]);
    $n += DB::delete('notifications', 'read_at IS NOT NULL AND read_at < ?', [date('Y-m-d H:i:s', strtotime('-90 days'))]);
    $n += DB::delete('password_resets', 'expires_at < ?', [date('Y-m-d H:i:s')]);
    $days = (int) Settings::get('security.audit_retention_days', 365);
    if ($days > 0) { $n += DB::delete('audit_logs', 'created_at < ?', [date('Y-m-d H:i:s', strtotime("-$days days"))]); }
    foreach (glob(NY_ROOT.'/storage/sessions/sess_*') ?: [] as $f) { if (filemtime($f) < time() - 86400 * 2) { @unlink($f); $n++; } }
    return "removed $n"; } }
