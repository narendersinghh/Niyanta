<?php
namespace Modules\Hr\Services;
use Core\{DB, Settings, WorkWeek};

/**
 * Leave & calendar helpers shared with other modules (Payroll, Attendance).
 * Company defaults: weekly off days (setting general.weekend_days, ISO-N, default "6,7") and hours per day (hr.work_hours_per_day).
 * Each employee may override both (employee_profiles.weekly_off_days / work_hours_per_day, NULL = company default) and
 * their yearly quota per leave type (employee_leave_quotas). Fixed holidays (holidays.optional=0) are off for everyone;
 * optional festival days are taken from the "Optional Holiday" (OH) pool or from Paid Leave (PL).
 *
 * Leave policies (leave_types): allowance none (manual credits only) | yearly (annual_quota, allocated on 1 January) |
 * monthly (monthly_accrual credited on the 1st of every month for that month), carry forward none | limit (carry_limit) | all,
 * max_balance cap, allow_half_day, needs_approval (off = booked as approved), notice_days, max_consecutive,
 * min_service_days and employment_types eligibility, shortage_cover. Unpaid types never need a balance.
 * Every balance change is written to leave_ledger (opening, allocation, accrual, adjustment, usage, carry_forward, expiry,
 * shortage); leave_balances.allocated is the sum of credits and used the sum of debits of a user/type/year.
 *
 * Accrual (cron leave_accrual, daily, idempotent per user/type/month): active employees who joined on or before the cut-off
 * day of the month (setting hr.leave.accrual_cutoff_day, default 15) get the full monthly amount, later joiners start next
 * month; never above max_balance. Year end (cron leave_year_rollover on 1 January): the new year is allocated, then each
 * type's remaining balance is carried forward per its rule (capped by max_balance) and the rest expires.
 *
 * Shortage cover for payroll:
 *   LeaveService::shortageCover(int $userId, string $period, float $shortageHours, float $hoursPerDay, bool $commit): array
 *   $period is 'Y-m'. Shortage days = $shortageHours / $hoursPerDay rounded to the nearest 0.5 day, taken from the first active
 *   paid type with shortage_cover=1 (PL first), never more than its available balance (allocated - used - pending requests).
 *   Returns ['days' => float days used, 'hours' => float hours covered (<= $shortageHours), 'type_id' => int|null,
 *   'balance_after' => float allocated - used after the cover]. $commit=false only previews. $commit=true records a 'shortage'
 *   ledger entry and adds it to used; re-running for the same user+period first reverses that period's earlier shortage use
 *   (so a regenerated draft run replaces it). When setting hr.leave.shortage_cover is off or the leave feature is off it returns
 *   0 days (and, with $commit, still reverses an earlier cover). shortageRevert($userId, $period) undoes it (e.g. deleted run).
 */
final class LeaveService {
  public const OPTIONAL_CODE = 'OH';
  public const PAID_CODE = 'PL';
  public const ALLOWANCES = ['none' => 'No automatic allowance', 'yearly' => 'Yearly quota', 'monthly' => 'Monthly accrual'];
  public const CARRY = ['none' => 'Lapses at year end', 'limit' => 'Carry forward up to a limit', 'all' => 'Carry forward everything'];
  public const EMPLOYMENT = ['full_time' => 'Full-time', 'part_time' => 'Part-time', 'contract' => 'Contract', 'intern' => 'Intern', 'consultant' => 'Consultant'];
  public const KINDS = ['opening' => 'Opening balance', 'allocation' => 'Yearly allocation', 'accrual' => 'Monthly accrual', 'adjustment' => 'Adjustment', 'usage' => 'Leave taken', 'carry_forward' => 'Carry forward', 'expiry' => 'Expired', 'shortage' => 'Hours shortage cover'];
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
  public static function num(mixed $v): string { return rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.'); }

  /** Human label for a set of off days, e.g. "Sat, Sun". */
  public static function daysLabel(array $days, ?string $sat = null): string {
    $l = $days ? implode(', ', array_map(static fn ($d) => self::DAY_NAMES[$d], $days)) : 'No weekly off';
    $x = $sat ? WorkWeek::label($sat, $days) : '';
    return $x === '' ? $l : ($days ? $l.' · '.$x : $x); }

  /**
   * Effective schedules of many users in one query (cached per request):
   * [uid => ['hours_per_day' => float, 'off_days' => int[], 'custom_hours' => bool, 'custom_days' => bool]].
   */
  public static function schedules(array $userIds): array {
    $userIds = array_values(array_unique(array_map('intval', $userIds)));
    $missing = array_values(array_filter($userIds, static fn ($u) => !isset(self::$sched[$u])));
    if ($missing) {
      $rows = [];
      try { foreach (DB::all('SELECT * FROM employee_profiles WHERE user_id IN '.DB::in($missing), $missing) as $r) { $rows[(int) $r['user_id']] = $r; } }
      catch (\Throwable $e) { error_log('LeaveService::schedules: '.$e->getMessage()); }
      foreach ($missing as $u) {
        $r = $rows[$u] ?? [];
        $h = isset($r['work_hours_per_day']) && $r['work_hours_per_day'] !== null && (float) $r['work_hours_per_day'] > 0;
        $d = isset($r['weekly_off_days']) && $r['weekly_off_days'] !== null;
        self::$sched[$u] = ['hours_per_day' => $h ? (float) $r['work_hours_per_day'] : self::companyHours(), 'off_days' => $d ? self::parseDays($r['weekly_off_days']) : self::weekendDays(), 'saturday' => $d && WorkWeek::valid($r['saturday_rule'] ?? null) ? $r['saturday_rule'] : ($d ? 'full' : WorkWeek::companyRule()), 'custom_hours' => $h, 'custom_days' => $d]; } }
    return array_intersect_key(self::$sched, array_flip($userIds)); }

  /** Effective work schedule of one user. */
  public static function scheduleFor(int $userId): array { return self::schedules([$userId])[$userId]; }

  /** Non-optional (fixed) holidays of a year as [Y-m-d => name] (cached per request). */
  public static function holidaysOf(int $year): array {
    return self::$holidays[$year] ??= DB::pairs('SELECT holiday_date, MIN(name) FROM holidays WHERE optional=0 AND holiday_date BETWEEN ? AND ? GROUP BY holiday_date', ["$year-01-01", "$year-12-31"]); }

  public static function flush(): void { self::$holidays = []; self::$weekend = null; self::$sched = []; }

  /** True when the date falls on a company default weekly off day. */
  public static function isWeekend(string $date): bool { return WorkWeek::weight($date, self::weekendDays(), WorkWeek::companyRule()) <= 0; }

  /** Share of a working day the date counts for one employee (or the company): 0 off, 0.5 half day, 1 full; fixed holidays are 0. */
  public static function weight(string $date, ?int $userId = null): float {
    if (self::isHoliday($date)) { return 0.0; }
    $s = $userId ? self::scheduleFor($userId) : ['off_days' => self::weekendDays(), 'saturday' => WorkWeek::companyRule()];
    return WorkWeek::weight($date, $s['off_days'], $s['saturday']); }

  /** True when the date is a fixed (non-optional) holiday. */
  public static function isHoliday(string $date): bool { $t = strtotime($date); return $t !== false && isset(self::holidaysOf((int) date('Y', $t))[date('Y-m-d', $t)]); }

  /** Working day on the company calendar. */
  public static function isWorkingDay(string $date): bool { return !self::isWeekend($date) && !self::isHoliday($date); }

  /** Working day for a specific employee (their own weekly off days + fixed holidays). */
  public static function isWorkingDayFor(int $userId, string $date): bool {
    $s = self::scheduleFor($userId);
    return WorkWeek::weight($date, $s['off_days'], $s['saturday']) > 0 && !self::isHoliday($date); }

  /** Working days in [from, to]: weekly off days, Saturday rule (off / half) and fixed holidays applied. */
  private static function countDays(string $from, string $to, array $offDays, string $sat = 'full'): float {
    $a = strtotime($from); $b = strtotime($to);
    if ($a === false || $b === false || $b < $a) { return 0.0; }
    $n = 0; $guard = 0;
    for ($t = $a; $t <= $b && $guard < 3700; $t = strtotime('+1 day', $t), $guard++) { if (!self::isHoliday(date('Y-m-d', $t))) { $n += WorkWeek::weight($t, $offDays, $sat); } }
    return (float) $n; }

  /** Company working days between two dates inclusive (default weekly off days and fixed holidays excluded). */
  public static function workingDays(string $from, string $to): float { return self::countDays($from, $to, self::weekendDays(), WorkWeek::companyRule()); }

  /** Working days of one employee between two dates inclusive (their weekly off days and fixed holidays excluded). */
  public static function workingDaysFor(int $userId, string $from, string $to): float { $s = self::scheduleFor($userId); return self::countDays($from, $to, $s['off_days'], $s['saturday']); }

  /** Bulk variant for payroll: [uid => working days]; users sharing a calendar are counted once. */
  public static function workingDaysMany(array $userIds, string $from, string $to): array {
    $out = []; $memo = [];
    foreach (self::schedules($userIds) as $u => $s) { $k = implode(',', $s['off_days']).'|'.$s['saturday']; $out[$u] = $memo[$k] ??= self::countDays($from, $to, $s['off_days'], $s['saturday']); }
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
    $sc = isset($r['user_id']) ? self::scheduleFor((int) $r['user_id']) : ['off_days' => self::weekendDays(), 'saturday' => WorkWeek::companyRule()];
    $off = $sc['off_days'];
    $full = self::countDays($r['start_date'], $r['end_date'], $off, $sc['saturday']);
    if ($full <= 0) { return 0.0; }
    $part = self::countDays(max($r['start_date'], $from), min($r['end_date'], $to), $off, $sc['saturday']);
    return min($days, $days * $part / $full); }

  /** True when the user has approved leave covering the date. */
  public static function onLeave(int $userId, string $date): bool {
    return (bool) DB::value("SELECT 1 FROM leave_requests WHERE user_id=? AND status='approved' AND start_date<=? AND end_date>=? LIMIT 1", [$userId, $date, $date]); }

  /** Users on approved leave on a date with their leave type. */
  public static function onLeaveOn(string $date, int $limit = 200): array {
    return DB::all("SELECT u.id, u.name, u.avatar, u.designation, u.department, t.name AS type_name, t.color, lr.half_day, lr.end_date FROM leave_requests lr JOIN users u ON u.id=lr.user_id JOIN leave_types t ON t.id=lr.leave_type_id WHERE lr.status='approved' AND lr.start_date<=? AND lr.end_date>=? AND u.status='active' ORDER BY u.name LIMIT ".max(1, $limit), [$date, $date]); }

  /** Balances of a user for a year with pending days and availability, one row per active leave type (policy columns included). */
  public static function balances(int $userId, int $year): array {
    $rows = DB::all("SELECT t.id, t.name, t.code, t.color, t.paid, t.annual_quota, t.allowance, t.monthly_accrual, t.allow_half_day, t.shortage_cover, t.description, b.user_id IS NOT NULL AS allocated_row, COALESCE(b.allocated,0) AS allocated, COALESCE(b.used,0) AS used, COALESCE(p.pending,0) AS pending
      FROM leave_types t LEFT JOIN leave_balances b ON b.leave_type_id=t.id AND b.user_id=? AND b.year=?
      LEFT JOIN (SELECT leave_type_id, SUM(days) AS pending FROM leave_requests WHERE user_id=? AND status='pending' AND start_date BETWEEN ? AND ? GROUP BY leave_type_id) p ON p.leave_type_id=t.id
      WHERE t.active=1 ORDER BY t.paid DESC, t.code=? DESC, t.id", [$userId, $year, $userId, "$year-01-01", "$year-12-31", self::PAID_CODE]);
    foreach ($rows as &$r) { $r['available'] = round((float) $r['allocated'] - (float) $r['used'] - (float) $r['pending'], 2); }
    return $rows; }

  /** Whether a leave type applies to an employment type (empty list = everyone). */
  public static function typeAllows(array $t, ?string $employment): bool {
    $list = array_filter(explode(',', (string) ($t['employment_types'] ?? '')));
    return !$list || in_array($employment ?: 'full_time', $list, true); }

  /** Write one ledger entry; false when the same once-only entry (user/type/year/kind/period) already exists. */
  public static function ledger(int $userId, int $typeId, int $year, string $kind, float $days, ?string $period = null, ?int $ref = null, ?string $note = null, ?int $by = null): bool {
    return DB::query('INSERT IGNORE INTO leave_ledger (user_id, leave_type_id, year, kind, days, period, ref_id, note, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)',
      [$userId, $typeId, $year, $kind, round($days, 2), $period, $ref, $note === null ? null : mb_substr($note, 0, 255), $by, date('Y-m-d H:i:s')])->rowCount() > 0; }

  private static function ensureRow(int $userId, int $typeId, int $year): void { DB::query('INSERT IGNORE INTO leave_balances (user_id, leave_type_id, year, allocated, used) VALUES (?,?,?,0,0)', [$userId, $typeId, $year]); }

  /** Allocate yearly balances for every active, eligible user: yearly types from their personal quota override, else annual_quota; other types start at 0. Idempotent: existing rows are kept. */
  public static function allocateYear(int $year): int {
    $rows = DB::all("SELECT u.id AS uid, t.id AS tid, t.allowance, t.employment_types, COALESCE(q.annual_quota, t.annual_quota) AS quota, COALESCE(p.employment_type,'full_time') AS et
      FROM users u CROSS JOIN leave_types t LEFT JOIN employee_leave_quotas q ON q.user_id=u.id AND q.leave_type_id=t.id LEFT JOIN employee_profiles p ON p.user_id=u.id
      LEFT JOIN leave_balances b ON b.user_id=u.id AND b.leave_type_id=t.id AND b.year=? WHERE u.status='active' AND t.active=1 AND b.user_id IS NULL", [$year]);
    $n = 0;
    foreach ($rows as $r) {
      if (!self::typeAllows($r, $r['et'])) { continue; }
      $alloc = $r['allowance'] === 'yearly' ? (float) $r['quota'] : 0.0;
      $ok = DB::transaction(static function () use ($r, $alloc, $year): bool {
        if (!DB::query('INSERT IGNORE INTO leave_balances (user_id, leave_type_id, year, allocated, used) VALUES (?,?,?,?,0)', [$r['uid'], $r['tid'], $year, $alloc])->rowCount()) { return false; }
        if ($alloc != 0) { self::ledger((int) $r['uid'], (int) $r['tid'], $year, 'allocation', $alloc, (string) $year, null, 'Yearly quota for '.$year); }
        return true; });
      $n += $ok ? 1 : 0; }
    return $n; }

  /** Yearly quotas of a user per active leave type: [{id, name, code, color, paid, default, override|null, effective}]. */
  public static function quotasFor(int $userId): array {
    $rows = DB::all('SELECT t.id, t.name, t.code, t.color, t.paid, t.allowance, t.annual_quota AS `default`, q.annual_quota AS `override` FROM leave_types t LEFT JOIN employee_leave_quotas q ON q.leave_type_id=t.id AND q.user_id=? WHERE t.active=1 ORDER BY t.paid DESC, t.id', [$userId]);
    foreach ($rows as &$r) { $r['effective'] = (float) ($r['override'] ?? $r['default']); }
    return $rows; }

  /** The optional-holiday leave type (code OH), or null when missing/inactive. */
  public static function optionalType(): ?array { return DB::first('SELECT * FROM leave_types WHERE code=? AND active=1', [self::OPTIONAL_CODE]); }

  /** Type used for single festival days on the holidays page: OH when active, else the first active paid shortage-cover type (PL). */
  public static function festivalType(): ?array { return self::optionalType() ?? DB::first('SELECT * FROM leave_types WHERE active=1 AND paid=1 AND shortage_cover=1 ORDER BY code=? DESC, id LIMIT 1', [self::PAID_CODE]); }

  /** Join day of the month up to which a new joiner still gets that month's accrual (1-28). */
  public static function accrualCutoff(): int { return max(1, min(28, (int) Settings::get('hr.leave.accrual_cutoff_day', 15) ?: 15)); }

  /**
   * Credit monthly-accrual types for a month (Y-m, not in the future). Idempotent per user/type/month; capped at max_balance
   * (a capped month is still recorded, with 0 or a partial amount). Returns ['credited' => entries, 'days' => total].
   */
  public static function accrueMonth(string $ym, ?array $userIds = null, ?int $by = null): array {
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $ym) || $ym > date('Y-m')) { return ['credited' => 0, 'days' => 0.0]; }
    $types = DB::all("SELECT * FROM leave_types WHERE active=1 AND allowance='monthly' AND monthly_accrual>0");
    if (!$types) { return ['credited' => 0, 'days' => 0.0]; }
    $year = (int) substr($ym, 0, 4); $cut = sprintf('%s-%02d', $ym, self::accrualCutoff());
    $sql = "SELECT u.id, COALESCE(p.employment_type,'full_time') AS et FROM users u LEFT JOIN employee_profiles p ON p.user_id=u.id WHERE u.status='active' AND (u.joined_on IS NULL OR u.joined_on<=?)";
    $p = [$cut];
    if ($userIds !== null) { $userIds = array_values(array_unique(array_map('intval', $userIds))); if (!$userIds) { return ['credited' => 0, 'days' => 0.0]; } $sql .= ' AND u.id IN '.DB::in($userIds); array_push($p, ...$userIds); }
    $users = DB::all($sql, $p);
    $done = array_fill_keys(array_map(static fn ($r) => $r['user_id'].'-'.$r['leave_type_id'], DB::all("SELECT user_id, leave_type_id FROM leave_ledger WHERE kind='accrual' AND year=? AND period=?", [$year, $ym])), true);
    $n = 0; $total = 0.0;
    foreach ($types as $t) {
      foreach ($users as $u) {
        $uid = (int) $u['id'];
        if (isset($done[$uid.'-'.$t['id']]) || !self::typeAllows($t, $u['et'])) { continue; }
        $amt = DB::transaction(static function () use ($uid, $t, $year, $ym, $by): ?float {
          self::ensureRow($uid, (int) $t['id'], $year);
          $b = DB::first('SELECT allocated, used FROM leave_balances WHERE user_id=? AND leave_type_id=? AND year=? FOR UPDATE', [$uid, $t['id'], $year]);
          $amt = (float) $t['monthly_accrual']; $note = self::num($amt).' day(s) for '.date('M Y', strtotime($ym.'-01'));
          if ($t['max_balance'] !== null && $t['max_balance'] !== '') {
            $room = max(0.0, (float) $t['max_balance'] - ((float) $b['allocated'] - (float) $b['used']));
            if ($room < $amt) { $amt = floor($room * 100) / 100; $note .= ', limited by the '.self::num($t['max_balance']).' day cap'; } }
          if (!self::ledger($uid, (int) $t['id'], $year, 'accrual', $amt, $ym, null, $note, $by)) { return null; }
          if ($amt != 0) { DB::query('UPDATE leave_balances SET allocated=allocated+? WHERE user_id=? AND leave_type_id=? AND year=?', [$amt, $uid, $t['id'], $year]); }
          return $amt; });
        if ($amt !== null) { $n++; $total += $amt; } } }
    return ['credited' => $n, 'days' => round($total, 2)]; }

  /** Cron: credit this month's accrual to everyone still missing it (new joiners up to the cut-off day are picked up on the next run). */
  public static function accrualCron(): string {
    $r = self::accrueMonth(date('Y-m'));
    return $r['credited'] ? 'Accrued '.self::num($r['days']).' day(s) in '.$r['credited'].' balance(s) for '.date('M Y') : 'Nothing to accrue for '.date('M Y'); }

  /**
   * Close a year: each balance's remainder (allocated - used) moves to the next year per the type's carry-forward rule
   * (none / up to carry_limit / all, capped by max_balance) and the rest expires. The old year's allocation is reduced by
   * the remainder so it cannot be spent twice. Idempotent per user/type/year. Returns the number of balances closed.
   */
  public static function carryForward(int $fromYear, ?int $by = null): int {
    $to = $fromYear + 1; $tag = (string) $to;
    $rows = DB::all("SELECT b.user_id, b.leave_type_id, t.name, t.paid, t.carry_forward, t.carry_limit, t.max_balance FROM leave_balances b JOIN leave_types t ON t.id=b.leave_type_id JOIN users u ON u.id=b.user_id
      WHERE b.year=? AND t.paid=1 AND u.status='active' AND b.allocated-b.used>0 AND NOT EXISTS (SELECT 1 FROM leave_ledger l WHERE l.user_id=b.user_id AND l.leave_type_id=b.leave_type_id AND l.year=b.year AND l.kind IN ('carry_forward','expiry') AND l.period=?)", [$fromYear, $tag]);
    $n = 0;
    foreach ($rows as $r) {
      $ok = DB::transaction(static function () use ($r, $fromYear, $to, $tag, $by): bool {
        $uid = (int) $r['user_id']; $tid = (int) $r['leave_type_id'];
        $b = DB::first('SELECT allocated, used FROM leave_balances WHERE user_id=? AND leave_type_id=? AND year=? FOR UPDATE', [$uid, $tid, $fromYear]);
        $left = round((float) $b['allocated'] - (float) $b['used'], 2);
        if ($left <= 0) { return false; }
        $carry = match ($r['carry_forward']) { 'all' => $left, 'limit' => min($left, max(0.0, (float) $r['carry_limit'])), default => 0.0 };
        if ($carry > 0) {
          self::ensureRow($uid, $tid, $to);
          $nb = DB::first('SELECT allocated, used FROM leave_balances WHERE user_id=? AND leave_type_id=? AND year=? FOR UPDATE', [$uid, $tid, $to]);
          if ($r['max_balance'] !== null && $r['max_balance'] !== '') { $carry = min($carry, max(0.0, (float) $r['max_balance'] - ((float) $nb['allocated'] - (float) $nb['used']))); } }
        $carry = round($carry, 2); $expire = round($left - $carry, 2);
        if ($carry > 0 && !self::ledger($uid, $tid, $fromYear, 'carry_forward', -$carry, $tag, null, self::num($carry).' day(s) moved to '.$to, $by)) { return false; }
        if ($expire > 0 && !self::ledger($uid, $tid, $fromYear, 'expiry', -$expire, $tag, null, self::num($expire).' unused day(s) lapsed at the end of '.$fromYear, $by)) { throw new \RuntimeException('Duplicate year close'); }
        DB::query('UPDATE leave_balances SET allocated=allocated-? WHERE user_id=? AND leave_type_id=? AND year=?', [$left, $uid, $tid, $fromYear]);
        if ($carry > 0) {
          self::ledger($uid, $tid, $to, 'carry_forward', $carry, (string) $fromYear, null, 'Carried forward from '.$fromYear, $by);
          DB::query('UPDATE leave_balances SET allocated=allocated+? WHERE user_id=? AND leave_type_id=? AND year=?', [$carry, $uid, $tid, $to]); }
        return true; });
      $n += $ok ? 1 : 0; }
    return $n; }

  /** Cron: on 1 January allocate the new year's balances, then carry forward last year's remainders. */
  public static function yearRollover(): string {
    if ((int) date('n') !== 1) { return 'Not January: nothing to do'; }
    $y = (int) date('Y');
    $n = self::allocateYear($y);
    $c = self::carryForward($y - 1);
    \Core\Audit::log('hr.leave.allocate', 'leave_year', $y, ['rows' => $n, 'carried' => $c, 'source' => 'cron']);
    return "Allocated $n balance rows for $y, closed $c balance(s) of ".($y - 1); }

  /** Manual balance change by HR (positive or negative, steps of 0.5). Returns the new allocation; throws on a negative result. */
  public static function adjust(int $userId, int $typeId, int $year, float $delta, string $reason, ?int $by): float {
    return DB::transaction(static function () use ($userId, $typeId, $year, $delta, $reason, $by): float {
      self::ensureRow($userId, $typeId, $year);
      $b = DB::first('SELECT allocated, used FROM leave_balances WHERE user_id=? AND leave_type_id=? AND year=? FOR UPDATE', [$userId, $typeId, $year]);
      $alloc = round((float) $b['allocated'] + $delta, 2);
      if ($alloc < 0 || $alloc > 999) { throw new \Core\ValidationException(['delta' => 'Allocated balance cannot go below zero.']); }
      DB::update('leave_balances', ['allocated' => $alloc], 'user_id=? AND leave_type_id=? AND year=?', [$userId, $typeId, $year]);
      self::ledger($userId, $typeId, $year, 'adjustment', $delta, null, null, $reason, $by);
      return $alloc; }); }

  /** Book an approved request against the balance (used += days) with a ledger entry. Call inside the approval transaction. */
  public static function recordUsage(array $lr, ?int $by = null): void {
    $year = (int) substr($lr['start_date'], 0, 4);
    DB::query('INSERT INTO leave_balances (user_id, leave_type_id, year, allocated, used) VALUES (?,?,?,0,?) ON DUPLICATE KEY UPDATE used=used+VALUES(used)', [$lr['user_id'], $lr['leave_type_id'], $year, $lr['days']]);
    self::ledger((int) $lr['user_id'], (int) $lr['leave_type_id'], $year, 'usage', -(float) $lr['days'], null, (int) $lr['id'], date('d M', strtotime($lr['start_date'])).($lr['end_date'] !== $lr['start_date'] ? ' - '.date('d M', strtotime($lr['end_date'])) : '').((int) ($lr['half_day'] ?? 0) ? ' (half day)' : ''), $by); }

  /**
   * Policy checks for a request (balance is checked separately under a lock): half days, notice, maximum consecutive days,
   * service-days and employment-type eligibility. Returns [field => message], empty when allowed.
   */
  public static function validateRequest(int $userId, array $t, string $from, string $to, bool $half, float $days, ?string $today = null): array {
    $e = []; $today ??= date('Y-m-d'); $name = $t['name'];
    if ($half && !(int) ($t['allow_half_day'] ?? 1)) { $e['half_day'] = $name.' cannot be taken as a half day. Choose full days or another leave type.'; }
    $notice = (int) ($t['notice_days'] ?? 0);
    if ($notice > 0 && $from < ($earliest = date('Y-m-d', strtotime($today.' +'.$notice.' days')))) { $e['start_date'] = $name.' needs '.$notice.' day'.($notice === 1 ? '' : 's').' notice. The earliest start date is '.date('d M Y', strtotime($earliest)).'.'; }
    $max = (float) ($t['max_consecutive'] ?? 0);
    if ($max > 0 && $days > $max) { $e['end_date'] = 'At most '.self::num($max).' day(s) of '.$name.' in one request; this one is '.self::num($days).'. Split it or use another leave type.'; }
    $u = DB::first("SELECT u.joined_on, COALESCE(p.employment_type,'full_time') AS et FROM users u LEFT JOIN employee_profiles p ON p.user_id=u.id WHERE u.id=?", [$userId]) ?? ['joined_on' => null, 'et' => 'full_time'];
    $svc = (int) ($t['min_service_days'] ?? 0);
    if ($svc > 0 && $u['joined_on'] && $from < ($ok = date('Y-m-d', strtotime($u['joined_on'].' +'.$svc.' days')))) { $e['leave_type_id'] = $name.' is available after '.$svc.' days of service, from '.date('d M Y', strtotime($ok)).'.'; }
    if (!self::typeAllows($t, $u['et'])) { $e['leave_type_id'] = $name.' is not available for '.strtolower(self::EMPLOYMENT[$u['et']] ?? $u['et']).' employees.'; }
    return $e; }

  /** Balance problem for a paid request, or null when it fits (available = allocated - used - pending). */
  public static function balanceError(int $userId, array $t, int $year, float $days): ?string {
    if ((int) $t['paid'] !== 1) { return null; }
    $bal = array_column(self::balances($userId, $year), null, 'id')[(int) $t['id']] ?? null;
    if (!$bal || !$bal['allocated_row']) { return $t['allowance'] === 'monthly' ? 'You have no '.$t['name'].' balance for '.$year.' yet. It builds up by '.self::num($t['monthly_accrual']).' day(s) every month.' : 'No '.$t['name'].' balance has been allocated to you for '.$year.'. Contact HR.'; }
    if ($days > (float) $bal['available']) { return 'Insufficient '.$t['name'].' balance: '.self::num($bal['available']).' day(s) available (incl. pending requests), '.self::num($days).' requested.'; }
    return null; }

  /** One-line policy summary parts for the leave types page. */
  public static function policySummary(array $t): array {
    $s = [];
    if (!(int) $t['paid']) { $s[] = 'Unpaid, no balance needed'; }
    elseif ($t['allowance'] === 'monthly') { $s[] = self::num($t['monthly_accrual']).' day(s) every month'; }
    elseif ($t['allowance'] === 'yearly') { $s[] = self::num($t['annual_quota']).' day(s) per year'; }
    else { $s[] = 'Balance credited by HR'; }
    if ((int) $t['paid']) { $s[] = match ($t['carry_forward']) { 'all' => 'carries forward', 'limit' => 'carries up to '.self::num($t['carry_limit']).' day(s)', default => 'lapses at year end' }; }
    if ($t['max_balance'] !== null && $t['max_balance'] !== '') { $s[] = 'cap '.self::num($t['max_balance']); }
    $s[] = (int) $t['allow_half_day'] ? 'half days allowed' : 'full days only';
    if (!(int) $t['needs_approval']) { $s[] = 'no approval needed'; }
    if ((int) $t['notice_days']) { $s[] = (int) $t['notice_days'].' day(s) notice'; }
    if ((float) $t['max_consecutive'] > 0) { $s[] = 'max '.self::num($t['max_consecutive']).' in a row'; }
    if ((int) $t['min_service_days']) { $s[] = 'after '.(int) $t['min_service_days'].' days of service'; }
    if ((string) $t['employment_types'] !== '') { $s[] = 'only '.implode(', ', array_map(static fn ($k) => self::EMPLOYMENT[$k] ?? $k, explode(',', (string) $t['employment_types']))); }
    if ((int) $t['shortage_cover']) { $s[] = 'covers hour shortages'; }
    return $s; }

  /** The leave type that covers working-hour shortages: first active paid type with shortage_cover, PL first. */
  public static function shortageType(): ?array { return DB::first('SELECT * FROM leave_types WHERE active=1 AND paid=1 AND shortage_cover=1 ORDER BY code=? DESC, id LIMIT 1', [self::PAID_CODE]); }

  /** See the class docblock. */
  public static function shortageCover(int $userId, string $period, float $shortageHours, float $hoursPerDay, bool $commit): array {
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period)) { throw new \InvalidArgumentException('Period must be Y-m.'); }
    $on = Settings::bool('hr.leave.shortage_cover', true) && (!function_exists('feature_on') || feature_on('leave'));
    $year = (int) substr($period, 0, 4);
    $fn = static function () use ($userId, $period, $shortageHours, $hoursPerDay, $commit, $on, $year): array {
      $prev = DB::all("SELECT id, leave_type_id, year, days FROM leave_ledger WHERE user_id=? AND kind='shortage' AND period=?".($commit ? ' FOR UPDATE' : ''), [$userId, $period]);
      $t = self::shortageType();
      $out = ['days' => 0.0, 'hours' => 0.0, 'type_id' => $t ? (int) $t['id'] : null, 'balance_after' => 0.0];
      if ($commit) {
        foreach ($prev as $p) { DB::query('UPDATE leave_balances SET used=GREATEST(0, used-?) WHERE user_id=? AND leave_type_id=? AND year=?', [-(float) $p['days'], $userId, $p['leave_type_id'], $p['year']]); DB::delete('leave_ledger', 'id=?', [$p['id']]); }
        $prev = []; }
      if (!$t) { return $out; }
      $b = DB::first('SELECT allocated, used FROM leave_balances WHERE user_id=? AND leave_type_id=? AND year=?'.($commit ? ' FOR UPDATE' : ''), [$userId, $t['id'], $year]) ?? ['allocated' => 0, 'used' => 0];
      $back = 0.0;
      foreach ($prev as $p) { if ((int) $p['leave_type_id'] === (int) $t['id'] && (int) $p['year'] === $year) { $back += -(float) $p['days']; } }
      $used = (float) $b['used'] - $back;
      $pending = (float) DB::value("SELECT COALESCE(SUM(days),0) FROM leave_requests WHERE user_id=? AND leave_type_id=? AND status='pending' AND start_date BETWEEN ? AND ?", [$userId, $t['id'], "$year-01-01", "$year-12-31"]);
      $avail = (float) $b['allocated'] - $used - $pending;
      $need = $on && $shortageHours > 0 && $hoursPerDay > 0 ? round($shortageHours / $hoursPerDay * 2) / 2 : 0.0;
      $days = max(0.0, min($need, floor(max(0.0, $avail) * 2) / 2));
      $out['days'] = $days;
      $out['hours'] = round(min(max(0.0, $shortageHours), $days * $hoursPerDay), 2);
      $out['balance_after'] = round((float) $b['allocated'] - $used - $days, 2);
      if ($commit && $days > 0) {
        self::ensureRow($userId, (int) $t['id'], $year);
        DB::query('UPDATE leave_balances SET used=used+? WHERE user_id=? AND leave_type_id=? AND year=?', [$days, $userId, $t['id'], $year]);
        self::ledger($userId, (int) $t['id'], $year, 'shortage', -$days, $period, null, self::num($out['hours']).' h short in '.date('M Y', strtotime($period.'-01')).' covered ('.self::num($hoursPerDay).' h per day)'); }
      return $out; };
    return $commit ? DB::transaction($fn) : $fn(); }

  /** Undo the shortage cover of a period (e.g. a deleted payroll run). Returns the days given back. */
  public static function shortageRevert(int $userId, string $period): float {
    return DB::transaction(static function () use ($userId, $period): float {
      $back = 0.0;
      foreach (DB::all("SELECT id, leave_type_id, year, days FROM leave_ledger WHERE user_id=? AND kind='shortage' AND period=? FOR UPDATE", [$userId, $period]) as $p) {
        DB::query('UPDATE leave_balances SET used=GREATEST(0, used-?) WHERE user_id=? AND leave_type_id=? AND year=?', [-(float) $p['days'], $userId, $p['leave_type_id'], $p['year']]);
        DB::delete('leave_ledger', 'id=?', [$p['id']]); $back += -(float) $p['days']; }
      return $back; }); }

  /** Ledger rows of a user (optionally one year / type), newest first. */
  public static function ledgerFor(int $userId, ?int $year = null, ?int $typeId = null): array {
    $sql = 'SELECT l.*, t.name AS type_name, t.code, t.color, c.name AS by_name FROM leave_ledger l JOIN leave_types t ON t.id=l.leave_type_id LEFT JOIN users c ON c.id=l.created_by WHERE l.user_id=?';
    $p = [$userId];
    if ($year) { $sql .= ' AND l.year=?'; $p[] = $year; }
    if ($typeId) { $sql .= ' AND l.leave_type_id=?'; $p[] = $typeId; }
    return DB::all($sql.' ORDER BY l.created_at DESC, l.id DESC LIMIT 500', $p); } }
