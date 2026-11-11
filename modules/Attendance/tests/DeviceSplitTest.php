<?php
use Core\{DB, Gate};
use Modules\Attendance\Services\{Devices, TimeService};

/**
 * Device split on the Day timesheet and Screenshots: a member who tracked yesterday on two devices (A 2h with 600s idle, B 1h 30m)
 * plus a 30-minute manual entry; screenshots (one of B's only through its entry) and activity slots per device; a single-device
 * member; and an outsider's token.
 */
function dev_fixture(): array {
  static $f = null;
  if ($f) { return $f; }
  $mgr = make_user('manager', 'mgr@devunit.test');
  $emp = make_user('employee', 'emp@devunit.test', ['manager_id' => $mgr, 'name' => 'Dev Owner']);
  $one = make_user('employee', 'one@devunit.test', ['manager_id' => $mgr, 'name' => 'One Device']);
  $out = make_user('employee', 'out@devunit.test', ['name' => 'Dev Outsider']);
  $d = date('Y-m-d', strtotime('-1 day')); $now = date('Y-m-d H:i:s');
  $tok = static fn (int $u, string $n, string $os) => DB::insert('agent_tokens', ['user_id' => $u, 'name' => $n, 'kind' => 'desktop', 'device_os' => $os, 'token_hash' => hash('sha256', $n.$u), 'created_at' => $now]);
  $a = $tok($emp, 'Dev MacBook Pro', 'mac'); $b = $tok($emp, 'Dev Office PC', 'windows'); $c = $tok($out, 'Outsider Laptop', 'linux'); $s1 = $tok($one, 'Only Laptop', 'mac');
  $entry = static fn (int $u, string $t, int $sec, ?int $k, int $idle = 0) => DB::insert('time_entries', ['user_id' => $u, 'started_at' => "$d $t", 'ended_at' => date('Y-m-d H:i:s', strtotime("$d $t") + $sec), 'duration_seconds' => $sec, 'idle_seconds' => $idle, 'status' => 'draft', 'is_running' => 0, 'source' => $k ? 'desktop' : 'manual', 'agent_token_id' => $k, 'billable' => 1, 'created_at' => $now]);
  $ea = $entry($emp, '09:00:00', 7200, $a, 600); $eb = $entry($emp, '13:00:00', 5400, $b); $entry($emp, '15:00:00', 1800, null);
  $entry($one, '10:00:00', 3600, $s1);
  $shot = static fn (int $u, string $t, int $pct, ?int $k, ?int $e) => DB::insert('screenshots', ['user_id' => $u, 'time_entry_id' => $e, 'disk_path' => 'screenshots/devunit/x.jpg', 'taken_at' => "$d $t", 'source' => 'desktop', 'activity_pct' => $pct, 'display_index' => 0, 'agent_token_id' => $k]);
  $shots = [$shot($emp, '09:05:00', 80, $a, $ea), $shot($emp, '10:15:00', 60, $a, $ea), $shot($emp, '13:20:00', 30, null, $eb), $shot($emp, '14:05:00', 50, $b, $eb)];
  $slot = static fn (string $t, int $act, int $k, int $e) => DB::insert('activity_slots', ['user_id' => $emp, 'time_entry_id' => $e, 'slot_start' => "$d $t", 'slot_seconds' => 600, 'active_seconds' => $act, 'agent_token_id' => $k, 'created_at' => $now]);
  $slot('09:00:00', 480, $a, $ea); $slot('10:10:00', 420, $a, $ea); $slot('13:20:00', 300, $b, $eb);
  return $f = compact('mgr', 'emp', 'one', 'out', 'd', 'a', 'b', 'c', 'shots'); }

function dev_grant(bool $on): void {
  $rid = (int) DB::value("SELECT id FROM roles WHERE slug='manager'");
  DB::query("DELETE FROM role_permissions WHERE role_id=? AND permission='attendance.screenshots.view'", [$rid]);
  if ($on) { DB::insert('role_permissions', ['role_id' => $rid, 'permission' => 'attendance.screenshots.view']); }
  Gate::flush(); }

/** The day summary's big total, the gallery ids, and whether the device toggle is on the page. */
function dev_page(string $html): array {
  preg_match('/class="ds-total"><b>([^<]+)</', $html, $t);
  $day = preg_match('/id="shot-viewer"[^>]*data-day="([^"]*)"/', $html, $m) ? json_decode(html_entity_decode($m[1], ENT_QUOTES), true) : [];
  return ['total' => $t[1] ?? null, 'ids' => array_column($day ?? [], 'id'), 'toggle' => str_contains($html, 'class="seg dev-seg"'), 'only' => preg_match('/Showing <b>([^<]+)<\/b> only/', $html, $o) ? $o[1] : null]; }

return [
  'devices: All devices keeps the day totals, which equal the sum of the per-device totals' => function () {
    ['emp' => $emp, 'd' => $d, 'a' => $a, 'b' => $b, 'shots' => $shots] = dev_fixture();
    act_as($emp);
    $all = http('GET', '/time/daily?date='.$d)->body(); $p = dev_page($all);
    $sec = TimeService::secondsForUser($emp, $d, $d);
    assert_eq(14400, $sec, 'fixture day total');
    assert_eq(hours($sec), $p['total'], 'summary total unchanged');
    assert_true(str_contains($all, '<dt>Idle</dt><dd class="ds-idle">'.hours(600).'</dd>') && str_contains($all, '<dt>Active</dt><dd>'.hours(13800).'</dd>'), 'active and idle unchanged');
    assert_eq($shots, $p['ids'], 'every screenshot in the gallery');
    assert_true($p['toggle'] && $p['only'] === null, 'toggle shown, All selected');
    assert_eq(http('GET', '/time/daily?date='.$d.'&device=')->body(), $all, 'an empty device param is All');
    $devs = Devices::day($emp, $d, array_map(static fn ($e) => $e + ['dur' => TimeService::elapsed($e)], DB::all('SELECT * FROM time_entries WHERE user_id=? AND started_at LIKE ?', [$emp, $d.'%'])));
    assert_eq([$a, $b, 0], array_keys($devs), 'devices by tracked time, Manual last');
    assert_eq($sec, array_sum(array_column($devs, 'secs')), 'device times add up to the day total');
    assert_eq(count($shots), array_sum(array_column($devs, 'shots')), 'device screenshots add up');
    assert_eq([7200, 2, 75], [$devs[$a]['secs'], $devs[$a]['shots'], $devs[$a]['act']], 'device A: time, screenshots, activity from slots');
    assert_eq([5400, 2, 50, 'Manual', 1800], [$devs[$b]['secs'], $devs[$b]['shots'], $devs[$b]['act'], $devs[0]['name'], $devs[0]['secs']], 'device B counts its screenshot through the entry');
    $s = dev_page(http('GET', '/time/screenshots?date='.$d)->body());
    assert_true($s['toggle'] && $s['ids'] === $shots && $s['total'] === hours($sec), 'screenshots page: same totals and every capture'); },

  'devices: picking a device shows only its entries, screenshots and numbers, links keep the date' => function () {
    ['emp' => $emp, 'd' => $d, 'a' => $a, 'b' => $b, 'shots' => $shots] = dev_fixture();
    act_as($emp);
    $h = http('GET', '/time/daily?date='.$d.'&device='.$b)->body(); $p = dev_page($h);
    assert_eq(hours(5400), $p['total'], 'device total');
    assert_eq([$shots[2], $shots[3]], $p['ids'], 'only device B screenshots');
    assert_eq('Dev Office PC', $p['only'], 'labelled');
    assert_eq(1, substr_count($h, 'class="tld-b '), 'one timeline block');
    assert_true(str_contains($h, e(url('/time/daily?date='.$d.'&device='.$a))) && str_contains($h, e(url('/time/daily?date='.$d)).'" class=""'), 'toggle links keep the date');
    assert_true(str_contains($h, 'name="device" value="'.$b.'"'), 'date navigator keeps the device');
    $m = dev_page(http('GET', '/time/daily?date='.$d.'&device=manual')->body());
    assert_true($m['total'] === hours(1800) && $m['ids'] === [] && $m['only'] === 'Manual', 'manual entries');
    $s = http('GET', '/time/screenshots?date='.$d.'&device='.$a)->body(); $sp = dev_page($s);
    assert_true($sp['ids'] === [$shots[0], $shots[1]] && $sp['total'] === hours(7200) && $sp['only'] === 'Dev MacBook Pro', 'screenshots page filtered');
    assert_eq(2, substr_count($s, 'class="shot-card"'), 'only device A cards');
    assert_true(str_contains($s, 'class="lv-hi">75%<span'), 'device activity in the summary'); },

  'devices: an unknown or foreign device param is ignored' => function () {
    ['emp' => $emp, 'd' => $d, 'c' => $c, 'shots' => $shots] = dev_fixture();
    act_as($emp);
    $all = dev_page(http('GET', '/time/daily?date='.$d)->body());
    foreach ([(string) $c, '999999', 'abc', '0', '-1'] as $v) {
      $p = dev_page(http('GET', '/time/daily?date='.$d.'&device='.urlencode($v))->body());
      assert_true($p === $all, 'ignored: '.$v); }
    assert_eq($shots, dev_page(http('GET', '/time/screenshots?date='.$d.'&device='.$c)->body())['ids'], 'screenshots page ignores a foreign token'); },

  'devices: a single-device day has no toggle and looks the same with any param' => function () {
    ['one' => $one, 'd' => $d] = dev_fixture();
    act_as($one);
    $h = http('GET', '/time/daily?date='.$d)->body();
    assert_true(!str_contains($h, 'dev-split') && !str_contains($h, 'ds-dev') && dev_page($h)['total'] === hours(3600), 'no toggle');
    $tok = (int) DB::value('SELECT id FROM agent_tokens WHERE user_id=?', [$one]);
    assert_eq($h, http('GET', '/time/daily?date='.$d.'&device='.$tok)->body(), 'device param ignored on a single-device day');
    assert_true(!str_contains(http('GET', '/time/screenshots?date='.$d)->body(), 'dev-split'), 'screenshots page: no toggle'); },

  'devices: teammate view keeps today\'s permissions' => function () {
    ['mgr' => $mgr, 'emp' => $emp, 'out' => $out, 'd' => $d, 'b' => $b, 'shots' => $shots] = dev_fixture();
    try {
      dev_grant(false); act_as($mgr);
      $r = http('GET', '/time/daily?user='.$emp.'&date='.$d.'&device='.$b);
      $p = dev_page($r->body());
      assert_true($r->status() === 200 && $p['toggle'] && $p['total'] === hours(5400) && $p['ids'] === [], 'manager: device time, no screenshots without the permission');
      assert_true(str_contains($r->body(), e(url('/time/daily?user='.$emp.'&date='.$d))), 'toggle links keep the member');
      assert_eq(403, http('GET', '/time/screenshots?user='.$emp.'&device='.$b)->status(), 'screenshots still need the permission');
      dev_grant(true); act_as($mgr);
      assert_eq([$shots[2], $shots[3]], dev_page(http('GET', '/time/screenshots?user='.$emp.'&date='.$d.'&device='.$b)->body())['ids'], 'with the permission: device B captures');
      assert_eq(403, http('GET', '/time/daily?user='.$out.'&device='.$b)->status(), 'outsider still refused');
      act_as($out);
      assert_eq(403, http('GET', '/time/daily?user='.$emp.'&date='.$d)->status(), 'not a teammate'); }
    finally { dev_grant(false); } },
];
