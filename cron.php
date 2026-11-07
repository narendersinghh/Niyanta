<?php
/**
 * Scheduler entry point. Add ONE system cron entry (every minute recommended, every 5 minutes is fine):
 *   * * * * * /usr/bin/php /path/to/niyanta/cron.php >/dev/null 2>&1
 * Options: --force (run all jobs now), --job=<name> (run a single job), --migrate (apply pending migrations)
 *   --meeting-mail (read one e-mail from STDIN into Meeting notes, for hosts without outbound IMAP: php cron.php --meeting-mail < message.eml, or a mail forwarder "pipe to program")
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/core/bootstrap.php';
Core\App::boot();
if (!Core\App::installed()) { fwrite(STDERR, "Niyanta is not installed.\n"); exit(1); }
$opts = getopt('', ['force', 'job:', 'migrate', 'meeting-mail']);
if (isset($opts['meeting-mail'])) {
  /* exits 0 even when the message is dropped, so mail servers never bounce back to the sender */
  if (!Core\Features::enabled('meetings') || !class_exists(Modules\Meetings\Services\Inbox::class)) { fwrite(STDERR, "Meeting notes are switched off.\n"); exit(0); }
  try { $r = Modules\Meetings\Services\Inbox::process((string) stream_get_contents(STDIN), 'pipe'); echo $r['outcome'].': '.$r['reason']."\n"; }
  catch (Throwable $e) { fwrite(STDERR, 'meeting mail failed: '.$e->getMessage()."\n"); error_log('meeting-mail: '.$e); }
  exit(0); }
if (isset($opts['migrate'])) { foreach (Core\Migrator::migrate() as $m) { echo "migrated $m\n"; } Core\Modules::syncPermissions(); exit(0); }
foreach (Core\Cron::run(isset($opts['force']), $opts['job'] ?? null) as $job => $status) { echo is_string($job) ? "$job: ".(is_string($status) ? $status : json_encode($status))."\n" : ''; }
