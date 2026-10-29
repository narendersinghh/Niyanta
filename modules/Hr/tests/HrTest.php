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

function hr_oh(): int { return (int) DB::value("SELECT id FROM leave_types WHERE code='OH' AND active=1"); }

return [
  'working days skip weekends and non-optional holidays' => function () {
    hr_fixture();
    assert_eq(4.0, LeaveService::workingDays('2031-03-03', '2031-03-07'), 'Mon holiday excluded, optional counted');
    assert_true(LeaveService::isHoliday('2031-03-03') && !LeaveService::isHoliday('2031-03-04'), 'optional holiday is not a holiday');
    assert_eq(0.0, LeaveService::workingDays('2031-03-08', '2031-03-09'), 'weekend');
    assert_eq(0.5, LeaveService::requestDays('2031-03-05', '2031-03-05', true)); },
  'simplified holiday model: optional pool active, legacy types inactive' => function () {
    hr_fixture();
    assert_true(hr_oh() > 0, 'OH type exists and is active');
    assert_eq(0, (int) DB::value("SELECT COUNT(*) FROM leave_types WHERE code IN ('CL','SL','EL') AND active=1"), 'unused CL/SL/EL deactivated');
    assert_eq(15.0, (float) DB::value('SELECT annual_quota FROM leave_types WHERE id=?', [hr_oh()])); },
  'allocation is idempotent' => function () {
    hr_fixture();
    assert_eq(0, LeaveService::allocateYear(2031));
    assert_eq(15.0, (float) DB::value('SELECT allocated FROM leave_balances WHERE user_id=? AND leave_type_id=? AND year=2031', [hr_fixture()['emp'], hr_oh()])); },
  'leave request validates balance and overlaps' => function () {
    ['emp' => $emp] = hr_fixture();
    act_as($emp);
    http('POST', '/hr/leave', ['leave_type_id' => hr_oh(), 'start_date' => '2031-03-03', 'end_date' => '2031-03-07', 'reason' => 'Trip']);
    assert_eq(4.0, (float) DB::value("SELECT days FROM leave_requests WHERE user_id=? AND status='pending'", [$emp]));
    http('POST', '/hr/leave', ['leave_type_id' => hr_oh(), 'start_date' => '2031-03-06', 'end_date' => '2031-03-06']);
    assert_eq(1, (int) DB::value('SELECT COUNT(*) FROM leave_requests WHERE user_id=?', [$emp]), 'overlap rejected');
    http('POST', '/hr/leave', ['leave_type_id' => hr_oh(), 'start_date' => '2031-04-01', 'end_date' => '2031-04-30']);
    assert_eq(1, (int) DB::value('SELECT COUNT(*) FROM leave_requests WHERE user_id=?', [$emp]), 'insufficient balance rejected');
    $lop = (int) DB::value("SELECT id FROM leave_types WHERE paid=0 LIMIT 1");
    http('POST', '/hr/leave', ['leave_type_id' => $lop, 'start_date' => '2031-04-01', 'end_date' => '2031-04-30']);
    assert_eq(2, (int) DB::value('SELECT COUNT(*) FROM leave_requests WHERE user_id=?', [$emp]), 'unpaid allowed without balance'); },
  'approval is scoped to direct reports and never self' => function () {
    ['emp' => $emp, 'mgr' => $mgr, 'other' => $other, 'hr' => $hr] = hr_fixture();
    $id = (int) DB::value("SELECT id FROM leave_requests WHERE user_id=? AND leave_type_id=? AND status='pending'", [$emp, hr_oh()]);
    act_as($other);
    assert_eq(403, http('POST', "/hr/leave/$id/review", ['decision' => 'approve'])->status(), 'employee cannot approve');
    act_as($mgr);
    http('POST', '/hr/leave', ['leave_type_id' => hr_oh(), 'start_date' => '2031-05-05', 'end_date' => '2031-05-05']);
    $own = (int) DB::value("SELECT id FROM leave_requests WHERE user_id=? AND status='pending'", [$mgr]);
    assert_eq(403, http('POST', "/hr/leave/$own/review", ['decision' => 'approve'])->status(), 'no self approval');
    act_as($other);
    http('POST', '/hr/leave', ['leave_type_id' => hr_oh(), 'start_date' => '2031-05-06', 'end_date' => '2031-05-06']);
    $foreign = (int) DB::value("SELECT id FROM leave_requests WHERE user_id=? AND status='pending'", [$other]);
    act_as($mgr);
    assert_eq(403, http('POST', "/hr/leave/$foreign/review", ['decision' => 'approve'])->status(), 'not a direct report');
    assert_eq(302, http('POST', "/hr/leave/$id/review", ['decision' => 'approve', 'review_note' => 'ok'])->status());
    assert_eq('approved', DB::value('SELECT status FROM leave_requests WHERE id=?', [$id]));
    assert_eq(4.0, (float) DB::value('SELECT used FROM leave_balances WHERE user_id=? AND leave_type_id=? AND year=2031', [$emp, hr_oh()]));
    http('POST', "/hr/leave/$id/review", ['decision' => 'approve']);
    assert_eq(4.0, (float) DB::value('SELECT used FROM leave_balances WHERE user_id=? AND leave_type_id=? AND year=2031', [$emp, hr_oh()]), 'no double count');
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
  'per-employee off days drive working days and request days' => function () {
    ['other' => $other, 'emp' => $emp] = hr_fixture();
    DB::query('INSERT INTO employee_profiles (user_id, weekly_off_days, work_hours_per_day) VALUES (?,?,?) ON DUPLICATE KEY UPDATE weekly_off_days=VALUES(weekly_off_days), work_hours_per_day=VALUES(work_hours_per_day)', [$other, '5,6', 6]);
    LeaveService::flush();
    // 2031-03-03 Mon (fixed holiday) … 2031-03-09 Sun: company calendar 4 days, Fri+Sat off → Tue,Wed,Thu,Sun = 4
    assert_eq(4.0, LeaveService::workingDaysFor($other, '2031-03-03', '2031-03-09'), 'own off days + fixed holiday');
    assert_eq(4.0, LeaveService::workingDaysFor($emp, '2031-03-03', '2031-03-09'), 'company default');
    assert_eq(1.0, LeaveService::workingDaysFor($other, '2031-03-09', '2031-03-09'), 'Sunday is a working day for a Fri/Sat off employee');
    assert_eq(0.0, LeaveService::workingDaysFor($emp, '2031-03-09', '2031-03-09'));
    assert_eq(0.0, LeaveService::requestDaysFor($other, '2031-03-07', '2031-03-08', false), 'Fri+Sat off');
    $s = LeaveService::scheduleFor($other);
    assert_true($s['custom_days'] && $s['custom_hours'] && $s['off_days'] === [5, 6] && $s['hours_per_day'] == 6.0, 'schedule override');
    $d = LeaveService::scheduleFor($emp);
    assert_true(!$d['custom_days'] && $d['off_days'] === [6, 7] && $d['hours_per_day'] == 8.0, 'defaults');
    assert_eq([$other => 4.0, $emp => 4.0], LeaveService::workingDaysMany([$other, $emp], '2031-03-03', '2031-03-09'));
    if (class_exists(\Modules\Attendance\Services\AttendanceService::class)) {
      assert_eq(6 * 3600, \Modules\Attendance\Services\AttendanceService::workSeconds($other), 'attendance target uses own hours');
      assert_eq(8 * 3600, \Modules\Attendance\Services\AttendanceService::workSeconds($emp));
      assert_eq([5, 6], \Modules\Attendance\Services\AttendanceService::offDays($other)); }
    act_as($other);
    http('POST', '/hr/leave', ['leave_type_id' => hr_oh(), 'start_date' => '2031-03-09', 'end_date' => '2031-03-09']);
    assert_eq(1.0, (float) DB::value("SELECT days FROM leave_requests WHERE user_id=? AND start_date='2031-03-09'", [$other]), 'Sunday counted with own schedule');
    DB::delete('leave_requests', "user_id=? AND start_date='2031-03-09'", [$other]); },
  'per-employee quota overrides are used by allocateYear and the HR schedule form' => function () {
    ['emp' => $emp, 'other' => $other, 'hr' => $hr] = hr_fixture();
    DB::insert('employee_leave_quotas', ['user_id' => $other, 'leave_type_id' => hr_oh(), 'annual_quota' => 20]);
    LeaveService::allocateYear(2032);
    assert_eq(20.0, (float) DB::value('SELECT allocated FROM leave_balances WHERE user_id=? AND leave_type_id=? AND year=2032', [$other, hr_oh()]), 'override');
    assert_eq(15.0, (float) DB::value('SELECT allocated FROM leave_balances WHERE user_id=? AND leave_type_id=? AND year=2032', [$emp, hr_oh()]), 'default');
    act_as($emp);
    assert_eq(403, http('POST', "/hr/employees/$emp/schedule", ['hours_mode' => 'custom', 'work_hours_per_day' => 4])->status(), 'employees cannot edit schedules');
    act_as($hr);
    $y = (int) date('Y');
    LeaveService::allocateYear($y);
    $before = (float) DB::value('SELECT allocated FROM leave_balances WHERE user_id=? AND leave_type_id=? AND year=?', [$emp, hr_oh(), $y]);
    assert_eq(302, http('POST', "/hr/employees/$emp/schedule", ['hours_mode' => 'custom', 'work_hours_per_day' => '7.5', 'days_mode' => 'custom', 'off_days' => ['7'], 'quota' => [hr_oh() => '18']])->status());
    LeaveService::flush();
    $s = LeaveService::scheduleFor($emp);
    assert_true($s['hours_per_day'] == 7.5 && $s['off_days'] === [7], 'schedule saved');
    assert_eq(18.0, (float) DB::value('SELECT annual_quota FROM employee_leave_quotas WHERE user_id=? AND leave_type_id=?', [$emp, hr_oh()]));
    assert_eq($before + 3, (float) DB::value('SELECT allocated FROM leave_balances WHERE user_id=? AND leave_type_id=? AND year=?', [$emp, hr_oh(), $y]), 'current-year balance follows the override');
    http('POST', "/hr/employees/$emp/schedule", ['hours_mode' => 'default', 'days_mode' => 'default', 'quota' => [hr_oh() => '']]);
    LeaveService::flush();
    assert_true(!LeaveService::scheduleFor($emp)['custom_days'] && !DB::value('SELECT 1 FROM employee_leave_quotas WHERE user_id=?', [$emp]), 'reset to defaults');
    assert_eq($before, (float) DB::value('SELECT allocated FROM leave_balances WHERE user_id=? AND leave_type_id=? AND year=?', [$emp, hr_oh(), $y])); },
  'optional holiday default setting keeps the OH quota in sync' => function () {
    ['hr' => $hr, 'emp' => $emp] = hr_fixture();
    act_as($emp);
    assert_eq(403, http('POST', '/hr/leave/optional-quota', ['quota' => 30])->status());
    act_as($hr);
    assert_eq(302, http('POST', '/hr/leave/optional-quota', ['quota' => 12])->status());
    assert_eq(12.0, (float) DB::value('SELECT annual_quota FROM leave_types WHERE id=?', [hr_oh()]));
    assert_eq('12', \Core\Settings::get('hr.optional_holidays_default'));
    http('POST', '/hr/leave/optional-quota', ['quota' => 15]); },
  'company documents: HR publishes, everyone reads and acknowledges' => function () {
    ['hr' => $hr, 'emp' => $emp] = hr_fixture();
    $id = DB::insert('company_documents', ['title' => 'Leave policy', 'category' => 'policy', 'requires_ack' => 1, 'created_by' => $hr, 'created_at' => date('Y-m-d H:i:s')]);
    act_as($emp);
    assert_eq(200, http('GET', '/hr/documents')->status());
    assert_true(str_contains(http('GET', '/hr/documents')->body(), 'Leave policy'));
    assert_eq(403, http('POST', "/hr/documents/$id/delete")->status(), 'employees cannot delete');
    assert_eq(403, http('POST', '/hr/documents', ['title' => 'X', 'category' => 'policy'])->status(), 'employees cannot publish');
    assert_eq(1, count(\Modules\Hr\Controllers\CompanyDocController::pendingFor($emp)), 'awaiting acknowledgement');
    http('POST', "/hr/documents/$id/ack");
    http('POST', "/hr/documents/$id/ack");
    assert_eq(1, (int) DB::value('SELECT COUNT(*) FROM company_document_acks WHERE document_id=? AND user_id=?', [$id, $emp]), 'idempotent');
    assert_eq(0, count(\Modules\Hr\Controllers\CompanyDocController::pendingFor($emp)));
    $cb = Modules::get('hr')['file_access']['company_doc'];
    $u = static fn (int $uid) => DB::first('SELECT u.*, r.slug AS role_slug FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=?', [$uid]);
    assert_true($cb($u($emp), $id, [], 'view') && !$cb($u($emp), $id, [], 'delete') && $cb($u($hr), $id, [], 'delete'), 'file access');
    act_as($hr);
    assert_eq(302, http('POST', "/hr/documents/$id", ['title' => 'Leave policy 2031', 'category' => 'handbook', 'requires_ack' => 1])->status());
    assert_eq('handbook', DB::value('SELECT category FROM company_documents WHERE id=?', [$id]));
    assert_eq(302, http('POST', "/hr/documents/$id/delete")->status());
    assert_true(!DB::value('SELECT 1 FROM company_documents WHERE id=?', [$id]), 'deleted'); },
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
