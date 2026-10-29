<?php
namespace Modules\Hr\Services;
use Core\{DB, Settings};

/**
 * Leave & calendar helpers shared with other modules (Payroll, Attendance).
 * Company defaults: weekly off days (setting general.weekend_days, ISO-N, default "6,7") and hours per day (hr.work_hours_per_day).
 * Each employee may override both (employee_profiles.weekly_off_days / work_hours_per_day, NULL = company default) and
 * their yearly quota per leave type (employee_leave_quotas). Fixed holidays (holidays.optional=0) are off for everyone;
 * optional festival days are taken from the "Optional Holiday" (OH) leave pool.
 */
final class LeaveService {
  public const OPTIONAL_CODE = 'OH';
  public const DAY_NAMES = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];
  private static array $holidays = [];
  private static ?array $weekend = null;
  private static array $sched = [];

  /** Parse an ISO-N csv ("6,7") into sorted unique day numbers. */
  public static function parseDays(?string $csv): array {
    $d = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) $csv)), static fn ($n) => $n >= 1 && $n <= 7)));
    sort($d);
    return $d; }

  /** Company default weekly off days (ISO-8601 weekday numbers, 1=Mon … 7=Sun). */
  public static function weekendDays(): array { return self::$weekend ??= self::parseDays((string) Settings::get('general.weekend_days', '6,7')); }

  /** Company default working hours per day. */
  public static function companyHours(): float { return max(0.5, min(24.0, (float) Settings::get('hr.work_hours_per_day', 8) ?: 8.0)); }

  /** Compact day count: 1, 1.5, 12. */
  public static function num(mixed $v): string { return rtrim(rtrim(number_format((float) $v, 1, '.', ''), '0'), '.'); }

  /** Human label for a set of off days, e.g. "Sat, Sun". */
  public static function daysLabel(array $days): string { return $days ? implode(', ', array_map(static fn ($d) => self::DAY_NAMES[$d], $days)) : 'No weekly off'; }

  /**
   * Effective schedules of many users in one query (cached per request):
   * [uid => ['hours_per_day' => float, 'off_days' => int[], 'custom_hours' => bool, 'custom_days' => bool]].
   */
  public static function schedules(array $userIds): array {
    $userIds = array_values(array_unique(array_map('intval', $userIds)));
    $missing = array_values(array_filter($userIds, static fn ($u) => !isset(self::$sched[$u])));
    if ($missing) {
      $rows = [];
      try { foreach (DB::all('SELECT user_id, work_hours_per_day, weekly_off_days FROM employee_profiles WHERE user_id IN '.DB::in($missing), $missing) as $r) { $rows[(int) $r['user_id']] = $r; } }
      catch (\Throwable $e) { error_log('LeaveService::schedules: '.$e->getMessage()); }
      foreach ($missing as $u) {
        $r = $rows[$u] ?? [];
        $h = isset($r['work_hours_per_day']) && $r['work_hours_per_day'] !== null && (float) $r['work_hours_per_day'] > 0;
        $d = isset($r['weekly_off_days']) && $r['weekly_off_days'] !== null;
        self::$sched[$u] = ['hours_per_day' => $h ? (float) $r['work_hours_per_day'] : self::companyHours(), 'off_days' => $d ? self::parseDays($r['weekly_off_days']) : self::weekendDays(), 'custom_hours' => $h, 'custom_days' => $d]; } }
    return array_intersect_key(self::$sched, array_flip($userIds)); }

  /** Effective work schedule of one user. */
  public static function scheduleFor(int $userId): array { return self::schedules([$userId])[$userId]; }

  /** Non-optional (fixed) holidays of a year as [Y-m-d => name] (cached per request). */
  public static function holidaysOf(int $year): array {
    return self::$holidays[$year] ??= DB::pairs('SELECT holiday_date, MIN(name) FROM holidays WHERE optional=0 AND holiday_date BETWEEN ? AND ? GROUP BY holiday_date', ["$year-01-01", "$year-12-31"]); }

  public static function flush(): void { self::$holidays = []; self::$weekend = null; self::$sched = []; }

  /** True when the date falls on a company default weekly off day. */
  public static function isWeekend(string $date): bool { return in_array((int) date('N', strtotime($date)), self::weekendDays(), true); }

  /** True when the date is a fixed (non-optional) holiday. */
  public static function isHoliday(string $date): bool { $t = strtotime($date); return $t !== false && isset(self::holidaysOf((int) date('Y', $t))[date('Y-m-d', $t)]); }

  /** Working day on the company calendar. */
  public static function isWorkingDay(string $date): bool { return !self::isWeekend($date) && !self::isHoliday($date); }

  /** Working day for a specific employee (their own weekly off days + fixed holidays). */
  public static function isWorkingDayFor(int $userId, string $date): bool {
    return !in_array((int) date('N', strtotime($date)), self::scheduleFor($userId)['off_days'], true) && !self::isHoliday($date); }

  /** Count dates in [from, to] that are not in $offDays and not fixed holidays. */
  private static function countDays(string $from, string $to, array $offDays): float {
    $a = strtotime($from); $b = strtotime($to);
    if ($a === false || $b === false || $b < $a) { return 0.0; }
    $n = 0; $guard = 0;
    for ($t = $a; $t <= $b && $guard < 3700; $t = strtotime('+1 day', $t), $guard++) { if (!in_array((int) date('N', $t), $offDays, true) && !self::isHoliday(date('Y-m-d', $t))) { $n++; } }
    return (float) $n; }

  /** Company working days between two dates inclusive (default weekly off days and fixed holidays excluded). */
  public static function workingDays(string $from, string $to): float { return self::countDays($from, $to, self::weekendDays()); }

  /** Working days of one employee between two dates inclusive (their weekly off days and fixed holidays excluded). */
  public static function workingDaysFor(int $userId, string $from, string $to): float { return self::countDays($from, $to, self::scheduleFor($userId)['off_days']); }

  /** Bulk variant for payroll: [uid => working days]; users sharing a calendar are counted once. */
  public static function workingDaysMany(array $userIds, string $from, string $to): array {
    $out = []; $memo = [];
    foreach (self::schedules($userIds) as $u => $s) { $k = implode(',', $s['off_days']); $out[$u] = $memo[$k] ??= self::countDays($from, $to, $s['off_days']); }
    return $out; }

  /** Days a request consumes on the company calendar: working days, or 0.5 for a half day on a working day. */
  public static function requestDays(string $from, string $to, bool $half): float {
    if ($half) { return $from === $to && self::isWorkingDay($from) ? 0.5 : 0.0; }
    return self::workingDays($from, $to); }

  /** Days a request of a specific employee consumes (uses their own weekly off days). */
  public static function requestDaysFor(int $userId, string $from, string $to, bool $half): float {
    if ($half) { return $from === $to && self::isWorkingDayFor($userId, $from) ? 0.5 : 0.0; }
    return self::workingDaysFor($userId, $from, $to); }

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
    self::schedules($userIds);
    foreach (DB::all($sql, $p) as $r) { $out[(int) $r['user_id']] += self::portion($r, $from, $to); }
    return array_map(static fn ($v) => round($v, 2), $out); }

  /** Share of a request's days that falls within [from, to] (on the requester's calendar when user_id is present). */
  public static function portion(array $r, string $from, string $to): float {
    $days = (float) $r['days'];
    if ($r['start_date'] >= $from && $r['end_date'] <= $to) { return $days; }
    $off = isset($r['user_id']) ? self::scheduleFor((int) $r['user_id'])['off_days'] : self::weekendDays();
    $full = self::countDays($r['start_date'], $r['end_date'], $off);
    if ($full <= 0) { return 0.0; }
    $part = self::countDays(max($r['start_date'], $from), min($r['end_date'], $to), $off);
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

  /** Allocate yearly balances for every active user from their personal quota override, else leave_types.annual_quota. Idempotent: existing rows are kept. */
  public static function allocateYear(int $year): int {
    return DB::query("INSERT IGNORE INTO leave_balances (user_id, leave_type_id, year, allocated, used) SELECT u.id, t.id, ?, COALESCE(q.annual_quota, t.annual_quota), 0 FROM users u CROSS JOIN leave_types t LEFT JOIN employee_leave_quotas q ON q.user_id=u.id AND q.leave_type_id=t.id WHERE u.status='active' AND t.active=1", [$year])->rowCount(); }

  /** Yearly quotas of a user per active leave type: [{id, name, code, color, paid, default, override|null, effective}]. */
  public static function quotasFor(int $userId): array {
    $rows = DB::all('SELECT t.id, t.name, t.code, t.color, t.paid, t.annual_quota AS `default`, q.annual_quota AS `override` FROM leave_types t LEFT JOIN employee_leave_quotas q ON q.leave_type_id=t.id AND q.user_id=? WHERE t.active=1 ORDER BY t.paid DESC, t.id', [$userId]);
    foreach ($rows as &$r) { $r['effective'] = (float) ($r['override'] ?? $r['default']); }
    return $rows; }

  /** The optional-holiday leave type (code OH), or null when missing/inactive. */
  public static function optionalType(): ?array { return DB::first('SELECT * FROM leave_types WHERE code=? AND active=1', [self::OPTIONAL_CODE]); }

  /** Cron: on 1 January allocate the new year's balances. */
  public static function yearRollover(): string {
    if ((int) date('n') !== 1) { return 'Not January: nothing to do'; }
    $y = (int) date('Y');
    $n = self::allocateYear($y);
    \Core\Audit::log('hr.leave.allocate', 'leave_year', $y, ['rows' => $n, 'source' => 'cron']);
    return "Allocated $n balance rows for $y"; } }
