<?php
use Core\{Cron, DB, Settings};
use Modules\Payroll\Services\{PayrollAccess, PayrollService, PayslipEditor};

/** Monthly-salaried user for the auto draft tests. */
function ad_person(string $role, string $email, string $name, float $salary, ?int $manager = null): int {
  $u = make_user($role, $email, ['name' => $name, 'manager_id' => $manager, 'joined_on' => '2040-01-01']);
  DB::insert('compensations', ['user_id' => $u, 'pay_type' => 'monthly', 'amount' => $salary, 'effective_from' => '2040-01-01', 'created_at' => date('Y-m-d H:i:s')]);
  return $u; }
function ad_slip(int $run, int $u): array { return DB::first('SELECT s.*, r.status AS run_status, r.period_month FROM payslips s JOIN payroll_runs r ON r.id=s.run_id WHERE s.run_id=? AND s.user_id=?', [$run, $u]); }
function ad_notes(int $u, string $like = '%'): int { return (int) DB::value('SELECT COUNT(*) FROM notifications WHERE user_id=? AND title LIKE ?', [$u, $like]); }
function ad_bonus(string $name, float $amount): array { return ['manual' => [['type' => 'bonus', 'name' => $name, 'amount' => (string) $amount, 'note' => 'Review']]]; }

return [
  'cron creates the previous month draft once, notifies HR and team managers but no employee, never regenerates over edits' => function () {
    Settings::set('payroll.salary_from_tracked_time', '0'); Settings::set('payroll.auto_draft', '1');
    $jobs = Cron::jobs();
    assert_true(isset($jobs['payroll.payroll_auto_draft']) && $jobs['payroll.payroll_auto_draft']['monthly'] === '1 06:10', 'scheduled on the 1st');
    $hr = make_user('hr', 'hr@autodraft.test');
    $mgr = ad_person('manager', 'mgr@autodraft.test', 'Mira Manager', 50000);
    $a = ad_person('employee', 'a@autodraft.test', 'Asha Teammate', 30000, $mgr);
    $b = ad_person('employee', 'b@autodraft.test', 'Bilal Outsider', 25000);
    $lone = make_user('manager', 'lone@autodraft.test', ['name' => 'Lone Manager']);
    act_as(null);
    $out = PayrollService::autoDraft('2041-03-01 06:10:00');
    $run = DB::first("SELECT * FROM payroll_runs WHERE period_month='2041-02-01'");
    assert_true($run !== null, 'February run created on 1 March: '.$out);
    assert_true($run['status'] === 'draft' && (int) $run['auto_created'] === 1 && $run['created_by'] === null, 'automatic draft');
    $n = (int) DB::value('SELECT COUNT(*) FROM payslips WHERE run_id=?', [$run['id']]);
    assert_true($n >= 3 && ad_slip((int) $run['id'], $a) && ad_slip((int) $run['id'], $b), 'payslips generated');
    assert_eq(1, (int) DB::value("SELECT COUNT(*) FROM audit_logs WHERE action='payroll.run.create' AND entity_id=?", [$run['id']]));
    assert_eq(1, ad_notes($hr, '%ready to review%'), 'HR told the draft is ready');
    assert_eq(1, ad_notes($GLOBALS['ADMIN_ID'], '%ready to review%'), 'super admin too');
    assert_eq('/payroll/runs/'.$run['id'], DB::value('SELECT url FROM notifications WHERE user_id=? ORDER BY id DESC LIMIT 1', [$hr]), 'links to the run');
    assert_eq(1, ad_notes($mgr, '%your team%'), 'manager of a team member asked to review');
    assert_eq(0, ad_notes($lone), 'manager without team members in the run is not told');
    assert_eq(0, ad_notes($a) + ad_notes($b), 'employees are not notified of a draft');
    // HR edits, then the cron fires again (late or duplicated): nothing regenerated, nobody notified twice
    act_as($hr);
    $s = ad_slip((int) $run['id'], $a);
    PayslipEditor::save((int) $s['id'], PayslipEditor::normalize(ad_bonus('Spot bonus', 1200), $s), 'Great quarter');
    act_as(null);
    assert_eq('run exists', PayrollService::autoDraft('2041-03-15'));
    assert_eq(1, (int) DB::value("SELECT COUNT(*) FROM payroll_runs WHERE period_month='2041-02-01'"));
    $s2 = ad_slip((int) $run['id'], $a);
    assert_true($s2['edits'] !== null && (float) $s2['net'] === (float) $s['net'] + 1200, 'HR edit kept');
    assert_eq(1, ad_notes($hr, '%ready to review%'), 'no second notification');
    // switched off: no run
    Settings::set('payroll.auto_draft', '0');
    assert_eq('off', PayrollService::autoDraft('2041-05-01'));
    assert_true(!DB::value("SELECT id FROM payroll_runs WHERE period_month='2041-04-01'"), 'nothing created while off');
    Settings::set('payroll.auto_draft', '1');
    Settings::set('payroll.salary_from_tracked_time', '1'); },

  'managers edit only their team\'s draft payslips; employees see nothing until Finalize & notify; finalize locks edits until reopened' => function () {
    Settings::set('payroll.salary_from_tracked_time', '0'); Settings::set('payroll.auto_draft', '1');
    $hr = make_user('hr', 'hr2@autodraft.test');
    $mgr = ad_person('manager', 'mgr2@autodraft.test', 'Mohan Lead', 60000);
    $a = ad_person('employee', 'a2@autodraft.test', 'Anita Report', 30000, $mgr);
    $b = ad_person('employee', 'b2@autodraft.test', 'Boris Elsewhere', 25000);
    act_as(null);
    PayrollService::autoDraft('2041-07-01');
    $run = (int) DB::value("SELECT id FROM payroll_runs WHERE period_month='2041-06-01'");
    $sa = ad_slip($run, $a); $sb = ad_slip($run, $b); $sm = ad_slip($run, $mgr);
    // employee: nothing visible
    act_as($a);
    assert_eq(404, http('GET', '/payslips/'.$sa['id'])->status(), 'draft payslip hidden');
    assert_true(!str_contains((string) http('GET', '/payslips')->body(), 'June 2041'), 'draft not listed');
    assert_eq(403, http('GET', '/payroll/payslips/'.$sa['id'].'/edit')->status(), 'employee cannot edit');
    assert_eq(403, http('GET', '/payroll/runs/'.$run)->status(), 'employee cannot open the run');
    // manager: team list, run page limited to the team, edit own report
    act_as($mgr);
    assert_true(PayrollAccess::team() && PayrollAccess::draftRunsForTeam() >= 1, 'team reviewer with a badge');
    $idx = http('GET', '/payroll');
    assert_eq(200, $idx->status()); assert_true(str_contains((string) $idx->body(), 'June 2041') && str_contains((string) $idx->body(), 'Team payslips'), 'team index lists the draft');
    $page = (string) http('GET', '/payroll/runs/'.$run)->body();
    assert_true(str_contains($page, 'Anita Report') && !str_contains($page, 'Boris Elsewhere') && !str_contains($page, '/payroll/payslips/'.$sm['id'].'/edit'), 'only direct reports on the run page');
    assert_true(!str_contains($page, '/finalize') && !str_contains($page, '/generate') && !str_contains($page, '/export') && !str_contains($page, '/delete'), 'no run actions for managers');
    assert_eq(200, http('GET', '/payroll/payslips/'.$sa['id'].'/edit')->status());
    $res = http('POST', '/payroll/payslips/'.$sa['id'].'/edit', ['reason' => 'Covered for a colleague'] + ad_bonus('Team bonus', 2000));
    assert_eq(302, $res->status());
    $sa2 = ad_slip($run, $a);
    assert_eq((float) $sa['net'] + 2000, (float) $sa2['net'], 'manager edit saved'); assert_eq($mgr, (int) $sa2['edited_by'], 'editor recorded');
    assert_eq(1, (int) DB::value("SELECT COUNT(*) FROM audit_logs WHERE action='payroll.payslip.edit' AND entity_id=? AND user_id=?", [$sa['id'], $mgr]), 'audit-logged as the manager');
    // manager: other people's payslips and run actions are refused server-side
    $denied = (int) DB::value("SELECT COUNT(*) FROM audit_logs WHERE action='access.denied' AND user_id=?", [$mgr]);
    assert_eq(403, http('GET', '/payroll/payslips/'.$sb['id'].'/edit')->status(), 'non-team payslip');
    assert_eq(403, http('POST', '/payroll/payslips/'.$sb['id'].'/edit', ['reason' => 'Sneaky'] + ad_bonus('X', 1))->status());
    assert_eq(403, http('POST', '/payroll/payslips/'.$sb['id'].'/preview', ad_bonus('X', 1))->status());
    assert_eq(403, http('POST', '/payroll/payslips/'.$sb['id'].'/reset', [])->status());
    assert_eq(403, http('GET', '/payroll/payslips/'.$sm['id'].'/edit')->status(), 'not their own payslip');
    assert_eq((float) $sb['net'], (float) ad_slip($run, $b)['net'], 'non-team payslip untouched');
    foreach (['finalize', 'generate', 'reopen', 'delete', 'paid', 'lines', 'notes'] as $act) { assert_eq(403, http('POST', '/payroll/runs/'.$run.'/'.$act, ['reason' => 'x', 'notes' => 'x'])->status(), "manager cannot $act"); }
    assert_eq(403, http('GET', '/payroll/runs/'.$run.'/export')->status(), 'manager cannot export');
    assert_eq(403, http('POST', '/payroll/runs', ['month' => '2041-08'])->status(), 'manager cannot create runs');
    assert_eq(403, http('GET', '/payslips/'.$sa['id'])->status(), 'nor open the employee payslip view');
    assert_eq('draft', DB::value('SELECT status FROM payroll_runs WHERE id=?', [$run]));
    assert_true((int) DB::value("SELECT COUNT(*) FROM audit_logs WHERE action='access.denied' AND user_id=?", [$mgr]) >= $denied + 14, 'denials audit-logged');
    assert_true((int) DB::value("SELECT COUNT(*) FROM audit_logs WHERE action='access.denied' AND entity_type='payslip' AND entity_id=? AND user_id=?", [$sb['id'], $mgr]) >= 1, 'record-level denial names the payslip');
    // HR sees everyone, who edited, and regenerating keeps the manager's edit
    act_as($hr);
    $page = (string) http('GET', '/payroll/runs/'.$run)->body();
    assert_true(str_contains($page, 'Boris Elsewhere') && str_contains($page, 'Mohan Lead') && str_contains($page, 'Edited'), 'HR sees every payslip and the edited marker');
    assert_true(str_contains($page, 'can see their payslip from now on') && str_contains($page, 'notified'), 'finalize confirm says employees are notified and slips become visible');
    $hrRes = http('POST', '/payroll/payslips/'.$sb['id'].'/edit', ['reason' => 'Advance recovery', 'manual' => [['type' => 'one_time', 'name' => 'Advance', 'amount' => '500']]]);
    assert_eq(302, $hrRes->status()); assert_eq((float) $sb['net'] - 500, (float) ad_slip($run, $b)['net'], 'HR edits any payslip');
    PayrollService::generate($run);
    assert_eq((float) $sa['net'] + 2000, (float) ad_slip($run, $a)['net'], 'regenerate keeps edits');
    assert_eq($mgr, (int) ad_slip($run, $a)['edited_by'], 'and who made them');
    $sa = ad_slip($run, $a);
    assert_eq(0, ad_notes($a) + ad_notes($b), 'still nobody notified');
    // finalize: employees notified and can see; edits locked for everyone
    assert_eq(302, http('POST', '/payroll/runs/'.$run.'/finalize')->status());
    assert_eq('finalized', DB::value('SELECT status FROM payroll_runs WHERE id=?', [$run]));
    assert_eq(1, ad_notes($a, '%is ready%')); assert_eq(1, ad_notes($b, '%is ready%'));
    act_as($a);
    $view = http('GET', '/payslips/'.$sa['id']);
    assert_eq(200, $view->status()); assert_true(str_contains((string) $view->body(), 'Team bonus'), 'employee sees the edited payslip');
    act_as($mgr);
    assert_eq(403, http('GET', '/payroll/runs/'.$run)->status(), 'finalized run closed to managers');
    assert_eq(403, http('POST', '/payroll/payslips/'.$sa['id'].'/edit', ['reason' => 'Late change'] + ad_bonus('Late', 100))->status(), 'manager edit after finalize refused');
    act_as($hr);
    $before = (float) ad_slip($run, $a)['net'];
    assert_throws(\DomainException::class, fn () => PayslipEditor::save((int) $sa['id'], ad_bonus('Late', 100), 'Late change'), 'locked');
    http('POST', '/payroll/payslips/'.$sa['id'].'/edit', ['reason' => 'Late change'] + ad_bonus('Late', 100));
    assert_eq($before, (float) ad_slip($run, $a)['net'], 'HR edit after finalize refused');
    PayrollService::reopen($run, 'Late bonus');
    act_as($mgr);
    $e = PayslipEditor::edits(ad_slip($run, $a)); $e['manual'][] = ['type' => 'bonus', 'name' => 'Late', 'amount' => '100'];
    $res = http('POST', '/payroll/payslips/'.$sa['id'].'/edit', ['reason' => 'Late change'] + $e);
    assert_eq(302, $res->status()); assert_eq($before + 100, (float) ad_slip($run, $a)['net'], 'editable again after reopening');
    Settings::set('payroll.salary_from_tracked_time', '1'); },
];
