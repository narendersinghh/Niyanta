<?php
/** Planned meetings: New meeting from the Meetings, client and project pages, attendees and notifications, scoping, reschedule / cancel, notes on a planned meeting, auto-link of arriving transcripts, To sort quick-assign, feature switch. */
use Core\{DB, Features, Settings};
use Modules\Meetings\Services\{MeetingService, Parser, Schedule};

function mp_fx(): array {
  static $f = null;
  Features::set('feature:meetings', true);
  $_FILES = []; unset($_SERVER['HTTP_X_REQUESTED_WITH']);
  DB::query('DELETE FROM ai_models'); Settings::setMany(['ai.enabled' => '0', 'meetings.auto_create' => '1']);
  if ($f) { return $f; }
  $now = date('Y-m-d H:i:s');
  $mgr = make_user('manager', 'mira@mplan.test', ['name' => 'Mira Mplan']);
  $mate = make_user('manager', 'nils@mplan.test', ['name' => 'Nils Mplan']);
  $out = make_user('manager', 'otto@mplan.test', ['name' => 'Otto Mplan']);
  $emp = make_user('employee', 'ema@mplan.test', ['name' => 'Ema Mplan']);
  $c = DB::insert('clients', ['name' => 'Ada', 'company' => 'Arcadia Plan Co', 'email' => 'ada@arcadia.example', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
  $c2 = DB::insert('clients', ['name' => 'Bo', 'company' => 'Borealis Plan Ltd', 'email' => 'bo@borealis.example', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
  DB::insert('client_managers', ['client_id' => $c, 'user_id' => $mgr]); DB::insert('client_managers', ['client_id' => $c2, 'user_id' => $mate]);
  $ada = DB::insert('client_contacts', ['client_id' => $c, 'name' => 'Ada Contact', 'email' => 'ada.c@arcadia.example']);
  $bo = DB::insert('client_contacts', ['client_id' => $c2, 'name' => 'Bo Contact', 'email' => 'bo.c@borealis.example']);
  $pr = static function (string $name, ?int $client, int $manager) use ($now): int { $id = DB::insert('projects', ['name' => $name, 'client_id' => $client, 'status' => 'active', 'manager_id' => $manager, 'created_by' => $manager, 'created_at' => $now, 'updated_at' => $now]); DB::insert('project_members', ['project_id' => $id, 'user_id' => $manager]); return $id; };
  $orbit = $pr('Plan Orbit Site', $c, $mgr); $ops = $pr('Plan Internal Ops', null, $mgr); $bor = $pr('Plan Borealis App', $c2, $mate);
  \Modules\Projects\Services\ProjectAccess::flush(); \Modules\Crm\Services\ClientAccess::flush();
  return $f = compact('mgr', 'mate', 'out', 'emp', 'c', 'c2', 'ada', 'bo', 'orbit', 'ops', 'bor'); }

function mp_post(string $path, array $post): Core\Response { unset($_SESSION['_flash_next'], $_SERVER['HTTP_X_REQUESTED_WITH'], $_SERVER['HTTP_ACCEPT']); return http('POST', $path, $post); }
function mp_errors(): array { return $_SESSION['_flash_next']['errors'] ?? []; }
function mp_last(): array { return DB::first("SELECT * FROM client_activities WHERE type='meeting' ORDER BY id DESC LIMIT 1"); }
function mp_user(int $id): array { return DB::first('SELECT u.*, r.slug AS role_slug FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=?', [$id]); }
function mp_when(string $rel): string { return date('Y-m-d\TH:i', strtotime($rel)); }
function mp_notes(int $uid, string $like): int { return (int) DB::value('SELECT COUNT(*) FROM notifications WHERE user_id=? AND title LIKE ?', [$uid, $like]); }

return [
  'New meeting from the Meetings page: log entry on client + project, attendees, contacts, follow-up, invites, listed as upcoming' => function () {
    $f = mp_fx(); act_as($f['mgr']);
    $r = mp_post('/meetings/new', ['title' => 'Kick-off Arcadia', 'occurred_at' => mp_when('+2 days 10:00'), 'duration_min' => 45, 'client_id' => $f['c'], 'project_id' => $f['orbit'], 'users' => [$f['mate'], $f['emp'], $f['mgr']], 'contacts' => [$f['ada'], $f['bo']], 'body' => 'Scope and timeline', 'follow_up_at' => mp_when('+5 days 09:00'), 'return' => 'meetings']);
    assert_eq([], mp_errors());
    assert_eq(302, $r->status()); assert_true(str_contains((string) ($r->headers()['Location'] ?? ''), '/meetings?tab=upcoming'), 'back to the Meetings page');
    $a = mp_last();
    assert_eq('Kick-off Arcadia', $a['title']); assert_eq('scheduled', $a['meeting_status']); assert_eq($f['c'], (int) $a['client_id']); assert_eq($f['orbit'], (int) $a['project_id']);
    assert_eq(45 * 60, strtotime($a['ends_at']) - strtotime($a['occurred_at']), 'duration'); assert_true((bool) $a['follow_up_at'], 'follow-up saved'); assert_eq('Scope and timeline', $a['body']);
    $people = Schedule::attendees((int) $a['id']);
    assert_eq(3, count(array_filter($people, static fn ($p) => $p['kind'] === 'user')), 'team members');
    assert_eq(['Ada Contact'], array_column(array_filter($people, static fn ($p) => $p['kind'] === 'contact'), 'name'), 'only contacts of the chosen client');
    assert_eq(1, mp_notes($f['mate'], 'Meeting invite: Kick-off Arcadia')); assert_eq(1, mp_notes($f['emp'], 'Meeting invite: Kick-off Arcadia'));
    assert_eq(0, mp_notes($f['mgr'], 'Meeting invite:%'), 'organiser not notified');
    assert_true(str_contains((string) DB::value('SELECT url FROM notifications WHERE user_id=? ORDER BY id DESC LIMIT 1', [$f['emp']]), '/meetings/event/'.$a['id']));
    assert_true((bool) DB::value("SELECT 1 FROM audit_logs WHERE action='meetings.schedule.create'"));
    $page = (string) http('GET', '/meetings')->body();
    assert_true(str_contains($page, 'Kick-off Arcadia') && str_contains($page, 'New meeting'), 'default tab lists it and shows the button');
    assert_true(str_contains((string) http('GET', '/clients/'.$f['c'])->body(), 'Kick-off Arcadia'), 'client page upcoming card + journey'); },

  'New meeting from the client page (client fixed) and the project page (both fixed, internal projects too)' => function () {
    $f = mp_fx(); act_as($f['mgr']);
    $b = (string) http('GET', '/clients/'.$f['c'])->body();
    assert_true(str_contains($b, 'data-modal="mt-new"') && str_contains($b, 'id="mt-new"') && str_contains($b, '<input type="hidden" name="client_id" value="'.$f['c'].'"'), 'client page: button and dialog with the client fixed');
    $r = mp_post('/meetings/new', ['title' => 'Arcadia monthly review', 'occurred_at' => mp_when('+3 days 15:00'), 'client_id' => $f['c'], 'return' => 'client']);
    assert_true(str_ends_with((string) ($r->headers()['Location'] ?? ''), '/clients/'.$f['c'].'#overview'), 'back to the client');
    $a = mp_last(); assert_eq($f['c'], (int) $a['client_id']); assert_eq(null, $a['project_id'], 'non-project activity');
    $b = (string) http('GET', '/projects/'.$f['orbit'].'?tab=log')->body();
    assert_true(str_contains($b, 'id="mt-new"') && str_contains($b, '<input type="hidden" name="project_id" value="'.$f['orbit'].'"'), 'project page dialog with the project fixed');
    assert_true(str_contains($b, 'Upcoming meetings') && str_contains($b, 'Kick-off Arcadia') && !str_contains($b, 'Arcadia monthly review'), 'project log lists only its own upcoming meetings');
    $r = mp_post('/meetings/new', ['title' => 'Orbit sprint demo', 'occurred_at' => mp_when('+4 days 11:00'), 'client_id' => $f['c'], 'project_id' => $f['orbit'], 'return' => 'project', 'notes_later' => 1]);
    $a = mp_last();
    assert_true(str_ends_with((string) ($r->headers()['Location'] ?? ''), '/meetings/event/'.$a['id'].'#notes'), '"add notes next" opens the meeting page');
    assert_true(str_contains((string) http('GET', '/meetings/event/'.$a['id'])->body(), 'Add notes / transcript'), 'notes section shown');
    $r = mp_post('/meetings/new', ['title' => 'Ops weekly', 'occurred_at' => mp_when('+1 day 09:30'), 'project_id' => $f['ops'], 'return' => 'project']);
    assert_true(str_ends_with((string) ($r->headers()['Location'] ?? ''), '/projects/'.$f['ops'].'?tab=log'));
    $a = mp_last(); assert_eq(null, $a['client_id']); assert_eq($f['ops'], (int) $a['project_id']); },

  'scoping: only clients / projects the user may log on; attendees see the meeting, others get 404; employees cannot plan' => function () {
    $f = mp_fx(); act_as($f['mate']);
    $n = (int) DB::value("SELECT COUNT(*) FROM client_activities WHERE type='meeting'");
    mp_post('/meetings/new', ['title' => 'Sneaky', 'occurred_at' => mp_when('+1 day'), 'client_id' => $f['c']]);
    assert_true(isset(mp_errors()['client_id']), 'not his client');
    mp_post('/meetings/new', ['title' => 'Wrong pair', 'occurred_at' => mp_when('+1 day'), 'client_id' => $f['c2'], 'project_id' => $f['orbit']]);
    assert_true(isset(mp_errors()['project_id']), 'project of another client');
    mp_post('/meetings/new', ['title' => '', 'occurred_at' => mp_when('+1 day'), 'client_id' => $f['c2']]);
    assert_true(isset(mp_errors()['title']), 'title required');
    assert_eq($n, (int) DB::value("SELECT COUNT(*) FROM client_activities WHERE type='meeting'"), 'nothing created');
    $kick = (int) DB::value("SELECT id FROM client_activities WHERE title='Kick-off Arcadia'");
    $own = (int) DB::value("SELECT id FROM client_activities WHERE title='Arcadia monthly review'");
    $up = array_column(Schedule::upcoming(mp_user($f['mate'])), 'title');
    assert_true(in_array('Kick-off Arcadia', $up, true) && !in_array('Arcadia monthly review', $up, true), 'invited meeting listed, others of that client not');
    assert_eq(200, http('GET', '/meetings/event/'.$kick)->status(), 'attendee opens it');
    assert_eq(404, http('GET', '/meetings/event/'.$own)->status(), 'not visible');
    assert_eq(403, mp_post('/meetings/event/'.$kick, ['title' => 'Hijack', 'occurred_at' => mp_when('+2 days'), 'client_id' => $f['c']])->status(), 'attendee cannot edit');
    assert_eq(403, mp_post('/meetings/event/'.$kick.'/cancel', [])->status());
    assert_true(!str_contains((string) http('GET', '/clients/'.$f['c2'])->body(), 'Kick-off Arcadia'), 'not on another client page');
    act_as($f['out']);
    assert_eq(404, http('GET', '/meetings/event/'.$kick)->status(), 'outsider');
    act_as($f['emp']);
    assert_eq(200, http('GET', '/meetings/event/'.$kick)->status(), 'invited employee without meetings.use still opens it');
    assert_eq(403, mp_post('/meetings/new', ['title' => 'x', 'occurred_at' => mp_when('+1 day'), 'client_id' => $f['c']])->status(), 'employees cannot plan'); },

  'reschedule and cancel: attendees told, new attendees invited, cancelled meetings leave the upcoming list and can be restored' => function () {
    $f = mp_fx(); act_as($f['mgr']);
    $id = (int) DB::value("SELECT id FROM client_activities WHERE title='Kick-off Arcadia'");
    DB::query('DELETE FROM notifications WHERE user_id IN (?,?,?)', [$f['mate'], $f['emp'], $f['out']]);
    $at = mp_when('+6 days 14:00');
    mp_post('/meetings/event/'.$id, ['title' => 'Kick-off Arcadia', 'occurred_at' => $at, 'duration_min' => 60, 'client_id' => $f['c'], 'project_id' => $f['orbit'], 'users' => [$f['mate'], $f['emp'], $f['out']], 'contacts' => [$f['ada']], 'body' => 'Scope']);
    assert_eq([], mp_errors());
    $a = Schedule::find($id);
    assert_eq(date('Y-m-d H:i:00', strtotime($at)), $a['occurred_at']); assert_eq(3600, strtotime($a['ends_at']) - strtotime($a['occurred_at']));
    assert_eq(1, mp_notes($f['mate'], 'Meeting rescheduled: Kick-off Arcadia')); assert_eq(1, mp_notes($f['emp'], 'Meeting rescheduled:%'));
    assert_eq(1, mp_notes($f['out'], 'Meeting invite: Kick-off Arcadia'), 'new attendee invited'); assert_eq(0, mp_notes($f['out'], 'Meeting rescheduled:%'));
    mp_post('/meetings/event/'.$id, ['title' => 'Kick-off Arcadia', 'occurred_at' => $at, 'duration_min' => 60, 'client_id' => $f['c'], 'project_id' => $f['orbit'], 'users' => [$f['mate']], 'body' => 'Scope']);
    assert_eq(1, count(array_filter(Schedule::attendees($id), static fn ($p) => $p['kind'] === 'user')), 'attendees removed');
    assert_eq(1, mp_notes($f['mate'], 'Meeting rescheduled:%'), 'same time: no second notice');
    mp_post('/meetings/event/'.$id.'/cancel', ['note' => 'Client travelling']);
    assert_eq('cancelled', Schedule::find($id)['meeting_status']);
    assert_true((bool) DB::value("SELECT 1 FROM notifications WHERE user_id=? AND title='Meeting cancelled: Kick-off Arcadia' AND body LIKE '%Client travelling%'", [$f['mate']]));
    assert_true(!in_array($id, array_map('intval', array_column(Schedule::upcoming(mp_user($f['mgr'])), 'id')), true), 'cancelled not upcoming');
    assert_true(str_contains((string) http('GET', '/meetings/event/'.$id)->body(), 'Cancelled'));
    mp_post('/meetings/event/'.$id.'/cancel', []);
    assert_eq('scheduled', Schedule::find($id)['meeting_status'], 'restored');
    assert_eq(1, mp_notes($f['mate'], 'Meeting back on:%')); },

  'Add notes / transcript on a planned meeting reuses the pipeline; arriving transcripts link to the matching planned meeting' => function () {
    $f = mp_fx(); act_as($f['mgr']);
    mp_post('/meetings/new', ['title' => 'Arcadia design review', 'occurred_at' => mp_when('-2 hours'), 'duration_min' => 60, 'client_id' => $f['c'], 'project_id' => $f['orbit']]);
    $id = (int) mp_last()['id'];
    assert_true(in_array($id, array_map('intval', array_column(Schedule::needsNotes(mp_user($f['mgr'])), 'id')), true), 'waiting for notes');
    $r = mp_post('/meetings/event/'.$id.'/notes', ['mt_text' => "Mira: We agreed the new homepage layout.\nAda: Please send the revised mockups by Friday.\nMira: Will do, and we will share the timeline."]);
    $t = DB::first('SELECT * FROM meeting_transcripts WHERE activity_id=?', [$id]);
    assert_true((bool) $t, 'transcript stored'); assert_eq('linked', $t['status']); assert_eq('Arcadia design review', $t['title']); assert_eq($f['orbit'], (int) $t['project_id']);
    assert_true(str_ends_with((string) ($r->headers()['Location'] ?? ''), '/meetings/'.$t['id']));
    assert_eq(1, (int) DB::value("SELECT COUNT(*) FROM client_activities WHERE title='Arcadia design review'"), 'no second log');
    assert_true(!in_array($id, array_map('intval', array_column(Schedule::needsNotes(mp_user($f['mgr'])), 'id')), true), 'no longer waiting');
    assert_true(str_contains((string) http('GET', '/meetings/event/'.$id)->body(), 'Notes &amp; summary'));
    mp_post('/meetings/new', ['title' => 'Arcadia budget call', 'occurred_at' => mp_when('-1 hour'), 'client_id' => $f['c'], 'project_id' => $f['orbit']]);
    $plan = (int) mp_last()['id']; $before = (int) DB::value("SELECT COUNT(*) FROM client_activities WHERE type='meeting'");
    $doc = Parser::parse("Budget call with Arcadia.\nAda: The budget is approved for phase two.\nMira: Great, we start Monday.", 'notes.txt');
    $doc['title'] = 'Budget sync'; $doc['meeting_at'] = date('Y-m-d H:i:s', strtotime('-50 minutes'));
    $res = MeetingService::ingest($doc, ['source' => 'email', 'owner_id' => $f['mgr'], 'user' => mp_user($f['mgr']), 'client_id' => $f['c'], 'project_id' => $f['orbit'], 'confirmed' => true, 'auto' => true]);
    assert_eq($plan, (int) $res['activity_id'], 'linked to the planned meeting');
    assert_eq($before, (int) DB::value("SELECT COUNT(*) FROM client_activities WHERE type='meeting'"), 'no new log entry'); },

  'To sort quick-assign: one click onto a planned meeting or the suggested client / project, scoped' => function () {
    $f = mp_fx(); act_as($f['mgr']);
    mp_post('/meetings/new', ['title' => 'Arcadia retro', 'occurred_at' => mp_when('-3 hours'), 'client_id' => $f['c'], 'project_id' => $f['orbit']]);
    $plan = (int) mp_last()['id'];
    $mk = static fn (string $title, ?int $cid) => DB::insert('meeting_transcripts', ['owner_id' => $f['mgr'], 'source' => 'paste', 'title' => $title, 'meeting_at' => date('Y-m-d H:i:s', strtotime('-3 hours')), 'transcript' => 'Notes about '.$title, 'chars' => 20, 'client_id' => $cid, 'confidence' => $cid ? 'medium' : 'none', 'reasons' => '[]', 'status' => 'to_sort', 'summary_status' => 'skipped', 'participants' => '[]', 'created_at' => date('Y-m-d H:i:s')]);
    $t1 = $mk('Retro raw notes', null); $t2 = $mk('Arcadia catch-up', $f['c']);
    $page = (string) http('GET', '/meetings?tab=sort')->body();
    assert_true(str_contains($page, 'Quick assign') && str_contains($page, 'name="activity_id" value="'.$plan.'"'), 'planned meeting offered');
    assert_true(str_contains($page, 'Log on Arcadia Plan Co'), 'suggested client offered');
    mp_post('/meetings/'.$t1.'/assign', ['activity_id' => $plan, 'return' => 'list']);
    assert_eq([], mp_errors());
    $t = DB::find('meeting_transcripts', $t1);
    assert_eq($plan, (int) $t['activity_id']); assert_eq('linked', $t['status']); assert_eq($f['c'], (int) $t['client_id']);
    mp_post('/meetings/'.$t2.'/assign', ['activity_id' => $plan]);
    assert_true(isset(mp_errors()['client_id']), 'meeting already has notes');
    mp_post('/meetings/'.$t2.'/assign', ['client_id' => $f['c'], 'project_id' => '', 'return' => 'list']);
    assert_eq('linked', DB::value('SELECT status FROM meeting_transcripts WHERE id=?', [$t2]), 'one-click client log');
    act_as($f['mate']);
    mp_post('/meetings/new', ['title' => 'Borealis sync', 'occurred_at' => mp_when('-3 hours'), 'client_id' => $f['c2']]);
    $bor = (int) mp_last()['id'];
    act_as($f['mgr']);
    $t3 = $mk('Another raw note', null);
    assert_true(!str_contains((string) http('GET', '/meetings?tab=sort')->body(), 'name="activity_id" value="'.$bor.'"'), 'meetings of hidden clients never offered');
    mp_post('/meetings/'.$t3.'/assign', ['activity_id' => $bor]);
    assert_true(isset(mp_errors()['client_id']), 'cannot attach to a meeting she cannot see');
    assert_eq('to_sort', DB::value('SELECT status FROM meeting_transcripts WHERE id=?', [$t3])); },

  'feature switch off: no New meeting button, dialog or upcoming card, routes blocked' => function () {
    $f = mp_fx(); act_as($f['mgr']);
    Features::set('feature:meetings', false);
    try {
      assert_eq(403, mp_post('/meetings/new', ['title' => 'x', 'occurred_at' => mp_when('+1 day'), 'client_id' => $f['c']])->status());
      $id = (int) DB::value("SELECT id FROM client_activities WHERE title='Kick-off Arcadia'");
      assert_eq(403, http('GET', '/meetings/event/'.$id)->status());
      $b = (string) http('GET', '/clients/'.$f['c'])->body();
      assert_true(!str_contains($b, 'id="mt-new"') && !str_contains($b, 'Upcoming meetings'), 'client page clean');
      assert_true(!str_contains((string) http('GET', '/projects/'.$f['orbit'].'?tab=log')->body(), 'id="mt-new"'), 'project page clean'); }
    finally { Features::set('feature:meetings', true); } },
];
