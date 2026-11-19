<?php
use Core\{DB, ErrorLog};

/** Error logs download as a text file, with the page's filters; employees' Reimbursements page renders without warnings. */
return [
  'error logs: Download .txt exports the filtered errors with their details, audit-logged; employees cannot' => function () {
    ErrorLog::record('desktop', 'warning', 'updater: net::ERR_TEST_EXPORT', 'updater.js', 12, ['feed' => 'https://x.test/'], 'desktop:updater', '1.4.10');
    ErrorLog::record('server', 'error', 'Other server failure for export', 'x.php', 3);
    $admin = (int) DB::value("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.slug='super_admin' LIMIT 1");
    act_as($admin);
    $page = http('GET', '/admin/audit/errors')->body();
    assert_true(str_contains($page, '/admin/audit/errors/export') && str_contains($page, 'Download .txt'), 'button on the page');
    $r = http('GET', '/admin/audit/errors/export?source=desktop&state=all');
    $t = $r->body();
    assert_true(str_contains($t, 'updater: net::ERR_TEST_EXPORT') && str_contains($t, '"feed": "https://x.test/"') && str_contains($t, 'Version:') && !str_contains($t, 'Other server failure for export'), 'desktop errors only, with details');
    assert_true(str_contains((string) ($r->headers()['Content-Type'] ?? ''), 'text/plain') && str_contains((string) ($r->headers()['Content-Disposition'] ?? ''), '.txt'), 'a .txt download');
    assert_true((bool) DB::value("SELECT 1 FROM audit_logs WHERE action='error_log.export' LIMIT 1"), 'audit');
    $emp = make_user('employee', 'zz-export@unit.test', ['name' => 'Zz Export']);
    act_as($emp);
    assert_eq(403, http('GET', '/admin/audit/errors/export')->status(), 'employees cannot');
    DB::update('users', ['status' => 'inactive'], 'id=?', [$emp]); },

  'reimbursements: an employee without the approval queue sees My Pay without a PHP warning' => function () {
    $emp = make_user('employee', 'zz-reimb@unit.test', ['name' => 'Zz Reimb']);
    act_as($emp);
    $before = (int) DB::value("SELECT COUNT(*) FROM error_logs WHERE file LIKE '%reimbursements/index.php'");
    assert_eq(200, http('GET', '/reimbursements?tab=mine')->status(), 'page loads');
    assert_eq($before, (int) DB::value("SELECT COUNT(*) FROM error_logs WHERE file LIKE '%reimbursements/index.php'"), 'no warning logged');
    DB::update('users', ['status' => 'inactive'], 'id=?', [$emp]); },
];
