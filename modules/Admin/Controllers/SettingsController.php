<?php
namespace Modules\Admin\Controllers;
use Core\{Audit, Auth, Controller, Cron, DB, Features, HttpException, Mailer, Modules, Request, Response, Session, Settings, ValidationException};
use Modules\Admin\Services\{BackupService, DemoData, MailPresets, SettingsSchema, SystemHealth, UpdateService};

/** Settings (Super Admin only): category home, schema-driven tabs, branding uploads, theme reset, test email, retention cleanup and emergency sign-out. */
final class SettingsController extends Controller {
  /** Tabs managed on their own pages: settings GET redirects there and saving returns there. */
  public const PAGES = ['ai' => '/ai/settings', 'backups' => '/admin/backups', 'cron' => '/admin/cron', 'updates' => '/admin/updates'];
  private const BRAND = ['logo' => [['png', 'jpg', 'jpeg', 'webp'], 1048576, ['image/png', 'image/jpeg', 'image/webp']], 'favicon' => [['png', 'ico'], 262144, ['image/png', 'image/x-icon', 'image/vnd.microsoft.icon']]];

  public function __construct(Request $request) {
    parent::__construct($request);
    if (!Auth::isSuperAdmin()) { throw new HttpException(403, 'Only a Super Admin can change system settings.'); } }

  private function base(string $tab, string $title): array { return ['title' => $title, 'tab' => $tab, 'tabs' => SettingsSchema::tabs(), 'styles' => ['css/admin.css'], 'scripts' => ['js/admin.js']]; }

  public function index(string $tab = 'home'): Response {
    if (array_key_exists($tab, SettingsSchema::MOVED)) { return $this->redirect('/admin/settings'.(SettingsSchema::MOVED[$tab] ? '/'.SettingsSchema::MOVED[$tab] : '')); }
    if ($tab === 'home') { return $this->view('admin::settings/home', $this->base('home', 'Settings') + ['status' => $this->statuses()]); }
    if ($tab === 'storage') { return $this->redirect('/admin/settings/retention'); }
    $tabs = SettingsSchema::tabs();
    if (!SettingsSchema::tabOn($tab)) { $this->flash('That part of the product is switched off. Turn it on under Features to change its settings.', 'error'); return $this->redirect('/admin/settings'); }
    if (!isset($tabs[$tab])) { $this->abort(404, 'Unknown settings section.'); }
    if (isset(self::PAGES[$tab])) { return $this->redirect(self::PAGES[$tab]); }
    $data = $this->base($tab, $tabs[$tab][0].' · Settings') + ['fields' => SettingsSchema::fields($tab), 'sections' => SettingsSchema::sections($tab)];
    $data += match ($tab) {
      'overview' => ['health' => SystemHealth::snapshot(), 'counts' => DB::pairs('SELECT status, COUNT(*) FROM users GROUP BY status'), 'modulesOn' => count(array_filter(array_keys(Modules::all()), [Modules::class, 'enabled'])), 'lastCheck' => UpdateService::lastCheck()],
      'users' => ['roles' => DB::all("SELECT r.name, r.slug, COUNT(u.id) AS n FROM roles r LEFT JOIN users u ON u.role_id=r.id AND u.status='active' GROUP BY r.id, r.name, r.slug ORDER BY r.id"), 'permCount' => (int) DB::value('SELECT COUNT(*) FROM permissions'),
        'sessions' => (int) DB::value("SELECT COUNT(*) FROM users WHERE status='active' AND id<>?", [$this->uid()]), 'failed' => $this->auditCount('auth.failed'), 'denied' => $this->auditCount('access.denied')],
      'features' => ['bundles' => Features::bundles(), 'raw' => DB::pairs("SELECT `key`, enabled FROM modules WHERE `key` LIKE 'feature:%'")],
      'email' => ['presets' => MailPresets::all()],
      'retention' => ['last' => Settings::json('retention.last_cleanup'), 'sizes' => $this->tableSizes(array_merge(['audit_logs', 'notifications', 'mail_queue', 'cron_logs'], feature_on('ai') ? ['ai_usage'] : [], feature_on('attendance') ? ['screenshots'] : [])), 'usage' => self::usage(), 'health' => SystemHealth::snapshot()],
      'demo' => ['demo' => DemoData::counts(), 'demoMeta' => Settings::json('demo.imported'), 'password' => Session::getFlash('demo_password')],
      'leave' => ['ohQuota' => $this->has('leave_types') ? DB::value("SELECT annual_quota FROM leave_types WHERE code='OH'") : null, 'holidays' => $this->has('holidays') ? DB::pairs('SELECT optional, COUNT(*) FROM holidays WHERE YEAR(holiday_date)=? GROUP BY optional', [(int) date('Y')]) : []],
      default => [] };
    return $this->view('admin::settings/index', $data); }

  /** Disk usage rows; with attendance switched off the screenshot folder is listed only while it still holds files. */
  private static function usage(): array {
    $u = SystemHealth::usage();
    if (isset($u['Screenshots']) && !feature_on('attendance')) { $row = $u['Screenshots']; unset($u['Screenshots']); if ($row[1] > 0) { $u['Archived activity files'] = $row; } }
    return $u; }

  private function has(string $table): bool { return (bool) DB::value('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?', [$table]); }
  private function auditCount(string $action): int { return (int) DB::value('SELECT COUNT(*) FROM audit_logs WHERE action=? AND created_at > ?', [$action, date('Y-m-d H:i:s', strtotime('-7 days'))]); }

  /** Row counts and data size of tables (missing tables are skipped). */
  private function tableSizes(array $tables): array {
    $out = [];
    foreach (DB::all('SELECT table_name AS t, table_rows AS n, data_length+index_length AS b FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN '.DB::in($tables), $tables) as $r) { $out[$r['t']] = [(int) DB::value('SELECT COUNT(*) FROM '.DB::id($r['t'])), (int) $r['b']]; }
    return $out; }

  /** One-line status per tab for the settings home. [text, tone] where tone is ok|warn|off|info. */
  private function statuses(): array {
    $h = SystemHealth::cron();
    $bOn = array_filter(array_keys(Features::bundles()), [Features::class, 'bundleEnabled']);
    $prov = MailPresets::get((string) SettingsSchema::value(['key' => 'email.provider']));
    $smtpOk = Settings::get('email.driver', 'mail') === 'mail' || (string) Settings::get('email.smtp_host', '') !== '';
    $last = DB::first("SELECT created_at FROM backups WHERE status='success' ORDER BY id DESC LIMIT 1");
    $demo = array_sum(DemoData::counts());
    $clean = Settings::json('retention.last_cleanup');
    $tz = (string) Settings::get('general.timezone', date_default_timezone_get());
    return array_intersect_key([
      'general' => [$tz.' · '.Settings::get('general.currency_code', 'INR'), 'info'],
      'appearance' => [SettingsSchema::customTheme() ? 'Custom colours' : 'Default theme', 'info'],
      'email' => [($prov['name'] ?? 'Not set').($smtpOk ? '' : ' · incomplete'), $smtpOk ? 'ok' : 'warn'],
      'users' => ['Min '.(int) Settings::get('security.password_min_length', 10).' chars · '.(int) Settings::get('security.session_idle_minutes', 120).' min idle', 'info'],
      'features' => [count($bOn).' of '.count(Features::bundles()).' bundles on', count($bOn) ? 'ok' : 'off'],
      'ai' => self::aiStatus(),
      'employees' => [rtrim(rtrim((string) Settings::get('hr.work_hours_per_day', '8'), '0'), '.').' h/day · '.count(array_filter(explode(',', (string) Settings::get('general.weekend_days', '6,7')))).' weekly offs', 'info'],
      'leave' => [($n = $this->has('leave_types') ? (int) DB::value('SELECT COUNT(*) FROM leave_types WHERE active=1') : 0).' leave type'.($n === 1 ? '' : 's').' · '.($this->has('holidays') ? (int) DB::value('SELECT COUNT(*) FROM holidays WHERE YEAR(holiday_date)=?', [(int) date('Y')]) : 0).' holidays', 'info'],
      'attendance' => [(Settings::bool('attendance.screenshots_enabled') ? 'Screenshots every '.\Modules\Attendance\Services\Monitoring::intervalSeconds().' s' : 'Screenshots off').' · desktop app', 'info'],
      'payroll' => feature_on('payroll') ? [Settings::get('payroll.payslip_footer') ? 'Payslip note set' : 'Default payslips', 'info'] : ['Claims & approvals', 'info'],
      'projects' => [feature_on('projects') ? 'Reminders daily 09:00' : 'Reviewed by managers', 'info'],
      'invoicing' => [(feature_on('invoices') ? Settings::get('crm.invoice_prefix', 'INV-') : Settings::get('crm.quotation_prefix', 'QT-')).'00001 · '.Settings::get('crm.default_tax_rate', '18').'% tax', 'info'],
      'meetings' => feature_on('meetings') ? [(\Modules\Meetings\Services\Inbox::configured() ? 'Inbox on' : 'Inbox not set up').' · '.(\Modules\Meetings\Services\Summarizer::available() ? 'AI summaries' : 'no AI summaries'), \Modules\Meetings\Services\Inbox::configured() ? 'ok' : 'info'] : ['Off', 'off'],
      'overview' => [['healthy' => 'Scheduler healthy', 'stale' => 'Scheduler stale', 'never' => 'Scheduler never ran'][$h['state']], $h['state'] === 'healthy' ? 'ok' : 'warn'],
      'retention' => [$clean ? 'Last cleanup '.ago($clean['at']) : 'No cleanup yet', $clean ? 'ok' : 'info'],
      'demo' => [$demo ? number_format($demo).' demo records' : 'Not imported', $demo ? 'warn' : 'info'],
      'backups' => [$last ? 'Last '.ago($last['created_at']) : 'No backups yet', $last && strtotime($last['created_at']) > time() - 172800 ? 'ok' : 'warn'],
      'cron' => [$h['heartbeat'] ? 'Heartbeat '.ago($h['heartbeat']) : 'Never ran', $h['state'] === 'healthy' ? 'ok' : 'warn'],
      'updates' => ['v'.NY_VERSION, 'info']], SettingsSchema::tabs()); }

  private static function aiStatus(): array {
    if (!class_exists(\Modules\Ai\Services\Assistant::class) || !Modules::enabled('ai')) { return ['Not installed', 'off']; }
    [$ok, $why] = \Modules\Ai\Services\Assistant::status();
    return $ok ? ['On · '.(\Modules\Ai\Services\ModelRegistry::current()['label'] ?? ''), 'ok'] : ($why === 'off' ? ['Off', 'off'] : ['On · no model yet', 'warn']); }

  public function save(string $tab): Response {
    $fields = SettingsSchema::fields($tab);
    if (!$fields || !SettingsSchema::tabOn($tab)) { $this->abort(404, 'Unknown settings section.'); }
    $input = $this->request->arr('s');
    $return = self::PAGES[$tab] ?? '/admin/settings/'.$tab;
    try {
      $values = SettingsSchema::validate($tab, $input, $this->request->arr('clear'));
      $files = $tab === 'appearance' ? $this->brandUploads() : []; }
    catch (ValidationException $e) {
      $secret = array_column(array_filter($fields, static fn ($f) => $f['type'] === 'secret'), 'key');
      Session::flash('errors', $e->errors);
      Session::flash('old', ['s' => array_diff_key($input, array_flip($secret))]);
      return $this->redirect($return); }
    $values += $files;
    if ($tab === 'appearance') { $values['branding.primary_color'] = $values['appearance.primary_color'] ?? SettingsSchema::DEFAULTS['appearance.primary_color']; }
    $changed = [];
    $all = Settings::all();
    foreach ($values as $k => $v) {
      $cur = in_array($k, Settings::SECRETS, true) ? Settings::get($k) : ($all[$k] ?? null);
      if ($v === null ? $cur !== null : (string) ($cur ?? '') !== (string) $v) { $changed[] = $k; } }
    if ($changed) {
      Settings::setMany(array_intersect_key($values, array_flip($changed)));
      if ($tab === 'leave' && in_array('hr.optional_holidays_default', $changed, true) && $this->has('leave_types')) { DB::query("UPDATE leave_types SET annual_quota=? WHERE code='OH'", [(int) $values['hr.optional_holidays_default']]); }
      Audit::log('settings.update', 'settings', null, ['tab' => $tab, 'changed' => array_values(array_diff($changed, Settings::SECRETS)), 'secrets_changed' => count(array_intersect($changed, Settings::SECRETS))]); }
    $this->flash($changed ? 'Settings saved.' : 'No changes to save.');
    return $this->redirect($return); }

  /** Remove every colour/size/radius override so the built-in stylesheet palette applies again. */
  public function resetTheme(): Response {
    Settings::setMany(array_fill_keys([...SettingsSchema::THEME_KEYS, 'branding.primary_color'], null));
    Audit::log('settings.theme_reset', 'settings', null);
    $this->flash('Theme reset to the default palette.');
    return $this->redirect('/admin/settings/appearance'); }

  /** Validate and store logo/favicon uploads (random names in assets/uploads/branding). Returns settings to save. */
  private function brandUploads(): array {
    $out = []; $errors = []; $stored = [];
    foreach (self::BRAND as $kind => [$exts, $max, $mimes]) {
      $key = 'branding.'.$kind;
      $file = $this->request->file($kind);
      if (!$file) { if ($this->request->input('remove_'.$kind) && Settings::get($key)) { $out[$key] = null; } continue; }
      $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
      $mime = $file['error'] === UPLOAD_ERR_OK ? ((new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: '') : '';
      $label = ucfirst($kind);
      if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) { $errors[$kind] = "$label upload failed."; }
      elseif ($file['size'] > $max) { $errors[$kind] = "$label must be at most ".BackupService::size($max).'.'; }
      elseif (!in_array($ext, $exts, true) || !in_array($mime, $mimes, true)) { $errors[$kind] = "$label must be ".strtoupper(implode('/', array_diff($exts, ['jpeg']))).'.'; }
      elseif ($ext !== 'ico' && !@getimagesize($file['tmp_name'])) { $errors[$kind] = "$label is not a valid image."; }
      elseif ($ext === 'ico' && file_get_contents($file['tmp_name'], false, null, 0, 4) !== "\0\0\1\0") { $errors[$kind] = "$label is not a valid .ico file."; }
      else { $stored[$kind] = [$file, $ext === 'jpeg' ? 'jpg' : $ext]; } }
    if ($errors) { throw new ValidationException($errors); }
    $dir = NY_ROOT.'/assets/uploads/branding';
    if (!is_dir($dir)) { mkdir($dir, 0775, true); }
    foreach ($stored as $kind => [$file, $ext]) {
      $name = $kind.'-'.bin2hex(random_bytes(12)).'.'.$ext;
      if (!move_uploaded_file($file['tmp_name'], $dir.'/'.$name)) { throw new ValidationException([$kind => 'Could not save the file. Check that assets/uploads/branding is writable.']); }
      @chmod($dir.'/'.$name, 0644);
      $out['branding.'.$kind] = $name; }
    foreach (array_keys($out) as $key) { self::deleteBrandFile((string) Settings::get($key, '')); }
    return $out; }

  private static function deleteBrandFile(string $name): void {
    $name = basename($name);
    if (preg_match('/^(logo|favicon)-[a-f0-9]{24}\.(png|jpg|webp|ico)$/', $name) && is_file($f = NY_ROOT.'/assets/uploads/branding/'.$name)) { @unlink($f); } }

  public function testEmail(): Response {
    $u = $this->user();
    $app = company_name();
    $meta = ['driver' => Settings::get('email.driver', 'mail'), 'provider' => Settings::get('email.provider')];
    try {
      Mailer::send($u['email'], "Test email from $app", Mailer::template('It works!', '<p>Hi '.e($u['name']).',</p><p>This test message confirms that '.e($app).' can send email using <b>'.e(MailPresets::get((string) SettingsSchema::value(['key' => 'email.provider']))['name'] ?? 'PHP mail()').'</b>.</p><p style="color:#888;font-size:12px">Sent '.e(date('c')).'</p>'), $u['name']);
      Audit::log('settings.email_test', 'settings', null, ['ok' => true] + $meta);
      $this->flash('Test email sent to '.$u['email'].'. Check your inbox (and spam folder).'); }
    catch (\Throwable $e) {
      Audit::log('settings.email_test', 'settings', null, ['ok' => false] + $meta);
      Session::flash('mail_error', mb_substr($e->getMessage(), 0, 500));
      $this->flash('Test email failed, see the error below the form.', 'error'); }
    return $this->redirect('/admin/settings/email'); }

  /** Run the retention cleanup now (same job and lock as the nightly cron). */
  public function runCleanup(): Response {
    $res = Cron::run(true, 'core.cleanup');
    $out = (string) DB::value("SELECT last_output FROM cron_jobs WHERE name='core.cleanup'");
    Audit::log('retention.cleanup', 'settings', null, ['result' => $res['core.cleanup'] ?? ($res['skipped'] ?? 'unknown')]);
    if (isset($res['skipped'])) { $this->flash($res['skipped'], 'error'); }
    else { $this->flash(($res['core.cleanup'] ?? '') === 'success' ? 'Cleanup finished: '.$out.'.' : 'Cleanup failed: '.$out, ($res['core.cleanup'] ?? '') === 'success' ? 'success' : 'error'); }
    return $this->redirect('/admin/settings/retention'); }

  /** Emergency: sign out every other user by bumping their session version. */
  public function logoutAll(): Response {
    $n = DB::query('UPDATE users SET session_version=session_version+1 WHERE id<>?', [$this->uid()])->rowCount();
    Audit::log('security.logout_all', 'user', null, ['users' => $n]);
    $this->flash("Signed out $n other user(s) on every device. You stay signed in.");
    return $this->redirect('/admin/settings/users'); } }
