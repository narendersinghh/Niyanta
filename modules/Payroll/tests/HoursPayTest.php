<?php
use Core\{DB, Settings};
use Modules\Hr\Services\LeaveService;
use Modules\Payroll\Services\PayrollService;

/** Approved (or other status) time entry of $h hours starting 09:00 on $date. */
function hp_time(int $u, string $date, float $h, string $status = 'approved'): void {
  $s = strtotime($date.' 09:00:00'); $sec = (int) round($h * 3600);
  DB::insert('time_entries', ['user_id' => $u, 'started_at' => date('Y-m-d H:i:s', $s), 'ended_at' => date('Y-m-d H:i:s', $s + $sec), 'duration_seconds' => $sec, 'status' => $status, 'is_running' => 0, 'source' => 'manual']); }

/** Payroll settings for one scenario (flex mode, overtime off / multiplier / 'manual:1.25'). */
function hp_settings(string $mode, ?string $ot = null): void {
  Settings::set('payroll.flex_mode', $mode);
  Settings::set('payroll.overtime_enabled', $ot === null ? '0' : '1');
  if ($ot !== null && str_starts_with($ot, 'manual:')) { Settings::set('payroll.overtime_rate', 'manual'); Settings::set('payroll.overtime_manual', substr($ot, 7)); }
  elseif ($ot !== null) { Settings::set('payroll.overtime_rate', $ot); } }

/** Regenerate the run and return [user_id => payslip row]. */
function hp_slips(int $run): array {
  PayrollService::generate($run);
  $out = [];
  foreach (DB::all('SELECT * FROM payslips WHERE run_id=?', [$run]) as $s) { $out[(int) $s['user_id']] = $s; }
  return $out; }

return [
  'flexible hours caps: monthly, weekly (split at month end) and daily, with overtime above the cap' => function () {
    // Mon 2036-09-01 .. Sat 09-06 (Sat half day), then Mon 09-08 .. Tue 09-09 of the next ISO week
    $exp = ['2036-09-01' => 8, '2036-09-02' => 8, '2036-09-03' => 8, '2036-09-04' => 8, '2036-09-05' => 8, '2036-09-06' => 4, '2036-09-07' => 0, '2036-09-08' => 8, '2036-09-09' => 8, '2036-08-31' => null];
    $worked = ['2036-09-01' => 12, '2036-09-02' => 6, '2036-09-03' => 8, '2036-09-04' => 8, '2036-09-05' => 8, '2036-09-06' => 4, '2036-09-07' => 3, '2036-09-08' => 10, '2036-08-31' => 9, '2036-10-01' => 7];
    $m = PayrollService::payable($exp, $worked, 'monthly');
    assert_eq(60.0, $m['expected']); assert_eq(59.0, $m['worked'], 'outside employment / period ignored');
    assert_eq(59.0, $m['payable']); assert_eq(0.0, $m['overtime']);
    $w = PayrollService::payable($exp, $worked, 'weekly');
    assert_eq(54.0, $w['payable'], 'week 1: 49 worked, 44 expected; week 2: 10 of 16'); assert_eq(5.0, $w['overtime']);
    $d = PayrollService::payable($exp, $worked, 'daily');
    assert_eq(50.0, $d['payable'], 'per day: 8+6+8+8+8+4+0+8+0'); assert_eq(9.0, $d['overtime'], '4 (Mon) + 3 (Sunday off) + 2 (next Mon)');
    $split = PayrollService::payable(['2036-09-29' => 8, '2036-09-30' => 8], ['2036-09-29' => 10, '2036-09-30' => 8], 'weekly');
    assert_eq(16.0, $split['payable'], 'a week cut by month end is capped at the expected hours of its days in the month'); assert_eq(2.0, $split['overtime']); },

  'monthly salary: rate = salary / expected hours of the month, pay = rate x payable hours; overtime and hourly pay' => function () {
    $c = ['pay_type' => 'monthly', 'amount' => 15700];
    $s = PayrollService::compute($c, 22, 0, 140, [], 0, null, ['expected' => 157, 'worked' => 140, 'payable' => 140, 'overtime' => 0, 'mode' => 'monthly']);
    assert_eq(14000.0, $s['base_pay'], '15,700 / 157 h = 100 per hour x 140 h'); assert_eq(100.0, $s['hour_rate']); assert_eq(1700.0, $s['missing_pay']);
    assert_eq(140.0, $s['payable_hours']); assert_eq(157.0, $s['expected_hours']);
    $s = PayrollService::compute($c, 22, 0, 140, [], 0, null, ['expected' => 154, 'worked' => 140, 'payable' => 140, 'overtime' => 0, 'mode' => 'monthly']);
    assert_eq(14272.73, $s['base_pay'], '154 expected hours: 101.95 per hour');
    $s = PayrollService::compute($c, 22, 0, 167, [], 0, null, ['expected' => 157, 'worked' => 167, 'payable' => 157, 'overtime' => 10, 'mode' => 'monthly', 'ot_multiplier' => 1.5]);
    assert_eq(15700.0, $s['base_pay'], 'never above the salary'); assert_eq(1500.0, $s['overtime_pay'], '10 h x 100 x 1.5'); assert_eq(17200.0, $s['gross']);
    assert_true(str_contains(json_encode($s['earnings'], JSON_UNESCAPED_UNICODE), 'Overtime (10 h × 1.5x)'), 'overtime earnings line');
    $s = PayrollService::compute($c, 22, 0, 167, [], 0, null, ['expected' => 157, 'worked' => 167, 'payable' => 157, 'overtime' => 10, 'mode' => 'monthly']);
    assert_eq(0.0, $s['overtime_pay'], 'overtime off: extra hours ignored'); assert_eq(15700.0, $s['gross']);
    $s = PayrollService::compute($c, 22, 0, 40, [], 0, null, ['expected' => 80, 'expected_full' => 160, 'worked' => 40, 'payable' => 40, 'overtime' => 0, 'mode' => 'monthly']);
    assert_eq(3925.0, $s['base_pay'], 'joiner: rate from the whole month (98.125), capped by the hours inside employment');
    $s = PayrollService::compute($c, 22, 0, 0, [], 0, null, ['expected' => 0, 'expected_full' => 0, 'scheduled_full' => 176, 'scheduled_in' => 176, 'worked' => 0, 'payable' => 0, 'overtime' => 0, 'mode' => 'monthly']);
    assert_eq(15700.0, $s['base_pay'], 'whole month on paid leave is paid in full');
    $hc = ['pay_type' => 'hourly', 'amount' => 200];
    $s = PayrollService::compute($hc, 0, 0, 45, [], 0, null, ['expected' => 40, 'worked' => 45, 'payable' => 40, 'overtime' => 5, 'mode' => 'daily', 'ot_multiplier' => 2.0]);
    assert_eq(8000.0, $s['base_pay']); assert_eq(2000.0, $s['overtime_pay']); assert_eq(200.0, $s['hour_rate']);
    assert_eq(9000.0, PayrollService::compute($hc, 0, 0, 45, [], 0)['base_pay'], 'without time tracking hourly pay is rate x all hours');
    $lines = array_column(PayrollService::hoursLines(['pay_type' => 'monthly', 'rate' => 15700, 'expected_hours' => 157, 'worked_hours' => 140, 'payable_hours' => 140, 'overtime_hours' => 0, 'overtime_pay' => 0, 'hour_rate' => 100, 'flex_mode' => 'weekly', 'base_pay' => 14000]), 1, 0);
    assert_eq('Flexible over the week', $lines['Flexible hours']); assert_eq('140 h', $lines['Payable hours']); assert_true(str_contains($lines['Deduction for missing hours'], '1,700'), 'missing hours deduction shown');
    assert_eq([], PayrollService::hoursLines(['pay_type' => 'monthly', 'expected_hours' => null]), 'no breakdown for day-based pay'); },

  'payroll run: expected hours with half Saturdays, holidays, paid vs unpaid leave and joining date; caps per flex mode' => function () {
    Settings::set('general.weekend_days', '7'); Settings::set('general.saturday_rule', 'half'); Settings::set('hr.work_hours_per_day', '8');
    Settings::set('payroll.salary_from_tracked_time', '1');
    DB::insert('holidays', ['holiday_date' => '2036-09-30', 'name' => 'Hours unit holiday', 'optional' => 0]);
    LeaveService::flush();
    $mk = static function (string $email, string $type, float $amount, string $joined = '2030-01-01'): int {
      $u = make_user('employee', $email, ['joined_on' => $joined]);
      DB::insert('compensations', ['user_id' => $u, 'pay_type' => $type, 'amount' => $amount, 'effective_from' => '2030-01-01', 'created_at' => date('Y-m-d H:i:s')]);
      return $u; };
    // September 2036: 22 weekdays x 8 h + 4 half Saturdays x 4 h = 192 h, minus the holiday on Tue 30th = 184 h
    $m = $mk('m@hoursunit.test', 'monthly', 18400);
    $p = $mk('p@hoursunit.test', 'monthly', 17400);
    $j = $mk('j@hoursunit.test', 'monthly', 18400, '2036-09-15');
    $h = $mk('h@hoursunit.test', 'hourly', 200);
    $paid = DB::insert('leave_types', ['name' => 'Hours unit paid', 'code' => 'HUP', 'paid' => 1]);
    $unpaid = DB::insert('leave_types', ['name' => 'Hours unit unpaid', 'code' => 'HUU', 'paid' => 0]);
    DB::insert('leave_requests', ['user_id' => $m, 'leave_type_id' => $unpaid, 'start_date' => '2036-09-03', 'end_date' => '2036-09-03', 'days' => 1, 'status' => 'approved', 'created_at' => date('Y-m-d H:i:s')]);
    DB::insert('leave_requests', ['user_id' => $p, 'leave_type_id' => $paid, 'start_date' => '2036-09-10', 'end_date' => '2036-09-10', 'days' => 1, 'status' => 'approved', 'created_at' => date('Y-m-d H:i:s')]);
    DB::insert('leave_requests', ['user_id' => $p, 'leave_type_id' => $paid, 'start_date' => '2036-09-13', 'end_date' => '2036-09-13', 'half_day' => 1, 'days' => 0.5, 'status' => 'approved', 'created_at' => date('Y-m-d H:i:s')]);
    $plan = PayrollService::hoursPlan([$m, $p, $j, $h], '2036-09-01', '2036-09-30');
    assert_eq(184.0, $plan[$m]['expected'], 'unpaid leave does not reduce expected hours');
    assert_eq(174.0, $plan[$p]['expected'], 'paid leave: 8 h weekday + half of a half Saturday (2 h)');
    assert_eq(96.0, $plan[$j]['expected'], 'joiner: only dates from the 15th'); assert_eq(184.0, $plan[$j]['expected_full']);
    assert_true($plan[$j]['days']['2036-09-14'] === null && $plan[$m]['days']['2036-09-06'] == 4.0 && $plan[$m]['days']['2036-09-30'] == 0.0, 'per-date plan');
    // M: week 1 = Mon 12, Tue 8, Thu 8, Fri 8, Sat 4 (40 of 44 expected); week 2 = Mon-Fri 10 each (50 of 44); a rejected 10 h day is ignored
    foreach (['2036-09-01' => 12, '2036-09-02' => 8, '2036-09-04' => 8, '2036-09-05' => 8, '2036-09-06' => 4, '2036-09-08' => 10, '2036-09-09' => 10, '2036-09-10' => 10, '2036-09-11' => 10, '2036-09-12' => 10] as $d => $x) { hp_time($m, $d, $x); }
    hp_time($m, '2036-09-15', 10, 'rejected');
    hp_time($p, '2036-09-01', 87); hp_time($p, '2036-09-02', 87);
    hp_time($j, '2036-09-02', 8); hp_time($j, '2036-09-15', 10); hp_time($j, '2036-09-16', 30);
    hp_time($h, '2036-09-01', 12); hp_time($h, '2036-09-02', 8);
    $run = DB::insert('payroll_runs', ['period_month' => '2036-09-01', 'status' => 'draft', 'created_at' => date('Y-m-d H:i:s')]);
    hp_settings('monthly');
    $s = hp_slips($run);
    assert_eq(9000.0, (float) $s[$m]['base_pay'], 'monthly flex: 90 h x 100'); assert_eq(100.0, (float) $s[$m]['hour_rate']); assert_eq(90.0, (float) $s[$m]['worked_hours'], 'rejected time excluded');
    assert_eq(1.0, (float) $s[$m]['unpaid_leave_days']); assert_eq('monthly', $s[$m]['flex_mode']);
    assert_eq(17400.0, (float) $s[$p]['base_pay'], 'paid leave lowers expected hours: 174 h worked = full salary');
    assert_eq(4000.0, (float) $s[$j]['base_pay'], 'joiner: 40 h after joining x 100 (time before joining ignored)');
    assert_eq(4000.0, (float) $s[$h]['base_pay'], 'hourly: 20 h x 200');
    hp_settings('weekly');
    $s = hp_slips($run);
    assert_eq(8400.0, (float) $s[$m]['base_pay'], 'weekly flex: 40 + min(50, 44) = 84 h'); assert_eq(84.0, (float) $s[$m]['payable_hours']);
    assert_true((float) $s[$m]['overtime_pay'] === 0.0 && (float) $s[$m]['overtime_hours'] === 0.0, 'overtime off: the 6 extra hours are not paid');
    assert_true(in_array(['Extra hours (overtime off, not paid)', '6 h'], PayrollService::hoursLines($s[$m]), true), 'extra hours shown on the payslip');
    hp_settings('weekly', 'manual:1.25');
    $s = hp_slips($run);
    assert_eq(750.0, (float) $s[$m]['overtime_pay'], '6 h x 100 x 1.25'); assert_eq(9150.0, (float) $s[$m]['gross']);
    hp_settings('daily');
    $s = hp_slips($run);
    assert_eq(7600.0, (float) $s[$m]['base_pay'], 'daily: 8+8+8+8+4 + 5 x 8 = 76 h');
    assert_eq(1600.0, (float) $s[$p]['base_pay'], 'daily: two long days capped at 8 h each');
    assert_eq(3200.0, (float) $s[$h]['base_pay'], 'hourly, daily cap: 8 + 8 h'); assert_eq(0.0, (float) $s[$h]['overtime_pay']);
    hp_settings('daily', '2');
    $s = hp_slips($run);
    assert_eq(2800.0, (float) $s[$m]['overtime_pay'], '14 h x 100 x 2'); assert_eq(14.0, (float) $s[$m]['overtime_hours']);
    assert_eq(1600.0, (float) $s[$h]['overtime_pay'], 'hourly: 4 h x 200 x 2'); assert_eq(4800.0, (float) $s[$h]['gross']);
    // payslip page and PDF show the breakdown
    act_as($GLOBALS['ADMIN_ID']);
    $html = (string) http('GET', '/payslips/'.$s[$m]['id'])->body();
    foreach (['Hours &amp; rate', 'Expected hours', 'Payable hours', 'Overtime hours', 'Hourly rate this month', 'Deduction for missing hours', 'Fixed daily hours'] as $t) { assert_true(str_contains($html, $t), "payslip page shows $t"); }
    assert_true(str_contains((string) http('GET', '/payroll/runs/'.$run)->body(), 'Pay from working hours'), 'run page explains the calculation');
    $row = DB::first('SELECT s.*, r.period_month, r.status AS run_status, u.name, u.email, u.employee_code, u.designation, u.department, ep.bank_name, ep.bank_ifsc FROM payslips s JOIN payroll_runs r ON r.id=s.run_id JOIN users u ON u.id=s.user_id LEFT JOIN employee_profiles ep ON ep.user_id=s.user_id WHERE s.id=?', [$s[$m]['id']]);
    $pdf = \Modules\Payroll\Services\PayslipPdf::render($row);
    preg_match_all('/stream\n(.*?)\nendstream/s', $pdf, $mm);
    $txt = implode('', array_map(static fn ($c) => (string) @gzuncompress($c), $mm[1]));
    assert_true(str_starts_with($pdf, '%PDF') && str_contains($txt, '(Hours & rate)') && str_contains($txt, '(Payable hours)') && str_contains($txt, '(Overtime pay)'), 'PDF has the hours box');
    DB::insert('compensations', ['user_id' => $m, 'pay_type' => 'monthly', 'amount' => 18400, 'effective_from' => '2020-01-01', 'created_at' => date('Y-m-d H:i:s')]);
    $emp = (string) http('GET', '/payroll/employees/'.$m)->body();
    assert_true(str_contains($emp, 'Pay calculation') && str_contains($emp, 'Monthly salary *') && str_contains($emp, 'Hourly rate *'), 'compensation page: labels and pay calculation');
    // salary from tracked time off: monthly salary by days (unpaid leave deducted), hourly still capped
    Settings::set('payroll.salary_from_tracked_time', '0');
    $s = hp_slips($run);
    assert_true($s[$m]['expected_hours'] === null && (float) $s[$m]['base_pay'] < 18400.0 && (float) $s[$m]['base_pay'] > 17000.0, 'off: prorated by days minus 1 unpaid day');
    assert_eq(3200.0, (float) $s[$h]['base_pay'], 'hourly keeps the cap');
    Settings::set('payroll.salary_from_tracked_time', '1');
    // attendance switched off: back to the previous behaviour (days for monthly, all approved hours for hourly)
    \Core\Features::set('feature:attendance', false);
    try { $s = hp_slips($run); assert_true($s[$m]['expected_hours'] === null && $s[$h]['expected_hours'] === null, 'no hours-based pay'); assert_eq(4000.0, (float) $s[$h]['base_pay'], 'hourly: all 20 h'); }
    finally { \Core\Features::set('feature:attendance', true); \Core\Gate::flush(); }
    hp_settings('monthly');
    Settings::set('general.weekend_days', '6,7'); Settings::set('general.saturday_rule', 'full'); LeaveService::flush(); },
  'payroll settings: flexible hours (monthly by default), overtime off by default with rate and manual multiplier' => function () {
    assert_eq('monthly', PayrollService::flexMode()); assert_eq(null, PayrollService::overtimeMultiplier());
    act_as($GLOBALS['ADMIN_ID']);
    $html = (string) http('GET', '/admin/settings/payroll')->body();
    foreach (['Calculate monthly salary from tracked time', 'Flexible hours: over the month (default)', 'Flexible hours: over the week', 'Off: fixed daily hours', 'Pay overtime for extra hours', 'data-when="s[payroll.overtime_enabled]"', 'data-when-value="manual"'] as $t) { assert_true(str_contains($html, $t), "settings page shows $t"); }
    assert_true(!str_contains((string) http('GET', '/admin/settings/attendance')->body(), 'payroll.salary_from_tracked_time'), 'toggle moved to the payroll tab');
    $post = ['s' => ['payroll.salary_from_tracked_time' => '1', 'payroll.flex_mode' => 'weekly', 'payroll.overtime_enabled' => '1', 'payroll.overtime_rate' => 'manual', 'payroll.overtime_manual' => '1.25', 'payroll.payslip_footer' => '']];
    http('POST', '/admin/settings/payroll', $post);
    assert_eq('weekly', PayrollService::flexMode()); assert_eq(1.25, PayrollService::overtimeMultiplier());
    http('POST', '/admin/settings/payroll', ['s' => ['payroll.flex_mode' => 'yearly'] + $post['s']]);
    assert_eq('weekly', PayrollService::flexMode(), 'invalid mode rejected');
    $post['s']['payroll.overtime_rate'] = '2'; http('POST', '/admin/settings/payroll', $post);
    assert_eq(2.0, PayrollService::overtimeMultiplier());
    hp_settings('monthly'); },
];
