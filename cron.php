<?php
/**
 * Scheduler entry point. Add ONE system cron entry (every minute recommended, every 5 minutes is fine):
 *   * * * * * /usr/bin/php /path/to/niyanta/cron.php >/dev/null 2>&1
 * Options: --force (run all jobs now), --job=<name> (run a single job), --migrate (apply pending migrations)
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__.'/core/bootstrap.php';
Core\App::boot();
if (!Core\App::installed()) { fwrite(STDERR, "Niyanta is not installed.\n"); exit(1); }
$opts = getopt('', ['force', 'job:', 'migrate']);
if (isset($opts['migrate'])) { foreach (Core\Migrator::migrate() as $m) { echo "migrated $m\n"; } Core\Modules::syncPermissions(); exit(0); }
foreach (Core\Cron::run(isset($opts['force']), $opts['job'] ?? null) as $job => $status) { echo is_string($job) ? "$job: ".(is_string($status) ? $status : json_encode($status))."\n" : ''; }
