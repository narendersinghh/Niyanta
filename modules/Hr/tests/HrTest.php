<?php
use Core\{Crypto, DB, Modules};
use Modules\Hr\Services\{HrAccess, LeaveService};

/** Shared fixture: HR, a manager with one direct report, and an unrelated employee, with 2031 balances and a holiday. */
function hr_fixture(): array {
  static $f = null;
  if ($f) { return $f; }
  $hr = make_user('hr', 'hr@hrunit.test'); $mgr = make_user('manager', 'mgr@hrunit.test');
  $emp = make_user('employee', 'emp@hrunit.test', ['manager_id' => $mgr]); $other = make_user('employee', 'other@hrunit.test');
  DB::insert('holidays', ['holiday_date' => '2031-03-03', 'name' => 'Unit Holiday', 'optional' => 0]);
  DB::insert('holidays', ['holiday_date' => '2031-03-04', 'name' => 'Optional Day', 'optional' => 1]);
  \Core\Settings::set('general.weekend_days', '6,7');
  LeaveService::flush();
  LeaveService::allocateYear(2031);
  return $f = compact('hr', 'mgr', 'emp', 'other'); }

function hr_cl(): int { return (int) DB::value("SELECT id FROM leave_types WHERE code='CL'"); }

return [
  'working days skip weekends and non-optional holidays' => function () {
    hr_fixture();
    assert_eq(4.0, LeaveService::workingDays('2031-03-03', '2031-03-07'), 'Mon holiday excluded, optional counted');
    assert_true(LeaveService::isHoliday('2031-03-03') && !LeaveService::isHoliday('2031-03-04'), 'optional holiday is not a holiday');
    assert_eq(0.0, LeaveService::workingDays('2031-03-08', '2031-03-09'), 'weekend');
    assert_eq(0.5, LeaveService::requestDays('2031-03-05', '2031-03-05', true)); },
  'allocation is idempotent' => function () {
    hr_fixture();
    assert_eq(0, LeaveService::allocateYear(2031));
    assert_eq(12.0, (float) DB::value('SELECT allocated FROM leave_balances WHERE user_id=? AND leave_type_id=? AND year=2031', [hr_fixture()['emp'], hr_cl()])); },
  'leave request validates balance and overlaps' => function () {
    ['emp' => $emp] = hr_fixture();
    act_as($emp);
    http('POST', '/hr/leave', ['leave_type_id' => hr_cl(), 'start_date' => '2031-03-03', 'end_date' => '2031-03-07', 'reason' => 'Trip']);
    assert_eq(4.0, (float) DB::value("SELECT days FROM leave_requests WHERE user_id=? AND status='pending'", [$emp]));
    http('POST', '/hr/leave', ['leave_type_id' => hr_cl(), 'start_date' => '2031-03-06', 'end_date' => '2031-03-06']);
    assert_eq(1, (int) DB::value('SELECT COUNT(*) FROM leave_requests WHERE user_id=?', [$emp]), 'overlap rejected');
    http('POST', '/hr/leave', ['leave_type_id' => hr_cl(), 'start_date' => '2031-04-01', 'end_date' => '2031-04-30']);
    assert_eq(1, (int) DB::value('SELECT COUNT(*) FROM leave_requests WHERE user_id=?', [$emp]), 'insufficient balance rejected');
    $lop = (int) DB::value("SELECT id FROM leave_types WHERE paid=0 LIMIT 1");
    http('POST', '/hr/leave', ['leave_type_id' => $lop, 'start_date' => '2031-04-01', 'end_date' => '2031-04-30']);
    assert_eq(2, (int) DB::value('SELECT COUNT(*) FROM leave_requests WHERE user_id=?', [$emp]), 'unpaid allowed without balance'); },
  'approval is scoped to direct reports and never self' => function () {
    ['emp' => $emp, 'mgr' => $mgr, 'other' => $other, 'hr' => $hr] = hr_fixture();
    $id = (int) DB::value("SELECT id FROM leave_requests WHERE user_id=? AND leave_type_id=? AND status='pending'", [$emp, hr_cl()]);
    act_as($other);
    assert_eq(403, http('POST', "/hr/leave/$id/review", ['decision' => 'approve'])->status(), 'employee cannot approve');
    act_as($mgr);
    http('POST', '/hr/leave', ['leave_type_id' => hr_cl(), 'start_date' => '2031-05-05', 'end_date' => '2031-05-05']);
    $own = (int) DB::value("SELECT id FROM leave_requests WHERE user_id=? AND status='pending'", [$mgr]);
    assert_eq(403, http('POST', "/hr/leave/$own/review", ['decision' => 'approve'])->status(), 'no self approval');
    act_as($other);
    http('POST', '/hr/leave', ['leave_type_id' => hr_cl(), 'start_date' => '2031-05-06', 'end_date' => '2031-05-06']);
    $foreign = (int) DB::value("SELECT id FROM leave_requests WHERE user_id=? AND status='pending'", [$other]);
    act_as($mgr);
    assert_eq(403, http('POST', "/hr/leave/$foreign/review", ['decision' => 'approve'])->status(), 'not a direct report');
    assert_eq(302, http('POST', "/hr/leave/$id/review", ['decision' => 'approve', 'review_note' => 'ok'])->status());
    assert_eq('approved', DB::value('SELECT status FROM leave_requests WHERE id=?', [$id]));
    assert_eq(4.0, (float) DB::value('SELECT used FROM leave_balances WHERE user_id=? AND leave_type_id=? AND year=2031', [$emp, hr_cl()]));
    http('POST', "/hr/leave/$id/review", ['decision' => 'approve']);
    assert_eq(4.0, (float) DB::value('SELECT used FROM leave_balances WHERE user_id=? AND leave_type_id=? AND year=2031', [$emp, hr_cl()]), 'no double count');
    assert_true((bool) DB::value("SELECT 1 FROM notifications WHERE user_id=? AND title LIKE 'Your leave was approved%'", [$emp]), 'requester notified');
    act_as($hr);
    assert_eq(302, http('POST', "/hr/leave/$foreign/review", ['decision' => 'reject'])->status(), 'HR reviews anyone');
    assert_eq('rejected', DB::value('SELECT status FROM leave_requests WHERE id=?', [$foreign])); },
  'approved leave days are prorated across ranges' => function () {
    ['emp' => $emp] = hr_fixture();
    assert_eq(4.0, LeaveService::approvedLeaveDays($emp, '2031-03-01', '2031-03-31'));
    assert_eq(2.0, LeaveService::approvedLeaveDays($emp, '2031-03-01', '2031-03-05'));
    assert_eq(4.0, LeaveService::approvedLeaveDays($emp, '2031-03-01', '2031-03-31', true));
    assert_eq(0.0, LeaveService::approvedLeaveDays($emp, '2031-03-01', '2031-03-31', false));
    assert_true(LeaveService::onLeave($emp, '2031-03-05') && !LeaveService::onLeave($emp, '2031-03-10')); },
  'employees only see their own HR profile and documents' => function () {
    ['emp' => $emp, 'other' => $other, 'hr' => $hr] = hr_fixture();
    act_as($emp);
    assert_eq(200, http('GET', "/hr/employees/$emp")->status());
    assert_eq(403, http('GET', "/hr/employees/$other")->status());
    assert_eq(403, http('GET', "/hr/employees/$other/edit")->status());
    $cb = Modules::get('hr')['file_access']['employee'];
    $u = static fn (int $id) => DB::first('SELECT u.*, r.slug AS role_slug FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=?', [$id]);
    assert_true($cb($u($emp), $emp, [], 'view') && !$cb($u($emp), $other, [], 'view'), 'own docs only');
    assert_true(!$cb($u($emp), $emp, [], 'delete') && $cb($u($hr), $emp, [], 'delete'), 'only HR deletes'); },
  'bank and tax ids are encrypted and masked' => function () {
    ['emp' => $emp, 'hr' => $hr] = hr_fixture();
    act_as($hr);
    http('POST', "/hr/employees/$emp", ['employment_type' => 'full_time', 'bank_account' => '123456789012', 'tax_id' => 'ABCDE1234F']);
    $p = DB::first('SELECT * FROM employee_profiles WHERE user_id=?', [$emp]);
    assert_true(str_starts_with($p['bank_account_enc'], 'enc:') && !str_contains($p['bank_account_enc'], '9012'), 'encrypted');
    assert_eq('123456789012', Crypto::decrypt($p['bank_account_enc']));
    assert_eq('••••9012', HrAccess::mask($p['bank_account_enc']));
    act_as($emp);
    $body = http('GET', "/hr/employees/$emp")->body();
    assert_true(str_contains($body, '••••9012') && !str_contains($body, '123456789012'), 'masked for employee');
    assert_eq(403, http('POST', "/hr/employees/$emp/reveal")->status(), 'employee cannot reveal'); },
];
