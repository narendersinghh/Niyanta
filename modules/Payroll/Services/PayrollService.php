<?php
namespace Modules\Payroll\Services;
use Core\{Audit, Auth, DB, Modules, Notify, Settings};

/**
 * Payroll engine: integration with HR (working days, unpaid leave) and Attendance (approved hours) with SQL fallbacks,
 * payslip calculation, and the run lifecycle draft → finalized → paid.
 */
final class PayrollService {
  private const LEAVE = 'Modules\\Hr\\Services\\LeaveService';
  private const TIME = 'Modules\\Attendance\\Services\\TimeService';
  /** Set to false to force the built-in SQL fallbacks (diagnostics / tests). */
  public static bool $integrations = true;

  /** [first day, last day] of the month containing $period. */
  public static function monthRange(string $period): array { $from = date('Y-m-01', strtotime($period)); return [$from, date('Y-m-t', strtotime($from))]; }
  public static function monthLabel(string $period): string { return date('F Y', strtotime($period)); }

  private static function hr(string $method): bool { return self::$integrations && Modules::enabled('hr') && class_exists(self::LEAVE) && method_exists(self::LEAVE, $method); }
  private static function att(string $method = 'approvedHours'): bool { return self::$integrations && Modules::enabled('attendance') && class_exists(self::TIME) && method_exists(self::TIME, $method); }

  public static function workingDays(string $from, string $to): float {
    if (self::hr('workingDays')) {
      try { return round((float) call_user_func([self::LEAVE, 'workingDays'], $from, $to), 1); }
      catch (\Throwable $e) { error_log('Payroll: LeaveService::workingDays failed, using fallback: '.$e->getMessage()); } }
    return (float) count(self::workingDates($from, $to)); }

  /** Working days per employee for the period (their own weekly off days via HR), falling back to the company count. */
  public static function workingDaysFor(array $userIds, string $from, string $to): array {
    $userIds = array_values(array_unique(array_map('intval', $userIds)));
    if (!$userIds) { return []; }
    if (self::hr('workingDaysMany')) {
      try { return array_map(static fn ($v) => round((float) $v, 1), call_user_func([self::LEAVE, 'workingDaysMany'], $userIds, $from, $to)) + array_fill_keys($userIds, self::workingDays($from, $to)); }
      catch (\Throwable $e) { error_log('Payroll: LeaveService::workingDaysMany failed, using company calendar: '.$e->getMessage()); } }
    return array_fill_keys($userIds, self::workingDays($from, $to)); }

  /** Fallback calendar: dates in range that are not weekend days (setting general.weekend_days, ISO-N) nor mandatory holidays. */
  public static function workingDates(string $from, string $to): array {
    static $cache = [];
    if (isset($cache["$from|$to"])) { return $cache["$from|$to"]; }
    $weekend = array_map('intval', array_filter(explode(',', (string) Settings::get('general.weekend_days', '6,7')), 'is_numeric'));
    $hol = array_fill_keys(DB::column('SELECT holiday_date FROM holidays WHERE optional=0 AND holiday_date BETWEEN ? AND ?', [$from, $to]), true);
    $out = [];
    for ($d = new \DateTimeImmutable($from), $end = new \DateTimeImmutable($to); $d <= $end; $d = $d->modify('+1 day')) {
      $k = $d->format('Y-m-d');
      if (\Core\WorkWeek::weight($k, $weekend, \Core\WorkWeek::companyRule()) > 0 && !isset($hol[$k])) { $out[$k] = true; } }
    return $cache["$from|$to"] = $out; }

  /** Approved unpaid leave days per user (prorated to the part of each request that overlaps the range). */
  public static function unpaidLeave(array $userIds, string $from, string $to): array { return self::leaveDays($userIds, $from, $to, false); }

  /** Approved paid (true) or unpaid (false) leave days per user, prorated to the range. */
  public static function leaveDays(array $userIds, string $from, string $to, bool $paid): array {
    if (!$userIds) { return []; }
    $out = array_fill_keys($userIds, 0.0);
    if (self::hr('approvedLeaveDays')) {
      try {
        if (method_exists(self::LEAVE, 'approvedLeaveDaysMany')) { foreach (call_user_func([self::LEAVE, 'approvedLeaveDaysMany'], $userIds, $from, $to, $paid) as $u => $v) { if (isset($out[$u])) { $out[$u] = round((float) $v, 1); } } }
        else { foreach ($userIds as $u) { $out[$u] = round((float) call_user_func([self::LEAVE, 'approvedLeaveDays'], (int) $u, $from, $to, $paid), 1); } }
        return $out; }
      catch (\Throwable $e) { error_log('Payroll: LeaveService::approvedLeaveDays failed, using fallback: '.$e->getMessage()); $out = array_fill_keys($userIds, 0.0); } }
    $rows = DB::all("SELECT lr.user_id, lr.start_date, lr.end_date, lr.half_day, lr.days FROM leave_requests lr JOIN leave_types lt ON lt.id=lr.leave_type_id WHERE lr.status='approved' AND lt.paid=? AND lr.start_date<=? AND lr.end_date>=? AND lr.user_id IN ".DB::in($userIds), [$paid ? 1 : 0, $to, $from, ...$userIds]);
    foreach ($rows as $r) {
      $s = max($r['start_date'], $from); $e = min($r['end_date'], $to);
      if ($r['half_day']) { $d = isset(self::workingDates($s, $e)[$s]) ? 0.5 : 0.0; }
      elseif ($r['start_date'] >= $from && $r['end_date'] <= $to) { $d = (float) $r['days']; }
      else { $all = count(self::workingDates($r['start_date'], $r['end_date'])); $d = $all ? (float) $r['days'] * count(self::workingDates($s, $e)) / $all : 0.0; }
      $out[(int) $r['user_id']] += $d; }
    return array_map(static fn ($v) => round($v, 1), $out); }

  /** Is monthly salary paid from tracked time? (setting payroll.salary_from_tracked_time, default on, needs time tracking) */
  public static function trackedSalary(): bool { return Settings::bool('payroll.salary_from_tracked_time', true) && self::hoursPay(); }

  /** Can pay follow tracked hours at all? Needs the Attendance feature (time tracking). Hourly pay is capped by schedule only then. */
  public static function hoursPay(): bool { return feature_on('attendance') && self::att(); }

  public const FLEX = ['monthly' => 'Flexible hours: over the month (default)', 'weekly' => 'Flexible hours: over the week', 'daily' => 'Off: fixed daily hours'];
  public const FLEX_SHORT = ['monthly' => 'Flexible over the month', 'weekly' => 'Flexible over the week', 'daily' => 'Fixed daily hours'];
  public const OT_RATES = ['1' => '1x hourly rate', '1.5' => '1.5x hourly rate', '2' => '2x hourly rate', 'manual' => 'Manual multiplier'];

  /** Flexible hours mode (setting payroll.flex_mode): monthly (default), weekly or daily (no flexibility). */
  public static function flexMode(): string { $m = (string) Settings::get('payroll.flex_mode', 'monthly'); return isset(self::FLEX[$m]) ? $m : 'monthly'; }

  /** Overtime multiplier of the hourly rate, or null while overtime pay is off (default). */
  public static function overtimeMultiplier(): ?float {
    if (!Settings::bool('payroll.overtime_enabled', false)) { return null; }
    $r = (string) Settings::get('payroll.overtime_rate', '1.5');
    if ($r === 'manual') { $v = (float) Settings::get('payroll.overtime_manual', 1); return $v > 0 ? min(10.0, $v) : 1.0; }
    return isset(self::OT_RATES[$r]) ? (float) $r : 1.5; }

  /** "1.5x" style label of a multiplier. */
  public static function multLabel(float $m): string { return rtrim(rtrim(number_format($m, 2, '.', ''), '0'), '.').'x'; }

  /** Work schedules (hours per day, weekly off days, Saturday rule) from HR, else the company defaults. */
  private static function scheduleMap(array $userIds): array {
    $def = ['hours_per_day' => max(0.5, (float) Settings::get('hr.work_hours_per_day', 8) ?: 8.0), 'off_days' => array_map('intval', array_filter(explode(',', (string) Settings::get('general.weekend_days', '6,7')), 'is_numeric')), 'saturday' => \Core\WorkWeek::companyRule()];
    $out = array_fill_keys($userIds, $def);
    if ($userIds && self::hr('schedules')) {
      try { foreach (call_user_func([self::LEAVE, 'schedules'], $userIds) as $u => $s) { $out[(int) $u] = ['hours_per_day' => (float) $s['hours_per_day'], 'off_days' => $s['off_days'], 'saturday' => $s['saturday'] ?? 'full']; } }
      catch (\Throwable $e) { error_log('Payroll: LeaveService::schedules failed: '.$e->getMessage()); } }
    return $out; }

  /**
   * Expected hours per user and date of [from, to] (one query per kind for all users):
   * hours per day × day weight (weekly offs, Saturday half/alternate rule; fixed holidays 0) minus approved PAID leave
   * (leave fraction × hours per day × weight). Unpaid leave does not reduce expected hours. Dates outside employment
   * (before joining, after exit) are null. Returns [uid => ['days' => [Y-m-d => float|null], 'expected' => hours inside
   * employment, 'expected_full' => hours of the whole period, 'scheduled_full' / 'scheduled_in' => hours before leave]].
   */
  public static function hoursPlan(array $userIds, string $from, string $to): array {
    $userIds = array_values(array_unique(array_map('intval', $userIds)));
    if (!$userIds) { return []; }
    $sched = self::scheduleMap($userIds);
    $hol = array_fill_keys(DB::column('SELECT holiday_date FROM holidays WHERE optional=0 AND holiday_date BETWEEN ? AND ?', [$from, $to]), true);
    $emp = [];
    foreach (DB::all('SELECT u.id, u.joined_on, ep.exit_date FROM users u LEFT JOIN employee_profiles ep ON ep.user_id=u.id WHERE u.id IN '.DB::in($userIds), $userIds) as $r) { $emp[(int) $r['id']] = [$r['joined_on'] ?: null, $r['exit_date'] ?: null]; }
    $leave = [];
    foreach (DB::all("SELECT lr.user_id, lr.start_date, lr.end_date, lr.half_day FROM leave_requests lr JOIN leave_types lt ON lt.id=lr.leave_type_id WHERE lr.status='approved' AND lt.paid=1 AND lr.start_date<=? AND lr.end_date>=? AND lr.user_id IN ".DB::in($userIds), [$to, $from, ...$userIds]) as $l) {
      $u = (int) $l['user_id'];
      for ($t = strtotime(max($l['start_date'], $from)), $e = strtotime(min($l['end_date'], $to)); $t <= $e; $t = strtotime('+1 day', $t)) { $d = date('Y-m-d', $t); $leave[$u][$d] = min(1.0, ($leave[$u][$d] ?? 0.0) + ($l['half_day'] ? 0.5 : 1.0)); } }
    $dates = [];
    for ($t = strtotime($from), $e = strtotime($to); $t <= $e; $t = strtotime('+1 day', $t)) { $dates[] = date('Y-m-d', $t); }
    $weights = []; $out = [];
    foreach ($userIds as $u) {
      $s = $sched[$u]; $hpd = (float) $s['hours_per_day'];
      $k = implode(',', $s['off_days']).'|'.$s['saturday'];
      if (!isset($weights[$k])) { foreach ($dates as $d) { $weights[$k][$d] = isset($hol[$d]) ? 0.0 : \Core\WorkWeek::weight($d, $s['off_days'], $s['saturday']); } }
      [$join, $exit] = $emp[$u] ?? [null, null];
      $p = ['days' => [], 'expected' => 0.0, 'expected_full' => 0.0, 'scheduled_full' => 0.0, 'scheduled_in' => 0.0];
      foreach ($dates as $d) {
        $sh = $hpd * $weights[$k][$d];
        $x = $sh * (1 - ($leave[$u][$d] ?? 0.0));
        $p['scheduled_full'] += $sh; $p['expected_full'] += $x;
        if (($join && $d < $join) || ($exit && $d > $exit)) { $p['days'][$d] = null; continue; }
        $p['days'][$d] = $x; $p['expected'] += $x; $p['scheduled_in'] += $sh; }
      foreach (['expected', 'expected_full', 'scheduled_full', 'scheduled_in'] as $f) { $p[$f] = round($p[$f], 2); }
      $out[$u] = $p; }
    return $out; }

  /** Expected hours of the period per user (see hoursPlan(): schedule × day weight, holidays off, minus paid leave, inside employment). */
  public static function expectedHours(array $userIds, string $from, string $to): array { return array_map(static fn ($p) => $p['expected'], self::hoursPlan($userIds, $from, $to)); }

  /** Approved worked hours per user and day: [uid => [Y-m-d => hours]] (same source as approvedHours()). */
  public static function approvedHoursByDay(array $userIds, string $from, string $to): array {
    $userIds = array_values(array_unique(array_map('intval', $userIds)));
    if (!$userIds) { return []; }
    if (self::att('approvedHoursByDay')) {
      try { return call_user_func([self::TIME, 'approvedHoursByDay'], $userIds, $from, $to) + array_fill_keys($userIds, []); }
      catch (\Throwable $e) { error_log('Payroll: TimeService::approvedHoursByDay failed, using fallback: '.$e->getMessage()); } }
    $out = array_fill_keys($userIds, []);
    foreach (DB::all("SELECT user_id, DATE(started_at) AS d, SUM(GREATEST(CAST(duration_seconds AS SIGNED) - CAST(idle_seconds AS SIGNED), 0)) AS s FROM time_entries WHERE status='approved' AND started_at BETWEEN ? AND ? AND user_id IN ".DB::in($userIds).' GROUP BY user_id, DATE(started_at)', [$from.' 00:00:00', $to.' 23:59:59', ...$userIds]) as $r) { $out[(int) $r['user_id']][$r['d']] = round((float) $r['s'] / 3600, 4); }
    return $out; }

  /**
   * Payable and overtime hours from expected and worked hours per date (pure). The cap depends on the flexible hours mode:
   * monthly: min(worked, expected) over the whole period; weekly: per ISO week (clipped to the period) min(worked, expected)
   * summed; daily: per day min(worked, expected) summed. Overtime = the hours above the cap at the same granularity.
   * Dates with a null expectation (outside employment) and worked dates outside the plan are ignored.
   */
  public static function payable(array $expected, array $worked, string $mode): array {
    $b = [];
    foreach ($expected as $d => $x) {
      if ($x === null) { continue; }
      $k = match ($mode) { 'daily' => $d, 'weekly' => date('o-W', strtotime($d)), default => 'm' };
      $b[$k]['e'] = ($b[$k]['e'] ?? 0.0) + (float) $x;
      $b[$k]['w'] = ($b[$k]['w'] ?? 0.0) + (float) ($worked[$d] ?? 0.0); }
    $r = ['expected' => 0.0, 'worked' => 0.0, 'payable' => 0.0, 'overtime' => 0.0];
    foreach ($b as $v) { $r['expected'] += $v['e']; $r['worked'] += $v['w']; $r['payable'] += min($v['w'], $v['e']); $r['overtime'] += max(0.0, $v['w'] - $v['e']); }
    return array_map(static fn ($v) => round($v, 2), $r) + ['mode' => isset(self::FLEX[$mode]) ? $mode : 'monthly']; }

  /** Hours breakdown of each user for compute(): plan + worked hours + flex mode + overtime setting. */
  public static function hoursBreakdown(array $userIds, string $from, string $to): array {
    $plan = self::hoursPlan($userIds, $from, $to);
    $byDay = self::approvedHoursByDay(array_keys($plan), $from, $to);
    $mode = self::flexMode(); $ot = self::overtimeMultiplier();
    $out = [];
    foreach ($plan as $u => $p) { $out[$u] = self::payable($p['days'], $byDay[$u] ?? [], $mode) + ['expected_full' => $p['expected_full'], 'scheduled_full' => $p['scheduled_full'], 'scheduled_in' => $p['scheduled_in'], 'ot_multiplier' => $ot]; }
    return $out; }

  /** Hours per working day of each user (their HR schedule, else the company setting). */
  public static function hoursPerDay(array $userIds): array {
    $def = max(0.5, (float) Settings::get('hr.work_hours_per_day', 8) ?: 8.0);
    $out = array_fill_keys($userIds, $def);
    if ($userIds && self::hr('schedules')) {
      try { foreach (call_user_func([self::LEAVE, 'schedules'], $userIds) as $u => $sch) { $out[(int) $u] = (float) $sch['hours_per_day']; } }
      catch (\Throwable $e) { error_log('Payroll: LeaveService::schedules failed: '.$e->getMessage()); } }
    return $out; }

  /** Approved worked hours per user (time entries minus idle time). */
  public static function approvedHours(array $userIds, string $from, string $to): array {
    if (!$userIds) { return []; }
    $out = array_fill_keys($userIds, 0.0);
    if (self::att()) {
      try {
        if (self::att('approvedHoursMany')) { foreach (call_user_func([self::TIME, 'approvedHoursMany'], $userIds, $from, $to) as $u => $v) { if (isset($out[$u])) { $out[$u] = round((float) $v, 2); } } }
        else { foreach ($userIds as $u) { $out[$u] = round((float) call_user_func([self::TIME, 'approvedHours'], (int) $u, $from, $to), 2); } }
        return $out; }
      catch (\Throwable $e) { error_log('Payroll: TimeService::approvedHours failed, using fallback: '.$e->getMessage()); $out = array_fill_keys($userIds, 0.0); } }
    $sec = DB::pairs("SELECT user_id, SUM(GREATEST(CAST(duration_seconds AS SIGNED) - CAST(idle_seconds AS SIGNED), 0)) FROM time_entries WHERE status='approved' AND started_at BETWEEN ? AND ? AND user_id IN ".DB::in($userIds).' GROUP BY user_id', [$from.' 00:00:00', $to.' 23:59:59', ...$userIds]);
    foreach ($sec as $u => $s) { $out[(int) $u] = round((float) $s / 3600, 2); }
    return $out; }

  /** Effective compensation (latest effective_from <= period end) of every active employee on the payroll for the period. */
  public static function effectiveCompensations(string $from, string $to): array {
    return DB::all("SELECT c.* FROM compensations c JOIN users u ON u.id=c.user_id LEFT JOIN employee_profiles ep ON ep.user_id=u.id
      WHERE u.status='active' AND (u.joined_on IS NULL OR u.joined_on<=?) AND (ep.exit_date IS NULL OR ep.exit_date>=?)
      AND c.id=(SELECT c2.id FROM compensations c2 WHERE c2.user_id=c.user_id AND c2.effective_from<=? ORDER BY c2.effective_from DESC, c2.id DESC LIMIT 1) ORDER BY u.name", [$to, $from, $to]); }

  public static function componentAmount(array $c, float $base): float { return $c['calc'] === 'percent' ? round($base * (float) $c['value'] / 100, 2) : round((float) $c['value'], 2); }
  public static function componentLabel(array $c): string { return $c['calc'] === 'percent' ? $c['name'].' ('.rtrim(rtrim(number_format((float) $c['value'], 2, '.', ''), '0'), '.').'%)' : $c['name']; }

  /** Earnings lines for display: while reimbursements are switched off, an older reimbursement line is shown as "Other earnings". */
  public static function earningsFor(array $slip): array {
    $earn = json_decode((string) $slip['earnings'], true) ?: [];
    if (feature_on('reimbursements')) { return $earn; }
    return array_map(static fn ($r) => ($r['name'] ?? '') === 'Reimbursements' ? ['name' => 'Other earnings'] + $r : $r, $earn); }

  /**
   * Pure payslip calculation.
   * monthly, days: base = amount × paid_days / working_days (the employee's own working days, unpaid leave unpaid).
   * monthly, hours ($expected or $hx given, salary from tracked time): hourly rate of the month = amount ÷ expected hours
   *   of the whole month (schedule, holidays and paid leave applied); base = rate × payable hours (capped by the flexible
   *   hours mode, see payable()), never above the amount. A joiner or leaver is capped by the expected hours inside employment.
   * hourly: base = rate × payable hours ($hx) or × all hours (no $hx: time tracking unavailable).
   * overtime ($hx with ot_multiplier): overtime hours × hourly rate × multiplier, an extra earnings line.
   * gross = base + overtime + allowances + reimbursements; net = max(0, gross − deductions).
   * $hx: ['expected', 'worked', 'payable', 'overtime', 'mode', 'expected_full'?, 'scheduled_full'?, 'scheduled_in'?, 'ot_multiplier'?].
   */
  public static function compute(array $comp, float $workingDays, float $unpaid, float $hours, array $components, float $reimb, ?float $expected = null, ?array $hx = null): array {
    $rate = round((float) $comp['amount'], 2);
    $h = static fn (float $x): string => rtrim(rtrim(number_format($x, 2, '.', ''), '0'), '.');
    $hourly = $comp['pay_type'] === 'hourly';
    if (!$hourly && $hx === null && $expected !== null) { $hx = ['expected' => $expected, 'worked' => $hours, 'payable' => min($hours, $expected), 'overtime' => 0.0, 'mode' => 'monthly']; }
    $tracked = $hx !== null;
    $mult = $tracked && ($hx['ot_multiplier'] ?? null) ? (float) $hx['ot_multiplier'] : null;
    $hr = null; $ot = 0.0; $otPay = 0.0;
    if ($tracked) { $hours = (float) $hx['worked']; $ot = $mult ? (float) $hx['overtime'] : 0.0; }
    if ($hourly) {
      $paid = 0.0; $hr = $rate;
      $base = round($rate * ($tracked ? (float) $hx['payable'] : $hours), 2); }
    elseif ($tracked) {
      $full = (float) ($hx['expected_full'] ?? $hx['expected']);
      if ($full > 0) { $hr = $rate / $full; $base = min($rate, round($hr * (float) $hx['payable'], 2)); }
      elseif (($hx['scheduled_full'] ?? 0) > 0) { $hr = $rate / (float) $hx['scheduled_full']; $base = round($rate * (float) $hx['scheduled_in'] / (float) $hx['scheduled_full'], 2); } /* the whole month on paid leave */
      else { $hr = 0.0; $base = 0.0; }
      $paid = $rate > 0 ? round($workingDays * $base / $rate, 1) : 0.0; }
    else { $paid = max(0.0, round($workingDays - $unpaid, 1)); $base = $workingDays > 0 ? round($rate * $paid / $workingDays, 2) : 0.0; }
    if ($mult && $ot > 0 && $hr) { $otPay = round($ot * $hr * $mult, 2); }
    $earn = [['name' => $hourly ? 'Base pay ('.$h($tracked ? (float) $hx['payable'] : $hours).' h)' : ($tracked ? 'Basic pay ('.$h((float) $hx['payable']).' of '.$h((float) $hx['expected']).' h tracked)' : 'Basic pay'), 'amount' => $base]];
    if ($otPay > 0) { $earn[] = ['name' => 'Overtime ('.$h($ot).' h × '.self::multLabel($mult).')', 'amount' => $otPay]; }
    $ded = [];
    $allow = 0.0; $totalDed = 0.0;
    foreach ($components as $c) {
      $amt = self::componentAmount($c, $base);
      if ($amt <= 0) { continue; }
      if ($c['kind'] === 'allowance') { $earn[] = ['name' => self::componentLabel($c), 'amount' => $amt]; $allow += $amt; }
      else { $ded[] = ['name' => self::componentLabel($c), 'amount' => $amt]; $totalDed += $amt; } }
    $reimb = round($reimb, 2);
    if ($reimb > 0) { $earn[] = ['name' => 'Reimbursements', 'amount' => $reimb]; }
    $gross = round($base + $otPay + $allow + $reimb, 2);
    $totalDed = round($totalDed, 2);
    return ['pay_type' => $comp['pay_type'], 'rate' => $rate, 'working_days' => $workingDays, 'paid_days' => $hourly ? 0.0 : $paid, 'unpaid_leave_days' => $hourly ? 0.0 : $unpaid,
      'worked_hours' => $hourly || $tracked ? round($hours, 2) : 0.0, 'expected_hours' => $tracked ? round((float) $hx['expected'], 2) : null, 'payable_hours' => $tracked ? round((float) $hx['payable'], 2) : null,
      'overtime_hours' => round($ot, 2), 'overtime_pay' => $otPay, 'hour_rate' => $hr === null ? null : round($hr, 4), 'flex_mode' => $tracked ? ($hx['mode'] ?? 'monthly') : null,
      'missing_pay' => $tracked && !$hourly ? max(0.0, round($rate - $base, 2)) : 0.0, 'base_pay' => $base, 'earnings' => $earn, 'deductions' => $ded, 'reimbursement_total' => $reimb, 'gross' => $gross, 'total_deductions' => $totalDed,
      'net' => max(0.0, round($gross - $totalDed, 2)), 'flagged' => $totalDed > $gross]; }

  /** (Re)generate all payslips of a draft run. Idempotent: previous slips are removed and their claims unlinked first. */
  public static function generate(int $runId): array {
    return DB::transaction(static function () use ($runId): array {
      $run = DB::first('SELECT * FROM payroll_runs WHERE id=? FOR UPDATE', [$runId]);
      if (!$run || $run['status'] !== 'draft') { throw new \DomainException('Payslips can only be generated while the run is a draft.'); }
      [$from, $to] = self::monthRange($run['period_month']);
      DB::query("UPDATE reimbursements SET payslip_id=NULL WHERE status='approved' AND payslip_id IN (SELECT id FROM payslips WHERE run_id=?)", [$runId]);
      DB::delete('payslips', 'run_id=?', [$runId]);
      $comps = self::effectiveCompensations($from, $to);
      $wd = self::workingDays($from, $to);
      $ids = array_map(static fn ($c) => (int) $c['user_id'], $comps);
      $monthly = array_values(array_map(static fn ($c) => (int) $c['user_id'], array_filter($comps, static fn ($c) => $c['pay_type'] === 'monthly')));
      $hourly = array_values(array_map(static fn ($c) => (int) $c['user_id'], array_filter($comps, static fn ($c) => $c['pay_type'] === 'hourly')));
      $unpaid = self::unpaidLeave($monthly, $from, $to);
      $ownDays = self::workingDaysFor($monthly, $from, $to);
      /* Hours-based pay needs time tracking: hourly pay is capped by the schedule, monthly salary only while "from tracked time" is on. */
      $byHours = array_merge(self::hoursPay() ? $hourly : [], self::trackedSalary() ? $monthly : []);
      $hx = self::hoursBreakdown($byHours, $from, $to);
      $plain = array_values(array_diff($hourly, $byHours));
      $hours = self::approvedHours($plain, $from, $to);
      $global = []; $personal = [];
      if ($ids) {
        foreach (DB::all('SELECT * FROM pay_components WHERE active=1 AND (user_id IS NULL OR user_id IN '.DB::in($ids).") ORDER BY kind, user_id IS NOT NULL, name", $ids) as $c) {
          if ($c['user_id'] === null) { $global[] = $c; } else { $personal[(int) $c['user_id']][] = $c; } } }
      $claims = [];
      if ($ids && feature_on('reimbursements')) { foreach (DB::all("SELECT id, user_id, amount FROM reimbursements WHERE status='approved' AND payslip_id IS NULL AND expense_date<=? AND user_id IN ".DB::in($ids).' FOR UPDATE', [$to, ...$ids]) as $r) { $claims[(int) $r['user_id']][] = $r; } }
      $total = 0.0; $flagged = 0; $now = date('Y-m-d H:i:s');
      foreach ($comps as $c) {
        $u = (int) $c['user_id'];
        $mine = $claims[$u] ?? [];
        $x = $hx[$u] ?? null;
        $s = self::compute($c, $ownDays[$u] ?? $wd, $unpaid[$u] ?? 0.0, $x ? (float) $x['worked'] : ($hours[$u] ?? 0.0), [...$global, ...($personal[$u] ?? [])], array_sum(array_map(static fn ($r) => (float) $r['amount'], $mine)), null, $x);
        $flagged += (int) $s['flagged'];
        $sid = DB::insert('payslips', ['run_id' => $runId, 'user_id' => $u, 'pay_type' => $s['pay_type'], 'rate' => $s['rate'], 'working_days' => $s['working_days'], 'paid_days' => $s['paid_days'], 'unpaid_leave_days' => $s['unpaid_leave_days'], 'worked_hours' => $s['worked_hours'], 'expected_hours' => $s['expected_hours'],
          'payable_hours' => $s['payable_hours'], 'overtime_hours' => $s['overtime_hours'], 'overtime_pay' => $s['overtime_pay'], 'hour_rate' => $s['hour_rate'], 'flex_mode' => $s['flex_mode'],
          'base_pay' => $s['base_pay'], 'earnings' => json_encode($s['earnings'], JSON_UNESCAPED_UNICODE), 'deductions' => json_encode($s['deductions'], JSON_UNESCAPED_UNICODE), 'reimbursement_total' => $s['reimbursement_total'], 'gross' => $s['gross'], 'total_deductions' => $s['total_deductions'], 'net' => $s['net'], 'created_at' => $now]);
        if ($mine) { $cid = array_map(static fn ($r) => (int) $r['id'], $mine); DB::query('UPDATE reimbursements SET payslip_id=? WHERE id IN '.DB::in($cid), [$sid, ...$cid]); }
        $total += $s['net']; }
      DB::update('payroll_runs', ['working_days' => $wd, 'total_net' => round($total, 2)], 'id=?', [$runId]);
      Audit::log('payroll.run.generate', 'payroll_run', $runId, ['slips' => count($comps), 'total_net' => round($total, 2), 'flagged' => $flagged, 'flex_mode' => $byHours ? self::flexMode() : null, 'overtime' => $byHours ? self::overtimeMultiplier() : null]);
      return ['count' => count($comps), 'total' => round($total, 2), 'flagged' => $flagged]; }); }

  /**
   * Hours breakdown of a payslip for display (web and PDF): [[label, value]], empty when pay was not based on hours.
   * Payslips from before the breakdown columns existed fall back to worked/expected hours.
   */
  public static function hoursLines(array $s): array {
    if (($s['expected_hours'] ?? null) === null) { return []; }
    $h = static fn ($x): string => rtrim(rtrim(number_format((float) $x, 2, '.', ''), '0'), '.').' h';
    $monthly = $s['pay_type'] !== 'hourly';
    $exp = (float) $s['expected_hours']; $worked = (float) $s['worked_hours'];
    $payable = ($s['payable_hours'] ?? null) !== null ? (float) $s['payable_hours'] : min($worked, $exp);
    $rate = ($s['hour_rate'] ?? null) !== null ? (float) $s['hour_rate'] : ($monthly ? ($exp > 0 ? (float) $s['rate'] / $exp : 0.0) : (float) $s['rate']);
    $ot = (float) ($s['overtime_hours'] ?? 0); $otPay = (float) ($s['overtime_pay'] ?? 0);
    $out = [['Flexible hours', self::FLEX_SHORT[$s['flex_mode'] ?? 'monthly'] ?? self::FLEX_SHORT['monthly']], ['Expected hours', $h($exp)], ['Worked hours (approved)', $h($worked)], ['Payable hours', $h($payable)]];
    if ($ot > 0) { $out[] = ['Overtime hours', $h($ot)]; }
    $out[] = [$monthly ? 'Hourly rate this month' : 'Hourly rate', money(round($rate, 2))];
    $out[] = ['Base pay', money($s['base_pay'])];
    if ($otPay > 0) { $out[] = ['Overtime pay', money($otPay)]; }
    if ($monthly) { $out[] = ['Deduction for missing hours', money(max(0.0, round((float) $s['rate'] - (float) $s['base_pay'], 2)))]; }
    if ($otPay <= 0 && $worked - $payable > 0.004) { $out[] = ['Extra hours (overtime off, not paid)', $h($worked - $payable)]; }
    return $out; }

  /** Estimate of one employee's base pay for a period with the current settings (compensation page preview). Null without compensation. */
  public static function estimate(array $comp, string $from, string $to): ?array {
    $u = (int) $comp['user_id'];
    $byHours = $comp['pay_type'] === 'hourly' ? self::hoursPay() : self::trackedSalary();
    $x = $byHours ? (self::hoursBreakdown([$u], $from, $to)[$u] ?? null) : null;
    $wd = self::workingDaysFor([$u], $from, $to)[$u] ?? self::workingDays($from, $to);
    $hours = $x ? (float) $x['worked'] : ($comp['pay_type'] === 'hourly' ? (self::approvedHours([$u], $from, $to)[$u] ?? 0.0) : 0.0);
    return self::compute($comp, $wd, $comp['pay_type'] === 'hourly' ? 0.0 : (self::unpaidLeave([$u], $from, $to)[$u] ?? 0.0), $hours, [], 0.0, null, $x); }

  public static function createRun(string $month): int {
    $period = $month.'-01';
    return DB::transaction(static function () use ($period): int {
      if (DB::value('SELECT id FROM payroll_runs WHERE period_month=?', [$period])) { throw new \DomainException('A payroll run for '.self::monthLabel($period).' already exists.'); }
      $id = DB::insert('payroll_runs', ['period_month' => $period, 'status' => 'draft', 'created_by' => Auth::id(), 'created_at' => date('Y-m-d H:i:s')]);
      Audit::log('payroll.run.create', 'payroll_run', $id, ['month' => $period]);
      self::generate($id);
      return $id; }); }

  public static function finalize(int $runId): int {
    [$run, $list] = DB::transaction(static function () use ($runId): array {
      $run = DB::first('SELECT * FROM payroll_runs WHERE id=? FOR UPDATE', [$runId]);
      if (!$run || $run['status'] !== 'draft') { throw new \DomainException('Only draft runs can be finalized.'); }
      $slips = DB::all('SELECT id, user_id FROM payslips WHERE run_id=?', [$runId]);
      if (!$slips) { throw new \DomainException('This run has no payslips. Add compensation records and regenerate first.'); }
      DB::update('payroll_runs', ['status' => 'finalized', 'finalized_by' => Auth::id(), 'finalized_at' => date('Y-m-d H:i:s')], 'id=?', [$runId]);
      Audit::log('payroll.run.finalize', 'payroll_run', $runId, ['slips' => count($slips), 'total_net' => (float) $run['total_net']]);
      return [$run, $slips]; });
    $label = self::monthLabel($run['period_month']);
    foreach ($list as $s) { Notify::user((int) $s['user_id'], "Your payslip for $label is ready", 'Your payslip has been published. You can view and download it any time.', '/payslips/'.$s['id'], true, 'wallet'); }
    return count($list); }

  public static function markPaid(int $runId): int {
    return DB::transaction(static function () use ($runId): int {
      $run = DB::first('SELECT * FROM payroll_runs WHERE id=? FOR UPDATE', [$runId]);
      if (!$run || $run['status'] !== 'finalized') { throw new \DomainException('Only finalized runs can be marked as paid.'); }
      DB::update('payroll_runs', ['status' => 'paid'], 'id=?', [$runId]);
      $n = DB::query("UPDATE reimbursements SET status='paid' WHERE status='approved' AND payslip_id IN (SELECT id FROM payslips WHERE run_id=?)", [$runId])->rowCount();
      Audit::log('payroll.run.paid', 'payroll_run', $runId, ['reimbursements_paid' => $n]);
      return $n; }); }

  public static function deleteRun(int $runId): void {
    DB::transaction(static function () use ($runId): void {
      $run = DB::first('SELECT * FROM payroll_runs WHERE id=? FOR UPDATE', [$runId]);
      if (!$run || $run['status'] !== 'draft') { throw new \DomainException('Only draft runs can be deleted.'); }
      DB::query("UPDATE reimbursements SET payslip_id=NULL WHERE status='approved' AND payslip_id IN (SELECT id FROM payslips WHERE run_id=?)", [$runId]);
      DB::delete('payroll_runs', 'id=?', [$runId]);
      Audit::log('payroll.run.delete', 'payroll_run', $runId, ['month' => $run['period_month']]); }); }

  /** Month the next run should cover: the month after the latest run, or the current month. */
  public static function nextRunMonth(): string {
    $last = DB::value('SELECT MAX(period_month) FROM payroll_runs');
    return $last ? date('Y-m-01', strtotime($last.' +1 month')) : date('Y-m-01'); }

  /** Cron: on the 25th remind payroll managers to prepare the current month's run when none exists yet. */
  public static function reminder(): string {
    $month = date('Y-m-01');
    if (DB::value('SELECT id FROM payroll_runs WHERE period_month=?', [$month])) { return 'run exists'; }
    $label = self::monthLabel($month);
    $users = \Core\Gate::usersWith('payroll.manage');
    foreach ($users as $u) { Notify::user((int) $u['id'], "Prepare payroll for $label", "No payroll run exists for $label yet. Create the draft run so payslips are ready before month end.", '/payroll', true, 'wallet'); }
    return 'notified '.count($users); }

  /** Amount in words, e.g. "Rupees Forty-Five Thousand Two Hundred and Fifty Paise Only". Indian grouping for INR. */
  public static function inWords(float $amount): string {
    $code = strtoupper((string) Settings::get('general.currency_code', 'INR'));
    $indian = $code === 'INR';
    [$major, $minor] = $indian ? ['Rupees', 'Paise'] : [$code, 'Cents'];
    $n = (int) floor(round($amount, 2)); $f = (int) round((round($amount, 2) - $n) * 100);
    $w = $major.' '.self::words($n, $indian);
    if ($f > 0) { $w .= ' and '.self::words($f, $indian).' '.$minor; }
    return $w.' Only'; }

  private static function words(int $n, bool $indian): string {
    static $ones = ['Zero', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
    static $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
    if ($n < 20) { return $ones[$n]; }
    if ($n < 100) { return $tens[intdiv($n, 10)].($n % 10 ? '-'.$ones[$n % 10] : ''); }
    if ($n < 1000) { return $ones[intdiv($n, 100)].' Hundred'.($n % 100 ? ' '.self::words($n % 100, $indian) : ''); }
    $scale = $indian ? [10000000 => 'Crore', 100000 => 'Lakh', 1000 => 'Thousand'] : [1000000000 => 'Billion', 1000000 => 'Million', 1000 => 'Thousand'];
    foreach ($scale as $v => $l) { if ($n >= $v) { return self::words(intdiv($n, $v), $indian).' '.$l.($n % $v ? ' '.self::words($n % $v, $indian) : ''); } }
    return (string) $n; } }
