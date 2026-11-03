<?php
namespace Modules\Todos\Controllers;
use Core\{Audit, Controller, DB, Notify, Response, ValidationException, View};
use Modules\Todos\Services\Todos as T;

/** Tasks & routines: My tasks, Assigned by me, Routines; create/edit/complete/delete with record-level scoping. */
final class TodoController extends Controller {
  /** Todo the user may see (assignee, creator, assigner of the assignee), or 404. */
  private function visible(int $id): array {
    $t = DB::first(T::ROW_SQL.' WHERE t.id=?', [$id]);
    if (!$t || !T::canSee($this->user(), $t)) { $this->abort(404, 'Task not found.'); }
    return $t; }

  private function managed(int $id): array {
    $t = $this->visible($id);
    if (!T::canManage($this->user(), $t)) { $this->abort(403, 'Only the person who assigned this task can change it.'); }
    return $t; }

  private function series(int $id): array {
    $s = DB::first('SELECT * FROM todo_series WHERE id=?', [$id]);
    if (!$s || !T::canManage($this->user(), $s)) { $this->abort(404, 'Routine not found.'); }
    return $s; }

  /** Safe local return path (hidden "return" field), else the fallback. */
  private function to(string $fallback = '/todos'): Response {
    $p = $this->request->str('return');
    return $this->redirect(preg_match('#^/todos(?:[?\#][^\s]*)?$#', $p) || preg_match('#^/(?:dashboard)?$#', $p) ? $p : $fallback); }

  private function done(string $msg, ?array $row = null, string $mode = 'my'): Response {
    if ($this->request->wantsJson()) { return $this->json(['ok' => true, 'message' => $msg, 'html' => $row ? View::partial('todos::partials/row', ['t' => $row, 'mode' => $mode, 'uid' => $this->uid(), 'manage' => T::canManage($this->user(), $row), 'return' => $this->request->str('return', '/todos')]) : '']); }
    $this->flash($msg);
    return $this->to(); }

  public function index(): Response {
    $u = $this->user(); $uid = $this->uid();
    $canAssign = T::canAssign($u); $all = $this->can('todos.assign_all');
    $views = ['my' => 'My tasks'] + ($canAssign ? ['assigned' => 'Assigned by me'] : []) + ['routines' => 'Routines'];
    $view = $this->request->str('view', 'my');
    if (!isset($views[$view])) { $view = 'my'; }
    $data = ['title' => 'Tasks', 'view' => $view, 'views' => $views, 'canAssign' => $canAssign, 'all' => $all, 'uid' => $uid, 'assignees' => T::assignable($u), 'edit' => null, 'editSeries' => null,
      'projTab' => feature_on('projects') && ($this->can('projects.view') || $this->can('projects.view_all')), 'newOpen' => $this->request->int('new') === 1, 'teamAllowed' => $this->projTeam($u), 'styles' => ['css/todos.css'], 'scripts' => ['js/todos.js']];
    if ($view === 'my') {
      $open = DB::all(T::ROW_SQL." WHERE t.assignee_id=? AND t.status='open' ORDER BY t.due_date IS NULL, t.due_date, FIELD(t.priority,'high','medium','low'), t.id LIMIT 500", [$uid]);
      $data['groups'] = T::groups($open);
      $data['pgroups'] = T::groups($ptasks = $this->projectTasks($u));
      $data['open'] = count($open) + count($ptasks);
      $data['doneRows'] = DB::all(T::ROW_SQL." WHERE t.assignee_id=? AND t.status='done' AND t.completed_at>=? ORDER BY t.completed_at DESC LIMIT 100", [$uid, date('Y-m-d H:i:s', strtotime('-7 days'))]); }
    elseif ($view === 'assigned') { $data += $this->assigned($u); }
    else {
      [$w, $p] = $all ? ['1=1', []] : ['s.created_by=?', [$uid]];
      $data['routines'] = DB::all("SELECT s.*, a.name AS assignee, a.avatar AS assignee_avatar FROM todo_series s JOIN users a ON a.id=s.assignee_id WHERE $w ORDER BY s.active DESC, s.title LIMIT 300", $p); }
    if ($id = $this->request->int('edit')) {
      $t = DB::first(T::ROW_SQL.' WHERE t.id=?', [$id]);
      if ($t && T::canManage($u, $t)) { $data['edit'] = $t; } }
    if ($id = $this->request->int('edit_routine')) {
      $s = DB::first('SELECT s.*, a.name AS assignee FROM todo_series s JOIN users a ON a.id=s.assignee_id WHERE s.id=?', [$id]);
      if ($s && T::canManage($u, $s)) { $data['editSeries'] = $s; } }
    return $this->view('todos::index', $data); }

  /** Whether the project "Team project tasks" view applies (unrestricted or manages a project). */
  private function projTeam(array $u): bool { return feature_on('projects') && (\Modules\Projects\Services\ProjectAccess::unrestricted($u) || (bool) DB::value('SELECT 1 FROM projects WHERE manager_id=? LIMIT 1', [(int) $u['id']])); }

  /** Open project tasks assigned to me (Projects feature on), shown in the same My tasks list. */
  private function projectTasks(array $u): array {
    if (!feature_on('projects') || !($this->can('projects.view') || $this->can('projects.view_all'))) { return []; }
    [$w, $wp] = \Modules\Projects\Services\ProjectAccess::scope($u, 'p.id');
    $rows = DB::all("SELECT t.id, t.title, t.status, t.priority, t.due_date, t.assignee_id, t.project_id, t.parent_id, p.name AS project, p.color, pt.title AS parent_title FROM tasks t JOIN projects p ON p.id=t.project_id LEFT JOIN tasks pt ON pt.id=t.parent_id WHERE $w AND t.assignee_id=? AND t.status<>'done' ORDER BY t.due_date IS NULL, t.due_date, t.id LIMIT 300", [...$wp, (int) $u['id']]);
    foreach ($rows as &$r) { $r['editable'] = \Modules\Projects\Services\ProjectAccess::canEditTask($u, $r) || ($r['parent_id'] && (int) $r['assignee_id'] === (int) $u['id']); }
    return $rows; }

  /** "Assigned by me": tasks I created for others (who=mine) or my team's / everyone's tasks (who=team), filter by person and status. */
  private function assigned(array $u): array {
    $uid = (int) $u['id'];
    $who = $this->request->str('who') === 'team' ? 'team' : 'mine';
    if ($who === 'mine') { $base = 't.created_by=? AND t.assignee_id<>?'; $bp = [$uid, $uid]; }
    elseif ($this->can('todos.assign_all')) { $base = 't.assignee_id<>?'; $bp = [$uid]; }
    else { $base = 't.assignee_id IN (SELECT id FROM users WHERE manager_id=?)'; $bp = [$uid]; }
    $people = DB::pairs("SELECT DISTINCT a.id, a.name FROM todos t JOIN users a ON a.id=t.assignee_id WHERE $base ORDER BY a.name", $bp);
    $person = $this->request->int('person');
    if ($person && isset($people[$person])) { $base .= ' AND t.assignee_id=?'; $bp[] = $person; } else { $person = 0; }
    $c = DB::first("SELECT COUNT(*) AS total, IFNULL(SUM(t.status='open'),0) AS open, IFNULL(SUM(t.status='done'),0) AS done, IFNULL(SUM(t.status='open' AND t.due_date<?),0) AS overdue FROM todos t WHERE $base", [date('Y-m-d'), ...$bp]);
    $status = $this->request->str('status', 'open');
    if (!in_array($status, ['open', 'done', 'overdue', 'all'], true)) { $status = 'open'; }
    $w = $base.match ($status) { 'open' => " AND t.status='open'", 'done' => " AND t.status='done'", 'overdue' => " AND t.status='open' AND t.due_date<?", default => '' };
    $p = $status === 'overdue' ? [...$bp, date('Y-m-d')] : $bp;
    $page = DB::paginate(T::ROW_SQL." WHERE $w ORDER BY t.status='done', t.due_date IS NULL, t.due_date".($status === 'done' ? ' DESC' : '').', t.id DESC', $p, 30);
    return ['who' => $who, 'people' => $people, 'person' => $person, 'status' => $status, 'counts' => array_map('intval', $c), 'page' => $page]; }

  /** Validated input. $mode: new (once or repeat), todo (one-time edit), series (routine edit). */
  private function input(string $mode, ?int $keepAssignee = null): array {
    $d = $this->validate(['title' => 'required|max:190', 'description' => 'nullable|max:5000', 'priority' => 'required|in:low,medium,high', 'assignee_id' => 'nullable|integer',
      'repeat' => $mode === 'todo' ? 'nullable' : 'required|in:'.($mode === 'new' ? 'once,' : '').'daily,weekly,monthly', 'due_date' => 'nullable|date', 'workdays_only' => 'boolean',
      'month_day' => 'nullable|integer|min:1|max:31', 'start_date' => 'nullable|date', 'end_date' => 'nullable|date'], ['assignee_id' => 'Assign to', 'month_day' => 'Day of month']);
    $d['assignee_id'] = (int) ($d['assignee_id'] ?: $this->uid());
    if ($d['assignee_id'] !== $keepAssignee && !isset(T::assignable($this->user())[$d['assignee_id']])) { throw new ValidationException(['assignee_id' => 'You cannot assign tasks to this person.']); }
    $base = ['title' => $d['title'], 'description' => $d['description'], 'priority' => $d['priority'], 'assignee_id' => $d['assignee_id']];
    $rep = $mode === 'todo' ? 'once' : $d['repeat'];
    if ($rep === 'once') { return $base + ['repeat' => 'once', 'due_date' => $d['due_date']]; }
    $s = $base + ['repeat' => $rep, 'frequency' => $rep, 'workdays_only' => $rep === 'daily' ? $d['workdays_only'] : 0, 'weekdays' => null, 'month_day' => null, 'start_date' => $d['start_date'] ?: date('Y-m-d'), 'end_date' => $d['end_date']];
    if ($rep === 'weekly') {
      $days = T::days($this->request->arr('weekdays'));
      if (!$days) { throw new ValidationException(['weekdays' => 'Pick at least one weekday.']); }
      $s['weekdays'] = implode(',', $days); }
    if ($rep === 'monthly') {
      if (!$d['month_day']) { throw new ValidationException(['month_day' => 'Day of month is required.']); }
      $s['month_day'] = $d['month_day']; }
    if ($s['end_date'] && $s['end_date'] < $s['start_date']) { throw new ValidationException(['end_date' => 'End date must be on or after the start date.']); }
    return $s; }

  private function notifyAssigned(int $assignee, string $title, string $body, bool $routine): void {
    if ($assignee === $this->uid()) { return; }
    Notify::user($assignee, ($routine ? 'New routine from ' : 'New task from ').$this->user()['name'].': '.$title, $body, '/todos', false, $routine ? 'refresh' : 'check-square'); }

  public function store(): Response {
    $d = $this->input('new');
    $now = date('Y-m-d H:i:s'); $uid = $this->uid();
    if ($d['repeat'] === 'once') {
      $id = DB::insert('todos', ['title' => $d['title'], 'description' => $d['description'], 'priority' => $d['priority'], 'assignee_id' => $d['assignee_id'], 'created_by' => $uid, 'due_date' => $d['due_date'], 'status' => 'open', 'created_at' => $now, 'updated_at' => $now]);
      $this->notifyAssigned($d['assignee_id'], $d['title'], $d['due_date'] ? 'Due '.fdate($d['due_date']).'.' : '', false);
      Audit::log('todos.create', 'todo', $id, ['assignee_id' => $d['assignee_id']]);
      $this->flash('Task added.');
      return $this->to(); }
    unset($d['repeat']);
    $id = DB::insert('todo_series', $d + ['created_by' => $uid, 'active' => 1, 'created_at' => $now, 'updated_at' => $now]);
    $n = T::sync($id);
    $this->notifyAssigned($d['assignee_id'], $d['title'], T::repeatLabel($d).', starting '.fdate($d['start_date']).'.', true);
    Audit::log('todos.routine.create', 'todo_series', $id, ['assignee_id' => $d['assignee_id'], 'frequency' => $d['frequency']]);
    $this->flash('Routine created'.($n ? ', today\'s task is on the list.' : '. Next one: '.(($nx = T::nextOccurrence($d + ['id' => $id])) ? fdate($nx) : 'none').'.'));
    return $this->to(); }

  public function update(int $id): Response {
    $t = $this->managed($id);
    $d = $this->input('todo', (int) $t['assignee_id']);
    unset($d['repeat']);
    if ($t['series_id'] || $t['occurrence']) { unset($d['due_date']); }
    DB::update('todos', $d + ['updated_at' => date('Y-m-d H:i:s')], 'id=?', [$id]);
    if ($d['assignee_id'] !== (int) $t['assignee_id']) { $this->notifyAssigned($d['assignee_id'], $d['title'], !empty($d['due_date']) ? 'Due '.fdate($d['due_date']).'.' : '', false); }
    Audit::log('todos.update', 'todo', $id, ['assignee_id' => $d['assignee_id']]);
    $this->flash('Task updated.');
    return $this->to(); }

  public function complete(int $id): Response {
    $t = $this->visible($id);
    if ($t['status'] !== 'done') {
      $now = date('Y-m-d H:i:s');
      DB::update('todos', ['status' => 'done', 'completed_at' => $now, 'updated_at' => $now], 'id=?', [$id]);
      if ((int) $t['assignee_id'] === $this->uid() && (int) $t['created_by'] !== $this->uid() && !$t['occurrence']) { Notify::user((int) $t['created_by'], $this->user()['name'].' completed: '.$t['title'], 'Task you assigned is done.', '/todos?view=assigned&status=done', false, 'check-circle'); }
      $t = ['status' => 'done', 'completed_at' => $now] + $t; }
    return $this->done('Task completed.', $t, $this->request->str('mode', 'my')); }

  public function reopen(int $id): Response {
    $t = $this->visible($id);
    if ($t['status'] !== 'open') {
      DB::update('todos', ['status' => 'open', 'completed_at' => null, 'updated_at' => date('Y-m-d H:i:s')], 'id=?', [$id]);
      $t = ['status' => 'open', 'completed_at' => null] + $t; }
    return $this->done('Task reopened.', $t, $this->request->str('mode', 'my')); }

  public function destroy(int $id): Response {
    $t = $this->managed($id);
    DB::delete('todos', 'id=?', [$id]);
    Audit::log('todos.delete', 'todo', $id, ['title' => $t['title']]);
    if ($this->request->wantsJson()) { return $this->json(['ok' => true, 'message' => 'Task deleted.']); }
    $this->flash('Task deleted.');
    return $this->to(); }

  public function updateSeries(int $id): Response {
    $s = $this->series($id);
    $d = $this->input('series', (int) $s['assignee_id']);
    unset($d['repeat']);
    DB::update('todo_series', $d + ['updated_at' => date('Y-m-d H:i:s')], 'id=?', [$id]);
    T::sync($id);
    if ($d['assignee_id'] !== (int) $s['assignee_id']) { $this->notifyAssigned($d['assignee_id'], $d['title'], T::repeatLabel($d).'.', true); }
    Audit::log('todos.routine.update', 'todo_series', $id, ['assignee_id' => $d['assignee_id'], 'frequency' => $d['frequency']]);
    $this->flash('Routine updated. Changes apply to upcoming tasks.');
    return $this->to('/todos?view=routines'); }

  public function toggleSeries(int $id): Response {
    $s = $this->series($id);
    $on = !(int) $s['active'];
    DB::update('todo_series', ['active' => (int) $on, 'updated_at' => date('Y-m-d H:i:s')], 'id=?', [$id]);
    if ($on) { T::sync($id); }
    Audit::log($on ? 'todos.routine.resume' : 'todos.routine.pause', 'todo_series', $id);
    $this->flash($on ? 'Routine resumed.' : 'Routine paused, no new tasks will be created.');
    return $this->to('/todos?view=routines'); }

  public function destroySeries(int $id): Response {
    $s = $this->series($id);
    $purge = $this->request->str('purge') === '1';
    $n = DB::transaction(static function () use ($id, $purge): int {
      $n = $purge ? DB::delete('todos', "series_id=? AND status='open'", [$id]) : 0;
      DB::delete('todo_series', 'id=?', [$id]);
      return $n; });
    Audit::log('todos.routine.delete', 'todo_series', $id, ['title' => $s['title'], 'open_deleted' => $n]);
    $this->flash('Routine deleted'.($n ? ' with '.$n.' open task'.($n === 1 ? '' : 's').'.' : ', past tasks are kept.'));
    return $this->to('/todos?view=routines'); } }
