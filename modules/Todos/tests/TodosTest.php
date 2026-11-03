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
];
