<?php
use Core\DB;
use Modules\Attendance\Services\{Timesheets, TimeService};

/** Fixture: HR, a manager with one direct report, a project member of the manager's project, and an unrelated employee. */
function att_fixture(): array {
  static $f = null;
  if ($f) { return $f; }
  $hr = make_user('hr', 'hr@attunit.test'); $mgr = make_user('manager', 'mgr@attunit.test');
  $emp = make_user('employee', 'emp@attunit.test', ['manager_id' => $mgr]);
  $member = make_user('employee', 'member@attunit.test');
  $other = make_user('employee', 'other@attunit.test');
  $pid = DB::insert('projects', ['name' => 'Unit Project', 'status' => 'active', 'manager_id' => $mgr, 'created_at' => date('Y-m-d H:i:s')]);
  DB::insert('project_members', ['project_id' => $pid, 'user_id' => $member]);
  return $f = compact('hr', 'mgr', 'emp', 'member', 'other', 'pid'); }

/** Insert a finished entry for a user directly (like an old/agent entry) and sync its week. */
function att_entry(int $uid, string $start, int $sec, string $status = 'approved'): int {
  $id = DB::insert('time_entries', ['user_id' => $uid, 'started_at' => $start, 'ended_at' => date('Y-m-d H:i:s', strtotime($start) + $sec), 'duration_seconds' => $sec, 'status' => $status, 'is_running' => 0, 'source' => 'manual', 'billable' => 1, 'created_at' => date('Y-m-d H:i:s')]);
  Timesheets::sync($uid, TimeService::weekStart(substr($start, 0, 10)));
  return $id; }

function att_user(int $id): array { return DB::first('SELECT u.*, r.slug AS role_slug FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=?', [$id]); }

return [
  'stopping a timer yields an approved entry and an approved week' => function () {
    ['emp' => $emp] = att_fixture();
    act_as($emp);
    $id = TimeService::start(att_user($emp), ['description' => 'Unit work']);
    assert_eq('draft', DB::value('SELECT status FROM time_entries WHERE id=?', [$id]), 'running entry is not yet counted');
    DB::query('UPDATE time_entries SET started_at=DATE_SUB(NOW(), INTERVAL 2 HOUR) WHERE id=?', [$id]);
    $r = TimeService::stop($emp);
    assert_true($r && $r['duration'] >= 7190, 'stopped with duration');
    assert_eq('approved', DB::value('SELECT status FROM time_entries WHERE id=?', [$id]));
    $ws = TimeService::weekStart(substr((string) DB::value('SELECT started_at FROM time_entries WHERE id=?', [$id]), 0, 10));
    $t = Timesheets::find($emp, $ws);
    assert_true($t && $t['status'] === 'approved', 'week row created as approved');
    assert_true((int) $t['total_seconds'] >= 7190, 'week totals synced');
    assert_eq((int) $t['id'], (int) DB::value('SELECT timesheet_id FROM time_entries WHERE id=?', [$id]), 'entry linked to week'); },
  'manual entries via HTTP are approved and old weeks are closed' => function () {
    ['emp' => $emp] = att_fixture();
    act_as($emp);
    $d = date('Y-m-d', strtotime('-1 day'));
    http('POST', '/time/entries', ['date' => $d, 'start_time' => '01:00', 'duration' => '1:30', 'billable' => '1']);
    assert_eq('approved', DB::value("SELECT status FROM time_entries WHERE user_id=? AND started_at=?", [$emp, $d.' 01:00:00']));
    $old = date('Y-m-d', strtotime('-40 days'));
    http('POST', '/time/entries', ['date' => $old, 'start_time' => '01:00', 'duration' => '1:00']);
    assert_eq(0, (int) DB::value('SELECT COUNT(*) FROM time_entries WHERE user_id=? AND started_at=?', [$emp, $old.' 01:00:00']), 'closed week rejects new time'); },
  'rejecting a week rejects its entries and approvedHours drops; resubmit restores' => function () {
    ['emp' => $emp, 'mgr' => $mgr] = att_fixture();
    $ws = '2033-03-07';
    att_entry($emp, '2033-03-08 09:00:00', 3 * 3600);
    att_entry($emp, '2033-03-09 09:00:00', 2 * 3600);
    assert_eq(5.0, TimeService::approvedHours($emp, '2033-03-01', '2033-03-31'));
    $t = Timesheets::find($emp, $ws);
    act_as($mgr);
    $res = http('POST', '/timesheets/'.$t['id'].'/reject', ['note' => 'Wednesday is wrong']);
    assert_true(in_array($res->status(), [302, 303], true), 'reject redirects: '.$res->status());
    assert_eq('rejected', DB::value('SELECT status FROM timesheets WHERE id=?', [$t['id']]));
    assert_eq(2, (int) DB::value("SELECT COUNT(*) FROM time_entries WHERE timesheet_id=? AND status='rejected'", [$t['id']]));
    assert_eq(0.0, TimeService::approvedHours($emp, '2033-03-01', '2033-03-31'), 'rejected hours excluded');
    assert_eq(0.0, TimeService::approvedHoursMany([$emp], '2033-03-01', '2033-03-31')[$emp]);
    assert_true((bool) DB::value("SELECT 1 FROM notifications WHERE user_id=? AND title='Timesheet rejected'", [$emp]), 'employee notified');
    assert_true((bool) DB::value("SELECT 1 FROM audit_logs WHERE action='timesheet.reject' AND entity_id=?", [$t['id']]), 'audited');
    // A new entry in a rejected week stays rejected until resubmitted.
    att_entry($emp, '2033-03-10 09:00:00', 3600, Timesheets::entryStatus($emp, '2033-03-10 09:00:00'));
    assert_eq('rejected', DB::value("SELECT status FROM time_entries WHERE user_id=? AND started_at='2033-03-10 09:00:00'", [$emp]));
    act_as($emp);
    Timesheets::resubmit(att_user($emp), $ws);
    assert_eq('approved', DB::value('SELECT status FROM timesheets WHERE id=?', [$t['id']]));
    assert_eq(6.0, TimeService::approvedHours($emp, '2033-03-01', '2033-03-31'), 'resubmitted entries approved again');
    assert_eq(6 * 3600, (int) DB::value('SELECT total_seconds FROM timesheets WHERE id=?', [$t['id']]));
    assert_true((bool) DB::value("SELECT 1 FROM notifications WHERE user_id=? AND title LIKE '%resubmitted%'", [$mgr]), 'reviewer notified'); },
  'approver can re-approve a rejected week; reject needs a note' => function () {
    ['emp' => $emp, 'hr' => $hr] = att_fixture();
    att_entry($emp, '2033-04-05 09:00:00', 3600);
    $t = Timesheets::find($emp, '2033-04-04');
    assert_throws(\DomainException::class, fn () => Timesheets::reject(att_user($hr), (int) $t['id'], '  '), 'note required');
    Timesheets::reject(att_user($hr), (int) $t['id'], 'Check task');
    assert_eq(0.0, TimeService::approvedHours($emp, '2033-04-01', '2033-04-30'));
    Timesheets::approve(att_user($hr), (int) $t['id']);
    assert_eq('approved', DB::value('SELECT status FROM timesheets WHERE id=?', [$t['id']]));
    assert_eq(1.0, TimeService::approvedHours($emp, '2033-04-01', '2033-04-30'));
    assert_eq((int) $hr, (int) DB::value('SELECT reviewed_by FROM timesheets WHERE id=?', [$t['id']])); },
  'manager can reject only direct reports and project members, never self' => function () {
    ['emp' => $emp, 'mgr' => $mgr, 'member' => $member, 'other' => $other] = att_fixture();
    foreach ([$emp, $member, $other, $mgr] as $u) { att_entry($u, '2033-05-03 09:00:00', 3600); }
    $id = static fn (int $u): int => (int) Timesheets::find($u, '2033-05-02')['id'];
    act_as($mgr);
    assert_eq(403, http('POST', '/timesheets/'.$id($other).'/reject', ['note' => 'Nope'])->status(), 'unrelated employee');
    assert_eq('approved', DB::value('SELECT status FROM timesheets WHERE id=?', [$id($other)]));
    assert_eq(403, http('POST', '/timesheets/'.$id($mgr).'/reject', ['note' => 'Self'])->status(), 'own week');
    http('POST', '/timesheets/'.$id($member).'/reject', ['note' => 'Project member']);
    assert_eq('rejected', DB::value('SELECT status FROM timesheets WHERE id=?', [$id($member)]), 'project member in scope');
    act_as($emp);
    assert_eq(403, http('POST', '/timesheets/'.$id($other).'/reject', ['note' => 'x'])->status(), 'employees cannot review');
    assert_eq(403, http('GET', '/timesheets/approvals')->status(), 'no approvals page for employees');
    act_as($mgr);
    assert_eq(200, http('GET', '/timesheets/approvals?week=2033-05-02')->status());
    assert_eq(200, http('GET', '/timesheets/user/'.$member.'?week=2033-05-02')->status());
    assert_eq(403, http('GET', '/timesheets/user/'.$other.'?week=2033-05-02')->status()); },
  'finalized payroll month freezes the week' => function () {
    ['emp' => $emp, 'hr' => $hr] = att_fixture();
    $e = att_entry($emp, '2033-08-10 09:00:00', 3600);
    DB::insert('payroll_runs', ['period_month' => '2033-08-01', 'status' => 'finalized', 'created_at' => date('Y-m-d H:i:s')]);
    Timesheets::flush();
    assert_true(Timesheets::payrollLocked('2033-08-10') && !Timesheets::payrollLocked('2033-09-10'));
    assert_true(!Timesheets::entryEditable(DB::first('SELECT * FROM time_entries WHERE id=?', [$e])), 'entry locked');
    $t = Timesheets::find($emp, '2033-08-08');
    assert_throws(\DomainException::class, fn () => Timesheets::reject(att_user($hr), (int) $t['id'], 'Too late'));
    assert_eq('approved', DB::value('SELECT status FROM timesheets WHERE id=?', [$t['id']])); },
  'timesheet pages render' => function () {
    ['emp' => $emp] = att_fixture();
    act_as($emp);
    foreach (['/timesheets', '/timesheets?week=2033-03-07', '/time', '/attendance'] as $p) { $r = http('GET', $p); assert_eq(200, $r->status(), $p); }
    $html = (string) http('GET', '/timesheets?week=2033-03-07')->body();
    assert_true(str_contains($html, 'ts-grid') && !str_contains($html, '—'), 'grid rendered without em dashes'); },
];
