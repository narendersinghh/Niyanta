<?php
namespace Modules\Admin\Controllers;
use Core\{Audit, Auth, Controller, DB, HttpException, Migrator, Modules, Request, Response, Settings};
use Modules\Admin\Services\{SettingsSchema, UpdateService};

/** Updates (Super Admin only): check GitHub, apply with backup + rollback, upload a package, run pending migrations, history. */
final class UpdateController extends Controller {
  public function __construct(Request $request) {
    parent::__construct($request);
    if (!Auth::isSuperAdmin()) { throw new HttpException(403, 'Only a Super Admin can update the application.'); } }

  public function index(): Response {
    try { $pending = Migrator::pending(); } catch (\Throwable) { $pending = []; }
    return $this->view('admin::updates/index', ['title' => 'Updates', 'check' => UpdateService::lastCheck(), 'pending' => $pending, 'fields' => SettingsSchema::fields('updates'), 'tabs' => SettingsSchema::tabs(), 'tab' => 'updates',
      'history' => DB::all('SELECT l.id, l.from_version, l.to_version, l.channel, l.ref, l.status, l.backup_id, l.started_at, l.finished_at, u.name AS by_name FROM update_logs l LEFT JOIN users u ON u.id=l.started_by ORDER BY l.id DESC LIMIT 30'),
      'zip' => class_exists(\ZipArchive::class), 'curl' => function_exists('curl_init'), 'repo' => UpdateService::repo(), 'manifest' => UpdateService::manifest(), 'uploadMax' => min(\Modules\Admin\Services\SystemHealth::iniBytes((string) ini_get('upload_max_filesize')), \Modules\Admin\Services\SystemHealth::iniBytes((string) ini_get('post_max_size'))),
      'desktop' => self::desktopOn() ? ['app' => \Modules\Attendance\Services\DesktopApp::latest(), 'sync' => \Modules\Attendance\Services\DesktopApp::syncStatus(), 'repo' => \Modules\Attendance\Services\DesktopApp::repo()] : null,
      'styles' => ['css/admin.css'], 'scripts' => ['js/admin.js']]); }

  private static function desktopOn(): bool { return class_exists(\Modules\Attendance\Services\DesktopApp::class) && Modules::enabled('attendance'); }

  /** Refresh now: ask GitHub for the newest tracker release and copy it to this server (may take a few minutes). */
  public function desktopRefresh(): Response {
    if (!self::desktopOn()) { $this->abort(404); }
    $s = \Modules\Attendance\Services\DesktopApp::sync(true);
    Audit::log('tracker.mirror_refresh', 'settings', null, ['ok' => $s['ok'], 'version' => $s['version'], 'downloaded' => $s['downloaded']]);
    $this->flash($s['ok'] ? ($s['version'] ? 'Desktop app v'.$s['version'].' is ready'.($s['downloaded'] ? ' ('.$s['downloaded'].' files downloaded).' : '.') : (string) $s['error']) : 'Desktop app refresh failed: '.mb_substr((string) $s['error'], 0, 250), $s['ok'] ? 'success' : 'error');
    return $this->redirect('/admin/updates#desktop-mirror'); }

  public function check(): Response {
    try {
      $c = UpdateService::check();
      Audit::log('update.check', 'update', null, ['channel' => $c['channel'], 'latest' => $c['version'] ?? null, 'available' => $c['available']]);
      $this->flash($c['available'] ? 'Update available: '.$c['version'].'.' : 'You are up to date ('.($c['channel'] === 'release' ? 'v'.NY_VERSION : 'latest commit').').'); }
    catch (\Throwable $e) { $this->flash('Update check failed: '.mb_substr($e->getMessage(), 0, 250), 'error'); }
    return $this->redirect('/admin/updates'); }

  /**
   * Download, back up, install and migrate after the response (App::defer): the browser gets the update page at once and
   * follows the log there, so a slow host never shows 504 Gateway Time-out. Without fastcgi/litespeed finish it runs inline.
   */
  public function apply(): Response {
    if ($this->request->str('confirm') !== 'UPDATE') { $this->flash('Type UPDATE in capitals to confirm.', 'error'); return $this->redirect('/admin/updates'); }
    $force = (bool) $this->request->input('force'); $uid = $this->uid();
    try { $c = UpdateService::ready($force); }
    catch (\Throwable $e) { $this->flash(mb_substr($e->getMessage(), 0, 250), 'error'); return $this->redirect('/admin/updates'); }
    $id = UpdateService::begin($c['channel'], (string) $c['version'], (string) $c['ref'], $uid);
    \Core\App::defer(static function () use ($uid, $force, $id): void { UpdateService::apply($uid, $force, $id); });
    return $this->redirect('/admin/updates/'.$id); }

  public function upload(): Response {
    if ($this->request->str('confirm') !== 'UPDATE') { $this->flash('Type UPDATE in capitals to confirm.', 'error'); return $this->redirect('/admin/updates'); }
    $f = $this->request->file('package');
    if (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) { $this->flash('Upload failed, check that the file is below the PHP upload limit.', 'error'); return $this->redirect('/admin/updates'); }
    $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    if (strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION)) !== 'zip' || !in_array($mime, ['application/zip', 'application/x-zip-compressed'], true)) { $this->flash('Please upload a .zip update package.', 'error'); return $this->redirect('/admin/updates'); }
    $path = UpdateService::dir().'/upload-'.bin2hex(random_bytes(8)).'.zip';
    if (!move_uploaded_file($f['tmp_name'], $path)) { $this->flash('Could not store the uploaded package.', 'error'); return $this->redirect('/admin/updates'); }
    $force = (bool) $this->request->input('force'); $uid = $this->uid(); $label = (string) $f['name'];
    $id = UpdateService::begin('upload', null, mb_substr(basename($label), 0, 120), $uid);
    \Core\App::defer(static function () use ($path, $uid, $force, $label, $id): void { try { UpdateService::applyLocal($path, $uid, $force, $label, $id); } finally { @unlink($path); } });
    return $this->redirect('/admin/updates/'.$id); }

  public function migrate(): Response {
    try {
      $ran = Migrator::migrate();
      $p = Modules::syncPermissions();
      \Core\Cache::flush();
      Audit::log('update.migrate', 'update', null, ['migrations' => $ran, 'permissions' => $p]);
      $this->flash($ran ? count($ran).' migration(s) applied.' : 'No pending migrations.'); }
    catch (\Throwable $e) { error_log('Migration failed: '.$e); Audit::log('update.migrate_failed', 'update', null, ['error' => mb_substr($e->getMessage(), 0, 300)]); $this->flash('Migration failed: '.mb_substr($e->getMessage(), 0, 250), 'error'); }
    return $this->redirect('/admin/updates'); }

  public function show(int $id): Response {
    if ($this->request->query('format') === 'json') {
      $r = DB::first('SELECT status, to_version, log, finished_at FROM update_logs WHERE id=?', [$id]) ?? $this->abort(404, 'Update not found.');
      return $this->json(['status' => $r['status'], 'version' => $r['to_version'], 'log' => (string) $r['log'], 'finished' => $r['finished_at'] !== null]); }
    $row = DB::first('SELECT l.*, u.name AS by_name, b.filename AS backup_file FROM update_logs l LEFT JOIN users u ON u.id=l.started_by LEFT JOIN backups b ON b.id=l.backup_id WHERE l.id=?', [$id]) ?? $this->abort(404, 'Update not found.');
    return $this->view('admin::updates/show', ['title' => 'Update #'.$id, 'u' => $row, 'tabs' => SettingsSchema::tabs(), 'tab' => 'updates', 'styles' => ['css/admin.css'], 'scripts' => ['js/admin.js']]); } }
