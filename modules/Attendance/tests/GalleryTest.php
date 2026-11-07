<?php
use Core\{DB, Gate};

/** Whole-day screenshot gallery fixture: a manager with one direct report who has three captures today (two slots), and an outsider with one. */
function gal_fixture(): array {
  static $f = null;
  if ($f) { return $f; }
  $mgr = make_user('manager', 'mgr@galunit.test');
  $emp = make_user('employee', 'emp@galunit.test', ['manager_id' => $mgr, 'name' => 'Gal Owner']);
  $out = make_user('employee', 'out@galunit.test', ['name' => 'Gal Outsider']);
  $d = date('Y-m-d');
  $shot = static fn (int $u, string $t, int $a, int $screen) => DB::insert('screenshots', ['user_id' => $u, 'disk_path' => 'screenshots/galunit/x.jpg', 'taken_at' => $d.' '.$t, 'source' => 'desktop', 'activity_pct' => $a, 'display_index' => $screen]);
  $ids = [$shot($emp, '00:21:00', 80, 0), $shot($emp, '00:21:00', 70, 1), $shot($emp, '00:34:00', 20, 0)];
  $shot($out, '00:22:00', 50, 0);
  return $f = compact('mgr', 'emp', 'out', 'ids'); }

/** The gallery list a page sends once (#shot-viewer[data-day]), or null when the page has none. */
function gal_day(string $html): ?array { return preg_match('/id="shot-viewer"[^>]*data-day="([^"]*)"/', $html, $m) ? json_decode(html_entity_decode($m[1], ENT_QUOTES), true) : null; }

function gal_grant(bool $on): void {
  $rid = (int) DB::value("SELECT id FROM roles WHERE slug='manager'");
  DB::query("DELETE FROM role_permissions WHERE role_id=? AND permission='attendance.screenshots.view'", [$rid]);
  if ($on) { DB::insert('role_permissions', ['role_id' => $rid, 'permission' => 'attendance.screenshots.view']); }
  Gate::flush(); }

return [
  'gallery: the owner gets the whole day once, cards and chart bars open it at the slot\'s first capture' => function () {
    ['emp' => $emp, 'ids' => $ids] = gal_fixture();
    act_as($emp);
    $html = http('GET', '/time/screenshots')->body();
    $day = gal_day($html);
    assert_true(is_array($day), 'gallery data on the screenshots page');
    assert_eq($ids, array_column($day, 'id'), 'every capture of the day, oldest first, across slots');
    foreach (['src', 't', 'a', 'd', 'g', 'h', 'r', 'p'] as $k) { assert_true(array_key_exists($k, $day[0]), 'item has '.$k); }
    assert_eq(2, $day[1]['d'], 'screen number');
    assert_true(str_contains($day[0]['src'], '/time/screenshots/'.$ids[0].'/image'), 'image route');
    assert_true(!str_contains($html, 'data-shots='), 'no per-card lists any more');
    assert_true(str_contains($html, 'id="slot-0-2" data-shot-at="0"') && str_contains($html, 'id="slot-0-3" data-shot-at="2"'), 'cards point at the first capture of their slot');
    $daily = http('GET', '/time/daily')->body();
    assert_eq($ids, array_column(gal_day($daily) ?? [], 'id'), 'daily page sends the same list');
    assert_true(str_contains($daily, 'data-shot-at="2"'), 'chart bar opens the second slot at its first capture'); },

  'gallery: a manager without the screenshots permission gets no images, with it the team member\'s day is audited' => function () {
    ['mgr' => $mgr, 'emp' => $emp, 'ids' => $ids] = gal_fixture();
    try {
      gal_grant(false); act_as($mgr);
      $r = http('GET', '/time/daily?user='.$emp);
      assert_eq(200, $r->status(), 'daily timesheet of a report');
      assert_eq(null, gal_day($r->body()), 'no gallery without attendance.screenshots.view');
      assert_eq(403, http('GET', '/time/screenshots?user='.$emp)->status(), 'screenshots page needs the permission');
      $n = (int) DB::value("SELECT COUNT(*) FROM audit_logs WHERE action='monitoring.view' AND entity_id=?", [$emp]);
      gal_grant(true); act_as($mgr);
      assert_eq($ids, array_column(gal_day(http('GET', '/time/daily?user='.$emp)->body()) ?? [], 'id'), 'daily gallery with the permission');
      assert_eq($ids, array_column(gal_day(http('GET', '/time/screenshots?user='.$emp)->body()) ?? [], 'id'), 'screenshots gallery with the permission');
      assert_eq($n + 2, (int) DB::value("SELECT COUNT(*) FROM audit_logs WHERE action='monitoring.view' AND entity_id=?", [$emp]), 'both views audited'); }
    finally { gal_grant(false); } },

  'gallery: someone outside the team is refused and never listed' => function () {
    ['mgr' => $mgr, 'out' => $out] = gal_fixture();
    try {
      gal_grant(true); act_as($mgr);
      assert_eq(403, http('GET', '/time/screenshots?user='.$out)->status());
      assert_eq(403, http('GET', '/time/daily?user='.$out)->status());
      assert_eq(403, http('GET', '/time/screenshots/'.(int) DB::value('SELECT id FROM screenshots WHERE user_id=?', [$out]).'/image')->status(), 'image refused');
      $own = gal_day(http('GET', '/time/screenshots')->body());
      assert_eq(null, $own, 'the manager has no captures of their own today'); }
    finally { gal_grant(false); } },
];
