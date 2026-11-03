<?php
use Core\{DB, Features, Modules, Settings};
use Modules\Todos\Services\Todos as T;

/** Fixture: HR, a manager with one direct report, an employee of another manager. */
function todo_fx(): array {
  static $f = null;
  unset($_SERVER['HTTP_ACCEPT']); /* other test files may leave a JSON Accept header behind */
  if ($f) { return $f; }
  $hr = make_user('hr', 'hr@todounit.test'); $mgr = make_user('manager', 'mgr@todounit.test'); $mgr2 = make_user('manager', 'mgr2@todounit.test');
  $emp = make_user('employee', 'emp@todounit.test', ['manager_id' => $mgr]);
  $other = make_user('employee', 'other@todounit.test', ['manager_id' => $mgr2]);
  return $f = compact('hr', 'mgr', 'mgr2', 'emp', 'other'); }

function todo_user(int $id): array { return DB::first('SELECT u.*, r.slug AS role_slug FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=?', [$id]); }

function todo_add(int $assignee, int $by, string $title, array $extra = []): int {
  return DB::insert('todos', $extra + ['title' => $title, 'priority' => 'medium', 'assignee_id' => $assignee, 'created_by' => $by, 'status' => 'open', 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]); }

function todo_series(int $assignee, int $by, array $s): int {
  return DB::insert('todo_series', $s + ['title' => 'Routine '.mt_rand(), 'priority' => 'medium', 'assignee_id' => $assignee, 'created_by' => $by, 'frequency' => 'daily', 'workdays_only' => 0, 'start_date' => '2026-01-01', 'active' => 1, 'created_at' => date('Y-m-d H:i:s')]); }

/** A project managed by $mgr with $members (project_members). */
function todo_project(int $mgr, array $members, string $name, array $extra = []): int {
  $id = DB::insert('projects', $extra + ['name' => $name, 'status' => 'active', 'manager_id' => $mgr, 'created_by' => $mgr, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
  foreach ($members as $m) { DB::insert('project_members', ['project_id' => $id, 'user_id' => $m]); }
  \Modules\Projects\Services\ProjectAccess::flush();
  return $id; }

/** Field names of a dialog / form in a page, in document order (hidden _token / _form / return left out). */
function todo_fields(string $body, string $id): array {
  if (!preg_match('#<dialog[^>]* id="'.preg_quote($id, '#').'".*?</dialog>#s', $body, $m)) { return []; }
  preg_match_all('#\bname="([^"]+)"#', $m[0], $n);
  return array_values(array_unique(array_diff($n[1], ['_token', '_csrf', '_form', 'return']))); }

/** Run a request as AJAX (JSON response). */
function todo_ajax(string $path, array $post = []): Core\Response {
  $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
  try { return http('POST', $path, $post); } finally { unset($_SERVER['HTTP_X_REQUESTED_WITH']); } }

return [
  'daily routine: every day, or workdays only skipping the company weekly off days' => function () {
    Settings::set('general.weekend_days', '6,7'); T::flush();
    $s = ['frequency' => 'daily', 'workdays_only' => 1, 'start_date' => '2026-11-01', 'end_date' => null];
    assert_true(T::occursOn($s, '2026-11-02'), 'Monday is a workday');
    assert_true(!T::occursOn($s, '2026-11-07') && !T::occursOn($s, '2026-11-08'), 'weekend skipped');
    assert_true(T::occursOn(['workdays_only' => 0] + $s, '2026-11-07'), 'every day includes Saturday');
    assert_true(!T::occursOn($s, '2026-10-30'), 'nothing before the start date');
    assert_true(!T::occursOn(['end_date' => '2026-11-03'] + $s, '2026-11-04'), 'nothing after the end date');
    assert_eq('Every weekday', T::repeatLabel($s));
    assert_eq('2026-11-09', T::nextOccurrence($s, '2026-11-07'), 'next after the weekend is Monday');
    Settings::set('general.weekend_days', '5'); T::flush();
    assert_true(!T::occursOn($s, '2026-11-06') && T::occursOn($s, '2026-11-07'), 'custom weekly off day (Friday)');
    assert_eq('Every working day', T::repeatLabel($s));
    Settings::set('general.weekend_days', '6,7'); T::flush(); },

  'weekly routine on chosen weekdays' => function () {
    $s = ['frequency' => 'weekly', 'weekdays' => '1,3,5', 'start_date' => '2026-11-01', 'end_date' => null];
    assert_eq([1, 3, 5], T::days('5,1,3,9,3'), 'parsed, sorted, invalid dropped');
    assert_true(T::occursOn($s, '2026-11-02') && T::occursOn($s, '2026-11-04') && T::occursOn($s, '2026-11-06'), 'Mon, Wed, Fri');
    assert_true(!T::occursOn($s, '2026-11-03') && !T::occursOn($s, '2026-11-08'), 'not Tue / Sun');
    assert_eq('Mon, Wed, Fri', T::repeatLabel($s));
    assert_eq('Every weekday', T::repeatLabel(['weekdays' => '1,2,3,4,5'] + $s));
    assert_eq('Weekly on Thu', T::repeatLabel(['weekdays' => '4'] + $s));
    assert_eq('2026-11-04', T::nextOccurrence($s, '2026-11-03')); },

  'monthly routine clamps to the last day of short months' => function () {
    $s = ['frequency' => 'monthly', 'month_day' => 31, 'start_date' => '2026-01-01', 'end_date' => null];
    assert_true(T::occursOn($s, '2026-04-30') && !T::occursOn($s, '2026-04-29'), '30-day month: the 30th');
    assert_true(T::occursOn($s, '2026-02-28'), 'February (non-leap): the 28th');
    assert_true(T::occursOn($s, '2028-02-29') && !T::occursOn($s, '2028-02-28'), 'leap February: the 29th');
    assert_true(T::occursOn($s, '2026-01-31') && !T::occursOn($s, '2026-01-30'), '31-day month: the 31st');
    $s30 = ['month_day' => 30] + $s;
    assert_true(T::occursOn($s30, '2026-02-28') && T::occursOn($s30, '2026-04-30') && T::occursOn($s30, '2026-05-30') && !T::occursOn($s30, '2026-05-31'), 'day 30');
    $s15 = ['month_day' => 15] + $s;
    assert_true(T::occursOn($s15, '2026-03-15') && !T::occursOn($s15, '2026-03-16'));
    assert_eq('Monthly on 15', T::repeatLabel($s15));
    assert_eq('2026-04-30', T::nextOccurrence($s, '2026-04-01')); },

  'generation is idempotent, catches up at most 7 days and skips paused routines' => function () {
    ['emp' => $emp, 'mgr' => $mgr] = todo_fx();
    $sid = todo_series($emp, $mgr, ['title' => 'Daily standup notes', 'start_date' => '2026-10-01', 'last_generated' => '2026-10-02']);
    $paused = todo_series($emp, $mgr, ['title' => 'Paused one', 'start_date' => '2026-10-01', 'active' => 0]);
    $ended = todo_series($emp, $mgr, ['title' => 'Ended one', 'start_date' => '2026-09-01', 'end_date' => '2026-09-30']);
    T::generateDue('2026-10-20');
    $dates = DB::column('SELECT occurrence FROM todos WHERE series_id=? ORDER BY occurrence', [$sid]);
    assert_eq(8, count($dates), 'today plus 7 days back');
    assert_eq('2026-10-13', $dates[0]);
    assert_eq('2026-10-20', (string) DB::value('SELECT last_generated FROM todo_series WHERE id=?', [$sid]));
    assert_eq('2026-10-20', (string) DB::value('SELECT due_date FROM todos WHERE series_id=? AND occurrence=?', [$sid, '2026-10-20']), 'due date = occurrence');
    T::generateDue('2026-10-20');
    assert_eq(0, T::generate(DB::find('todo_series', $sid), '2026-10-13', '2026-10-20'), 'unique key prevents duplicates');
    assert_eq(8, (int) DB::value('SELECT COUNT(*) FROM todos WHERE series_id=?', [$sid]), 'second run creates nothing');
    T::generateDue('2026-10-21');
    assert_eq(9, (int) DB::value('SELECT COUNT(*) FROM todos WHERE series_id=?', [$sid]), 'next day adds one');
    assert_eq(0, (int) DB::value('SELECT COUNT(*) FROM todos WHERE series_id IN (?,?)', [$paused, $ended]), 'paused / ended routines create nothing');
    DB::delete('todo_series', 'id IN (?,?,?)', [$sid, $paused, $ended]);
    DB::delete('todos', "title='Daily standup notes'"); },

  'creating tasks: one-time with notification, routine creates today right away, assignee must be allowed' => function () {
    ['emp' => $emp, 'mgr' => $mgr, 'other' => $other] = todo_fx();
    act_as($mgr);
    $n0 = (int) DB::value('SELECT COUNT(*) FROM notifications WHERE user_id=?', [$emp]);
    assert_eq(302, http('POST', '/todos', ['title' => 'Send the report', 'priority' => 'high', 'assignee_id' => $emp, 'repeat' => 'once', 'due_date' => date('Y-m-d')])->status());
    $t = DB::first("SELECT * FROM todos WHERE title='Send the report'");
    assert_true($t && (int) $t['assignee_id'] === $emp && (int) $t['created_by'] === $mgr && $t['series_id'] === null, 'one-time task stored');
    assert_eq($n0 + 1, (int) DB::value('SELECT COUNT(*) FROM notifications WHERE user_id=?', [$emp]), 'assignee notified');
    http('POST', '/todos', ['title' => 'Check inbox', 'priority' => 'low', 'assignee_id' => $emp, 'repeat' => 'daily', 'start_date' => date('Y-m-d')]);
    $sid = (int) DB::value("SELECT id FROM todo_series WHERE title='Check inbox'");
    assert_true($sid > 0, 'routine stored');
    assert_eq(1, (int) DB::value('SELECT COUNT(*) FROM todos WHERE series_id=? AND occurrence=?', [$sid, date('Y-m-d')]), 'today generated immediately');
    assert_eq($n0 + 2, (int) DB::value('SELECT COUNT(*) FROM notifications WHERE user_id=?', [$emp]), 'one notification for the routine');
    T::generateDue();
    assert_eq($n0 + 2, (int) DB::value('SELECT COUNT(*) FROM notifications WHERE user_id=?', [$emp]), 'no notification per occurrence');
    http('POST', '/todos', ['title' => 'Not my report', 'priority' => 'low', 'assignee_id' => $other, 'repeat' => 'once']);
    assert_eq(0, (int) DB::value("SELECT COUNT(*) FROM todos WHERE title='Not my report'"), 'manager cannot assign outside direct reports');
    http('POST', '/todos', ['title' => 'No days', 'priority' => 'low', 'assignee_id' => $mgr, 'repeat' => 'weekly', 'start_date' => date('Y-m-d')]);
    assert_eq(0, (int) DB::value("SELECT COUNT(*) FROM todo_series WHERE title='No days'"), 'weekly needs a weekday');
    http('POST', '/todos', ['title' => 'Bad range', 'priority' => 'low', 'repeat' => 'monthly', 'month_day' => 5, 'start_date' => '2026-05-10', 'end_date' => '2026-05-01']);
    assert_eq(0, (int) DB::value("SELECT COUNT(*) FROM todo_series WHERE title='Bad range'"), 'end before start rejected');
    act_as($emp);
    http('POST', '/todos', ['title' => 'Employee assigns up', 'priority' => 'low', 'assignee_id' => $mgr, 'repeat' => 'once']);
    assert_eq(0, (int) DB::value("SELECT COUNT(*) FROM todos WHERE title='Employee assigns up'"), 'employee can only create for self');
    http('POST', '/todos', ['title' => 'My own note', 'priority' => 'low', 'repeat' => 'once']);
    assert_eq($emp, (int) DB::value("SELECT assignee_id FROM todos WHERE title='My own note'"), 'defaults to self'); },

  'scoping: employees see only their tasks, managers only direct reports, HR everyone' => function () {
    ['emp' => $emp, 'mgr' => $mgr, 'mgr2' => $mgr2, 'other' => $other, 'hr' => $hr] = todo_fx();
    $mine = todo_add($emp, $mgr, 'Scoped emp task');
    $theirs = todo_add($other, $mgr2, 'Scoped other task');
    act_as($emp);
    $body = http('GET', '/todos')->body();
    assert_true(str_contains($body, 'Scoped emp task') && !str_contains($body, 'Scoped other task'), 'employee list scoped');
    assert_eq(404, http('POST', '/todos/'.$theirs.'/complete')->status(), 'cannot complete others');
    assert_eq('open', DB::value('SELECT status FROM todos WHERE id=?', [$theirs]));
    assert_eq(404, http('POST', '/todos/'.$theirs.'/delete')->status(), 'cannot delete others');
    assert_eq(403, http('POST', '/todos/'.$mine.'/delete')->status(), 'cannot delete a task the manager assigned');
    assert_true(!str_contains(http('GET', '/search?q=Scoped')->body(), 'Scoped other task'), 'search scoped');
    act_as($mgr);
    assert_true(T::canSee(todo_user($mgr), DB::find('todos', $mine)) && !T::canSee(todo_user($mgr), DB::find('todos', $theirs)), 'manager sees direct reports only');
    $body = http('GET', '/todos?view=assigned&who=team&status=all')->body();
    assert_true(str_contains($body, 'Scoped emp task') && !str_contains($body, 'Scoped other task'), 'team view limited to reports');
    assert_eq(404, http('POST', '/todos/'.$theirs.'/complete')->status(), 'manager cannot complete a non-report task');
    act_as($hr);
    assert_true(str_contains(http('GET', '/todos?view=assigned&who=team&status=all')->body(), 'Scoped other task'), 'HR sees everyone');
    assert_eq(302, http('POST', '/todos/'.$theirs.'/complete')->status());
    assert_eq('done', DB::value('SELECT status FROM todos WHERE id=?', [$theirs]));
    DB::delete('todos', 'id IN (?,?)', [$mine, $theirs]); },

  'completing notifies the assigner for one-time tasks only; AJAX returns the done row' => function () {
    ['emp' => $emp, 'mgr' => $mgr] = todo_fx();
    $one = todo_add($emp, $mgr, 'Assigned one-off');
    $sid = todo_series($emp, $mgr, ['title' => 'Routine thing']);
    $occ = todo_add($emp, $mgr, 'Routine thing', ['series_id' => $sid, 'occurrence' => date('Y-m-d'), 'due_date' => date('Y-m-d')]);
    $n0 = (int) DB::value('SELECT COUNT(*) FROM notifications WHERE user_id=?', [$mgr]);
    act_as($emp);
    $r = todo_ajax('/todos/'.$one.'/complete');
    $j = json_decode($r->body(), true);
    assert_true(!empty($j['ok']) && str_contains($j['html'], 'is-done') && str_contains($j['html'], '/reopen'), 'JSON with done row');
    assert_eq($n0 + 1, (int) DB::value('SELECT COUNT(*) FROM notifications WHERE user_id=?', [$mgr]), 'assigner notified');
    todo_ajax('/todos/'.$occ.'/complete');
    assert_eq('done', DB::value('SELECT status FROM todos WHERE id=?', [$occ]));
    assert_eq($n0 + 1, (int) DB::value('SELECT COUNT(*) FROM notifications WHERE user_id=?', [$mgr]), 'no notification for routine occurrences');
    todo_ajax('/todos/'.$one.'/reopen');
    assert_eq('open', DB::value('SELECT status FROM todos WHERE id=?', [$one]));
    assert_eq(null, DB::value('SELECT completed_at FROM todos WHERE id=?', [$one])); },

  'routines: edit, pause, delete keeps past occurrences unless asked' => function () {
    ['emp' => $emp, 'mgr' => $mgr, 'other' => $other] = todo_fx();
    act_as($mgr);
    $sid = todo_series($emp, $mgr, ['title' => 'Weekly sync', 'frequency' => 'weekly', 'weekdays' => '1']);
    $past = todo_add($emp, $mgr, 'Weekly sync', ['series_id' => $sid, 'occurrence' => '2026-01-05', 'due_date' => '2026-01-05', 'status' => 'done', 'completed_at' => '2026-01-05 10:00:00']);
    $open = todo_add($emp, $mgr, 'Weekly sync', ['series_id' => $sid, 'occurrence' => '2026-01-12', 'due_date' => '2026-01-12']);
    http('POST', '/todos/routines/'.$sid, ['title' => 'Weekly sync v2', 'priority' => 'high', 'assignee_id' => $emp, 'repeat' => 'weekly', 'weekdays' => ['1', '3'], 'start_date' => '2026-01-01']);
    $s = DB::find('todo_series', $sid);
    assert_eq('Weekly sync v2', $s['title']); assert_eq('1,3', $s['weekdays']);
    assert_eq('Weekly sync', DB::value('SELECT title FROM todos WHERE id=?', [$past]), 'past occurrences untouched');
    http('POST', '/todos/routines/'.$sid.'/toggle');
    assert_eq(0, (int) DB::value('SELECT active FROM todo_series WHERE id=?', [$sid]), 'paused');
    assert_eq('paused', T::state(DB::find('todo_series', $sid)));
    assert_eq('ended', T::state(['end_date' => '2020-01-01', 'active' => 1]));
    act_as($other);
    assert_eq(404, http('POST', '/todos/routines/'.$sid.'/delete')->status(), 'others cannot delete the routine');
    act_as($emp);
    assert_eq(404, http('POST', '/todos/routines/'.$sid.'/toggle')->status(), 'assignee cannot manage a routine set by the manager');
    act_as($mgr);
    http('POST', '/todos/routines/'.$sid.'/delete');
    assert_eq(null, DB::find('todo_series', $sid));
    assert_eq(null, DB::value('SELECT series_id FROM todos WHERE id=?', [$past]), 'kept, unlinked');
    assert_true((bool) DB::find('todos', $open), 'open occurrence kept by default');
    $sid2 = todo_series($emp, $mgr, ['title' => 'Purge me']);
    $o2 = todo_add($emp, $mgr, 'Purge me', ['series_id' => $sid2, 'occurrence' => '2026-01-12']);
    $d2 = todo_add($emp, $mgr, 'Purge me', ['series_id' => $sid2, 'occurrence' => '2026-01-11', 'status' => 'done']);
    http('POST', '/todos/routines/'.$sid2.'/delete', ['purge' => '1']);
    assert_true(!DB::find('todos', $o2) && DB::find('todos', $d2), 'open occurrences deleted, done kept'); },

  'permissions, navigation and feature switch' => function () {
    ['emp' => $emp, 'mgr' => $mgr, 'hr' => $hr] = todo_fx();
    act_as($emp);
    assert_true(\Core\Gate::allows('todos.use') && !\Core\Gate::allows('todos.assign') && !\Core\Gate::allows('todos.assign_all'));
    $body = http('GET', '/todos?view=assigned')->body();
    assert_true(!str_contains($body, 'Assigned by me'), 'no assigned tab for employees');
    assert_eq([$emp], array_keys(T::assignable(todo_user($emp))), 'employee assigns to self only');
    $mt = static fn () => array_values(array_filter(array_merge(...array_values(Modules::nav())), static fn ($n) => $n['label'] === 'My Tasks'));
    $n = $mt(); assert_eq(1, count($n), 'one My Tasks entry'); assert_eq('/todos', $n[0]['url']);
    act_as($mgr);
    assert_eq([$mgr, $emp], array_keys(T::assignable(todo_user($mgr))), 'manager: self + direct report');
    assert_true(str_contains(http('GET', '/todos?view=assigned')->body(), 'Assigned by me'));
    act_as($hr);
    assert_true(count(T::assignable(todo_user($hr))) >= 5, 'HR assigns to anyone');
    act_as($GLOBALS['ADMIN_ID']);
    $n = $mt(); assert_eq(1, count($n), 'super admin: one My Tasks'); assert_eq('/todos', $n[0]['url']);
    foreach (['/todos', '/todos?view=assigned', '/todos?view=routines', '/'] as $p) { assert_eq(200, http('GET', $p)->status(), $p); }
    Features::set('feature:todos', false); \Core\Gate::flush();
    try {
      act_as($emp);
      assert_eq(403, http('GET', '/todos')->status(), 'blocked while off');
      $n = $mt(); assert_eq(1, count($n), 'project My Tasks back'); assert_eq('/tasks', $n[0]['url']);
      assert_true(!isset(\Core\Cron::jobs()['todos.generate']), 'cron job off');
      act_as($GLOBALS['ADMIN_ID']);
      $n = $mt(); assert_eq(1, count($n), 'super admin keeps one entry'); assert_eq('/tasks', $n[0]['url']); }
    finally { Features::set('feature:todos', true); \Core\Gate::flush(); }
    assert_true(isset(\Core\Cron::jobs()['todos.generate']), 'cron job registered'); },

  'new task dialog: a project_id posted to /todos becomes a project task with the project checks, no project stays a to-do' => function () {
    ['emp' => $emp, 'mgr' => $mgr, 'mgr2' => $mgr2, 'other' => $other] = todo_fx();
    $pid = todo_project($mgr, [$emp], 'Dialog project');
    $ms = DB::insert('milestones', ['project_id' => $pid, 'title' => 'Phase 1']);
    $foreign = todo_project($mgr2, [$other], 'Foreign project');
    act_as($mgr);
    $body = http('GET', '/todos')->body();
    assert_true(str_contains($body, 'id="task-new"') && str_contains($body, 'data-task-project') && str_contains($body, '>Dialog project<') && !str_contains($body, 'Foreign project'), 'one dialog, project select limited to projects the manager may add tasks to');
    assert_true(str_contains($body, 'value="weekly"') && str_contains($body, 'data-repeat="once"'), 'repeat options with the js / no :has() hook');
    $n0 = (int) DB::value('SELECT COUNT(*) FROM notifications WHERE user_id=?', [$emp]);
    $r = http('POST', '/todos', ['_form' => 'task-new', 'project_id' => $pid, 'title' => 'Dialog project task', 'status' => 'in_progress', 'priority' => 'urgent', 'assignee_id' => $emp, 'milestone_id' => $ms, 'estimated_hours' => '2.5', 'billable' => '1', 'return' => '/todos?view=assigned']);
    assert_eq(302, $r->status());
    assert_true(str_ends_with($r->headers()['Location'] ?? '', '/todos?view=assigned'), 'back to where the dialog was opened');
    $t = DB::first("SELECT * FROM tasks WHERE title='Dialog project task'");
    assert_true($t && (int) $t['project_id'] === $pid && (int) $t['assignee_id'] === $emp && (int) $t['created_by'] === $mgr && $t['status'] === 'in_progress' && (int) $t['milestone_id'] === $ms && (float) $t['estimated_hours'] === 2.5, 'project task stored with project fields');
    assert_eq(0, (int) DB::value("SELECT COUNT(*) FROM todos WHERE title='Dialog project task'"), 'no to-do created');
    assert_eq($n0 + 1, (int) DB::value('SELECT COUNT(*) FROM notifications WHERE user_id=?', [$emp]), 'assignee notified');
    assert_true((bool) DB::value("SELECT 1 FROM audit_logs WHERE action='projects.task.create' AND entity_id=?", [(int) $t['id']]), 'audited');
    http('POST', '/todos', ['project_id' => $pid, 'title' => 'Dialog outsider', 'status' => 'todo', 'priority' => 'medium', 'assignee_id' => $other]);
    assert_eq(0, (int) DB::value("SELECT COUNT(*) FROM tasks WHERE title='Dialog outsider'") + (int) DB::value("SELECT COUNT(*) FROM todos WHERE title='Dialog outsider'"), 'assignee must be a project member');
    assert_eq(404, http('POST', '/todos', ['project_id' => $foreign, 'title' => 'Dialog foreign', 'status' => 'todo', 'priority' => 'medium'])->status(), 'project the manager cannot see');
    assert_eq(0, (int) DB::value("SELECT COUNT(*) FROM tasks WHERE title='Dialog foreign'"));
    $j = json_decode(http('GET', '/projects/'.$pid.'/task-form')->body(), true);
    assert_eq([[$emp, DB::value('SELECT name FROM users WHERE id=?', [$emp])], [$mgr, DB::value('SELECT name FROM users WHERE id=?', [$mgr])]], array_values(array_filter($j['team'], static fn ($x) => in_array($x[0], [$emp, $mgr], true))), 'team for the assign list');
    assert_eq([[$ms, 'Phase 1']], $j['milestones']); assert_true($j['billable'] === true);
    assert_eq(404, http('GET', '/projects/'.$foreign.'/task-form')->status(), 'no team list of other projects');
    $r = todo_ajax('/projects/'.$pid.'/tasks', ['title' => 'Board quick add', 'status' => 'review', 'priority' => 'medium']);
    assert_true(str_contains((string) (json_decode($r->body(), true)['html'] ?? ''), 'Board quick add'), 'board quick add still returns the card');
    $body = http('GET', '/projects/'.$pid.'?tab=board')->body();
    assert_true(str_contains($body, 'id="task-new"') && str_contains($body, 'data-mode="project"') && str_contains($body, 'action="'.url('/todos').'" data-task-form') && str_contains($body, 'value="'.$pid.'" selected'), 'project page: same dialog with the project preselected, posted to /todos');
    assert_true(!str_contains($body, 'id="tn-title"'), 'old project dialog gone');
    $r = http('POST', '/todos', ['title' => 'Dialog plain todo', 'priority' => 'low', 'assignee_id' => $emp, 'repeat' => 'once', 'project_id' => '', 'return' => '/projects/'.$pid.'?tab=board']);
    assert_true(str_ends_with($r->headers()['Location'] ?? '', '/projects/'.$pid.'?tab=board'), 'no project: to-do, back to the project page');
    assert_eq($emp, (int) DB::value("SELECT assignee_id FROM todos WHERE title='Dialog plain todo'"));
    assert_eq(0, (int) DB::value("SELECT COUNT(*) FROM tasks WHERE title='Dialog plain todo'"));
    Features::set('feature:todos', false); \Core\Gate::flush();
    try {
      $body = http('GET', '/projects/'.$pid.'?tab=board')->body();
      assert_true(str_contains($body, 'id="task-new"') && !str_contains($body, 'No project') && !str_contains($body, 'name="repeat"') && str_contains($body, 'projects/'.$pid.'/tasks" data-task-form'), 'Tasks & routines off: project-only dialog posted to the project'); }
    finally { Features::set('feature:todos', true); \Core\Gate::flush(); }
    Features::set('feature:projects', false); \Core\Gate::flush();
    try {
      $body = http('GET', '/todos')->body();
      assert_true(str_contains($body, 'id="task-new"') && !str_contains($body, 'data-task-project'), 'Projects off: no project select');
      assert_eq(404, http('POST', '/todos', ['project_id' => $pid, 'title' => 'Dialog projects off', 'status' => 'todo', 'priority' => 'medium'])->status(), 'no project tasks while Projects is off'); }
    finally { Features::set('feature:projects', true); \Core\Gate::flush(); }
    act_as($emp);
    assert_eq(404, http('POST', '/todos', ['project_id' => $pid, 'title' => 'Employee project task', 'status' => 'todo', 'priority' => 'medium'])->status(), 'employees cannot add project tasks through /todos');
    assert_eq(403, http('GET', '/projects/'.$pid.'/task-form')->status());
    $body = http('GET', '/todos')->body();
    assert_true(str_contains($body, 'id="task-new"') && !str_contains($body, 'data-task-project'), 'employee: dialog without the project select');
    assert_true(str_contains(http('GET', '/tasks')->body(), 'id="task-new"'), '/tasks has the same dialog'); },

  'one task form: the same fields in the same order on /todos, /tasks, a project page and in the edit dialogs' => function () {
    ['emp' => $emp, 'mgr' => $mgr] = todo_fx();
    $pid = todo_project($mgr, [$emp], 'Same form project');
    act_as($mgr);
    $want = ['title', 'description', 'project_id', 'assignee_id', 'priority', 'status', 'start_date', 'due_date', 'estimated_hours', 'milestone_id', 'billable', 'repeat', 'workdays_only', 'weekdays[]', 'month_day', 'end_date'];
    $todos = http('GET', '/todos')->body(); $tasks = http('GET', '/tasks')->body(); $proj = http('GET', '/projects/'.$pid.'?tab=board')->body();
    assert_eq($want, todo_fields($todos, 'task-new'), '/todos');
    assert_eq($want, todo_fields($tasks, 'task-new'), '/tasks');
    assert_eq($want, todo_fields($proj, 'task-new'), 'project page');
    preg_match('#<dialog[^>]* id="task-new".*?</dialog>#s', $todos, $a); preg_match('#<dialog[^>]* id="task-new".*?</dialog>#s', $proj, $b);
    assert_true(str_contains($a[0], 'data-needs-project disabled') && str_contains($a[0], 'Choose a project first'), 'no project: milestone / billable visible but disabled with a hint');
    assert_true(!str_contains($b[0], 'data-needs-project disabled') && str_contains($b[0], 'value="'.$pid.'" selected'), 'project page: enabled, project preselected');
    foreach (['To do', 'In progress', 'In review', 'Done', 'Urgent', 'Estimate (hours)', 'Billable to client', 'Weekdays only'] as $l) { assert_true(str_contains($a[0], $l) && str_contains($b[0], $l), "label $l on both"); }
    $tid = todo_add($mgr, $mgr, 'Same form todo');
    $sid = todo_series($emp, $mgr, ['title' => 'Same form routine']);
    $pt = DB::insert('tasks', ['project_id' => $pid, 'title' => 'Same form ptask', 'status' => 'todo', 'priority' => 'medium', 'created_by' => $mgr, 'created_at' => date('Y-m-d H:i:s')]);
    $common = array_slice($want, 0, 11);
    $common = array_values(array_diff($common, ['project_id']));
    assert_eq($common, todo_fields(http('GET', '/todos?edit='.$tid)->body(), 'todo-edit'), 'to-do edit (project shown read-only)');
    assert_eq(array_merge($common, ['repeat', 'workdays_only', 'weekdays[]', 'month_day', 'end_date']), todo_fields(http('GET', '/todos?view=routines&edit_routine='.$sid)->body(), 'routine-edit'), 'routine edit');
    assert_eq($common, todo_fields(http('GET', '/tasks/'.$pt)->body(), 'task-edit'), 'project task edit');
    DB::delete('todos', 'id=?', [$tid]); DB::delete('todo_series', 'id=?', [$sid]); },

  'to-do from the task form: start date, estimate and status saved; Done completes it; edit keeps the open/done logic' => function () {
    ['emp' => $emp, 'mgr' => $mgr] = todo_fx();
    act_as($mgr);
    $r = http('POST', '/todos', ['_form' => 'task-new', 'title' => 'Form fields todo', 'priority' => 'urgent', 'status' => 'in_progress', 'assignee_id' => $emp, 'start_date' => '2026-11-10', 'due_date' => '2026-11-12', 'estimated_hours' => '3.5', 'milestone_id' => '99', 'billable' => '1', 'repeat' => 'once', 'project_id' => '']);
    assert_eq(302, $r->status());
    $t = DB::first("SELECT * FROM todos WHERE title='Form fields todo'");
    assert_true($t && $t['status'] === 'open' && $t['stage'] === 'in_progress' && $t['start_date'] === '2026-11-10' && $t['due_date'] === '2026-11-12' && (float) $t['estimate_hours'] === 3.5 && $t['priority'] === 'urgent', 'start, estimate, stage, urgent priority stored');
    $body = http('GET', '/todos?view=assigned')->body();
    assert_true(str_contains($body, 'In progress') && str_contains($body, '3.5h'), 'stage and estimate shown in the row');
    assert_true(str_contains(http('GET', '/todos?view=assigned&edit='.$t['id'])->body(), 'value="2026-11-10"'), 'edit dialog shows the start date');
    http('POST', '/todos', ['title' => 'Form done todo', 'priority' => 'low', 'status' => 'done', 'repeat' => 'once']);
    $d = DB::first("SELECT * FROM todos WHERE title='Form done todo'");
    assert_true($d['status'] === 'done' && $d['completed_at'] !== null, 'Done creates the to-do as done');
    http('POST', '/todos', ['title' => 'Form bad range', 'priority' => 'low', 'repeat' => 'once', 'start_date' => '2026-11-10', 'due_date' => '2026-11-01']);
    assert_eq(0, (int) DB::value("SELECT COUNT(*) FROM todos WHERE title='Form bad range'"), 'due before start rejected');
    http('POST', '/todos/'.$t['id'], ['title' => 'Form fields todo', 'priority' => 'high', 'status' => 'done', 'assignee_id' => $emp, 'start_date' => '', 'due_date' => '2026-11-12', 'estimated_hours' => '2']);
    $t2 = DB::find('todos', (int) $t['id']);
    assert_true($t2['status'] === 'done' && $t2['completed_at'] && (float) $t2['estimate_hours'] === 2.0 && $t2['start_date'] === null, 'edit to Done completes it');
    http('POST', '/todos/'.$t['id'], ['title' => 'Form fields todo', 'priority' => 'high', 'status' => 'review', 'assignee_id' => $emp]);
    $t3 = DB::find('todos', (int) $t['id']);
    assert_true($t3['status'] === 'open' && $t3['stage'] === 'review' && $t3['completed_at'] === null, 'edit back to In review reopens it');
    http('POST', '/todos', ['title' => 'Form repeat done', 'priority' => 'low', 'status' => 'done', 'repeat' => 'daily']);
    assert_eq(0, (int) DB::value("SELECT COUNT(*) FROM todo_series WHERE title='Form repeat done'"), 'a repeating task cannot start as Done');
    http('POST', '/todos', ['title' => 'Form routine est', 'priority' => 'low', 'status' => 'review', 'repeat' => 'daily', 'start_date' => date('Y-m-d'), 'estimated_hours' => '1.25', 'due_date' => '2026-12-31']);
    $s = DB::first("SELECT * FROM todo_series WHERE title='Form routine est'");
    assert_true($s && $s['status'] === 'review' && (float) $s['estimate_hours'] === 1.25 && $s['project_id'] === null && (int) $s['billable'] === 0, 'routine keeps status and estimate');
    assert_eq(date('Y-m-d'), (string) DB::value('SELECT due_date FROM todos WHERE series_id=?', [(int) $s['id']]), 'repeat: due date = occurrence, the due date field is ignored');
    DB::delete('todo_series', 'id=?', [(int) $s['id']]); },

  'repeating project task: a project routine creates project tasks once per day, only while allowed' => function () {
    ['emp' => $emp, 'mgr' => $mgr, 'other' => $other] = todo_fx();
    $pid = todo_project($mgr, [$emp], 'Routine project', ['billing_type' => 'hourly']);
    $ms = DB::insert('milestones', ['project_id' => $pid, 'title' => 'Sprint']);
    $today = date('Y-m-d');
    act_as($mgr);
    $n0 = (int) DB::value('SELECT COUNT(*) FROM notifications WHERE user_id=?', [$emp]);
    $r = http('POST', '/todos', ['_form' => 'task-new', 'project_id' => $pid, 'title' => 'Daily project check', 'description' => 'Look at the board', 'priority' => 'high', 'status' => 'todo', 'assignee_id' => $emp, 'milestone_id' => $ms, 'billable' => '1', 'estimated_hours' => '1.5', 'repeat' => 'daily', 'start_date' => $today, 'return' => '/projects/'.$pid.'?tab=board']);
    assert_eq(302, $r->status());
    assert_true(str_ends_with($r->headers()['Location'] ?? '', '/projects/'.$pid.'?tab=board'), 'back to the project page');
    $s = DB::first("SELECT * FROM todo_series WHERE title='Daily project check'");
    assert_true($s && (int) $s['project_id'] === $pid && (int) $s['milestone_id'] === $ms && (int) $s['billable'] === 1 && (float) $s['estimate_hours'] === 1.5 && (int) $s['assignee_id'] === $emp, 'project routine stored');
    $t = DB::first('SELECT * FROM tasks WHERE project_id=? AND title=?', [$pid, 'Daily project check']);
    assert_true($t && $t['status'] === 'todo' && $t['due_date'] === $today && $t['start_date'] === $today && (int) $t['assignee_id'] === $emp && (int) $t['milestone_id'] === $ms && (int) $t['billable'] === 1 && (float) $t['estimated_hours'] === 1.5 && $t['priority'] === 'high' && $t['description'] === 'Look at the board' && (int) $t['created_by'] === $mgr, 'today\'s project task created right away');
    assert_eq((int) $t['id'], (int) DB::value('SELECT task_id FROM todo_series_tasks WHERE series_id=? AND occurrence=?', [(int) $s['id'], $today]), 'linked to the routine');
    assert_eq(0, (int) DB::value("SELECT COUNT(*) FROM todos WHERE title='Daily project check'"), 'no to-do rows');
    assert_eq($n0 + 1, (int) DB::value('SELECT COUNT(*) FROM notifications WHERE user_id=?', [$emp]), 'assignee notified once');
    assert_true((bool) DB::value("SELECT 1 FROM audit_logs WHERE action='todos.routine.create' AND entity_id=?", [(int) $s['id']]), 'audited');
    $body = http('GET', '/todos?view=routines')->body();
    assert_true(str_contains($body, 'Daily project check') && str_contains($body, '>Routine project<'), 'Routines tab shows the project');
    $ed = http('GET', '/todos?view=routines&edit_routine='.$s['id'])->body();
    assert_true(str_contains($ed, 'id="routine-edit"') && str_contains($ed, 'Routine project') && str_contains($ed, '>Sprint<'), 'routine edit with the project team and milestones');
    assert_true(str_contains(http('GET', '/tasks/'.$t['id'])->body(), 'Part of a routine: Every day'), 'project task edit shows the routine');
    $sid = todo_series($emp, $mgr, ['title' => 'Catch-up project routine', 'project_id' => $pid, 'start_date' => '2026-10-01', 'billable' => 0]);
    T::generateDue('2026-10-10');
    $cnt = static fn () => (int) DB::value('SELECT COUNT(*) FROM tasks WHERE project_id=? AND title=?', [$pid, 'Catch-up project routine']);
    assert_eq(8, $cnt(), 'catch-up: today plus 7 days back');
    DB::update('todo_series', ['last_generated' => null], 'id=?', [$sid]);
    T::generateDue('2026-10-10');
    assert_eq(0, T::generate(DB::find('todo_series', $sid), '2026-10-03', '2026-10-10'), 'second run creates nothing');
    assert_eq(8, $cnt(), 'idempotent per series and date');
    DB::delete('tasks', 'project_id=? AND title=? AND due_date=?', [$pid, 'Catch-up project routine', '2026-10-05']);
    assert_eq(0, T::generate(DB::find('todo_series', $sid), '2026-10-05', '2026-10-05'), 'a deleted occurrence is not generated again');
    T::generateDue('2026-10-11');
    assert_eq(8, $cnt(), 'next day adds one');
    DB::update('projects', ['status' => 'completed'], 'id=?', [$pid]);
    T::generateDue('2026-10-12');
    assert_eq(8, $cnt(), 'skipped while the project is completed');
    DB::update('projects', ['status' => 'active'], 'id=?', [$pid]);
    DB::delete('project_members', 'project_id=? AND user_id=?', [$pid, $emp]);
    T::generateDue('2026-10-13');
    assert_eq(8, $cnt(), 'skipped when the assignee left the team');
    assert_true(str_contains((string) T::projectBlock(DB::find('todo_series', $sid)), 'no longer on the project team'));
    DB::insert('project_members', ['project_id' => $pid, 'user_id' => $emp]);
    $own = todo_series($emp, $emp, ['title' => 'Employee project routine', 'project_id' => $pid, 'start_date' => '2026-10-01']);
    T::generateDue('2026-10-14');
    assert_eq(9, $cnt(), 'allowed again');
    assert_eq(0, (int) DB::value("SELECT COUNT(*) FROM tasks WHERE title='Employee project routine'"), 'creator without projects.tasks.manage: nothing generated');
    http('POST', '/todos', ['project_id' => $pid, 'title' => 'Outsider routine', 'priority' => 'low', 'status' => 'todo', 'assignee_id' => $other, 'repeat' => 'daily']);
    assert_eq(0, (int) DB::value("SELECT COUNT(*) FROM todo_series WHERE title='Outsider routine'"), 'assignee must be on the project team');
    http('POST', '/todos/routines/'.$s['id'], ['title' => 'Daily project check v2', 'priority' => 'urgent', 'status' => 'in_progress', 'assignee_id' => '', 'repeat' => 'weekly', 'weekdays' => ['1'], 'start_date' => $today, 'milestone_id' => '', 'billable' => '0']);
    $s2 = DB::find('todo_series', (int) $s['id']);
    assert_true($s2['title'] === 'Daily project check v2' && $s2['assignee_id'] === null && $s2['frequency'] === 'weekly' && $s2['milestone_id'] === null && (int) $s2['billable'] === 0 && (int) $s2['project_id'] === $pid, 'project routine edited (unassigned allowed)');
    act_as($emp);
    assert_eq(404, http('POST', '/todos', ['project_id' => $pid, 'title' => 'Emp routine post', 'priority' => 'low', 'status' => 'todo', 'repeat' => 'daily'])->status(), 'employees cannot create project routines');
    act_as($mgr);
    http('POST', '/todos/routines/'.$sid.'/delete', ['purge' => '1']);
    assert_eq(0, $cnt(), 'delete with purge removes the open (To do) project tasks');
    DB::delete('projects', 'id=?', [$pid]);
    assert_eq(null, DB::find('todo_series', (int) $s['id']), 'project routines go with the project');
    DB::delete('todo_series', 'id=?', [$own]); },

  'assigned by me: one-time, routine and project tasks given to others, never self-assigned; manager, HR and super admin' => function () {
    ['emp' => $emp, 'mgr' => $mgr, 'hr' => $hr, 'other' => $other] = todo_fx();
    $pid = todo_project($mgr, [$emp], 'Assigned project');
    act_as($mgr);
    http('POST', '/todos', ['title' => 'ABM one-off', 'priority' => 'medium', 'assignee_id' => $emp, 'repeat' => 'once']);
    http('POST', '/todos', ['title' => 'ABM for myself', 'priority' => 'medium', 'assignee_id' => $mgr, 'repeat' => 'once']);
    http('POST', '/todos', ['title' => 'ABM routine', 'priority' => 'medium', 'assignee_id' => $emp, 'repeat' => 'daily', 'start_date' => date('Y-m-d')]);
    http('POST', '/projects/'.$pid.'/tasks', ['title' => 'ABM project task', 'status' => 'todo', 'priority' => 'high', 'assignee_id' => $emp]);
    http('POST', '/projects/'.$pid.'/tasks', ['title' => 'ABM project self', 'status' => 'todo', 'priority' => 'high', 'assignee_id' => $mgr]);
    $body = http('GET', '/todos?view=assigned')->body();
    foreach (['ABM one-off', 'ABM routine', 'ABM project task'] as $t) { assert_true(str_contains($body, $t), "manager sees $t"); }
    assert_true(!str_contains($body, 'ABM for myself') && !str_contains($body, 'ABM project self'), 'self-assigned tasks stay in My tasks');
    assert_true(str_contains($body, 'Tasks you assign to yourself are in'), 'hint about self-assigned tasks');
    assert_true(str_contains(http('GET', '/todos')->body(), 'ABM for myself'), 'self-assigned in My tasks');
    $tid = (int) DB::value("SELECT id FROM tasks WHERE title='ABM project task'");
    DB::update('tasks', ['status' => 'done', 'completed_at' => date('Y-m-d H:i:s')], 'id=?', [$tid]);
    assert_true(!str_contains(http('GET', '/todos?view=assigned')->body(), 'ABM project task'), 'done project task leaves the open list');
    assert_true(str_contains(http('GET', '/todos?view=assigned&status=done')->body(), 'ABM project task'), '... and is under Done');
    assert_true(str_contains(http('GET', '/todos?view=assigned&status=all&person='.$emp)->body(), 'ABM project task'), 'person filter covers project tasks');
    assert_true(!str_contains(http('GET', '/todos?view=assigned&who=team&status=all')->body(), 'ABM project task'), 'team view lists to-dos only');
    act_as($hr);
    http('POST', '/todos', ['title' => 'ABM from HR', 'priority' => 'medium', 'assignee_id' => $other, 'repeat' => 'once']);
    $body = http('GET', '/todos?view=assigned')->body();
    assert_true(str_contains($body, 'ABM from HR') && !str_contains($body, 'ABM one-off'), 'HR: own assignments only under Created by me');
    act_as($GLOBALS['ADMIN_ID']);
    http('POST', '/todos', ['title' => 'ABM from admin', 'priority' => 'medium', 'assignee_id' => $emp, 'repeat' => 'once']);
    http('POST', '/todos', ['_form' => 'task-new', 'project_id' => $pid, 'title' => 'ABM admin project task', 'status' => 'todo', 'priority' => 'low', 'assignee_id' => $emp]);
    $body = http('GET', '/todos?view=assigned')->body();
    assert_true(str_contains($body, 'Assigned by me') && str_contains($body, 'ABM from admin') && str_contains($body, 'ABM admin project task'), 'super admin sees to-dos and project tasks they assigned');
    act_as($emp);
    $body = http('GET', '/todos?view=assigned')->body();
    assert_true(!str_contains($body, 'Tasks you assign to yourself are in') && !str_contains($body, 'Assigned by me'), 'employee has no assigned view'); },
];
