<?php
namespace Modules\Attendance\Services;
use Core\{Audit, DB};

/**
 * The desktop tracker's day: attendance and time tracking are one thing.
 * Start day = clock in + start an entry; Pause = stop the entry + start a break; Resume = end the break + new entry on the
 * last project; Switch = close the running entry and start the next at the same second (no gap); End day = stop + clock out.
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
    return ['state' => $state, 'label' => self::STATES[$state], 'server_time' => date('c'),
      'day' => ['date' => $today, 'clock_in' => $att && $att['clock_in'] ? date('c', strtotime($att['clock_in'])) : null, 'clock_out' => $att && $att['clock_out'] ? date('c', strtotime($att['clock_out'])) : null,
        'paused_since' => $att && !empty($att['open_break']) ? date('c', strtotime($att['open_break']['start_at'])) : null],
      'running' => $run ? ['id' => (int) $run['id'], 'started_at' => date('c', strtotime($run['started_at'])), 'elapsed' => TimeService::elapsed($run), 'idle_seconds' => (int) $run['idle_seconds'], 'discarded_seconds' => (int) $run['discarded_seconds'],
        'project_id' => $pj && $run['project_id'] ? (int) $run['project_id'] : null, 'project' => $pj ? $run['project_name'] : null, 'task_id' => $pj && $run['task_id'] ? (int) $run['task_id'] : null, 'task' => $pj ? $run['task_title'] : null,
        'description' => $run['description'], 'source' => $run['source'], 'device' => $device['name'] ?? null, 'device_os' => $device['device_os'] ?? null, 'last_heartbeat_at' => $run['last_heartbeat_at'] ? date('c', strtotime($run['last_heartbeat_at'])) : null] : null,
      'last' => $last ? ['project_id' => $pj && $last['project_id'] ? (int) $last['project_id'] : null, 'project' => $pj ? $last['project_name'] : null, 'task_id' => $pj && $last['task_id'] ? (int) $last['task_id'] : null, 'task' => $pj ? $last['task_title'] : null, 'description' => $last['description']] : null,
      'totals' => ['tracked' => $tracked, 'active' => $tracked - $idle, 'idle' => $idle, 'paused' => AttendanceService::live($att)['break'], 'entries' => (int) $tot['n']]]; }

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

  /** Start (or continue) the day: clock in when needed, then start an entry. Already tracking: nothing changes. */
  public static function startDay(array $user, array $d, ?int $tokenId, string $ip, ?string $at = null): array {
    $uid = (int) $user['id'];
    TimeService::checkTarget($user, isset($d['project_id']) ? (int) $d['project_id'] : null, isset($d['task_id']) ? (int) $d['task_id'] : null);
    DB::transaction(static function () use ($user, $uid, $d, $tokenId, $ip, $at): void {
      AttendanceService::lock($uid);
      if (TimeService::running($uid)) { return; }
      $at = self::at($uid, $at);
      $st = AttendanceService::state(AttendanceService::today($uid));
      if ($st === 'out' || $st === 'done') { AttendanceService::clockIn($uid, $ip, $at); }
      elseif ($st === 'break') { AttendanceService::endBreak($uid, $at); }
      TimeService::start($user, $d, 'desktop', $tokenId, $at);
      Audit::log('tracker.day_start', 'user', $uid, ['from' => $st, 'project_id' => $d['project_id'] ?? null], $uid); });
    return self::state($uid); }

  /** Pause: stop the running entry and start a break. Paused already: nothing changes. */
  public static function pause(int $uid, ?string $at = null): array {
    DB::transaction(static function () use ($uid, $at): void {
      AttendanceService::lock($uid);
      $st = AttendanceService::state(AttendanceService::today($uid));
      if ($st === 'break') { return; }
      if ($st !== 'in') { throw new \DomainException('Start your day before pausing.'); }
      $now = self::at($uid, $at) ?? date('Y-m-d H:i:s');
      TimeService::stop($uid, $now, 'pause');
      AttendanceService::startBreak($uid, $now);
      Audit::log('tracker.pause', 'user', $uid, [], $uid); });
    return self::state($uid); }

  /** Resume after a pause (or after the server stopped a stale entry): end the break and track the given or the last project. */
  public static function resume(array $user, array $d, ?int $tokenId, ?string $at = null): array {
    $uid = (int) $user['id'];
    $last = self::state($uid)['last'] ?? null;
    $target = array_key_exists('project_id', $d) ? $d : ['project_id' => $last['project_id'] ?? null, 'task_id' => $last['task_id'] ?? null, 'description' => $last['description'] ?? null];
    try { TimeService::checkTarget($user, isset($target['project_id']) ? (int) $target['project_id'] : null, isset($target['task_id']) ? (int) $target['task_id'] : null); }
    catch (\DomainException $e) { if (array_key_exists('project_id', $d)) { throw $e; } $target = ['description' => $target['description'] ?? null]; }
    DB::transaction(static function () use ($user, $uid, $target, $tokenId, $at): void {
      AttendanceService::lock($uid);
      if (TimeService::running($uid)) { return; }
      $at = self::at($uid, $at);
      $st = AttendanceService::state(AttendanceService::today($uid));
      if ($st === 'out' || $st === 'done') { throw new \DomainException($st === 'done' ? 'Your day has ended. Start a new day to track again.' : 'Start your day first.'); }
      if ($st === 'break') { AttendanceService::endBreak($uid, $at); }
      TimeService::start($user, $target, 'desktop', $tokenId, $at);
      Audit::log('tracker.resume', 'user', $uid, ['project_id' => $target['project_id'] ?? null], $uid); });
    return self::state($uid); }

  /** Switch project/task while tracking: no pause, no gap. */
  public static function switchTo(array $user, array $d, ?int $tokenId, ?string $at = null): array {
    TimeService::switchTo($user, $d, 'desktop', $tokenId, self::at((int) $user['id'], $at));
    return self::state((int) $user['id']); }

  /** End the day: stop the entry and clock out. Ended already: nothing changes. */
  public static function endDay(int $uid, string $ip, ?string $at = null): array {
    DB::transaction(static function () use ($uid, $ip, $at): void {
      AttendanceService::lock($uid);
      $at = self::at($uid, $at);
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
