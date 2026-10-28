<?php
namespace Modules\Attendance\Controllers;
use Core\{Audit, DB, Response, Session, Settings};
use Modules\Attendance\Services\{AttendanceService, Monitoring, TimeService as T};

/** Timer, heartbeat/idle, manual entries, monitoring consent and agent tokens. */
final class TimeController extends BaseController {
  public function index(): Response {
    $u = $this->user(); $uid = (int) $u['id'];
    $ws = $this->weekParam();
    $running = T::running($uid);
    $todayRow = DB::first('SELECT COALESCE(SUM(CASE WHEN is_running=1 THEN GREATEST(0, TIMESTAMPDIFF(SECOND, started_at, NOW()) - discarded_seconds) ELSE duration_seconds END),0) AS total, COALESCE(SUM(GREATEST(0, CAST(idle_seconds AS SIGNED) - discarded_seconds)),0) AS idle FROM time_entries WHERE user_id=? AND started_at>=?', [$uid, date('Y-m-d 00:00:00')]);
    $att = AttendanceService::today($uid);
    $total = (int) $todayRow['total']; $idle = min($total, (int) $todayRow['idle']);
    $tokens = DB::all('SELECT id, name, created_at, last_used_at FROM agent_tokens WHERE user_id=? AND revoked_at IS NULL ORDER BY id DESC', [$uid]);
    $shotsToday = Monitoring::enabled() ? (int) DB::value('SELECT COUNT(*) FROM screenshots WHERE user_id=? AND taken_at>=?', [$uid, date('Y-m-d 00:00:00')]) : 0;
    $weekTotal = (int) DB::value('SELECT COALESCE(SUM(duration_seconds),0) FROM time_entries WHERE user_id=? AND is_running=0 AND started_at>=? AND started_at<?', [$uid, $ws.' 00:00:00', date('Y-m-d', strtotime($ws.' +7 days')).' 00:00:00']);
    return $this->view('attendance::time/index', ['title' => 'Time Tracker', 'running' => $running, 'projects' => T::projects($u), 'ws' => $ws, 'days' => T::weekEntries($uid, $ws), 'weekTotal' => $weekTotal,
      'stats' => ['total' => $total, 'idle' => $idle, 'active' => $total - $idle, 'break' => AttendanceService::live($att)['break']], 'attState' => AttendanceService::state($att),
      'idleMinutes' => max(1, (int) Settings::get('attendance.idle_minutes', 5)), 'monitoring' => Monitoring::enabled(), 'consent' => Monitoring::consent($uid), 'interval' => Monitoring::interval(), 'retention' => Monitoring::retention(), 'shotsToday' => $shotsToday,
      'tokens' => $tokens, 'newToken' => Session::getFlash('agent_token'), 'tsStatus' => DB::value('SELECT status FROM timesheets WHERE user_id=? AND week_start=?', [$uid, $ws]),
      'styles' => ['css/attendance.css'], 'scripts' => ['js/attendance.js']]); }

  public function start(): Response {
    $u = $this->user();
    $d = $this->validate(['project_id' => 'nullable|integer', 'task_id' => 'nullable|integer', 'description' => 'nullable|max:500', 'billable' => 'boolean']);
    return $this->act(static function () use ($u, $d): array { $id = T::start($u, $d); return ['message' => 'Timer started.', 'id' => $id]; }, '/time'); }

  public function stop(): Response {
    return $this->act(function (): array {
      $r = T::stop($this->uid());
      if (!$r) { throw new \DomainException('No timer is running.'); }
      return ['message' => 'Timer stopped — '.hours($r['duration']).' logged.', 'duration' => $r['duration']]; }, $this->request->str('back') === 'dashboard' ? '/' : '/time'); }

  public function heartbeat(): Response {
    $r = T::heartbeat($this->uid(), min(3600, max(0, $this->request->int('active'))), min(3600, max(0, $this->request->int('idle'))));
    return $this->json($r ? ['running' => true] + $r : ['running' => false]); }

  /** User returned from idle: keep (no change) or discard the idle seconds. */
  public function idle(): Response {
    $action = $this->request->str('action');
    $sec = min(86400, max(0, $this->request->int('seconds')));
    if ($action !== 'discard') {
      Audit::log('time.idle_keep', 'user', $this->uid(), ['seconds' => $sec]);
      return $this->json(['ok' => true, 'discarded' => 0]); }
    $r = T::discardIdle($this->uid(), $sec);
    return $this->json($r ? ['ok' => true] + $r : ['ok' => false, 'error' => 'No timer is running.'], $r ? 200 : 422); }

  public function tasks(): Response { return $this->json(['tasks' => T::tasks($this->user(), $this->request->int('project_id'))]); }

  private function entryRules(): array { return ['date' => 'required|date', 'start_time' => 'required|time', 'end_time' => 'nullable|time', 'duration' => 'nullable|max:10', 'project_id' => 'nullable|integer', 'task_id' => 'nullable|integer', 'description' => 'nullable|max:500', 'billable' => 'boolean']; }

  public function storeEntry(): Response {
    $u = $this->user();
    $d = $this->validate($this->entryRules());
    return $this->act(static function () use ($u, $d): string {
      $row = T::manualData($u, $d);
      $id = DB::insert('time_entries', $row + ['user_id' => $u['id'], 'source' => 'manual', 'status' => 'draft', 'created_at' => date('Y-m-d H:i:s')]);
      Audit::log('time.manual_create', 'time_entry', $id, ['duration' => $row['duration_seconds']]);
      return 'Time entry added ('.hours($row['duration_seconds']).').'; }); }

  private function ownEditable(int $id): array {
    $e = DB::first('SELECT * FROM time_entries WHERE id=? AND user_id=? LIMIT 1', [$id, $this->uid()]) ?? $this->abort(404, 'Entry not found.');
    if (!T::editable($e)) { $this->abort(422, 'Submitted or approved entries are locked.'); }
    return $e; }

  public function updateEntry(int $id): Response {
    $u = $this->user();
    $e = $this->ownEditable($id);
    $d = $this->validate($this->entryRules());
    return $this->act(static function () use ($u, $d, $e, $id): string {
      $row = T::manualData($u, $d, $id);
      if ($e['source'] !== 'manual' && ($row['started_at'] !== $e['started_at'] || $row['ended_at'] !== $e['ended_at'])) { $row['source'] = 'manual'; }
      if ($e['source'] !== 'manual' && ($row['started_at'] === $e['started_at'] && $row['ended_at'] === $e['ended_at'])) { $row['duration_seconds'] = (int) $e['duration_seconds']; }
      DB::update('time_entries', $row, 'id=?', [$id]);
      Audit::log('time.entry_update', 'time_entry', $id, ['before' => ['start' => $e['started_at'], 'end' => $e['ended_at'], 'project' => $e['project_id']], 'after' => ['start' => $row['started_at'], 'end' => $row['ended_at'], 'project' => $row['project_id']]]);
      return 'Time entry updated.'; }); }

  public function deleteEntry(int $id): Response {
    $e = $this->ownEditable($id);
    DB::delete('time_entries', 'id=?', [$id]);
    Audit::log('time.entry_delete', 'time_entry', $id, ['start' => $e['started_at'], 'duration' => $e['duration_seconds']]);
    $this->flash('Time entry deleted.');
    return $this->back(); }

  public function consent(): Response {
    if (!Monitoring::enabled()) { $this->abort(422, 'Screenshot monitoring is not enabled.'); }
    if (!$this->request->input('agree')) { $this->flash('Please tick the box to confirm you have read the notice.', 'error'); return $this->back(); }
    Monitoring::giveConsent($this->uid(), $this->request->ip());
    $this->flash('Thanks — consent recorded. You can revoke it at any time.');
    return $this->redirect('/time#monitoring'); }

  public function revokeConsent(): Response {
    Monitoring::revokeConsent($this->uid());
    $this->flash('Consent revoked. No further screenshots will be accepted.');
    return $this->redirect('/time#monitoring'); }

  public function createToken(): Response {
    $d = $this->validate(['name' => 'required|max:100'], ['name' => 'Device name']);
    return $this->act(function () use ($d): string { Session::flash('agent_token', ['name' => $d['name'], 'token' => Monitoring::createToken($this->uid(), $d['name'])]); return 'Agent token created. Copy it now — it will not be shown again.'; }, '/time#agent'); }

  public function revokeToken(int $id): Response {
    if (!Monitoring::revokeToken($this->uid(), $id)) { $this->abort(404, 'Token not found.'); }
    $this->flash('Agent token revoked.');
    return $this->redirect('/time#agent'); } }
