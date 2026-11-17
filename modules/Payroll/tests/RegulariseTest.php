<?php
use Core\{DB, Settings};
use Modules\Payroll\Services\PayrollService;

/** Time regularisation: up to N minutes of a monthly shortage count as worked (company setting, 90 by default; per salary revision: custom or off). */
return [
  'regularise: a shortage within the limit is paid in full, a bigger one is reduced by the limit, paid leave covers the rest' => function () {
    $hx = static fn (float $p) => ['expected' => 160.0, 'worked' => $p, 'payable' => $p, 'overtime' => 0.0, 'mode' => 'monthly'];
    $calc = static fn (float $p, float $g) => ['comp' => ['pay_type' => 'monthly', 'amount' => 16000], 'wd' => 20, 'unpaid' => 0, 'hours' => $p, 'components' => [], 'reimb' => 0, 'hx' => $hx($p), 'grace' => $g];
    $s = PayrollService::computeSlip($calc(158.75, 1.5));
    assert_eq([160.0, 1.25, 16000.0], [(float) $s['payable_hours'], (float) $s['regularised_hours'], (float) $s['base_pay']], 'within 90 min: full pay');
    assert_true(str_contains($s['earnings'][0]['name'], '1.25 h regularised'), 'shown on the payslip line');
    $s = PayrollService::computeSlip($calc(150.0, 1.5));
    assert_eq([151.5, 1.5, 15150.0], [(float) $s['payable_hours'], (float) $s['regularised_hours'], (float) $s['base_pay']], 'bigger shortage reduced by 90 min');
    $seen = null; $cover = static function (float $short) use (&$seen) { $seen = $short; return ['days' => 1, 'hours' => $short, 'type_id' => 1, 'balance_after' => 3]; };
    $s = PayrollService::computeSlip($calc(150.0, 1.5), [], $cover);
    assert_eq([8.5, 160.0], [$seen, (float) $s['payable_hours']], 'paid leave covers what is left after regularising');
    assert_eq(0.0, (float) PayrollService::computeSlip($calc(150.0, 0.0))['regularised_hours'], 'off');
    assert_eq(0.0, (float) PayrollService::computeSlip($calc(150.0, 1.5), ['hours' => ['payable' => 152.0]])['regularised_hours'], 'HR payable override wins');
    $h = PayrollService::computeSlip(['comp' => ['pay_type' => 'hourly', 'amount' => 100], 'wd' => 0, 'unpaid' => 0, 'hours' => 150, 'components' => [], 'reimb' => 0, 'hx' => $hx(150.0), 'grace' => 1.5]);
    assert_eq(0.0, (float) $h['regularised_hours'], 'never for hourly pay'); },

  'regularise: company default 90 min; stored once per employee from the salary form (custom or off), audit-logged, used when generating' => function () {
    Settings::set('payroll.grace_minutes', '');
    assert_eq(90, PayrollService::graceMinutes(null), 'default');
    Settings::set('payroll.grace_minutes', '60');
    assert_eq([60, 30, 0], [PayrollService::graceMinutes(null), PayrollService::graceMinutes(30), PayrollService::graceMinutes(0)], 'company, custom, off');
    Settings::set('payroll.grace_minutes', '90');
    $hr = make_user('hr', 'hr@regunit.test', ['name' => 'Zz Reg HR']); $emp = make_user('employee', 'emp@regunit.test', ['name' => 'Zz Reg Emp']);
    act_as($hr);
    http('POST', '/payroll/employees/'.$emp.'/compensation', ['pay_type' => 'monthly', 'amount' => '20000', 'effective_from' => date('Y-m-01', strtotime('-1 month')), 'grace_mode' => 'custom', 'grace_minutes' => '45']);
    assert_eq(45, PayrollService::graceSetting($emp), 'saved for the employee');
    assert_true(str_contains(http('GET', '/payroll/employees/'.$emp)->body(), 'Time regularisation'), 'shown on the employee page');
    http('POST', '/payroll/employees/'.$emp.'/compensation', ['pay_type' => 'monthly', 'amount' => '21000', 'effective_from' => date('Y-m-01'), 'grace_mode' => 'off']);
    assert_eq(0, PayrollService::graceSetting($emp), 'turned off with the next salary edit');
    assert_eq(2, (int) DB::value('SELECT COUNT(*) FROM compensations WHERE user_id=?', [$emp]), 'one setting, two revisions');
    assert_true((bool) DB::value("SELECT 1 FROM audit_logs WHERE action='payroll.grace.update' AND entity_id=? LIMIT 1", [$emp]), 'audit');
    http('POST', '/payroll/employees/'.$emp.'/compensation', ['pay_type' => 'monthly', 'amount' => '22000', 'effective_from' => date('Y-m-02'), 'grace_mode' => 'custom']);
    assert_eq(2, (int) DB::value('SELECT COUNT(*) FROM compensations WHERE user_id=?', [$emp]), 'nothing saved on a validation error');
    PayrollService::setGrace($emp, null);
    assert_true(PayrollService::graceSetting($emp) === null, 'back to the company setting');
    act_as($emp);
    assert_eq(403, http('POST', '/payroll/employees/'.$emp.'/compensation', ['pay_type' => 'monthly', 'amount' => '1', 'effective_from' => date('Y-m-01'), 'grace_mode' => 'off'])->status(), 'employees cannot change it'); },
];
