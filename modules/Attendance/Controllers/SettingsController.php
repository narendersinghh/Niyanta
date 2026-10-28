<?php
namespace Modules\Attendance\Controllers;
use Core\{Audit, Response, Settings};

/** Super-admin settings for attendance, idle detection and monitoring. */
final class SettingsController extends BaseController {
  public const RULES = ['idle_minutes' => 'required|integer|min:1|max:120', 'screenshots_enabled' => 'boolean', 'screenshot_interval' => 'required|integer|min:1|max:60', 'screenshot_retention_days' => 'required|integer|min:1|max:365'];

  public function edit(): Response { return $this->view('attendance::settings_page', ['title' => 'Attendance settings', 'styles' => ['css/attendance.css']]); }

  public function save(): Response {
    $d = $this->validate(self::RULES, ['idle_minutes' => 'Idle threshold', 'screenshot_interval' => 'Screenshot interval', 'screenshot_retention_days' => 'Retention'], $this->request->arr('attendance'));
    $kv = [];
    foreach ($d as $k => $v) { $kv['attendance.'.$k] = (string) (int) $v; }
    Settings::setMany($kv);
    Audit::log('settings.update', 'settings', null, ['group' => 'attendance', 'values' => $kv]);
    $this->flash('Attendance settings saved.');
    return $this->redirect('/attendance/settings'); } }
