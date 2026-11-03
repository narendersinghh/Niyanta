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
  public static function trackedSalary(): bool { return Settings::bool('payroll.salary_from_tracked_time', true) && feature_on('attendance') && self::att(); }

  /** Hours per working day of each user (their HR schedule, else the company setting). */
  public static function hoursPerDay(array $userIds): array {
    $def = max(0.5, (float) Settings::get('hr.work_hours_per_day', 8) ?: 8.0);
    $out = array_fill_keys($userIds, $def);
    if ($userIds && self::hr('schedules')) {
      try { foreach (call_user_func([self::LEAVE, 'schedules'], $userIds) as $u => $sch) { $out[(int) $u] = (float) $sch['hours_per_day']; } }
      catch (\Throwable $e) { error_log('Payroll: LeaveService::schedules failed: '.$e->getMessage()); } }
    return $out; }

  /** Expected hours of the period: (the employee's working days, fixed holidays excluded, minus approved paid leave) × hours per day. */
  public static function expectedHours(array $userIds, string $from, string $to): array {
    $userIds = array_values(array_unique(array_map('intval', $userIds)));
    if (!$userIds) { return []; }
    $days = self::workingDaysFor($userIds, $from, $to);
    $paid = self::leaveDays($userIds, $from, $to, true);
    $hpd = self::hoursPerDay($userIds);
    $out = [];
    foreach ($userIds as $u) { $out[$u] = round(max(0.0, ($days[$u] ?? 0.0) - ($paid[$u] ?? 0.0)) * $hpd[$u], 2); }
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
   * monthly: base = amount × paid_days / working_days (the employee's own working days); with $expected hours (salary from
   * tracked time): base = amount × min(1, tracked hours ÷ expected hours). hourly: base = rate × hours.
   * gross = base + allowances + reimbursements; net = max(0, gross − deductions).
   */
  public static function compute(array $comp, float $workingDays, float $unpaid, float $hours, array $components, float $reimb, ?float $expected = null): array {
    $rate = round((float) $comp['amount'], 2);
    $h = static fn (float $x): string => rtrim(rtrim(number_format($x, 2, '.', ''), '0'), '.');
    $tracked = $comp['pay_type'] !== 'hourly' && $expected !== null;
    if ($comp['pay_type'] === 'hourly') { $paid = 0.0; $base = round($rate * $hours, 2); }
    elseif ($tracked) { $share = $expected > 0 ? min(1.0, $hours / $expected) : 0.0; $paid = round($workingDays * $share, 1); $base = round($rate * $share, 2); }
    else { $paid = max(0.0, round($workingDays - $unpaid, 1)); $base = $workingDays > 0 ? round($rate * $paid / $workingDays, 2) : 0.0; }
    $earn = [['name' => $comp['pay_type'] === 'hourly' ? 'Base pay ('.$h($hours).' h)' : ($tracked ? 'Basic pay ('.$h($hours).' of '.$h($expected).' h tracked)' : 'Basic pay'), 'amount' => $base]];
    $ded = [];
    $allow = 0.0; $totalDed = 0.0;
    foreach ($components as $c) {
      $amt = self::componentAmount($c, $base);
      if ($amt <= 0) { continue; }
      if ($c['kind'] === 'allowance') { $earn[] = ['name' => self::componentLabel($c), 'amount' => $amt]; $allow += $amt; }
      else { $ded[] = ['name' => self::componentLabel($c), 'amount' => $amt]; $totalDed += $amt; } }
    $reimb = round($reimb, 2);
    if ($reimb > 0) { $earn[] = ['name' => 'Reimbursements', 'amount' => $reimb]; }
    $gross = round($base + $allow + $reimb, 2);
    $totalDed = round($totalDed, 2);
    return ['pay_type' => $comp['pay_type'], 'rate' => $rate, 'working_days' => $workingDays, 'paid_days' => $comp['pay_type'] === 'hourly' ? 0.0 : $paid, 'unpaid_leave_days' => $comp['pay_type'] === 'hourly' ? 0.0 : $unpaid,
      'worked_hours' => $comp['pay_type'] === 'hourly' || $tracked ? $hours : 0.0, 'expected_hours' => $tracked ? $expected : null, 'base_pay' => $base, 'earnings' => $earn, 'deductions' => $ded, 'reimbursement_total' => $reimb, 'gross' => $gross, 'total_deductions' => $totalDed,
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
      $monthly = array_map(static fn ($c) => (int) $c['user_id'], array_filter($comps, static fn ($c) => $c['pay_type'] === 'monthly'));
      $hourly = array_map(static fn ($c) => (int) $c['user_id'], array_filter($comps, static fn ($c) => $c['pay_type'] === 'hourly'));
      $unpaid = self::unpaidLeave(array_values($monthly), $from, $to);
      $ownDays = self::workingDaysFor(array_values($monthly), $from, $to);
      $fromTime = self::trackedSalary();
      $hours = self::approvedHours($fromTime ? $ids : array_values($hourly), $from, $to);
      $expected = $fromTime ? self::expectedHours(array_values($monthly), $from, $to) : [];
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
        $s = self::compute($c, $ownDays[$u] ?? $wd, $unpaid[$u] ?? 0.0, $hours[$u] ?? 0.0, [...$global, ...($personal[$u] ?? [])], array_sum(array_map(static fn ($r) => (float) $r['amount'], $mine)), $c['pay_type'] === 'monthly' && $fromTime ? ($expected[$u] ?? 0.0) : null);
        $flagged += (int) $s['flagged'];
        $sid = DB::insert('payslips', ['run_id' => $runId, 'user_id' => $u, 'pay_type' => $s['pay_type'], 'rate' => $s['rate'], 'working_days' => $s['working_days'], 'paid_days' => $s['paid_days'], 'unpaid_leave_days' => $s['unpaid_leave_days'], 'worked_hours' => $s['worked_hours'], 'expected_hours' => $s['expected_hours'],
          'base_pay' => $s['base_pay'], 'earnings' => json_encode($s['earnings'], JSON_UNESCAPED_UNICODE), 'deductions' => json_encode($s['deductions'], JSON_UNESCAPED_UNICODE), 'reimbursement_total' => $s['reimbursement_total'], 'gross' => $s['gross'], 'total_deductions' => $s['total_deductions'], 'net' => $s['net'], 'created_at' => $now]);
        if ($mine) { $cid = array_map(static fn ($r) => (int) $r['id'], $mine); DB::query('UPDATE reimbursements SET payslip_id=? WHERE id IN '.DB::in($cid), [$sid, ...$cid]); }
        $total += $s['net']; }
      DB::update('payroll_runs', ['working_days' => $wd, 'total_net' => round($total, 2)], 'id=?', [$runId]);
      Audit::log('payroll.run.generate', 'payroll_run', $runId, ['slips' => count($comps), 'total_net' => round($total, 2), 'flagged' => $flagged]);
      return ['count' => count($comps), 'total' => round($total, 2), 'flagged' => $flagged]; }); }

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
