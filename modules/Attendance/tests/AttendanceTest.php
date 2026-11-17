<?php
use Core\DB;
use Modules\Attendance\Services\{EntryReview, Timesheets, TimeService};

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
  'week review is gone: no week reject, approve or resubmit; the old approvals page opens Review' => function () {
    ['emp' => $emp, 'mgr' => $mgr] = att_fixture();
    att_entry($emp, '2033-03-08 09:00:00', 3 * 3600);
    $t = Timesheets::find($emp, '2033-03-07');
    act_as($mgr);
    foreach (['/timesheets/'.$t['id'].'/reject', '/timesheets/'.$t['id'].'/approve', '/timesheets/resubmit'] as $p) { assert_true(in_array(http('POST', $p, ['note' => 'x', 'week' => '2033-03-07'])->status(), [404, 405], true), "$p removed"); }
    assert_eq('approved', DB::value('SELECT status FROM timesheets WHERE id=?', [$t['id']]), 'week row is only a totals cache');
    $r = http('GET', '/timesheets/approvals');
    assert_true(in_array($r->status(), [301, 302, 303], true) && str_contains((string) ($r->headers()['Location'] ?? ''), '/timesheets/review'), 'approvals redirects to Review');
    $w = (string) http('GET', '/timesheets/user/'.$emp.'?week=2033-03-07')->body();
    assert_true(!str_contains($w, 'Reject week') && !str_contains($w, 'Resubmit') && str_contains($w, 'data-entry-view') && str_contains($w, 'Reject day'), 'week page offers entry and day review only'); },
  'manager can reject entries only of direct reports and project members, never self' => function () {
    ['emp' => $emp, 'mgr' => $mgr, 'member' => $member, 'other' => $other] = att_fixture();
    $id = [];
    foreach ([$emp, $member, $other, $mgr] as $u) { $id[$u] = att_entry($u, '2033-05-03 09:00:00', 3600); }
    $st = static fn (int $u): string => (string) DB::value('SELECT status FROM time_entries WHERE id=?', [$id[$u]]);
    act_as($mgr);
    assert_eq(403, http('POST', '/time/entries/'.$id[$other].'/reject', ['note' => 'Nope'])->status(), 'unrelated employee');
    assert_eq('approved', $st($other));
    assert_eq(403, http('POST', '/time/entries/'.$id[$mgr].'/reject', ['note' => 'Self'])->status(), 'own entry');
    http('POST', '/time/entries/'.$id[$member].'/reject', ['note' => 'Project member']);
    assert_eq('rejected', $st($member), 'project member in scope');
    act_as($emp);
    assert_eq(403, http('POST', '/time/entries/'.$id[$other].'/reject', ['note' => 'x'])->status(), 'employees cannot review');
    assert_eq(403, http('GET', '/timesheets/review')->status(), 'no Review page for employees');
    act_as($mgr);
    $rv = http('GET', '/timesheets/review');
    assert_eq(200, $rv->status());
    assert_true(str_contains((string) $rv->body(), 'Project member'), 'rejected entry listed with its reason');
    assert_eq(200, http('GET', '/timesheets/user/'.$member.'?week=2033-05-02')->status());
    assert_eq(403, http('GET', '/timesheets/user/'.$other.'?week=2033-05-02')->status()); },
  'finalized payroll month freezes review' => function () {
    ['emp' => $emp, 'hr' => $hr] = att_fixture();
    $e = att_entry($emp, '2033-08-10 09:00:00', 3600);
    DB::insert('payroll_runs', ['period_month' => '2033-08-01', 'status' => 'finalized', 'created_at' => date('Y-m-d H:i:s')]);
    Timesheets::flush();
    assert_true(Timesheets::payrollLocked('2033-08-10') && !Timesheets::payrollLocked('2033-09-10'));
    assert_true(!Timesheets::entryEditable(DB::first('SELECT * FROM time_entries WHERE id=?', [$e])), 'entry locked');
    assert_throws(\DomainException::class, fn () => EntryReview::reject(att_user($hr), $e, 'Too late'));
    assert_throws(\DomainException::class, fn () => EntryReview::day(att_user($hr), $emp, '2033-08-10', 'rejected', 'Too late'));
    assert_eq('approved', DB::value('SELECT status FROM time_entries WHERE id=?', [$e])); },
  'timesheet pages render' => function () {
    ['emp' => $emp] = att_fixture();
    act_as($emp);
    foreach (['/timesheets', '/timesheets?week=2033-03-07', '/time', '/attendance'] as $p) { $r = http('GET', $p); assert_eq(200, $r->status(), $p); }
    $html = (string) http('GET', '/timesheets?week=2033-03-07')->body();
    assert_true(str_contains($html, 'ts-grid') && !str_contains($html, '—'), 'grid rendered without em dashes'); },
];
