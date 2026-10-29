<?php
namespace Modules\Attendance\Services;
use Core\{Audit, DB, Gate, Modules};

/** Timer, heartbeat/idle, manual entries and reporting helpers (used by Payroll). */
final class TimeService {
  public const STALE_SECONDS = 600;
  private static array $vis = [];

  /** Seconds tracked by a user between two dates (inclusive), optionally by entry status. Running entries count up to now. */
  public static function secondsForUser(int $userId, string $from, string $to, ?string $status = null): int {
    $sql = 'SELECT COALESCE(SUM(CASE WHEN is_running=1 THEN GREATEST(0, TIMESTAMPDIFF(SECOND, started_at, NOW()) - discarded_seconds) ELSE duration_seconds END),0) FROM time_entries WHERE user_id=? AND started_at>=? AND started_at<?';
    $p = [$userId, $from.' 00:00:00', date('Y-m-d', strtotime($to.' +1 day')).' 00:00:00'];
    if ($status !== null) { $sql .= ' AND status=?'; $p[] = $status; }
    return (int) DB::value($sql, $p); }

  /** Approved hours (for hourly payroll). */
  public static function approvedHours(int $userId, string $from, string $to): float { return round(self::secondsForUser($userId, $from, $to, 'approved') / 3600, 2); }

  /** Bulk approved hours for payroll runs: [uid => hours] in one query. */
  public static function approvedHoursMany(array $userIds, string $from, string $to): array {
    $userIds = array_values(array_unique(array_map('intval', $userIds)));
    if (!$userIds) { return []; }
    $out = array_fill_keys($userIds, 0.0);
    $sec = DB::pairs("SELECT user_id, COALESCE(SUM(CASE WHEN is_running=1 THEN GREATEST(0, TIMESTAMPDIFF(SECOND, started_at, NOW()) - discarded_seconds) ELSE duration_seconds END),0) FROM time_entries WHERE status='approved' AND started_at>=? AND started_at<? AND user_id IN ".DB::in($userIds).' GROUP BY user_id', [$from.' 00:00:00', date('Y-m-d', strtotime($to.' +1 day')).' 00:00:00', ...$userIds]);
    foreach ($sec as $u => $s) { $out[(int) $u] = round((float) $s / 3600, 2); }
    return $out; }

  /** Present days from attendance (half day = 0.5). */
  public static function presentDays(int $userId, string $from, string $to): float {
    return (float) DB::value("SELECT COALESCE(SUM(CASE status WHEN 'present' THEN 1 WHEN 'half_day' THEN 0.5 ELSE 0 END),0) FROM attendance WHERE user_id=? AND work_date BETWEEN ? AND ?", [$userId, $from, $to]); }

  public static function weekStart(?string $date = null): string { $t = strtotime($date ?: 'today') ?: time(); return date('Y-m-d', strtotime('-'.(date('N', $t) - 1).' days', $t)); }

  public static function running(int $uid): ?array {
    return DB::first('SELECT te.*, p.name AS project_name, p.color AS project_color, t.title AS task_title FROM time_entries te LEFT JOIN projects p ON p.id=te.project_id LEFT JOIN tasks t ON t.id=te.task_id WHERE te.user_id=? AND te.is_running=1 ORDER BY te.id DESC LIMIT 1', [$uid]); }

  /** Elapsed seconds of an entry (running: up to now) minus discarded idle time. */
  public static function elapsed(array $e, ?int $now = null): int {
    if (!$e['is_running']) { return (int) $e['duration_seconds']; }
    return max(0, ($now ?? time()) - strtotime($e['started_at']) - (int) $e['discarded_seconds']); }

  /** Projects visible to the user: null means all. */
  public static function visibleProjectIds(array $user): ?array {
    $uid = (int) $user['id'];
    if (array_key_exists($uid, self::$vis)) { return self::$vis[$uid]; }
    if (class_exists(\Modules\Projects\Services\ProjectAccess::class)) { return self::$vis[$uid] = \Modules\Projects\Services\ProjectAccess::visibleProjectIds($user); }
    if (($user['role_slug'] ?? '') === 'super_admin' || Gate::allows('projects.view_all', $user)) { return self::$vis[$uid] = null; }
    return self::$vis[$uid] = array_map('intval', DB::column('SELECT project_id FROM project_members WHERE user_id=? UNION SELECT id FROM projects WHERE manager_id=?', [$uid, $uid])); }

  /** Title of a time entry: its project (while projects are on), else a neutral label. */
  public static function label(array $e): string { return feature_on('projects') ? (string) ($e['project_name'] ?? 'No project') : 'Tracked time'; }

  /** id => name of open projects for pickers. */
  public static function projects(array $user): array {
    if (!feature_on('projects')) { return []; }
    $ids = self::visibleProjectIds($user);
    if ($ids === []) { return []; }
    $sql = "SELECT id, name FROM projects WHERE status IN ('planning','active','on_hold')";
    $p = [];
    if ($ids !== null) { $sql .= ' AND id IN '.DB::in($ids); $p = $ids; }
    return DB::pairs($sql.' ORDER BY name LIMIT 500', $p); }

  public static function projectVisible(array $user, int $pid): bool {
    if (!feature_on('projects')) { return false; }
    $ids = self::visibleProjectIds($user);
    return ($ids === null || in_array($pid, $ids, true)) && (bool) DB::value('SELECT 1 FROM projects WHERE id=? LIMIT 1', [$pid]); }

  /** Open tasks of a visible project (assigned to the user first). */
  public static function tasks(array $user, int $pid): array {
    if (!self::projectVisible($user, $pid)) { return []; }
    return DB::all("SELECT id, title, status, assignee_id=? AS mine FROM tasks WHERE project_id=? AND status<>'done' ORDER BY (assignee_id=?) DESC, position, id LIMIT 300", [$user['id'], $pid, $user['id']]); }

  /** Validate project/task ids against visibility. Returns [project_id|null, task_id|null]. */
  public static function checkTarget(array $user, ?int $pid, ?int $tid): array {
    $pid = $pid ?: null; $tid = $tid ?: null;
    if (!feature_on('projects')) { return [null, null]; }
    if ($pid && !self::projectVisible($user, $pid)) { throw new \DomainException('You cannot log time on that project.'); }
    if ($tid) {
      if (!$pid) { throw new \DomainException('Choose the project of the task.'); }
      if (!DB::value('SELECT 1 FROM tasks WHERE id=? AND project_id=? LIMIT 1', [$tid, $pid])) { throw new \DomainException('That task does not belong to the selected project.'); } }
    return [$pid, $tid]; }

  /** Start an entry (one running entry per user). $at pins the start second (project switch: same second the previous entry ended). */
  public static function start(array $user, array $d, string $source = 'timer', ?int $tokenId = null, ?string $at = null): int {
    [$pid, $tid] = self::checkTarget($user, isset($d['project_id']) ? (int) $d['project_id'] : null, isset($d['task_id']) ? (int) $d['task_id'] : null);
    $uid = (int) $user['id'];
    return DB::transaction(static function () use ($uid, $pid, $tid, $d, $source, $tokenId, $at): int {
      AttendanceService::lock($uid);
      if (DB::value('SELECT id FROM time_entries WHERE user_id=? AND is_running=1 LIMIT 1', [$uid])) { throw new \DomainException('A timer is already running. Stop it first.'); }
      $now = $at ?? date('Y-m-d H:i:s');
      $id = DB::insert('time_entries', ['user_id' => $uid, 'project_id' => $pid, 'task_id' => $tid, 'description' => mb_substr(trim((string) ($d['description'] ?? '')), 0, 500) ?: null, 'started_at' => $now, 'last_heartbeat_at' => $now,
        'source' => $source, 'agent_token_id' => $tokenId, 'billable' => array_key_exists('billable', $d) && empty($d['billable']) ? 0 : 1, 'is_running' => 1, 'status' => 'draft', 'created_at' => date('Y-m-d H:i:s')]);
      Audit::log('time.start', 'time_entry', $id, ['source' => $source, 'project_id' => $pid], $uid);
      return $id; }); }

  /** Change project/task without pausing: the running entry ends and the new one starts at the same second (no gap). Returns the new entry id. */
  public static function switchTo(array $user, array $d, string $source = 'desktop', ?int $tokenId = null, ?string $at = null): int {
    self::checkTarget($user, isset($d['project_id']) ? (int) $d['project_id'] : null, isset($d['task_id']) ? (int) $d['task_id'] : null);
    $uid = (int) $user['id'];
    return DB::transaction(static function () use ($user, $uid, $d, $source, $tokenId, $at): int {
      AttendanceService::lock($uid);
      $cur = DB::first('SELECT id, project_id, task_id, billable, started_at FROM time_entries WHERE user_id=? AND is_running=1 LIMIT 1', [$uid]);
      if (!$cur) { throw new \DomainException('Start tracking before switching project.'); }
      $now = max($at ?? date('Y-m-d H:i:s'), $cur['started_at']);
      self::stop($uid, $now, 'switch');
      $id = self::start($user, $d + ['billable' => (int) $cur['billable']], $source, $tokenId, $now);
      Audit::log('time.switch', 'time_entry', $id, ['from' => (int) $cur['id'], 'project_id' => $d['project_id'] ?? null], $uid);
      return $id; }); }

  /** Stop the user's running entry at $at (default now). The entry is approved automatically unless its week is rejected. Returns the stopped entry or null. */
  public static function stop(int $uid, ?string $at = null, string $reason = 'user', bool $atHeartbeat = false): ?array {
    return DB::transaction(static function () use ($uid, $at, $reason, $atHeartbeat): ?array {
      AttendanceService::lock($uid);
      $e = DB::first('SELECT * FROM time_entries WHERE user_id=? AND is_running=1 ORDER BY id DESC LIMIT 1', [$uid]);
      if (!$e) { return null; }
      $start = strtotime($e['started_at']);
      $end = $at ? strtotime($at) : ($atHeartbeat && $e['last_heartbeat_at'] ? strtotime($e['last_heartbeat_at']) : time());
      $end = max($start, $end);
      $dur = max(0, $end - $start - (int) $e['discarded_seconds']);
      $status = Timesheets::entryStatus($uid, $e['started_at']);
      DB::update('time_entries', ['ended_at' => date('Y-m-d H:i:s', $end), 'duration_seconds' => $dur, 'idle_seconds' => min((int) $e['idle_seconds'], $end - $start), 'is_running' => 0, 'status' => $status], 'id=?', [$e['id']]);
      Timesheets::sync($uid, self::weekStart(substr($e['started_at'], 0, 10)));
      Audit::log('time.stop', 'time_entry', (int) $e['id'], ['reason' => $reason, 'duration' => $dur, 'status' => $status], $uid);
      return ['id' => (int) $e['id'], 'duration' => $dur]; }); }

  /** Accumulate activity from a heartbeat. Idle is capped to the time since the last beat (+ the idle threshold, which clients report retroactively) and to the elapsed time. */
  public static function heartbeat(int $uid, int $active, int $idle): ?array {
    return DB::transaction(static function () use ($uid, $active, $idle): ?array {
      $e = DB::first('SELECT * FROM time_entries WHERE user_id=? AND is_running=1 ORDER BY id DESC LIMIT 1 FOR UPDATE', [$uid]);
      if (!$e) { return null; }
      $now = time();
      $since = max(0, $now - strtotime($e['last_heartbeat_at'] ?: $e['started_at'])) + 5 + max(1, (int) \Core\Settings::get('attendance.idle_minutes', 5)) * 60;
      $idle = min(max(0, $idle), $since);
      $elapsed = max(0, $now - strtotime($e['started_at']));
      $total = min((int) $e['idle_seconds'] + $idle, $elapsed);
      DB::update('time_entries', ['idle_seconds' => $total, 'last_heartbeat_at' => date('Y-m-d H:i:s', $now)], 'id=?', [$e['id']]);
      $e['idle_seconds'] = $total;
      return ['id' => (int) $e['id'], 'elapsed' => self::elapsed($e, $now), 'idle' => $total, 'discarded' => (int) $e['discarded_seconds'], 'activity' => max(0, min(100, (int) round(100 * max(0, $active) / max(1, $active + $idle))))]; }); }

  /** Remove recorded idle time from the running entry (user chose "discard"). */
  public static function discardIdle(int $uid, int $seconds): ?array {
    return DB::transaction(static function () use ($uid, $seconds): ?array {
      $e = DB::first('SELECT * FROM time_entries WHERE user_id=? AND is_running=1 ORDER BY id DESC LIMIT 1 FOR UPDATE', [$uid]);
      if (!$e) { return null; }
      $sec = max(0, min($seconds, (int) $e['idle_seconds'] - (int) $e['discarded_seconds']));
      if ($sec) {
        DB::update('time_entries', ['discarded_seconds' => (int) $e['discarded_seconds'] + $sec], 'id=?', [$e['id']]);
        Audit::log('time.idle_discard', 'time_entry', (int) $e['id'], ['seconds' => $sec], $uid); }
      $e['discarded_seconds'] = (int) $e['discarded_seconds'] + $sec;
      return ['discarded' => $sec, 'total_discarded' => $e['discarded_seconds'], 'elapsed' => self::elapsed($e)]; }); }

  /** Cron: stop timers without a heartbeat for more than 10 minutes, at their last heartbeat. */
  public static function staleTimers(): string {
    $n = 0;
    foreach (DB::column('SELECT user_id FROM time_entries WHERE is_running=1 AND COALESCE(last_heartbeat_at, started_at) < ?', [date('Y-m-d H:i:s', time() - self::STALE_SECONDS)]) as $uid) {
      if (self::stop((int) $uid, null, 'stale', true)) { $n++; } }
    return "stopped $n"; }

  /** Is the entry editable by its owner? (open week, or a rejected week being fixed; never in a finalized payroll month) */
  public static function editable(array $e): bool { return Timesheets::entryEditable($e); }

  /** Validate & normalise manual entry input. Returns row data. */
  public static function manualData(array $user, array $d, ?int $ignoreId = null): array {
    [$pid, $tid] = self::checkTarget($user, $d['project_id'] ? (int) $d['project_id'] : null, $d['task_id'] ? (int) $d['task_id'] : null);
    $start = strtotime($d['date'].' '.$d['start_time']);
    if ($d['end_time']) { $end = strtotime($d['date'].' '.$d['end_time']); if ($end <= $start) { $end += 86400; } }
    elseif ($d['duration']) {
      if (!preg_match('/^(\d{1,2})(?::([0-5]\d))?$|^(\d+(?:\.\d+)?)h?$/', (string) $d['duration'], $m)) { throw new \DomainException('Duration must look like 1:30 or 1.5.'); }
      $sec = isset($m[3]) && $m[3] !== '' ? (int) round((float) $m[3] * 3600) : (int) $m[1] * 3600 + (int) ($m[2] ?? 0) * 60;
      $end = $start + $sec; }
    else { throw new \DomainException('Enter an end time or a duration.'); }
    if ($end - $start < 60) { throw new \DomainException('An entry must be at least one minute long.'); }
    if ($end - $start > 86400) { throw new \DomainException('An entry cannot be longer than 24 hours.'); }
    if ($end > time() + 300) { throw new \DomainException('You cannot log time in the future.'); }
    $s = date('Y-m-d H:i:s', $start); $e = date('Y-m-d H:i:s', $end);
    $ov = DB::value('SELECT id FROM time_entries WHERE user_id=? AND id<>? AND started_at<? AND COALESCE(ended_at, NOW())>? AND started_at>? LIMIT 1', [$user['id'], $ignoreId ?? 0, $e, $s, date('Y-m-d H:i:s', $start - 86400 * 2)]);
    if ($ov) { throw new \DomainException('This overlaps another time entry.'); }
    return ['project_id' => $pid, 'task_id' => $tid, 'description' => $d['description'] ?: null, 'started_at' => $s, 'ended_at' => $e, 'duration_seconds' => $end - $start, 'billable' => (int) $d['billable']]; }

  /** Week entries for a user, grouped by day. */
  public static function weekEntries(int $uid, string $ws): array {
    $rows = DB::all('SELECT te.*, p.name AS project_name, p.color AS project_color, t.title AS task_title FROM time_entries te LEFT JOIN projects p ON p.id=te.project_id LEFT JOIN tasks t ON t.id=te.task_id WHERE te.user_id=? AND te.started_at>=? AND te.started_at<? ORDER BY te.started_at DESC', [$uid, $ws.' 00:00:00', date('Y-m-d', strtotime($ws.' +7 days')).' 00:00:00']);
    $days = [];
    foreach ($rows as $r) { $days[substr($r['started_at'], 0, 10)][] = $r; }
    return $days; } }
