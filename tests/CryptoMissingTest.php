<?php
use Core\{Crypto, DB, Settings};

/** Hosts can switch the sodium extension off (a PHP version change on Hostinger did): pages keep working, secrets read as empty, one clear error is logged, admins see a banner. */
return [
  'crypto: without sodium, decrypt returns null instead of a fatal error; encrypt refuses with a clear message; admin banner' => function () {
    $enc = Crypto::encrypt('secret-token');
    assert_eq('secret-token', Crypto::decrypt($enc), 'round trip with sodium');
    Crypto::$simulateMissing = true;
    try {
      assert_true(Crypto::decrypt($enc) === null, 'unreadable, no crash');
      assert_throws(RuntimeException::class, fn () => Crypto::encrypt('x'), 'cannot store');
      assert_true((bool) DB::value("SELECT 1 FROM error_logs WHERE message LIKE '%sodium extension is disabled%' LIMIT 1"), 'one clear error logged');
      $admin = (int) DB::value("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.slug='super_admin' LIMIT 1");
      act_as($admin);
      $h = http('GET', '/');
      assert_eq(200, $h->status(), 'dashboard still loads');
      assert_true(str_contains($h->body(), 'extension is turned off'), 'banner for the super admin'); }
    finally { Crypto::$simulateMissing = null; }
    assert_eq('secret-token', Crypto::decrypt($enc), 'readable again once enabled'); },
  'browser errors raised only inside a browser extension are not logged' => function () {
    $n = (int) \Core\DB::value('SELECT COUNT(*) FROM error_logs');
    http('POST', '/errors/client', ['message' => "TypeError: Cannot read properties of undefined (reading 'M_ID')", 'stack' => 'at h (chrome-extension://eppiocemhmnlbhjplcgkofciiegomcon/executors/200.js:1:761)'], false);
    assert_eq($n, (int) \Core\DB::value('SELECT COUNT(*) FROM error_logs'), 'extension error ignored');
    http('POST', '/errors/client', ['message' => 'TypeError: ours '.bin2hex(random_bytes(4)), 'source' => '/assets/js/app.js', 'line' => 3], false);
    assert_eq($n + 1, (int) \Core\DB::value('SELECT COUNT(*) FROM error_logs'), 'our own errors still logged'); },
];
