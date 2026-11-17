<?php
/* fixture names sort late so name-ordered capped lists in other tests (TeamNow) keep their own people */
use Core\DB;
use Modules\Attendance\Services\{EntryReview, Timesheets, TimeService};

/** Per-entry and per-day review: fixture with HR, a manager, their direct report and an employee outside the team. */
function er_fixture(): array {
  static $f = null;
  if ($f) { return $f; }
  $hr = make_user('hr', 'hr@erunit.test', ['name' => 'Zed Review HR']); $mgr = make_user('manager', 'mgr@erunit.test', ['name' => 'Rev Manager']);
  $emp = make_user('employee', 'emp@erunit.test', ['manager_id' => $mgr, 'name' => 'Rev Member']);
  $other = make_user('employee', 'other@erunit.test', ['name' => 'Zed Outsider']);
  return $f = compact('hr', 'mgr', 'emp', 'other'); }

function er_entry(int $uid, string $start, int $sec, bool $running = false): int {
  $id = DB::insert('time_entries', ['user_id' => $uid, 'started_at' => $start, 'ended_at' => $running ? null : date('Y-m-d H:i:s', strtotime($start) + $sec), 'duration_seconds' => $running ? 0 : $sec, 'status' => $running ? 'draft' : 'approved', 'is_running' => (int) $running, 'last_heartbeat_at' => $running ? date('Y-m-d H:i:s') : null, 'source' => 'manual', 'billable' => 1, 'created_at' => date('Y-m-d H:i:s')]);
  Timesheets::sync($uid, TimeService::weekStart(substr($start, 0, 10)));
  return $id; }

function er_user(int $id): array { return DB::first('SELECT u.*, r.slug AS role_slug FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=?', [$id]); }
function er_st(int $id): string { return (string) DB::value('SELECT status FROM time_entries WHERE id=?', [$id]); }
function er_notes(int $uid, string $title): int { return (int) DB::value('SELECT COUNT(*) FROM notifications WHERE user_id=? AND title LIKE ?', [$uid, $title]); }

return [
  'entry review: a reviewer rejects one entry with a required reason; the employee is notified and payroll leaves it out' => function () {
    ['mgr' => $mgr, 'emp' => $emp] = er_fixture();
    $d = date('Y-m-d', strtotime('-30 days'));
    $a = er_entry($emp, "$d 09:00:00", 2 * 3600); er_entry($emp, "$d 13:00:00", 3600);
    assert_eq(3.0, TimeService::approvedHours($emp, $d, $d));
    act_as($mgr);
    http('POST', '/time/entries/'.$a.'/reject', ['note' => '   ']);
    assert_eq('approved', er_st($a), 'a reason is required');
    assert_throws(\DomainException::class, fn () => EntryReview::reject(er_user($mgr), $a, ' '), 'service needs a reason too');
    $r = http('POST', '/time/entries/'.$a.'/reject', ['note' => 'Wrong project']);
    assert_true(in_array($r->status(), [302, 303], true), 'redirects back: '.$r->status());
    $e = DB::first('SELECT status, review_note, reviewed_by, reviewed_at FROM time_entries WHERE id=?', [$a]);
    assert_true($e['status'] === 'rejected' && $e['review_note'] === 'Wrong project' && (int) $e['reviewed_by'] === $mgr && $e['reviewed_at'], 'review stored on the entry');
    assert_eq(1.0, TimeService::approvedHours($emp, $d, $d), 'rejected time is not approved');
    assert_eq(1.0, TimeService::approvedHoursMany([$emp], $d, $d)[$emp]);
    assert_eq(1, er_notes($emp, 'Time entry rejected'), 'one notification');
    assert_eq('/time/daily?date='.$d, DB::value("SELECT url FROM notifications WHERE user_id=? AND title='Time entry rejected'", [$emp]));
    assert_true((bool) DB::value("SELECT 1 FROM audit_logs WHERE action='time.entry_reject' AND entity_id=?", [$a]), 'audited');
    assert_eq(1, EntryReview::rejectedCount(er_user($emp)), 'own badge');
    assert_true(EntryReview::rejectedCount(er_user($mgr), true) >= 1, 'team badge');
    assert_eq('approved', DB::value('SELECT status FROM timesheets WHERE user_id=? AND week_start=?', [$emp, TimeService::weekStart($d)]), 'the week row never turns rejected');
    $s = Timesheets::summary($emp, TimeService::weekStart($d));
    assert_eq(2 * 3600, $s['rejected'], 'week grid shows the rejected time');
    assert_eq(3 * 3600, $s['total'], 'and keeps it in the total');
    act_as($emp);
    $day = (string) http('GET', '/time/daily?date='.$d)->body();
    assert_true(str_contains($day, 'tld-b') && str_contains($day, ' rej"') && str_contains($day, 'Wrong project') && str_contains($day, 'Rev Manager'), 'Day shows the rejected block with reason and reviewer');
    $wk = (string) http('GET', '/timesheets?week='.$d)->body();
    assert_true(str_contains($wk, 'is-rej') && str_contains($wk, 'Wrong project') && str_contains($wk, 'cell rej'), 'Week marks the entry and the grid cell'); },

  'entry review: the owner fixes a rejected entry in a closed week; it is approved again and the reviewer is told' => function () {
    ['mgr' => $mgr, 'emp' => $emp] = er_fixture();
    $d = date('Y-m-d', strtotime('-30 days'));
    $a = (int) DB::value("SELECT id FROM time_entries WHERE user_id=? AND started_at=?", [$emp, "$d 09:00:00"]);
    $b = (int) DB::value("SELECT id FROM time_entries WHERE user_id=? AND started_at=?", [$emp, "$d 13:00:00"]);
    assert_eq('rejected', er_st($a));
    assert_true(Timesheets::entryEditable(DB::first('SELECT * FROM time_entries WHERE id=?', [$a])), 'rejected entry stays editable');
    assert_true(!Timesheets::entryEditable(DB::first('SELECT * FROM time_entries WHERE id=?', [$b])), 'approved entry of a closed week is locked');
    act_as($emp);
    assert_eq(422, http('POST', '/time/entries/'.$b, ['date' => $d, 'start_time' => '13:00', 'end_time' => '14:30'])->status(), 'locked entry refused');
    $n = (int) DB::value('SELECT COUNT(*) FROM time_entries WHERE user_id=?', [$emp]);
    http('POST', '/time/entries', ['date' => $d, 'start_time' => '16:00', 'duration' => '1:00']);
    assert_eq($n, (int) DB::value('SELECT COUNT(*) FROM time_entries WHERE user_id=?', [$emp]), 'no new time in the closed week');
    http('POST', '/time/entries/'.$a, ['date' => $d, 'start_time' => '09:00', 'end_time' => '10:30', 'description' => 'Moved to the right project', 'billable' => '1']);
    $e = DB::first('SELECT status, review_note, reviewed_by, duration_seconds FROM time_entries WHERE id=?', [$a]);
    assert_true($e['status'] === 'approved' && $e['review_note'] === null && $e['reviewed_by'] === null, 'approved again, review cleared');
    assert_eq(5400, (int) $e['duration_seconds']);
    assert_eq(2.5, TimeService::approvedHours($emp, $d, $d));
    assert_eq(1, er_notes($mgr, '%fixed a rejected time entry'), 'reviewer notified');
    assert_true((bool) DB::value("SELECT 1 FROM audit_logs WHERE action='time.entry_fix' AND entity_id=?", [$a]), 'fix audited');
    assert_eq(0, EntryReview::rejectedCount(er_user($emp))); },

  'day review: approve or reject every stopped entry of a day, one notification each; running entries are left alone' => function () {
    ['mgr' => $mgr, 'emp' => $emp] = er_fixture();
    $d = date('Y-m-d', strtotime('-32 days'));
    $ids = [er_entry($emp, "$d 09:00:00", 3600), er_entry($emp, "$d 11:00:00", 1800), er_entry($emp, "$d 14:00:00", 3600)];
    act_as($mgr);
    assert_throws(\DomainException::class, fn () => EntryReview::day(er_user($mgr), $emp, $d, 'rejected', ''), 'reason required');
    $page = (string) http('GET', '/time/daily?user='.$emp.'&date='.$d)->body();
    assert_true(str_contains($page, 'Reject day') && str_contains($page, 'Approve day') && str_contains($page, 'id="entry-view"') && str_contains($page, 'id="day-reject"'), 'Day view has the day buttons and the dialogs');
    http('POST', '/time/review/day', ['user_id' => $emp, 'date' => $d, 'decision' => 'rejected', 'note' => 'Training day, not client work']);
    foreach ($ids as $i) { assert_eq('rejected', er_st($i)); }
    assert_eq('Training day, not client work', DB::value('SELECT review_note FROM time_entries WHERE id=?', [$ids[2]]));
    assert_eq(1, er_notes($emp, 'Day rejected'), 'one notification for the day');
    assert_eq(0.0, TimeService::approvedHours($emp, $d, $d));
    assert_true((bool) DB::value("SELECT 1 FROM audit_logs WHERE action='time.day_reject' AND entity_id=?", [$emp]), 'audited');
    http('POST', '/time/review/day', ['user_id' => $emp, 'date' => $d, 'decision' => 'approved']);
    foreach ($ids as $i) { assert_eq('approved', er_st($i)); }
    assert_eq(1, er_notes($emp, 'Day approved'));
    assert_eq(2.5, TimeService::approvedHours($emp, $d, $d));
    assert_throws(\DomainException::class, fn () => EntryReview::day(er_user($mgr), $emp, $d, 'approved'), 'nothing left to approve');
    // today: a stopped entry and a running one
    $t = date('Y-m-d');
    $stop = er_entry($emp, date('Y-m-d H:i:s', max(strtotime($t.' 00:00:00'), time() - 7200)), 60);
    $run = er_entry($emp, date('Y-m-d H:i:s', max(strtotime($t.' 00:01:30'), time() - 3000)), 0, true);
    assert_throws(\DomainException::class, fn () => EntryReview::reject(er_user($mgr), $run, 'Still running'), 'running entry refused');
    http('POST', '/time/entries/'.$run.'/reject', ['note' => 'Still running']);
    assert_eq('draft', er_st($run));
    EntryReview::day(er_user($mgr), $emp, $t, 'rejected', 'Check today');
    assert_eq('rejected', er_st($stop)); assert_eq('draft', er_st($run), 'running entry untouched by the day action');
    DB::delete('time_entries', 'id IN (?,?)', [$stop, $run]); },

  'entry review permissions: employees never, reviewers not themselves or outside the team, HR anyone in scope, payroll-locked refused' => function () {
    ['hr' => $hr, 'mgr' => $mgr, 'emp' => $emp, 'other' => $other] = er_fixture();
    $d = date('Y-m-d', strtotime('-33 days'));
    $o = er_entry($other, "$d 09:00:00", 3600); $m = er_entry($mgr, "$d 09:00:00", 3600); $x = er_entry($emp, "$d 09:00:00", 3600);
    act_as($emp);
    assert_eq(403, http('POST', '/time/entries/'.$o.'/reject', ['note' => 'x'])->status(), 'employee cannot reject');
    assert_eq(403, http('POST', '/time/review/day', ['user_id' => $other, 'date' => $d, 'decision' => 'rejected', 'note' => 'x'])->status(), 'employee cannot review a day');
    assert_eq(403, http('GET', '/timesheets/review')->status());
    act_as($mgr);
    assert_eq(403, http('POST', '/time/entries/'.$m.'/reject', ['note' => 'Self'])->status(), 'not self');
    assert_eq(403, http('POST', '/time/review/day', ['user_id' => $mgr, 'date' => $d, 'decision' => 'rejected', 'note' => 'Self'])->status(), 'not own day');
    assert_eq(403, http('POST', '/time/entries/'.$o.'/reject', ['note' => 'Outside'])->status(), 'not outside the team');
    assert_true((bool) DB::value("SELECT 1 FROM audit_logs WHERE action='access.denied' AND entity_type='time_entry' AND entity_id=?", [$o]), 'denial audited');
    assert_eq('approved', er_st($o)); assert_eq('approved', er_st($m));
    $page = (string) http('GET', '/time/daily?user='.$emp.'&date='.$d)->body();
    assert_true(str_contains($page, '&quot;review&quot;:true'), 'reviewer gets review actions on a team member\'s day');
    assert_true(!str_contains((string) http('GET', '/time/daily?date='.$d)->body(), 'Reject day'), 'no day buttons on the own day');
    EntryReview::reject(er_user($hr), $o, 'HR check');
    assert_eq('rejected', er_st($o), 'HR reviews anyone');
    // finalized payroll
    $l = er_entry($emp, '2034-02-10 09:00:00', 3600);
    DB::insert('payroll_runs', ['period_month' => '2034-02-01', 'status' => 'finalized', 'created_at' => date('Y-m-d H:i:s')]);
    Timesheets::flush();
    assert_throws(\DomainException::class, fn () => EntryReview::reject(er_user($mgr), $l, 'Too late'));
    assert_throws(\DomainException::class, fn () => EntryReview::day(er_user($mgr), $emp, '2034-02-10', 'rejected', 'Too late'));
    assert_eq('approved', er_st($l));
    assert_true(!EntryReview::canReviewDay(er_user($mgr), $emp, '2034-02-10') && EntryReview::canReviewDay(er_user($mgr), $emp, $d)); },

  'review page: rejected team entries by person and dates, re-approve from the list' => function () {
    ['mgr' => $mgr, 'emp' => $emp] = er_fixture();
    $d = date('Y-m-d', strtotime('-34 days'));
    $x = er_entry($emp, "$d 10:00:00", 3600);
    act_as($mgr);
    EntryReview::reject(er_user($mgr), $x, 'Missing description');
    $h = (string) http('GET', '/timesheets/review?user='.$emp.'&from='.$d.'&to='.$d)->body();
    assert_true(str_contains($h, 'Missing description') && str_contains($h, '/time/entries/'.$x.'/approve') && str_contains($h, '/time/daily?user='.$emp.'&amp;date='.$d), 'listed with approve and day link');
    assert_true(!str_contains((string) http('GET', '/timesheets/review?from='.date('Y-m-d', strtotime($d.' +1 day')).'&to='.date('Y-m-d', strtotime($d.' +1 day')))->body(), 'Missing description'), 'date filter');
    assert_true((bool) preg_match('/Review <span class="hub-badge warn"/', $h), 'Review tab badge');
    http('POST', '/time/entries/'.$x.'/approve');
    assert_eq('approved', er_st($x));
    assert_true(er_notes($emp, 'Time entry approved') >= 1, 'owner told');
    assert_true((bool) DB::value("SELECT 1 FROM audit_logs WHERE action='time.entry_approve' AND entity_id=?", [$x]));
    assert_throws(\DomainException::class, fn () => EntryReview::approve(er_user($mgr), $x), 'already approved'); },
];
