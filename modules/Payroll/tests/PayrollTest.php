<?php
use Core\DB;
use Modules\Hr\Services\LeaveService;
use Modules\Payroll\Services\PayrollService;

return [
  'monthly salary is prorated on the employee\'s own working days' => function () {
    \Core\Settings::set('general.weekend_days', '6,7');
    $std = make_user('employee', 'std@payunit.test', ['joined_on' => '2030-01-01']);
    $six = make_user('employee', 'six@payunit.test', ['joined_on' => '2030-01-01']);
    DB::insert('employee_profiles', ['user_id' => $six, 'weekly_off_days' => '7']);
    LeaveService::flush();
    foreach ([$std, $six] as $u) { DB::insert('compensations', ['user_id' => $u, 'pay_type' => 'monthly', 'amount' => 30000, 'effective_from' => '2030-01-01', 'created_at' => date('Y-m-d H:i:s')]); }
    // June 2033: 30 days, 4 Sundays + 4 Saturdays → company 22 days, Sunday-only off → 26 days
    $wd = PayrollService::workingDaysFor([$std, $six], '2033-06-01', '2033-06-30');
    assert_eq(22.0, $wd[$std]); assert_eq(26.0, $wd[$six]);
    $id = DB::insert('payroll_runs', ['period_month' => '2033-06-01', 'status' => 'draft', 'created_at' => date('Y-m-d H:i:s')]);
    PayrollService::generate($id);
    $s = DB::pairs('SELECT user_id, working_days FROM payslips WHERE run_id=? AND user_id IN (?,?)', [$id, $std, $six]);
    assert_eq(22.0, (float) $s[$std]); assert_eq(26.0, (float) $s[$six]);
    PayrollService::$integrations = false;
    assert_eq(22.0, PayrollService::workingDaysFor([$six], '2033-06-01', '2033-06-30')[$six], 'fallback uses company calendar');
    PayrollService::$integrations = true; },
  'hourly payroll uses bulk approved hours' => function () {
    $u = make_user('employee', 'hourly@payunit.test');
    DB::insert('time_entries', ['user_id' => $u, 'started_at' => '2033-07-03 09:00:00', 'ended_at' => '2033-07-03 12:00:00', 'duration_seconds' => 10800, 'status' => 'approved', 'is_running' => 0, 'source' => 'manual']);
    assert_eq(3.0, PayrollService::approvedHours([$u], '2033-07-01', '2033-07-31')[$u]); },
];
