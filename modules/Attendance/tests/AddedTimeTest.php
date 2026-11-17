<?php
use Core\DB;
use Modules\Attendance\Services\{AttendanceService, Timesheets, TimeService};

/**
 * Imported or manually added time on the timesheet: an HR attendance record beyond the tracked time shows as its own tagged row and counts
 * in the Day, Week and Month totals; manual entries are tagged; HR (attendance.manage) can add, edit and delete time for a team member,
 * managers without it and employees cannot.
 */
function added_fixture(): array {
  static $f = null;
  if ($f) { return $f; }
  $hr = make_user('hr', 'hr@addunit.test', ['name' => 'Zz Hira HR']);
  $mgr = make_user('manager', 'mgr@addunit.test', ['name' => 'Zz Manager']);
  $emp = make_user('employee', 'emp@addunit.test', ['manager_id' => $mgr, 'name' => 'Zz Owner']);
  $d = date('Y-m-d', strtotime('-1 day'));
  DB::insert('time_entries', ['user_id' => $emp, 'started_at' => "$d 09:00:00", 'ended_at' => "$d 11:00:00", 'duration_seconds' => 7200, 'status' => 'approved', 'is_running' => 0, 'source' => 'agent', 'billable' => 1, 'created_at' => date('Y-m-d H:i:s')]);
  DB::insert('attendance', ['user_id' => $emp, 'work_date' => $d, 'clock_in' => "$d 09:00:00", 'clock_out' => "$d 18:00:00", 'break_seconds' => 3600, 'worked_seconds' => 28800, 'status' => 'present', 'note' => 'Imported from biometric', 'edited_by' => $hr]);
  return $f = compact('hr', 'mgr', 'emp', 'd'); }

return [
  'added time: HR attendance record beyond the tracked time counts once, tagged, on Day, Week and Month' => function () {
    ['emp' => $emp, 'd' => $d] = added_fixture();
    assert_eq([$d => 21600], array_map(static fn ($a) => $a['secs'], AttendanceService::addedTime($emp, $d, $d)), 'record minus tracked');
    $s = Timesheets::summary($emp, TimeService::weekStart($d));
    assert_eq([28800, 21600, 7200], [$s['total'], $s['added'], $s['billable']], 'week total includes it, not billable');
    assert_true(!empty($s['rows']['attendance']['added']) && $s['rows']['attendance']['days'][$d] === 21600, 'own row');
    act_as($emp);
    $day = http('GET', '/time/daily?date='.$d)->body();
    assert_true(str_contains($day, 'class="ds-total"><b>'.hours(28800).'<') && str_contains($day, 'Imported or manually added') && str_contains($day, 'Imported from biometric'), 'day total and tag');
    $wk = http('GET', '/timesheets?week='.$d)->body();
    assert_true(str_contains($wk, 'Attendance record') && str_contains($wk, 'mk added'), 'week grid row tagged');
    $mo = http('GET', '/time/monthly?month='.substr($d, 0, 7))->body();
    assert_true(str_contains($mo, 'class="mcal-add"'), 'month marks the added time');
    $rec = DB::value('SELECT id FROM attendance WHERE user_id=? AND work_date=?', [$emp, $d]);
    DB::update('attendance', ['edited_by' => null], 'id=?', [$rec]);
    assert_eq([], AttendanceService::addedTime($emp, $d, $d), 'a tracked day (not edited by HR) adds nothing');
    DB::update('attendance', ['edited_by' => added_fixture()['hr']], 'id=?', [$rec]); },

  'added time: HR logs time for a team member; manager without attendance.manage and employees cannot' => function () {
    ['hr' => $hr, 'mgr' => $mgr, 'emp' => $emp, 'd' => $d] = added_fixture();
    act_as($mgr);
    assert_eq(403, http('POST', '/time/entries', ['user_id' => $emp, 'date' => $d, 'start_time' => '19:00', 'duration' => '1:00', 'billable' => '1'])->status(), 'manager refused');
    assert_true(!str_contains(http('GET', '/timesheets/user/'.$emp.'?week='.$d)->body(), 'data-entry-new'), 'no add button for the manager');
    act_as($hr);
    assert_true(str_contains(http('GET', '/timesheets/user/'.$emp.'?week='.$d)->body(), 'Add time for Zz'), 'HR sees the add button');
    http('POST', '/time/entries', ['user_id' => $emp, 'date' => $d, 'start_time' => '19:00', 'duration' => '1:00', 'billable' => '1']);
    $e = DB::first("SELECT * FROM time_entries WHERE user_id=? AND source='manual' ORDER BY id DESC LIMIT 1", [$emp]);
    assert_true($e && $e['started_at'] === "$d 19:00:00" && (int) $e['duration_seconds'] === 3600, 'saved on the member');
    assert_true((bool) DB::value("SELECT 1 FROM notifications WHERE user_id=? AND title LIKE 'Time added by%'", [$emp]), 'member notified');
    assert_true(str_contains(http('GET', '/time/daily?user='.$emp.'&date='.$d)->body(), 'data-entry-edit'), 'HR can edit it from the day');
    http('POST', '/time/entries/'.$e['id'], ['date' => $d, 'start_time' => '19:00', 'duration' => '0:30', 'billable' => '1']);
    assert_eq(1800, (int) DB::value('SELECT duration_seconds FROM time_entries WHERE id=?', [$e['id']]), 'HR edited it');
    act_as($emp);
    assert_eq(403, http('POST', '/time/entries', ['user_id' => $hr, 'date' => $d, 'start_time' => '20:00', 'duration' => '1:00'])->status(), 'employee cannot add for others');
    act_as($hr);
    http('POST', '/time/entries/'.$e['id'].'/delete');
    assert_true(!DB::value('SELECT 1 FROM time_entries WHERE id=?', [$e['id']]), 'HR deleted it'); },
];
