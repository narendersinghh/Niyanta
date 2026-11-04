<?php
namespace Modules\Attendance\Controllers;
use Core\{Audit, DB, Response};
use Modules\Attendance\Services\{DesktopApp, DesktopInstall, Monitoring, Timesheets, Tracker, TimeService as T};

/**
 * Time Tracking page (read-only live status, desktop app downloads, week entries), manual corrections, monitoring consent
 * and connected devices. Tracking itself (start, pause, switch, end) happens only in the Niyanta Tracker desktop app.
 */
final class TimeController extends BaseController {
  public function index(): Response {
    $u = $this->user(); $uid = (int) $u['id'];
    $tokens = DB::all('SELECT id, name, kind, device_os, app_version, created_at, last_used_at FROM agent_tokens WHERE user_id=? AND revoked_at IS NULL ORDER BY COALESCE(last_used_at, created_at) DESC', [$uid]);
    $shotsToday = Monitoring::enabled() ? (int) DB::value('SELECT COUNT(*) FROM screenshots WHERE user_id=? AND taken_at>=?', [$uid, date('Y-m-d 00:00:00')]) : 0;
    $st = Tracker::state($uid);
    $app = DesktopApp::latest();
    /* one-line install commands: a personal key valid for 2 hours (only over HTTPS, see DesktopInstall) */
    $key = $app['ok'] && DesktopInstall::available() ? DesktopInstall::key($uid) : null;
    return $this->view('attendance::time/index', ['title' => 'Time Tracking', 'st' => $st,
      'monitoring' => Monitoring::enabled(), 'consent' => Monitoring::consent($uid), 'policy' => Monitoring::policy(), 'retention' => Monitoring::retention(), 'shotsToday' => $shotsToday,
      'tokens' => $tokens, 'app' => $app, 'install' => $key ? ['commands' => DesktopInstall::commands($key), 'until' => DesktopInstall::expires($key)] : null,
      'styles' => ['css/attendance.css'], 'scripts' => ['js/attendance.js']]); }

  /** Live status for the page and the dashboard card (polled about every 30 s). */
  public function status(): Response { return $this->json(['ok' => true, 'projects' => feature_on('projects')] + Tracker::state($this->uid())); }

  /** Installer from the server's mirror of the newest release (signed in, attendance.track), streamed as an attachment. */
  public function download(string $file): Response {
    $file = rawurldecode($file);
    $path = DesktopApp::path($file);
    $c = DesktopApp::classify($file);
    if (!$path || !$c) { $this->abort(404, 'That installer is no longer available. Reload the page to get the newest version.'); }
    Audit::log('tracker.download', 'settings', null, ['version' => $c['version'], 'file' => $file]);
    return Response::file($path, $file, 'application/octet-stream'); }

  public function tasks(): Response { return $this->json(['tasks' => T::tasks($this->user(), $this->request->int('project_id'))]); }
  private function entryRules(): array { return ['date' => 'required|date', 'start_time' => 'required|time', 'end_time' => 'nullable|time', 'duration' => 'nullable|max:10', 'project_id' => 'nullable|integer', 'task_id' => 'nullable|integer', 'description' => 'nullable|max:500', 'billable' => 'boolean']; }

  public function storeEntry(): Response {
    $u = $this->user();
    $d = $this->validate($this->entryRules());
    return $this->act(static function () use ($u, $d): string {
      $uid = (int) $u['id'];
      $row = T::manualData($u, $d);
      self::checkOpen($uid, $row['started_at']);
      $id = DB::transaction(static function () use ($uid, $row): int {
        $id = DB::insert('time_entries', $row + ['user_id' => $uid, 'source' => 'manual', 'status' => Timesheets::entryStatus($uid, $row['started_at']), 'created_at' => date('Y-m-d H:i:s')]);
        Timesheets::sync($uid, T::weekStart(substr($row['started_at'], 0, 10)));
        \Modules\Attendance\Services\AttendanceService::resync($uid, substr($row['started_at'], 0, 10));
        return $id; });
      Audit::log('time.manual_create', 'time_entry', $id, ['duration' => $row['duration_seconds']]);
      return 'Time entry added ('.hours($row['duration_seconds']).').'; }); }

  /** Time can be added to or moved into a week only while it is open for the owner. */
  private static function checkOpen(int $uid, string $startedAt): void {
    $date = substr($startedAt, 0, 10);
    if (!Timesheets::canEditDate($date, Timesheets::weekRejected($uid, T::weekStart($date)))) { throw new \DomainException(Timesheets::payrollLocked($date) ? 'Payroll for that month is finalized, so its time can no longer change.' : 'That week is closed. You can log or edit time for the current and previous week.'); } }

  private function ownEditable(int $id): array {
    $e = DB::first('SELECT * FROM time_entries WHERE id=? AND user_id=? LIMIT 1', [$id, $this->uid()]) ?? $this->abort(404, 'Entry not found.');
    if (!T::editable($e)) { $this->abort(422, $e['is_running'] ? 'This entry is still being tracked. End or pause tracking in the desktop app first.' : 'This entry is locked: its week is closed or its payroll period is finalized.'); }
    return $e; }

  public function updateEntry(int $id): Response {
    $u = $this->user();
    $e = $this->ownEditable($id);
    $d = $this->validate($this->entryRules());
    return $this->act(static function () use ($u, $d, $e, $id): string {
      $row = T::manualData($u, $d, $id);
      $uid = (int) $u['id'];
      if (T::weekStart(substr($row['started_at'], 0, 10)) !== T::weekStart(substr($e['started_at'], 0, 10))) { self::checkOpen($uid, $row['started_at']); }
      if ($e['source'] !== 'manual' && ($row['started_at'] !== $e['started_at'] || $row['ended_at'] !== $e['ended_at'])) { $row['source'] = 'manual'; }
      if ($e['source'] !== 'manual' && ($row['started_at'] === $e['started_at'] && $row['ended_at'] === $e['ended_at'])) { $row['duration_seconds'] = (int) $e['duration_seconds']; }
      DB::transaction(static function () use ($uid, $row, $e, $id): void {
        DB::update('time_entries', $row + ['status' => Timesheets::entryStatus($uid, $row['started_at'])], 'id=?', [$id]);
        foreach (array_unique([T::weekStart(substr($e['started_at'], 0, 10)), T::weekStart(substr($row['started_at'], 0, 10))]) as $w) { Timesheets::sync($uid, $w); }
        foreach (array_unique([substr($e['started_at'], 0, 10), substr($row['started_at'], 0, 10)]) as $dt) { \Modules\Attendance\Services\AttendanceService::resync($uid, $dt); } });
      Audit::log('time.entry_update', 'time_entry', $id, ['before' => ['start' => $e['started_at'], 'end' => $e['ended_at'], 'project' => $e['project_id']], 'after' => ['start' => $row['started_at'], 'end' => $row['ended_at'], 'project' => $row['project_id']]]);
      return 'Time entry updated.'; }); }

  public function deleteEntry(int $id): Response {
    $e = $this->ownEditable($id);
    DB::transaction(static function () use ($e, $id): void { DB::delete('time_entries', 'id=?', [$id]); Timesheets::sync((int) $e['user_id'], T::weekStart(substr($e['started_at'], 0, 10))); \Modules\Attendance\Services\AttendanceService::resync((int) $e['user_id'], substr($e['started_at'], 0, 10)); });
    Audit::log('time.entry_delete', 'time_entry', $id, ['start' => $e['started_at'], 'duration' => $e['duration_seconds']]);
    $this->flash('Time entry deleted.');
    return $this->back(); }

  public function consent(): Response {
    if (!Monitoring::enabled()) { $this->abort(422, 'Screenshot monitoring is not enabled.'); }
    if (!$this->request->input('agree')) { $this->flash('Please tick the box to confirm you have read the notice.', 'error'); return $this->back(); }
    Monitoring::giveConsent($this->uid(), $this->request->ip());
    $this->flash('Thanks, consent recorded. You can revoke it at any time.');
    return $this->redirect('/time#monitoring'); }

  public function revokeConsent(): Response {
    Monitoring::revokeConsent($this->uid());
    $this->flash('Consent revoked. No further screenshots will be accepted.');
    return $this->redirect('/time#monitoring'); }

  public function revokeToken(int $id): Response {
    if (!Monitoring::revokeToken($this->uid(), $id)) { $this->abort(404, 'Token not found.'); }
    $this->flash('Device disconnected. The tracker on it has to sign in again.');
    return $this->redirect('/time#devices'); } }
