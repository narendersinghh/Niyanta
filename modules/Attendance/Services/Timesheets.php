<?php
namespace Modules\Attendance\Services;
use Core\{DB, Gate};

/**
 * Week totals and edit windows. Entries count as approved as soon as they are stopped or saved; reviewers reject single entries
 * or whole days (EntryReview), never weeks. The timesheets row is only a cache of the week totals (sync). Finalized payroll months are frozen.
 */
final class Timesheets {
  private static array $locked = [];
  private static ?bool $payroll = null;

  /** [user_id, from, to) for a week. */
  public static function range(int $uid, string $ws): array { return [$uid, $ws.' 00:00:00', date('Y-m-d', strtotime($ws.' +7 days')).' 00:00:00']; }

  public static function find(int $uid, string $ws): ?array {
    return DB::first('SELECT t.*, r.name AS reviewer FROM timesheets t LEFT JOIN users r ON r.id=t.reviewed_by WHERE t.user_id=? AND t.week_start=? LIMIT 1', [$uid, $ws]); }

  /** Recompute the week totals and upsert its row (a cache, always 'approved'). Stopped entries that are not rejected count as approved. Returns the timesheet id, or null for an empty week. */
  public static function sync(int $uid, string $ws): ?int {
    $r = self::range($uid, $ws);
    $tot = DB::first('SELECT COUNT(*) AS n, COALESCE(SUM(duration_seconds),0) AS total, COALESCE(SUM(CASE WHEN billable=1 THEN duration_seconds ELSE 0 END),0) AS billable FROM time_entries WHERE user_id=? AND started_at>=? AND started_at<? AND is_running=0', $r);
    if (!(int) $tot['n']) { DB::query('DELETE FROM timesheets WHERE user_id=? AND week_start=?', [$uid, $ws]); return null; }
    DB::query("INSERT INTO timesheets (user_id, week_start, total_seconds, billable_seconds, status, submitted_at) VALUES (?,?,?,?,'approved',NOW()) ON DUPLICATE KEY UPDATE total_seconds=VALUES(total_seconds), billable_seconds=VALUES(billable_seconds), status='approved'", [$uid, $ws, (int) $tot['total'], (int) $tot['billable']]);
    $id = (int) DB::value('SELECT id FROM timesheets WHERE user_id=? AND week_start=?', [$uid, $ws]);
    DB::query('UPDATE time_entries SET timesheet_id=? WHERE user_id=? AND started_at>=? AND started_at<? AND (timesheet_id IS NULL OR timesheet_id<>?)', [$id, ...$r, $id]);
    DB::query("UPDATE time_entries SET status='approved' WHERE user_id=? AND started_at>=? AND started_at<? AND is_running=0 AND status IN ('draft','submitted')", $r);
    return $id; }

  /** Is the payroll_runs table available (Payroll installed)? */
  public static function hasPayroll(): bool {
    if (self::$payroll === null) { try { DB::value('SELECT 1 FROM payroll_runs LIMIT 1'); self::$payroll = true; } catch (\Throwable $e) { self::$payroll = false; } }
    return self::$payroll; }

  /** True when the month of $date is in a finalized or paid payroll run. */
  public static function payrollLocked(string $date): bool {
    if (!self::hasPayroll()) { return false; }
    $m = substr($date, 0, 7).'-01';
    return self::$locked[$m] ??= (bool) DB::value("SELECT 1 FROM payroll_runs WHERE period_month=? AND status IN ('finalized','paid') LIMIT 1", [$m]); }

  /** A week is frozen when any of its days falls in a finalized payroll month. */
  public static function weekLocked(string $ws): bool { return self::payrollLocked($ws) || self::payrollLocked(date('Y-m-d', strtotime($ws.' +6 days'))); }

  public static function flush(): void { self::$locked = []; self::$payroll = null; }

  /**
   * May the owner add or change time on $date? Current and previous week are open, a rejected entry ($rejected) stays open for its fix,
   * a finalized payroll month is closed. Without Payroll the window is the last 14 days (plus rejected entries).
   */
  public static function canEditDate(string $date, bool $rejected = false): bool {
    if (!self::hasPayroll()) { return $rejected || $date >= date('Y-m-d', strtotime('-14 days')); }
    if (self::payrollLocked($date)) { return false; }
    return $rejected || TimeService::weekStart($date) >= TimeService::weekStart(date('Y-m-d', strtotime('-7 days'))); }

  /** Is the entry editable by its owner? */
  public static function entryEditable(array $e): bool { return !$e['is_running'] && self::canEditDate(substr($e['started_at'], 0, 10), $e['status'] === 'rejected'); }

  /** May $u log and fix time for someone else ($uid): HR with attendance.manage, in scope, never self. */
  public static function canManage(array $u, int $uid): bool { return $uid !== (int) $u['id'] && Gate::allows('attendance.manage', $u) && Team::includes($u, $uid); }

  /** May $u change this entry: the owner while its week is open (or while it is rejected), HR (canManage) any stopped entry outside a finalized payroll month. */
  public static function entryChangeable(array $u, array $e): bool {
    if ((int) $e['user_id'] === (int) $u['id']) { return self::entryEditable($e); }
    return !$e['is_running'] && !self::payrollLocked(substr($e['started_at'], 0, 10)) && self::canManage($u, (int) $e['user_id']); }

  /** Can $u review the time entries of $targetId (approver permission, in scope, never self)? */
  public static function canReview(array $u, int $targetId): bool {
    return $targetId !== (int) $u['id'] && Gate::allows('attendance.timesheets.approve', $u) && Team::includes($u, $targetId); }

  /** Week grid: rows per project, task and billable flag with per-day seconds (rej: rejected seconds per day); daily totals; rejected time (counted in the totals, not approved); attendance; expected hours. */
  public static function summary(int $uid, string $ws): array {
    $rows = DB::all('SELECT DATE(te.started_at) AS d, te.project_id, p.name AS project_name, p.color, te.task_id, t.title AS task_title, te.billable, te.source=\'manual\' AS manual, SUM('.TimeService::secondsSql('te.').') AS secs, SUM(CASE WHEN te.status=\'rejected\' AND te.is_running=0 THEN te.duration_seconds ELSE 0 END) AS rej, MAX(te.is_running) AS running FROM time_entries te LEFT JOIN projects p ON p.id=te.project_id LEFT JOIN tasks t ON t.id=te.task_id WHERE te.user_id=? AND te.started_at>=? AND te.started_at<? GROUP BY d, te.project_id, p.name, p.color, te.task_id, t.title, te.billable, manual', self::range($uid, $ws));
    $dates = array_map(static fn ($i) => date('Y-m-d', strtotime($ws." +$i days")), range(0, 6));
    $pj = feature_on('projects'); /* projects switched off: one row per billable flag, no project/task names */
    $s = ['days' => array_fill_keys($dates, 0), 'billable_days' => array_fill_keys($dates, 0), 'rejected_days' => array_fill_keys($dates, 0), 'rows' => [], 'total' => 0, 'billable' => 0, 'rejected' => 0, 'running' => false, 'added' => 0];
    foreach ($rows as $r) {
      $sec = (int) $r['secs'];
      $k = ($pj ? (int) $r['project_id'].'-'.(int) $r['task_id'] : 'all').'-'.(int) $r['billable'].'-'.(int) $r['manual'];
      $s['rows'][$k] ??= ['project' => $pj ? $r['project_name'] ?? 'No project' : 'Tracked time', 'task' => $pj ? $r['task_title'] : null, 'color' => $pj ? ($r['color'] ?: 'gray') : 'gray', 'billable' => (bool) $r['billable'], 'added' => (bool) $r['manual'], 'total' => 0, 'days' => [], 'rej' => []];
      $s['rows'][$k]['total'] += $sec;
      if ($rej = (int) $r['rej']) { $s['rows'][$k]['rej'][$r['d']] = ($s['rows'][$k]['rej'][$r['d']] ?? 0) + $rej; $s['rejected_days'][$r['d']] += $rej; $s['rejected'] += $rej; }
      $s['rows'][$k]['days'][$r['d']] = ($s['rows'][$k]['days'][$r['d']] ?? 0) + $sec;
      $s['days'][$r['d']] = ($s['days'][$r['d']] ?? 0) + $sec;
      $s['total'] += $sec;
      if ($r['billable']) { $s['billable'] += $sec; $s['billable_days'][$r['d']] += $sec; }
      if ($r['manual']) { $s['added'] += $sec; }
      if ($r['running']) { $s['running'] = true; } }
    uasort($s['rows'], static fn ($a, $b) => [$a['project'], (string) $a['task'], !$a['billable'], $a['added']] <=> [$b['project'], (string) $b['task'], !$b['billable'], $b['added']]);
    /* attendance records HR added, edited or imported beyond the tracked time: one non-billable row, counted in the totals */
    foreach (AttendanceService::addedTime($uid, $ws, end($dates)) as $d => $a) {
      $s['rows']['attendance'] ??= ['project' => 'Attendance record', 'task' => null, 'color' => 'gray', 'billable' => false, 'added' => true, 'record' => true, 'total' => 0, 'days' => []];
      $s['rows']['attendance']['total'] += $a['secs']; $s['rows']['attendance']['days'][$d] = $a['secs'];
      $s['days'][$d] += $a['secs']; $s['total'] += $a['secs']; $s['added'] += $a['secs']; }
    $s['attendance'] = DB::pairs('SELECT work_date, worked_seconds FROM attendance WHERE user_id=? AND work_date BETWEEN ? AND ?', [$uid, $ws, end($dates)]);
    // Expected hours: the employee's schedule (HR) on working days, minus fixed holidays and approved leave.
    $perDay = AttendanceService::workSeconds($uid);
    $off = AttendanceService::offDays($uid);
    $hol = AttendanceService::holidays($ws, end($dates));
    $leave = AttendanceService::leaveMap([$uid], $ws, end($dates))[$uid] ?? [];
    $s['off'] = []; $s['expected'] = 0; $s['workdays'] = 0;
    foreach ($dates as $d) {
      $w = AttendanceService::dayWeight($uid, $d);
      $isOff = $w <= 0 || isset($hol[$d]);
      $s['off'][$d] = $isOff ? ($hol[$d] ?? 'Off') : null;
      if ($isOff) { continue; }
      $f = max(0.0, $w - (float) ($leave[$d] ?? 0));
      $s['expected'] += (int) round($perDay * $f);
      $s['workdays'] += $f; }
    $s['per_day'] = $perDay;
    $s['days_worked'] = count(array_filter($s['days']));
    return $s; } }
