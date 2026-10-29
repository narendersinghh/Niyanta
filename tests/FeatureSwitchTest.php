<?php
use Core\{DB, Features, Modules, Notify, Settings};
use Modules\Admin\Services\SettingsSchema;
use Modules\Payroll\Services\PayrollService;

/** Run $fn with the given feature/bundle switches, always restoring them (switch keys like 'feature:leave'). */
function with_off(array $keys, callable $fn): void {
  foreach ($keys as $k) { Features::set($k, false); }
  try { $fn(); }
  finally { foreach ($keys as $k) { Features::set($k, true); } \Core\Gate::flush(); } }

return [
  'reimbursements off: no dashboard widget, settings tab becomes "Payroll", claims stay out of payroll runs' => function () {
    $hr = make_user('hr', 'hr@featsw.test');
    $emp = make_user('employee', 'emp@featsw.test', ['joined_on' => '2030-01-01']);
    DB::insert('compensations', ['user_id' => $emp, 'pay_type' => 'monthly', 'amount' => 30000, 'effective_from' => '2030-01-01', 'created_at' => date('Y-m-d H:i:s')]);
    $claim = DB::insert('reimbursements', ['user_id' => $emp, 'title' => 'Taxi to client', 'amount' => 750, 'expense_date' => '2034-03-05', 'status' => 'approved', 'created_at' => date('Y-m-d H:i:s')]);
    DB::insert('reimbursements', ['user_id' => $emp, 'title' => 'Pending lunch claim', 'amount' => 300, 'expense_date' => '2034-03-06', 'status' => 'pending', 'created_at' => date('Y-m-d H:i:s')]);
    act_as($hr);
    assert_true(str_contains(http('GET', '/')->body(), 'Pending reimbursements'), 'widget shown while on');
    assert_eq('Payroll & reimbursements', SettingsSchema::tabs()['payroll'][0]);
    with_off(['feature:reimbursements'], function () use ($hr, $emp, $claim) {
      act_as($hr);
      $dash = http('GET', '/')->body();
      assert_true(!str_contains($dash, 'Pending reimbursements') && !str_contains($dash, '/reimbursements'), 'widget and links hidden');
      assert_true(!str_contains(http('GET', '/payroll')->body(), '/reimbursements'), 'payroll runs page has no claim tile');
      assert_eq('Payroll', SettingsSchema::tabs()['payroll'][0]);
      $run = DB::insert('payroll_runs', ['period_month' => '2034-03-01', 'status' => 'draft', 'created_at' => date('Y-m-d H:i:s')]);
      PayrollService::generate($run);
      $slip = DB::first('SELECT id, reimbursement_total FROM payslips WHERE run_id=? AND user_id=?', [$run, $emp]);
      assert_eq(0.0, (float) $slip['reimbursement_total'], 'approved claim not paid while the feature is off');
      assert_eq(null, DB::value('SELECT payslip_id FROM reimbursements WHERE id=?', [$claim]));
      $body = http('GET', '/payroll/runs/'.$run)->body();
      assert_true(!str_contains($body, 'Reimb.') && !stripos($body, 'reimbursement'), 'no reimbursement column or wording on the run');
      assert_true(!stripos(http('GET', '/payslips/'.$slip['id'])->body(), 'reimbursement'), 'payslip has no claims section');
      assert_eq(200, http('GET', '/payslips/'.$slip['id'].'/pdf')->status(), 'payslip PDF renders');
      act_as($GLOBALS['ADMIN_ID']);
      assert_true(!stripos(http('GET', '/admin/settings')->body(), 'reimbursement'), 'settings home free of reimbursements');
      DB::delete('payroll_runs', 'id=?', [$run]); });
    with_off(['feature:payroll'], function () {
      assert_eq('Reimbursements', SettingsSchema::tabs()['payroll'][0]);
      assert_eq([], SettingsSchema::fields('payroll'), 'payslip footer hidden without payroll'); });
    $run = DB::insert('payroll_runs', ['period_month' => '2034-03-01', 'status' => 'draft', 'created_at' => date('Y-m-d H:i:s')]);
    PayrollService::generate($run);
    assert_eq(750.0, (float) DB::value('SELECT reimbursement_total FROM payslips WHERE run_id=? AND user_id=?', [$run, $emp]), 'included again once switched back on');
    DB::delete('payroll_runs', 'id=?', [$run]); },

  'leave off: holiday and leave widgets, holiday page and leave wording disappear' => function () {
    $emp = make_user('employee', 'leave-off@featsw.test');
    DB::insert('holidays', ['holiday_date' => date('Y-m-d', strtotime('+3 days')), 'name' => 'Featsw Festival', 'optional' => 0]);
    act_as($emp);
    $on = http('GET', '/')->body();
    assert_true(str_contains($on, 'Featsw Festival') && str_contains($on, '/hr/leave'), 'holidays and leave tile shown while on');
    with_off(['feature:leave'], function () use ($emp) {
      act_as($emp);
      $dash = http('GET', '/')->body();
      assert_true(!str_contains($dash, 'Featsw Festival') && !str_contains($dash, '/hr/leave') && !str_contains($dash, '/hr/holidays'), 'leave/holiday widgets hidden');
      assert_eq(403, http('GET', '/hr/holidays')->status(), 'holiday calendar blocked although the directory permission allows it');
      $profile = http('GET', '/hr/employees/'.$emp.'?tab=schedule')->body();
      assert_true(!str_contains($profile, 'Leave history') && !str_contains($profile, 'leave quotas'), 'profile has no leave tabs or quotas'); }); },

  'invoices off: search drops invoice results, quotations stay' => function () {
    $c = DB::insert('clients', ['name' => 'Featsw Contact', 'company' => 'Featsw Corp', 'status' => 'active', 'created_at' => date('Y-m-d H:i:s')]);
    DB::insert('invoices', ['client_id' => $c, 'number' => 'FSW-INV-77', 'issue_date' => date('Y-m-d'), 'status' => 'sent', 'currency' => 'INR', 'total' => 1000, 'amount_paid' => 0, 'created_at' => date('Y-m-d H:i:s')]);
    DB::insert('quotations', ['client_id' => $c, 'number' => 'FSW-QT-77', 'issue_date' => date('Y-m-d'), 'status' => 'sent', 'currency' => 'INR', 'total' => 500, 'created_at' => date('Y-m-d H:i:s')]);
    act_as($GLOBALS['ADMIN_ID']);
    assert_true(str_contains(http('GET', '/search?q=FSW')->body(), 'FSW-INV-77'), 'invoice found while on');
    with_off(['feature:invoices'], function () {
      act_as($GLOBALS['ADMIN_ID']);
      $body = http('GET', '/search?q=FSW')->body();
      assert_true(!str_contains($body, 'FSW-INV-77') && !str_contains($body, '/invoices/'), 'no invoice results or links');
      assert_true(str_contains($body, 'FSW-QT-77'), 'quotations still searchable');
      assert_true(!str_contains(http('GET', '/clients/'.DB::value("SELECT id FROM clients WHERE company='Featsw Corp'"))->body(), 'FSW-INV-77'), 'client page has no invoices'); });
    with_off(['bundle:clients'], function () {
      act_as($GLOBALS['ADMIN_ID']);
      assert_true(!str_contains(http('GET', '/search?q=FSW')->body(), 'FSW-'), 'whole bundle off: nothing found'); }); },

  'settings tab of a switched-off feature is unreachable and hidden' => function () {
    act_as($GLOBALS['ADMIN_ID']);
    assert_eq(200, http('GET', '/admin/settings/payroll')->status());
    with_off(['feature:payroll', 'feature:reimbursements'], function () {
      act_as($GLOBALS['ADMIN_ID']);
      assert_true(!isset(SettingsSchema::tabs()['payroll']), 'tab removed');
      $r = http('GET', '/admin/settings/payroll');
      assert_eq(302, $r->status(), 'direct URL redirects to the settings home');
      assert_eq(404, http('POST', '/admin/settings/payroll', ['s' => ['payroll.payslip_footer' => 'x']])->status(), 'cannot be saved');
      assert_true(!str_contains(http('GET', '/admin/settings')->body(), '/admin/settings/payroll'), 'no card or nav entry'); });
    with_off(['feature:attendance'], function () {
      $keys = array_column(SettingsSchema::fields('retention'), 'key');
      assert_true(!in_array('attendance.screenshot_retention_days', $keys, true), 'screenshot retention hidden');
      assert_true(in_array('retention.sessions_days', $keys, true)); });
    Settings::set('ai.enabled', '0');
    assert_true(!feature_on('ai') && !isset(SettingsSchema::tabs()['ai']), 'AI tab hidden while the assistant is off');
    assert_true(!in_array('retention.ai_usage_days', array_column(SettingsSchema::fields('retention'), 'key'), true), 'AI usage retention hidden');
    Settings::set('ai.enabled', '1');
    assert_true(feature_on('ai') && isset(SettingsSchema::tabs()['ai'])); },

  'notifications, cron jobs and nav entries of switched-off features are dropped' => function () {
    $emp = make_user('employee', 'notif@featsw.test');
    with_off(['feature:reimbursements'], function () use ($emp) {
      $n = (int) DB::value('SELECT COUNT(*) FROM notifications WHERE user_id=?', [$emp]);
      Notify::user($emp, 'Claim approved', '', '/reimbursements');
      assert_eq($n, (int) DB::value('SELECT COUNT(*) FROM notifications WHERE user_id=?', [$emp]), 'not sent while off');
      DB::insert('notifications', ['user_id' => $emp, 'title' => 'Old claim note', 'url' => '/reimbursements', 'created_at' => date('Y-m-d H:i:s')]);
      act_as($emp);
      assert_true(!str_contains(http('GET', '/notifications')->body(), 'Old claim note'), 'existing notification hidden');
      assert_eq(0, \Core\Controllers\NotificationController::unread($emp), 'and not counted');
      assert_true(!str_contains(http('GET', '/')->body(), '/reimbursements'), 'nav entry hidden'); });
    with_off(['feature:invoices'], function () {
      $jobs = array_keys(\Core\Cron::jobs());
      assert_true(!in_array('crm.mark_overdue', $jobs, true), 'invoice cron skipped');
      assert_true(in_array('crm.payment_reminders', $jobs, true), 'follow-up reminders still run for clients'); });
    assert_true(in_array('crm.mark_overdue', array_keys(\Core\Cron::jobs()), true)); },
];
