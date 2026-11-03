<?php
namespace Modules\Todos\Controllers;
use Core\{Audit, Controller, DB, Notify, Response, ValidationException, View};
use Modules\Projects\Services\{ProjectAccess as PA, ProjectService as PS};
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

  /** Safe local return path (hidden "return" field: task pages, a project page or the dashboard), else the fallback. */
  private function to(string $fallback = '/todos'): Response {
    $p = $this->request->str('return');
    return $this->redirect(preg_match('#^/(?:todos|tasks|projects/\d+)(?:[?\#][^\s]*)?$#', $p) || preg_match('#^/(?:dashboard)?$#', $p) ? $p : $fallback); }

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
      'projTab' => feature_on('projects') && ($this->can('projects.view') || $this->can('projects.view_all')), 'newOpen' => $this->request->int('new') === 1, 'teamAllowed' => $this->projTeam($u), 'styles' => ['css/todos.css'], 'scripts' => ['js/todos.js', 'js/taskdialog.js']];
    if ($view === 'my') {
      $open = DB::all(T::ROW_SQL." WHERE t.assignee_id=? AND t.status='open' ORDER BY t.due_date IS NULL, t.due_date, ".T::PRIO_ORDER.", t.id LIMIT 500", [$uid]);
      $data['groups'] = T::groups($open);
      $data['pgroups'] = T::groups($ptasks = $this->projectTasks($u));
      $data['open'] = count($open) + count($ptasks);
      $data['doneRows'] = DB::all(T::ROW_SQL." WHERE t.assignee_id=? AND t.status='done' AND t.completed_at>=? ORDER BY t.completed_at DESC LIMIT 100", [$uid, date('Y-m-d H:i:s', strtotime('-7 days'))]); }
    elseif ($view === 'assigned') { $data += $this->assigned($u); }
    else {
      [$w, $p] = $all ? ['1=1', []] : ['s.created_by=?', [$uid]];
      $data['routines'] = DB::all("SELECT s.*, a.name AS assignee, a.avatar AS assignee_avatar, p.name AS project, p.color AS project_color FROM todo_series s LEFT JOIN users a ON a.id=s.assignee_id LEFT JOIN projects p ON p.id=s.project_id WHERE $w ORDER BY s.active DESC, s.title LIMIT 300", $p); }
    if ($id = $this->request->int('edit')) {
      $t = DB::first(T::ROW_SQL.' WHERE t.id=?', [$id]);
      if ($t && T::canManage($u, $t)) { $data['edit'] = $t; } }
    if ($id = $this->request->int('edit_routine')) {
      $s = DB::first('SELECT s.*, a.name AS assignee, p.name AS project FROM todo_series s LEFT JOIN users a ON a.id=s.assignee_id LEFT JOIN projects p ON p.id=s.project_id WHERE s.id=?', [$id]);
      if ($s && T::canManage($u, $s)) {
        if ($s['project_id']) { $s['team'] = PS::team((int) $s['project_id']); $s['milestones'] = \Modules\Todos\Services\TaskDialog::projectData((int) $s['project_id'])['milestones']; }
        $data['editSeries'] = $s; } }
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

  /**
   * "Assigned by me": tasks I gave to someone else (who=mine): one-time tasks, routine occurrences and, with Projects on, project tasks
   * I created for another person; or my team's / everyone's to-dos (who=team). Filter by person and status (open, overdue, done, all).
   * Tasks I assign to myself are only in My tasks.
   */
  private function assigned(array $u): array {
    $uid = (int) $u['id'];
    $who = $this->request->str('who') === 'team' ? 'team' : 'mine';
    if ($who === 'mine') { $base = 't.created_by=? AND t.assignee_id<>?'; $bp = [$uid, $uid]; }
    elseif ($this->can('todos.assign_all')) { $base = 't.assignee_id<>?'; $bp = [$uid]; }
    else { $base = 't.assignee_id IN (SELECT id FROM users WHERE manager_id=?)'; $bp = [$uid]; }
    $sql = "SELECT 'todo' AS kind, t.id, t.title, t.description, t.priority, t.assignee_id, t.created_by, t.due_date, t.status, t.completed_at, t.series_id, t.occurrence, c.name AS creator, a.name AS assignee, a.avatar AS assignee_avatar, s.frequency, s.workdays_only, s.weekdays, s.month_day, NULL AS project_id, NULL AS project, NULL AS color, NULL AS parent_title, NULL AS pstatus, t.stage, t.start_date, t.estimate_hours FROM todos t JOIN users c ON c.id=t.created_by JOIN users a ON a.id=t.assignee_id LEFT JOIN todo_series s ON s.id=t.series_id WHERE $base";
    $proj = $who === 'mine' && feature_on('projects') && ($this->can('projects.view') || $this->can('projects.view_all'));
    if ($proj) {
      [$pw, $pp] = \Modules\Projects\Services\ProjectAccess::scope($u, 't.project_id');
      $sql .= " UNION ALL SELECT 'task', t.id, t.title, t.description, t.priority, t.assignee_id, t.created_by, t.due_date, IF(t.status='done','done','open'), t.completed_at, NULL, NULL, c.name, a.name, a.avatar, NULL, NULL, NULL, NULL, t.project_id, p.name, p.color, pt.title, t.status, NULL, NULL, NULL FROM tasks t JOIN projects p ON p.id=t.project_id JOIN users c ON c.id=t.created_by JOIN users a ON a.id=t.assignee_id LEFT JOIN tasks pt ON pt.id=t.parent_id WHERE t.created_by=? AND t.assignee_id<>? AND p.status<>'cancelled' AND $pw";
      $bp = [...$bp, $uid, $uid, ...$pp]; }
    $from = "FROM ($sql) x";
    $people = DB::pairs("SELECT DISTINCT x.assignee_id, x.assignee $from ORDER BY x.assignee", $bp);
    $person = $this->request->int('person');
    $w = '1=1'; $p = $bp;
    if ($person && isset($people[$person])) { $w .= ' AND x.assignee_id=?'; $p[] = $person; } else { $person = 0; }
    $today = date('Y-m-d');
    $c = DB::first("SELECT COUNT(*) AS total, IFNULL(SUM(x.status='open'),0) AS open, IFNULL(SUM(x.status='done'),0) AS done, IFNULL(SUM(x.status='open' AND x.due_date<?),0) AS overdue $from WHERE $w", [$today, ...$p]);
    $status = $this->request->str('status', 'open');
    if (!in_array($status, ['open', 'done', 'overdue', 'all'], true)) { $status = 'open'; }
    $w .= match ($status) { 'open' => " AND x.status='open'", 'done' => " AND x.status='done'", 'overdue' => " AND x.status='open' AND x.due_date<?", default => '' };
    if ($status === 'overdue') { $p[] = $today; }
    $page = DB::paginate("SELECT x.* $from WHERE $w ORDER BY x.status='done', x.due_date IS NULL, x.due_date".($status === 'done' ? ' DESC' : '').', x.id DESC', $p, 30);
    foreach ($page['items'] as &$r) {
      if ($r['kind'] === 'task') { $r['status'] = $r['pstatus']; $r['editable'] = \Modules\Projects\Services\ProjectAccess::canEditTask($u, $r); } }
    unset($r);
    return ['who' => $who, 'people' => $people, 'person' => $person, 'status' => $status, 'counts' => array_map('intval', $c), 'page' => $page, 'projRows' => $proj]; }

  /**
   * Validated input of the task form (same fields everywhere). $mode: new (once or repeat), todo (one-time edit), series (routine edit).
   * $pid: project of a project routine (assignee must be on its team, milestone of the project), 0 for to-dos (assignee from Todos::assignable).
   */
  private function input(string $mode, ?int $keepAssignee = null, int $pid = 0): array {
    $d = $this->validate(['title' => 'required|max:255', 'description' => 'nullable|max:'.($pid ? 20000 : 5000), 'priority' => 'required|in:'.implode(',', array_keys(T::PRIORITIES)), 'status' => 'nullable|in:'.implode(',', array_keys(PS::TASK_STATUSES)), 'assignee_id' => 'nullable|integer',
      'repeat' => $mode === 'todo' ? 'nullable' : 'required|in:'.($mode === 'new' ? 'once,' : '').'daily,weekly,monthly', 'due_date' => 'nullable|date', 'workdays_only' => 'boolean', 'month_day' => 'nullable|integer|min:1|max:31',
      'start_date' => 'nullable|date', 'end_date' => 'nullable|date', 'estimated_hours' => 'nullable|numeric|min:0|max:99999', 'milestone_id' => 'nullable|integer', 'billable' => 'boolean'],
      ['assignee_id' => 'Assign to', 'month_day' => 'Day of month', 'estimated_hours' => 'Estimate', 'milestone_id' => 'Milestone']);
    if ($pid) {
      $d['assignee_id'] = $d['assignee_id'] ?: null;
      if ($d['assignee_id'] && $d['assignee_id'] !== $keepAssignee && !isset(PS::team($pid)[$d['assignee_id']])) { throw new ValidationException(['assignee_id' => 'Assignee must be a member of this project.']); }
      if ($d['milestone_id'] && !DB::value('SELECT 1 FROM milestones WHERE id=? AND project_id=?', [$d['milestone_id'], $pid])) { throw new ValidationException(['milestone_id' => 'Milestone is invalid.']); } }
    else {
      $d['assignee_id'] = (int) ($d['assignee_id'] ?: $this->uid());
      if ($d['assignee_id'] !== $keepAssignee && !isset(T::assignable($this->user())[$d['assignee_id']])) { throw new ValidationException(['assignee_id' => 'You cannot assign tasks to this person.']); } }
    $base = ['title' => $d['title'], 'description' => $d['description'], 'priority' => $d['priority'], 'assignee_id' => $d['assignee_id'], 'status' => $d['status'] ?: 'todo', 'estimate_hours' => $d['estimated_hours'] === null || $d['estimated_hours'] === '' ? null : round((float) $d['estimated_hours'], 2)];
    $rep = $mode === 'todo' ? 'once' : $d['repeat'];
    if ($rep === 'once') {
      if ($d['start_date'] && $d['due_date'] && $d['due_date'] < $d['start_date']) { throw new ValidationException(['due_date' => 'Due date must be on or after the start date.']); }
      return $base + ['repeat' => 'once', 'start_date' => $d['start_date'], 'due_date' => $d['due_date']]; }
    if ($base['status'] === 'done') { throw new ValidationException(['status' => 'A repeating task starts as To do, In progress or In review.']); }
    $s = $base + ['repeat' => $rep, 'frequency' => $rep, 'workdays_only' => $rep === 'daily' ? $d['workdays_only'] : 0, 'weekdays' => null, 'month_day' => null, 'start_date' => $d['start_date'] ?: date('Y-m-d'), 'end_date' => $d['end_date'],
      'project_id' => $pid ?: null, 'milestone_id' => $pid ? ($d['milestone_id'] ?: null) : null, 'billable' => $pid ? $d['billable'] : 0];
    if ($rep === 'weekly') {
      $days = T::days($this->request->arr('weekdays'));
      if (!$days) { throw new ValidationException(['weekdays' => 'Pick at least one weekday.']); }
      $s['weekdays'] = implode(',', $days); }
    if ($rep === 'monthly') {
      if (!$d['month_day']) { throw new ValidationException(['month_day' => 'Day of month is required.']); }
      $s['month_day'] = $d['month_day']; }
    if ($s['end_date'] && $s['end_date'] < $s['start_date']) { throw new ValidationException(['end_date' => 'End date must be on or after the start date.']); }
    return $s; }

  /** Columns of a one-time to-do from the form: Status To do / In progress / In review keeps it open (stage), Done completes it. */
  private function todoCols(array $d, ?array $old = null): array {
    $done = $d['status'] === 'done';
    $c = ['title' => $d['title'], 'description' => $d['description'], 'priority' => $d['priority'], 'assignee_id' => $d['assignee_id'], 'start_date' => $d['start_date'], 'due_date' => $d['due_date'], 'estimate_hours' => $d['estimate_hours'],
      'status' => $done ? 'done' : 'open', 'stage' => $done ? ($old['stage'] ?? null) : $d['status']];
    $c['completed_at'] = $done ? ($old && $old['status'] === 'done' ? $old['completed_at'] : date('Y-m-d H:i:s')) : null;
    return $c; }

  private function notifyAssigned(?int $assignee, string $title, string $body, bool $routine, string $url = '/todos'): void {
    if (!$assignee || $assignee === $this->uid()) { return; }
    Notify::user($assignee, ($routine ? 'New routine from ' : 'New task from ').$this->user()['name'].': '.$title, $body, $url, false, $routine ? 'refresh' : 'check-square'); }

  /** Project of a project routine the user may add tasks to (404 otherwise); finished projects take no new routines. */
  private function routineProject(int $pid, bool $new): array {
    if (!feature_on('projects') || !PA::canManageTasks($this->user(), $pid)) { $this->abort(404, 'Project not found.'); }
    $pr = DB::first('SELECT id, name, status FROM projects WHERE id=?', [$pid]);
    if ($new && in_array($pr['status'], ['completed', 'cancelled'], true)) { throw new ValidationException(['project_id' => 'This project is finished, repeating tasks cannot be added to it.']); }
    return $pr; }

  /**
   * New task dialog (always posted here when Tasks & routines is on). Project + Once: handed to the Projects task controller, which checks
   * projects.tasks.manage + project visibility, that the assignee is a project member, the milestone, notifies and audits.
   * Project + Daily/Weekly/Monthly: a project routine (todo_series with project_id) whose occurrences are project tasks.
   * No project: a to-do (Once) or a routine.
   */
  public function store(): Response {
    $pid = $this->request->int('project_id');
    if ($pid && !feature_on('projects')) { $this->abort(404, 'Project not found.'); }
    if ($pid && !isset(T::FREQ[$this->request->str('repeat')])) { return (new \Modules\Projects\Controllers\TaskController($this->request))->store($pid); }
    $pr = $pid ? $this->routineProject($pid, true) : null;
    $d = $this->input('new', null, $pid);
    $now = date('Y-m-d H:i:s'); $uid = $this->uid();
    if ($d['repeat'] === 'once') {
      $id = DB::insert('todos', $this->todoCols($d) + ['created_by' => $uid, 'created_at' => $now, 'updated_at' => $now]);
      $this->notifyAssigned($d['assignee_id'], $d['title'], $d['due_date'] ? 'Due '.fdate($d['due_date']).'.' : '', false);
      Audit::log('todos.create', 'todo', $id, ['assignee_id' => $d['assignee_id']]);
      $this->flash('Task added.');
      return $this->to(); }
    unset($d['repeat'], $d['due_date']);
    $id = DB::insert('todo_series', $d + ['created_by' => $uid, 'active' => 1, 'created_at' => $now, 'updated_at' => $now]);
    $n = T::sync($id);
    $this->notifyAssigned($d['assignee_id'], $d['title'], T::repeatLabel($d).($pr ? ' in '.$pr['name'] : '').', starting '.fdate($d['start_date']).'.', true, $pr ? '/tasks' : '/todos');
    Audit::log('todos.routine.create', 'todo_series', $id, ['assignee_id' => $d['assignee_id'], 'frequency' => $d['frequency'], 'project_id' => $pid ?: null]);
    $this->flash('Routine created'.($n ? ', today\'s task is '.($pr ? 'on the project board.' : 'on the list.') : '. Next one: '.(($nx = T::nextOccurrence($d + ['id' => $id])) ? fdate($nx) : 'none').'.'));
    return $this->to(); }

  public function update(int $id): Response {
    $t = $this->managed($id);
    $d = $this->input('todo', (int) $t['assignee_id']);
    $c = $this->todoCols($d, $t);
    if ($t['series_id'] || $t['occurrence']) { unset($c['due_date']); }
    DB::update('todos', $c + ['updated_at' => date('Y-m-d H:i:s')], 'id=?', [$id]);
    if ($d['assignee_id'] !== (int) $t['assignee_id']) { $this->notifyAssigned($d['assignee_id'], $d['title'], !empty($d['due_date']) ? 'Due '.fdate($d['due_date']).'.' : '', false); }
    Audit::log('todos.update', 'todo', $id, ['assignee_id' => $d['assignee_id'], 'status' => $c['status']]);
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
    $pid = (int) $s['project_id'];
    if ($pid) { $this->routineProject($pid, false); }
    $d = $this->input('series', $s['assignee_id'] === null ? null : (int) $s['assignee_id'], $pid);
    unset($d['repeat'], $d['due_date']);
    DB::update('todo_series', $d + ['updated_at' => date('Y-m-d H:i:s')], 'id=?', [$id]);
    T::sync($id);
    if ($d['assignee_id'] !== ($s['assignee_id'] === null ? null : (int) $s['assignee_id'])) { $this->notifyAssigned($d['assignee_id'], $d['title'], T::repeatLabel($d).'.', true, $pid ? '/tasks' : '/todos'); }
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
    $pid = (int) $s['project_id'];
    $tasks = $purge && $pid && feature_on('projects') && PA::canManageTasks($this->user(), $pid) ? array_map('intval', DB::column("SELECT t.id FROM todo_series_tasks l JOIN tasks t ON t.id=l.task_id WHERE l.series_id=? AND t.status='todo'", [$id])) : [];
    $n = DB::transaction(static function () use ($id, $purge, $tasks): int {
      $n = $purge ? DB::delete('todos', "series_id=? AND status='open'", [$id]) : 0;
      if ($tasks) { $n += DB::delete('tasks', 'id IN '.DB::in($tasks), $tasks); }
      DB::delete('todo_series', 'id=?', [$id]);
      return $n; });
    if ($tasks) { PS::recalcProgress($pid); }
    Audit::log('todos.routine.delete', 'todo_series', $id, ['title' => $s['title'], 'open_deleted' => $n]);
    $this->flash('Routine deleted'.($n ? ' with '.$n.' open task'.($n === 1 ? '' : 's').'.' : ', past tasks are kept.'));
    return $this->to('/todos?view=routines'); } }
