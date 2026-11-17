<?php
/** Leave approvals end to end: reject needs a reason, notes reach the employee, bulk approve, request detail and timeline, team overlap, balance preview, sidebar badge, reminders, holiday recount, who is out. */
use Core\{DB, Settings};
use Modules\Hr\Services\{LeaveChanges, LeaveService};

/* 2029: 1 January is a Monday. Two managers, two reports of the first (same department), one of the second, HR. */
function la_fx(): array {
  static $f = null;
  if ($f) { return $f; }
  $hr = make_user('hr', 'hr@la.test', ['name' => 'Hari LaHR']);
  $mgr = make_user('manager', 'mgr@la.test', ['name' => 'Mona LaMgr', 'department' => 'Studio']);
  $mgr2 = make_user('manager', 'mgr2@la.test', ['name' => 'Milo LaMgr']);
  $e1 = make_user('employee', 'e1@la.test', ['name' => 'Ella LaEmp', 'manager_id' => $mgr, 'department' => 'Studio', 'designation' => 'Designer', 'joined_on' => '2024-01-01']);
  $e2 = make_user('employee', 'e2@la.test', ['name' => 'Eric LaEmp', 'manager_id' => $mgr, 'department' => 'Studio', 'joined_on' => '2024-01-01']);
  $e3 = make_user('employee', 'e3@la.test', ['name' => 'Ezra LaEmp', 'manager_id' => $mgr2, 'joined_on' => '2024-01-01']);
  $t = DB::insert('leave_types', ['name' => 'La Paid', 'code' => 'LAP', 'annual_quota' => 10, 'allowance' => 'yearly', 'monthly_accrual' => 0, 'carry_forward' => 'none', 'paid' => 1, 'color' => 'mint', 'active' => 1, 'allow_half_day' => 1, 'needs_approval' => 1]);
  foreach ([$e1, $e2, $e3, $mgr] as $u) { DB::query('INSERT INTO leave_balances (user_id, leave_type_id, year, allocated, used) VALUES (?,?,2029,10,0)', [$u, $t]); LeaveService::ledger($u, $t, 2029, 'allocation', 10, '2029', null, 'test'); }
  LeaveService::flush();
  return $f = compact('hr', 'mgr', 'mgr2', 'e1', 'e2', 'e3', 't'); }

function la_req(int $u, int $t, string $from, string $to, string $created = '-1 hour', string $reason = 'Family trip'): int {
  return DB::insert('leave_requests', ['user_id' => $u, 'leave_type_id' => $t, 'start_date' => $from, 'end_date' => $to, 'half_day' => 0, 'days' => LeaveService::requestDaysFor($u, $from, $to, false), 'reason' => $reason, 'status' => 'pending', 'created_at' => date('Y-m-d H:i:s', strtotime($created))]); }
function la_post(string $path, array $post): Core\Response { unset($_SESSION['_flash_next']); return http('POST', $path, $post); }
function la_flash(): string { return json_encode($_SESSION['_flash_next'] ?? []); }
function la_clean(): void { $f = la_fx(); $ids = [$f['e1'], $f['e2'], $f['e3'], $f['mgr']]; DB::query('DELETE FROM leave_requests WHERE user_id IN '.DB::in($ids), $ids); DB::query("DELETE FROM leave_ledger WHERE kind<>'allocation' AND user_id IN ".DB::in($ids), $ids); DB::query('UPDATE leave_balances SET used=0 WHERE user_id IN '.DB::in($ids), $ids); DB::query('DELETE FROM notifications WHERE user_id IN '.DB::in([...$ids, $f['hr'], $f['mgr2']]), [...$ids, $f['hr'], $f['mgr2']]); }

return [
  'reject needs a reason; approve and reject notes reach the employee, the history and the audit log' => function () {
    ['mgr' => $mgr, 'e1' => $e1, 't' => $t] = la_fx(); la_clean();
    $a = la_req($e1, $t, '2029-01-08', '2029-01-10'); $b = la_req($e1, $t, '2029-02-05', '2029-02-05');
    act_as($mgr);
    la_post("/hr/leave/$b/review", ['decision' => 'reject', 'review_note' => '  ']);
    assert_eq('pending', DB::value('SELECT status FROM leave_requests WHERE id=?', [$b]), 'reject without a reason is refused');
    assert_true(str_contains(la_flash(), 'reason for rejecting'), 'told why');
    la_post("/hr/leave/$b/review", ['decision' => 'reject', 'review_note' => 'Client launch that day']);
    assert_eq('rejected', DB::value('SELECT status FROM leave_requests WHERE id=?', [$b]));
    la_post("/hr/leave/$a/review", ['decision' => 'approve', 'review_note' => 'Enjoy']);
    assert_eq('approved', DB::value('SELECT status FROM leave_requests WHERE id=?', [$a]));
    assert_eq(3.0, (float) DB::value('SELECT used FROM leave_balances WHERE user_id=? AND leave_type_id=? AND year=2029', [$e1, $t]), 'charged');
    $h = LeaveChanges::history([$a, $b]);
    assert_eq('approve', $h[$a][0]['action']); assert_eq('Enjoy', $h[$a][0]['note']); assert_eq((int) $mgr, (int) $h[$a][0]['actor_id']);
    assert_eq('reject', $h[$b][0]['action']); assert_eq('Client launch that day', $h[$b][0]['note']);
    assert_true((bool) DB::value("SELECT 1 FROM audit_logs WHERE action='hr.leave.reject' AND entity_id=? AND meta LIKE '%Client launch%'", [$b]), 'audit with the note');
    $n = DB::first("SELECT body, url FROM notifications WHERE user_id=? AND title='Your leave was rejected'", [$e1]);
    assert_true(str_contains($n['body'], 'Reason: Client launch that day'), 'reason in the notification'); assert_eq("/hr/leave/requests/$b", $n['url'], 'notification opens the request');
    assert_true((bool) DB::value("SELECT 1 FROM notifications WHERE user_id=? AND title='Your leave was approved' AND body LIKE '%Note: Enjoy%'", [$e1]), 'approve note sent');
    act_as($e1);
    $body = (string) http('GET', '/hr/leave')->body();
    assert_true(str_contains($body, 'Client launch that day') && str_contains($body, 'Rejected by Mona LaMgr'), 'employee sees the reason in My leave');
    la_clean(); },
  'bulk approve only touches pending requests the approver may decide; bulk reject needs a reason' => function () {
    ['mgr' => $mgr, 'e1' => $e1, 'e2' => $e2, 'e3' => $e3, 't' => $t] = la_fx(); la_clean();
    $x = la_req($e1, $t, '2029-03-05', '2029-03-06'); $y = la_req($e2, $t, '2029-03-07', '2029-03-07'); $z = la_req($e3, $t, '2029-03-08', '2029-03-08'); $own = la_req($mgr, $t, '2029-03-09', '2029-03-09');
    act_as($mgr);
    la_post('/hr/leave/bulk-review', ['decision' => 'reject', 'ids' => [$x, $y]]);
    assert_eq(2, (int) DB::value("SELECT COUNT(*) FROM leave_requests WHERE id IN (?,?) AND status='pending'", [$x, $y]), 'bulk reject without reason refused');
    la_post('/hr/leave/bulk-review', ['decision' => 'approve', 'ids' => [$x, $y, $z, $own], 'review_note' => 'Approved in one go']);
    assert_eq(2, (int) DB::value("SELECT COUNT(*) FROM leave_requests WHERE id IN (?,?) AND status='approved'", [$x, $y]), 'direct reports approved');
    assert_eq('pending', DB::value('SELECT status FROM leave_requests WHERE id=?', [$z]), 'not a direct report: skipped');
    assert_eq('pending', DB::value('SELECT status FROM leave_requests WHERE id=?', [$own]), 'own leave: skipped');
    assert_true(str_contains(la_flash(), 'Approved 2 requests') && str_contains(la_flash(), '2 skipped'), 'summary');
    assert_eq(2, (int) DB::value("SELECT COUNT(*) FROM leave_request_events WHERE request_id IN (?,?) AND action='approve' AND note='Approved in one go'", [$x, $y]), 'history per request');
    assert_true((bool) DB::value("SELECT 1 FROM audit_logs WHERE action='hr.leave.bulk_approve'"), 'bulk audited');
    la_clean(); },
  'request detail: owner, their approvers and HR only, with the full timeline and team overlap' => function () {
    ['hr' => $hr, 'mgr' => $mgr, 'mgr2' => $mgr2, 'e1' => $e1, 'e2' => $e2, 'e3' => $e3, 't' => $t] = la_fx(); la_clean();
    $a = la_req($e1, $t, '2029-04-02', '2029-04-04', '-3 days', 'Cousin wedding'); $o = la_req($e2, $t, '2029-04-03', '2029-04-03');
    act_as($mgr);
    la_post("/hr/leave/$a/review", ['decision' => 'approve', 'review_note' => 'Have fun']);
    act_as($e1);
    la_post("/hr/leave/$a/cancel-request", ['cancel_reason' => 'Wedding moved']);
    act_as($mgr);
    la_post("/hr/leave/$a/cancellation", ['decision' => 'decline', 'note' => 'Sorry, needed']);
    foreach ([[$e1, 200], [$mgr, 200], [$hr, 200], [$mgr2, 403], [$e2, 403], [$e3, 403]] as [$who, $code]) { act_as($who); assert_eq($code, http('GET', "/hr/leave/requests/$a")->status(), "detail for user $who"); }
    act_as($e1);
    $body = (string) http('GET', "/hr/leave/requests/$a")->body();
    foreach (['Requested by Ella LaEmp', 'Cousin wedding', 'Approved by Mona LaMgr', 'Have fun', 'Cancellation requested by Ella LaEmp', 'Wedding moved', 'Cancellation declined by Mona LaMgr', 'Sorry, needed'] as $s) { assert_true(str_contains($body, $s), "timeline shows: $s"); }
    assert_true(!str_contains($body, 'Team out on these dates'), 'employees do not see team overlap');
    act_as($mgr);
    $body = (string) http('GET', "/hr/leave/requests/$o")->body();
    assert_true(str_contains($body, 'Team out on these dates') && str_contains($body, 'Ella LaEmp'), 'approver sees who else is out');
    $list = (string) http('GET', '/hr/leave/approvals')->body();
    assert_true(str_contains($list, 'Also out: Ella LaEmp'), 'overlap on the approvals list');
    assert_true(str_contains($list, '10 left, 9 after approval'), 'balance after approval');
    la_clean(); },
  'timeline rebuilds decisions made before they were recorded as events' => function () {
    ['mgr' => $mgr, 'e1' => $e1, 't' => $t] = la_fx(); la_clean();
    $id = DB::insert('leave_requests', ['user_id' => $e1, 'leave_type_id' => $t, 'start_date' => '2029-05-07', 'end_date' => '2029-05-07', 'half_day' => 0, 'days' => 1, 'status' => 'rejected', 'reviewed_by' => $mgr, 'reviewed_at' => '2029-01-02 10:00:00', 'review_note' => 'Old style note', 'created_at' => '2029-01-01 09:00:00']);
    $lr = DB::first('SELECT lr.*, u.name, r.name AS reviewer FROM leave_requests lr JOIN users u ON u.id=lr.user_id LEFT JOIN users r ON r.id=lr.reviewed_by WHERE lr.id=?', [$id]);
    $tl = LeaveChanges::timeline($lr, []);
    assert_eq(['apply', 'reject'], array_column($tl, 'action')); assert_eq('Old style note', $tl[1]['note']); assert_eq('Mona LaMgr', $tl[1]['actor']);
    la_clean(); },
  'employee withdraws a pending request: approvers are told; preview shows the balance after the request' => function () {
    ['mgr' => $mgr, 'e1' => $e1, 't' => $t] = la_fx(); la_clean();
    $a = la_req($e1, $t, '2029-06-04', '2029-06-05');
    act_as($e1);
    $j = json_decode((string) http('GET', "/hr/leave/preview?from=2029-06-11&to=2029-06-15&type=$t")->body(), true);
    assert_eq(5.0, (float) $j['days']); assert_true(str_contains($j['balance'], '8 day(s) available') && str_contains($j['balance'], '3 left after this'), 'balance after pending: '.$j['balance']); assert_true(!$j['warn']);
    $j = json_decode((string) http('GET', "/hr/leave/preview?from=2029-06-04&to=2029-06-04&type=$t")->body(), true);
    assert_true($j['warn'] && str_contains($j['balance'], 'Overlaps your pending leave'), 'overlap warned');
    $j = json_decode((string) http('GET', "/hr/leave/preview?from=2029-07-02&to=2029-07-20&type=$t")->body(), true);
    assert_true($j['warn'] && str_contains($j['balance'], 'not enough'), 'short balance warned');
    la_post("/hr/leave/$a/cancel", []);
    assert_eq('cancelled', DB::value('SELECT status FROM leave_requests WHERE id=?', [$a]));
    assert_true((bool) DB::value("SELECT 1 FROM notifications WHERE user_id=? AND title LIKE '%withdrew a leave request'", [$mgr]), 'manager told');
    assert_eq('withdraw', LeaveChanges::history([$a])[$a][0]['action']);
    la_clean(); },
  'approvers get a sidebar entry with the pending count and a dashboard widget; employees do not' => function () {
    ['mgr' => $mgr, 'e1' => $e1, 't' => $t] = la_fx(); la_clean();
    la_req($e1, $t, '2029-08-06', '2029-08-06'); la_req($e1, $t, '2029-08-08', '2029-08-08');
    act_as($mgr);
    $body = (string) http('GET', '/hr/leave')->body();
    assert_true((bool) preg_match('#href="[^"]*/hr/leave/approvals"[^>]*>.*?Leave approvals <span class="nav-badge"[^>]*>2</span>#s', $body), 'sidebar entry with badge for a manager who also requests leave');
    $dash = (string) http('GET', '/')->body();
    assert_true(str_contains($dash, 'Leave requests waiting') && str_contains($dash, 'Ella LaEmp'), 'dashboard widget lists who applied');
    act_as($e1);
    assert_true(!str_contains((string) http('GET', '/hr/leave')->body(), 'Leave approvals'), 'no approvals entry for employees');
    la_clean(); },
  'reminders: one digest per approver for requests waiting N days, off at 0' => function () {
    ['hr' => $hr, 'mgr' => $mgr, 'mgr2' => $mgr2, 'e1' => $e1, 'e3' => $e3, 't' => $t] = la_fx(); la_clean();
    $old = la_req($e1, $t, '2029-09-03', '2029-09-03', '-5 days'); la_req($e1, $t, '2029-09-05', '2029-09-05', '-1 hour'); la_req($e3, $t, '2029-09-04', '2029-09-04', '-4 days');
    Settings::set('hr.leave.reminder_days', '0');
    assert_eq('Reminders are off', LeaveChanges::remindPending());
    Settings::set('hr.leave.reminder_days', '3');
    $r = LeaveChanges::remindPending();
    assert_true(str_contains($r, '2 request(s)'), $r);
    $n = DB::first('SELECT title, body, url FROM notifications WHERE user_id=? ORDER BY id DESC LIMIT 1', [$mgr]);
    assert_eq('A leave request is waiting for your review', $n['title']); assert_true(str_contains($n['body'], 'Ella LaEmp') && !str_contains($n['body'], 'Ezra'), 'manager: own reports only'); assert_eq("/hr/leave/requests/$old", $n['url']);
    assert_true(str_contains((string) DB::value('SELECT title FROM notifications WHERE user_id=? ORDER BY id DESC LIMIT 1', [$hr]), '2 leave requests'), 'HR gets everyone');
    assert_true((bool) DB::value('SELECT 1 FROM notifications WHERE user_id=?', [$mgr2]), 'other manager reminded about their report');
    Settings::set('hr.leave.reminder_days', '2');
    la_clean(); },
  'adding or removing a fixed holiday recounts pending and approved leave, balance and ledger stay exact' => function () {
    ['hr' => $hr, 'mgr' => $mgr, 'e1' => $e1, 'e2' => $e2, 't' => $t] = la_fx(); la_clean();
    $a = la_req($e1, $t, '2029-10-08', '2029-10-12'); $p = la_req($e2, $t, '2029-10-10', '2029-10-10');
    act_as($mgr); la_post("/hr/leave/$a/review", ['decision' => 'approve']);
    assert_eq(5.0, (float) DB::value('SELECT used FROM leave_balances WHERE user_id=? AND leave_type_id=? AND year=2029', [$e1, $t]));
    act_as($hr);
    la_post('/hr/holidays', ['holiday_date' => '2029-10-10', 'name' => 'La Festival', 'optional' => 0]);
    $hid = (int) DB::value("SELECT id FROM holidays WHERE name='La Festival'");
    assert_eq(4.0, (float) DB::value('SELECT days FROM leave_requests WHERE id=?', [$a]), 'approved leave recounted');
    assert_eq(4.0, (float) DB::value('SELECT used FROM leave_balances WHERE user_id=? AND leave_type_id=? AND year=2029', [$e1, $t]), 'a day returned');
    assert_eq(6.0, (float) DB::value('SELECT SUM(days) FROM leave_ledger WHERE user_id=? AND leave_type_id=? AND year=2029', [$e1, $t]), 'ledger matches allocated - used');
    assert_eq('cancelled', DB::value('SELECT status FROM leave_requests WHERE id=?', [$p]), 'pending leave only on the holiday is cancelled');
    assert_true((bool) DB::value("SELECT 1 FROM notifications WHERE user_id=? AND title='Your leave days were recounted'", [$e1]), 'employee told');
    assert_true(str_contains(implode(' ', array_column(LeaveChanges::history([$a])[$a], 'note')), 'Fixed holiday added: La Festival'), 'history says why');
    la_post("/hr/holidays/$hid/delete", []);
    assert_eq(5.0, (float) DB::value('SELECT days FROM leave_requests WHERE id=?', [$a]), 'holiday removed: day counted again');
    assert_eq(5.0, (float) DB::value('SELECT used FROM leave_balances WHERE user_id=? AND leave_type_id=? AND year=2029', [$e1, $t]));
    assert_eq(5.0, (float) DB::value('SELECT SUM(days) FROM leave_ledger WHERE user_id=? AND leave_type_id=? AND year=2029', [$e1, $t]));
    LeaveService::flush(); la_clean(); },
  'who is out: today and this week for everyone, team filter and pending requests for approvers' => function () {
    ['mgr' => $mgr, 'e1' => $e1, 'e3' => $e3, 't' => $t] = la_fx(); la_clean();
    $p = la_req($e1, $t, date('Y-m-d', strtotime('+1 day')), date('Y-m-d', strtotime('+1 day')));
    act_as($e3);
    $body = (string) http('GET', '/hr/leave/calendar')->body();
    assert_true(str_contains($body, 'Out today') && str_contains($body, 'This week'), 'who is out panels');
    assert_true(!str_contains($body, "/hr/leave/requests/$p"), 'employees do not see other people\'s pending requests');
    act_as($mgr);
    $body = (string) http('GET', '/hr/leave/calendar?scope=team&month='.date('Y-m', strtotime('+1 day')))->body();
    assert_true(str_contains($body, 'My team') && str_contains($body, "/hr/leave/requests/$p"), 'approver sees pending of their reports, linked');
    la_clean(); },
];
