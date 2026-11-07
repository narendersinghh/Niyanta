<?php
use Core\{DB, Features, Settings};
use Modules\Hr\Services\LeaveService;
use Modules\Payroll\Services\{PayrollService, PayslipEditor};

/** Employee with a monthly salary (and optional personal components) for the edit tests. */
function pe_employee(string $email, float $salary, array $components = []): int {
  $u = make_user('employee', $email, ['joined_on' => '2030-01-01']);
  DB::insert('compensations', ['user_id' => $u, 'pay_type' => 'monthly', 'amount' => $salary, 'effective_from' => '2030-01-01', 'created_at' => date('Y-m-d H:i:s')]);
  foreach ($components as [$name, $kind, $calc, $value]) { DB::insert('pay_components', ['user_id' => $u, 'name' => $name, 'kind' => $kind, 'calc' => $calc, 'value' => $value, 'active' => 1, 'created_at' => date('Y-m-d H:i:s')]); }
  return $u; }

function pe_slip(int $run, int $u): array { return DB::first('SELECT s.*, r.status AS run_status, r.period_month FROM payslips s JOIN payroll_runs r ON r.id=s.run_id WHERE s.run_id=? AND s.user_id=?', [$run, $u]); }
function pe_codes(array $slip, string $side = 'earnings'): array { return array_column(json_decode((string) $slip[$side], true) ?: [], 'amount', 'code'); }
function pe_tracked(int $u, string $date, float $h): void { $s = strtotime($date.' 09:00:00'); $sec = (int) round($h * 3600); DB::insert('time_entries', ['user_id' => $u, 'started_at' => date('Y-m-d H:i:s', $s), 'ended_at' => date('Y-m-d H:i:s', $s + $sec), 'duration_seconds' => $sec, 'status' => 'approved', 'is_running' => 0, 'source' => 'manual']); }

return [
  'draft payslip edits: manual lines with notes, line and hours overrides, live totals, audit before/after' => function () {
    Settings::set('payroll.salary_from_tracked_time', '0');
    $u = pe_employee('edit@payedit.test', 30000, [['HRA', 'allowance', 'fixed', 2000], ['PF', 'deduction', 'percent', 10]]);
    act_as($GLOBALS['ADMIN_ID']);
    $run = DB::insert('payroll_runs', ['period_month' => '2034-03-01', 'status' => 'draft', 'created_at' => date('Y-m-d H:i:s')]);
    PayrollService::generate($run);
    $s = pe_slip($run, $u);
    assert_eq(32000.0, (float) $s['gross']); assert_eq(3000.0, (float) $s['total_deductions']); assert_eq(29000.0, (float) $s['net']);
    assert_true(PayslipEditor::calc($s) !== null, 'calculation inputs stored');
    $hra = array_search(2000.0, pe_codes($s)); $pf = array_key_first(pe_codes($s, 'deductions'));
    $in = ['lines' => [$hra => ['amount' => '2500', 'note' => 'Rent revised'], $pf => ['removed' => '1', 'note' => 'Opted out']],
      'manual' => [['type' => 'bonus', 'name' => 'Diwali bonus', 'amount' => '5000', 'note' => 'Approved by director'], ['type' => 'one_time', 'name' => '', 'amount' => '1000', 'note' => 'Laptop damage'], ['type' => 'bonus', 'name' => '', 'amount' => '']]];
    $edits = PayslipEditor::normalize($in, $s);
    assert_eq(2, count($edits['manual']), 'blank rows are ignored'); assert_eq('One-time deduction', $edits['manual'][1]['name'], 'default name from the kind');
    $p = PayslipEditor::preview($s, $edits);
    assert_eq(37500.0, $p['gross'], '30000 + HRA 2500 + bonus 5000'); assert_eq(1000.0, $p['total_deductions'], 'PF removed, one-time deduction added'); assert_eq(36500.0, $p['net']);
    assert_eq(29000.0, (float) pe_slip($run, $u)['net'], 'preview does not save');
    assert_throws(\DomainException::class, fn () => PayslipEditor::save((int) $s['id'], $edits, ''), 'a note is required');
    assert_throws(\DomainException::class, fn () => PayslipEditor::normalize(['manual' => [['type' => 'bonus', 'name' => 'X', 'amount' => '-5']]], $s), 'negative amount');
    assert_throws(\DomainException::class, fn () => PayslipEditor::normalize(['manual' => [['type' => 'gift', 'name' => 'X', 'amount' => '5']]], $s), 'unknown kind');
    $r = PayslipEditor::save((int) $s['id'], $edits, 'March corrections');
    assert_true($r['changed']); assert_eq(36500.0, $r['net']);
    $s2 = pe_slip($run, $u);
    assert_eq(36500.0, (float) $s2['net']); assert_true($s2['edits'] !== null && (int) $s2['edited_by'] === $GLOBALS['ADMIN_ID'], 'edits stored with editor');
    assert_eq(36500.0, (float) DB::value('SELECT SUM(net) FROM payslips WHERE run_id=? AND user_id=?', [$run, $u]));
    assert_true((float) DB::value('SELECT total_net FROM payroll_runs WHERE id=?', [$run]) == (float) DB::value('SELECT SUM(net) FROM payslips WHERE run_id=?', [$run]), 'run total follows edits');
    $earn = json_decode((string) $s2['earnings'], true);
    assert_true((bool) array_filter($earn, static fn ($l) => !empty($l['manual']) && $l['name'] === 'Diwali bonus' && $l['note'] === 'Approved by director'), 'manual line with note on the payslip');
    $a = json_decode((string) DB::value("SELECT meta FROM audit_logs WHERE action='payroll.payslip.edit' AND entity_id=? ORDER BY id DESC LIMIT 1", [$s['id']]), true);
    assert_eq(29000.0, $a['before']['net']); assert_eq(36500.0, $a['after']['net']); assert_eq('March corrections', $a['reason']);
    assert_true(count($a['changes']) >= 4 && (bool) array_filter($a['changes'], static fn ($c) => str_contains($c, 'Diwali bonus')), 'changes listed');
    assert_eq(false, PayslipEditor::save((int) $s['id'], $edits, '')['changed'], 'same edits: nothing to save, no note needed');
    // edit a manual line, remove the other
    $e2 = PayslipEditor::normalize(['lines' => $in['lines'], 'manual' => [['id' => $edits['manual'][0]['id'], 'type' => 'bonus', 'name' => 'Diwali bonus', 'amount' => '6000', 'note' => 'Revised']]], $s2);
    assert_eq($edits['manual'][0]['id'], $e2['manual'][0]['id'], 'line id kept');
    assert_eq(38500.0, PayslipEditor::save((int) $s['id'], $e2, 'Bonus revised')['net'], 'bonus 6000, one-time deduction removed');
    // regenerate keeps manual edits; discard brings back the calculated figures
    $r = PayrollService::generate($run);
    assert_true($r['kept'] >= 1); assert_eq(38500.0, (float) pe_slip($run, $u)['net'], 'edits survive regeneration');
    $html = (string) http('GET', '/payroll/runs/'.$run)->body();
    assert_true(str_contains($html, 'Edited') && str_contains($html, 'Discard manual edits') && str_contains($html, '/payroll/payslips/'.pe_slip($run, $u)['id'].'/edit'), 'run page: edited badge, regenerate choice, edit link');
    $r = PayrollService::generate($run, true);
    assert_true($r['discarded'] >= 1); $s3 = pe_slip($run, $u);
    assert_eq(29000.0, (float) $s3['net']); assert_true($s3['edits'] === null, 'edits discarded');
    // bulk line and HTTP endpoints
    act_as($GLOBALS['ADMIN_ID']);
    $res = http('POST', '/payroll/runs/'.$run.'/lines', ['type' => 'bonus', 'name' => 'Festival bonus', 'amount' => '1500', 'note' => 'For everyone', 'slips' => [$s3['id']]]);
    assert_true($res->status() === 302, 'bulk line redirects'); assert_eq(30500.0, (float) pe_slip($run, $u)['net']);
    assert_eq(1, (int) DB::value("SELECT COUNT(*) FROM audit_logs WHERE action='payroll.payslip.bulk_line' AND entity_id=?", [$s3['id']]));
    $prev = http('POST', '/payroll/payslips/'.$s3['id'].'/preview', ['manual' => [['type' => 'one_time', 'name' => 'Advance', 'amount' => '500']]]);
    $j = json_decode((string) $prev->body(), true);
    assert_true($j['ok'] && str_contains($j['net'], '28,500') && $j['deltaText'] !== '', 'preview endpoint (the form posts every line): '.$prev->body());
    assert_true(str_contains((string) http('GET', '/payroll/payslips/'.$s3['id'].'/edit')->body(), 'Festival bonus'), 'edit page lists manual lines');
    $res = http('POST', '/payroll/payslips/'.$s3['id'].'/edit', ['reason' => 'Advance recovery', 'manual' => [['type' => 'one_time', 'name' => 'Advance', 'amount' => '500']]]);
    assert_eq(28500.0, (float) pe_slip($run, $u)['net'], 'form save replaces manual lines (festival bonus removed, advance added)');
    http('POST', '/payroll/payslips/'.$s3['id'].'/reset', ['reason' => 'Start over']);
    assert_eq(29000.0, (float) pe_slip($run, $u)['net'], 'reset');
    assert_eq(1, (int) DB::value("SELECT COUNT(*) FROM audit_logs WHERE action='payroll.payslip.reset' AND entity_id=?", [$s3['id']]));
    Settings::set('payroll.salary_from_tracked_time', '1'); },

  'employees never see draft payslips; Finalize & notify publishes; reopen needs a reason and is refused once paid' => function () {
    Settings::set('payroll.salary_from_tracked_time', '0');
    $u = pe_employee('vis@payedit.test', 20000);
    $hr = make_user('hr', 'hr@payedit.test');
    act_as($hr);
    $run = PayrollService::createRun('2034-04');
    $s = pe_slip($run, $u);
    act_as($u);
    assert_eq(404, http('GET', '/payslips/'.$s['id'])->status(), 'draft payslip hidden');
    assert_eq(404, http('GET', '/payslips/'.$s['id'].'/pdf')->status(), 'draft PDF hidden');
    assert_true(!str_contains((string) http('GET', '/payslips')->body(), 'April 2034'), 'draft not listed');
    assert_eq(403, http('GET', '/payroll/payslips/'.$s['id'].'/edit')->status(), 'employees cannot open the editor');
    assert_true(in_array(http('POST', '/payroll/payslips/'.$s['id'].'/preview', [])->status(), [403, 404], true), 'nor preview');
    assert_eq(0, (int) DB::value('SELECT COUNT(*) FROM notifications WHERE user_id=?', [$u]), 'no notification for a draft');
    act_as($hr);
    PayslipEditor::save((int) $s['id'], ['manual' => [['id' => 'abcdef12', 'kind' => 'earning', 'type' => 'bonus', 'name' => 'Spot bonus', 'amount' => 700.0, 'note' => '']]], 'Spot award');
    assert_eq(1, PayrollService::finalize($run) > 0 ? 1 : 0);
    $r = DB::first('SELECT * FROM payroll_runs WHERE id=?', [$run]);
    assert_true($r['status'] === 'finalized' && $r['notified_at'] !== null, 'published and notified');
    assert_eq(1, (int) DB::value("SELECT COUNT(*) FROM notifications WHERE user_id=? AND title LIKE '%is ready%'", [$u]));
    act_as($u);
    $page = http('GET', '/payslips/'.$s['id']);
    assert_eq(200, $page->status()); assert_true(str_contains((string) $page->body(), 'Spot bonus') && !str_contains((string) $page->body(), 'Added by HR'), 'employee sees the bonus, not the internal markers');
    act_as($hr);
    assert_throws(\DomainException::class, fn () => PayslipEditor::save((int) $s['id'], [], 'x'), 'published payslips are locked');
    assert_throws(\DomainException::class, fn () => PayrollService::reopen($run, ''), 'reason required');
    PayrollService::reopen($run, 'Missed overtime');
    assert_eq('draft', DB::value('SELECT status FROM payroll_runs WHERE id=?', [$run]));
    assert_eq(1, (int) DB::value("SELECT COUNT(*) FROM audit_logs WHERE action='payroll.run.reopen' AND entity_id=?", [$run]));
    act_as($u);
    assert_eq(404, http('GET', '/payslips/'.$s['id'])->status(), 'hidden again after reopening');
    act_as($hr);
    PayrollService::finalize($run);
    assert_eq(1, (int) DB::value("SELECT COUNT(*) FROM notifications WHERE user_id=? AND title LIKE '%was updated%'", [$u]), 'republish says updated');
    PayrollService::markPaid($run);
    assert_throws(\DomainException::class, fn () => PayrollService::reopen($run, 'Too late'), 'paid runs cannot be reopened');
    assert_eq(302, http('POST', '/payroll/runs/'.$run.'/reopen', ['reason' => 'Too late'])->status());
    assert_eq('paid', DB::value('SELECT status FROM payroll_runs WHERE id=?', [$run]));
    Settings::set('payroll.salary_from_tracked_time', '1'); },

  'shortage of hours covered from paid leave (stub for LeaveService::shortageCover): payable, payslip line, commit per month, release' => function () {
    Settings::set('general.weekend_days', '6,7'); Settings::set('general.saturday_rule', 'full'); Settings::set('hr.work_hours_per_day', '8');
    Settings::set('payroll.salary_from_tracked_time', '1'); Settings::set('payroll.flex_mode', 'monthly'); Settings::set('payroll.overtime_enabled', '0');
    LeaveService::flush();
    assert_eq(method_exists(LeaveService::class, 'shortageCover') && feature_on('leave'), PayrollService::shortageOn(), 'guarded by method_exists without a stub');
    $u = pe_employee('cover@payedit.test', 17600);
    foreach (['2037-03-02', '2037-03-03', '2037-03-04', '2037-03-05', '2037-03-06', '2037-03-09', '2037-03-10', '2037-03-11', '2037-03-12', '2037-03-13'] as $d) { pe_tracked($u, $d, 8); }
    $calls = [];
    PayrollService::$shortageCover = static function (int $uid, string $ym, float $short, float $hpd, bool $commit) use (&$calls, $u): array {
      if ($uid === $u) { $calls[] = [$ym, $short, $hpd, $commit]; }
      $days = min(2.0, floor($short / $hpd * 2) / 2);
      return ['days' => $days, 'hours' => $days * $hpd, 'type_id' => 7, 'balance_after' => 10 - $days]; };
    try {
      assert_true(PayrollService::shortageOn(), 'stub enables cover');
      act_as($GLOBALS['ADMIN_ID']);
      $run = DB::insert('payroll_runs', ['period_month' => '2037-03-01', 'status' => 'draft', 'created_at' => date('Y-m-d H:i:s')]);
      PayrollService::generate($run);
      $s = pe_slip($run, $u);
      $exp = (float) $s['expected_hours'];
      assert_eq(176.0, $exp, 'March 2037: 22 weekdays x 8 h');
      $c = PayrollService::coverOf($s);
      assert_true($c !== null && $c['hours'] == 16.0 && $c['days'] == 2.0, 'two days of paid leave cover 16 h');
      assert_eq(96.0, (float) $s['payable_hours'], '80 h worked + 16 h paid leave');
      assert_eq(9600.0, (float) $s['base_pay'], '100 per hour x 96 h');
      assert_eq(['2037-03', 96.0, 8.0, true], $calls[0], 'committed for the run month with the missing hours');
      assert_true(in_array(['Shortage covered by Paid Leave', '2 days (16 h)'], PayrollService::hoursLines($s), true), 'payslip line');
      assert_true(str_contains((string) $s['earnings'], '16 h paid leave'), 'basic pay label mentions the leave');
      act_as($GLOBALS['ADMIN_ID']);
      assert_true(str_contains((string) http('GET', '/payslips/'.$s['id'])->body(), 'Shortage covered by Paid Leave: 2 days (16 h)'), 'payslip page');
      // hours override: preview does not commit, save re-commits for the new shortage
      $calls = [];
      $p = PayslipEditor::preview($s, ['hours' => ['payable' => 170.0]]);
      assert_eq(174.0, (float) $p['payable_hours'], '170 h + half a day (4 h) of the 6 missing hours'); assert_eq(false, $calls[0][3], 'preview is not recorded');
      $calls = [];
      PayslipEditor::save((int) $s['id'], ['hours' => ['payable' => 172.0]], 'Approved missing timesheet');
      assert_eq(['2037-03', 4.0, 8.0, true], $calls[0], 'save records the new shortage');
      assert_eq(176.0, (float) pe_slip($run, $u)['payable_hours'], '172 h + half a day');
      assert_eq(4.0, (float) PayrollService::coverOf(pe_slip($run, $u))['hours']);
      // regenerate re-commits idempotently; HR edits kept
      $calls = [];
      PayrollService::generate($run);
      assert_eq(['2037-03', 4.0, 8.0, true], $calls[0], 'regenerate records again for the month (idempotent)');
      PayrollService::generate($run, true);
      assert_eq(16.0, (float) PayrollService::coverOf(pe_slip($run, $u))['hours'], 'edits discarded: tracked shortage covered again');
      // setting off or leave feature off: skipped
      Settings::set('hr.leave.shortage_cover', '0');
      $calls = [];
      PayrollService::generate($run);
      assert_true(!$calls && pe_slip($run, $u)['shortage_cover'] === null && (float) pe_slip($run, $u)['payable_hours'] === 80.0, 'setting off: missing hours deducted');
      Settings::set('hr.leave.shortage_cover', '1');
      Features::set('feature:leave', false);
      try { PayrollService::generate($run); assert_true(!$calls, 'leave feature off: skipped'); }
      finally { Features::set('feature:leave', true); }
      PayrollService::generate($run);
      assert_true(pe_slip($run, $u)['shortage_cover'] !== null);
      // deleting the draft releases the cover
      $calls = [];
      PayrollService::deleteRun($run);
      assert_eq(['2037-03', 0.0, 8.0, true], $calls[0], 'deleting the draft records 0 h'); }
    finally { PayrollService::$shortageCover = null; } },

  'pure: shortage cover only for monthly salaries paid from hours; hourly and day-based pay untouched' => function () {
    $cover = static fn (float $short): array => ['days' => 1.0, 'hours' => min(8.0, $short), 'type_id' => null, 'balance_after' => 0.0];
    $hx = ['expected' => 160, 'worked' => 150, 'payable' => 150, 'overtime' => 0, 'mode' => 'monthly'];
    $s = PayrollService::computeSlip(['comp' => ['pay_type' => 'monthly', 'amount' => 16000], 'wd' => 20, 'unpaid' => 0, 'hours' => 150, 'components' => [], 'reimb' => 0, 'hx' => $hx], [], $cover);
    assert_eq(158.0, $s['payable_hours']); assert_eq(15800.0, $s['base_pay']); assert_eq(8.0, $s['shortage_cover']['hours']);
    $s = PayrollService::computeSlip(['comp' => ['pay_type' => 'hourly', 'amount' => 100], 'wd' => 0, 'unpaid' => 0, 'hours' => 150, 'components' => [], 'reimb' => 0, 'hx' => $hx], [], $cover);
    assert_true($s['shortage_cover'] === null && $s['base_pay'] == 15000.0, 'hourly pay is never topped up from leave');
    $s = PayrollService::computeSlip(['comp' => ['pay_type' => 'monthly', 'amount' => 16000], 'wd' => 20, 'unpaid' => 2, 'hours' => 0, 'components' => [], 'reimb' => 0, 'hx' => null], [], $cover);
    assert_true($s['shortage_cover'] === null && $s['base_pay'] == 14400.0, 'day-based pay unchanged');
    $s = PayrollService::computeSlip(['comp' => ['pay_type' => 'monthly', 'amount' => 16000], 'wd' => 20, 'unpaid' => 0, 'hours' => 150, 'components' => [], 'reimb' => 0, 'hx' => $hx], ['hours' => ['payable' => 160.0]], $cover);
    assert_true($s['shortage_cover'] === null && $s['base_pay'] == 16000.0 && $s['edited'], 'override removes the shortage'); },
];
