<?php
use Core\{DB, Settings};
use Modules\Hr\Services\{HolidayService, LeaveService};

/** Users for the policy tests (joined dates drive proration and service eligibility). */
function lp_fixture(): array {
  static $f = null;
  if ($f) { return $f; }
  $hr = make_user('hr', 'hr@lp.test');
  $a = make_user('employee', 'a@lp.test', ['joined_on' => '2025-01-10']);
  $b = make_user('employee', 'b@lp.test', ['joined_on' => '2025-03-10']);
  $c = make_user('employee', 'c@lp.test', ['joined_on' => '2025-03-20']);
  $d = make_user('employee', 'd@lp.test', ['joined_on' => '2025-05-01']);
  return $f = compact('hr', 'a', 'b', 'c', 'd'); }

function lp_type(array $x): int {
  return DB::insert('leave_types', $x + ['name' => 'T '.$x['code'], 'annual_quota' => 0, 'allowance' => 'yearly', 'monthly_accrual' => 0, 'carry_forward' => 'none', 'paid' => 1, 'color' => 'lav', 'active' => 1, 'allow_half_day' => 1, 'needs_approval' => 1]); }

function lp_bal(int $u, int $t, int $y): array { return DB::first('SELECT allocated, used FROM leave_balances WHERE user_id=? AND leave_type_id=? AND year=?', [$u, $t, $y]) ?? ['allocated' => null, 'used' => null]; }

function lp_ledger_sum(int $u, int $t, int $y): float { return (float) DB::value('SELECT COALESCE(SUM(days),0) FROM leave_ledger WHERE user_id=? AND leave_type_id=? AND year=?', [$u, $t, $y]); }

return [
  'default setup after the reset: Paid Leave accrues 2.5 a month and covers shortages; old types removed' => function () {
    $pl = DB::first("SELECT * FROM leave_types WHERE code='PL'");
    assert_true((bool) $pl, 'PL seeded');
    assert_eq('monthly', $pl['allowance']); assert_eq(2.5, (float) $pl['monthly_accrual']); assert_eq(1, (int) $pl['shortage_cover']); assert_eq(1, (int) $pl['active']);
    assert_eq(0, (int) DB::value("SELECT COUNT(*) FROM leave_types WHERE code IN ('CL','SL','EL','LOP')"), 'old leave types removed');
    assert_eq(1, (int) DB::value("SELECT COUNT(*) FROM leave_types WHERE code='PL'"), 'one Paid Leave type');
    assert_eq((int) $pl['id'], (int) LeaveService::shortageType()['id']); },
  'accrual: once per user/type/month, mid-month joiners prorated, cap respected' => function () {
    ['a' => $a, 'b' => $b, 'c' => $c, 'd' => $d] = lp_fixture();
    $pl = (int) DB::value("SELECT id FROM leave_types WHERE code='PL'");
    $r = LeaveService::accrueMonth('2025-03', [$a, $b, $c, $d]);
    assert_eq(2, $r['credited'], 'a (joined before) and b (joined 10th) credited, c (20th) and d (May) not');
    assert_eq(2.5, (float) lp_bal($a, $pl, 2025)['allocated']); assert_eq(2.5, (float) lp_bal($b, $pl, 2025)['allocated']);
    assert_eq(null, lp_bal($c, $pl, 2025)['allocated'], 'late joiner starts next month');
    assert_eq(0, LeaveService::accrueMonth('2025-03', [$a, $b, $c, $d])['credited'], 'idempotent');
    assert_eq(2.5, (float) lp_bal($a, $pl, 2025)['allocated'], 'not credited twice');
    assert_eq(1, LeaveService::accrueMonth('2025-04', [$c])['credited']);
    assert_eq(2.5, (float) lp_bal($c, $pl, 2025)['allocated'], 'c accrues from April');
    assert_eq(0, LeaveService::accrueMonth(date('Y-m', strtotime('+1 month')), [$a])['credited'], 'future months never accrue');
    $cap = lp_type(['code' => 'CAPT', 'allowance' => 'monthly', 'monthly_accrual' => 2, 'max_balance' => 3]);
    LeaveService::accrueMonth('2025-01', [$a]); LeaveService::accrueMonth('2025-02', [$a]); LeaveService::accrueMonth('2025-03', [$a]);
    assert_eq(3.0, (float) lp_bal($a, $cap, 2025)['allocated'], 'capped at 3');
    assert_eq(3, (int) DB::value("SELECT COUNT(*) FROM leave_ledger WHERE user_id=? AND leave_type_id=? AND kind='accrual'", [$a, $cap]), 'capped months are still recorded once');
    assert_eq(3.0, lp_ledger_sum($a, $cap, 2025), 'ledger matches balance');
    DB::update('leave_types', ['active' => 0], 'id=?', [$cap]); },
  'carry forward: up to a limit, all, none; rest expires; once per year' => function () {
    ['a' => $a] = lp_fixture();
    $lim = lp_type(['code' => 'CFL', 'annual_quota' => 10, 'carry_forward' => 'limit', 'carry_limit' => 5]);
    $all = lp_type(['code' => 'CFA', 'annual_quota' => 10, 'carry_forward' => 'all', 'max_balance' => 12]);
    $non = lp_type(['code' => 'CFN', 'annual_quota' => 10]);
    LeaveService::allocateYear(2023);
    foreach ([$lim, $all, $non] as $t) { LeaveService::recordUsage(['id' => 0, 'user_id' => $a, 'leave_type_id' => $t, 'start_date' => '2023-05-02', 'end_date' => '2023-05-04', 'days' => 3, 'half_day' => 0]); }
    DB::query('INSERT INTO leave_balances (user_id, leave_type_id, year, allocated, used) VALUES (?,?,2024,8,0)', [$a, $all]);
    LeaveService::carryForward(2023);
    assert_eq(5.0, (float) lp_bal($a, $lim, 2024)['allocated'], 'limit carries 5 of 7');
    assert_eq(12.0, (float) lp_bal($a, $all, 2024)['allocated'], 'all carries, capped by max balance 12 (8 + 4)');
    assert_eq(null, lp_bal($a, $non, 2024)['allocated'], 'none carries nothing');
    foreach ([$lim, $all, $non] as $t) { $b = lp_bal($a, $t, 2023); assert_eq(0.0, (float) $b['allocated'] - (float) $b['used'], 'old year closed'); assert_eq(0.0, lp_ledger_sum($a, $t, 2023) , 'old year ledger nets to zero'); }
    assert_eq(2.0, -(float) DB::value("SELECT days FROM leave_ledger WHERE user_id=? AND leave_type_id=? AND kind='expiry'", [$a, $lim]), '2 days lapse');
    assert_eq(0, LeaveService::carryForward(2023), 'idempotent');
    assert_eq(5.0, (float) lp_bal($a, $lim, 2024)['allocated'], 'not carried twice');
    DB::query('UPDATE leave_types SET active=0 WHERE id IN (?,?,?)', [$lim, $all, $non]); },
  'policy validation: half days, notice, max consecutive, service days, employment types' => function () {
    ['b' => $b, 'hr' => $hr] = lp_fixture();
    $t = ['name' => 'Strict', 'paid' => 1, 'allow_half_day' => 0, 'notice_days' => 7, 'max_consecutive' => 3, 'min_service_days' => 90, 'employment_types' => 'part_time,contract'];
    $e = LeaveService::validateRequest($b, $t, '2025-05-12', '2025-05-12', true, 0.5, '2025-05-10');
    assert_true(isset($e['half_day']) && str_contains($e['half_day'], 'half day'), 'half day refused');
    assert_true(isset($e['start_date']) && str_contains($e['start_date'], '7 days notice'), 'notice: '.json_encode($e));
    $e = LeaveService::validateRequest($b, $t, '2025-06-02', '2025-06-06', false, 5, '2025-05-10');
    assert_true(isset($e['end_date']) && str_contains($e['end_date'], 'At most 3'), 'max consecutive');
    assert_true(isset($e['leave_type_id']) && str_contains($e['leave_type_id'], 'full-time'), 'employment type: '.json_encode($e));
    $t['employment_types'] = null;
    $e = LeaveService::validateRequest($b, $t, '2025-05-20', '2025-05-20', false, 1, '2025-05-10');
    assert_true(isset($e['leave_type_id']) && str_contains($e['leave_type_id'], '90 days of service'), 'service days: '.json_encode($e));
    assert_eq([], LeaveService::validateRequest($b, $t, '2025-07-01', '2025-07-02', false, 2, '2025-06-01'), 'valid request passes');
    act_as($hr);
    http('POST', '/hr/leave/types', ['name' => 'Work from home', 'code' => 'wfh', 'paid' => 1, 'color' => 'sky', 'active' => 1, 'allowance' => 'none', 'carry_forward' => 'none', 'allow_half_day' => 1, 'needs_approval' => 0, 'notice_days' => 0, 'employment_types' => ['full_time']]);
    $wfh = DB::first("SELECT * FROM leave_types WHERE code='WFH'");
    assert_true((bool) $wfh, 'type created through the form');
    assert_eq(0, (int) $wfh['needs_approval']); assert_eq('full_time', $wfh['employment_types'], 'one of five employment types stored');
    LeaveService::adjust($b, (int) $wfh['id'], 2031, 3, 'Grant', $hr);
    act_as($b);
    http('POST', '/hr/leave', ['leave_type_id' => $wfh['id'], 'start_date' => '2031-02-04', 'end_date' => '2031-02-04']);
    $lr = DB::first('SELECT * FROM leave_requests WHERE user_id=? AND leave_type_id=?', [$b, $wfh['id']]);
    assert_eq('approved', $lr['status'] ?? null, 'no approval needed: booked as approved');
    assert_eq(1.0, (float) lp_bal($b, (int) $wfh['id'], 2031)['used']);
    assert_eq(-1.0, (float) DB::value("SELECT days FROM leave_ledger WHERE kind='usage' AND ref_id=?", [$lr['id']]), 'usage in ledger');
    act_as($hr);
    http('POST', '/hr/leave/types/'.$wfh['id'], ['name' => 'Work from home', 'code' => 'WFH', 'paid' => 1, 'color' => 'sky', 'active' => 1, 'allowance' => 'none', 'carry_forward' => 'none', 'needs_approval' => 1, 'notice_days' => 2, 'max_consecutive' => 1]);
    act_as($b);
    $before = (int) DB::value('SELECT COUNT(*) FROM leave_requests WHERE user_id=?', [$b]);
    for ($day = strtotime('+10 days'); !LeaveService::isWorkingDayFor($b, date('Y-m-d', $day)); $day = strtotime('+1 day', $day)) {}
    $res = http('POST', '/hr/leave', ['leave_type_id' => $wfh['id'], 'start_date' => date('Y-m-d', $day), 'end_date' => date('Y-m-d', $day), 'half_day' => 1]);
    assert_eq($before, (int) DB::value('SELECT COUNT(*) FROM leave_requests WHERE user_id=?', [$b]), 'half day refused after policy change');
    $fl = json_encode(\Core\Session::get('_flash_next'));
    assert_true(str_contains($fl.$res->body(), 'cannot be taken as a half day'), 'clear message (flash or JSON): '.$fl);
    DB::update('leave_types', ['active' => 0], 'id=?', [$wfh['id']]); },
  'shortage cover: preview, commit replaces the period, limited by balance, switch off' => function () {
    ['d' => $d, 'hr' => $hr] = lp_fixture();
    $pl = (int) DB::value("SELECT id FROM leave_types WHERE code='PL'");
    LeaveService::adjust($d, $pl, 2025, 5, 'Opening', $hr);
    $p = LeaveService::shortageCover($d, '2025-06', 10, 8, false);
    assert_eq(['days' => 1.5, 'hours' => 10.0, 'type_id' => $pl, 'balance_after' => 3.5], $p, 'preview: 10 h / 8 = 1.25 days, rounded to 1.5');
    assert_eq(0.0, (float) lp_bal($d, $pl, 2025)['used'], 'preview writes nothing');
    LeaveService::shortageCover($d, '2025-06', 10, 8, true);
    assert_eq(1.5, (float) lp_bal($d, $pl, 2025)['used']);
    $r = LeaveService::shortageCover($d, '2025-06', 4, 8, true);
    assert_eq(0.5, $r['days'], '4 h = half a day');
    assert_eq(0.5, (float) lp_bal($d, $pl, 2025)['used'], 're-run replaces the earlier cover');
    assert_eq(1, (int) DB::value("SELECT COUNT(*) FROM leave_ledger WHERE user_id=? AND kind='shortage' AND period='2025-06'", [$d]));
    $r = LeaveService::shortageCover($d, '2025-07', 100, 8, true);
    assert_eq(4.5, $r['days'], 'limited to the 4.5 days still available');
    assert_eq(36.0, $r['hours']); assert_eq(0.0, $r['balance_after']);
    assert_eq(0.0, LeaveService::shortageCover($d, '2025-08', 8, 8, false)['days'], 'nothing left');
    assert_eq(4.5, LeaveService::shortageRevert($d, '2025-07'), 'revert gives days back');
    DB::insert('leave_requests', ['user_id' => $d, 'leave_type_id' => $pl, 'start_date' => '2025-09-01', 'end_date' => '2025-09-04', 'days' => 4, 'status' => 'pending', 'created_at' => date('Y-m-d H:i:s')]);
    assert_eq(0.5, LeaveService::shortageCover($d, '2025-08', 16, 8, false)['days'], 'pending requests are reserved');
    Settings::set('hr.leave.shortage_cover', '0');
    $r = LeaveService::shortageCover($d, '2025-06', 10, 8, true);
    assert_eq(0.0, $r['days'], 'switched off');
    assert_eq(0.0, (float) lp_bal($d, $pl, 2025)['used'], 'earlier cover of the period reversed');
    Settings::set('hr.leave.shortage_cover', '1');
    assert_eq(0.0, LeaveService::shortageCover($d, '2025-06', 3, 0, false)['days'], 'no hours per day: nothing'); },
  'holidays: defaults seeded only when missing, next year from last year with AI dates (mocked)' => function () {
    $before = (int) DB::value('SELECT COUNT(*) FROM holidays');
    $keep = DB::all("SELECT * FROM holidays WHERE YEAR(holiday_date) IN (2026, 2027, 2040, 2041)");
    DB::query("DELETE FROM holidays WHERE YEAR(holiday_date) IN (2026, 2027, 2040, 2041)");
    assert_eq(7, HolidayService::seedDefaults([2026]), 'all 7 defaults for 2026');
    $dates = DB::pairs("SELECT name, holiday_date FROM holidays WHERE holiday_date BETWEEN '2026-01-01' AND '2026-12-31'");
    assert_eq('2026-03-03', $dates['Holika Dahan']); assert_eq('2026-03-04', $dates['Rangwali Holi (Dhulandi)']); assert_eq('2026-11-08', $dates['Diwali']); assert_eq('2026-11-09', $dates['Day after Diwali (Govardhan Puja)']);
    assert_eq('2026-01-26', $dates['Republic Day']); assert_eq('2026-08-15', $dates['Independence Day']); assert_eq('2026-10-02', $dates['Gandhi Jayanti']);
    assert_eq(0, HolidayService::seedDefaults([2026]), 'idempotent');
    DB::query("DELETE FROM holidays WHERE holiday_date BETWEEN '2026-01-01' AND '2026-12-31'");
    DB::insert('holidays', ['holiday_date' => '2040-03-01', 'name' => 'Holi', 'optional' => 0, 'movable' => 1]);
    assert_eq(3, HolidayService::seedDefaults([2040]), 'unknown year: fixed-date ones only; existing "Holi" counts as Dhulandi; Holika and Diwali dates unknown');
    DB::insert('holidays', ['holiday_date' => '2040-11-04', 'name' => 'Diwali', 'optional' => 0, 'movable' => 1]);
    DB::insert('holidays', ['holiday_date' => '2040-12-25', 'name' => 'Christmas', 'optional' => 0, 'movable' => 0]);
    DB::insert('holidays', ['holiday_date' => '2040-04-10', 'name' => 'Eid ul-Fitr', 'optional' => 1, 'movable' => 1]);
    $asked = [];
    HolidayService::$complete = static function (string $sys, string $prompt) use (&$asked): string { $asked[] = $prompt; return "Sure!\n```json\n{\"year\":2041,\"holidays\":[{\"name\":\"Holi\",\"date\":\"2041-03-19\"},{\"name\":\"Diwali\",\"date\":\"2041-10-24\"},{\"name\":\"Eid ul-Fitr\",\"date\":null}]}\n```"; };
    try { $r = HolidayService::createYear(2041, true, null); } finally { HolidayService::$complete = null; }
    assert_eq(7, $r['created']); assert_eq(2, $r['ai'], 'two festival dates from AI'); assert_eq(1, $r['unverified'], 'Eid copied as a placeholder');
    assert_true(str_contains($asked[0], '2041') && str_contains($asked[0], 'Diwali') && !str_contains($asked[0], 'Christmas'), 'only movable names are asked');
    $n = DB::pairs("SELECT name, CONCAT(holiday_date,'|',verified) FROM holidays WHERE holiday_date BETWEEN '2041-01-01' AND '2041-12-31'");
    assert_eq('2041-12-25|1', $n['Christmas']); assert_eq('2041-01-26|1', $n['Republic Day']); assert_eq('2041-10-24|1', $n['Diwali']); assert_eq('2041-04-10|0', $n['Eid ul-Fitr']);
    assert_eq(0, HolidayService::createYear(2041, true, null)['created'], 'existing names skipped');
    $r = HolidayService::createYear(2027, false, null);
    assert_eq(0, $r['source'], 'nothing to copy from an empty year');
    DB::insert('holidays', ['holiday_date' => '2026-11-08', 'name' => 'Diwali', 'optional' => 0, 'movable' => 1]);
    $r = HolidayService::createYear(2027, false, null);
    assert_eq('2027-10-29', DB::value("SELECT holiday_date FROM holidays WHERE name='Diwali' AND YEAR(holiday_date)=2027"), 'known date used without AI');
    DB::query("DELETE FROM holidays WHERE YEAR(holiday_date) IN (2026, 2027, 2040, 2041)");
    foreach ($keep as $h) { DB::insert('holidays', $h); }
    LeaveService::flush();
    assert_eq($before, (int) DB::value('SELECT COUNT(*) FROM holidays'), 'cleaned up'); },
  'AI date fix: strict JSON parsing, preview and apply' => function () {
    $names = ['Holi', 'Diwali', 'Eid ul-Fitr'];
    assert_eq(['Holi' => '2030-03-20', 'Diwali' => '2030-10-26'], HolidayService::parseDates('{"year":2030,"holidays":[{"name":"holi","date":"2030-03-20"},{"name":"Diwali","date":"2030-10-26"},{"name":"Eid ul-Fitr","date":null}]}', $names, 2030));
    assert_eq(['Diwali' => '2030-10-26'], HolidayService::parseDates("Here you go:\n```json\n[{\"name\":\"Diwali\",\"date\":\"2030-10-26\"},{\"name\":\"Holi\",\"date\":\"2031-03-20\"},{\"name\":\"Eid ul-Fitr\",\"date\":\"2030-02-30\"},{\"name\":\"Pongal\",\"date\":\"2030-01-14\"}]\n```", $names, 2030), 'wrong year, invalid date and unknown names dropped');
    assert_eq(['Holi' => '2030-03-20'], HolidayService::parseDates('{"Holi": "2030-03-20", "Diwali": "soon"}', $names, 2030), 'name => date map');
    assert_eq([], HolidayService::parseDates('I am not sure.', $names, 2030));
    ['hr' => $hr] = lp_fixture();
    $id = DB::insert('holidays', ['holiday_date' => '2030-10-20', 'name' => 'Diwali', 'optional' => 0, 'movable' => 1, 'verified' => 0]);
    $fixed = DB::insert('holidays', ['holiday_date' => '2030-01-26', 'name' => 'Republic Day', 'optional' => 0]);
    act_as($hr);
    $page = http('GET', '/hr/holidays?year=2030');
    assert_true(str_contains($page->body(), 'Fix dates with AI') && str_contains($page->body(), 'disabled'), 'button shown disabled without AI');
    HolidayService::$complete = static fn () => '{"holidays":[{"name":"Diwali","date":"2030-10-26"},{"name":"Republic Day","date":"2030-01-26"}]}';
    try {
      http('POST', '/hr/holidays/ai-fix', ['year' => 2030]);
      $f = \Core\Session::get('hr_ai_fix');
      assert_eq(2030, $f['year']);
      $row = array_values(array_filter($f['rows'], static fn ($r) => $r['id'] === $id))[0];
      assert_true($row['changed'] && $row['old'] === '2030-10-20' && $row['new'] === '2030-10-26', 'diff old -> new');
      assert_true(!array_values(array_filter($f['rows'], static fn ($r) => $r['id'] === $fixed))[0]['changed'], 'unchanged row');
      $html = http('GET', '/hr/holidays?year=2030')->body();
      assert_true(str_contains($html, 'AI date check for 2030') && str_contains($html, 'Apply ticked dates'), 'preview shown'); }
    finally { HolidayService::$complete = null; }
    assert_eq(302, http('POST', '/hr/holidays/ai-apply', ['year' => 2030, 'apply' => [$id]])->status());
    $h = DB::first('SELECT holiday_date, verified FROM holidays WHERE id=?', [$id]);
    assert_eq('2030-10-26', $h['holiday_date']); assert_eq(1, (int) $h['verified']);
    assert_eq(null, \Core\Session::get('hr_ai_fix'), 'proposal consumed');
    DB::query('DELETE FROM holidays WHERE id IN (?,?)', [$id, $fixed]); LeaveService::flush(); },
  'pages render: leave types with policy, balances, ledger, holidays' => function () {
    ['hr' => $hr, 'a' => $a] = lp_fixture();
    act_as($hr);
    $t = http('GET', '/hr/leave/types');
    assert_eq(200, $t->status());
    assert_true(str_contains($t->body(), '2.5 day(s) every month') && str_contains($t->body(), 'Covers hour shortages'), 'policy summary');
    assert_eq(200, http('GET', '/hr/leave/balances')->status());
    $l = http('GET', '/hr/leave/ledger?user='.$a.'&year=2025');
    assert_eq(200, $l->status());
    assert_true(str_contains($l->body(), 'Monthly accrual'), 'ledger lists accruals');
    act_as($a);
    $own = http('GET', '/hr/leave/ledger?user='.$hr);
    assert_true(str_contains($own->body(), 'My leave ledger'), 'employees only see their own ledger');
    assert_eq(200, http('GET', '/hr/holidays')->status()); },
];
