<?php
use Core\DB;

/** Time & Attendance header: Me | Organization switch, tabs with badges and the single toolbar, for an employee and their manager. */
function hub_fixture(): array {
  static $f = null;
  if ($f) { return $f; }
  $mgr = make_user('manager', 'mgr@hubunit.test');
  $emp = make_user('employee', 'emp@hubunit.test', ['manager_id' => $mgr, 'name' => 'Hub Member']);
  $ws = \Modules\Attendance\Services\TimeService::weekStart(date('Y-m-d', strtotime('-14 days')));
  DB::insert('timesheets', ['user_id' => $emp, 'week_start' => $ws, 'status' => 'rejected', 'total_seconds' => 3600, 'billable_seconds' => 0]);
  return $f = compact('mgr', 'emp', 'ws'); }

return [
  'hub: an employee gets no Organization switch; tabs start with Today and the rejected week shows on Timesheet' => function () {
    ['emp' => $emp] = hub_fixture();
    act_as($emp);
    $h = http('GET', '/time')->body();
    assert_true(!str_contains($h, 'hub-scope'), 'no Me | Organization switch');
    assert_true((bool) preg_match('/hub-tabs.*?>.*?Today<\/a>.*?Timesheet <span class="hub-badge warn"[^>]*>1/s', $h), 'Today first, Timesheet badge counts the rejected week');
    $w = http('GET', '/timesheets')->body();
    assert_true(str_contains($w, 'id="hub-week"') && substr_count($w, 'aria-label="Previous week"') === 1, 'one week navigator, in the toolbar'); },

  'hub: a manager sees the switch first, Organization tabs with the Approvals badge, and member pages count as Organization' => function () {
    ['mgr' => $mgr, 'emp' => $emp, 'ws' => $ws] = hub_fixture();
    act_as($mgr);
    $h = http('GET', '/attendance/team')->body();
    assert_true(strpos($h, 'hub-scope') < strpos($h, '<h1>'), 'switch above the title');
    assert_true((bool) preg_match('/<a [^>]*class="on" aria-current="page"><span class="hs-ic">.*?<b>Organization<\/b>/s', $h), 'Organization is on');
    assert_true(str_contains($h, 'Team today') && (bool) preg_match('/Approvals <span class="hub-badge warn"[^>]*>1/', $h), 'Team today tab and Approvals badge');
    assert_true(str_contains($h, 'id="hub-date"'), 'team date lives in the toolbar');
    $d = http('GET', '/time/daily?scope=org')->body();
    assert_true(str_contains($d, 'id="hub-member"') && str_contains($d, 'value="'.$emp.'" selected'), '?scope=org opens the first teammate');
    $s = http('GET', '/timesheets/user/'.$emp.'?week='.$ws)->body();
    assert_true(str_contains($s, e(url('/timesheets/user/'.$emp.'?week='.date('Y-m-d', strtotime($ws.' -7 days'))))), 'week navigator stays on the member\'s page');
    assert_true(str_contains($s, '<b>Organization</b>') && str_contains($s, 'aria-current="page">Week</a>'), 'member week counts as Organization, Week selected');
    $me = http('GET', '/time/daily')->body();
    assert_true((bool) preg_match('/class="on" aria-current="page"><span class="hs-ic">.*?<b>Me<\/b>/s', $me), 'own page is Me'); },
];
