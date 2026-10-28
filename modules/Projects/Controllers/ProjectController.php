<?php
namespace Modules\Projects\Controllers;
use Core\{Audit, Controller, DB, Notify, Response, Storage, ValidationException};
use Modules\Projects\Services\{ProjectAccess as PA, ProjectService as PS};

/** Projects: list, create/edit, detail tabs (overview, board, list, milestones, change requests, files). */
final class ProjectController extends Controller {
  /** Load a project the current user may see, or 404 (never reveal projects outside scope). */
  private function visible(int $id): array {
    $p = DB::first('SELECT p.*, c.name AS client_name, c.company AS client_company, m.name AS manager_name, m.avatar AS manager_avatar FROM projects p LEFT JOIN clients c ON c.id=p.client_id LEFT JOIN users m ON m.id=p.manager_id WHERE p.id=?', [$id]);
    if (!$p || !PA::canSee($this->user(), $id)) { $this->abort(404, 'Project not found.'); }
    return $p; }

  private function manageable(int $id): array {
    $p = $this->visible($id);
    if (!PA::canManageProject($this->user(), $id)) { $this->abort(403, 'You cannot edit this project.'); }
    return $p; }

  public function index(): Response {
    $u = $this->user();
    [$w, $wp] = PA::scope($u, 'p.id');
    $status = $this->request->str('status'); $client = $this->request->int('client'); $q = $this->request->str('q');
    $view = $this->request->str('view') === 'table' ? 'table' : 'cards';
    $sql = "SELECT p.id, p.name, p.code, p.status, p.priority, p.deadline, p.start_date, p.color, p.progress, p.budget, p.billing_type, c.name AS client_name, m.name AS manager_name, m.avatar AS manager_avatar FROM projects p LEFT JOIN clients c ON c.id=p.client_id LEFT JOIN users m ON m.id=p.manager_id WHERE $w";
    $p = $wp;
    if (isset(PS::STATUSES[$status])) { $sql .= ' AND p.status=?'; $p[] = $status; }
    elseif ($status === 'open') { $sql .= " AND p.status IN ('planning','active','on_hold')"; }
    if ($client) { $sql .= ' AND p.client_id=?'; $p[] = $client; }
    if ($q !== '') { $sql .= ' AND (p.name LIKE ? OR p.code LIKE ? OR c.name LIKE ?)'; array_push($p, DB::like($q), DB::like($q), DB::like($q)); }
    $page = DB::paginate($sql." ORDER BY FIELD(p.status,'active','planning','on_hold','completed','cancelled'), p.deadline IS NULL, p.deadline, p.id DESC", $p, $view === 'table' ? 20 : 12);
    $ids = array_column($page['items'], 'id');
    $members = []; $counts = [];
    if ($ids) {
      foreach (DB::all('SELECT pm.project_id, u.name, u.avatar FROM project_members pm JOIN users u ON u.id=pm.user_id WHERE pm.project_id IN '.DB::in($ids).' ORDER BY u.name', $ids) as $m) { $members[$m['project_id']][] = $m; }
      $counts = DB::all("SELECT project_id, COUNT(*) AS total, SUM(status='done') AS done FROM tasks WHERE parent_id IS NULL AND project_id IN ".DB::in($ids).' GROUP BY project_id', $ids);
      $counts = array_column($counts, null, 'project_id'); }
    $stats = DB::pairs("SELECT p.status, COUNT(*) FROM projects p WHERE $w GROUP BY p.status", $wp);
    $overdue = (int) DB::value("SELECT COUNT(*) FROM projects p WHERE $w AND p.deadline<? AND p.status NOT IN ('completed','cancelled')", [...$wp, date('Y-m-d')]);
    $clients = DB::pairs("SELECT DISTINCT c.id, c.name FROM clients c JOIN projects p ON p.client_id=c.id WHERE $w ORDER BY c.name", $wp);
    return $this->view('projects::projects/index', ['title' => 'Projects', 'p' => $page, 'members' => $members, 'counts' => $counts, 'stats' => $stats, 'overdue' => $overdue, 'clients' => $clients, 'viewMode' => $view, 'styles' => ['css/projects.css']]); }

  private function lookups(?int $id): array {
    return ['clients' => DB::pairs("SELECT id, CONCAT(name, IF(company IS NULL OR company='', '', CONCAT(' — ', company))) FROM clients WHERE status<>'inactive' ORDER BY name"),
      'users' => PS::users(), 'memberIds' => $id ? array_map('intval', DB::column('SELECT user_id FROM project_members WHERE project_id=?', [$id])) : [], 'styles' => ['css/projects.css']]; }

  public function create(): Response {
    $cid = $this->request->int('client_id');
    return $this->view('projects::projects/form', ['title' => 'New project', 'pr' => ['id' => null, 'status' => 'planning', 'priority' => 'medium', 'billing_type' => 'fixed', 'color' => PS::COLORS[random_int(0, 5)], 'manager_id' => $this->uid(), 'client_id' => $cid ?: null, 'start_date' => date('Y-m-d')]] + $this->lookups(null)); }

  public function edit(int $id): Response {
    $pr = $this->manageable($id);
    return $this->view('projects::projects/form', ['title' => 'Edit '.$pr['name'], 'pr' => $pr] + $this->lookups($id)); }

  private function data(?int $id): array {
    $d = $this->validate(['name' => 'required|max:190', 'code' => 'nullable|max:30|regex:/^[A-Za-z0-9._-]+$/|unique:projects,code'.($id ? ",$id" : ''), 'client_id' => 'nullable|integer|exists:clients,id',
      'description' => 'nullable|max:20000', 'scope' => 'nullable|max:20000', 'deliverables' => 'nullable|max:20000', 'status' => 'required|in:'.implode(',', array_keys(PS::STATUSES)), 'priority' => 'required|in:'.implode(',', array_keys(PS::PRIORITIES)),
      'start_date' => 'nullable|date', 'deadline' => 'nullable|date', 'budget' => 'nullable|numeric|min:0|max:999999999999', 'billing_type' => 'required|in:'.implode(',', array_keys(PS::BILLING)), 'hourly_rate' => 'nullable|numeric|min:0|max:99999999',
      'estimated_hours' => 'nullable|numeric|min:0|max:99999999', 'color' => 'required|in:'.implode(',', PS::COLORS), 'manager_id' => 'nullable|integer|exists:users,id'], ['client_id' => 'Client', 'manager_id' => 'Project manager']);
    if ($d['start_date'] && $d['deadline'] && $d['deadline'] < $d['start_date']) { throw new ValidationException(['deadline' => 'Deadline must be on or after the start date.']); }
    if ($d['code'] !== null) { $d['code'] = strtoupper($d['code']); }
    return $d; }

  /** Validated member ids (active users only). */
  private function memberIds(): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $this->request->arr('members')))));
    return $ids ? array_map('intval', DB::column("SELECT id FROM users WHERE status='active' AND id IN ".DB::in($ids), $ids)) : []; }

  public function store(): Response {
    $d = $this->data(null);
    $members = $this->memberIds();
    $now = date('Y-m-d H:i:s');
    $id = DB::transaction(function () use ($d, $members, $now) {
      $id = DB::insert('projects', $d + ['created_by' => $this->uid(), 'created_at' => $now, 'updated_at' => $now]);
      DB::insertMany('project_members', array_map(static fn ($m) => ['project_id' => $id, 'user_id' => $m, 'role' => null], $members));
      return $id; });
    PA::flush();
    Audit::log('projects.project.create', 'project', $id, ['name' => $d['name'], 'members' => count($members)]);
    $this->notifyAdded($id, $d['name'], array_unique([...$members, (int) $d['manager_id']]));
    $this->flash('Project created.');
    return $this->redirect('/projects/'.$id); }

  public function update(int $id): Response {
    $old = $this->manageable($id);
    $d = $this->data($id);
    $members = $this->memberIds();
    $before = array_map('intval', DB::column('SELECT user_id FROM project_members WHERE project_id=?', [$id]));
    DB::transaction(function () use ($id, $d, $members, $before) {
      DB::update('projects', $d + ['updated_at' => date('Y-m-d H:i:s')], 'id=?', [$id]);
      if ($gone = array_values(array_diff($before, $members))) { DB::query('DELETE FROM project_members WHERE project_id=? AND user_id IN '.DB::in($gone), [$id, ...$gone]); }
      DB::insertMany('project_members', array_map(static fn ($m) => ['project_id' => $id, 'user_id' => $m, 'role' => null], array_values(array_diff($members, $before)))); });
    PA::flush();
    $added = array_diff($members, $before);
    if ((int) $d['manager_id'] && (int) $d['manager_id'] !== (int) $old['manager_id']) { $added[] = (int) $d['manager_id']; }
    $this->notifyAdded($id, $d['name'], array_unique($added));
    Audit::log('projects.project.update', 'project', $id, ['changed' => array_keys(array_diff_assoc(array_map('strval', $d), array_map('strval', array_intersect_key($old, $d)))), 'members_added' => array_values(array_diff($members, $before)), 'members_removed' => array_values(array_diff($before, $members))]);
    $this->flash('Project updated.');
    return $this->redirect('/projects/'.$id); }

  private function notifyAdded(int $id, string $name, array $uids): void {
    foreach ($uids as $uid) { if ($uid && $uid !== $this->uid()) { Notify::user($uid, 'You were added to project '.$name, 'Open the project to see tasks and milestones.', '/projects/'.$id, false, 'folder'); } } }

  public function destroy(int $id): Response {
    $p = $this->visible($id);
    $files = DB::column("SELECT id FROM files WHERE module='projects' AND ((entity_type='project' AND entity_id=?) OR (entity_type='task' AND entity_id IN (SELECT id FROM tasks WHERE project_id=?)))", [$id, $id]);
    DB::transaction(static function () use ($id, $files) {
      foreach ($files as $f) { Storage::remove((int) $f); }
      DB::delete('projects', 'id=?', [$id]); });
    Audit::log('projects.project.delete', 'project', $id, ['name' => $p['name']]);
    $this->flash('Project deleted.');
    return $this->redirect('/projects'); }

  public function upload(int $id): Response {
    $this->visible($id);
    if (!$this->can('projects.manage') && !$this->can('projects.tasks.manage') && !$this->can('projects.tasks.update_own')) { $this->abort(403, 'You cannot upload files to this project.'); }
    $f = $this->request->file('file') ?? throw new ValidationException(['file' => 'Choose a file to upload.']);
    $cat = in_array($c = $this->request->str('category'), ['general', 'brief', 'design', 'contract', 'deliverable'], true) ? $c : 'general';
    Storage::attach($f, 'projects', 'project', $id, $cat);
    $this->flash('File uploaded.');
    return $this->redirect('/projects/'.$id.'?tab=files'); }

  public function show(int $id): Response {
    $u = $this->user();
    $pr = $this->visible($id);
    $tab = in_array($t = $this->request->str('tab', 'overview'), ['overview', 'board', 'list', 'milestones', 'changes', 'files'], true) ? $t : 'overview';
    $team = DB::all('SELECT u.id, u.name, u.avatar, u.designation, pm.role FROM project_members pm JOIN users u ON u.id=pm.user_id WHERE pm.project_id=? ORDER BY u.name', [$id]);
    $sc = DB::pairs('SELECT status, COUNT(*) FROM tasks WHERE project_id=? AND parent_id IS NULL GROUP BY status', [$id]);
    $data = ['title' => $pr['name'], 'pr' => $pr, 'tab' => $tab, 'team' => $team, 'sc' => $sc, 'styles' => ['css/projects.css'], 'scripts' => ['js/projects.js'],
      'canManage' => PA::canManageProject($u, $id), 'canTasks' => PA::canManageTasks($u, $id), 'canCr' => $this->can('projects.change_requests.manage'), 'canDelete' => $this->can('projects.delete')];
    if ($data['canTasks'] && in_array($tab, ['board', 'list', 'overview'], true)) { $data['assignees'] = PS::team($id); $data['milestoneOpts'] = DB::pairs('SELECT id, title FROM milestones WHERE project_id=? ORDER BY due_date IS NULL, due_date, position', [$id]); }
    $data += match ($tab) {
      'overview' => $this->overview($pr),
      'board' => $this->board($id),
      'list' => $this->taskList($id),
      'milestones' => ['milestones' => $this->milestones($id)],
      'changes' => ['crs' => DB::all('SELECT cr.*, d.name AS decided_name, c.name AS creator FROM change_requests cr LEFT JOIN users d ON d.id=cr.decided_by LEFT JOIN users c ON c.id=cr.created_by WHERE cr.project_id=? ORDER BY cr.id DESC', [$id])],
      'files' => ['files' => Storage::files('project', $id), 'taskFiles' => DB::all("SELECT f.id, f.original_name, f.size, f.created_at, t.id AS task_id, t.title AS task_title FROM files f JOIN tasks t ON t.id=f.entity_id WHERE f.module='projects' AND f.entity_type='task' AND t.project_id=? ORDER BY f.id DESC LIMIT 50", [$id])] };
    return $this->view('projects::projects/show', $data); }

  private function milestones(int $id): array {
    return DB::all("SELECT m.*, COUNT(t.id) AS total, IFNULL(SUM(t.status='done'),0) AS done FROM milestones m LEFT JOIN tasks t ON t.milestone_id=m.id AND t.parent_id IS NULL WHERE m.project_id=? GROUP BY m.id ORDER BY m.due_date IS NULL, m.due_date, m.position, m.id", [$id]); }

  private function overview(array $pr): array {
    $id = (int) $pr['id'];
    $sec = PS::projectSeconds($id);
    $load = DB::pairs("SELECT assignee_id, COUNT(*) FROM tasks WHERE project_id=? AND status<>'done' AND assignee_id IS NOT NULL GROUP BY assignee_id", [$id]);
    $estTasks = (float) DB::value('SELECT IFNULL(SUM(estimated_hours),0) FROM tasks WHERE project_id=? AND parent_id IS NULL', [$id]);
    $upcoming = DB::all("SELECT t.id, t.title, t.due_date, t.status, t.priority, u.name AS assignee, u.avatar FROM tasks t LEFT JOIN users u ON u.id=t.assignee_id WHERE t.project_id=? AND t.status<>'done' AND t.due_date IS NOT NULL ORDER BY t.due_date LIMIT 6", [$id]);
    $openCr = (int) DB::value("SELECT COUNT(*) FROM change_requests WHERE project_id=? AND status='pending'", [$id]);
    return ['milestones' => $this->milestones($id), 'sec' => $sec, 'load' => $load, 'estTasks' => $estTasks, 'upcoming' => $upcoming, 'openCr' => $openCr]; }

  private function board(int $id): array {
    $tasks = DB::all("SELECT t.id, t.title, t.status, t.priority, t.due_date, t.assignee_id, t.position, t.project_id, u.name AS assignee, u.avatar, IFNULL(s.total,0) AS sub_total, IFNULL(s.done,0) AS sub_done, IFNULL(c.n,0) AS comments, IFNULL(d.open_deps,0) AS open_deps
      FROM tasks t LEFT JOIN users u ON u.id=t.assignee_id
      LEFT JOIN (SELECT parent_id, COUNT(*) AS total, SUM(status='done') AS done FROM tasks WHERE project_id=? AND parent_id IS NOT NULL GROUP BY parent_id) s ON s.parent_id=t.id
      LEFT JOIN (SELECT tc.task_id, COUNT(*) AS n FROM task_comments tc JOIN tasks x ON x.id=tc.task_id WHERE x.project_id=? GROUP BY tc.task_id) c ON c.task_id=t.id
      LEFT JOIN (SELECT td.task_id, COUNT(*) AS open_deps FROM task_dependencies td JOIN tasks dt ON dt.id=td.depends_on_id AND dt.status<>'done' JOIN tasks x ON x.id=td.task_id WHERE x.project_id=? GROUP BY td.task_id) d ON d.task_id=t.id
      WHERE t.project_id=? AND t.parent_id IS NULL ORDER BY t.position, t.id", [$id, $id, $id, $id]);
    $cols = array_fill_keys(array_keys(PS::TASK_STATUSES), []);
    foreach ($tasks as $t) { $t['editable'] = PA::canEditTask($this->user(), $t); $cols[$t['status']][] = $t; }
    return ['cols' => $cols]; }

  private function taskList(int $id): array {
    $f = ['assignee' => $this->request->str('assignee'), 'priority' => $this->request->str('priority'), 'status' => $this->request->str('status'), 'due' => $this->request->str('due'), 'q' => $this->request->str('q')];
    $sorts = ['title' => 't.title', 'status' => "FIELD(t.status,'todo','in_progress','review','done')", 'priority' => "FIELD(t.priority,'urgent','high','medium','low')", 'due' => 't.due_date IS NULL, t.due_date', 'assignee' => 'u.name', 'created' => 't.id'];
    $sort = isset($sorts[$s = $this->request->str('sort', 'due')]) ? $s : 'due';
    $dir = $this->request->str('dir') === 'desc' ? 'DESC' : 'ASC';
    $sql = "SELECT t.id, t.title, t.status, t.priority, t.due_date, t.estimated_hours, t.billable, t.assignee_id, t.project_id, u.name AS assignee, u.avatar, m.title AS milestone, (SELECT COUNT(*) FROM tasks s WHERE s.parent_id=t.id) AS subs FROM tasks t LEFT JOIN users u ON u.id=t.assignee_id LEFT JOIN milestones m ON m.id=t.milestone_id WHERE t.project_id=? AND t.parent_id IS NULL";
    $p = [$id];
    if ($f['assignee'] === 'none') { $sql .= ' AND t.assignee_id IS NULL'; } elseif (ctype_digit($f['assignee'])) { $sql .= ' AND t.assignee_id=?'; $p[] = (int) $f['assignee']; }
    if (isset(PS::PRIORITIES[$f['priority']])) { $sql .= ' AND t.priority=?'; $p[] = $f['priority']; }
    if (isset(PS::TASK_STATUSES[$f['status']])) { $sql .= ' AND t.status=?'; $p[] = $f['status']; } elseif ($f['status'] === 'open') { $sql .= " AND t.status<>'done'"; }
    $today = date('Y-m-d');
    if ($f['due'] === 'overdue') { $sql .= " AND t.due_date<? AND t.status<>'done'"; $p[] = $today; }
    elseif ($f['due'] === 'week') { $sql .= ' AND t.due_date BETWEEN ? AND ?'; array_push($p, $today, date('Y-m-d', strtotime('sunday this week'))); }
    elseif ($f['due'] === 'none') { $sql .= ' AND t.due_date IS NULL'; }
    if ($f['q'] !== '') { $sql .= ' AND t.title LIKE ?'; $p[] = DB::like($f['q']); }
    $order = $sort === 'due' ? ($dir === 'DESC' ? 't.due_date IS NULL, t.due_date DESC' : 't.due_date IS NULL, t.due_date') : $sorts[$sort].' '.$dir;
    $page = DB::paginate("$sql ORDER BY $order, t.id", $p, 25);
    foreach ($page['items'] as &$t) { $t['editable'] = PA::canEditTask($this->user(), $t); }
    unset($t);
    return ['tp' => $page, 'f' => $f, 'sort' => $sort, 'dir' => $dir, 'members' => PS::team($id)]; } }
