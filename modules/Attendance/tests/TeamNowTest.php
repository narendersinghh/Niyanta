<?php
use Core\{DB, Features};

/** Top-bar team widget fixture: a manager with two direct reports (one tracking, one stale), an outsider who tracks, HR and an employee. */
function tnow_fixture(): array {
  static $f = null;
  if ($f) { return $f; }
  $mgr = make_user('manager', 'mgr@tnow.test');
  $hr = make_user('hr', 'hr@tnow.test');
  $emp = make_user('employee', 'emp@tnow.test', ['manager_id' => $mgr, 'name' => 'Tnow Runner']);
  $stale = make_user('employee', 'stale@tnow.test', ['manager_id' => $mgr, 'name' => 'Tnow Sleeper']);
  $idle = make_user('employee', 'idle@tnow.test', ['manager_id' => $mgr, 'name' => 'Tnow Waiting']);
  $out = make_user('employee', 'out@tnow.test', ['name' => 'Tnow Outsider']);
  $now = date('Y-m-d H:i:s');
  $run = static fn (int $u, string $hb) => DB::insert('time_entries', ['user_id' => $u, 'started_at' => date('Y-m-d H:i:s', time() - 3600), 'last_heartbeat_at' => $hb, 'source' => 'agent', 'is_running' => 1, 'status' => 'draft', 'created_at' => $now]);
  $run($emp, $now); $run($out, $now); $run($stale, date('Y-m-d H:i:s', time() - 3 * 3600));
  return $f = compact('mgr', 'hr', 'emp', 'stale', 'idle', 'out'); }

function tnow_get(string $path): array { $r = http('GET', $path); return [$r->status(), json_decode((string) $r->body(), true) ?? []]; }

return [
  'team now: employees see their own team with names and status only (no times, projects or links)' => function () {
    ['mgr' => $mgr, 'emp' => $emp, 'stale' => $stale, 'idle' => $idle, 'out' => $out] = tnow_fixture();
    act_as($idle);
    [$code, $j] = tnow_get('/time/team/now?count=1');
    assert_eq(200, $code, json_encode($j));
    assert_eq(1, $j['active'], 'the tracking teammate counts, the outsider does not');
    [$code, $j] = tnow_get('/time/team/now');
    assert_eq(200, $code);
    assert_true($j['full'] === false && $j['team_url'] === null, 'peers view');
    $ids = static fn (array $rows): array => array_map(static fn ($r) => (int) $r['id'], $rows);
    $all = array_merge($ids($j['active']), $ids($j['away']), $ids($j['leave']), $ids($j['notin']));
    assert_eq([$emp], $ids($j['active']), 'teammate tracking');
    assert_true(in_array($stale, $ids($j['away']), true), 'stale teammate shows as away');
    assert_true(in_array($mgr, $all, true), 'the manager is part of the team');
    assert_true(!in_array($out, $all, true) && !in_array($idle, $all, true), 'no outsider, not themselves');
    foreach (['active', 'away', 'leave', 'notin'] as $k) { foreach ($j[$k] as $row) { assert_eq(['id', 'name', 'avatar', 'status', 'label'], array_keys($row), 'only names and status: '.json_encode($row)); } }
    assert_eq('Away', $j['away'][0]['label']);
    act_as($out);
    [$code, $j] = tnow_get('/time/team/now');
    assert_eq(200, $code);
    assert_eq([], array_merge($j['active'], $j['away'], $j['leave'], $j['notin']), 'no manager and no department: nobody listed'); },

  'team now: a manager sees only the own team, active vs not active' => function () {
    ['mgr' => $mgr, 'emp' => $emp, 'stale' => $stale, 'idle' => $idle, 'out' => $out] = tnow_fixture();
    act_as($mgr);
    [$code, $j] = tnow_get('/time/team/now?count=1');
    assert_eq(200, $code, json_encode($j));
    assert_eq(1, $j['active'], 'only the fresh team member counts (outsider and stale excluded)');
    [$code, $j] = tnow_get('/time/team/now');
    assert_eq(200, $code);
    $ids = static fn (array $rows): array => array_map(static fn ($r) => (int) $r['id'], $rows);
    $all = array_merge($ids($j['active']), $ids($j['away']), $ids($j['leave']), $ids($j['notin']));
    assert_eq([$emp], $ids($j['active']), 'active list');
    assert_true(!in_array($out, $all, true), 'outsider never listed');
    assert_true(!in_array($mgr, $all, true), 'the manager is not part of the own team list');
    assert_true(in_array($stale, $ids($j['away']), true), 'stale heartbeat shows as idle');
    assert_eq('idle', $j['away'][array_search($stale, $ids($j['away']), true)]['status']);
    assert_true(in_array($idle, $ids($j['notin']), true), 'not started member under not in yet');
    assert_true($j['full'] === true && isset($j['active'][0]['dur'], $j['active'][0]['project']), 'managers keep times and projects');
    assert_eq(1, $j['counts']['active']);
    assert_true(str_contains($j['active'][0]['url'], '/time/daily?user='.$emp), 'link to the daily timesheet');
    assert_true(str_contains($j['team_url'], '/attendance/team'), 'team tab link');
    assert_eq(200, http('GET', '/time/daily?user='.$emp)->status(), 'daily timesheet link opens for the manager'); },

  'team now: HR sees everyone and the top bar shows the pill only with view_all' => function () {
    ['hr' => $hr, 'emp' => $emp, 'out' => $out] = tnow_fixture();
    act_as($hr);
    [$code, $j] = tnow_get('/time/team/now');
    assert_eq(200, $code);
    $act = array_map(static fn ($r) => (int) $r['id'], $j['active']);
    assert_true(in_array($emp, $act, true) && in_array($out, $act, true), 'HR sees all trackers: '.json_encode($act));
    assert_true(str_contains(http('GET', '/time/screenshots')->body(), 'data-team-now'), 'pill rendered for HR');
    act_as($emp);
    assert_true(str_contains(http('GET', '/time')->body(), 'data-team-now'), 'employees get the pill too (peers view)'); },

  'team now: switched off with the attendance feature' => function () {
    ['mgr' => $mgr] = tnow_fixture();
    Features::set('feature:attendance', false);
    try { act_as($mgr); assert_true(http('GET', '/time/team/now')->status() >= 403, 'blocked when attendance is off'); }
    finally { Features::set('feature:attendance', true); \Core\Gate::flush(); } },

  'activity chart: 10-minute slots from activity_slots with average and levels' => function () {
    ['emp' => $emp] = tnow_fixture();
    $d = '2033-06-14';
    foreach ([['09:00:00', 600, 540], ['09:10:00', 600, 240], ['09:20:00', 600, 60], ['10:05:00', 300, 300]] as [$t, $s, $a]) { DB::insert('activity_slots', ['user_id' => $emp, 'slot_start' => "$d $t", 'slot_seconds' => $s, 'active_seconds' => $a, 'created_at' => date('Y-m-d H:i:s')]); }
    $c = \Modules\Attendance\Controllers\HubController::chart($emp, $d, [['started_at' => "$d 09:00:00", 'end' => "$d 10:10:00"]]);
    assert_true($c !== null, 'chart built');
    assert_eq((int) round(100 * 1140 / 2100), $c['avg'], 'average of active over slot seconds');
    $by = array_column($c['slots'], null, 'i');
    assert_eq('hi', $by[54]['lvl']); assert_eq('mid', $by[55]['lvl']); assert_eq('lo', $by[56]['lvl']); assert_eq('none', $by[57]['lvl']);
    assert_eq(10, $by[57]['min'], 'tracked minutes come from entries');
    assert_eq(9, $c['hours'][0]['h'], 'chart starts at the first hour with data');
    assert_true(count($c['hours']) >= 6, 'at least six hours shown');
    assert_eq(null, \Modules\Attendance\Controllers\HubController::chart($emp, '2033-06-15', []), 'empty day has no chart'); },

  'activity chart: meeting slots are drawn apart and left out of the average' => function () {
    ['emp' => $emp] = tnow_fixture();
    if (!\Modules\Attendance\Controllers\HubController::meetingFlag('activity_slots')) { return; }
    $d = '2033-06-20';
    foreach ([['14:00:00', 600, 600, 0], ['14:10:00', 600, 30, 1]] as [$t, $s, $a, $m]) { DB::insert('activity_slots', ['user_id' => $emp, 'slot_start' => "$d $t", 'slot_seconds' => $s, 'active_seconds' => $a, 'meeting' => $m, 'created_at' => date('Y-m-d H:i:s')]); }
    $c = \Modules\Attendance\Controllers\HubController::chart($emp, $d, []);
    $by = array_column($c['slots'], null, 'i');
    assert_eq(100, $c['avg'], 'meeting slot not averaged');
    assert_eq('meet', $by[85]['lvl']); assert_true($by[85]['meet'] && $by[85]['pct'] === null, 'meeting slot flagged');
    assert_eq(1, $c['meetings']); },
];
