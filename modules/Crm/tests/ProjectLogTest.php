<?php
use Core\{DB, Features};
use Modules\Crm\Services\{ClientAccess, Journey};
use Modules\Projects\Services\ProjectAccess;

/** Client logs organised by project: client Journey chips and grouping, the project page Client log, permissions and internal projects. */
function pl_fx(): array {
  static $f = null;
  if ($f) { return $f; }
  $now = date('Y-m-d H:i:s');
  $mgr = make_user('manager', 'mgr@plog.test'); $other = make_user('manager', 'other@plog.test'); $member = make_user('manager', 'member@plog.test'); $emp = make_user('employee', 'emp@plog.test');
  $c = DB::insert('clients', ['name' => 'Ravi', 'company' => 'Plog Industries', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
  DB::insert('client_managers', ['client_id' => $c, 'user_id' => $mgr]);
  $p = static function (string $name, ?int $client, array $members) use ($mgr, $now): int {
    $id = DB::insert('projects', ['name' => $name, 'client_id' => $client, 'status' => 'active', 'manager_id' => $mgr, 'created_by' => $mgr, 'created_at' => $now, 'updated_at' => $now]);
    foreach ($members as $m) { DB::insert('project_members', ['project_id' => $id, 'user_id' => $m]); }
    return $id; };
  $p1 = $p('Plog Website', $c, [$member, $emp]); $p2 = $p('Plog Mobile', $c, []); $p3 = $p('Plog Internal Ops', null, [$member]);
  ProjectAccess::flush(); ClientAccess::flush();
  $add = static fn (array $a) => DB::insert('client_activities', $a + ['client_id' => $c, 'title' => 'Entry', 'occurred_at' => $now, 'user_id' => $mgr, 'created_at' => $now]);
  $add(['project_id' => $p1, 'type' => 'meeting', 'title' => 'P1 kickoff', 'occurred_at' => '2026-10-01 10:00:00']);
  $add(['project_id' => $p1, 'type' => 'meeting', 'title' => 'P1 review', 'occurred_at' => '2026-10-20 10:00:00']);
  $add(['project_id' => $p1, 'type' => 'call', 'title' => 'P1 call', 'occurred_at' => '2026-10-10 10:00:00']);
  $add(['project_id' => $p2, 'type' => 'call', 'title' => 'P2 call', 'occurred_at' => '2026-10-15 10:00:00']);
  $add(['project_id' => null, 'type' => 'note', 'title' => 'General note', 'is_internal' => 1, 'occurred_at' => '2026-10-25 10:00:00']);
  $add(['project_id' => null, 'type' => 'call', 'title' => 'General call', 'occurred_at' => '2026-10-05 10:00:00']);
  return $f = compact('mgr', 'other', 'member', 'emp', 'c', 'p1', 'p2', 'p3'); }

function pl_loc(Core\Response $r): string { return (string) ($r->headers()['Location'] ?? ''); }

return [
  'journey counts: one GROUP BY gives type counts within the project filter and project counts within the type filter' => function () {
    $rows = [['type' => 'meeting', 'project_id' => 1, 'n' => 2], ['type' => 'call', 'project_id' => 1, 'n' => 1], ['type' => 'call', 'project_id' => 2, 'n' => 1], ['type' => 'note', 'project_id' => null, 'n' => 1], ['type' => 'call', 'project_id' => null, 'n' => 1]];
    [$t, $types, $projs] = Journey::tally($rows, '', '');
    assert_eq(6, $t); assert_eq(['meeting' => 2, 'call' => 3, 'note' => 1], $types); assert_eq(['1' => 3, '2' => 1, 'none' => 2], $projs);
    [, $types, $projs] = Journey::tally($rows, 'call', '1');
    assert_eq(['meeting' => 2, 'call' => 1], $types, 'types inside project 1'); assert_eq(['1' => 1, '2' => 1, 'none' => 1], $projs, 'projects inside type call');
    [, $types] = Journey::tally($rows, '', 'none');
    assert_eq(['note' => 1, 'call' => 1], $types, 'non-project types'); },

  'client Journey: project chips with counts, All view grouped under project headings, filtered views combine with type and keep pagination' => function () {
    $f = pl_fx(); act_as($f['mgr']);
    $b = (string) http('GET', '/clients/'.$f['c'])->body();
    assert_true(str_contains($b, 'All projects <span class="crm-count">6</span>'), 'all projects chip');
    assert_true(str_contains($b, 'Plog Website <span class="crm-count">3</span>') && str_contains($b, 'Plog Mobile <span class="crm-count">1</span>') && str_contains($b, 'Non-project <span class="crm-count">2</span>'), 'project chips with counts');
    assert_eq(3, substr_count($b, 'class="crm-proj-h'), 'three groups: two projects and non-project');
    assert_true(str_contains($b, 'Non-project activity') && str_contains($b, 'href="'.url('/projects/'.$f['p1']).'">Plog Website</a>'), 'headings link to the project');
    $g = strpos($b, 'General note'); $r = strpos($b, 'P1 review'); $k = strpos($b, 'P1 kickoff'); $m = strpos($b, 'P2 call');
    assert_true($g < $r && $r < $k && $k < $m, 'groups by latest entry (non-project 25 Oct, Website 20 Oct, Mobile 15 Oct), newest first inside');
    $b = (string) http('GET', '/clients/'.$f['c'].'?jt=call')->body();
    assert_true(str_contains($b, 'All projects <span class="crm-count">3</span>') && str_contains($b, 'Plog Website <span class="crm-count">1</span>'), 'project chips count within the type filter');
    $b = (string) http('GET', '/clients/'.$f['c'].'?jpr='.$f['p1'])->body();
    assert_true(str_contains($b, 'P1 kickoff') && !str_contains($b, 'P2 call') && !str_contains($b, 'General note'), 'project filter');
    assert_eq(0, substr_count($b, 'class="crm-proj-h'), 'no group headings in a filtered view');
    assert_eq(3, substr_count($b, 'class="pill pill-sky" href="'.url('/projects/'.$f['p1']).'"'), 'project pill on each entry');
    assert_true(str_contains($b, 'href="'.url('/clients/'.$f['c'].'?jt=meeting&amp;jpr='.$f['p1'].'#journey').'"'), 'type chips keep the project filter');
    $b = (string) http('GET', '/clients/'.$f['c'].'?jpr=none&jt=call')->body();
    assert_true(str_contains($b, 'General call') && !str_contains($b, 'General note') && !str_contains($b, 'P1 call'), 'non-project + type');
    assert_true(str_contains($b, 'pill pill-gray">'), 'non-project pill');
    $b = (string) http('GET', '/clients/'.$f['c'].'?jpr=999999')->body();
    assert_eq(3, substr_count($b, 'class="crm-proj-h'), 'unknown project falls back to All');
    $ids = [];
    for ($i = 0; $i < 26; $i++) { $ids[] = DB::insert('client_activities', ['client_id' => $f['c'], 'project_id' => $f['p2'], 'type' => 'email', 'title' => 'Bulk '.$i, 'occurred_at' => '2026-09-01 10:00:00', 'created_at' => date('Y-m-d H:i:s')]); }
    try {
      $b = (string) http('GET', '/clients/'.$f['c'].'?jpr='.$f['p2'])->body();
      assert_true(str_contains($b, url('/clients/'.$f['c'].'?jpr='.$f['p2'].'&amp;jp=2#journey')), 'older page keeps the project filter');
      $b2 = (string) http('GET', '/clients/'.$f['c'].'?jpr='.$f['p2'].'&jp=2')->body();
      assert_eq(2, substr_count($b2, 'id="log-'), 'second page holds the rest (27 entries, 25 per page)'); }
    finally { DB::query('DELETE FROM client_activities WHERE id IN '.DB::in($ids), $ids); } },

  'Log activity dialog on the client page: optional Project select, first option "No project", hidden without projects' => function () {
    $f = pl_fx(); act_as($f['mgr']);
    $b = (string) http('GET', '/clients/'.$f['c'])->body();
    assert_true((bool) preg_match('#<select id="a-proj" name="project_id"[^>]*><option value="">No project \(non-project activity\)</option>#', $b), 'project select with No project first');
    assert_true(!preg_match('#id="a-proj"[^>]*required#', $b), 'not mandatory');
    Features::set('feature:projects', false);
    try { assert_true(!str_contains((string) http('GET', '/clients/'.$f['c'])->body(), 'id="a-proj"'), 'no select while projects are off'); }
    finally { Features::set('feature:projects', true); ProjectAccess::flush(); }
    $r = http('POST', '/clients/'.$f['c'].'/activities', ['type' => 'call', 'title' => 'No project call', 'occurred_at' => '2026-10-30T10:00', 'project_id' => '']);
    assert_true(str_ends_with(pl_loc($r), '/clients/'.$f['c'].'#journey'));
    assert_eq(null, DB::value("SELECT project_id FROM client_activities WHERE title='No project call'"), 'saved as non-project activity');
    $r = http('POST', '/clients/'.$f['c'].'/activities', ['type' => 'call', 'title' => 'Wrong project', 'occurred_at' => '2026-10-30T10:00', 'project_id' => (string) $f['p3']]);
    assert_eq(null, DB::value("SELECT id FROM client_activities WHERE title='Wrong project'"), 'a project of another client is refused'); },

  'project page: Client log tab, newest first, Log activity button; entries saved from it carry the project and return to the tab' => function () {
    $f = pl_fx(); act_as($f['mgr']);
    $b = (string) http('GET', '/projects/'.$f['p1'].'?tab=log')->body();
    assert_true(str_contains($b, 'Client log') && str_contains($b, 'data-modal="activity-modal"'), 'tab + Log activity button');
    assert_true(strpos($b, 'P1 review') < strpos($b, 'P1 call') && strpos($b, 'P1 call') < strpos($b, 'P1 kickoff'), 'newest first');
    assert_true(!str_contains($b, 'P2 call') && !str_contains($b, 'General note'), 'only this project');
    assert_true(!str_contains($b, 'tab=log&amp;jp=2') && !str_contains($b, '>Older'), 'no pager for three entries');
    assert_true(str_contains($b, '<input type="hidden" name="project_id" value="'.$f['p1'].'">') && str_contains($b, 'crm-fixed-proj'), 'project fixed in the dialog');
    assert_true(str_contains((string) http('GET', '/projects/'.$f['p1'])->body(), 'tab=log'), 'tab link on the overview');
    $r = http('POST', '/projects/'.$f['p1'].'/activities', ['type' => 'requirement', 'title' => 'Needs dark mode', 'body' => 'From the client', 'occurred_at' => '2026-11-01T09:30', 'follow_up_at' => '2026-11-03T09:00']);
    assert_true(str_ends_with(pl_loc($r), '/projects/'.$f['p1'].'?tab=log'), 'back to the project tab: '.pl_loc($r));
    $a = DB::first("SELECT * FROM client_activities WHERE title='Needs dark mode'");
    assert_eq($f['c'], (int) $a['client_id'], 'client taken from the project'); assert_eq($f['p1'], (int) $a['project_id']);
    assert_true(str_contains((string) http('GET', '/clients/'.$f['c'].'?jpr='.$f['p1'])->body(), 'Needs dark mode'), 'also on the client Journey');
    http('POST', '/projects/'.$f['p1'].'/activities/'.$a['id'], ['type' => 'requirement', 'title' => 'Needs dark mode v2', 'occurred_at' => '2026-11-01T09:30', 'follow_up_at' => '2026-11-03T09:00']);
    assert_eq('Needs dark mode v2', DB::value('SELECT title FROM client_activities WHERE id=?', [$a['id']]), 'edited');
    http('POST', '/projects/'.$f['p1'].'/activities/'.$a['id'].'/done');
    assert_eq(1, (int) DB::value('SELECT follow_up_done FROM client_activities WHERE id=?', [$a['id']]), 'follow-up done');
    $p2entry = (int) DB::value("SELECT id FROM client_activities WHERE title='P2 call'");
    assert_eq(404, http('POST', '/projects/'.$f['p1'].'/activities/'.$p2entry, ['type' => 'call', 'title' => 'Hijack', 'occurred_at' => '2026-11-01T09:30'])->status(), 'entry of another project');
    assert_eq(404, http('POST', '/projects/'.$f['p1'].'/activities/'.$p2entry.'/delete')->status());
    http('POST', '/projects/'.$f['p1'].'/activities/'.$a['id'].'/delete');
    assert_eq(null, DB::value('SELECT id FROM client_activities WHERE id=?', [$a['id']]), 'deleted');
    assert_true((int) DB::value("SELECT COUNT(*) FROM audit_logs WHERE action='crm.activity.save' AND meta LIKE '%\"from\":\"project\"%'") >= 2, 'audit-logged'); },

  'project log permissions: crm.clients.manage plus project and client visibility; switched off with Clients' => function () {
    $f = pl_fx();
    $post = static fn (int $p) => http('POST', '/projects/'.$p.'/activities', ['type' => 'call', 'title' => 'Sneaky', 'occurred_at' => '2026-11-01T09:30']);
    act_as($f['emp']);
    $b = (string) http('GET', '/projects/'.$f['p1'].'?tab=log')->body();
    assert_true(!str_contains($b, '>Client log<') && !str_contains($b, 'P1 kickoff'), 'project member without client access sees no log');
    assert_eq(403, $post($f['p1'])->status(), 'route needs crm.clients.manage');
    act_as($f['other']);
    assert_eq(404, $post($f['p1'])->status(), 'project not visible');
    act_as($f['member']);
    assert_true(!str_contains((string) http('GET', '/projects/'.$f['p1'].'?tab=log')->body(), 'P1 kickoff'), 'project member who is not on the client account');
    assert_eq(404, $post($f['p1'])->status(), 'client not visible');
    assert_eq(null, DB::value("SELECT id FROM client_activities WHERE title='Sneaky'"));
    act_as($f['mgr']);
    Features::set('feature:clients', false);
    try {
      assert_true(!str_contains((string) http('GET', '/projects/'.$f['p1'].'?tab=log')->body(), 'P1 kickoff'), 'tab hidden while Clients is off');
      assert_eq(403, $post($f['p1'])->status(), 'route off'); }
    finally { Features::set('feature:clients', true); } },

  'internal project without a client keeps a log: client_id NULL, never on client pages, scoped by project access, removed with the project' => function () {
    $f = pl_fx();
    act_as($f['member']);
    $r = http('POST', '/projects/'.$f['p3'].'/activities', ['type' => 'discussion', 'title' => 'Internal sync', 'occurred_at' => '2026-11-02T11:00']);
    assert_true(str_ends_with(pl_loc($r), '/projects/'.$f['p3'].'?tab=log'), 'saved: '.pl_loc($r).' '.$r->status());
    $a = DB::first("SELECT * FROM client_activities WHERE title='Internal sync'");
    assert_eq(null, $a['client_id'], 'no client'); assert_eq($f['p3'], (int) $a['project_id']);
    $b = (string) http('GET', '/projects/'.$f['p3'].'?tab=log')->body();
    assert_true(str_contains($b, 'Internal sync') && str_contains($b, 'Internal project'), 'listed on the project');
    act_as($f['mgr']);
    assert_true(!str_contains((string) http('GET', '/clients/'.$f['c'])->body(), 'Internal sync'), 'not on any client page');
    act_as($f['other']);
    assert_eq(404, http('POST', '/projects/'.$f['p3'].'/activities/'.$a['id'], ['type' => 'call', 'title' => 'x', 'occurred_at' => '2026-11-02T11:00'])->status(), 'non-member cannot edit it');
    act_as($GLOBALS['ADMIN_ID']);
    $keep = DB::insert('client_activities', ['client_id' => $f['c'], 'project_id' => $f['p3'], 'type' => 'call', 'title' => 'Client call about ops', 'occurred_at' => '2026-11-02 12:00:00', 'created_at' => date('Y-m-d H:i:s')]);
    http('POST', '/projects/'.$f['p3'].'/delete');
    assert_eq(null, DB::value('SELECT id FROM projects WHERE id=?', [$f['p3']]), 'project deleted');
    assert_eq(null, DB::value('SELECT id FROM client_activities WHERE id=?', [$a['id']]), 'client-less log removed with it');
    $k = DB::first('SELECT client_id, project_id FROM client_activities WHERE id=?', [$keep]);
    assert_true($k && (int) $k['client_id'] === $f['c'] && $k['project_id'] === null, 'client log stays, project link cleared'); },
];
