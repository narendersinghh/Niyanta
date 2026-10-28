<?php
namespace Modules\Admin\Controllers;
use Core\{Audit, Auth, Controller, DB, HttpException, Mailer, Modules, Request, Response, Session, Settings, ValidationException};
use Modules\Admin\Services\{BackupService, SettingsSchema, SystemHealth, UpdateService};

/** Settings (Super Admin only): generic schema-driven tabs, branding uploads, test email, emergency sign-out and the system overview. */
final class SettingsController extends Controller {
  /** Tabs managed on their own pages: settings GET redirects there and saving returns there. */
  public const PAGES = ['backups' => '/admin/backups', 'cron' => '/admin/cron', 'updates' => '/admin/updates'];
  private const BRAND = ['logo' => [['png', 'jpg', 'jpeg', 'webp'], 1048576, ['image/png', 'image/jpeg', 'image/webp']], 'favicon' => [['png', 'ico'], 262144, ['image/png', 'image/x-icon', 'image/vnd.microsoft.icon']]];

  public function __construct(Request $request) {
    parent::__construct($request);
    if (!Auth::isSuperAdmin()) { throw new HttpException(403, 'Only a Super Admin can change system settings.'); } }

  public function index(string $tab = 'overview'): Response {
    $tabs = SettingsSchema::tabs();
    if (!isset($tabs[$tab])) { $this->abort(404, 'Unknown settings section.'); }
    if (isset(self::PAGES[$tab])) { return $this->redirect(self::PAGES[$tab]); }
    $data = ['title' => $tabs[$tab][0].' · Settings', 'tab' => $tab, 'tabs' => $tabs, 'fields' => SettingsSchema::fields($tab), 'styles' => ['css/admin.css'], 'scripts' => ['js/admin.js']];
    $data += match ($tab) {
      'overview' => ['health' => SystemHealth::snapshot(), 'counts' => DB::pairs("SELECT status, COUNT(*) FROM users GROUP BY status"), 'modulesOn' => count(array_filter(array_keys(Modules::all()), [Modules::class, 'enabled'])), 'lastCheck' => UpdateService::lastCheck()],
      'users' => ['roles' => DB::all("SELECT r.name, r.slug, COUNT(u.id) AS n FROM roles r LEFT JOIN users u ON u.role_id=r.id AND u.status='active' GROUP BY r.id, r.name, r.slug ORDER BY r.id"), 'permCount' => (int) DB::value('SELECT COUNT(*) FROM permissions')],
      'modules' => ['modules' => Modules::all()],
      'ai' => ['aiView' => is_file(NY_ROOT.'/modules/Ai/views/settings.php'), 'aiOn' => Modules::enabled('ai')],
      'storage' => ['usage' => SystemHealth::usage(), 'health' => SystemHealth::snapshot()],
      'security' => ['sessions' => (int) DB::value("SELECT COUNT(*) FROM users WHERE status='active' AND id<>?", [$this->uid()]), 'failed' => (int) DB::value("SELECT COUNT(*) FROM audit_logs WHERE action='auth.failed' AND created_at > ?", [date('Y-m-d H:i:s', strtotime('-7 days'))]), 'denied' => (int) DB::value("SELECT COUNT(*) FROM audit_logs WHERE action='access.denied' AND created_at > ?", [date('Y-m-d H:i:s', strtotime('-7 days'))])],
      default => [] };
    return $this->view('admin::settings/index', $data); }

  public function save(string $tab): Response {
    $fields = SettingsSchema::fields($tab);
    if (!$fields) { $this->abort(404, 'Unknown settings section.'); }
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
    $changed = [];
    foreach ($values as $k => $v) { if ((string) Settings::get($k, '') !== (string) $v) { $changed[] = $k; } }
    if ($changed) {
      $save = array_intersect_key($values, array_flip($changed));
      if (isset($save['appearance.primary_color'])) { $save['branding.primary_color'] = $save['appearance.primary_color']; }
      Settings::setMany($save);
      Audit::log('settings.update', 'settings', null, ['tab' => $tab, 'changed' => $changed]); }
    $this->flash($changed ? 'Settings saved.' : 'No changes to save.');
    return $this->redirect($return); }

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
    $app = (string) setting('branding.app_name', 'Niyanta');
    try {
      Mailer::send($u['email'], "Test email from $app", Mailer::template('It works! 🎉', '<p>Hi '.e($u['name']).',</p><p>This test message confirms that '.e($app).' can send email using the <b>'.e((string) Settings::get('email.driver', 'mail')).'</b> driver.</p><p style="color:#888;font-size:12px">Sent '.e(date('c')).'</p>'), $u['name']);
      Audit::log('settings.email_test', 'settings', null, ['ok' => true, 'driver' => Settings::get('email.driver', 'mail')]);
      $this->flash('Test email sent to '.$u['email'].'. Check your inbox (and spam folder).'); }
    catch (\Throwable $e) {
      Audit::log('settings.email_test', 'settings', null, ['ok' => false, 'driver' => Settings::get('email.driver', 'mail')]);
      $this->flash('Test email failed: '.mb_substr($e->getMessage(), 0, 300), 'error'); }
    return $this->redirect('/admin/settings/email'); }

  /** Emergency: sign out every other user by bumping their session version. */
  public function logoutAll(): Response {
    $n = DB::query('UPDATE users SET session_version=session_version+1 WHERE id<>?', [$this->uid()])->rowCount();
    Audit::log('security.logout_all', 'user', null, ['users' => $n]);
    $this->flash("Signed out $n other user(s) on every device. You stay signed in.");
    return $this->redirect('/admin/settings/security'); } }
