<?php
namespace Modules\Attendance\Services;
use Core\{Audit, DB, Gate, Modules};

/** Timer, heartbeat/idle, manual entries and reporting helpers (used by Payroll). */
final class TimeService {
  public const STALE_SECONDS = 600;
  private static array $vis = [];

  /**
   * Is this running entry stale at $ref (default now): no heartbeat for more than STALE_SECONDS, so its app is gone (quit,
   * crashed, asleep, offline, signed out)? A stale entry counts only up to its last heartbeat and is stopped there. Exception:
   * an entry the server created in the last STALE_SECONDS from a queued, older start that has had no heartbeat yet (the app
   * is replaying its offline queue; the heartbeats that cover the gap follow right after it).
   */
  public static function stale(array $e, ?int $ref = null): bool {
    if (empty($e['is_running'])) { return false; }
    $now = time(); $hb = strtotime((string) ($e['last_heartbeat_at'] ?: $e['started_at']));
    if (($ref ?? $now) - $hb <= self::STALE_SECONDS) { return false; }
    return !($e['last_heartbeat_at'] === $e['started_at'] && !empty($e['created_at']) && strtotime((string) $e['created_at']) >= $now - self::STALE_SECONDS); }

  /**
   * SQL for the seconds an entry counts (column prefix $t, e.g. 'te.'): finished entries their duration, running entries up to
   * now, or only up to their last heartbeat once stale (see stale()), so no total keeps growing for an app that is gone.
   */
  public static function secondsSql(string $t = ''): string {
    $hb = "COALESCE({$t}last_heartbeat_at, {$t}started_at)"; $s = self::STALE_SECONDS;
    return "CASE WHEN {$t}is_running=1 THEN GREATEST(0, TIMESTAMPDIFF(SECOND, {$t}started_at, IF($hb < NOW() - INTERVAL $s SECOND AND NOT ({$t}last_heartbeat_at <=> {$t}started_at AND {$t}created_at >= NOW() - INTERVAL $s SECOND), $hb, NOW())) - {$t}discarded_seconds) ELSE {$t}duration_seconds END"; }

  /** Seconds tracked by a user between two dates (inclusive), optionally by entry status. Running entries count up to now. */
  public static function secondsForUser(int $userId, string $from, string $to, ?string $status = null): int {
    $sql = 'SELECT COALESCE(SUM('.self::secondsSql().'),0) FROM time_entries WHERE user_id=? AND started_at>=? AND started_at<?';
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
    $sec = DB::pairs('SELECT user_id, COALESCE(SUM('.self::secondsSql().'),0) FROM time_entries WHERE status=\'approved\' AND started_at>=? AND started_at<? AND user_id IN '.DB::in($userIds).' GROUP BY user_id', [$from.' 00:00:00', date('Y-m-d', strtotime($to.' +1 day')).' 00:00:00', ...$userIds]);
    foreach ($sec as $u => $s) { $out[(int) $u] = round((float) $s / 3600, 2); }
    return $out; }

  /** Approved hours per user and day for payroll caps (flexible hours by day or week): [uid => [Y-m-d => hours]] in one query. */
  public static function approvedHoursByDay(array $userIds, string $from, string $to): array {
    $userIds = array_values(array_unique(array_map('intval', $userIds)));
    if (!$userIds) { return []; }
    $out = array_fill_keys($userIds, []);
    $rows = DB::all("SELECT user_id, DATE(started_at) AS d, COALESCE(SUM(duration_seconds),0) AS s FROM time_entries WHERE status='approved' AND is_running=0 AND started_at>=? AND started_at<? AND user_id IN ".DB::in($userIds).' GROUP BY user_id, DATE(started_at)', [$from.' 00:00:00', date('Y-m-d', strtotime($to.' +1 day')).' 00:00:00', ...$userIds]);
    foreach ($rows as $r) { $out[(int) $r['user_id']][$r['d']] = round((float) $r['s'] / 3600, 4); }
    return $out; }

  /** Present days from attendance (half day = 0.5). */
  public static function presentDays(int $userId, string $from, string $to): float {
    return (float) DB::value("SELECT COALESCE(SUM(CASE status WHEN 'present' THEN 1 WHEN 'half_day' THEN 0.5 ELSE 0 END),0) FROM attendance WHERE user_id=? AND work_date BETWEEN ? AND ?", [$userId, $from, $to]); }

  public static function weekStart(?string $date = null): string { $t = strtotime($date ?: 'today') ?: time(); return date('Y-m-d', strtotime('-'.(date('N', $t) - 1).' days', $t)); }

  /**
   * The user's running entry. A stale one (its app is gone) is stopped at its last heartbeat first (Tracker::settle), so the
   * web pages and the top bar never show it as running; $settle = false inside the tracker's own actions (settled already).
   */
  public static function running(int $uid, bool $settle = true): ?array {
    $sql = 'SELECT te.*, p.name AS project_name, p.color AS project_color, t.title AS task_title FROM time_entries te LEFT JOIN projects p ON p.id=te.project_id LEFT JOIN tasks t ON t.id=te.task_id WHERE te.user_id=? AND te.is_running=1 ORDER BY te.id DESC LIMIT 1';
    $r = DB::first($sql, [$uid]);
    if ($r && $settle && self::stale($r)) { Tracker::settle($uid); $r = DB::first($sql, [$uid]); }
    return $r; }

  /** Elapsed seconds of an entry (running: up to now, a stale one up to its last heartbeat) minus discarded idle time. */
  public static function elapsed(array $e, ?int $now = null): int {
    if (!$e['is_running']) { return (int) $e['duration_seconds']; }
    $end = self::stale($e, $now) ? strtotime((string) ($e['last_heartbeat_at'] ?: $e['started_at'])) : ($now ?? time());
    return max(0, $end - strtotime($e['started_at']) - (int) $e['discarded_seconds']); }

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

  /**
   * Accumulate activity from a heartbeat sent at $at (the app's time of the beat, clamped by Tracker::at(); null = now). The beat
   * covers the $active + $idle seconds before it: when that period starts more than STALE_SECONDS after the last heartbeat, the
   * app was not running in between (switched off, killed, asleep), so the entry is stopped at its last heartbeat instead of
   * being stretched over the gap. Otherwise the entry's last heartbeat moves to $at (crossing midnight starts the new day, see
   * Tracker::settle()). Idle is capped to the time since the last beat (+ the idle threshold, reported retroactively) and to
   * the elapsed time.
   */
  public static function heartbeat(int $uid, int $active, int $idle, ?string $at = null): ?array {
    return DB::transaction(static function () use ($uid, $active, $idle, $at): ?array {
      AttendanceService::lock($uid);
      $e = DB::first('SELECT * FROM time_entries WHERE user_id=? AND is_running=1 ORDER BY id DESC LIMIT 1', [$uid]);
      if (!$e) { Tracker::settle($uid); return null; }
      $hb = strtotime($e['last_heartbeat_at'] ?: $e['started_at']);
      $end = max($hb, $at ? (int) strtotime($at) : time());
      if ($end - max(0, $active) - max(0, $idle) - $hb > self::STALE_SECONDS) { self::stop($uid, null, 'stale', true); Tracker::settle($uid); return null; }
      Tracker::settle($uid, date('Y-m-d H:i:s', $end), $end - max(0, $active) - max(0, $idle));
      $e = DB::first('SELECT * FROM time_entries WHERE user_id=? AND is_running=1 ORDER BY id DESC LIMIT 1', [$uid]);
      if (!$e) { return null; }
      $last = strtotime($e['last_heartbeat_at'] ?: $e['started_at']);
      $since = max(0, $end - $last) + 5 + max(1, (int) \Core\Settings::get('attendance.idle_minutes', 5)) * 60;
      $idle = min(max(0, $idle), $since);
      $elapsed = max(0, $end - strtotime($e['started_at']));
      $total = min((int) $e['idle_seconds'] + $idle, $elapsed);
      DB::update('time_entries', ['idle_seconds' => $total, 'last_heartbeat_at' => date('Y-m-d H:i:s', max($end, $last))], 'id=?', [$e['id']]);
      $e['idle_seconds'] = $total;
      return ['id' => (int) $e['id'], 'elapsed' => self::elapsed($e), 'idle' => $total, 'discarded' => (int) $e['discarded_seconds'], 'activity' => max(0, min(100, (int) round(100 * max(0, $active) / max(1, $active + $idle))))]; }); }

  /**
   * Idle spans [{from, to}] (ISO 8601, the app's times; at most 24 h each, within the last 7 days) saved as idle time: each
   * span is recorded per overlapping entry in time_idle_spans with $action ('auto' from the app, 'keep' for the
   * legacy keep/discard answers of older apps). The seconds themselves reach time_entries.idle_seconds through heartbeats;
   * nothing is ever removed from tracked time. Spans already recorded for the same entry and times are skipped (retries).
   */
  public static function idleSpans(int $uid, array $spans, string $action = 'auto'): array {
    $action = $action === 'keep' ? 'keep' : 'auto';
    return DB::transaction(static function () use ($uid, $spans, $action): array {
      AttendanceService::lock($uid);
      $now = time(); $kept = 0;
      foreach (array_slice($spans, 0, 50) as $sp) {
        $f = is_array($sp) ? strtotime((string) ($sp['from'] ?? '')) : false; $t = is_array($sp) ? strtotime((string) ($sp['to'] ?? '')) : false;
        if (!$f || !$t) { continue; }
        $t = min($t, $now);
        if ($t <= $f || $f < $now - 7 * 86400 || $t - $f > 86400) { continue; }
        $rows = DB::all('SELECT id, started_at, ended_at, is_running FROM time_entries WHERE user_id=? AND started_at<? AND started_at>? AND (is_running=1 OR ended_at>?) ORDER BY started_at', [$uid, date('Y-m-d H:i:s', $t), date('Y-m-d H:i:s', $f - 2 * 86400), date('Y-m-d H:i:s', $f)]);
        foreach ($rows as $e) {
          $s = max($f, strtotime($e['started_at'])); $en = min($t, $e['is_running'] ? $now : strtotime($e['ended_at']));
          if ($en <= $s) { continue; }
          $from = date('Y-m-d H:i:s', $s); $to = date('Y-m-d H:i:s', $en);
          if (DB::value('SELECT 1 FROM time_idle_spans WHERE time_entry_id=? AND started_at=? AND ended_at=? LIMIT 1', [$e['id'], $from, $to])) { continue; }
          DB::insert('time_idle_spans', ['user_id' => $uid, 'time_entry_id' => (int) $e['id'], 'started_at' => $from, 'ended_at' => $to, 'seconds' => $en - $s, 'action' => $action, 'created_at' => date('Y-m-d H:i:s')]);
          $kept += $en - $s; } }
      if ($kept) { Audit::log('time.idle_'.$action, 'user', $uid, ['seconds' => $kept, 'source' => 'desktop'], $uid); }
      return ['discarded' => 0, 'kept' => $kept]; }); }

  /**
   * Activity slots from the desktop app (batch, idempotent per slot_start): [{slot_start (ISO 8601), slot_seconds (tracked
   * seconds in the slot, 1 to 3600), active_seconds (seconds with keyboard or mouse input, clamped to slot_seconds),
   * meeting? (1 = meeting mode was on for most of the slot: stored, never averaged, see dayActivity())}]. Keystroke and mouse
   * counts are no longer collected: counts an old app still sends are ignored (the columns stay NULL, older rows keep theirs). Each slot is tied to the entry that overlaps it most (null when none). A
   * repeated slot_start replaces the stored slot only when it covers at least as many seconds (retries and partial reports
   * after a restart never shrink a slot). Slots older than 7 days or in the future are skipped. Returns saved/skipped counts.
   */
  public static function activitySlots(int $uid, array $slots, ?int $tokenId = null): array {
    return DB::transaction(static function () use ($uid, $slots, $tokenId): array {
      AttendanceService::lock($uid);
      $now = time(); $out = ['saved' => 0, 'skipped' => 0];
      foreach (array_slice(array_values($slots), 0, 500) as $sl) {
        $t = is_array($sl) && is_string($sl['slot_start'] ?? null) ? strtotime($sl['slot_start']) : false;
        $sec = is_array($sl) && is_numeric($sl['slot_seconds'] ?? null) ? (int) $sl['slot_seconds'] : 0;
        $act = is_array($sl) && is_numeric($sl['active_seconds'] ?? null) ? (int) $sl['active_seconds'] : -1;
        if (!$t || $t > $now + 60 || $t < $now - 7 * 86400 || $sec < 1 || $sec > 3600 || $act < 0) { $out['skipped']++; continue; }
        $start = date('Y-m-d H:i:s', $t); $end = date('Y-m-d H:i:s', $t + $sec);
        $old = DB::first('SELECT id, slot_seconds FROM activity_slots WHERE user_id=? AND slot_start=?', [$uid, $start]);
        if ($old && (int) $old['slot_seconds'] > $sec) { $out['skipped']++; continue; }
        $entry = DB::value('SELECT id FROM time_entries WHERE user_id=? AND started_at<? AND (is_running=1 OR ended_at>?) AND started_at>? ORDER BY TIMESTAMPDIFF(SECOND, GREATEST(started_at, ?), LEAST(COALESCE(ended_at, NOW()), ?)) DESC, id DESC LIMIT 1', [$uid, $end, $start, date('Y-m-d H:i:s', $t - 2 * 86400), $start, $end]);
        $row = ['time_entry_id' => $entry ? (int) $entry : null, 'slot_seconds' => $sec, 'active_seconds' => min($sec, $act), 'keystrokes' => null, 'mouse_events' => null, 'meeting' => filter_var($sl['meeting'] ?? false, FILTER_VALIDATE_BOOLEAN) ? 1 : 0, 'agent_token_id' => $tokenId];
        if ($old) { DB::update('activity_slots', $row, 'id=?', [$old['id']]); }
        else { DB::insert('activity_slots', $row + ['user_id' => $uid, 'slot_start' => $start, 'created_at' => date('Y-m-d H:i:s')]); }
        $out['saved']++; }
      return $out; }); }

  /**
   * Activity of one day from the desktop app's slots, meeting slots excluded: pct (active / tracked seconds of the slots
   * without meeting mode; null when there are none, then the average of the screenshots outside meetings, else null),
   * seconds (slot seconds averaged) and meeting (tracked seconds in meeting mode). Every activity average uses this.
   */
  public static function dayActivity(int $uid, string $date): array {
    $range = [$uid, $date.' 00:00:00', date('Y-m-d', strtotime($date.' +1 day')).' 00:00:00'];
    $r = DB::first('SELECT COALESCE(SUM(CASE WHEN meeting=0 THEN slot_seconds END),0) AS s, COALESCE(SUM(CASE WHEN meeting=0 THEN active_seconds END),0) AS a, COALESCE(SUM(CASE WHEN meeting=1 THEN slot_seconds END),0) AS m FROM activity_slots WHERE user_id=? AND slot_start>=? AND slot_start<?', $range);
    $pct = (int) $r['s'] ? 100 * (int) $r['a'] / (int) $r['s'] : DB::value('SELECT AVG(activity_pct) FROM screenshots WHERE user_id=? AND taken_at>=? AND taken_at<? AND activity_pct IS NOT NULL AND meeting=0', $range);
    return ['pct' => $pct !== null ? (int) round((float) $pct) : null, 'seconds' => (int) $r['s'], 'meeting' => (int) $r['m']]; }

  /**
   * Cron (every 5 min): stop timers without a heartbeat for more than 10 minutes at their last heartbeat (never at the time the
   * job runs), then settle people with a day before today still open or an entry still running from an earlier day (closes
   * those days and splits entries at midnight, see Tracker::settle()).
   */
  public static function staleTimers(): string {
    $n = 0;
    foreach (DB::all('SELECT * FROM time_entries WHERE is_running=1 AND COALESCE(last_heartbeat_at, started_at) < ?', [date('Y-m-d H:i:s', time() - self::STALE_SECONDS)]) as $e) {
      if (self::stale($e) && self::stop((int) $e['user_id'], null, 'stale', true)) { $n++; } }
    $today = date('Y-m-d');
    $users = DB::column('SELECT user_id FROM attendance WHERE clock_out IS NULL AND clock_in IS NOT NULL AND work_date<? UNION SELECT user_id FROM time_entries WHERE is_running=1 AND started_at<?', [$today, $today.' 00:00:00']);
    foreach ($users as $uid) { Tracker::settle((int) $uid); }
    return "stopped $n, settled ".count($users); }

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
