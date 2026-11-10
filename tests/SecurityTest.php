<?php
use Core\{Auth, Cron, Csrf, DB, ErrorLog, Request, Response, Session, Storage, ValidationException};
use Modules\Ai\Services\Http;
use Modules\Attendance\Services\Monitoring;

/** Writes a small file into the scratch upload area and returns a $_FILES-style array (CLI: Storage renames instead of move_uploaded_file). */
function sec_upload(string $name, string $content): array {
  $tmp = sys_get_temp_dir().'/ny-sec-'.bin2hex(random_bytes(4));
  file_put_contents($tmp, $content);
  return ['name' => $name, 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen($content), 'type' => 'application/octet-stream']; }
function sec_png(int $w = 40, int $h = 30): string { $im = imagecreatetruecolor($w, $h); imagefill($im, 0, 0, 0xFF8800); ob_start(); imagepng($im); return (string) ob_get_clean(); }

return [
  'security headers: strict CSP, nosniff, COOP, frame and cache rules; HSTS only over HTTPS' => function () {
    $_SERVER['HTTPS'] = 'off'; $_SERVER['SERVER_PORT'] = '80'; unset($_SERVER['HTTP_X_FORWARDED_PROTO']);
    $h = Response::securityHeaders(Request::capture());
    $csp = $h['Content-Security-Policy'];
    assert_true(str_contains($csp, "default-src 'self'") && str_contains($csp, "object-src 'none'") && str_contains($csp, "frame-ancestors 'self'") && str_contains($csp, "form-action 'self'") && str_contains($csp, "base-uri 'self'") && str_contains($csp, "frame-src 'none'"), 'csp directives');
    assert_true(!preg_match("/script-src[^;]*unsafe-inline/", $csp) && !preg_match("/script-src[^;]*unsafe-eval/", $csp), 'no inline or eval scripts');
    assert_eq('nosniff', $h['X-Content-Type-Options']); assert_eq('same-origin', $h['Cross-Origin-Opener-Policy']); assert_eq('SAMEORIGIN', $h['X-Frame-Options']);
    assert_true(str_contains($h['Cache-Control'], 'no-store') && str_contains($h['Permissions-Policy'], 'camera=()') && str_contains($h['Referrer-Policy'], 'strict-origin'));
    assert_true(!isset($h['Strict-Transport-Security']), 'no HSTS over plain http');
    $_SERVER['HTTPS'] = 'on';
    $h = Response::securityHeaders(Request::capture());
    assert_true(str_starts_with($h['Strict-Transport-Security'] ?? '', 'max-age=31536000') && str_contains($h['Content-Security-Policy'], 'upgrade-insecure-requests'), 'HSTS and upgrade over https');
    $_SERVER['HTTPS'] = 'off'; },
  'session cookie: HttpOnly, SameSite=Lax, kept for the sign-in lifetime, Secure on HTTPS' => function () {
    $_SERVER['HTTPS'] = 'off';
    $c = Session::cookieParams(Request::capture());
    assert_true($c['httponly'] && $c['samesite'] === 'Lax' && $c['lifetime'] === Session::lifetime() && $c['lifetime'] >= 3600 && !$c['secure']);
    $_SERVER['HTTPS'] = 'on'; assert_true(Session::cookieParams(Request::capture())['secure'], 'secure flag over https'); $_SERVER['HTTPS'] = 'off'; },
  'passwords: argon2id when available (else bcrypt cost 12), old bcrypt hashes upgraded at sign-in, common passwords refused' => function () {
    $h = Auth::hash('Longer12345'); $i = password_get_info($h);
    assert_true(defined('PASSWORD_ARGON2ID') ? $i['algoName'] === 'argon2id' : ($i['algoName'] === 'bcrypt' && $i['options']['cost'] === 12), 'algo '.$i['algoName']);
    $id = make_user('employee', 'rehash@unit.test', ['password_hash' => password_hash('Passw0rd123', PASSWORD_BCRYPT, ['cost' => 4])]);
    assert_eq(null, Auth::attempt('rehash@unit.test', 'Passw0rd123'));
    $new = password_get_info((string) DB::value('SELECT password_hash FROM users WHERE id=?', [$id]));
    assert_true(!($new['algoName'] === 'bcrypt' && $new['options']['cost'] === 4), 'weak hash was upgraded');
    assert_true(Auth::validatePassword('Password123') !== null, 'common password refused'); assert_true(Auth::validatePassword(str_repeat('a1', 600)) !== null, 'overlong refused'); },
  'sessions end after the absolute lifetime even while active' => function () {
    $id = make_user('employee', 'absolute@unit.test');
    act_as($id); assert_true(Auth::check());
    $_SESSION['login_at'] = time() - 6 * 86400; Auth::forget();
    assert_true(Auth::check(), 'still signed in after 6 days');
    $_SESSION['login_at'] = time() - 8 * 86400; Auth::forget();
    assert_true(!Auth::check(), 'signed out after 7 days'); },
  'CSRF: cross-site Origin is refused even with a valid token, same-origin accepted' => function () {
    act_as($GLOBALS['ADMIN_ID']);
    $_SERVER['HTTP_ORIGIN'] = 'https://evil.example';
    assert_eq(419, http('POST', '/account/theme', ['theme' => 'dark'])->status(), 'foreign origin');
    $_SERVER['HTTP_ORIGIN'] = 'http://localhost';
    assert_eq(200, http('POST', '/account/theme', ['theme' => 'dark'])->status(), 'same origin');
    unset($_SERVER['HTTP_ORIGIN']); $_POST = [];
    assert_true(!Csrf::verify(Request::capture()), 'missing token fails'); },
  'rate limits: forgot-password route answers 429 after its quota, login route is throttled' => function () {
    DB::delete('rate_limits', '1=1');
    for ($i = 0; $i < 5; $i++) { assert_eq(302, http('POST', '/forgot-password', ['email' => 'nobody'])->status()); }
    assert_eq(429, http('POST', '/forgot-password', ['email' => 'nobody'])->status(), 'sixth request throttled');
    assert_true(\Core\RateLimiter::hit('t:same', 1, 60) && !\Core\RateLimiter::hit('t:same', 1, 60), 'limiter counts hits');
    DB::delete('rate_limits', '1=1'); },
  'IDOR: other people\'s payslips, AI chats, time entries and tasks are refused' => function () {
    $a = make_user('employee', 'idor-a@unit.test'); $b = make_user('employee', 'idor-b@unit.test');
    $now = date('Y-m-d H:i:s');
    $conv = DB::insert('ai_conversations', ['user_id' => $a, 'title' => 'private', 'created_at' => $now, 'updated_at' => $now]);
    $entry = DB::insert('time_entries', ['user_id' => $a, 'started_at' => date('Y-m-d H:i:s', time() - 7200), 'ended_at' => date('Y-m-d H:i:s', time() - 3600), 'duration_seconds' => 3600, 'is_running' => 0, 'created_at' => $now]);
    $todo = DB::insert('todos', ['title' => 'mine', 'assignee_id' => $a, 'created_by' => $a, 'created_at' => $now, 'updated_at' => $now]);
    act_as($b);
    $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
    assert_eq(404, http('GET', '/ai/conversations/'.$conv)->status(), 'conversation');
    unset($_SERVER['HTTP_X_REQUESTED_WITH']);
    assert_eq(404, http('POST', '/time/entries/'.$entry.'/delete')->status(), 'time entry');
    assert_true(in_array(http('POST', '/todos/'.$todo.'/delete')->status(), [403, 404], true), 'todo');
    assert_eq(1, (int) DB::value('SELECT COUNT(*) FROM time_entries WHERE id=?', [$entry]), 'entry untouched');
    act_as($a); assert_eq(200, http('GET', '/ai/conversations/'.$conv)->status(), 'owner sees own chat'); },
  'files: a document of another module entity is served only through its access callback' => function () {
    $emp = make_user('employee', 'files-emp@unit.test'); $other = make_user('employee', 'files-other@unit.test');
    $dir = Storage::root().'/hr/test'; @mkdir($dir, 0775, true); file_put_contents($dir.'/secret.txt', 'top secret');
    $f = DB::insert('files', ['module' => 'hr', 'entity_type' => 'employee', 'entity_id' => $emp, 'category' => 'general', 'disk_path' => 'hr/test/secret.txt', 'original_name' => 'secret.txt', 'mime' => 'text/plain', 'size' => 10, 'uploaded_by' => $GLOBALS['ADMIN_ID'], 'created_at' => date('Y-m-d H:i:s')]);
    act_as($other); assert_eq(403, http('GET', '/files/'.$f)->status(), 'colleague refused');
    act_as($GLOBALS['ADMIN_ID']); assert_eq(200, http('GET', '/files/'.$f)->status(), 'super admin allowed');
    act_as($emp); assert_eq(200, http('GET', '/files/'.$f)->status(), 'owner allowed'); },
  'uploads: scripts, SVG, HTML and disguised PHP are refused; a real image passes and is re-encoded' => function () {
    foreach ([['shell.php', '<?php echo 1;'], ['logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'], ['page.html', '<html><script>1</script></html>'], ['fake.png', '<?php system($_GET["c"]); ?>'], ['notes.txt', "<?php phpinfo();"]] as [$n, $c]) {
      assert_throws(ValidationException::class, fn () => Storage::store(sec_upload($n, $c), 'tests', array_merge(Storage::DOCS, ['php', 'svg', 'html'])), $n); }
    $s = Storage::store(sec_upload('photo.png', sec_png(900, 600)), 'tests', Storage::IMAGES);
    $abs = Storage::path($s['path']);
    assert_true(is_file($abs) && preg_match('#^tests/\d{4}/\d{2}/[a-f0-9]{32}\.png$#', $s['path']), 'random name under module dir');
    assert_true(Storage::reencodeImage($abs, 300), 'reencode');
    [$w, $h] = getimagesize($abs); assert_eq(300, $w); assert_eq(200, $h);
    assert_throws(ValidationException::class, fn () => Storage::store(sec_upload('big.pdf', str_repeat('x', 2048)), 'tests', Storage::DOCS, 1024), 'size limit');
    assert_eq(Storage::root().'/evil', Storage::path('../../evil')); },
  'open redirects: login next= must be a local path; back() ignores foreign referers' => function () {
    foreach (['//evil.example', 'https://evil.example/x', '\\\\evil.example', 'javascript:alert(1)'] as $bad) {
      $_SESSION['_csrf'] = 'test-token';
      $r = http('POST', '/login', ['email' => 'admin@unit.test', 'password' => 'Admin12345', 'next' => $bad]);
      assert_eq(302, $r->status()); assert_eq(url('/'), $r->headers()['Location'], $bad); }
    $_SESSION['_csrf'] = 'test-token';
    $r = http('POST', '/login', ['email' => 'admin@unit.test', 'password' => 'Admin12345', 'next' => '/clients?x=1']);
    assert_eq(url('/clients?x=1'), $r->headers()['Location']);
    $_SERVER['HTTP_REFERER'] = 'https://evil.example/page'; assert_eq(url('/'), Response::back()->headers()['Location']);
    $_SERVER['HTTP_REFERER'] = 'http://localhost/clients'; assert_eq('http://localhost/clients', Response::back()->headers()['Location']);
    unset($_SERVER['HTTP_REFERER']); },
  'SSRF: outbound URLs must be https; loopback, private, link-local and numeric hosts are blocked in strict mode' => function () {
    assert_true(Http::allowedUrl('https://api.openai.com/v1') && Http::allowedUrl('http://localhost:11434/v1') && Http::allowedUrl('http://192.168.1.5/v1'));
    assert_true(!Http::allowedUrl('http://evil.example/v1') && !Http::allowedUrl('ftp://x') && !Http::allowedUrl('https://user:pw@host/'));
    foreach (['http://127.0.0.1/', 'http://10.0.0.1/', 'https://169.254.169.254/latest', 'https://[::1]/', 'https://metadata.internal/', 'https://2130706433/', 'https://0x7f000001/', 'http://example.com/'] as $u) { assert_true(!Http::allowedUrl($u, false), $u); }
    assert_true(Http::allowedUrl('https://example.com/webhook', false)); },
  'device tokens are hashed at rest, work until revoked, and expire when unused' => function () {
    $id = make_user('employee', 'tok@unit.test');
    [$plain, $tid] = Monitoring::createToken($id, 'Laptop', 'desktop');
    $row = DB::find('agent_tokens', $tid);
    assert_true(str_starts_with($plain, 'nyt_') && $row['token_hash'] === hash('sha256', $plain) && !str_contains(json_encode($row), $plain), 'stored hashed');
    assert_eq($id, (int) Monitoring::tokenUser($plain)['id']);
    assert_eq(null, Monitoring::tokenUser('nyt_'.str_repeat('0', 48)), 'unknown token');
    DB::update('agent_tokens', ['last_used_at' => date('Y-m-d H:i:s', time() - 100 * 86400)], 'id=?', [$tid]);
    assert_eq(null, Monitoring::tokenUser($plain), 'idle token expired');
    assert_true((bool) DB::value('SELECT revoked_at FROM agent_tokens WHERE id=?', [$tid]), 'and revoked');
    [$p2, $t2] = Monitoring::createToken($id, 'Laptop', 'desktop');
    assert_true(Monitoring::revokeToken($id, $t2) && Monitoring::tokenUser($p2) === null, 'revocation'); },
  'password reset tokens are hashed, expire, and work once' => function () {
    $id = make_user('employee', 'reset@unit.test');
    DB::delete('rate_limits', '1=1');
    assert_eq(302, http('POST', '/forgot-password', ['email' => 'reset@unit.test'])->status());
    $row = DB::first('SELECT * FROM password_resets WHERE user_id=?', [$id]);
    assert_true($row && preg_match('/^[a-f0-9]{64}$/', $row['token_hash']) && strtotime($row['expires_at']) > time(), 'hashed with expiry');
    $mail = (string) DB::value('SELECT body_html FROM mail_queue WHERE to_email=? ORDER BY id DESC LIMIT 1', ['reset@unit.test']);
    assert_true((bool) preg_match('#/reset-password/([A-Za-z0-9_-]+)#', $mail, $m), 'link mailed');
    assert_true(!str_contains($mail, $row['token_hash']), 'hash never mailed');
    $r = http('POST', '/reset-password', ['token' => $m[1], 'password' => 'Brand-new-9', 'password_confirmation' => 'Brand-new-9']);
    assert_eq(url('/login'), $r->headers()['Location']);
    assert_eq(0, (int) DB::value('SELECT COUNT(*) FROM password_resets WHERE user_id=?', [$id]), 'single use');
    assert_eq(null, Auth::attempt('reset@unit.test', 'Brand-new-9'));
    $_SESSION['_csrf'] = 'test-token';
    assert_eq(url('/forgot-password'), http('POST', '/reset-password', ['token' => $m[1], 'password' => 'Brand-new-9', 'password_confirmation' => 'Brand-new-9'])->headers()['Location'], 'reuse refused'); },
  'error log redacts reset tokens, install keys and bearer tokens' => function () {
    $s = ErrorLog::redact('GET /reset-password/abcDEF123 and /time/desktop/install.sh?k=1-2-sig&x=1 Bearer nyt_0123456789abcdef0123 nyt_0123456789abcdefabcd');
    assert_true(!str_contains($s, 'abcDEF123') && !str_contains($s, '1-2-sig') && !str_contains($s, '0123456789abcdef0123') && str_contains($s, '&x=1'), $s); },
  'private files: inline images answer 304 to a matching ETag, downloads are never cached' => function () {
    $f = sys_get_temp_dir().'/ny-etag-'.bin2hex(random_bytes(3)).'.png'; file_put_contents($f, sec_png());
    unset($_SERVER['HTTP_IF_NONE_MATCH']); Request::capture();
    $r = Response::file($f, 'a.png', 'image/png', true);
    $h = $r->headers(); assert_true(isset($h['ETag'], $h['Last-Modified']) && $h['Cache-Control'] === 'private, no-cache' && $h['X-Content-Type-Options'] === 'nosniff');
    $_SERVER['HTTP_IF_NONE_MATCH'] = $h['ETag']; Request::capture();
    assert_eq(304, Response::file($f, 'a.png', 'image/png', true)->status());
    unset($_SERVER['HTTP_IF_NONE_MATCH']); Request::capture();
    $d = Response::file($f, 'a.png', 'image/png', false)->headers();
    assert_true($d['Cache-Control'] === 'private, no-store' && str_starts_with($d['Content-Disposition'], 'attachment') && !isset($d['ETag']));
    unlink($f); },
  'retention jobs delete in bounded batches' => function () {
    $rows = []; for ($i = 0; $i < 25; $i++) { $rows[] = ['user_id' => null, 'action' => 'test.prune', 'entity_type' => null, 'entity_id' => null, 'meta' => null, 'ip' => 'cli', 'user_agent' => 't', 'created_at' => '2000-01-01 00:00:00']; }
    DB::insertMany('audit_logs', $rows);
    assert_eq(25, Cron::chunkedDelete('audit_logs', "action='test.prune' AND created_at < ?", ['2001-01-01'], microtime(true) + 5, 10));
    assert_eq(0, (int) DB::value("SELECT COUNT(*) FROM audit_logs WHERE action='test.prune'")); },
  'web server rules deny private folders, dotfiles and stray PHP files' => function () {
    $ht = (string) file_get_contents(NY_ROOT.'/.htaccess');
    assert_true(str_contains($ht, 'Options -Indexes') && preg_match('#RewriteRule \^\(config\|core\|modules\|storage#', $ht) && str_contains($ht, '(^|/)\.(?!well-known)') && str_contains($ht, 'FilesMatch "\.(php\d?|phtml|phar|phps|pht)$"') && str_contains($ht, 'X-Powered-By'));
    assert_true(str_contains((string) file_get_contents(NY_ROOT.'/storage/.htaccess'), 'Require all denied') && str_contains((string) file_get_contents(NY_ROOT.'/.user.ini'), 'expose_php = Off')); },
  'security indexes are in place' => function () {
    foreach (['password_resets' => 'idx_pr_expires', 'agent_tokens' => 'idx_at_active', 'audit_logs' => 'idx_audit_action_created', 'time_entries' => 'idx_te_user_ended'] as $t => $i) {
      assert_true((bool) DB::value('SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=? LIMIT 1', [$t, $i]), "$t.$i"); } },
];
