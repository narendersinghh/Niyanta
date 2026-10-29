<?php
use Core\{DB, Settings};
use Modules\Attendance\Services\{AttendanceService, DesktopApp, Tracker};
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

  'version endpoint and downloads use uploaded installers' => function () {
    ['emp' => $emp] = trk_fixture();
    $tok = trk_login();
    $tmp = tempnam(sys_get_temp_dir(), 'exe'); file_put_contents($tmp, "MZ".str_repeat("\0", 2000));
    $row = DesktopApp::upload(['name' => 'niyanta-tracker-1.2.0-win-x64.exe', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => 2002], '1.2.0', (int) $GLOBALS['ADMIN_ID']);
    assert_true($row['platform'] === 'windows' && is_file(DesktopApp::filePath($row)), 'stored privately');
    assert_true(str_starts_with(DesktopApp::filePath($row), NY_ROOT.'/storage/desktop/1.2.0/'), 'storage/desktop/<version>');
    [$code, $j] = trk_api('GET', '/api/agent/version', [], $tok, [], '1.0.0');
    assert_eq(200, $code, json_encode($j));
    assert_true($j['latest'] === '1.2.0' && $j['update_available'] === true && str_contains($j['download_page'], '/time'), json_encode($j));
    assert_eq(false, trk_api('GET', '/api/agent/version', [], $tok, [], '1.2.0')[1]['update_available'], 'up to date');
    act_as($emp);
    $r = http('GET', '/time/desktop/download?f='.$row['id']);
    assert_eq(200, $r->status(), 'signed-in tracker can download');
    $html = (string) http('GET', '/time')->body();
    assert_true(str_contains($html, 'niyanta-tracker') || str_contains($html, 'v1.2.0'), 'time page offers the installer');
    act_as(null);
    $g = http('GET', '/time/desktop/download?f='.$row['id'])->status();
    assert_true(in_array($g, [301, 302, 303, 401], true), 'guests cannot download: '.$g);
    assert_eq(['platform' => 'mac', 'arch' => 'arm64', 'ext' => 'dmg'], DesktopApp::classify('niyanta-tracker-1.2.0-mac-arm64.dmg'));
    assert_eq('deb', DesktopApp::classify('niyanta-tracker-1.2.0-linux-amd64.deb')['platform']);
    $rel = DesktopApp::pickRelease([['tag_name' => 'v9.0.0', 'assets' => []], ['tag_name' => 'tracker-v1.3.0', 'draft' => false, 'prerelease' => false, 'published_at' => '2026-10-01T00:00:00Z', 'assets' => [['name' => 'niyanta-tracker-1.3.0-linux-x86_64.AppImage', 'size' => 9, 'browser_download_url' => 'https://example.test/a']]], ['tag_name' => 'tracker-v1.4.0', 'prerelease' => true, 'assets' => []]]);
    assert_true($rel['version'] === '1.3.0' && $rel['assets'][0]['platform'] === 'linux', 'picks the newest published tracker release');
    DesktopApp::remove($row['id'], (int) $GLOBALS['ADMIN_ID']); },

  'web app has no start/stop controls; tracking routes are gone' => function () {
    ['emp' => $emp] = trk_fixture();
    act_as($emp);
    $html = (string) http('GET', '/time')->body();
    foreach (['/time/start', '/time/stop', 'clock-in', 'data-cap-start', 'getDisplayMedia', 'Start timer'] as $needle) { assert_true(!str_contains($html, $needle), "/time contains $needle"); }
    assert_true(str_contains($html, 'Get the desktop app') && str_contains($html, 'data-live-status'), 'download section and live status');
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
];
