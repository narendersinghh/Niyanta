<?php
namespace Modules\Admin\Controllers;
use Core\{Audit, Auth, Controller, Cron, Crypto, DB, HttpException, Request, Response, Settings};
use Modules\Admin\Services\{SettingsSchema, SystemHealth};

/** Cron & scheduler (Super Admin only): setup instructions, health, jobs, run now, web-cron token and logs. */
final class CronAdminController extends Controller {
  public function __construct(Request $request) {
    parent::__construct($request);
    if (!Auth::isSuperAdmin()) { throw new HttpException(403, 'Only a Super Admin can manage the scheduler.'); } }

  private static function schedule(array $j): string {
    if (isset($j['every'])) { $m = (int) $j['every']; return $m === 1 ? 'Every minute' : ($m % 60 === 0 ? 'Every '.($m / 60).' hour(s)' : "Every $m minutes"); }
    if (isset($j['daily'])) { return 'Daily at '.$j['daily']; }
    if (isset($j['weekly'])) { [$d, $t] = explode(' ', (string) $j['weekly']) + [1 => '']; return 'Weekly on '.(SettingsSchema::DAYS[$d] ?? $d).' at '.$t; }
    if (isset($j['monthly'])) { [$d, $t] = explode(' ', (string) $j['monthly']) + [1 => '']; return 'Monthly on day '.$d.' at '.$t; }
    return 'Manual'; }

  public function index(): Response {
    $last = [];
    foreach (DB::all('SELECT * FROM cron_jobs') as $r) { $last[$r['name']] = $r; }
    $jobs = [];
    foreach (Cron::jobs() as $name => $j) { $jobs[$name] = ['schedule' => self::schedule($j), 'last' => $last[$name] ?? null]; }
    $php = PHP_BINARY && preg_match('/^php[\d.]*$/', basename(PHP_BINARY)) ? PHP_BINARY : '/usr/bin/php';
    $token = (string) Settings::get('cron.web_token', '');
    $logs = DB::paginate('SELECT * FROM cron_logs'.($this->request->str('job') !== '' ? ' WHERE job=?' : '').' ORDER BY id DESC', $this->request->str('job') !== '' ? [$this->request->str('job')] : [], 25);
    return $this->view('admin::cron/index', ['title' => 'Cron & Scheduler', 'jobs' => $jobs, 'health' => SystemHealth::cron(), 'php' => $php, 'path' => NY_ROOT.'/cron.php', 'webUrl' => $token ? absolute_url('/cron/run/'.$token) : null,
      'webOn' => Settings::bool('cron.web_enabled', true), 'logs' => $logs, 'jobFilter' => $this->request->str('job'), 'fields' => SettingsSchema::fields('cron'), 'tabs' => SettingsSchema::tabs(), 'tab' => 'cron',
      'failed24' => (int) DB::value("SELECT COUNT(*) FROM cron_logs WHERE status='failed' AND ran_at > ?", [date('Y-m-d H:i:s', strtotime('-1 day'))]), 'lockFile' => is_file(NY_ROOT.'/storage/cache/cron.lock'),
      'styles' => ['css/admin.css'], 'scripts' => ['js/admin.js']]); }

  /** Run one job now, or every due job (same lock as the system cron, so runs never overlap). */
  public function run(): Response {
    $job = $this->request->str('job');
    if ($job !== '' && !array_key_exists($job, Cron::jobs())) { $this->abort(404, 'Unknown job.'); }
    $res = $job !== '' ? Cron::run(true, $job) : Cron::run(false);
    Audit::log('cron.run', 'cron', null, ['job' => $job ?: 'all-due', 'result' => $res]);
    if (isset($res['skipped'])) { $this->flash($res['skipped'], 'error'); }
    elseif (!$res) { $this->flash('No jobs were due. Use "Run now" on a job to force it.'); }
    else { $failed = array_keys(array_filter($res, static fn ($s) => $s === 'failed')); $this->flash(count($res).' job(s) ran'.($failed ? ', failed: '.implode(', ', $failed) : ' successfully').'.', $failed ? 'error' : 'success'); }
    return $this->redirect('/admin/cron'); }

  public function regenerateToken(): Response {
    Settings::set('cron.web_token', Crypto::token(24));
    Audit::log('cron.token_regenerated', 'cron');
    $this->flash('A new web cron URL was generated. Update your external pinger, the old URL no longer works.');
    return $this->redirect('/admin/cron'); } }
