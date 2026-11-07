<?php
use Core\{Cache, DB, Settings};
use Modules\Admin\Services\UpdateService;
use Modules\Attendance\Services\{AttendanceService, DesktopApp, TimeService, Tracker};
use Modules\Payroll\Services\PayrollService;

/** Desktop tracker API fixture: an employee on project A (not B) and a fresh device token. */
function trk_fixture(): array {
  static $f = null;
  if ($f) { return $f; }
  $emp = make_user('employee', 'emp@trkunit.test');
  $mgr = make_user('manager', 'mgr@trkunit.test');
  $a = DB::insert('projects', ['name' => 'Tracker Alpha', 'status' => 'active', 'manager_id' => $mgr, 'created_at' => date('Y-m-d H:i:s')]);
  $b = DB::insert('projects', ['name' => 'Tracker Hidden', 'status' => 'active', 'manager_id' => $mgr, 'created_at' => date('Y-m-d H:i:s')]);
  DB::insert('project_members', ['project_id' => $a, 'user_id' => $emp]);
  $t1 = DB::insert('tasks', ['project_id' => $a, 'title' => 'Design review', 'status' => 'todo', 'created_at' => date('Y-m-d H:i:s')]);
  return $f = compact('emp', 'mgr', 'a', 'b', 't1'); }

/** Call the agent API like the desktop app (Bearer token, JSON answers). Returns [status, decoded body]. */
function trk_api(string $method, string $path, array $post = [], ?string $token = null, array $files = [], string $ver = '1.0.0'): array {
  $_SERVER['HTTP_ACCEPT'] = 'application/json'; $_SERVER['HTTP_X_APP_VERSION'] = $ver;
  if ($token) { $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer '.$token; } else { unset($_SERVER['HTTP_AUTHORIZATION']); }
  $_FILES = $files;
  act_as(null);
  $r = http($method, $path, $post, false);
  unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTP_X_APP_VERSION']); $_FILES = [];
  return [$r->status(), json_decode($r->body(), true) ?? ['raw' => substr($r->body(), 0, 300)]]; }

function trk_login(): string {
  [$code, $j] = trk_api('POST', '/api/agent/login', ['email' => 'emp@trkunit.test', 'password' => 'Passw0rd123', 'device_name' => 'Unit laptop', 'os' => 'linux']);
  assert_eq(200, $code, 'login: '.json_encode($j));
  return $j['token']; }

function trk_jpeg(): array {
  $im = imagecreatetruecolor(64, 40); imagefill($im, 0, 0, imagecolorallocate($im, 91, 76, 245));
  $tmp = tempnam(sys_get_temp_dir(), 'shot'); imagejpeg($im, $tmp, 60);
  return ['screenshot' => ['name' => 'screen-0.jpg', 'type' => 'image/jpeg', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp)]]; }

return [
  'login returns a device token and bootstrap scopes projects to the user' => function () {
    ['a' => $a, 'b' => $b, 'emp' => $emp] = trk_fixture();
    [$code, $j] = trk_api('POST', '/api/agent/login', ['email' => 'emp@trkunit.test', 'password' => 'wrong-pass1']);
    assert_eq(401, $code, 'bad password');
    $tok = trk_login();
    assert_true((bool) preg_match('/^nyt_[a-f0-9]{48}$/', $tok), 'token format');
    assert_eq('desktop', DB::value('SELECT kind FROM agent_tokens WHERE user_id=? AND revoked_at IS NULL ORDER BY id DESC LIMIT 1', [$emp]));
    assert_true((bool) DB::value("SELECT 1 FROM audit_logs WHERE action='agent.login' AND user_id=?", [$emp]), 'login audited');
    [$code, $j] = trk_api('GET', '/api/agent/bootstrap', [], $tok);
    assert_eq(200, $code, json_encode($j));
    $ids = array_column($j['projects'], 'id');
    assert_true(in_array($a, $ids, true) && !in_array($b, $ids, true), 'only visible projects: '.json_encode($ids));
    assert_eq('Design review', $j['projects'][array_search($a, $ids, true)]['tasks'][0]['title'] ?? null, 'open tasks included');
    assert_eq('not_started', $j['state']['state']);
    assert_true(isset($j['settings']['screenshot_interval_seconds'], $j['policy']['points']), 'settings and policy');
    assert_eq(401, trk_api('GET', '/api/agent/bootstrap', [], 'nyt_'.str_repeat('0', 48))[0], 'unknown token');
    // a second sign-in on the same device replaces the first token
    $tok2 = trk_login();
    assert_eq(401, trk_api('GET', '/api/agent/state', [], $tok)[0], 'old token of the same device revoked');
    assert_eq(200, trk_api('POST', '/api/agent/logout', [], $tok2)[0]);
    assert_eq(401, trk_api('GET', '/api/agent/state', [], $tok2)[0], 'logout revokes'); },

  'start, switch without a gap, pause, resume and end: worked time equals tracked time' => function () {
    ['a' => $a, 't1' => $t1, 'emp' => $emp, 'b' => $b] = trk_fixture();
    $tok = trk_login();
    [$code, $j] = trk_api('POST', '/api/agent/day/start', ['project_id' => $a], $tok);
    assert_eq(200, $code, json_encode($j));
    assert_eq('tracking', $j['state']['state']);
    assert_eq('in', AttendanceService::state(AttendanceService::today($emp)), 'starting the day clocks in');
    assert_eq(200, trk_api('POST', '/api/agent/day/start', ['project_id' => $a], $tok)[0], 'start is idempotent');
    assert_eq(1, (int) DB::value('SELECT COUNT(*) FROM time_entries WHERE user_id=?', [$emp]));
    // pretend the first entry has run for 50 minutes
    DB::query('UPDATE time_entries SET started_at=DATE_SUB(started_at, INTERVAL 3000 SECOND), last_heartbeat_at=NOW() WHERE user_id=?', [$emp]);
    DB::query('UPDATE attendance SET clock_in=DATE_SUB(clock_in, INTERVAL 3000 SECOND) WHERE user_id=?', [$emp]);
    assert_eq(422, trk_api('POST', '/api/agent/switch', ['project_id' => $b], $tok)[0], 'cannot switch to an invisible project');
    [$code, $j] = trk_api('POST', '/api/agent/switch', ['project_id' => $a, 'task_id' => $t1, 'description' => 'Review'], $tok);
    assert_eq(200, $code, json_encode($j));
    assert_eq('tracking', $j['state']['state'], 'switch does not pause');
    $e = DB::all('SELECT * FROM time_entries WHERE user_id=? ORDER BY id', [$emp]);
    assert_eq(2, count($e), 'two entries');
    assert_eq($e[0]['ended_at'], $e[1]['started_at'], 'contiguous: no gap');
    assert_true($e[0]['is_running'] == 0 && $e[1]['is_running'] == 1 && (int) $e[1]['task_id'] === $t1, 'second entry runs on the task');
    assert_true((int) $e[0]['duration_seconds'] >= 2999, 'first entry kept its time');
    DB::query('UPDATE time_entries SET started_at=DATE_SUB(started_at, INTERVAL 600 SECOND) WHERE id=?', [$e[1]['id']]);
    DB::query('UPDATE time_entries SET started_at=DATE_SUB(started_at, INTERVAL 600 SECOND), ended_at=DATE_SUB(ended_at, INTERVAL 600 SECOND) WHERE id=?', [$e[0]['id']]);
    [$code, $j] = trk_api('POST', '/api/agent/pause', [], $tok);
    assert_eq('paused', $j['state']['state'], json_encode($j));
    assert_eq('break', AttendanceService::state(AttendanceService::today($emp)), 'pause is a break');
    assert_eq(0, (int) DB::value('SELECT COUNT(*) FROM time_entries WHERE user_id=? AND is_running=1', [$emp]), 'pause stops the entry');
    [$code, $j] = trk_api('POST', '/api/agent/resume', [], $tok);
    assert_eq('tracking', $j['state']['state'], json_encode($j));
    assert_eq($t1, $j['state']['running']['task_id'], 'resume continues the last project and task');
    [$code, $j] = trk_api('POST', '/api/agent/heartbeat', ['active_seconds' => 50, 'idle_seconds' => 10], $tok);
    assert_true($code === 200 && $j['running'] === true, 'heartbeat while tracking');
    [$code, $j] = trk_api('POST', '/api/agent/day/end', [], $tok);
    assert_eq('ended', $j['state']['state'], json_encode($j));
    $att = AttendanceService::today($emp);
    $tracked = (int) DB::value('SELECT SUM(duration_seconds) FROM time_entries WHERE user_id=?', [$emp]);
    assert_true($tracked >= 3599, 'tracked about an hour: '.$tracked);
    assert_eq($tracked, (int) $att['worked_seconds'], 'attendance worked seconds = tracked seconds');
    assert_eq(3, (int) DB::value("SELECT COUNT(*) FROM time_entries WHERE user_id=? AND status='approved' AND source='desktop'", [$emp]), 'entries approved automatically');
    assert_eq(200, trk_api('POST', '/api/agent/day/end', [], $tok)[0], 'end is idempotent'); },

  'actions queued offline keep their client time (clamped)' => function () {
    ['a' => $a, 'emp' => $emp] = trk_fixture();
    $tok = trk_login();
    DB::query('DELETE FROM attendance WHERE user_id=?', [$emp]); DB::query('DELETE FROM time_entries WHERE user_id=?', [$emp]);
    trk_api('POST', '/api/agent/day/start', ['project_id' => $a, 'at' => date('c', time() - 1800)], $tok);
    $start = strtotime((string) DB::value('SELECT started_at FROM time_entries WHERE user_id=?', [$emp]));
    assert_true(abs($start - (time() - 1800)) <= 2, 'start uses the queued time');
    trk_api('POST', '/api/agent/pause', ['at' => date('c', time() - 600)], $tok);
    $e = DB::first('SELECT * FROM time_entries WHERE user_id=?', [$emp]);
    assert_true(abs((int) $e['duration_seconds'] - 1200) <= 2, 'pause at the queued time: '.$e['duration_seconds']);
    [, $j] = trk_api('POST', '/api/agent/resume', ['at' => date('c', time() - 86400)], $tok);
    $r = DB::first('SELECT started_at FROM time_entries WHERE user_id=? AND is_running=1', [$emp]);
    assert_true(strtotime($r['started_at']) >= strtotime((string) $e['ended_at']), 'never before the last event');
    trk_api('POST', '/api/agent/day/end', ['at' => date('c', time() + 3600)], $tok);
    assert_true(strtotime((string) DB::value('SELECT clock_out FROM attendance WHERE user_id=?', [$emp])) <= time(), 'never in the future'); },

  'screenshots need monitoring enabled and consent, and only while tracking' => function () {
    ['a' => $a, 'emp' => $emp] = trk_fixture();
    $tok = trk_login();
    DB::query('DELETE FROM attendance WHERE user_id=?', [$emp]); DB::query('DELETE FROM time_entries WHERE user_id=?', [$emp]);
    Settings::set('attendance.screenshots_enabled', '0');
    trk_api('POST', '/api/agent/day/start', ['project_id' => $a], $tok);
    [$code, $j] = trk_api('POST', '/api/agent/screenshot', ['activity_pct' => 80, 'display' => 0], $tok, trk_jpeg());
    assert_eq(422, $code, 'disabled'); assert_true(str_contains($j['error'], 'turned off'));
    Settings::set('attendance.screenshots_enabled', '1');
    [$code, $j] = trk_api('POST', '/api/agent/screenshot', ['activity_pct' => 80, 'display' => 0], $tok, trk_jpeg());
    assert_eq(422, $code, 'no consent'); assert_true(str_contains($j['error'], 'consent'));
    assert_eq(422, trk_api('POST', '/api/agent/consent', ['agree' => '0'], $tok)[0], 'consent needs agreement');
    [$code, $j] = trk_api('POST', '/api/agent/consent', ['agree' => '1', 'policy_version' => \Modules\Attendance\Services\Monitoring::POLICY_VERSION], $tok);
    assert_eq(200, $code, json_encode($j));
    [$code, $j] = trk_api('POST', '/api/agent/screenshot', ['activity_pct' => 80, 'display' => 0, 'captured_at' => date('c')], $tok, trk_jpeg());
    assert_eq(200, $code, json_encode($j));
    [$code] = trk_api('POST', '/api/agent/screenshot', ['activity_pct' => 80, 'display' => 1], $tok, trk_jpeg());
    assert_eq(200, $code, 'second display in the same capture is accepted');
    $s = DB::first('SELECT * FROM screenshots WHERE id=?', [$j['id']]);
    assert_true($s['source'] === 'desktop' && (int) $s['display_index'] === 0 && (int) $s['activity_pct'] === 80 && $s['time_entry_id'], 'stored with display, activity and entry');
    trk_api('POST', '/api/agent/pause', [], $tok);
    [$code, $j] = trk_api('POST', '/api/agent/screenshot', ['display' => 2], $tok, trk_jpeg());
    assert_eq(422, $code, 'refused while paused');
    act_as($emp);
    assert_eq(200, http('GET', '/time/screenshots')->status(), 'owner sees own screenshots');
    Settings::set('attendance.screenshots_enabled', '0'); },

  'desktop error reports land in the error log with source desktop and the app version' => function () {
    $tok = trk_login();
    [$code] = trk_api('POST', '/api/agent/error', ['level' => 'error', 'message' => 'Capture failed: display 2 unavailable', 'where' => 'capture', 'stack' => 'Error: x', 'os' => 'win32'], $tok, [], '1.3.7');
    assert_eq(200, $code);
    $r = DB::first("SELECT * FROM error_logs WHERE source='desktop' AND message LIKE 'Capture failed%' LIMIT 1");
    assert_true($r && $r['app_version'] === '1.3.7' && (int) $r['user_id'] === trk_fixture()['emp'], 'recorded: '.json_encode($r));
    [$code] = trk_api('POST', '/api/agent/error', ['message' => 'Sign-in screen crashed'], null, [], '1.3.7');
    assert_eq(200, $code, 'works without a token');
    assert_eq(422, trk_api('POST', '/api/agent/error', [], null)[0], 'message required'); },

  'desktop app: newest private tracker release is mirrored, downloaded from the server and fed to the auto-updater' => function () {
    ['emp' => $emp] = trk_fixture();
    $tok = trk_login();
    $oldTok = (string) Settings::get('updates.github_token', ''); $oldRepo = (string) Settings::get('updates.repo', '');
    Settings::set('updates.github_token', ''); Settings::set('updates.repo', '');
    assert_eq('narendersinghh/Niyanta', DesktopApp::repo(), 'official repository from niyanta.json');
    assert_eq('tracker-v', \Core\App::trackerTagPrefix());
    $root = sys_get_temp_dir().'/ny-desktop-'.bin2hex(random_bytes(4));
    DesktopApp::$root = $root; DesktopApp::$fetcher = null; DesktopApp::forget();
    Cache::forget(DesktopApp::cacheKey());
    try {
      act_as($emp);
      $html = (string) http('GET', '/time')->body();
      assert_true(str_contains($html, "The desktop app isn't available yet. An administrator needs to connect GitHub in") && str_contains($html, 'Not available yet'), 'no token: friendly message');
      assert_eq('skipped: no GitHub token', DesktopApp::cron());

      $bin = ['niyanta-tracker-1.2.10-win-x64.exe' => 'MZ'.str_repeat('w', 300), 'niyanta-tracker-1.2.10-win-x64.exe.blockmap' => 'bm-win', 'niyanta-tracker-1.2.10-mac-arm64.dmg' => 'dmg-arm', 'niyanta-tracker-1.2.10-mac-x64.dmg' => 'dmg-x64', 'niyanta-tracker-1.2.10-mac-arm64.zip' => 'PKzip',
        'niyanta-tracker-1.2.10-linux-x86_64.AppImage' => "\x7fELF".str_repeat('a', 500), 'niyanta-tracker-1.2.10-linux-amd64.deb' => '!<arch>deb'];
      $sha = static fn (string $c): string => base64_encode(hash('sha512', $c, true));
      $bin['latest-linux.yml'] = "version: 1.2.10\nfiles:\n  - url: niyanta-tracker-1.2.10-linux-x86_64.AppImage\n    sha512: ".$sha($bin['niyanta-tracker-1.2.10-linux-x86_64.AppImage'])."\n    size: 504\n    blockMapSize: 98765\npath: niyanta-tracker-1.2.10-linux-x86_64.AppImage\nsha512: ".$sha($bin['niyanta-tracker-1.2.10-linux-x86_64.AppImage'])."\nreleaseDate: '2026-10-20T08:00:00.000Z'\n";
      $bin['latest.yml'] = "version: 1.2.10\nfiles:\n  - url: niyanta-tracker-1.2.10-win-x64.exe\n    sha512: ".$sha($bin['niyanta-tracker-1.2.10-win-x64.exe'])."\n    size: 302\npath: niyanta-tracker-1.2.10-win-x64.exe\n";
      $id = 100; $assets = [];
      foreach ($bin as $n => $c) { $assets[] = ['id' => ++$id, 'name' => $n, 'size' => strlen($c), 'url' => 'https://api.github.com/repos/narendersinghh/Niyanta/releases/assets/'.$id]; }
      $assets[] = ['id' => 999, 'name' => 'notes.txt', 'size' => 3];
      $list = [
        ['tag_name' => 'v9.0.0', 'draft' => false, 'prerelease' => false, 'assets' => [['id' => 1, 'name' => 'niyanta-9.0.0.zip', 'size' => 9]]],
        ['tag_name' => 'tracker-v1.4.0', 'draft' => false, 'prerelease' => true, 'assets' => []],
        ['tag_name' => 'tracker-v1.5.0', 'draft' => true, 'prerelease' => false, 'assets' => []],
        ['tag_name' => 'tracker-v1.2.10', 'draft' => false, 'prerelease' => false, 'published_at' => '2026-10-20T08:00:00Z', 'assets' => $assets],
        ['tag_name' => 'tracker-v1.2.9', 'draft' => false, 'prerelease' => false, 'assets' => []]];
      $rel = DesktopApp::pickRelease($list);
      assert_true($rel['version'] === '1.2.10' && count($rel['assets']) === count($bin) && !isset($rel['assets']['notes.txt']), 'newest by semver, drafts/prereleases/other files ignored');
      assert_eq(['platform' => 'mac', 'arch' => 'arm64', 'ext' => 'dmg', 'version' => '1.2.0'], DesktopApp::classify('niyanta-tracker-1.2.0-mac-arm64.dmg'));
      assert_true(DesktopApp::classify('setup.exe') === null && DesktopApp::classify('niyanta-tracker-1.2.0-win-x64.exe.blockmap') === null, 'only official installer names');

      // stubbed GitHub: metadata in the cache, assets through the fetcher
      $fetched = [];
      DesktopApp::$fetcher = static function (array $a) use ($bin, &$fetched): string { $fetched[] = $a['name']; return $bin[$a['name']]; };
      Cache::put(DesktopApp::cacheKey(), ['ok' => true, 'release' => $rel, 'checked_at' => date('c')], 600);
      foreach (['1.0.0', '1.1.0'] as $old) { @mkdir("$root/$old", 0775, true); file_put_contents("$root/$old/manifest.json", json_encode(['version' => $old, 'files' => []])); }
      $s = DesktopApp::sync();
      assert_true($s['ok'] && $s['version'] === '1.2.10' && $s['downloaded'] === count($bin), json_encode($s));
      assert_true(is_file("$root/1.2.10/niyanta-tracker-1.2.10-win-x64.exe") && is_file("$root/1.2.10/manifest.json") && is_file("$root/.htaccess"), 'mirrored privately');
      assert_true(is_dir("$root/1.1.0") && !is_dir("$root/1.0.0"), 'keeps the newest 2 versions');
      $fetched = [];
      assert_true(DesktopApp::sync()['downloaded'] === 0 && $fetched === [], 'each asset downloaded once');
      assert_eq('up to date 1.2.10', DesktopApp::cron());
      $l = DesktopApp::latest();
      assert_true($l['ok'] && $l['version'] === '1.2.10' && count($l['platforms']['mac']) === 3 && $l['platforms']['mac'][0]['ext'] === 'dmg' && str_contains($l['platforms']['windows'][0]['url'], '/time/desktop/download/niyanta-tracker-1.2.10-win-x64.exe'), json_encode($l['platforms']));

      // sha512 mismatch is rejected
      $bad = $rel; $bad['version'] = '1.3.0'; $bad['tag'] = 'tracker-v1.3.0';
      $bad['assets'] = ['latest.yml' => ['id' => 1, 'size' => 0, 'url' => ''], 'niyanta-tracker-1.3.0-win-x64.exe' => ['id' => 2, 'size' => 0, 'url' => '']];
      DesktopApp::$fetcher = static fn (array $a): string => $a['name'] === 'latest.yml' ? "version: 1.3.0\nfiles:\n  - url: niyanta-tracker-1.3.0-win-x64.exe\n    sha512: AAAA\n" : 'MZtampered';
      Cache::put(DesktopApp::cacheKey(), ['ok' => true, 'release' => $bad, 'checked_at' => date('c')], 600);
      $s = DesktopApp::sync();
      assert_true(!$s['ok'] && str_contains((string) $s['error'], 'sha512') && !is_file("$root/1.3.0/niyanta-tracker-1.3.0-win-x64.exe"), json_encode($s));
      DesktopApp::forget();
      assert_eq('1.2.10', DesktopApp::latest()['version'], 'failed mirror leaves the previous version in place');
      Cache::put(DesktopApp::cacheKey(), ['ok' => true, 'release' => $rel, 'checked_at' => date('c')], 600);

      // Time Tracking page and the download route
      $html = (string) http('GET', '/time')->body();
      assert_true(str_contains($html, '/time/desktop/download/niyanta-tracker-1.2.10-win-x64.exe') && str_contains($html, 'v1.2.10') && !str_contains($html, "isn't available yet") && !str_contains($html, 'github.com'), 'time page offers server downloads');
      $r = http('GET', '/time/desktop/download/niyanta-tracker-1.2.10-win-x64.exe');
      assert_true($r->status() === 200 && str_starts_with((string) ($r->headers()['Content-Disposition'] ?? ''), 'attachment;') && ($r->headers()['Content-Length'] ?? '') === '302', 'streamed attachment: '.$r->status());
      assert_eq(404, http('GET', '/time/desktop/download/latest.yml')->status(), 'only installers on the web route');
      assert_eq(404, http('GET', '/time/desktop/download/..%2Fmanifest.json')->status(), 'no traversal');
      act_as(null);
      $g = http('GET', '/time/desktop/download/niyanta-tracker-1.2.10-win-x64.exe')->status();
      assert_true(in_array($g, [301, 302, 303, 401], true), 'guests cannot download: '.$g);

      // version endpoint and the electron-updater feed (device token)
      [$code, $j] = trk_api('GET', '/api/agent/version', [], $tok, [], '1.0.0');
      assert_true($code === 200 && $j['latest'] === '1.2.10' && $j['update_available'] === true && str_ends_with($j['updates_feed'], '/api/agent/updates/'), json_encode($j));
      assert_eq(false, trk_api('GET', '/api/agent/version', [], $tok, [], '1.2.10')[1]['update_available'], 'up to date');
      assert_eq(401, trk_api('GET', '/api/agent/updates/latest-linux.yml', [], null)[0], 'feed needs the device token');
      $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer '.$tok;
      $r = http('GET', '/api/agent/updates/latest-linux.yml', [], false);
      $abs = "'".absolute_url('/api/agent/updates/niyanta-tracker-1.2.10-linux-x86_64.AppImage')."'";
      assert_true($r->status() === 200 && str_starts_with((string) ($r->headers()['Content-Type'] ?? ''), 'text/yaml'), $r->status().' '.$r->body());
      assert_true(str_contains($r->body(), "  - url: $abs\n") && str_contains($r->body(), "path: $abs\n") && str_contains($r->body(), 'blockMapSize: 98765') && str_contains($r->body(), "releaseDate: '2026-10-20T08:00:00.000Z'"), $r->body());
      $b = http('GET', '/api/agent/updates/niyanta-tracker-1.2.10-win-x64.exe.blockmap', [], false);
      assert_true($b->status() === 200 && ($b->headers()['Content-Length'] ?? '') === '6', 'blockmap served');
      assert_eq(200, http('GET', '/api/agent/updates/niyanta-tracker-1.2.10-linux-x86_64.AppImage', [], false)->status(), 'binary served');
      assert_eq(404, http('GET', '/api/agent/updates/latest-mac.yml', [], false)->status(), 'not in the release');
      assert_eq(404, http('GET', '/api/agent/updates/manifest.json', [], false)->status(), 'manifest not served');
      unset($_SERVER['HTTP_AUTHORIZATION']); }
    finally {
      DesktopApp::$fetcher = null; DesktopApp::$root = null; DesktopApp::forget(); Cache::forget(DesktopApp::cacheKey());
      Settings::set('updates.github_token', $oldTok); Settings::set('updates.repo', $oldRepo);
      if (is_dir($root)) { exec('rm -rf '.escapeshellarg($root)); } } },

  'installer uploads are gone; the web app updater ignores tracker releases' => function () {
    foreach (['/attendance/desktop/upload', '/attendance/desktop/delete'] as $p) { assert_eq(404, http('POST', $p)->status(), $p); }
    act_as((int) $GLOBALS['ADMIN_ID']);
    $html = (string) http('GET', '/admin/settings/attendance')->body();
    assert_true(!str_contains($html, 'tracker.github_repo') && !str_contains($html, 'Upload installer') && !str_contains($html, 'Desktop app installers'), 'no installer settings');
    $html = (string) http('GET', '/admin/updates')->body();
    assert_true(str_contains($html, 'placeholder="narendersinghh/Niyanta"') && str_contains($html, 'id="desktop-mirror"') && str_contains($html, 'Refresh now'), 'built-in repository and the desktop mirror status in Settings › Updates');
    act_as(null);
    assert_true(!method_exists(DesktopApp::class, 'upload') && !method_exists(DesktopApp::class, 'remove') && !is_file(NY_ROOT.'/modules/Attendance/views/partials/installers.php'), 'upload code removed');
    $old = Settings::get('updates.repo', '');
    Settings::set('updates.repo', '');
    assert_eq(['narendersinghh', 'Niyanta'], UpdateService::repo(), 'defaults to niyanta.json');
    Settings::set('updates.repo', 'acme/fork');
    assert_eq(['acme', 'fork'], UpdateService::repo(), 'override for forks');
    Settings::set('updates.repo', (string) $old);
    $pick = UpdateService::pickAppRelease([
      ['tag_name' => 'tracker-v3.0.0', 'draft' => false, 'prerelease' => false],
      ['tag_name' => 'v1.3.0', 'draft' => false, 'prerelease' => true],
      ['tag_name' => 'v1.10.0', 'draft' => true, 'prerelease' => false],
      ['tag_name' => 'v1.2.0', 'draft' => false, 'prerelease' => false],
      ['tag_name' => 'v1.2.1', 'draft' => false, 'prerelease' => false],
      ['tag_name' => 'nightly', 'draft' => false, 'prerelease' => false]]);
    assert_eq('v1.2.1', $pick['tag_name'] ?? null, 'app release only');
    assert_eq(null, UpdateService::pickAppRelease([['tag_name' => 'tracker-v1.0.0']]), 'tracker-only repository: no app update'); },

  'web app has no start/stop controls; tracking routes are gone' => function () {
    ['emp' => $emp] = trk_fixture();
    act_as($emp);
    $html = (string) http('GET', '/time')->body();
    foreach (['/time/start', '/time/stop', 'clock-in', 'data-cap-start', 'getDisplayMedia', 'Start timer'] as $needle) { assert_true(!str_contains($html, $needle), "/time contains $needle"); }
    assert_true((str_contains($html, 'Get the desktop app') || str_contains($html, 'Add another computer')) && str_contains($html, 'data-live-status'), 'download section and live status');
    $att = (string) http('GET', '/attendance')->body();
    assert_true(!str_contains($att, 'clock-out') && !str_contains($att, 'break/start'), 'attendance page is read-only');
    foreach (['/time/start', '/time/stop', '/attendance/clock-in', '/attendance/clock-out', '/time/heartbeat'] as $p) { assert_eq(404, http('POST', $p)->status(), $p); }
    $j = json_decode((string) http('GET', '/time/status')->body(), true);
    assert_true(isset($j['state'], $j['totals']['tracked']), 'status json');
    assert_true(!is_file(NY_ROOT.'/assets/js/attendance-bg.js'), 'background heartbeat script removed'); },

  'monthly salary from tracked time: base x tracked / expected hours, capped; hourly unchanged' => function () {
    $c = ['pay_type' => 'monthly', 'amount' => 30000];
    assert_eq(15000.0, PayrollService::compute($c, 22, 0, 88, [], 0, 176)['base_pay'], 'half the expected hours');
    assert_eq(30000.0, PayrollService::compute($c, 22, 0, 200, [], 0, 176)['base_pay'], 'capped at 100%');
    assert_eq(0.0, PayrollService::compute($c, 22, 0, 10, [], 0, 0.0)['base_pay'], 'no expected hours');
    assert_eq(27272.73, PayrollService::compute($c, 22, 2, 0, [], 0)['base_pay'], 'day proration when off');
    Settings::set('general.weekend_days', '6,7'); \Modules\Hr\Services\LeaveService::flush();
    $m = make_user('employee', 'monthly@trkunit.test', ['joined_on' => '2030-01-01']);
    $h = make_user('employee', 'hourly@trkunit.test', ['joined_on' => '2030-01-01']);
    DB::insert('compensations', ['user_id' => $m, 'pay_type' => 'monthly', 'amount' => 30000, 'effective_from' => '2030-01-01', 'created_at' => date('Y-m-d H:i:s')]);
    DB::insert('compensations', ['user_id' => $h, 'pay_type' => 'hourly', 'amount' => 500, 'effective_from' => '2030-01-01', 'created_at' => date('Y-m-d H:i:s')]);
    // June 2034: 22 company working days x 8 h; two days of approved paid leave -> 160 expected hours
    $lt = DB::insert('leave_types', ['name' => 'Paid unit leave', 'code' => 'PUL', 'paid' => 1]);
    DB::insert('leave_requests', ['user_id' => $m, 'leave_type_id' => $lt, 'start_date' => '2034-06-05', 'end_date' => '2034-06-06', 'days' => 2, 'status' => 'approved', 'created_at' => date('Y-m-d H:i:s')]);
    assert_eq(160.0, PayrollService::expectedHours([$m], '2034-06-01', '2034-06-30')[$m]);
    foreach ([[$m, 80 * 3600], [$h, 10 * 3600]] as [$u, $sec]) { DB::insert('time_entries', ['user_id' => $u, 'started_at' => '2034-06-12 09:00:00', 'ended_at' => date('Y-m-d H:i:s', strtotime('2034-06-12 09:00:00') + $sec), 'duration_seconds' => $sec, 'status' => 'approved', 'is_running' => 0, 'source' => 'desktop']); }
    DB::insert('time_entries', ['user_id' => $m, 'started_at' => '2034-06-13 09:00:00', 'ended_at' => '2034-06-13 19:00:00', 'duration_seconds' => 36000, 'status' => 'rejected', 'is_running' => 0, 'source' => 'manual']);
    $run = DB::insert('payroll_runs', ['period_month' => '2034-06-01', 'status' => 'draft', 'created_at' => date('Y-m-d H:i:s')]);
    Settings::set('payroll.salary_from_tracked_time', '1');
    PayrollService::generate($run);
    $s = DB::first('SELECT * FROM payslips WHERE run_id=? AND user_id=?', [$run, $m]);
    assert_eq(15000.0, (float) $s['base_pay'], 'monthly: 80 of 160 h (rejected time excluded)');
    assert_true((float) $s['expected_hours'] === 160.0 && (float) $s['worked_hours'] === 80.0 && str_contains((string) $s['earnings'], '80 of 160 h tracked'), 'computation stored on the payslip');
    assert_eq(5000.0, (float) DB::value('SELECT base_pay FROM payslips WHERE run_id=? AND user_id=?', [$run, $h]), 'hourly unchanged');
    Settings::set('payroll.salary_from_tracked_time', '0');
    PayrollService::generate($run);
    $s = DB::first('SELECT * FROM payslips WHERE run_id=? AND user_id=?', [$run, $m]);
    assert_true((float) $s['base_pay'] === 30000.0 && $s['expected_hours'] === null, 'off: prorated by days as before');
    Settings::set('payroll.salary_from_tracked_time', '1'); },
  'midnight while tracking: the entry and the day end at 00:00 and tracking continues in the new day' => function () {
    ['a' => $a, 'emp' => $emp] = trk_fixture();
    $tok = trk_login();
    DB::query('DELETE FROM attendance WHERE user_id=?', [$emp]); DB::query('DELETE FROM time_entries WHERE user_id=?', [$emp]);
    $y = date('Y-m-d', strtotime('-1 day')); $mid = date('Y-m-d').' 00:00:00';
    $att = DB::insert('attendance', ['user_id' => $emp, 'work_date' => $y, 'clock_in' => $y.' 20:00:00', 'status' => 'present']);
    DB::insert('time_entries', ['user_id' => $emp, 'project_id' => $a, 'started_at' => $y.' 22:00:00', 'last_heartbeat_at' => date('Y-m-d H:i:s', time() - 30), 'source' => 'desktop', 'is_running' => 1, 'status' => 'draft', 'created_at' => date('Y-m-d H:i:s')]);
    [$code, $j] = trk_api('POST', '/api/agent/heartbeat', ['active_seconds' => 30, 'idle_seconds' => 0], $tok);
    assert_true($code === 200 && $j['running'] === true, 'still tracking: '.json_encode($j));
    $e = DB::all('SELECT * FROM time_entries WHERE user_id=? ORDER BY started_at', [$emp]);
    assert_eq(2, count($e), 'split at midnight');
    assert_true($e[0]['ended_at'] === $mid && !$e[0]['is_running'] && (int) $e[0]['duration_seconds'] === 7200, 'old entry ends at 00:00: '.json_encode($e[0]));
    assert_true($e[1]['started_at'] === $mid && $e[1]['is_running'] && (int) $e[1]['project_id'] === $a, 'new entry starts at 00:00 on the same project');
    assert_eq($mid, DB::value('SELECT clock_out FROM attendance WHERE id=?', [$att]), 'yesterday closed at midnight');
    assert_eq(7200, (int) DB::value('SELECT worked_seconds FROM attendance WHERE id=?', [$att]), 'yesterday worked = tracked');
    assert_eq($mid, AttendanceService::today($emp)['clock_in'], 'today starts at midnight');
    assert_eq('tracking', $j['state']['state']);
    assert_true($j['state']['totals']['tracked'] >= time() - strtotime($mid) - 2, 'today counts from midnight'); },

  'asleep across midnight: the stale entry closes at its last heartbeat, the old day closes, resume starts the new day' => function () {
    ['a' => $a, 'emp' => $emp] = trk_fixture();
    $tok = trk_login();
    DB::query('DELETE FROM attendance WHERE user_id=?', [$emp]); DB::query('DELETE FROM time_entries WHERE user_id=?', [$emp]);
    $y = date('Y-m-d', strtotime('-1 day'));
    $att = DB::insert('attendance', ['user_id' => $emp, 'work_date' => $y, 'clock_in' => $y.' 21:00:00', 'status' => 'present']);
    DB::insert('time_entries', ['user_id' => $emp, 'project_id' => $a, 'started_at' => $y.' 21:00:00', 'last_heartbeat_at' => $y.' 23:00:00', 'source' => 'desktop', 'is_running' => 1, 'status' => 'draft', 'created_at' => date('Y-m-d H:i:s')]);
    [$code, $j] = trk_api('GET', '/api/agent/state', [], $tok);
    assert_eq('not_started', $j['state']['state'], json_encode($j));
    $e = DB::first('SELECT * FROM time_entries WHERE user_id=?', [$emp]);
    assert_true($e['ended_at'] === $y.' 23:00:00' && (int) $e['duration_seconds'] === 7200, 'closed at its last heartbeat, not when noticed: '.json_encode($e));
    assert_eq($y.' 23:00:00', DB::value('SELECT clock_out FROM attendance WHERE id=?', [$att]), 'old day closed at its last entry');
    assert_eq(200, trk_api('POST', '/api/agent/pause', [], $tok)[0], 'pause with nothing open is harmless');
    [$code, $j] = trk_api('POST', '/api/agent/resume', [], $tok);
    assert_eq('tracking', $j['state']['state'], 'resume starts the new day: '.json_encode($j));
    assert_eq($a, $j['state']['running']['project_id'], 'on the last project');
    assert_eq('in', AttendanceService::state(AttendanceService::today($emp))); },

  'heartbeats: a gap (app not running) stops the entry at its last heartbeat; a continuous offline stretch is kept' => function () {
    ['a' => $a, 'emp' => $emp] = trk_fixture();
    $tok = trk_login();
    DB::query('DELETE FROM attendance WHERE user_id=?', [$emp]); DB::query('DELETE FROM time_entries WHERE user_id=?', [$emp]);
    trk_api('POST', '/api/agent/day/start', ['project_id' => $a], $tok);
    DB::query('UPDATE time_entries SET started_at=DATE_SUB(NOW(), INTERVAL 3600 SECOND), last_heartbeat_at=DATE_SUB(NOW(), INTERVAL 1800 SECOND) WHERE user_id=?', [$emp]);
    DB::query('UPDATE attendance SET clock_in=DATE_SUB(NOW(), INTERVAL 3600 SECOND) WHERE user_id=?', [$emp]);
    [$code, $j] = trk_api('POST', '/api/agent/heartbeat', ['active_seconds' => 60, 'idle_seconds' => 0], $tok);
    assert_true($code === 200 && $j['running'] === false && $j['state']['state'] === 'stopped', 'stopped: '.json_encode($j));
    $e = DB::first('SELECT * FROM time_entries WHERE user_id=?', [$emp]);
    assert_true(abs((int) $e['duration_seconds'] - 1800) <= 2, 'counted up to the last heartbeat only: '.$e['duration_seconds']);
    [, $j] = trk_api('POST', '/api/agent/resume', [], $tok);
    assert_eq('tracking', $j['state']['state']);
    DB::query('UPDATE time_entries SET started_at=DATE_SUB(NOW(), INTERVAL 1200 SECOND), last_heartbeat_at=DATE_SUB(NOW(), INTERVAL 1200 SECOND) WHERE user_id=? AND is_running=1', [$emp]);
    [$code, $j] = trk_api('POST', '/api/agent/heartbeat', ['active_seconds' => 1100, 'idle_seconds' => 100, 'at' => date('c', time() - 60)], $tok);
    assert_true($j['running'] === true, 'merged offline beats are one continuous stretch: '.json_encode($j));
    $hb = strtotime((string) DB::value('SELECT last_heartbeat_at FROM time_entries WHERE user_id=? AND is_running=1', [$emp]));
    assert_true(abs($hb - (time() - 60)) <= 2, 'last heartbeat = time of the beat, not of the upload');
    assert_eq(100, (int) DB::value('SELECT idle_seconds FROM time_entries WHERE user_id=? AND is_running=1', [$emp])); },

  'idle is saved automatically: heartbeat idle_spans are recorded as auto, the legacy idle answer never discards' => function () {
    ['a' => $a, 'emp' => $emp] = trk_fixture();
    $tok = trk_login();
    DB::query('DELETE FROM attendance WHERE user_id=?', [$emp]); DB::query('DELETE FROM time_entries WHERE user_id=?', [$emp]);
    trk_api('POST', '/api/agent/day/start', ['project_id' => $a], $tok);
    DB::query('UPDATE time_entries SET started_at=DATE_SUB(NOW(), INTERVAL 3600 SECOND), last_heartbeat_at=DATE_SUB(NOW(), INTERVAL 300 SECOND) WHERE user_id=?', [$emp]);
    DB::query('UPDATE attendance SET clock_in=DATE_SUB(NOW(), INTERVAL 3600 SECOND) WHERE user_id=?', [$emp]);
    $span = ['from' => date('c', time() - 600), 'to' => date('c', time() - 10)];
    [$code, $j] = trk_api('POST', '/api/agent/heartbeat', ['active_seconds' => 0, 'idle_seconds' => 590, 'idle_spans' => [$span]], $tok);
    assert_true($code === 200 && $j['running'] === true, 'heartbeat: '.json_encode($j));
    assert_true(abs($j['state']['totals']['tracked'] - 3600) <= 2, 'idle time stays tracked: '.$j['state']['totals']['tracked']);
    assert_true($j['state']['totals']['idle'] >= 590, 'and is reported as idle: '.$j['state']['totals']['idle']);
    assert_eq(1, (int) DB::value("SELECT COUNT(*) FROM time_idle_spans WHERE user_id=? AND action='auto' AND seconds=590", [$emp]), 'span recorded as auto');
    trk_api('POST', '/api/agent/heartbeat', ['active_seconds' => 5, 'idle_seconds' => 0, 'idle_spans' => [$span]], $tok);
    assert_eq(1, (int) DB::value('SELECT COUNT(*) FROM time_idle_spans WHERE user_id=?', [$emp]), 'a retried span is not recorded twice');
    [$code, $j] = trk_api('POST', '/api/agent/idle', ['action' => 'discard', 'spans' => [['from' => date('c', time() - 1800), 'to' => date('c', time() - 1200)]]], $tok);
    assert_true($code === 200 && $j['discarded'] === 0 && $j['kept'] === 600, 'legacy discard keeps the time: '.json_encode($j));
    assert_eq(0, (int) DB::value('SELECT discarded_seconds FROM time_entries WHERE user_id=? AND is_running=1', [$emp]));
    assert_eq(0, trk_api('POST', '/api/agent/idle', ['action' => 'discard', 'seconds' => 300], $tok)[1]['discarded'], 'legacy seconds-only answer is a no-op');
    assert_true(abs(trk_api('GET', '/api/agent/state', [], $tok)[1]['state']['totals']['tracked'] - 3600) <= 3, 'tracked time unchanged'); },

  'idle auto pause setting reaches the app and is always more than the idle threshold' => function () {
    $tok = trk_login();
    Settings::set('attendance.idle_minutes', '5'); Settings::set('attendance.idle_auto_pause_minutes', '20');
    assert_eq(20, trk_api('GET', '/api/agent/bootstrap', [], $tok)[1]['settings']['idle_auto_pause_minutes']);
    Settings::set('attendance.idle_auto_pause_minutes', '3');
    assert_eq(6, trk_api('GET', '/api/agent/state', [], $tok)[1]['settings']['idle_auto_pause_minutes'], 'clamped above idle_minutes');
    Settings::set('attendance.idle_auto_pause_minutes', '15'); },

  'activity slots: stored per slot, tied to the entry, clamped, idempotent; keystroke and mouse counts from old apps are ignored' => function () {
    ['a' => $a, 'emp' => $emp] = trk_fixture();
    $tok = trk_login();
    DB::query('DELETE FROM attendance WHERE user_id=?', [$emp]); DB::query('DELETE FROM time_entries WHERE user_id=?', [$emp]); DB::query('DELETE FROM activity_slots WHERE user_id=?', [$emp]);
    trk_api('POST', '/api/agent/day/start', ['project_id' => $a], $tok);
    DB::query('UPDATE time_entries SET started_at=DATE_SUB(NOW(), INTERVAL 3600 SECOND), last_heartbeat_at=NOW() WHERE user_id=?', [$emp]);
    $entry = (int) DB::value('SELECT id FROM time_entries WHERE user_id=? AND is_running=1', [$emp]);
    $t0 = time() - 900; $t0 -= $t0 % 180;
    $r = TimeService::activitySlots($emp, [
      ['slot_start' => gmdate('c', $t0), 'slot_seconds' => 180, 'active_seconds' => 120, 'keystrokes' => 340, 'mouse_events' => 85],
      ['slot_start' => gmdate('c', $t0 + 180), 'slot_seconds' => 180, 'active_seconds' => 999, 'keystrokes' => null, 'mouse_events' => null],
      ['slot_start' => gmdate('c', $t0 + 360), 'slot_seconds' => 0, 'active_seconds' => 0],
      ['slot_start' => 'nonsense', 'slot_seconds' => 60, 'active_seconds' => 1],
      ['slot_start' => gmdate('c', time() + 3600), 'slot_seconds' => 60, 'active_seconds' => 1]], 7);
    assert_eq(['saved' => 2, 'skipped' => 3], $r);
    $s = DB::first('SELECT * FROM activity_slots WHERE user_id=? AND slot_start=?', [$emp, date('Y-m-d H:i:s', $t0)]);
    assert_true($s && (int) $s['time_entry_id'] === $entry && (int) $s['active_seconds'] === 120 && $s['keystrokes'] === null && $s['mouse_events'] === null && (int) $s['agent_token_id'] === 7, 'stored without counts: '.json_encode($s));
    $s2 = DB::first('SELECT * FROM activity_slots WHERE user_id=? AND slot_start=?', [$emp, date('Y-m-d H:i:s', $t0 + 180)]);
    assert_true((int) $s2['active_seconds'] === 180 && $s2['keystrokes'] === null, 'active clamped to the slot, counts stay null');
    TimeService::activitySlots($emp, [['slot_start' => gmdate('c', $t0), 'slot_seconds' => 180, 'active_seconds' => 120, 'keystrokes' => 340, 'mouse_events' => 85]]);
    assert_eq(['saved' => 0, 'skipped' => 1], TimeService::activitySlots($emp, [['slot_start' => gmdate('c', $t0), 'slot_seconds' => 40, 'active_seconds' => 40]]), 'a smaller report never shrinks a slot');
    assert_eq(2, (int) DB::value('SELECT COUNT(*) FROM activity_slots WHERE user_id=?', [$emp]), 'retries are idempotent');
    TimeService::activitySlots($emp, [['slot_start' => gmdate('c', $t0 + 180), 'slot_seconds' => 180, 'active_seconds' => 30, 'keystrokes' => 3, 'mouse_events' => 4]]);
    assert_eq(30, (int) DB::value('SELECT active_seconds FROM activity_slots WHERE user_id=? AND slot_start=?', [$emp, date('Y-m-d H:i:s', $t0 + 180)]), 'a complete report replaces the slot');
    Settings::set('attendance.screenshots_enabled', '1'); DB::query('DELETE FROM screenshots WHERE user_id=?', [$emp]);
    trk_api('POST', '/api/agent/consent', ['agree' => '1', 'policy_version' => \Modules\Attendance\Services\Monitoring::POLICY_VERSION], $tok);
    [$code, $j] = trk_api('POST', '/api/agent/screenshot', ['activity_pct' => 67, 'keystrokes' => 340, 'mouse_events' => 85, 'display' => 0, 'captured_at' => date('c')], $tok, trk_jpeg());
    assert_eq(200, $code, json_encode($j));
    $sh = DB::first('SELECT * FROM screenshots WHERE id=?', [$j['id']]);
    assert_true((int) $sh['activity_pct'] === 67 && $sh['keystrokes'] === null && $sh['mouse_events'] === null, 'screenshot keeps the activity, ignores counts: '.json_encode($sh));
    Settings::set('attendance.screenshots_enabled', '0'); },

  'meeting mode: meeting slots and screenshots are flagged, kept and left out of every activity average' => function () {
    ['a' => $a, 'emp' => $emp] = trk_fixture();
    $tok = trk_login();
    DB::query('DELETE FROM attendance WHERE user_id=?', [$emp]); DB::query('DELETE FROM time_entries WHERE user_id=?', [$emp]); DB::query('DELETE FROM activity_slots WHERE user_id=?', [$emp]); DB::query('DELETE FROM screenshots WHERE user_id=?', [$emp]);
    trk_api('POST', '/api/agent/day/start', ['project_id' => $a], $tok);
    DB::query('UPDATE time_entries SET started_at=DATE_SUB(NOW(), INTERVAL 3600 SECOND), last_heartbeat_at=NOW() WHERE user_id=?', [$emp]);
    $t0 = time() - 1200; $t0 -= $t0 % 180;
    if (date('Y-m-d', $t0) !== date('Y-m-d')) { return; } /* just after midnight: the slots would be on yesterday */
    [$code, $j] = trk_api('POST', '/api/agent/activity', ['slots' => [
      ['slot_start' => gmdate('c', $t0), 'slot_seconds' => 180, 'active_seconds' => 162, 'meeting' => 0],
      ['slot_start' => gmdate('c', $t0 + 180), 'slot_seconds' => 180, 'active_seconds' => 18, 'meeting' => 1],
      ['slot_start' => gmdate('c', $t0 + 360), 'slot_seconds' => 180, 'active_seconds' => 0, 'meeting' => true]]], $tok);
    assert_true($code === 200 && $j['saved'] === 3, 'saved: '.json_encode($j));
    assert_eq([0, 1, 1], array_map('intval', DB::column('SELECT meeting FROM activity_slots WHERE user_id=? ORDER BY slot_start', [$emp])), 'meeting flag stored per slot');
    assert_eq(['pct' => 90, 'seconds' => 180, 'meeting' => 360], TimeService::dayActivity($emp, date('Y-m-d')), 'average without the meeting slots (162/180), meeting seconds reported');
    $st = trk_api('GET', '/api/agent/state', [], $tok)[1]['state'];
    assert_true($st['totals']['activity'] === 90 && $st['totals']['meeting'] === 360, 'state totals: '.json_encode($st['totals']));
    $ref = new ReflectionMethod(\Modules\Attendance\Controllers\BaseController::class, 'day');
    $sum = $ref->invoke(null, $emp, date('Y-m-d'))['sum'];
    assert_true($sum['activity'] === 90 && $sum['meeting'] === 360, 'day summary activity excludes meetings: '.json_encode($sum));
    DB::query('UPDATE activity_slots SET meeting=1 WHERE user_id=?', [$emp]);
    assert_eq(null, TimeService::dayActivity($emp, date('Y-m-d'))['pct'], 'a day of meetings only has no activity average');
    Settings::set('attendance.screenshots_enabled', '1'); DB::query('DELETE FROM screenshots WHERE user_id=?', [$emp]);
    trk_api('POST', '/api/agent/consent', ['agree' => '1', 'policy_version' => \Modules\Attendance\Services\Monitoring::POLICY_VERSION], $tok);
    [$code, $j] = trk_api('POST', '/api/agent/screenshot', ['activity_pct' => 5, 'meeting' => '1', 'display' => 0, 'captured_at' => date('c')], $tok, trk_jpeg());
    assert_eq(200, $code, json_encode($j));
    assert_eq(1, (int) DB::value('SELECT meeting FROM screenshots WHERE id=?', [$j['id']]), 'screenshot carries the meeting flag');
    [, $j2] = trk_api('POST', '/api/agent/screenshot', ['activity_pct' => 70, 'display' => 0, 'captured_at' => date('c', time() - 100)], $tok, trk_jpeg());
    assert_eq(0, (int) DB::value('SELECT meeting FROM screenshots WHERE id=?', [$j2['id']]), 'not a meeting by default');
    DB::query('DELETE FROM activity_slots WHERE user_id=?', [$emp]);
    assert_eq(70, TimeService::dayActivity($emp, date('Y-m-d'))['pct'], 'screenshot fallback average skips meeting captures');
    Settings::set('attendance.screenshots_enabled', '0'); },

  'nightly auto close keeps today running; stale_timers closes days that are over at their last entry' => function () {
    ['a' => $a, 'emp' => $emp] = trk_fixture();
    $tok = trk_login();
    DB::query('DELETE FROM attendance WHERE user_id=?', [$emp]); DB::query('DELETE FROM time_entries WHERE user_id=?', [$emp]);
    $d = date('Y-m-d', strtotime('-2 days'));
    $old = DB::insert('attendance', ['user_id' => $emp, 'work_date' => $d, 'clock_in' => $d.' 09:00:00', 'status' => 'present']);
    DB::insert('time_entries', ['user_id' => $emp, 'project_id' => $a, 'started_at' => $d.' 09:00:00', 'ended_at' => $d.' 12:00:00', 'duration_seconds' => 10800, 'source' => 'desktop', 'is_running' => 0, 'status' => 'approved', 'created_at' => date('Y-m-d H:i:s')]);
    trk_api('POST', '/api/agent/day/start', ['project_id' => $a], $tok);
    AttendanceService::autoClose();
    assert_eq(1, (int) DB::value('SELECT COUNT(*) FROM time_entries WHERE user_id=? AND is_running=1', [$emp]), 'healthy entry keeps running');
    assert_eq('in', AttendanceService::state(AttendanceService::today($emp)), 'today stays open');
    assert_eq($d.' 12:00:00', DB::value('SELECT clock_out FROM attendance WHERE id=?', [$old]), 'old day closed at its last entry');
    DB::query('UPDATE time_entries SET started_at=DATE_SUB(NOW(), INTERVAL 1800 SECOND), last_heartbeat_at=DATE_SUB(NOW(), INTERVAL 900 SECOND) WHERE user_id=? AND is_running=1', [$emp]);
    TimeService::staleTimers();
    $e = DB::first('SELECT * FROM time_entries WHERE user_id=? ORDER BY id DESC LIMIT 1', [$emp]);
    assert_true(!$e['is_running'] && abs(strtotime($e['ended_at']) - (time() - 900)) <= 2, 'stale entry stopped at its last heartbeat'); },
];
