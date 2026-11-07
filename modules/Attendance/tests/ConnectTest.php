<?php
use Core\{DB, Features, Settings};
use Modules\Attendance\Services\{DesktopApp, DesktopConnect, DesktopInstall};

/** Desktop app API call (Bearer optional, form body like the other agent tests). Returns [status, decoded body, response]. */
function cx_api(string $method, string $path, array $post = [], ?string $token = null): array {
  $_SERVER['HTTP_ACCEPT'] = 'application/json'; $_SERVER['HTTP_X_APP_VERSION'] = '1.5.0';
  if ($token) { $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer '.$token; } else { unset($_SERVER['HTTP_AUTHORIZATION']); }
  $r = http($method, $path, $post, false);
  unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTP_X_APP_VERSION'], $_SERVER['HTTP_ACCEPT']);
  return [$r->status(), json_decode($r->body(), true) ?? ['raw' => substr($r->body(), 0, 300)], $r]; }

function cx_connect(string $code, array $extra = []): array { act_as(null); return cx_api('POST', '/api/agent/connect', $extra + ['code' => $code, 'device_name' => 'Studio Mac', 'device_os' => 'mac', 'app_version' => '1.5.0']); }
function cx_user(string $email, string $role = 'employee'): int { return (int) (DB::value('SELECT id FROM users WHERE email=?', [$email]) ?: make_user($role, $email)); }
function cx_pkce(): array { $v = DesktopConnect::b64url(random_bytes(48)); return [$v, DesktopConnect::b64url(hash('sha256', $v, true))]; }
/** Code and state from a niyanta-tracker:// deep link (HTML-escaped or not). */
function cx_link(string $s): array {
  if (!preg_match('#niyanta-tracker://connect\?[^"\'\s<]+#', html_entity_decode($s), $m)) { throw new RuntimeException('no deep link in: '.substr($s, 0, 300)); }
  parse_str((string) parse_url($m[0], PHP_URL_QUERY), $q);
  return $q + ['link' => $m[0]]; }

return [
  'web code: exchange returns the same token + bootstrap shape as password login, once, hashed, audited' => function () {
    $emp = cx_user('emp@connect.test');
    $code = DesktopConnect::issue($emp, 'web', null, null, null, null, '127.0.0.1');
    assert_true((bool) preg_match('/^[A-Za-z0-9_-]{43}$/', $code), 'url-safe code');
    $row = DB::first('SELECT * FROM desktop_connect_codes WHERE user_id=? ORDER BY id DESC LIMIT 1', [$emp]);
    assert_eq(hash('sha256', $code), $row['code_hash'], 'only the sha256 is stored');
    assert_true(!DB::value('SELECT 1 FROM desktop_connect_codes WHERE code_hash=?', [$code]), 'plain code not stored');
    assert_eq(120, strtotime($row['expires_at']) - strtotime($row['created_at']), 'web codes: 2 minutes');
    [$code1, $j] = cx_connect($code);
    assert_eq(200, $code1, json_encode($j));
    [, $login] = cx_api('POST', '/api/agent/login', ['email' => 'emp@connect.test', 'password' => 'Passw0rd123', 'device_name' => 'Other box', 'os' => 'linux']);
    assert_eq(array_keys($login), array_keys($j), 'same keys as /api/agent/login');
    assert_true((bool) preg_match('/^nyt_[a-f0-9]{48}$/', $j['token']) && $j['user']['id'] === $emp, 'token for the code owner');
    assert_eq(absolute_url('/'), $j['workspace']['url'], 'workspace.url to store');
    $tk = DB::first('SELECT * FROM agent_tokens WHERE token_hash=?', [hash('sha256', $j['token'])]);
    assert_true($tk['kind'] === 'desktop' && $tk['name'] === 'Studio Mac' && $tk['device_os'] === 'mac' && $tk['app_version'] === '1.5.0', 'device recorded: '.json_encode($tk));
    assert_eq(200, cx_api('GET', '/api/agent/bootstrap', [], $j['token'])[0], 'token works');
    assert_true(DB::value('SELECT used_at FROM desktop_connect_codes WHERE id=?', [$row['id']]) !== null, 'marked used');
    foreach (['desktop.connect.issued', 'desktop.connect.used'] as $a) { assert_true((bool) DB::value('SELECT 1 FROM audit_logs WHERE action=? AND user_id=?', [$a, $emp]), $a); }
    [$s, $j2] = cx_connect($code);
    assert_true($s === 401 && $j2['code'] === 'code_invalid' && $j2['ok'] === false && $j2['error'] !== '', 'single use: '.json_encode($j2));
    assert_true((bool) DB::value("SELECT 1 FROM audit_logs WHERE action='desktop.connect.failed' AND user_id=? AND meta LIKE '%code_invalid%'", [$emp]), 'failure audited');
    foreach (['', 'short', str_repeat('a', 43).'!', DesktopConnect::b64url(random_bytes(32))] as $bad) { assert_eq('code_invalid', cx_connect($bad)[1]['code'] ?? null, 'unknown code: '.$bad); }
    DB::query('DELETE FROM rate_limits'); },

  'expiry: 2 minutes for web and browser codes, 30 minutes for install codes; cleanup deletes expired rows' => function () {
    $emp = cx_user('emp@connect.test');
    [, $ch] = cx_pkce();
    $now = time();
    foreach (['web' => 120, 'browser' => 120, 'install' => 1800] as $p => $ttl) {
      $c = DesktopConnect::issue($emp, $p, $p === 'browser' ? $ch : null, $p === 'browser' ? 'state-'.bin2hex(random_bytes(6)) : null, null, null, null, $now);
      assert_eq(date('Y-m-d H:i:s', $now + $ttl), DB::value('SELECT expires_at FROM desktop_connect_codes WHERE code_hash=?', [hash('sha256', $c)]), $p); }
    $c = DesktopConnect::issue($emp, 'web');
    DB::query('UPDATE desktop_connect_codes SET expires_at=? WHERE code_hash=?', [date('Y-m-d H:i:s', time() - 1), hash('sha256', $c)]);
    [$s, $j] = cx_connect($c);
    assert_true($s === 401 && $j['code'] === 'code_expired', 'expired: '.json_encode($j));
    $c2 = DesktopConnect::issue($emp, 'install', null, null, null, null, null, time() - 1700);
    assert_eq(200, cx_connect($c2)[0], 'install code still valid after 28 minutes');
    $n = (int) DB::value('SELECT COUNT(*) FROM desktop_connect_codes WHERE expires_at<?', [date('Y-m-d H:i:s')]);
    assert_true($n >= 1, 'something to clean');
    assert_eq("deleted $n", DesktopConnect::cleanup());
    assert_eq(0, (int) DB::value('SELECT COUNT(*) FROM desktop_connect_codes WHERE expires_at<?', [date('Y-m-d H:i:s')]), 'expired rows gone');
    DB::query('DELETE FROM rate_limits'); },

  'PKCE: a code created with a challenge needs the matching verifier; a wrong one burns the code' => function () {
    $emp = cx_user('emp@connect.test');
    [$v, $ch] = cx_pkce();
    $st = 'st_'.bin2hex(random_bytes(8));
    $c = DesktopConnect::issue($emp, 'browser', $ch, $st, 'Desk', 'linux');
    assert_eq('verifier_mismatch', cx_connect($c)[1]['code'] ?? null, 'verifier required');
    assert_eq('code_invalid', cx_connect($c, ['verifier' => $v])[1]['code'] ?? null, 'burned after a failed attempt');
    $c = DesktopConnect::issue($emp, 'browser', $ch, $st, 'Desk', 'linux');
    [$wrong] = cx_pkce();
    assert_eq('verifier_mismatch', cx_connect($c, ['verifier' => $wrong])[1]['code'] ?? null, 'wrong verifier');
    $c = DesktopConnect::issue($emp, 'browser', $ch, $st, 'Desk', 'linux');
    [$s, $j] = cx_connect($c, ['verifier' => $v, 'device_name' => '']);
    assert_eq(200, $s, json_encode($j));
    assert_eq('Desk', DB::value('SELECT name FROM agent_tokens WHERE token_hash=?', [hash('sha256', $j['token'])]), 'device name from the browser request when the app sends none');
    assert_throws(DomainException::class, fn () => DesktopConnect::issue($emp, 'browser', 'short', $st), 'challenge format');
    assert_throws(DomainException::class, fn () => DesktopConnect::issue($emp, 'browser', $ch, 'bad state!'), 'state format');
    DB::query('DELETE FROM rate_limits'); },

  'refused: inactive user, role without tracking, time tracking off (code kept), session of another user ignored, device limit' => function () {
    $emp = cx_user('emp@connect.test');
    $other = cx_user('other@connect.test');
    $c = DesktopConnect::issue($emp, 'web');
    DB::update('users', ['status' => 'inactive'], 'id=?', [$emp]);
    assert_eq('code_invalid', cx_connect($c)[1]['code'] ?? null, 'inactive user');
    DB::update('users', ['status' => 'active'], 'id=?', [$emp]);
    if (!DB::value("SELECT 1 FROM roles WHERE slug='contractor_cx'")) { DB::insert('roles', ['slug' => 'contractor_cx', 'name' => 'Contractor CX', 'created_at' => date('Y-m-d H:i:s')]); }
    $con = cx_user('con@connect.test', 'contractor_cx');
    [$s, $j] = cx_connect(DesktopConnect::issue($con, 'web'));
    assert_true($s === 403 && $j['code'] === 'forbidden', 'role without attendance.track: '.json_encode($j));
    $c = DesktopConnect::issue($emp, 'web');
    Features::set('feature:attendance', false);
    try { [$s, $j] = cx_connect($c); assert_true($s === 403 && $j['code'] === 'disabled', 'feature off: '.json_encode($j)); }
    finally { Features::set('feature:attendance', true); \Core\Gate::flush(); }
    // a code is bound to the user who created it: a signed-in browser session of someone else changes nothing
    act_as($other);
    $_SERVER['HTTP_ACCEPT'] = 'application/json';
    $r = http('POST', '/api/agent/connect', ['code' => $c, 'device_name' => 'Shared PC'], false);
    unset($_SERVER['HTTP_ACCEPT']);
    $j = json_decode($r->body(), true);
    assert_true($r->status() === 200 && $j['user']['id'] === $emp, 'token for the code owner, not the session user: '.json_encode($j['user'] ?? $j));
    // device limit (Monitoring::createToken): 5 non-desktop tokens leave no room
    $lim = cx_user('limit@connect.test');
    for ($i = 0; $i < 5; $i++) { DB::insert('agent_tokens', ['user_id' => $lim, 'name' => 'Key '.$i, 'kind' => 'token', 'token_hash' => hash('sha256', 'k'.$i.$lim), 'created_at' => date('Y-m-d H:i:s')]); }
    [$s, $j] = cx_connect(DesktopConnect::issue($lim, 'web'));
    assert_true($s === 422 && str_contains($j['error'], 'at most 5'), 'device limit: '.json_encode($j));
    DB::query('DELETE FROM rate_limits'); },

  'flow B: the connect page needs a web login (and comes back), shows who and what, Allow needs CSRF and opens the deep link' => function () {
    $emp = cx_user('emp@connect.test');
    [$v, $ch] = cx_pkce();
    $st = 'state_'.bin2hex(random_bytes(8));
    $path = '/time/desktop/connect?challenge='.$ch.'&state='.$st.'&device='.rawurlencode('Asha\'s ThinkPad').'&os=win';
    act_as(null);
    $r = http('GET', $path);
    assert_eq(302, $r->status(), 'guests are sent to the sign-in');
    assert_eq(url('/login').'?next='.rawurlencode($path), $r->headers()['Location'] ?? '', 'with next back to the request');
    act_as($emp);
    $r = http('GET', $path);
    $h = $r->body();
    $name = (string) DB::value('SELECT name FROM users WHERE id=?', [$emp]);
    assert_eq(200, $r->status(), substr(strip_tags($h), 0, 300));
    assert_true(str_contains($h, e($name)) && str_contains($h, 'emp@connect.test') && str_contains($h, e('Asha\'s ThinkPad')) && str_contains($h, 'Windows') && str_contains($h, e(company_name())), 'shows user, device, OS and workspace');
    assert_true(str_contains($h, 'value="allow"') && str_contains($h, 'value="cancel"') && str_contains($h, 'Not you?') && str_contains($h, 'name="next" value="'.e($path).'"'), 'Allow, Cancel and Not you? Sign out');
    assert_true(str_contains(strtolower($r->headers()['Cache-Control'] ?? ''), 'no-store'), 'not cached');
    assert_true(!preg_match('/<script(?![^>]*\bsrc=)(?![^>]*\bnonce=)/i', $h), 'no inline script');
    foreach (['challenge=short&state='.$st, 'challenge='.$ch.'&state=x', 'challenge='.$ch.'&state='.$st.'&os=amiga', 'challenge='.$ch.'&state='.$st.'&device='.str_repeat('d', 101)] as $q) { assert_eq(400, http('GET', '/time/desktop/connect?'.$q)->status(), 'invalid: '.$q); }
    $form = ['challenge' => $ch, 'state' => $st, 'device' => 'Asha\'s ThinkPad', 'os' => 'win', 'decision' => 'allow'];
    $before = (int) DB::value('SELECT COUNT(*) FROM desktop_connect_codes WHERE user_id=?', [$emp]);
    assert_eq(419, http('POST', '/time/desktop/connect', $form, false)->status(), 'Allow needs the CSRF token');
    assert_true(str_contains(http('POST', '/time/desktop/connect', ['decision' => 'cancel'] + $form)->body(), 'Not connected'), 'Cancel');
    assert_eq($before, (int) DB::value('SELECT COUNT(*) FROM desktop_connect_codes WHERE user_id=?', [$emp]), 'no code without Allow');
    $r = http('POST', '/time/desktop/connect', $form);
    assert_eq(200, $r->status());
    $h = $r->body();
    $q = cx_link($h);
    assert_true($q['ws'] === absolute_url('/') && $q['state'] === $st && preg_match('/^[A-Za-z0-9_-]{43}$/', $q['code']), 'deep link with ws, code and state: '.$q['link']);
    assert_true(str_contains($h, 'data-deeplink') && str_contains($h, 'js/tt-connect.js') && str_contains($h, 'You can close this tab') && str_contains($h, 'Install Niyanta Tracker first'), 'link + external script + copy');
    assert_true(!preg_match('/<script(?![^>]*\bsrc=)(?![^>]*\bnonce=)/i', $h), 'no inline script');
    $row = DB::first('SELECT * FROM desktop_connect_codes WHERE code_hash=?', [hash('sha256', $q['code'])]);
    assert_true($row['purpose'] === 'browser' && $row['challenge'] === $ch && $row['state'] === $st && $row['device_os'] === 'win' && (int) $row['user_id'] === $emp, 'bound to user, challenge and state');
    [$s, $j] = cx_connect($q['code'], ['verifier' => $v, 'device_name' => 'Asha\'s ThinkPad', 'device_os' => 'win']);
    assert_true($s === 200 && $j['user']['id'] === $emp, json_encode($j));
    // "Not you? Sign out" comes back to the request after the next sign-in
    act_as($emp);
    $r = http('POST', '/logout', ['next' => $path]);
    assert_eq(url('/login').'?next='.rawurlencode($path), $r->headers()['Location'] ?? '', 'sign out keeps next');
    act_as($emp);
    assert_eq(url('/login'), http('POST', '/logout', ['next' => '//evil.example/x'])->headers()['Location'] ?? '', 'foreign next dropped');
    DB::query('DELETE FROM rate_limits'); },

  'flow A: the Time page offers "Connect the desktop app", the button mints a 2-minute code and opens the deep link' => function () {
    $emp = cx_user('emp@connect.test');
    act_as($emp);
    $h = http('GET', '/time')->body();
    assert_true(str_contains($h, 'Connect the desktop app') && str_contains($h, 'action="'.e(url('/time/desktop/connect-link')).'"') && str_contains($h, 'stays signed in even when you sign out of the website') && str_contains($h, 'Your devices'), 'button and copy on the download card');
    assert_eq(419, http('POST', '/time/desktop/connect-link', [], false)->status(), 'needs CSRF');
    act_as(null);
    assert_eq(302, http('POST', '/time/desktop/connect-link')->status(), 'needs a web login');
    act_as($emp);
    $r = http('POST', '/time/desktop/connect-link');
    $q = cx_link($r->body());
    assert_true($r->status() === 200 && !isset($q['state']) && $q['ws'] === absolute_url('/'), 'deep link: '.$q['link']);
    assert_true(str_contains($r->body(), 'Opening Niyanta Tracker') && str_contains($r->body(), 'Nothing happened?'), 'opening page');
    assert_eq('web', DB::value('SELECT purpose FROM desktop_connect_codes WHERE code_hash=?', [hash('sha256', $q['code'])]));
    assert_eq(200, cx_connect($q['code'])[0], 'exchanges without a verifier');
    act_as($emp);
    for ($i = 0; $i < 12 && ($s = http('POST', '/time/desktop/connect-link')->status()) === 200; $i++) { }
    assert_eq(429, $s, 'button throttled');
    DB::query('DELETE FROM rate_limits'); },

  'install scripts open the deep link with a fresh 30-minute install code after installing' => function () {
    $emp = cx_user('emp@connect.test');
    $root = sys_get_temp_dir().'/ny-cx-'.bin2hex(random_bytes(4)); $dir = "$root/1.5.0"; mkdir($dir, 0775, true);
    $bin = ['niyanta-tracker-1.5.0-win-x64.exe' => 'MZexe', 'niyanta-tracker-1.5.0-mac-arm64.zip' => 'PKarm', 'niyanta-tracker-1.5.0-mac-x64.zip' => 'PKx64', 'niyanta-tracker-1.5.0-linux-x86_64.AppImage' => "\x7fELFapp"];
    $files = [];
    foreach ($bin as $n => $c) { file_put_contents("$dir/$n", $c); $files[$n] = ['size' => strlen($c)] + (DesktopApp::classify($n) ?? []); }
    file_put_contents("$dir/manifest.json", json_encode(['version' => '1.5.0', 'tag' => 'tracker-v1.5.0', 'name' => 'v1.5.0', 'published_at' => '2026-11-06T08:00:00Z', 'synced_at' => date('Y-m-d H:i:s'), 'files' => $files]));
    DesktopApp::$root = $root; DesktopApp::forget();
    try {
      $k = DesktopInstall::key($emp);
      act_as(null);
      $sh = http('GET', '/time/desktop/install.sh?k='.$k, [], false)->body();
      assert_true((bool) preg_match("/^CONNECT='(niyanta-tracker:\/\/connect\?[^']+)'$/m", $sh, $m), 'CONNECT in the bash script');
      $q = cx_link($m[1]);
      assert_true($q['ws'] === absolute_url('/') && preg_match('/^[A-Za-z0-9_-]{43}$/', $q['code']), 'deep link: '.$m[1]);
      assert_true(str_contains($sh, 'open "$CONNECT"') && str_contains($sh, 'nohup "$dest" "$CONNECT"') && str_contains($sh, 'MimeType=x-scheme-handler/niyanta-tracker;'), 'opens it on macOS and Linux, registers the scheme');
      $tmp = tempnam(sys_get_temp_dir(), 'cx'); file_put_contents($tmp, $sh);
      exec('bash -n '.escapeshellarg($tmp).' 2>&1', $o, $rc); @unlink($tmp); assert_eq(0, $rc, 'bash -n: '.implode("\n", $o));
      $row = DB::first('SELECT * FROM desktop_connect_codes WHERE code_hash=?', [hash('sha256', $q['code'])]);
      assert_true($row['purpose'] === 'install' && (int) $row['user_id'] === $emp && strtotime($row['expires_at']) - strtotime($row['created_at']) === 1800 && $row['challenge'] === null, 'install code, 30 minutes: '.json_encode($row));
      [$s, $j] = cx_connect($q['code']);
      assert_true($s === 200 && $j['user']['id'] === $emp, 'install code signs in: '.json_encode($j));
      $ps = http('GET', '/time/desktop/install.ps1?k='.$k, [], false)->body();
      assert_true((bool) preg_match("/\\\$connect = '(niyanta-tracker:\/\/connect\?[^']+)'/", $ps, $m), 'connect link in PowerShell');
      $q2 = cx_link($m[1]);
      assert_true($q2['code'] !== $q['code'] && str_contains($ps, 'Start-Process -FilePath $connect') && str_contains($ps, 'HKEY_CLASSES_ROOT\niyanta-tracker'), 'fresh code, opened once the app is set up');
      assert_true(!preg_match('/-Wait|-WindowStyle|Hidden|Unblock-File|^\s*exit\b/mi', $ps) && substr_count($ps, '{') === substr_count($ps, '}'), 'still no AV-flagged patterns, balanced');
      assert_eq('win', DB::value('SELECT device_os FROM desktop_connect_codes WHERE code_hash=?', [hash('sha256', $q2['code'])]));
      $n = (int) DB::value('SELECT COUNT(*) FROM desktop_connect_codes');
      http('GET', '/time/desktop/install.sh?k=bad', [], false);
      assert_eq($n, (int) DB::value('SELECT COUNT(*) FROM desktop_connect_codes'), 'no code for an invalid install link'); }
    finally { DesktopApp::$root = null; DesktopApp::forget(); exec('rm -rf '.escapeshellarg($root)); DB::query('DELETE FROM rate_limits'); } },

  'web logout (POST /logout) leaves desktop tokens signed in' => function () {
    $emp = cx_user('emp@connect.test');
    [, $j] = cx_connect(DesktopConnect::issue($emp, 'web'));
    $tok = $j['token'];
    act_as($emp);
    $r = http('POST', '/logout');
    assert_eq(302, $r->status(), 'signed out of the website');
    assert_true(!\Core\Auth::check(), 'web session gone');
    act_as(null);
    [$s, $b] = cx_api('GET', '/api/agent/bootstrap', [], $tok);
    assert_true($s === 200 && $b['user']['id'] === $emp, 'desktop token still works: '.json_encode($b));
    assert_eq(null, DB::value('SELECT revoked_at FROM agent_tokens WHERE token_hash=?', [hash('sha256', $tok)]), 'not revoked');
    DB::query('DELETE FROM rate_limits'); },

  'exchange is throttled per address (failed codes lock out, then the route limit)' => function () {
    DB::query('DELETE FROM rate_limits');
    for ($i = 0; $i < 25 && ($s = cx_connect(DesktopConnect::b64url(random_bytes(32)))[0]) === 401; $i++) { }
    assert_eq(429, $s, 'too many failed codes');
    $emp = cx_user('emp@connect.test');
    [$s, $j] = cx_connect(DesktopConnect::issue($emp, 'web'));
    assert_true($s === 429 && $j['code'] === 'locked', 'even a valid code waits: '.json_encode($j));
    DB::query('DELETE FROM rate_limits');
    for ($i = 0; $i < 35 && ($s = cx_connect('x')[0]) !== 429; $i++) { if ($i % 15 === 14) { DB::query("DELETE FROM rate_limits WHERE k=?", [hash('sha256', 'connect-fail:127.0.0.1')]); } }
    assert_eq(429, $s, 'route throttle');
    DB::query('DELETE FROM rate_limits'); },

  'version: sha512 (base64) per file from the mirror, cached; the agent API never redirects and links stay on the workspace host' => function () {
    $emp = cx_user('emp@connect.test');
    $root = sys_get_temp_dir().'/ny-cxv-'.bin2hex(random_bytes(4)); $dir = "$root/1.6.0"; mkdir($dir, 0775, true);
    $bin = ['niyanta-tracker-1.6.0-win-x64.exe' => 'MZ'.str_repeat('w', 50), 'niyanta-tracker-1.6.0-mac-arm64.dmg' => 'dmg-arm', 'niyanta-tracker-1.6.0-linux-x86_64.AppImage' => "\x7fELF6"];
    $sha = static fn (string $n): string => base64_encode(hash('sha512', $bin[$n], true));
    $bin['latest.yml'] = "version: 1.6.0\nfiles:\n  - url: niyanta-tracker-1.6.0-win-x64.exe\n    sha512: ".$sha('niyanta-tracker-1.6.0-win-x64.exe')."\n    size: 52\npath: niyanta-tracker-1.6.0-win-x64.exe\n";
    $files = [];
    foreach ($bin as $n => $c) { file_put_contents("$dir/$n", $c); $files[$n] = ['size' => strlen($c)] + (DesktopApp::classify($n) ?? []); }
    $files['niyanta-tracker-1.6.0-linux-x86_64.AppImage']['sha512'] = $sha('niyanta-tracker-1.6.0-linux-x86_64.AppImage');
    file_put_contents("$dir/manifest.json", json_encode(['version' => '1.6.0', 'tag' => 'tracker-v1.6.0', 'name' => 'v1.6.0', 'published_at' => '2026-11-06T08:00:00Z', 'synced_at' => date('Y-m-d H:i:s'), 'files' => $files]));
    DesktopApp::$root = $root; DesktopApp::forget();
    try {
      [, $j] = cx_connect(DesktopConnect::issue($emp, 'web'));
      $tok = $j['token'];
      $host = $_SERVER['HTTP_HOST'];
      $_SERVER['HTTP_HOST'] = 'evil.example';
      try { [$s, $v] = cx_api('GET', '/api/agent/version', [], $tok); } finally { $_SERVER['HTTP_HOST'] = $host; }
      assert_eq(200, $s, json_encode($v));
      $all = array_merge(...array_values($v['files']));
      $by = array_column($all, null, 'name');
      foreach (['niyanta-tracker-1.6.0-win-x64.exe', 'niyanta-tracker-1.6.0-mac-arm64.dmg', 'niyanta-tracker-1.6.0-linux-x86_64.AppImage'] as $n) {
        assert_eq($sha($n), $by[$n]['sha512'] ?? null, 'sha512 of '.$n);
        assert_true(isset($by[$n]['arch'], $by[$n]['ext']), 'name, arch, ext'); }
      assert_true(is_file("$dir/sha512.json") && isset(json_decode((string) file_get_contents("$dir/sha512.json"), true)['niyanta-tracker-1.6.0-mac-arm64.dmg']), 'hashed once, cached next to the mirror');
      assert_true(!isset(json_decode((string) file_get_contents("$dir/sha512.json"), true)['niyanta-tracker-1.6.0-win-x64.exe']), 'files listed in latest.yml are not hashed');
      $ws = parse_url(absolute_url('/'), PHP_URL_HOST);
      foreach (['download_page', 'updates_feed'] as $k) { assert_eq($ws, parse_url($v[$k], PHP_URL_HOST), $k.' on the workspace host'); }
      assert_eq(404, DesktopApp::path('sha512.json') === null ? 404 : 200, 'cache file is not served');
      // no endpoint answers with a redirect (signed out, bad token, unknown path)
      foreach ([['GET', '/api/agent/bootstrap'], ['GET', '/api/agent/version'], ['GET', '/api/agent/updates/latest.yml'], ['POST', '/api/agent/login'], ['POST', '/api/agent/connect'], ['POST', '/api/agent/heartbeat'], ['GET', '/api/agent/nope'], ['POST', '/api/agent/logout']] as [$m, $p]) {
        foreach ([null, 'nyt_'.str_repeat('0', 48), $tok] as $t) {
          act_as(null);
          [$s, , $r] = cx_api($m, $p, [], $t);
          assert_true($s < 300 || $s >= 400, "$m $p: no redirect ($s)");
          assert_true(!isset($r->headers()['Location']), "$m $p: no Location header"); } } }
    finally { DesktopApp::$root = null; DesktopApp::forget(); exec('rm -rf '.escapeshellarg($root)); DB::query('DELETE FROM rate_limits'); } },
];
