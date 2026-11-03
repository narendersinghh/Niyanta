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
    assert_true(str_contains($body, 'id="task-new"') && str_contains($body, 'data-mode="project"') && str_contains($body, 'projects/'.$pid.'/tasks" data-task-form') && str_contains($body, 'value="'.$pid.'" selected'), 'project page: same dialog with the project preselected');
    assert_true(!str_contains($body, 'id="tn-title"'), 'old project dialog gone');
    $r = http('POST', '/todos', ['title' => 'Dialog plain todo', 'priority' => 'low', 'assignee_id' => $emp, 'repeat' => 'once', 'project_id' => '', 'return' => '/projects/'.$pid.'?tab=board']);
    assert_true(str_ends_with($r->headers()['Location'] ?? '', '/projects/'.$pid.'?tab=board'), 'no project: to-do, back to the project page');
    assert_eq($emp, (int) DB::value("SELECT assignee_id FROM todos WHERE title='Dialog plain todo'"));
    assert_eq(0, (int) DB::value("SELECT COUNT(*) FROM tasks WHERE title='Dialog plain todo'"));
    Features::set('feature:todos', false); \Core\Gate::flush();
    try {
      $body = http('GET', '/projects/'.$pid.'?tab=board')->body();
      assert_true(str_contains($body, 'id="task-new"') && !str_contains($body, 'No project') && !str_contains($body, 'name="repeat"'), 'Tasks & routines off: project-only dialog'); }
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
