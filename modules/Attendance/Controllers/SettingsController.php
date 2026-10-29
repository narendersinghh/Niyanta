<?php
namespace Modules\Attendance\Controllers;
use Core\{Audit, Response, Settings};
use Modules\Attendance\Services\DesktopApp;

/** Super-admin settings for attendance, idle detection and monitoring, plus desktop installer uploads. */
final class SettingsController extends BaseController {
  public const RULES = ['idle_minutes' => 'required|integer|min:1|max:120', 'screenshots_enabled' => 'boolean', 'screenshot_interval_seconds' => 'required|integer|min:10|max:3600', 'screenshot_retention_days' => 'required|integer|min:1|max:365'];

  public function edit(): Response { return $this->redirect('/admin/settings/attendance'); }

  public function save(): Response {
    $d = $this->validate(self::RULES, ['idle_minutes' => 'Idle threshold', 'screenshot_interval_seconds' => 'Screenshot interval', 'screenshot_retention_days' => 'Retention'], $this->request->arr('attendance'));
    $kv = [];
    foreach ($d as $k => $v) { $kv['attendance.'.$k] = (string) (int) $v; }
    Settings::setMany($kv);
    Audit::log('settings.update', 'settings', null, ['group' => 'attendance', 'values' => $kv]);
    $this->flash('Attendance settings saved.');
    return $this->redirect('/admin/settings/attendance'); }

  /** Upload a Niyanta Tracker installer (stored privately in storage/desktop/<version>/). */
  public function uploadInstaller(): Response {
    $f = $this->request->file('installer');
    return $this->act(function () use ($f): string {
      if (!$f) { throw new \DomainException('Choose an installer file to upload.'); }
      $r = DesktopApp::upload($f, $this->request->str('version'), $this->uid());
      return 'Uploaded '.$r['name'].' ('.DesktopApp::PLATFORMS[$r['platform']][0].', '.$r['version'].').'; }, '/admin/settings/attendance#desktop'); }

  public function deleteInstaller(): Response {
    return $this->act(function (): string {
      if (!DesktopApp::remove($this->request->str('id'), $this->uid())) { throw new \DomainException('Installer not found.'); }
      return 'Installer removed.'; }, '/admin/settings/attendance#desktop'); } }
