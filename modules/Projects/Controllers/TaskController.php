<?php
namespace Modules\Projects\Controllers;
use Core\{Audit, Controller, DB, Notify, Response, Storage, ValidationException};
use Modules\Projects\Services\{ProjectAccess as PA, ProjectService as PS};

/** Tasks: My Tasks, detail, create/edit, status & Kanban moves, subtasks, dependencies, comments, attachments. */
final class TaskController extends Controller {
  /** Task in a visible project, or 404. */
  private function task(int $id): array {
    $t = DB::first('SELECT t.*, p.name AS project_name, p.color AS project_color, p.manager_id AS project_manager FROM tasks t JOIN projects p ON p.id=t.project_id WHERE t.id=?', [$id]);
    if (!$t || !PA::canSee($this->user(), (int) $t['project_id'])) { $this->abort(404, 'Task not found.'); }
    return $t; }

  private function editable(int $id): array {
    $t = $this->task($id);
    if (!PA::canEditTask($this->user(), $t)) { $this->abort(403, 'You can only update tasks assigned to you.'); }
    return $t; }

  private function managed(int $id): array {
    $t = $this->task($id);
    if (!PA::canManageTasks($this->user(), (int) $t['project_id'])) { $this->abort(403, 'You cannot edit this task.'); }
    return $t; }

  /** Error that works for both AJAX (JSON 422) and normal forms (toast + back). */
  private function fail(string $msg, string $fallback = '/tasks'): Response {
    if ($this->request->wantsJson()) { return $this->json(['error' => $msg], 422); }
    $this->flash($msg, 'error');
    return $this->redirect(PS::returnPath($this->request, $fallback)); }

  private function rules(int $projectId): array {
    return ['title' => 'required|max:255', 'description' => 'nullable|max:20000', 'status' => 'required|in:'.implode(',', array_keys(PS::TASK_STATUSES)), 'priority' => 'required|in:'.implode(',', array_keys(PS::PRIORITIES)),
      'assignee_id' => 'nullable|integer', 'milestone_id' => 'nullable|integer', 'start_date' => 'nullable|date', 'due_date' => 'nullable|date', 'estimated_hours' => 'nullable|numeric|min:0|max:99999', 'billable' => 'boolean']; }

  /** Validate task input and make sure assignee/milestone belong to the project. */
  private function data(int $projectId): array {
    $d = $this->validate($this->rules($projectId), ['assignee_id' => 'Assignee', 'milestone_id' => 'Milestone']);
    if ($d['assignee_id'] && !isset(PS::team($projectId)[$d['assignee_id']])) { throw new ValidationException(['assignee_id' => 'Assignee must be a member of this project.']); }
    if ($d['milestone_id'] && !DB::value('SELECT 1 FROM milestones WHERE id=? AND project_id=?', [$d['milestone_id'], $projectId])) { throw new ValidationException(['milestone_id' => 'Milestone is invalid.']); }
    if ($d['start_date'] && $d['due_date'] && $d['due_date'] < $d['start_date']) { throw new ValidationException(['due_date' => 'Due date must be on or after the start date.']); }
    return $d; }

  /** Titles of unfinished dependencies blocking a task. */
  private function openDeps(int $id): array { return DB::column("SELECT d.title FROM task_dependencies td JOIN tasks d ON d.id=td.depends_on_id WHERE td.task_id=? AND d.status<>'done'", [$id]); }

  private function blockedMsg(array $titles): string { return 'This task is blocked, finish '.implode(', ', array_map(static fn ($t) => '“'.$t.'”', array_slice($titles, 0, 3))).(count($titles) > 3 ? ' and '.(count($titles) - 3).' more' : '').' first.'; }

  public function store(int $id): Response {
    if (!PA::canManageTasks($this->user(), $id)) { $this->abort(404, 'Project not found.'); }
    $d = $this->data($id);
    if ($d['status'] === 'done') { $d['completed_at'] = date('Y-m-d H:i:s'); }
    $now = date('Y-m-d H:i:s');
    $tid = DB::insert('tasks', $d + ['project_id' => $id, 'position' => (int) DB::value('SELECT IFNULL(MAX(position),0)+1 FROM tasks WHERE project_id=? AND status=?', [$id, $d['status']]), 'created_by' => $this->uid(), 'created_at' => $now, 'updated_at' => $now]);
    PS::recalcProgress($id);
    DB::update('projects', ['updated_at' => $now], 'id=?', [$id]);
    PS::notifyAssignee($d + ['id' => $tid, 'project_id' => $id], $this->uid());
    Audit::log('projects.task.create', 'task', $tid, ['project_id' => $id, 'title' => $d['title']]);
    if ($this->request->wantsJson()) {
      $card = DB::first('SELECT t.id, t.title, t.status, t.priority, t.due_date, t.assignee_id, t.project_id, u.name AS assignee, u.avatar, 0 AS sub_total, 0 AS sub_done, 0 AS comments, 0 AS open_deps FROM tasks t LEFT JOIN users u ON u.id=t.assignee_id WHERE t.id=?', [$tid]);
      $card['editable'] = PA::canEditTask($this->user(), $card);
      return $this->json(['ok' => true, 'message' => 'Task added', 'html' => \Core\View::partial('projects::projects/kcard', ['t' => $card]), 'progress' => (int) DB::value('SELECT progress FROM projects WHERE id=?', [$id])]); }
    $this->flash('Task added.');
    return $this->redirect(PS::returnPath($this->request, '/projects/'.$id.'?tab=board')); }

  /** New task dialog: team, milestones and billing default of a project the user may add tasks to (JSON). */
  public function formData(int $id): Response {
    if (!PA::canManageTasks($this->user(), $id)) { $this->abort(404, 'Project not found.'); }
    $d = \Modules\Todos\Services\TaskDialog::projectData($id);
    $pairs = static fn (array $a): array => array_map(null, array_keys($a), array_values($a));
    return $this->json(['team' => $pairs($d['team']), 'milestones' => $pairs($d['milestones']), 'billable' => $d['billable']]); }

  public function update(int $id): Response {
    $t = $this->managed($id);
    $d = $this->data((int) $t['project_id']);
    if ($d['status'] === 'done' && $t['status'] !== 'done' && ($open = $this->openDeps($id))) { return $this->fail($this->blockedMsg($open), '/tasks/'.$id); }
    $d['completed_at'] = $d['status'] === 'done' ? ($t['completed_at'] ?? date('Y-m-d H:i:s')) : null;
    DB::update('tasks', $d + ['updated_at' => date('Y-m-d H:i:s')], 'id=?', [$id]);
    if ($d['status'] !== $t['status']) { PS::recalcProgress((int) $t['project_id']); }
    if ((int) $d['assignee_id'] !== (int) $t['assignee_id']) { PS::notifyAssignee($d + ['id' => $id, 'project_id' => $t['project_id']], $this->uid()); }
    Audit::log('projects.task.update', 'task', $id, ['changed' => array_keys(array_diff_assoc(array_map('strval', $d), array_map('strval', array_intersect_key($t, $d))))]);
    $this->flash('Task updated.');
    return $this->redirect('/tasks/'.$id); }

  /** Apply a status change after the dependency check. Returns error message or null. */
  private function applyStatus(array $t, string $status): ?string {
    if ($status === $t['status']) { return null; }
    if ($status === 'done' && ($open = $this->openDeps((int) $t['id']))) { return $this->blockedMsg($open); }
    DB::update('tasks', ['status' => $status, 'completed_at' => $status === 'done' ? date('Y-m-d H:i:s') : null, 'updated_at' => date('Y-m-d H:i:s')], 'id=?', [(int) $t['id']]);
    PS::recalcProgress((int) $t['project_id']);
    Audit::log('projects.task.status', 'task', (int) $t['id'], ['from' => $t['status'], 'to' => $status]);
    if ($status === 'review' && $t['project_manager'] && (int) $t['project_manager'] !== $this->uid()) { Notify::user((int) $t['project_manager'], 'Task ready for review: '.$t['title'], 'Project: '.$t['project_name'], '/tasks/'.$t['id'], false, 'eye'); }
    return null; }

  public function status(int $id): Response {
    $t = $this->task($id);
    $parent = $t['parent_id'] ? DB::first('SELECT id, project_id, assignee_id FROM tasks WHERE id=?', [(int) $t['parent_id']]) : null;
    if (!PA::canEditTask($this->user(), $t) && !($parent && PA::canEditTask($this->user(), $parent))) { $this->abort(403, 'You can only update tasks assigned to you.'); }
    $status = $this->request->str('status');
    if (!isset(PS::TASK_STATUSES[$status])) { return $this->fail('Invalid status.'); }
    if ($err = $this->applyStatus($t, $status)) { return $this->fail($err, '/tasks/'.$id); }
    if ($this->request->wantsJson()) { return $this->json(['ok' => true, 'progress' => (int) DB::value('SELECT progress FROM projects WHERE id=?', [(int) $t['project_id']])]); }
    $this->flash('Status set to '.PS::TASK_STATUSES[$status].'.');
    return $this->redirect(PS::returnPath($this->request, '/tasks/'.$id)); }

  /** Kanban drop: new status + ordered ids of the target column. */
  public function move(int $id): Response {
    $t = $this->editable($id);
    if ($t['parent_id']) { return $this->fail('Subtasks are not shown on the board.'); }
    $status = $this->request->str('status');
    if (!isset(PS::TASK_STATUSES[$status])) { return $this->fail('Invalid status.'); }
    if ($err = $this->applyStatus($t, $status)) { return $this->fail($err); }
    $order = array_values(array_filter(array_map('intval', explode(',', $this->request->str('order')))));
    if ($order && PA::canManageTasks($this->user(), (int) $t['project_id'])) {
      $valid = array_map('intval', DB::column('SELECT id FROM tasks WHERE project_id=? AND status=? AND parent_id IS NULL AND id IN '.DB::in($order), [(int) $t['project_id'], $status, ...$order]));
      DB::transaction(static function () use ($order, $valid) {
        $pos = 0;
        foreach ($order as $tid) { if (in_array($tid, $valid, true)) { DB::update('tasks', ['position' => ++$pos], 'id=?', [$tid]); } } }); }
    elseif (($i = array_search($id, $order, true)) !== false) {
      $prev = $i > 0 ? (int) DB::value('SELECT position FROM tasks WHERE id=? AND project_id=?', [$order[$i - 1], (int) $t['project_id']]) : 0;
      DB::update('tasks', ['position' => $prev + 1], 'id=?', [$id]); }
    return $this->json(['ok' => true, 'message' => 'Moved to '.PS::TASK_STATUSES[$status], 'progress' => (int) DB::value('SELECT progress FROM projects WHERE id=?', [(int) $t['project_id']])]); }

  public function destroy(int $id): Response {
    $t = $this->managed($id);
    $files = DB::column("SELECT id FROM files WHERE module='projects' AND entity_type='task' AND (entity_id=? OR entity_id IN (SELECT id FROM tasks WHERE parent_id=?))", [$id, $id]);
    DB::transaction(static function () use ($id, $files) {
      foreach ($files as $f) { Storage::remove((int) $f); }
      DB::delete('tasks', 'id=?', [$id]); });
    PS::recalcProgress((int) $t['project_id']);
    Audit::log('projects.task.delete', 'task', $id, ['title' => $t['title'], 'project_id' => $t['project_id']]);
    $this->flash('Task deleted.');
    return $this->redirect($t['parent_id'] ? '/tasks/'.$t['parent_id'] : '/projects/'.$t['project_id'].'?tab=board'); }

  public function comment(int $id): Response {
    $t = $this->editable($id);
    $d = $this->validate(['body' => 'required|max:5000'], ['body' => 'Comment']);
    $cid = DB::insert('task_comments', ['task_id' => $id, 'user_id' => $this->uid(), 'body' => $d['body'], 'created_at' => date('Y-m-d H:i:s')]);
    Audit::log('projects.task.comment', 'task', $id, ['comment_id' => $cid]);
    $me = $this->user();
    foreach (array_unique(array_filter([(int) $t['assignee_id'], (int) $t['created_by']])) as $uid) { if ($uid !== $this->uid()) { Notify::user($uid, $me['name'].' commented on '.$t['title'], str_limit($d['body'], 160), '/tasks/'.$id.'#comments', false, 'message'); } }
    $this->flash('Comment added.');
    return $this->redirect('/tasks/'.$id.'#comments'); }

  public function deleteComment(int $id, int $commentId): Response {
    $t = $this->task($id);
    $c = DB::first('SELECT * FROM task_comments WHERE id=? AND task_id=?', [$commentId, $id]) ?? $this->abort(404, 'Comment not found.');
    if ((int) $c['user_id'] !== $this->uid() && !PA::canManageTasks($this->user(), (int) $t['project_id'])) { $this->abort(403, 'You can only delete your own comments.'); }
    DB::delete('task_comments', 'id=?', [$commentId]);
    Audit::log('projects.task.comment_delete', 'task', $id, ['comment_id' => $commentId]);
    $this->flash('Comment deleted.');
    return $this->redirect('/tasks/'.$id.'#comments'); }

  public function subtask(int $id): Response {
    $t = $this->editable($id);
    if ($t['parent_id']) { return $this->fail('Subtasks cannot have their own subtasks.', '/tasks/'.$id); }
    $d = $this->validate(['title' => 'required|max:255', 'assignee_id' => 'nullable|integer', 'due_date' => 'nullable|date'], ['assignee_id' => 'Assignee']);
    $manage = PA::canManageTasks($this->user(), (int) $t['project_id']);
    $assignee = $manage && $d['assignee_id'] && isset(PS::team((int) $t['project_id'])[$d['assignee_id']]) ? $d['assignee_id'] : ($t['assignee_id'] ? (int) $t['assignee_id'] : null);
    $now = date('Y-m-d H:i:s');
    $sid = DB::insert('tasks', ['project_id' => $t['project_id'], 'parent_id' => $id, 'milestone_id' => $t['milestone_id'], 'title' => $d['title'], 'status' => 'todo', 'priority' => $t['priority'], 'assignee_id' => $assignee, 'due_date' => $d['due_date'], 'billable' => $t['billable'], 'position' => (int) DB::value('SELECT IFNULL(MAX(position),0)+1 FROM tasks WHERE parent_id=?', [$id]), 'created_by' => $this->uid(), 'created_at' => $now, 'updated_at' => $now]);
    if ($assignee && $assignee !== (int) $t['assignee_id']) { PS::notifyAssignee(['id' => $sid, 'title' => $d['title'], 'project_id' => $t['project_id'], 'assignee_id' => $assignee, 'due_date' => $d['due_date']], $this->uid()); }
    Audit::log('projects.task.subtask', 'task', $id, ['subtask_id' => $sid]);
    $this->flash('Subtask added.');
    return $this->redirect('/tasks/'.$id); }

  /** Would adding "task depends on dep" create a cycle? Walk dep's dependency chain within the project. */
  private function createsCycle(int $taskId, int $depId, int $projectId): bool {
    $edges = [];
    foreach (DB::all('SELECT td.task_id, td.depends_on_id FROM task_dependencies td JOIN tasks t ON t.id=td.task_id WHERE t.project_id=?', [$projectId]) as $e) { $edges[(int) $e['task_id']][] = (int) $e['depends_on_id']; }
    $stack = [$depId]; $seen = [];
    while ($stack) {
      $n = array_pop($stack);
      if ($n === $taskId) { return true; }
      if (isset($seen[$n])) { continue; }
      $seen[$n] = true;
      foreach ($edges[$n] ?? [] as $m) { $stack[] = $m; } }
    return false; }

  public function addDependency(int $id): Response {
    $t = $this->managed($id);
    $dep = $this->request->int('depends_on_id');
    if ($dep === $id || !DB::value('SELECT 1 FROM tasks WHERE id=? AND project_id=?', [$dep, (int) $t['project_id']])) { return $this->fail('Pick another task from the same project.', '/tasks/'.$id); }
    if ($this->createsCycle($id, $dep, (int) $t['project_id'])) { return $this->fail('That would create a circular dependency.', '/tasks/'.$id); }
    DB::query('INSERT IGNORE INTO task_dependencies (task_id, depends_on_id) VALUES (?,?)', [$id, $dep]);
    Audit::log('projects.task.dependency_add', 'task', $id, ['depends_on' => $dep]);
    $this->flash('Dependency added.');
    return $this->redirect('/tasks/'.$id); }

  public function removeDependency(int $id, int $depId): Response {
    $this->managed($id);
    DB::delete('task_dependencies', 'task_id=? AND depends_on_id=?', [$id, $depId]);
    Audit::log('projects.task.dependency_remove', 'task', $id, ['depends_on' => $depId]);
    $this->flash('Dependency removed.');
    return $this->redirect('/tasks/'.$id); }

  public function upload(int $id): Response {
    $this->editable($id);
    $f = $this->request->file('file') ?? throw new ValidationException(['file' => 'Choose a file to upload.']);
    Storage::attach($f, 'projects', 'task', $id, 'attachment');
    $this->flash('Attachment uploaded.');
    return $this->redirect('/tasks/'.$id.'#files'); }

  public function show(int $id): Response {
    $u = $this->user();
    $t = $this->task($id);
    $t += DB::first('SELECT a.name AS assignee, a.avatar AS assignee_avatar, c.name AS creator, m.title AS milestone, pt.title AS parent_title FROM tasks t LEFT JOIN users a ON a.id=t.assignee_id LEFT JOIN users c ON c.id=t.created_by LEFT JOIN milestones m ON m.id=t.milestone_id LEFT JOIN tasks pt ON pt.id=t.parent_id WHERE t.id=?', [$id]);
    $subs = DB::all('SELECT s.id, s.title, s.status, s.due_date, s.assignee_id, s.project_id, u.name AS assignee, u.avatar FROM tasks s LEFT JOIN users u ON u.id=s.assignee_id WHERE s.parent_id=? ORDER BY s.position, s.id', [$id]);
    $deps = DB::all('SELECT d.id, d.title, d.status, d.due_date FROM task_dependencies td JOIN tasks d ON d.id=td.depends_on_id WHERE td.task_id=? ORDER BY d.title', [$id]);
    $blocking = DB::all('SELECT d.id, d.title, d.status FROM task_dependencies td JOIN tasks d ON d.id=td.task_id WHERE td.depends_on_id=? ORDER BY d.title', [$id]);
    $comments = DB::all('SELECT c.*, u.name, u.avatar FROM task_comments c LEFT JOIN users u ON u.id=c.user_id WHERE c.task_id=? ORDER BY c.id', [$id]);
    $sec = PS::on('time') ? PS::taskSeconds([$id, ...array_map('intval', array_column($subs, 'id'))]) : [];
    $own = $sec[$id] ?? ['total' => 0, 'billable' => 0];
    $subSec = array_sum(array_map(static fn ($s) => $s['total'], array_diff_key($sec, [$id => 1])));
    $canEdit = PA::canEditTask($u, $t);
    $canManage = PA::canManageTasks($u, (int) $t['project_id']);
    $data = ['title' => $t['title'], 't' => $t, 'subs' => $subs, 'deps' => $deps, 'blocking' => $blocking, 'comments' => $comments, 'files' => Storage::files('task', $id), 'sec' => $own, 'subSec' => $subSec,
      'canEdit' => $canEdit, 'canManage' => $canManage, 'styles' => ['css/projects.css'], 'scripts' => ['js/projects.js']];
    if ($canManage) {
      $data['candidates'] = DB::pairs('SELECT id, title FROM tasks WHERE project_id=? AND id<>? AND id NOT IN (SELECT depends_on_id FROM task_dependencies WHERE task_id=?) ORDER BY parent_id IS NOT NULL, title LIMIT 300', [(int) $t['project_id'], $id, $id]);
      $data['assignees'] = PS::team((int) $t['project_id']);
      $data['milestoneOpts'] = DB::pairs('SELECT id, title FROM milestones WHERE project_id=? ORDER BY due_date IS NULL, due_date, position', [(int) $t['project_id']]); }
    return $this->view('projects::tasks/show', $data); }

  /** My Tasks (assigned to me) or Team view (projects I manage / all for unrestricted users). */
  public function mine(): Response {
    $u = $this->user(); $uid = $this->uid();
    $teamAllowed = PA::unrestricted($u) || (bool) DB::value('SELECT 1 FROM projects WHERE manager_id=? LIMIT 1', [$uid]);
    $mode = $teamAllowed && $this->request->str('view') === 'team' ? 'team' : 'mine';
    [$w, $wp] = PA::scope($u, 'p.id');
    $sql = "SELECT t.id, t.title, t.status, t.priority, t.due_date, t.assignee_id, t.project_id, t.parent_id, p.name AS project, p.color, u.name AS assignee, u.avatar, pt.title AS parent_title FROM tasks t JOIN projects p ON p.id=t.project_id LEFT JOIN users u ON u.id=t.assignee_id LEFT JOIN tasks pt ON pt.id=t.parent_id WHERE $w AND t.status<>'done' AND p.status NOT IN ('cancelled')";
    $p = $wp;
    $assignee = $this->request->int('assignee');
    $project = $this->request->int('project');
    if ($mode === 'mine') { $sql .= ' AND t.assignee_id=?'; $p[] = $uid; }
    else {
      if (!PA::unrestricted($u)) { $sql .= ' AND p.manager_id=?'; $p[] = $uid; }
      if ($assignee) { $sql .= ' AND t.assignee_id=?'; $p[] = $assignee; } }
    if ($project) { $sql .= ' AND t.project_id=?'; $p[] = $project; }
    $tasks = DB::all($sql." ORDER BY t.due_date IS NULL, t.due_date, FIELD(t.priority,'urgent','high','medium','low'), t.id LIMIT 400", $p);
    $groups = ['overdue' => [], 'today' => [], 'week' => [], 'later' => [], 'none' => []];
    foreach ($tasks as $t) { $t['editable'] = PA::canEditTask($u, $t) || ($t['parent_id'] && (int) $t['assignee_id'] === $uid); $groups[PS::bucket($t['due_date'], $t['status'])][] = $t; }
    $mon = date('Y-m-d', strtotime('monday this week'));
    $week = [];
    for ($i = 0; $i < 7; $i++) { $week[date('Y-m-d', strtotime("$mon +$i day"))] = 0; }
    foreach ($tasks as $t) { if ($t['due_date'] && isset($week[$t['due_date']])) { $week[$t['due_date']]++; } }
    $doneWeek = (int) DB::value('SELECT COUNT(*) FROM tasks t JOIN projects p ON p.id=t.project_id WHERE '.$w.' AND t.status=\'done\' AND t.completed_at>=?'.($mode === 'mine' ? ' AND t.assignee_id=?' : ''), [...$wp, $mon.' 00:00:00', ...($mode === 'mine' ? [$uid] : [])]);
    $projects = DB::pairs("SELECT p.id, p.name FROM projects p WHERE $w AND p.status NOT IN ('completed','cancelled')".($mode === 'team' && !PA::unrestricted($u) ? ' AND p.manager_id='.(int) $uid : '').' ORDER BY p.name', $wp);
    $people = $mode === 'team' ? DB::pairs('SELECT DISTINCT u.id, u.name FROM users u JOIN tasks t ON t.assignee_id=u.id JOIN projects p ON p.id=t.project_id WHERE '.$w.(PA::unrestricted($u) ? '' : ' AND p.manager_id='.(int) $uid)." AND t.status<>'done' ORDER BY u.name", $wp) : [];
    return $this->view('projects::tasks/mine', ['title' => $mode === 'team' ? 'Team tasks' : 'My tasks', 'groups' => $groups, 'total' => count($tasks), 'week' => $week, 'doneWeek' => $doneWeek, 'mode' => $mode, 'teamAllowed' => $teamAllowed, 'projects' => $projects, 'people' => $people, 'project' => $project, 'assignee' => $assignee, 'styles' => ['css/projects.css', 'css/todos.css'], 'scripts' => ['js/projects.js', 'js/taskdialog.js']]); } }
