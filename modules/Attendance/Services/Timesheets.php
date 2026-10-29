<?php
namespace Modules\Attendance\Services;
use Core\{Audit, DB, Gate, Notify};

/**
 * Auto-approved weekly timesheets. Entries count as approved as soon as they are stopped or saved; the week row is
 * kept in sync automatically. An approver may reject a week (entries become 'rejected', excluded from payroll);
 * the employee fixes entries and resubmits, which approves the week again. Weeks touching a finalized payroll month are frozen.
 */
final class Timesheets {
  private static array $locked = [];
  private static ?bool $payroll = null;

  /** [user_id, from, to) for a week. */
  public static function range(int $uid, string $ws): array { return [$uid, $ws.' 00:00:00', date('Y-m-d', strtotime($ws.' +7 days')).' 00:00:00']; }

  public static function find(int $uid, string $ws): ?array {
    return DB::first('SELECT t.*, r.name AS reviewer FROM timesheets t LEFT JOIN users r ON r.id=t.reviewed_by WHERE t.user_id=? AND t.week_start=? LIMIT 1', [$uid, $ws]); }

  public static function weekRejected(int $uid, string $ws): bool { return DB::value('SELECT status FROM timesheets WHERE user_id=? AND week_start=?', [$uid, $ws]) === 'rejected'; }

  /** Status a stopped or saved entry takes: rejected while its week is rejected, otherwise approved. */
  public static function entryStatus(int $uid, string $startedAt): string { return self::weekRejected($uid, TimeService::weekStart(substr($startedAt, 0, 10))) ? 'rejected' : 'approved'; }

  /** Recompute the week totals and upsert its row (approved by default; a rejected week stays rejected). Returns the timesheet id, or null for an empty week. */
  public static function sync(int $uid, string $ws): ?int {
    $r = self::range($uid, $ws);
    $tot = DB::first('SELECT COUNT(*) AS n, COALESCE(SUM(duration_seconds),0) AS total, COALESCE(SUM(CASE WHEN billable=1 THEN duration_seconds ELSE 0 END),0) AS billable FROM time_entries WHERE user_id=? AND started_at>=? AND started_at<? AND is_running=0', $r);
    $cur = DB::first('SELECT id, status, reviewed_by FROM timesheets WHERE user_id=? AND week_start=?', [$uid, $ws]);
    if (!(int) $tot['n']) {
      if ($cur && $cur['status'] !== 'rejected' && !$cur['reviewed_by']) { DB::delete('timesheets', 'id=?', [$cur['id']]); return null; }
      if (!$cur) { return null; } }
    DB::query("INSERT INTO timesheets (user_id, week_start, total_seconds, billable_seconds, status, submitted_at) VALUES (?,?,?,?,'approved',NOW()) ON DUPLICATE KEY UPDATE total_seconds=VALUES(total_seconds), billable_seconds=VALUES(billable_seconds), status=IF(status IN ('draft','submitted'),'approved',status)", [$uid, $ws, (int) $tot['total'], (int) $tot['billable']]);
    $t = DB::first('SELECT id, status FROM timesheets WHERE user_id=? AND week_start=?', [$uid, $ws]);
    DB::query('UPDATE time_entries SET timesheet_id=? WHERE user_id=? AND started_at>=? AND started_at<? AND (timesheet_id IS NULL OR timesheet_id<>?)', [$t['id'], ...$r, $t['id']]);
    DB::query("UPDATE time_entries SET status=? WHERE user_id=? AND started_at>=? AND started_at<? AND is_running=0 AND status IN ('draft','submitted')", [$t['status'] === 'rejected' ? 'rejected' : 'approved', ...$r]);
    return (int) $t['id']; }

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
   * May the owner add or change time on $date? Current and previous week are open, a rejected week stays open for fixes,
   * a finalized payroll month is closed. Without Payroll the window is the last 14 days (plus rejected weeks).
   */
  public static function canEditDate(string $date, bool $rejected): bool {
    if (!self::hasPayroll()) { return $rejected || $date >= date('Y-m-d', strtotime('-14 days')); }
    if (self::payrollLocked($date)) { return false; }
    return $rejected || TimeService::weekStart($date) >= TimeService::weekStart(date('Y-m-d', strtotime('-7 days'))); }

  /** Is the entry editable by its owner? */
  public static function entryEditable(array $e): bool { return !$e['is_running'] && self::canEditDate(substr($e['started_at'], 0, 10), $e['status'] === 'rejected'); }

  /** Can $u review the timesheets of $targetId (approver permission, in scope, never self)? */
  public static function canReview(array $u, int $targetId): bool {
    return $targetId !== (int) $u['id'] && Gate::allows('attendance.timesheets.approve', $u) && Team::includes($u, $targetId); }

  /** Reject a week (note required). Entries of the week become 'rejected' and drop out of approved hours. */
  public static function reject(array $by, int $id, string $note): array {
    $note = trim($note);
    if ($note === '') { throw new \DomainException('Add a note so the employee knows what to fix.'); }
    $t = self::review($by, $id, 'rejected', $note);
    Notify::user((int) $t['user_id'], 'Timesheet rejected', 'Your timesheet for the week of '.fdate($t['week_start']).' was rejected by '.$by['name'].': '.$note.' Fix the entries and click Resubmit.', '/timesheets?week='.$t['week_start'], true, 'alert');
    return $t; }

  /** Approve a rejected week again (approver override, no resubmit needed). */
  public static function approve(array $by, int $id, ?string $note = null): array {
    $note = trim((string) $note);
    $t = self::review($by, $id, 'approved', $note);
    Notify::user((int) $t['user_id'], 'Timesheet approved', 'Your timesheet for the week of '.fdate($t['week_start']).' was approved by '.$by['name'].($note !== '' ? ': '.$note : '.'), '/timesheets?week='.$t['week_start'], false, 'check-circle');
    return $t; }

  private static function review(array $by, int $id, string $to, string $note): array {
    $t = DB::first('SELECT id, user_id, week_start, status FROM timesheets WHERE id=?', [$id]) ?? throw new \DomainException('Timesheet not found.');
    if (!self::canReview($by, (int) $t['user_id'])) { Audit::log('access.denied', 'timesheet', $id, ['action' => $to], (int) $by['id']); throw new \DomainException('You cannot review this timesheet.'); }
    if (self::weekLocked($t['week_start'])) { throw new \DomainException('This week is in a finalized payroll period and can no longer change.'); }
    DB::transaction(static function () use ($t, $id, $to, $note, $by): void {
      $cur = DB::value('SELECT status FROM timesheets WHERE id=? FOR UPDATE', [$id]);
      if ($to === 'rejected' && $cur === 'rejected') { throw new \DomainException('This week is already rejected.'); }
      if ($to === 'approved' && $cur !== 'rejected') { throw new \DomainException('This week is already approved.'); }
      DB::update('timesheets', ['status' => $to, 'reviewed_by' => (int) $by['id'], 'reviewed_at' => date('Y-m-d H:i:s'), 'review_note' => $note !== '' ? mb_substr($note, 0, 500) : null], 'id=?', [$id]);
      DB::query('UPDATE time_entries SET status=?, timesheet_id=? WHERE user_id=? AND started_at>=? AND started_at<? AND is_running=0', [$to, $id, ...self::range((int) $t['user_id'], $t['week_start'])]); });
    Audit::log('timesheet.'.($to === 'approved' ? 'approve' : 'reject'), 'timesheet', $id, ['user_id' => (int) $t['user_id'], 'week' => $t['week_start'], 'note' => $note], (int) $by['id']);
    return $t; }

  /** Owner fixed a rejected week: approve it again automatically. */
  public static function resubmit(array $user, string $ws): int {
    $uid = (int) $user['id'];
    if (self::weekLocked($ws)) { throw new \DomainException('This week is in a finalized payroll period. Ask HR to make changes.'); }
    $t = DB::transaction(static function () use ($uid, $ws): array {
      AttendanceService::lock($uid);
      $t = DB::first('SELECT id, status, reviewed_by FROM timesheets WHERE user_id=? AND week_start=? FOR UPDATE', [$uid, $ws]);
      if (!$t || $t['status'] !== 'rejected') { throw new \DomainException('Only a rejected week needs to be resubmitted.'); }
      DB::update('timesheets', ['status' => 'approved', 'submitted_at' => date('Y-m-d H:i:s'), 'reviewed_by' => null, 'reviewed_at' => null, 'review_note' => null], 'id=?', [$t['id']]);
      DB::query("UPDATE time_entries SET status='approved' WHERE user_id=? AND started_at>=? AND started_at<? AND is_running=0", self::range($uid, $ws));
      self::sync($uid, $ws);
      return $t; });
    Audit::log('timesheet.resubmit', 'timesheet', (int) $t['id'], ['week' => $ws, 'rejected_by' => $t['reviewed_by']], $uid);
    if ($t['reviewed_by'] && (int) $t['reviewed_by'] !== $uid) { Notify::user((int) $t['reviewed_by'], $user['name'].' resubmitted a timesheet', 'Week of '.fdate($ws).' was corrected and is approved again. You can reject it again if something is still wrong.', '/timesheets/'.$t['id'], false, 'calendar'); }
    return (int) $t['id']; }

  /** Week grid: rows per project, task and billable flag with per-day seconds; daily totals; attendance; expected hours. */
  public static function summary(int $uid, string $ws): array {
    $rows = DB::all('SELECT DATE(te.started_at) AS d, te.project_id, p.name AS project_name, p.color, te.task_id, t.title AS task_title, te.billable, SUM(CASE WHEN te.is_running=1 THEN GREATEST(0, TIMESTAMPDIFF(SECOND, te.started_at, NOW()) - te.discarded_seconds) ELSE te.duration_seconds END) AS secs, MAX(te.is_running) AS running FROM time_entries te LEFT JOIN projects p ON p.id=te.project_id LEFT JOIN tasks t ON t.id=te.task_id WHERE te.user_id=? AND te.started_at>=? AND te.started_at<? GROUP BY d, te.project_id, p.name, p.color, te.task_id, t.title, te.billable', self::range($uid, $ws));
    $dates = array_map(static fn ($i) => date('Y-m-d', strtotime($ws." +$i days")), range(0, 6));
    $s = ['days' => array_fill_keys($dates, 0), 'billable_days' => array_fill_keys($dates, 0), 'rows' => [], 'total' => 0, 'billable' => 0, 'running' => false];
    foreach ($rows as $r) {
      $sec = (int) $r['secs'];
      $k = (int) $r['project_id'].'-'.(int) $r['task_id'].'-'.(int) $r['billable'];
      $s['rows'][$k] ??= ['project' => $r['project_name'] ?? 'No project', 'task' => $r['task_title'], 'color' => $r['color'] ?: 'gray', 'billable' => (bool) $r['billable'], 'total' => 0, 'days' => []];
      $s['rows'][$k]['total'] += $sec;
      $s['rows'][$k]['days'][$r['d']] = ($s['rows'][$k]['days'][$r['d']] ?? 0) + $sec;
      $s['days'][$r['d']] = ($s['days'][$r['d']] ?? 0) + $sec;
      $s['total'] += $sec;
      if ($r['billable']) { $s['billable'] += $sec; $s['billable_days'][$r['d']] += $sec; }
      if ($r['running']) { $s['running'] = true; } }
    uasort($s['rows'], static fn ($a, $b) => [$a['project'], (string) $a['task'], !$a['billable']] <=> [$b['project'], (string) $b['task'], !$b['billable']]);
    $s['attendance'] = DB::pairs('SELECT work_date, worked_seconds FROM attendance WHERE user_id=? AND work_date BETWEEN ? AND ?', [$uid, $ws, end($dates)]);
    // Expected hours: the employee's schedule (HR) on working days, minus fixed holidays and approved leave.
    $perDay = AttendanceService::workSeconds($uid);
    $off = AttendanceService::offDays($uid);
    $hol = AttendanceService::holidays($ws, end($dates));
    $leave = AttendanceService::leaveMap([$uid], $ws, end($dates))[$uid] ?? [];
    $s['off'] = []; $s['expected'] = 0; $s['workdays'] = 0;
    foreach ($dates as $d) {
      $isOff = in_array((int) date('N', strtotime($d)), $off, true) || isset($hol[$d]);
      $s['off'][$d] = $isOff ? ($hol[$d] ?? 'Off') : null;
      if ($isOff) { continue; }
      $f = max(0.0, 1 - (float) ($leave[$d] ?? 0));
      $s['expected'] += (int) round($perDay * $f);
      $s['workdays'] += $f; }
    $s['per_day'] = $perDay;
    $s['days_worked'] = count(array_filter($s['days']));
    return $s; } }
