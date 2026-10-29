<?php
namespace Modules\Admin\Services;
use Core\{App, DB, Installer, Migrator, Settings, Storage};

/** Health snapshot for the System overview: versions, requirements, storage, cron, mail, migrations and backups. */
final class SystemHealth {
  public static function cron(): array {
    $hb = (string) Settings::get('cron.last_heartbeat', '');
    $age = $hb ? time() - strtotime($hb) : null;
    $state = $age === null ? 'never' : ($age > 86400 ? 'stale' : 'healthy');
    return ['heartbeat' => $hb ?: null, 'age' => $age, 'state' => $state]; }

  public static function snapshot(): array {
    $dirs = ['storage', 'storage/uploads', 'storage/backups', 'storage/cache', 'storage/logs', 'storage/sessions', 'storage/updates', 'assets/uploads/branding', 'config'];
    $writable = [];
    foreach ($dirs as $d) { $writable[$d] = is_dir(NY_ROOT.'/'.$d) && is_writable(NY_ROOT.'/'.$d); }
    try { $pending = Migrator::pending(); } catch (\Throwable) { $pending = []; }
    $last = DB::first("SELECT * FROM backups WHERE status='success' ORDER BY id DESC LIMIT 1");
    $free = @disk_free_space(NY_ROOT.'/storage');
    $total = @disk_total_space(NY_ROOT.'/storage');
    $debug = (bool) App::config('debug');
    $https = str_starts_with((string) App::config('app_url', ''), 'https://');
    $mailDriver = (string) Settings::get('email.driver', 'mail');
    $checks = [
      ['Debug mode is off', !$debug, 'Set \'debug\' => false in config/config.php on production.'],
      ['Storage folders writable', !in_array(false, $writable, true), 'Make storage/ and assets/uploads/ writable by PHP.'],
      ['Cron heartbeat within 10 minutes', self::cron()['state'] === 'healthy', 'Add the cron job shown on the Cron page.'],
      ['Database schema up to date', !$pending, count($pending).' migration(s) pending, run them from Updates.'],
      ['Backup in the last 48 hours', $last && strtotime($last['created_at']) > time() - 172800, 'Create a backup or enable automatic backups.'],
      ['Served over HTTPS', $https, 'Use an https:// app_url and enable SSL on your host.'],
      ['Email configured', $mailDriver === 'mail' || (string) Settings::get('email.smtp_host', '') !== '', 'Complete the SMTP settings.'],
      ['Config file not world-readable', is_file(NY_ROOT.'/config/config.php') && (DIRECTORY_SEPARATOR === '\\' || (fileperms(NY_ROOT.'/config/config.php') & 0004) === 0), 'chmod 640 config/config.php']];
    return [
      'version' => NY_VERSION, 'php' => PHP_VERSION, 'sapi' => PHP_SAPI, 'mysql' => (string) DB::value('SELECT VERSION()'), 'os' => PHP_OS_FAMILY,
      'requirements' => Installer::requirements(), 'writable' => $writable, 'debug' => $debug, 'https' => $https, 'cron' => self::cron(), 'mail_driver' => $mailDriver,
      'pending' => $pending, 'last_backup' => $last, 'disk_free' => $free === false ? null : (int) $free, 'disk_total' => $total === false ? null : (int) $total,
      'maintenance' => is_file(NY_ROOT.'/storage/maintenance.flag'), 'checks' => $checks, 'timezone' => date_default_timezone_get(),
      'limits' => ['upload_max_filesize' => ini_get('upload_max_filesize'), 'post_max_size' => ini_get('post_max_size'), 'memory_limit' => ini_get('memory_limit'), 'max_execution_time' => ini_get('max_execution_time')],
      'opcache' => function_exists('opcache_get_status') && @opcache_get_status(false) !== false]; }

  /** Disk usage of the main storage folders (bytes). */
  public static function usage(): array {
    return ['Uploaded files' => ['storage/uploads', Storage::dirSize(NY_ROOT.'/storage/uploads'), 'lav'], 'Backups' => ['storage/backups', Storage::dirSize(NY_ROOT.'/storage/backups'), 'mint'], 'Screenshots' => ['storage/screenshots', Storage::dirSize(NY_ROOT.'/storage/screenshots'), 'peach'], 'Update packages' => ['storage/updates', Storage::dirSize(NY_ROOT.'/storage/updates'), 'sky'], 'Logs' => ['storage/logs', Storage::dirSize(NY_ROOT.'/storage/logs'), 'lemon']]; }

  public static function iniBytes(string $v): int { $v = trim($v); $n = (int) $v; return match (strtolower(substr($v, -1))) { 'g' => $n * 1073741824, 'm' => $n * 1048576, 'k' => $n * 1024, default => $n }; } }
