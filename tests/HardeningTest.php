<?php
/** Performance and hardening: control-character open redirects, Fetch Metadata CSRF, bounded audit of denials, single-query rate limits, streamed CSV, notification dot, session lifetime on disk. */
use Core\{Audit, Cron, DB, Response, Settings};
use Core\Controllers\NotificationController;

return [
  'open redirects: tabs, newlines, backslashes and spaces in next= or the referer never leave the site' => function () {
    foreach (["/\t/evil.example", "/\n/evil.example", "/\r\n/evil.example", '/\\evil.example', "/ /evil.example", "/x\\..\\evil"] as $bad) {
      assert_eq('/', local_path($bad), json_encode($bad));
      $_SESSION['_csrf'] = 'test-token';
      $r = http('POST', '/login', ['email' => 'admin@unit.test', 'password' => 'Admin12345', 'next' => $bad]);
      assert_eq(url('/'), $r->headers()['Location'], 'login next '.json_encode($bad));
      $_SESSION['_csrf'] = 'test-token';
      $r = http('POST', '/logout', ['next' => $bad]);
      assert_eq(url('/login'), $r->headers()['Location'], 'logout next '.json_encode($bad)); }
    assert_eq('/tasks?view=board#x', local_path('/tasks?view=board#x'));
    foreach (['javascript:alert(1)', 'data:text/html,x', "/\t/evil.example", 'ftp://localhost/x'] as $ref) { $_SERVER['HTTP_REFERER'] = $ref; assert_eq(url('/'), Response::back()->headers()['Location'], 'referer '.json_encode($ref)); }
    $_SERVER['HTTP_REFERER'] = '/clients?x=1'; assert_eq('/clients?x=1', Response::back()->headers()['Location']);
    unset($_SERVER['HTTP_REFERER']); },

  'CSRF: a cross-site request (Sec-Fetch-Site) is refused even with a valid token; same-origin passes' => function () {
    act_as($GLOBALS['ADMIN_ID']);
    $_SERVER['HTTP_SEC_FETCH_SITE'] = 'cross-site';
    assert_eq(419, http('POST', '/account/theme', ['theme' => 'dark'])->status(), 'cross-site');
    $_SERVER['HTTP_SEC_FETCH_SITE'] = 'same-origin';
    assert_eq(200, http('POST', '/account/theme', ['theme' => 'dark'])->status(), 'same-origin');
    unset($_SERVER['HTTP_SEC_FETCH_SITE']);
    assert_eq(419, http('POST', '/account/theme', ['theme' => 'dark'], false)->status(), 'no token'); },

  'current-password guessing on the account page is throttled' => function () {
    DB::delete('rate_limits', '1=1');
    act_as($GLOBALS['ADMIN_ID']);
    for ($i = 0; $i < 10; $i++) { assert_eq(302, http('POST', '/account/password', ['current_password' => 'wrong'.$i, 'password' => 'Newpass12345', 'password_confirmation' => 'Newpass12345'])->status()); }
    assert_eq(429, http('POST', '/account/password', ['current_password' => 'wrong', 'password' => 'Newpass12345', 'password_confirmation' => 'Newpass12345'])->status());
    DB::delete('rate_limits', '1=1'); },

  'audit: repeated permission denials are logged a bounded number of times (the table cannot be flooded)' => function () {
    DB::delete('rate_limits', '1=1');
    $id = make_user('employee', 'flood@unit.test'); act_as($id);
    $before = (int) DB::value("SELECT COUNT(*) FROM audit_logs WHERE action='access.denied' AND user_id=?", [$id]);
    for ($i = 0; $i < 30; $i++) { assert_eq(403, http('GET', '/admin/users')->status()); }
    assert_eq(20, (int) DB::value("SELECT COUNT(*) FROM audit_logs WHERE action='access.denied' AND user_id=?", [$id]) - $before, 'capped at 20 per 10 minutes');
    Audit::logLimited('test.event', null, null, [], 2); Audit::logLimited('test.event', null, null, [], 2); Audit::logLimited('test.event', null, null, [], 2);
    assert_eq(2, (int) DB::value("SELECT COUNT(*) FROM audit_logs WHERE action='test.event'"));
    DB::update('users', ['status' => 'inactive'], 'id=?', [$id]);
    DB::delete('rate_limits', '1=1'); },

  'rate limiter: one statement per hit, counts within the window and restarts after it' => function () {
    $k = 'hard:'.bin2hex(random_bytes(3));
    $q = DB::$queries;
    $res = []; for ($i = 0; $i < 4; $i++) { $res[] = \Core\RateLimiter::hit($k, 3, 60); }
    assert_eq([true, true, true, false], $res);
    assert_eq(4, DB::$queries - $q, 'one query per hit');
    assert_true(\Core\RateLimiter::blocked($k, 3));
    DB::query('UPDATE rate_limits SET reset_at=? WHERE k=?', [time() - 1, hash('sha256', $k)]);
    assert_true(\Core\RateLimiter::hit($k, 1, 60), 'new window');
    assert_eq(1, (int) DB::value('SELECT hits FROM rate_limits WHERE k=?', [hash('sha256', $k)])); },

  'notifications: the top bar dot and the unread count follow unread rows (counted per link, not loaded one by one)' => function () {
    $id = make_user('employee', 'dot@unit.test');
    assert_true(!NotificationController::hasUnread($id)); assert_eq(0, NotificationController::unread($id));
    DB::insertMany('notifications', array_map(static fn ($i) => ['user_id' => $id, 'title' => 'N'.$i, 'url' => $i % 2 ? '/tasks' : null, 'created_at' => date('Y-m-d H:i:s')], range(1, 30)));
    assert_true(NotificationController::hasUnread($id)); assert_eq(30, NotificationController::unread($id));
    DB::query('UPDATE notifications SET read_at=NOW() WHERE user_id=?', [$id]);
    assert_true(!NotificationController::hasUnread($id)); assert_eq(0, NotificationController::unread($id));
    DB::update('users', ['status' => 'inactive'], 'id=?', [$id]); },

  'CSV exports stream from a cursor: formula cells are neutralised, the BOM and header lead, rows are counted for the audit' => function () {
    DB::query('CREATE TEMPORARY TABLE ny_csv_t (id INT, v VARCHAR(50))');
    DB::insertMany('ny_csv_t', [['id' => 1, 'v' => '=HYPERLINK("x")'], ['id' => 2, 'v' => 'plain'], ['id' => 3, 'v' => '@SUM(A1)'], ['id' => 4, 'v' => '-2+3']]);
    $n = null;
    $r = Response::csv(['ID', 'Value'], DB::cursor('SELECT id, v FROM ny_csv_t ORDER BY id'), 'x.csv', null, static function (int $c) use (&$n) { $n = $c; });
    assert_true(str_starts_with($r->headers()['Content-Disposition'], 'attachment'));
    $b = $r->body();
    assert_eq("\xEF\xBB\xBFID,Value\n1,\"'=HYPERLINK(\"\"x\"\")\"\n2,plain\n3,'@SUM(A1)\n4,'-2+3\n", $b);
    assert_eq(4, $n); assert_eq($b, $r->body(), 'body is stable once rendered');
    assert_eq(1, (int) DB::value('SELECT 1'), 'connection usable after the cursor');
    act_as($GLOBALS['ADMIN_ID']);
    $csv = http('GET', '/admin/audit?export=csv');
    assert_eq(200, $csv->status()); assert_true(str_contains($csv->body(), 'ID,Date,User'));
    assert_true((bool) DB::value("SELECT 1 FROM audit_logs WHERE action='audit.export' AND JSON_EXTRACT(meta, '$.rows') > 0")); },

  'session files on disk outlive the 7-day sign-in, whatever the retention setting says' => function () {
    $dir = NY_ROOT.'/storage/sessions'; if (!is_dir($dir)) { mkdir($dir, 0775, true); }
    $young = $dir.'/sess_nyhardtestyoung'; $old = $dir.'/sess_nyhardtestold';
    file_put_contents($young, ''); touch($young, time() - 3 * 86400);
    file_put_contents($old, ''); touch($old, time() - 9 * 86400);
    Settings::set('retention.sessions_days', 2);
    Cron::prune();
    assert_true(is_file($young), 'a 3-day idle sign-in is still valid and kept');
    assert_true(!is_file($old), 'past the sign-in lifetime it is removed');
    @unlink($young); Settings::set('retention.sessions_days', null); },

  'browser error reports from signed-out pages are capped per day (cannot fill the error log)' => function () {
    DB::delete('rate_limits', '1=1'); act_as(null);
    $n = (int) DB::value("SELECT COUNT(*) FROM error_logs WHERE source='browser'");
    for ($i = 0; $i < 3; $i++) { http('POST', '/errors/client', ['message' => 'flood '.bin2hex(random_bytes(6))], false); }
    assert_eq($n + 3, (int) DB::value("SELECT COUNT(*) FROM error_logs WHERE source='browser'"), 'recorded under the cap');
    DB::query('UPDATE rate_limits SET hits=300 WHERE k=?', [hash('sha256', 'errors:guest')]);
    assert_eq(204, http('POST', '/errors/client', ['message' => 'flood over cap'], false)->status());
    assert_eq($n + 3, (int) DB::value("SELECT COUNT(*) FROM error_logs WHERE source='browser'"), 'dropped over the daily cap');
    DB::delete('rate_limits', '1=1'); },

  'profiling: the opt-in Server-Timing value reports time, queries and peak memory' => function () {
    assert_true(preg_match('/^app;dur=[\d.]+, db;desc="\d+ queries";dur=[\d.]+, mem;desc="[\d.]+ MB peak"$/', Response::timing()) === 1); },
];
