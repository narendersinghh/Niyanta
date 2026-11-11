<?php
/** Leave corrections: approver edit (type, dates across a holiday, half day), revoke, employee cancellation requests, booking on behalf, filters, CSV export, access rules and ledger math. */
use Core\DB;
use Modules\Hr\Services\{LeaveChanges, LeaveService};

/* 2027: 1 March is a Monday; a fixed holiday is added on Wednesday 3 March for the tests and removed again. */
function lc_fx(): array {
  static $f = null;
  if ($f) { return $f; }
  $hr = make_user('hr', 'hr@lc.test', ['name' => 'Hema LcHR']);
  $mgr = make_user('manager', 'mgr@lc.test', ['name' => 'Manu LcMgr']);
  $other = make_user('manager', 'other@lc.test', ['name' => 'Omar LcOther']);
  $emp = make_user('employee', 'emp@lc.test', ['name' => 'Esha LcEmp', 'manager_id' => $mgr, 'joined_on' => '2024-01-01']);
  $emp2 = make_user('employee', 'emp2@lc.test', ['name' => 'Ravi LcEmp', 'manager_id' => $other, 'joined_on' => '2024-01-01']);
  $t = static fn (string $code, float $q, int $paid = 1, int $needs = 1) => DB::insert('leave_types', ['name' => 'Lc '.$code, 'code' => $code, 'annual_quota' => $q, 'allowance' => $paid ? 'yearly' : 'none', 'monthly_accrual' => 0, 'carry_forward' => 'none', 'paid' => $paid, 'color' => 'sky', 'active' => 1, 'allow_half_day' => 1, 'needs_approval' => $needs]);
  $a = $t('LCA', 10); $b = $t('LCB', 4); $u = $t('LCU', 0, 0); $n = $t('LCN', 5, 1, 0);
  foreach ([$emp, $emp2] as $x) { foreach ([$a => 10, $b => 4, $n => 5] as $tid => $q) { DB::query('INSERT INTO leave_balances (user_id, leave_type_id, year, allocated, used) VALUES (?,?,2027,?,0)', [$x, $tid, $q]); LeaveService::ledger($x, $tid, 2027, 'allocation', $q, '2027', null, 'test'); } }
  LeaveService::flush();
  return $f = compact('hr', 'mgr', 'other', 'emp', 'emp2', 'a', 'b', 'u', 'n'); }

function lc_bal(int $u, int $t): array { $b = DB::first('SELECT allocated, used FROM leave_balances WHERE user_id=? AND leave_type_id=? AND year=2027', [$u, $t]); return ['alloc' => (float) $b['allocated'], 'used' => (float) $b['used'], 'ledger' => (float) DB::value('SELECT COALESCE(SUM(days),0) FROM leave_ledger WHERE user_id=? AND leave_type_id=? AND year=2027', [$u, $t])]; }
/** Approved request booked the normal way (request + approval) so usage and ledger are real. */
function lc_approved(int $u, int $t, string $from, string $to, bool $half = false): int {
  $days = LeaveService::requestDaysFor($u, $from, $to, $half);
  $id = DB::insert('leave_requests', ['user_id' => $u, 'leave_type_id' => $t, 'start_date' => $from, 'end_date' => $to, 'half_day' => $half ? 1 : 0, 'days' => $days, 'status' => 'approved', 'reviewed_at' => date('Y-m-d H:i:s'), 'created_at' => date('Y-m-d H:i:s')]);
  LeaveService::recordUsage(DB::find('leave_requests', $id));
  return $id; }
function lc_errors(): array { return $_SESSION['_flash_next']['errors'] ?? []; }
function lc_post(string $path, array $post): Core\Response { unset($_SESSION['_flash_next'], $_SERVER['HTTP_X_REQUESTED_WITH'], $_SERVER['HTTP_ACCEPT']); return http('POST', $path, $post); }
function lc_clean(array $ids): void { DB::query('DELETE FROM leave_requests WHERE id IN '.DB::in($ids), $ids); }
function lc_reset(int $u): void { DB::query('DELETE FROM leave_requests WHERE user_id=?', [$u]); DB::query("DELETE FROM leave_ledger WHERE user_id=? AND year=2027 AND kind<>'allocation'", [$u]); DB::query('UPDATE leave_balances SET used=0 WHERE user_id=? AND year=2027', [$u]); }

return [
  'edit approved leave: change type returns days to the old type and charges the new one, with history, audit and notification' => function () {
    ['hr' => $hr, 'emp' => $emp, 'a' => $a, 'b' => $b] = lc_fx(); lc_reset($emp);
    $id = lc_approved($emp, $a, '2027-03-08', '2027-03-10');
    assert_eq(3.0, lc_bal($emp, $a)['used']);
    act_as($hr);
    assert_eq(302, lc_post("/hr/leave/$id/edit", ['leave_type_id' => $b, 'start_date' => '2027-03-08', 'end_date' => '2027-03-10', 'reason' => 'Fever', 'note' => 'Approved as LCA by mistake'])->status());
    assert_eq([], lc_errors(), 'no validation errors');
    $lr = DB::find('leave_requests', $id);
    assert_eq('approved', $lr['status']); assert_eq($b, (int) $lr['leave_type_id']); assert_eq('Fever', $lr['reason']);
    $ba = lc_bal($emp, $a); $bb = lc_bal($emp, $b);
    assert_eq(0.0, $ba['used'], 'days returned to the old type'); assert_eq(3.0, $bb['used'], 'charged to the new type');
    assert_eq($ba['alloc'] - $ba['used'], $ba['ledger'], 'old type ledger matches balance'); assert_eq($bb['alloc'] - $bb['used'], $bb['ledger'], 'new type ledger matches balance');
    assert_eq(3.0, (float) DB::value("SELECT days FROM leave_ledger WHERE user_id=? AND leave_type_id=? AND kind='reversal' AND ref_id=?", [$emp, $a, $id]), 'reversal entry');
    assert_eq(-3.0, (float) DB::value("SELECT days FROM leave_ledger WHERE user_id=? AND leave_type_id=? AND kind='usage' AND ref_id=?", [$emp, $b, $id]), 'new usage entry');
    $h = LeaveChanges::history([$id])[$id];
    assert_eq('edit', $h[0]['action']); assert_eq((int) $hr, (int) $h[0]['actor_id']); assert_eq(['Lc LCA', 'Lc LCB'], $h[0]['changes']['Type']); assert_eq('Approved as LCA by mistake', $h[0]['note']);
    assert_true((bool) DB::value("SELECT 1 FROM audit_logs WHERE action='hr.leave.edit' AND entity_id=?", [$id]), 'audit');
    assert_true((bool) DB::value("SELECT 1 FROM notifications WHERE user_id=? AND title='Your leave was edited' AND body LIKE '%Type: Lc LCA to Lc LCB%'", [$emp]), 'employee notified');
    act_as($emp);
    assert_true(str_contains((string) http('GET', '/hr/leave')->body(), 'Edited by Hema LcHR'), 'history visible to the employee'); },
  'edit dates across a fixed holiday and weekend: days recounted by the existing day logic, ledger stays exact' => function () {
    ['hr' => $hr, 'emp' => $emp, 'a' => $a] = lc_fx(); lc_reset($emp);
    $hol = DB::insert('holidays', ['holiday_date' => '2027-03-03', 'name' => 'Lc Test Day', 'optional' => 0]); LeaveService::flush();
    try {
      $id = lc_approved($emp, $a, '2027-03-01', '2027-03-02');
      act_as($hr);
      lc_post("/hr/leave/$id/edit", ['leave_type_id' => $a, 'start_date' => '2027-03-01', 'end_date' => '2027-03-08', 'note' => 'Extended']);
      assert_eq([], lc_errors());
      assert_eq(5.0, (float) DB::value('SELECT days FROM leave_requests WHERE id=?', [$id]), 'Mon-Mon minus holiday and weekend = 5');
      $bal = lc_bal($emp, $a); assert_eq(5.0, $bal['used']); assert_eq($bal['alloc'] - $bal['used'], $bal['ledger']);
      lc_post("/hr/leave/$id/edit", ['leave_type_id' => $a, 'start_date' => '2027-03-04', 'end_date' => '2027-03-04', 'half_day' => 1, 'note' => 'Only half a day']);
      assert_eq(0.5, (float) DB::value('SELECT days FROM leave_requests WHERE id=?', [$id]));
      $bal = lc_bal($emp, $a); assert_eq(0.5, $bal['used']); assert_eq($bal['alloc'] - $bal['used'], $bal['ledger'], 'ledger after second edit');
      lc_post("/hr/leave/$id/edit", ['leave_type_id' => $a, 'start_date' => '2027-03-06', 'end_date' => '2027-03-07', 'note' => 'Weekend']);
      assert_true(isset(lc_errors()['start_date']), 'weekend only refused');
      lc_post("/hr/leave/$id/edit", ['leave_type_id' => $a, 'start_date' => '2027-03-04', 'end_date' => '2027-03-04', 'half_day' => 1, 'note' => 'Same']);
      assert_true(str_contains(lc_errors()['start_date'] ?? '', 'Nothing changed'), 'no-op edit refused');
      lc_post("/hr/leave/$id/edit", ['leave_type_id' => $a, 'start_date' => '2027-03-04', 'end_date' => '2027-03-05', 'note' => '']);
      assert_true(isset(lc_errors()['note']), 'note required');
      lc_post("/hr/leave/$id/edit", ['leave_type_id' => $a, 'start_date' => '2027-03-04', 'end_date' => '2027-03-25', 'note' => 'Too long']);
      assert_true(str_contains(lc_errors()['leave_type_id'] ?? '', 'Insufficient'), 'balance respected: '.json_encode(lc_errors()));
      assert_eq(0.5, lc_bal($emp, $a)['used'], 'failed edit changed nothing');
      $other = lc_approved($emp, $a, '2027-03-15', '2027-03-15');
      lc_post("/hr/leave/$id/edit", ['leave_type_id' => $a, 'start_date' => '2027-03-12', 'end_date' => '2027-03-15', 'note' => 'Overlap']);
      assert_true(str_contains(lc_errors()['start_date'] ?? '', 'Overlaps'), 'overlap refused'); }
    finally { DB::delete('holidays', 'id=?', [$hol]); LeaveService::flush(); } },
  'edit pending request: balance check counts its own pending days once' => function () {
    ['mgr' => $mgr, 'emp' => $emp, 'b' => $b] = lc_fx(); lc_reset($emp);
    $id = DB::insert('leave_requests', ['user_id' => $emp, 'leave_type_id' => $b, 'start_date' => '2027-04-05', 'end_date' => '2027-04-08', 'half_day' => 0, 'days' => 4, 'status' => 'pending', 'created_at' => date('Y-m-d H:i:s')]);
    act_as($mgr);
    lc_post("/hr/leave/$id/edit", ['leave_type_id' => $b, 'start_date' => '2027-04-06', 'end_date' => '2027-04-09', 'note' => 'Shifted a day']);
    assert_eq([], lc_errors(), 'all 4 days of LCB still fit');
    assert_eq('2027-04-09', DB::value('SELECT end_date FROM leave_requests WHERE id=?', [$id]));
    assert_eq(0.0, lc_bal($emp, $b)['used'], 'pending edits do not touch used');
    lc_post("/hr/leave/$id/edit", ['leave_type_id' => $b, 'start_date' => '2027-04-06', 'end_date' => '2027-04-12', 'note' => 'Longer']);
    assert_true(str_contains(lc_errors()['leave_type_id'] ?? '', 'Insufficient'), 'over the balance'); },
  'access: managers correct only their direct reports, never their own leave; employees cannot edit or revoke' => function () {
    ['mgr' => $mgr, 'other' => $other, 'emp' => $emp, 'emp2' => $emp2, 'a' => $a] = lc_fx(); lc_reset($emp); lc_reset($emp2); lc_reset($mgr);
    $mine = lc_approved($emp, $a, '2027-05-03', '2027-05-03'); $foreign = lc_approved($emp2, $a, '2027-05-03', '2027-05-03');
    DB::query('INSERT IGNORE INTO leave_balances (user_id, leave_type_id, year, allocated, used) VALUES (?,?,2027,5,0)', [$mgr, $a]);
    $own = lc_approved($mgr, $a, '2027-05-04', '2027-05-04');
    act_as($mgr);
    assert_eq(403, lc_post("/hr/leave/$foreign/edit", ['leave_type_id' => $a, 'start_date' => '2027-05-04', 'note' => 'x'])->status(), 'not a direct report');
    assert_eq(403, lc_post("/hr/leave/$foreign/revoke", ['note' => 'x'])->status());
    assert_eq(403, lc_post("/hr/leave/$own/revoke", ['note' => 'x'])->status(), 'no self correction');
    assert_eq(302, lc_post("/hr/leave/$mine/revoke", ['note' => 'Project deadline'])->status());
    act_as($emp);
    assert_eq(403, lc_post("/hr/leave/$foreign/edit", ['leave_type_id' => $a, 'start_date' => '2027-05-04', 'note' => 'x'])->status(), 'employees have no approve permission');
    assert_eq(403, lc_post('/hr/leave/on-behalf', ['user_id' => $emp2, 'leave_type_id' => $a, 'start_date' => '2027-05-10'])->status());
    assert_eq(403, http('GET', '/hr/leave/approvals/export')->status());
    act_as($other);
    assert_eq(302, lc_post("/hr/leave/$foreign/edit", ['leave_type_id' => $a, 'start_date' => '2027-05-05', 'note' => 'Moved'])->status());
    assert_eq([], lc_errors());
    lc_clean([$own]); DB::query('UPDATE leave_balances SET used=0 WHERE user_id=? AND year=2027', [$mgr]); },
  'revoke approved leave: note required, status cancelled, days back with a reversal entry' => function () {
    ['hr' => $hr, 'emp' => $emp, 'a' => $a] = lc_fx(); lc_reset($emp);
    $id = lc_approved($emp, $a, '2027-06-07', '2027-06-08');
    act_as($hr);
    lc_post("/hr/leave/$id/revoke", ['note' => '']);
    assert_true(isset(lc_errors()['note']), 'note required');
    assert_eq('approved', DB::value('SELECT status FROM leave_requests WHERE id=?', [$id]));
    lc_post("/hr/leave/$id/revoke", ['note' => 'Office closed that week anyway']);
    assert_eq('cancelled', DB::value('SELECT status FROM leave_requests WHERE id=?', [$id]));
    $bal = lc_bal($emp, $a); assert_eq(0.0, $bal['used']); assert_eq($bal['alloc'], $bal['ledger'], 'ledger nets back');
    assert_eq('revoke', DB::value('SELECT action FROM leave_request_events WHERE request_id=? ORDER BY id DESC LIMIT 1', [$id]));
    assert_true((bool) DB::value("SELECT 1 FROM notifications WHERE user_id=? AND title='Your approved leave was revoked'", [$emp]));
    lc_post("/hr/leave/$id/revoke", ['note' => 'again']);
    assert_true(isset(lc_errors()['note']), 'cannot revoke twice');
    assert_eq(0.0, lc_bal($emp, $a)['used'], 'not returned twice'); },
  'employee: cancel own pending, ask to cancel approved future leave, approver confirms or declines' => function () {
    ['mgr' => $mgr, 'emp' => $emp, 'emp2' => $emp2, 'a' => $a, 'n' => $n] = lc_fx(); lc_reset($emp);
    $p = DB::insert('leave_requests', ['user_id' => $emp, 'leave_type_id' => $a, 'start_date' => '2027-07-05', 'end_date' => '2027-07-05', 'half_day' => 0, 'days' => 1, 'status' => 'pending', 'created_at' => date('Y-m-d H:i:s')]);
    act_as($emp2);
    assert_eq(403, lc_post("/hr/leave/$p/cancel", [])->status(), 'not their request');
    act_as($emp);
    lc_post("/hr/leave/$p/cancel", []);
    assert_eq('cancelled', DB::value('SELECT status FROM leave_requests WHERE id=?', [$p]));
    $ap = lc_approved($emp, $a, '2027-07-12', '2027-07-13');
    lc_post("/hr/leave/$ap/cancel", []);
    assert_eq('approved', DB::value('SELECT status FROM leave_requests WHERE id=?', [$ap]), 'approved leave needs the approver');
    lc_post("/hr/leave/$ap/cancel-request", ['cancel_reason' => '']);
    assert_eq(null, DB::value('SELECT cancel_requested_at FROM leave_requests WHERE id=?', [$ap]), 'reason required');
    lc_post("/hr/leave/$ap/cancel-request", ['cancel_reason' => 'Trip postponed']);
    assert_true((bool) DB::value('SELECT cancel_requested_at FROM leave_requests WHERE id=?', [$ap]));
    assert_true((bool) DB::value("SELECT 1 FROM notifications WHERE user_id=? AND title LIKE 'Leave cancellation request from%'", [$mgr]), 'approver notified');
    act_as($mgr);
    assert_true(str_contains((string) http('GET', '/hr/leave/approvals?status=cancel')->body(), 'Trip postponed'), 'listed under cancel requests');
    lc_post("/hr/leave/$ap/cancellation", ['decision' => 'decline', 'note' => 'Release is that week']);
    assert_eq('approved', DB::value('SELECT status FROM leave_requests WHERE id=?', [$ap])); assert_eq(null, DB::value('SELECT cancel_requested_at FROM leave_requests WHERE id=?', [$ap]));
    assert_eq(2.0, lc_bal($emp, $a)['used'], 'declined keeps the days used');
    act_as($emp); lc_post("/hr/leave/$ap/cancel-request", ['cancel_reason' => 'Please, it really moved']);
    act_as($mgr); lc_post("/hr/leave/$ap/cancellation", ['decision' => 'approve']);
    assert_eq('cancelled', DB::value('SELECT status FROM leave_requests WHERE id=?', [$ap]));
    $bal = lc_bal($emp, $a); assert_eq(0.0, $bal['used'], 'days returned'); assert_eq($bal['alloc'], $bal['ledger']);
    assert_eq(['cancel_request', 'cancel_decline', 'cancel_request', 'cancel_approve'], array_column(LeaveChanges::history([$ap])[$ap], 'action'));
    $started = lc_approved($emp, $a, date('Y-m-d'), date('Y-m-d'));
    act_as($emp); lc_post("/hr/leave/$started/cancel-request", ['cancel_reason' => 'x']);
    assert_eq(null, DB::value('SELECT cancel_requested_at FROM leave_requests WHERE id=?', [$started]), 'started leave cannot be cancelled by the employee');
    $auto = lc_approved($emp, $n, '2027-07-20', '2027-07-20');
    lc_post("/hr/leave/$auto/cancel", []);
    assert_eq('cancelled', DB::value('SELECT status FROM leave_requests WHERE id=?', [$auto]), 'no-approval types cancel directly before they start');
    assert_eq(0.0, lc_bal($emp, $n)['used']);
    lc_clean([$started]); },
  'apply on behalf: approver books approved leave for their people only, balance and ledger charged' => function () {
    ['hr' => $hr, 'mgr' => $mgr, 'emp' => $emp, 'emp2' => $emp2, 'a' => $a, 'b' => $b, 'u' => $u] = lc_fx(); lc_reset($emp); lc_reset($emp2);
    act_as($mgr);
    lc_post('/hr/leave/on-behalf', ['user_id' => $emp2, 'leave_type_id' => $a, 'start_date' => '2027-08-02']);
    assert_true(isset(lc_errors()['user_id']), 'not a direct report');
    lc_post('/hr/leave/on-behalf', ['user_id' => $mgr, 'leave_type_id' => $a, 'start_date' => '2027-08-02']);
    assert_true(isset(lc_errors()['user_id']), 'not for themselves');
    lc_post('/hr/leave/on-behalf', ['user_id' => $emp, 'leave_type_id' => $a, 'start_date' => '2027-08-02', 'end_date' => '2027-08-04', 'reason' => 'Called in sick', 'note' => 'Get well soon']);
    assert_eq([], lc_errors());
    $lr = DB::first('SELECT * FROM leave_requests WHERE user_id=? ORDER BY id DESC LIMIT 1', [$emp]);
    assert_eq('approved', $lr['status']); assert_eq(3.0, (float) $lr['days']); assert_eq($mgr, (int) $lr['created_by']); assert_eq($mgr, (int) $lr['reviewed_by']);
    $bal = lc_bal($emp, $a); assert_eq(3.0, $bal['used']); assert_eq($bal['alloc'] - 3, $bal['ledger']);
    assert_eq('on_behalf', DB::value('SELECT action FROM leave_request_events WHERE request_id=?', [$lr['id']]));
    assert_true((bool) DB::value("SELECT 1 FROM notifications WHERE user_id=? AND title='Leave booked for you'", [$emp]));
    lc_post('/hr/leave/on-behalf', ['user_id' => $emp, 'leave_type_id' => $a, 'start_date' => '2027-08-03']);
    assert_true(str_contains(lc_errors()['start_date'] ?? '', 'Overlaps'), 'overlap');
    lc_post('/hr/leave/on-behalf', ['user_id' => $emp, 'leave_type_id' => $b, 'start_date' => '2027-08-09', 'end_date' => '2027-08-13']);
    assert_true(str_contains(lc_errors()['leave_type_id'] ?? '', 'Insufficient'), 'balance: 5 days of LCB (4)');
    act_as($hr);
    lc_post('/hr/leave/on-behalf', ['user_id' => $emp2, 'leave_type_id' => $u, 'start_date' => '2027-08-09', 'end_date' => '2027-08-13']);
    assert_eq([], lc_errors(), 'HR books anyone; unpaid needs no balance');
    assert_eq(5.0, (float) DB::value('SELECT days FROM leave_requests WHERE user_id=? AND leave_type_id=?', [$emp2, $u])); },
  'approvals list filters by person, type, status and dates; CSV export follows the filters and the approver scope' => function () {
    ['hr' => $hr, 'mgr' => $mgr, 'emp' => $emp, 'emp2' => $emp2, 'a' => $a, 'b' => $b] = lc_fx(); lc_reset($emp); lc_reset($emp2);
    $x = lc_approved($emp, $a, '2027-09-06', '2027-09-06'); $y = lc_approved($emp, $b, '2027-10-04', '2027-10-04'); $z = lc_approved($emp2, $a, '2027-09-07', '2027-09-07');
    act_as($hr);
    $body = (string) http('GET', "/hr/leave/approvals?status=approved&type=$a&from=2027-09-01&to=2027-09-30")->body();
    assert_true(str_contains($body, '<b class="small">Esha LcEmp</b>') && str_contains($body, '<b class="small">Ravi LcEmp</b>'), 'both September LCA rows');
    $body = (string) http('GET', "/hr/leave/approvals?status=approved&user=$emp&type=$b")->body();
    assert_true(str_contains($body, '<b class="small">Esha LcEmp</b>') && !str_contains($body, '<b class="small">Ravi LcEmp</b>'), 'person + type filter');
    $csv = http('GET', "/hr/leave/approvals/export?status=approved&type=$a&from=2027-09-01&to=2027-09-30");
    assert_eq(200, $csv->status());
    $lines = array_values(array_filter(explode("\n", trim((string) $csv->body()))));
    assert_eq(3, count($lines), 'header + 2 rows');
    assert_true(str_contains($lines[0], 'Employee') && str_contains((string) $csv->body(), 'Lc LCA'));
    act_as($mgr);
    $csv = (string) http('GET', '/hr/leave/approvals/export?status=all')->body();
    assert_true(str_contains($csv, 'Esha LcEmp') && !str_contains($csv, 'Ravi LcEmp'), 'manager export only covers direct reports');
    lc_clean([$x, $y, $z]); lc_reset($emp); lc_reset($emp2); },
  'preview counts days on the employee calendar only for approvers of that employee' => function () {
    ['mgr' => $mgr, 'emp' => $emp, 'emp2' => $emp2] = lc_fx();
    DB::query('INSERT INTO employee_profiles (user_id, weekly_off_days) VALUES (?, ?) ON DUPLICATE KEY UPDATE weekly_off_days=VALUES(weekly_off_days)', [$emp, '5,6,7']); LeaveService::flush();
    act_as($mgr);
    assert_eq(4, (int) json_decode((string) http('GET', "/hr/leave/preview?from=2027-03-08&to=2027-03-12&user=$emp")->body(), true)['days'], 'Fri off for the employee');
    assert_eq(5, (int) json_decode((string) http('GET', "/hr/leave/preview?from=2027-03-08&to=2027-03-12&user=$emp2")->body(), true)['days'], 'not their report: own calendar');
    DB::query('UPDATE employee_profiles SET weekly_off_days=NULL WHERE user_id=?', [$emp]); LeaveService::flush(); },
];
