<?php
namespace Modules\Attendance\Services;
use Core\{Audit, DB};

/**
 * The desktop tracker's day: attendance and time tracking are one thing.
 * Start = clock in + start an entry; Pause = stop the entry + start a break; Resume = end the break + new entry on the last
 * project (and clock in first when the day has not started, e.g. after midnight); Switch = close the running entry and start
 * the next at the same second (no gap). The day ends by itself at midnight (settle()): a running entry is split there, the old
 * day is closed at 00:00 and the new day continues on the same project/task. End day (legacy) = stop + clock out.
 * Every action is serialised per user (row lock) and idempotent, so a desktop retrying after a timeout cannot double-book.
 */
final class Tracker {
  public const STATES = ['not_started' => 'Not started', 'tracking' => 'Tracking', 'paused' => 'Paused', 'stopped' => 'Not tracking', 'ended' => 'Day ended'];

  /** Snapshot of today's tracker state for the desktop app and the web status card. */
  public static function state(int $uid): array {
    $att = AttendanceService::today($uid);
    $st = AttendanceService::state($att);
    $run = TimeService::running($uid);
    $state = $run ? 'tracking' : match ($st) { 'break' => 'paused', 'in' => 'stopped', 'done' => 'ended', default => 'not_started' };
    $today = date('Y-m-d');
    $tot = DB::first('SELECT COALESCE(SUM(CASE WHEN is_running=1 THEN GREATEST(0, TIMESTAMPDIFF(SECOND, started_at, NOW()) - discarded_seconds) ELSE duration_seconds END),0) AS tracked, COALESCE(SUM(GREATEST(0, CAST(idle_seconds AS SIGNED) - CAST(discarded_seconds AS SIGNED))),0) AS idle, COUNT(*) AS n FROM time_entries WHERE user_id=? AND started_at>=? AND started_at<?', [$uid, $today.' 00:00:00', date('Y-m-d', strtotime('+1 day')).' 00:00:00']);
    $tracked = (int) $tot['tracked']; $idle = min($tracked, (int) $tot['idle']);
    $last = DB::first('SELECT te.project_id, te.task_id, te.description, p.name AS project_name, t.title AS task_title FROM time_entries te LEFT JOIN projects p ON p.id=te.project_id LEFT JOIN tasks t ON t.id=te.task_id WHERE te.user_id=? ORDER BY te.started_at DESC, te.id DESC LIMIT 1', [$uid]);
    $device = $run && $run['agent_token_id'] ? DB::first('SELECT name, device_os FROM agent_tokens WHERE id=?', [$run['agent_token_id']]) : null;
    $pj = feature_on('projects');
    $da = TimeService::dayActivity($uid, $today);
    return ['state' => $state, 'label' => self::STATES[$state], 'server_time' => date('c'),
      'day' => ['date' => $today, 'clock_in' => $att && $att['clock_in'] ? date('c', strtotime($att['clock_in'])) : null, 'clock_out' => $att && $att['clock_out'] ? date('c', strtotime($att['clock_out'])) : null,
        'paused_since' => $att && !empty($att['open_break']) ? date('c', strtotime($att['open_break']['start_at'])) : null],
      'running' => $run ? ['id' => (int) $run['id'], 'started_at' => date('c', strtotime($run['started_at'])), 'elapsed' => TimeService::elapsed($run), 'idle_seconds' => (int) $run['idle_seconds'], 'discarded_seconds' => (int) $run['discarded_seconds'],
        'project_id' => $pj && $run['project_id'] ? (int) $run['project_id'] : null, 'project' => $pj ? $run['project_name'] : null, 'task_id' => $pj && $run['task_id'] ? (int) $run['task_id'] : null, 'task' => $pj ? $run['task_title'] : null,
        'description' => $run['description'], 'source' => $run['source'], 'device' => $device['name'] ?? null, 'device_os' => $device['device_os'] ?? null, 'last_heartbeat_at' => $run['last_heartbeat_at'] ? date('c', strtotime($run['last_heartbeat_at'])) : null] : null,
      'last' => $last ? ['project_id' => $pj && $last['project_id'] ? (int) $last['project_id'] : null, 'project' => $pj ? $last['project_name'] : null, 'task_id' => $pj && $last['task_id'] ? (int) $last['task_id'] : null, 'task' => $pj ? $last['task_title'] : null, 'description' => $last['description']] : null,
      'totals' => ['tracked' => $tracked, 'active' => $tracked - $idle, 'idle' => $idle, 'paused' => AttendanceService::live($att)['break'], 'entries' => (int) $tot['n'],
        'activity' => $da['pct'], 'meeting' => $da['meeting']]]; }

  /**
   * Server time for an action the app queued while offline: the client's time, never in the future, never before the
   * person's last recorded event and at most 6 hours back. Null (= now) when no or a current time is given.
   */
  public static function at(int $uid, ?string $client): ?string {
    if ($client === null || $client === '' || ($t = strtotime($client)) === false || $t >= time() - 2) { return null; }
    $last = DB::value("SELECT GREATEST(COALESCE(MAX(started_at),'1970-01-01'), COALESCE(MAX(ended_at),'1970-01-01')) FROM time_entries WHERE user_id=?", [$uid]);
    $att = DB::value("SELECT GREATEST(COALESCE(a.clock_in,'1970-01-01'), COALESCE(a.clock_out,'1970-01-01'), COALESCE((SELECT MAX(GREATEST(b.start_at, COALESCE(b.end_at, b.start_at))) FROM attendance_breaks b WHERE b.attendance_id=a.id),'1970-01-01')) FROM attendance a WHERE a.user_id=? ORDER BY a.work_date DESC LIMIT 1", [$uid]);
    $floor = max(time() - 21600, strtotime((string) $last) ?: 0, $att ? (strtotime((string) $att) ?: 0) : 0);
    return date('Y-m-d H:i:s', min(time(), max($t, $floor))); }

  /**
   * Bring the person's tracking in line with the clock (runs inside every action, heartbeat and state read, user lock held):
   * a running entry that started before today is split at every midnight when the app was alive across it (its last heartbeat
   * or $alive, the time of this action/heartbeat, is at or after midnight and no more than STALE_SECONDS after the last
   * heartbeat); otherwise it is stopped at its last heartbeat once stale. Open days before today with nothing running are closed.
   */
  public static function settle(int $uid, ?string $alive = null): void {
    DB::transaction(static function () use ($uid, $alive): void {
      AttendanceService::lock($uid);
      $now = time(); $today = date('Y-m-d', $now); $mid = strtotime($today.' 00:00:00');
      $run = DB::first('SELECT * FROM time_entries WHERE user_id=? AND is_running=1 ORDER BY id DESC LIMIT 1', [$uid]);
      if ($run && strtotime($run['started_at']) < $mid) {
        $hb = strtotime($run['last_heartbeat_at'] ?: $run['started_at']);
        $seen = max($hb, $alive ? min($now, (int) strtotime($alive)) : 0);
        if ($seen >= $mid && $seen - $hb <= TimeService::STALE_SECONDS) { self::split($uid, $run, $seen); }
        elseif ($now - $hb > TimeService::STALE_SECONDS) { TimeService::stop($uid, null, 'stale', true); } }
      foreach (DB::all('SELECT * FROM attendance WHERE user_id=? AND clock_out IS NULL AND clock_in IS NOT NULL AND work_date<?', [$uid, $today]) as $r) {
        if (DB::value('SELECT 1 FROM time_entries WHERE user_id=? AND is_running=1 AND started_at<? LIMIT 1', [$uid, date('Y-m-d', strtotime($r['work_date'].' +1 day')).' 00:00:00'])) { continue; }
        AttendanceService::autoCloseRow($r);
        Audit::log('attendance.auto_close', 'attendance', (int) $r['id'], ['reason' => 'day over'], $uid); } }); }

  /** Midnight while tracking: end the entry and the day at 00:00 and continue on the same project/task in the new day, up to $seen. */
  private static function split(int $uid, array $run, int $seen): void {
    for ($d = date('Y-m-d', strtotime(substr($run['started_at'], 0, 10).' +1 day')); ($b = strtotime($d.' 00:00:00')) <= $seen; $d = date('Y-m-d', strtotime($d.' +1 day'))) {
      $at = date('Y-m-d H:i:s', $b); $prev = date('Y-m-d', strtotime($d.' -1 day'));
      TimeService::stop($uid, $at, 'midnight');
      $old = DB::first('SELECT * FROM attendance WHERE user_id=? AND work_date=? AND clock_out IS NULL AND clock_in IS NOT NULL', [$uid, $prev]);
      if ($old) { AttendanceService::closeRow($old, $at); }
      $att = DB::first('SELECT * FROM attendance WHERE user_id=? AND work_date=?', [$uid, $d]);
      if (!$att) { DB::insert('attendance', ['user_id' => $uid, 'work_date' => $d, 'clock_in' => $at, 'status' => 'present', 'ip_in' => $old['ip_in'] ?? null]); }
      elseif (!$att['clock_in'] || $att['clock_out']) { DB::update('attendance', ['clock_in' => $att['clock_in'] ?: $at, 'clock_out' => null, 'status' => 'present'], 'id=?', [$att['id']]); }
      $id = DB::insert('time_entries', ['user_id' => $uid, 'project_id' => $run['project_id'], 'task_id' => $run['task_id'], 'description' => $run['description'], 'started_at' => $at, 'last_heartbeat_at' => date('Y-m-d H:i:s', max($b, strtotime($run['last_heartbeat_at'] ?: $run['started_at']))),
        'source' => $run['source'], 'agent_token_id' => $run['agent_token_id'], 'billable' => $run['billable'], 'is_running' => 1, 'status' => 'draft', 'created_at' => date('Y-m-d H:i:s')]);
      Audit::log('tracker.new_day', 'time_entry', $id, ['from' => (int) $run['id'], 'date' => $d], $uid);
      $run = DB::first('SELECT * FROM time_entries WHERE id=?', [$id]); } }

  /** Start (or continue) the day: clock in when needed, then start an entry. Already tracking: nothing changes. */
  public static function startDay(array $user, array $d, ?int $tokenId, string $ip, ?string $at = null): array {
    $uid = (int) $user['id'];
    TimeService::checkTarget($user, isset($d['project_id']) ? (int) $d['project_id'] : null, isset($d['task_id']) ? (int) $d['task_id'] : null);
    DB::transaction(static function () use ($user, $uid, $d, $tokenId, $ip, $at): void {
      AttendanceService::lock($uid);
      $at = self::at($uid, $at);
      self::settle($uid, $at ?? date('Y-m-d H:i:s'));
      if (TimeService::running($uid)) { return; }
      $st = AttendanceService::state(AttendanceService::today($uid));
      if ($st === 'out' || $st === 'done') { AttendanceService::clockIn($uid, $ip, $at); }
      elseif ($st === 'break') { AttendanceService::endBreak($uid, $at); }
      TimeService::start($user, $d, 'desktop', $tokenId, $at);
      Audit::log('tracker.day_start', 'user', $uid, ['from' => $st, 'project_id' => $d['project_id'] ?? null], $uid); });
    return self::state($uid); }

  /**
   * Pause: stop the running entry and start a break. Paused already, or nothing open: nothing changes. An entry still running
   * from an earlier day (the app paused just before midnight) is stopped at the pause time and its day is closed.
   */
  public static function pause(int $uid, ?string $at = null): array {
    DB::transaction(static function () use ($uid, $at): void {
      AttendanceService::lock($uid);
      $now = self::at($uid, $at) ?? date('Y-m-d H:i:s');
      self::settle($uid, $now);
      $st = AttendanceService::state(AttendanceService::today($uid));
      if ($st === 'break') { return; }
      if ($st !== 'in') {
        if (TimeService::stop($uid, $now, 'pause')) { self::settle($uid); Audit::log('tracker.pause', 'user', $uid, ['day' => 'previous'], $uid); }
        return; }
      TimeService::stop($uid, $now, 'pause');
      AttendanceService::startBreak($uid, $now);
      Audit::log('tracker.pause', 'user', $uid, [], $uid); });
    return self::state($uid); }

  /**
   * Resume after a pause (or after the server stopped a stale entry): end the break and track the given or the last project.
   * When today has not started (a new day after midnight) or was ended, it clocks in first, like Start.
   */
  public static function resume(array $user, array $d, ?int $tokenId, ?string $at = null, string $ip = ''): array {
    $uid = (int) $user['id'];
    $last = self::state($uid)['last'] ?? null;
    $target = array_key_exists('project_id', $d) ? $d : ['project_id' => $last['project_id'] ?? null, 'task_id' => $last['task_id'] ?? null, 'description' => $last['description'] ?? null];
    try { TimeService::checkTarget($user, isset($target['project_id']) ? (int) $target['project_id'] : null, isset($target['task_id']) ? (int) $target['task_id'] : null); }
    catch (\DomainException $e) { if (array_key_exists('project_id', $d)) { throw $e; } $target = ['description' => $target['description'] ?? null]; }
    DB::transaction(static function () use ($user, $uid, $target, $tokenId, $at, $ip): void {
      AttendanceService::lock($uid);
      $at = self::at($uid, $at);
      self::settle($uid, $at ?? date('Y-m-d H:i:s'));
      if (TimeService::running($uid)) { return; }
      $st = AttendanceService::state(AttendanceService::today($uid));
      if ($st === 'out' || $st === 'done') { AttendanceService::clockIn($uid, $ip, $at); }
      elseif ($st === 'break') { AttendanceService::endBreak($uid, $at); }
      TimeService::start($user, $target, 'desktop', $tokenId, $at);
      Audit::log('tracker.resume', 'user', $uid, ['project_id' => $target['project_id'] ?? null], $uid); });
    return self::state($uid); }

  /** Switch project/task while tracking: no pause, no gap. */
  public static function switchTo(array $user, array $d, ?int $tokenId, ?string $at = null): array {
    $uid = (int) $user['id'];
    DB::transaction(static function () use ($user, $uid, $d, $tokenId, $at): void {
      AttendanceService::lock($uid);
      $at = self::at($uid, $at);
      self::settle($uid, $at ?? date('Y-m-d H:i:s'));
      TimeService::switchTo($user, $d, 'desktop', $tokenId, $at); });
    return self::state($uid); }

  /** End the day (legacy, the app no longer offers it: days end at midnight): stop the entry and clock out. Ended already: nothing changes. */
  public static function endDay(int $uid, string $ip, ?string $at = null): array {
    DB::transaction(static function () use ($uid, $ip, $at): void {
      AttendanceService::lock($uid);
      $at = self::at($uid, $at);
      self::settle($uid, $at ?? date('Y-m-d H:i:s'));
      $att = AttendanceService::today($uid) ?? DB::first('SELECT * FROM attendance WHERE user_id=? AND clock_out IS NULL AND clock_in IS NOT NULL ORDER BY work_date DESC LIMIT 1', [$uid]);
      if ($att && !array_key_exists('open_break', $att)) { $att['open_break'] = DB::first('SELECT * FROM attendance_breaks WHERE attendance_id=? AND end_at IS NULL LIMIT 1', [$att['id']]); }
      $st = AttendanceService::state($att);
      if ($st === 'done') { TimeService::stop($uid, $at, 'day-end'); return; }
      if ($st === 'out') {
        if (TimeService::stop($uid, $at, 'day-end')) { return; }
        throw new \DomainException('Your day has not started.'); }
      AttendanceService::clockOut($uid, $ip, $at);
      Audit::log('tracker.day_end', 'user', $uid, [], $uid); });
    return self::state($uid); } }
