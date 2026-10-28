<?php
namespace Modules\Hr\Services;
use Core\{DB, Settings};

/**
 * Leave & calendar helpers shared with other modules (Payroll, Attendance).
 * Working days exclude configured weekend days (setting general.weekend_days, ISO-N, default "6,7") and non-optional holidays.
 */
final class LeaveService {
  private static array $holidays = [];
  private static ?array $weekend = null;

  /** ISO-8601 weekday numbers (1=Mon … 7=Sun) treated as weekend. */
  public static function weekendDays(): array {
    return self::$weekend ??= array_values(array_unique(array_filter(array_map('intval', explode(',', (string) Settings::get('general.weekend_days', '6,7'))), static fn ($n) => $n >= 1 && $n <= 7))); }

  /** Non-optional holidays of a year as [Y-m-d => name] (cached per request). */
  public static function holidaysOf(int $year): array {
    return self::$holidays[$year] ??= DB::pairs('SELECT holiday_date, MIN(name) FROM holidays WHERE optional=0 AND holiday_date BETWEEN ? AND ? GROUP BY holiday_date', ["$year-01-01", "$year-12-31"]); }

  public static function flush(): void { self::$holidays = []; self::$weekend = null; }

  public static function isWeekend(string $date): bool { return in_array((int) date('N', strtotime($date)), self::weekendDays(), true); }

  /** True when the date is a (non-optional) public holiday. */
  public static function isHoliday(string $date): bool { $t = strtotime($date); return $t !== false && isset(self::holidaysOf((int) date('Y', $t))[date('Y-m-d', $t)]); }

  public static function isWorkingDay(string $date): bool { return !self::isWeekend($date) && !self::isHoliday($date); }

  /** Number of working days between two dates inclusive (weekends and non-optional holidays excluded). */
  public static function workingDays(string $from, string $to): float {
    $a = strtotime($from); $b = strtotime($to);
    if ($a === false || $b === false || $b < $a) { return 0.0; }
    $n = 0; $guard = 0;
    for ($t = $a; $t <= $b && $guard < 3700; $t = strtotime('+1 day', $t), $guard++) { if (self::isWorkingDay(date('Y-m-d', $t))) { $n++; } }
    return (float) $n; }

  /** Days a request consumes: working days, or 0.5 for a half day on a working day. */
  public static function requestDays(string $from, string $to, bool $half): float {
    if ($half) { return $from === $to && self::isWorkingDay($from) ? 0.5 : 0.0; }
    return self::workingDays($from, $to); }

  /** Approved leave days of a user inside [from, to]; multi-day requests crossing the range are prorated by working days. */
  public static function approvedLeaveDays(int $userId, string $from, string $to, ?bool $paid = null): float { return self::approvedLeaveDaysMany([$userId], $from, $to, $paid)[$userId] ?? 0.0; }

  /** Bulk variant for payroll runs: [user_id => days] in a single query. */
  public static function approvedLeaveDaysMany(array $userIds, string $from, string $to, ?bool $paid = null): array {
    $userIds = array_values(array_unique(array_map('intval', $userIds)));
    if (!$userIds) { return []; }
    $sql = 'SELECT lr.user_id, lr.start_date, lr.end_date, lr.days FROM leave_requests lr JOIN leave_types t ON t.id=lr.leave_type_id WHERE lr.user_id IN '.DB::in($userIds)." AND lr.status='approved' AND lr.start_date<=? AND lr.end_date>=?";
    $p = [...$userIds, $to, $from];
    if ($paid !== null) { $sql .= ' AND t.paid=?'; $p[] = $paid ? 1 : 0; }
    $out = array_fill_keys($userIds, 0.0);
    foreach (DB::all($sql, $p) as $r) { $out[(int) $r['user_id']] += self::portion($r, $from, $to); }
    return array_map(static fn ($v) => round($v, 2), $out); }

  /** Share of a request's days that falls within [from, to]. */
  public static function portion(array $r, string $from, string $to): float {
    $days = (float) $r['days'];
    if ($r['start_date'] >= $from && $r['end_date'] <= $to) { return $days; }
    $full = self::workingDays($r['start_date'], $r['end_date']);
    if ($full <= 0) { return 0.0; }
    $part = self::workingDays(max($r['start_date'], $from), min($r['end_date'], $to));
    return min($days, $days * $part / $full); }

  /** True when the user has approved leave covering the date. */
  public static function onLeave(int $userId, string $date): bool {
    return (bool) DB::value("SELECT 1 FROM leave_requests WHERE user_id=? AND status='approved' AND start_date<=? AND end_date>=? LIMIT 1", [$userId, $date, $date]); }

  /** Users on approved leave on a date with their leave type. */
  public static function onLeaveOn(string $date, int $limit = 200): array {
    return DB::all("SELECT u.id, u.name, u.avatar, u.designation, u.department, t.name AS type_name, t.color, lr.half_day, lr.end_date FROM leave_requests lr JOIN users u ON u.id=lr.user_id JOIN leave_types t ON t.id=lr.leave_type_id WHERE lr.status='approved' AND lr.start_date<=? AND lr.end_date>=? AND u.status='active' ORDER BY u.name LIMIT ".max(1, $limit), [$date, $date]); }

  /** Balances of a user for a year with pending days and availability, one row per active leave type. */
  public static function balances(int $userId, int $year): array {
    $rows = DB::all("SELECT t.id, t.name, t.code, t.color, t.paid, t.annual_quota, b.user_id IS NOT NULL AS allocated_row, COALESCE(b.allocated,0) AS allocated, COALESCE(b.used,0) AS used, COALESCE(p.pending,0) AS pending
      FROM leave_types t LEFT JOIN leave_balances b ON b.leave_type_id=t.id AND b.user_id=? AND b.year=?
      LEFT JOIN (SELECT leave_type_id, SUM(days) AS pending FROM leave_requests WHERE user_id=? AND status='pending' AND start_date BETWEEN ? AND ? GROUP BY leave_type_id) p ON p.leave_type_id=t.id
      WHERE t.active=1 ORDER BY t.paid DESC, t.id", [$userId, $year, $userId, "$year-01-01", "$year-12-31"]);
    foreach ($rows as &$r) { $r['available'] = round((float) $r['allocated'] - (float) $r['used'] - (float) $r['pending'], 1); }
    return $rows; }

  /** Allocate yearly balances from leave_types.annual_quota for every active user. Idempotent: existing rows are kept. */
  public static function allocateYear(int $year): int {
    return DB::query("INSERT IGNORE INTO leave_balances (user_id, leave_type_id, year, allocated, used) SELECT u.id, t.id, ?, t.annual_quota, 0 FROM users u CROSS JOIN leave_types t WHERE u.status='active' AND t.active=1", [$year])->rowCount(); }

  /** Cron: on 1 January allocate the new year's balances. */
  public static function yearRollover(): string {
    if ((int) date('n') !== 1) { return 'Not January: nothing to do'; }
    $y = (int) date('Y');
    $n = self::allocateYear($y);
    \Core\Audit::log('hr.leave.allocate', 'leave_year', $y, ['rows' => $n, 'source' => 'cron']);
    return "Allocated $n balance rows for $y"; } }
