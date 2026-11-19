<?php
use Core\{DB, View};
use Modules\Attendance\Services\TimeService;
use Modules\Crm\Services\ClientAccess;
use Modules\Projects\Services\{ProjectAccess, ProjectService, Widgets};
use Modules\Todos\Services\TaskDialog;

/** Inactive clients: Active / Inactive tabs, quick status change, projects of inactive clients inactive (derived) and left out of pickers. */
function ic_fx(): array {
  static $f = null;
  if ($f) { return $f; }
  $now = date('Y-m-d H:i:s');
  $mgr = make_user('manager', 'zzmgr@inact.test', ['name' => 'Zz Inact Manager']); $other = make_user('manager', 'zzother@inact.test', ['name' => 'Zz Inact Other']);
  $emp = make_user('employee', 'zzemp@inact.test', ['name' => 'Zz Inact Employee']);
  $cl = static function (string $company, string $status) use ($mgr, $now): int {
    $id = DB::insert('clients', ['name' => 'Contact '.$company, 'company' => $company, 'status' => $status, 'created_at' => $now, 'updated_at' => $now]);
    DB::insert('client_managers', ['client_id' => $id, 'user_id' => $mgr]);
    return $id; };
  $act = $cl('Inact Alpha', 'active'); $lead = $cl('Inact Lead Co', 'lead'); $off = $cl('Inact Sleeper', 'inactive');
  $pr = static function (string $name, ?int $client, string $status = 'active') use ($mgr, $emp, $now): int {
    $id = DB::insert('projects', ['name' => $name, 'client_id' => $client, 'status' => $status, 'manager_id' => $mgr, 'created_by' => $mgr, 'created_at' => $now, 'updated_at' => $now]);
    DB::insert('project_members', ['project_id' => $id, 'user_id' => $emp]);
    return $id; };
  $p1 = $pr('Inact Live Site', $act); $p2 = $pr('Inact Dormant App', $off); $p3 = $pr('Inact Internal', null); $p4 = $pr('Inact Old Done', $off, 'completed');
  $t1 = DB::insert('tasks', ['project_id' => $p1, 'title' => 'Inact live task', 'status' => 'todo', 'assignee_id' => $emp, 'due_date' => date('Y-m-d'), 'created_by' => $mgr, 'created_at' => $now]);
  $t2 = DB::insert('tasks', ['project_id' => $p2, 'title' => 'Inact dormant task', 'status' => 'todo', 'assignee_id' => $emp, 'due_date' => date('Y-m-d'), 'created_by' => $mgr, 'created_at' => $now]);
  ProjectAccess::flush(); ClientAccess::flush();
  return $f = compact('mgr', 'other', 'emp', 'act', 'lead', 'off', 'p1', 'p2', 'p3', 'p4', 't1', 't2'); }

function ic_user(int $id): array { return DB::first('SELECT u.*, r.slug AS role_slug FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=?', [$id]); }

/** Set a client's status through the quick status route (as the account manager). */
function ic_status(int $client, string $status, string $return = ''): Core\Response { return http('POST', '/clients/'.$client.'/status', ['status' => $status] + ($return !== '' ? ['return' => $return] : [])); }

return [
  'clients list: Active and Inactive tabs with counts, filters work inside the tab and keep it' => function () {
    $f = ic_fx(); act_as($f['mgr']);
    $b = (string) http('GET', '/clients')->body();
    assert_true((bool) preg_match('#aria-current="page">.{0,200}Active <span class="crm-count">2</span>#s', $b), 'Active tab current, 2 clients');
    assert_true(str_contains($b, 'Inactive <span class="crm-count">1</span>'), 'Inactive count');
    assert_true(str_contains($b, 'Inact Alpha') && str_contains($b, 'Inact Lead Co') && !str_contains($b, 'Inact Sleeper'), 'active tab lists lead and active, not inactive');
    assert_true(!str_contains($b, '<option value="inactive"'), 'no Inactive option in the status filter of the Active tab');
    $b = (string) http('GET', '/clients?tab=inactive')->body();
    assert_true(str_contains($b, 'Inact Sleeper') && !str_contains($b, 'Inact Alpha') && !str_contains($b, 'Inact Lead Co'), 'inactive tab');
    assert_true(str_contains($b, '<input type="hidden" name="tab" value="inactive">'), 'filter form keeps the tab');
    $b = (string) http('GET', '/clients?status=lead')->body();
    assert_true(str_contains($b, 'Inact Lead Co') && !str_contains($b, 'Inact Alpha'), 'status filter inside the Active tab');
    $b = (string) http('GET', '/clients?tab=inactive&status=lead&q=Sleeper&view=cards')->body();
    assert_true(str_contains($b, 'Inact Sleeper'), 'status of the other tab is ignored, search and cards work');
    $ids = [];
    for ($i = 0; $i < 21; $i++) { $ids[] = $id = DB::insert('clients', ['name' => 'Zz bulk '.$i, 'status' => 'inactive', 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]); DB::insert('client_managers', ['client_id' => $id, 'user_id' => $f['mgr']]); }
    ClientAccess::flush();
    try {
      $b = (string) http('GET', '/clients?tab=inactive')->body();
      assert_true(str_contains($b, 'Inactive <span class="crm-count">22</span>'), 'count follows');
      assert_true(str_contains($b, '?tab=inactive&amp;page=2'), 'pagination keeps the tab'); }
    finally { DB::query('DELETE FROM clients WHERE id IN '.DB::in($ids), $ids); ClientAccess::flush(); } },

  'quick status change: menu on list and profile, confirmation names the projects, audit, permission and scope' => function () {
    $f = ic_fx(); act_as($f['mgr']);
    $b = (string) http('GET', '/clients')->body();
    assert_true(str_contains($b, 'action="'.url('/clients/'.$f['act'].'/status').'" data-confirm="Mark this client inactive? 1 project becomes inactive too."'), 'list menu confirms with the project count');
    $b = (string) http('GET', '/clients/'.$f['act'])->body();
    assert_true(str_contains($b, 'class="dd crm-more crm-st"') && str_contains($b, 'data-confirm="Mark this client inactive? 1 project becomes inactive too."'), 'profile header menu');
    $r = ic_status($f['lead'], 'on_hold', '/clients?tab=active&q=Lead');
    assert_true(str_ends_with((string) ($r->headers()['Location'] ?? ''), '/clients?tab=active&q=Lead'), 'back to the list it came from');
    assert_eq('on_hold', DB::value('SELECT status FROM clients WHERE id=?', [$f['lead']]));
    $a = DB::first("SELECT * FROM audit_logs WHERE action='crm.client.status' AND entity_id=? ORDER BY id DESC LIMIT 1", [$f['lead']]);
    assert_eq(['before' => 'lead', 'after' => 'on_hold'], json_decode($a['meta'], true), 'audit before/after');
    $r = ic_status($f['lead'], 'lead', 'https://evil.test/x');
    assert_true(str_ends_with((string) ($r->headers()['Location'] ?? ''), '/clients/'.$f['lead']), 'foreign return path falls back to the profile');
    assert_true(in_array(ic_status($f['lead'], 'bogus')->status(), [302, 422], true), 'invalid status is a validation error');
    assert_eq('lead', DB::value('SELECT status FROM clients WHERE id=?', [$f['lead']]));
    act_as($f['emp']); assert_eq(403, ic_status($f['lead'], 'inactive')->status(), 'needs crm.clients.manage');
    act_as($f['other']); assert_eq(404, ic_status($f['lead'], 'inactive')->status(), 'client outside the manager scope');
    assert_eq('lead', DB::value('SELECT status FROM clients WHERE id=?', [$f['lead']]));
    act_as($f['mgr']);
    $b = (string) http('GET', '/clients/create')->body();
    assert_true(str_contains($b, 'value="inactive"') && (bool) preg_match('#value="lead" checked#', $b), 'new clients may be added inactive, default stays Lead'); },

  'projects: Active and Inactive tabs (derived from the client), banner on an inactive project, status untouched' => function () {
    $f = ic_fx(); act_as($f['mgr']);
    $card = static fn (string $b, int $id): bool => str_contains($b, 'class="pc-title"><a href="'.url('/projects/'.$id).'"') || str_contains($b, 'class="row-link pname" href="'.url('/projects/'.$id).'"');
    $b = (string) http('GET', '/projects')->body();
    assert_true($card($b, $f['p1']) && $card($b, $f['p3']) && !$card($b, $f['p2']), 'active tab');
    assert_true(str_contains($b, 'Active <span class="tab-n">2</span>') && str_contains($b, 'Inactive <span class="tab-n">2</span>'), 'tab counts');
    $b = (string) http('GET', '/projects?tab=inactive&view=table')->body();
    assert_true($card($b, $f['p2']) && $card($b, $f['p4']) && !$card($b, $f['p1']), 'inactive tab');
    $b = (string) http('GET', '/projects?tab=inactive&status=completed')->body();
    assert_true($card($b, $f['p4']) && !$card($b, $f['p2']), 'filters inside the tab');
    $b = (string) http('GET', '/projects/'.$f['p2'])->body();
    assert_true(str_contains($b, '<b>Inactive:</b> client') && str_contains($b, url('/clients/'.$f['off'])), 'banner links to the client');
    assert_true(str_contains((string) http('GET', '/projects/'.$f['p2'].'?tab=list')->body(), 'Inact dormant task'), 'tasks stay reachable on the project page');
    assert_true(!str_contains((string) http('GET', '/projects/'.$f['p1'])->body(), '<b>Inactive:</b>'), 'no banner on an active project');
    assert_eq('active', DB::value('SELECT status FROM projects WHERE id=?', [$f['p2']]), 'projects.status is never overwritten');
    assert_true(ProjectService::isActive($f['p1']) && ProjectService::isActive($f['p3']) && !ProjectService::isActive($f['p2'])); },

  'inactive projects are left out of pickers, desktop list, side card, widgets and task lists; new time is refused' => function () {
    $f = ic_fx(); act_as($f['emp']);
    $emp = ic_user($f['emp']); $mgr = ic_user($f['mgr']);
    $tp = TimeService::projects($emp);
    assert_true(isset($tp[$f['p1']]) && isset($tp[$f['p3']]) && !isset($tp[$f['p2']]), 'time pickers and desktop app list');
    assert_eq([], TimeService::tasks($emp, $f['p2']), 'desktop app tasks of an inactive project');
    assert_true((bool) TimeService::tasks($emp, $f['p1']));
    $side = View::partial('partials/side_work', ['u' => $emp]);
    assert_true(str_contains($side, 'Inact Live Site') && !str_contains($side, 'Inact Dormant App'), 'sidebar My projects card');
    $b = (string) http('GET', '/tasks')->body();
    assert_true(str_contains($b, 'Inact live task') && !str_contains($b, 'Inact dormant task'), 'My tasks');
    try { TimeService::start($emp, ['project_id' => $f['p2']]); throw new RuntimeException('started on an inactive project'); }
    catch (DomainException $e) { assert_true(str_contains($e->getMessage(), 'client is inactive'), $e->getMessage()); }
    assert_eq([$f['p2'], null], TimeService::checkTarget($emp, $f['p2'], null, $f['p2']), 'an edited entry keeps its project');
    assert_eq([$f['p1'], $f['t1']], TimeService::checkTarget($emp, $f['p1'], $f['t1']));
    act_as($f['mgr']);
    $dlg = TaskDialog::projects($mgr);
    assert_true(isset($dlg[$f['p1']]) && !isset($dlg[$f['p2']]), 'task dialog');
    assert_true(isset(TaskDialog::projects($mgr, $f['p2'])[$f['p2']]), 'kept on its own project page');
    $w = Widgets::projectProgress($mgr);
    assert_true(str_contains($w, 'Inact Live Site') && !str_contains($w, 'Inact Dormant App'), 'dashboard progress widget'); },

  'reactivating the client restores its projects everywhere' => function () {
    $f = ic_fx(); act_as($f['mgr']);
    $b = (string) http('GET', '/clients?tab=inactive')->body();
    assert_true(str_contains($b, 'action="'.url('/clients/'.$f['off'].'/status').'"><input'), 'no confirmation when reactivating');
    $r = ic_status($f['off'], 'active', '/clients?tab=inactive');
    assert_true(str_ends_with((string) ($r->headers()['Location'] ?? ''), '/clients?tab=inactive'));
    $a = DB::first("SELECT * FROM audit_logs WHERE action='crm.client.status' AND entity_id=? ORDER BY id DESC LIMIT 1", [$f['off']]);
    assert_eq(['before' => 'inactive', 'after' => 'active', 'projects' => 1], json_decode($a['meta'], true), 'audit names the projects that follow');
    assert_eq('Inact Sleeper is now Active. 1 project is active again.', $_SESSION['_flash_next']['toast']['msg'] ?? null, 'flash');
    ProjectAccess::flush();
    assert_true(isset(TimeService::projects(ic_user($f['emp']))[$f['p2']]), 'back in the pickers');
    $b = (string) http('GET', '/projects?q=Inact+Dormant')->body();
    assert_true(str_contains($b, 'class="pc-title"><a href="'.url('/projects/'.$f['p2']).'"') && str_contains($b, 'Inactive <span class="tab-n">0</span>'), 'back in the Active tab');
    assert_true(!str_contains((string) http('GET', '/projects/'.$f['p2'])->body(), '<b>Inactive:</b>'), 'banner gone');
    ic_status($f['off'], 'inactive');
    assert_true(!isset(TimeService::projects(ic_user($f['emp']))[$f['p2']]), 'inactive again'); },
];
