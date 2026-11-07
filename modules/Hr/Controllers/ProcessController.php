<?php
namespace Modules\Hr\Controllers;
use Core\{Audit, Controller, DB, Response, Settings, ValidationException};
use Modules\Hr\Services\ProcessService as PS;

/** Onboarding & exit: list, process page, lifecycle (start, pause, cancel, remove, complete, reopen), tasks, uploads, reviews and deletion requests. */
final class ProcessController extends Controller {
  private function assets(): array { return ['styles' => ['css/hr.css'], 'scripts' => ['js/hr.js']]; }
  private function hrOnly(): void { if (!PS::isHr()) { $this->abort(403, 'Only HR can do this.'); } }
  private function done(string $msg, string $to): Response { if ($this->request->wantsJson()) { return $this->json(['ok' => true, 'message' => $msg]); } $this->flash($msg); return $this->redirect($to); }
  private function backTo(array $p, string $hash = ''): string { $r = $this->request->str('return'); return ($r !== '' && str_starts_with($r, '/hr/') && !str_contains($r, '//') ? $r : '/hr/processes/'.$p['id']).$hash; }

  /** HR: everyone; managers: their direct reports. Tabs: processes, files waiting for review, deletion requests, tasks due. */
  public function index(): Response {
    [$w, $wp] = PS::scope('u');
    if (PS::isHr() || $this->can('hr.employees.view')) { $w = '1=1'; $wp = []; }
    $view = $this->request->str('view', 'processes'); $kind = $this->request->str('kind'); $status = $this->request->str('status', 'open');
    if (!in_array($view, ['processes', 'review', 'requests', 'due'], true)) { $view = 'processes'; }
    [$mw, $mp] = PS::scope('u');
    $counts = ['review' => (int) DB::value("SELECT COUNT(*) FROM hr_uploads h JOIN users u ON u.id=h.user_id LEFT JOIN hr_process_tasks t ON t.id=h.task_id LEFT JOIN hr_processes p ON p.id=t.process_id WHERE h.status='pending' AND h.purpose='submission' AND (p.id IS NULL OR p.status<>'cancelled') AND $mw", $mp),
      'requests' => (int) DB::value("SELECT COUNT(*) FROM hr_delete_requests r JOIN users u ON u.id=r.user_id WHERE r.status='pending' AND $mw", $mp),
      'due' => (int) DB::value("SELECT COUNT(*) FROM hr_process_tasks t JOIN hr_processes p ON p.id=t.process_id JOIN users u ON u.id=p.user_id WHERE p.status='active' AND t.status IN ('pending','rejected','submitted') AND t.due_date<=? AND $w", array_merge([date('Y-m-d', strtotime('+3 days'))], $wp))];
    $data = ['title' => 'Onboarding & exit', 'view' => $view, 'kind' => $kind, 'status' => $status, 'counts' => $counts] + $this->assets();
    if ($view === 'processes') {
      $sql = "SELECT p.*, u.name, u.avatar, u.designation, u.status AS emp_status, (SELECT COUNT(*) FROM hr_process_tasks t WHERE t.process_id=p.id) AS total, (SELECT COUNT(*) FROM hr_process_tasks t WHERE t.process_id=p.id AND t.status IN ('done','skipped')) AS done, (SELECT COUNT(*) FROM hr_process_tasks t WHERE t.process_id=p.id AND t.status='submitted') AS waiting FROM hr_processes p JOIN users u ON u.id=p.user_id WHERE $w";
      $p = $wp;
      if (isset(PS::KINDS[$kind])) { $sql .= ' AND p.kind=?'; $p[] = $kind; }
      if ($status === 'open') { $sql .= " AND p.status IN ('active','paused')"; } elseif (isset(PS::STATUS[$status])) { $sql .= ' AND p.status=?'; $p[] = $status; }
      $data['p'] = DB::paginate($sql." ORDER BY p.status IN ('active','paused') DESC, p.id DESC", $p, 20);
      $data['kpi'] = DB::first("SELECT SUM(p.kind='onboarding' AND p.status='active') AS onb, SUM(p.kind='exit' AND p.status='active') AS ex, SUM(p.status='paused') AS paused, SUM(p.status='completed' AND p.completed_at>=?) AS done30 FROM hr_processes p JOIN users u ON u.id=p.user_id WHERE $w", array_merge([date('Y-m-d', strtotime('-30 days'))], $wp)); }
    if ($view === 'review') { $data['rows'] = DB::all("SELECT h.*, u.name, u.avatar, t.title AS task_title, t.process_id, p.kind, d.title AS doc_title FROM hr_uploads h JOIN users u ON u.id=h.user_id LEFT JOIN hr_process_tasks t ON t.id=h.task_id LEFT JOIN hr_processes p ON p.id=t.process_id LEFT JOIN company_documents d ON d.id=h.company_doc_id WHERE h.status='pending' AND h.purpose='submission' AND (p.id IS NULL OR p.status<>'cancelled') AND $mw ORDER BY h.id LIMIT 100", $mp); }
    if ($view === 'requests') { $data['rows'] = DB::all("SELECT r.*, u.name, u.avatar, h.created_at AS uploaded_at, h.file_id, t.title AS task_title, t.process_id FROM hr_delete_requests r JOIN users u ON u.id=r.user_id LEFT JOIN hr_uploads h ON h.id=r.upload_id LEFT JOIN hr_process_tasks t ON t.id=r.task_id WHERE r.status='pending' AND $mw ORDER BY r.id LIMIT 100", $mp); }
    if ($view === 'due') { $data['rows'] = DB::all("SELECT t.*, u.name, u.avatar, p.kind FROM hr_process_tasks t JOIN hr_processes p ON p.id=t.process_id JOIN users u ON u.id=p.user_id WHERE p.status='active' AND t.status IN ('pending','rejected','submitted') AND t.due_date<=? AND $w ORDER BY t.due_date, t.id LIMIT 200", array_merge([date('Y-m-d', strtotime('+3 days'))], $wp)); }
    return $this->view('hr::processes/index', $data); }

  public function show(int $id): Response {
    $p = PS::find($id);
    return $this->view('hr::processes/show', ['title' => PS::KINDS[$p['kind']].' · '.$p['emp_name'], 'proc' => $p, 'others' => DB::all('SELECT id, kind, status, started_at, cancel_reason FROM hr_processes WHERE user_id=? AND id<>? ORDER BY id DESC', [(int) $p['user_id'], $id])] + PS::details($p) + $this->formData($p['kind']) + $this->assets()); }

  /** Lookups for HR forms (templates, company documents). */
  private function formData(string $kind): array {
    if (!PS::isHr()) { return []; }
    return ['templates' => PS::templates($kind), 'companyDocs' => DB::pairs('SELECT id, title FROM company_documents ORDER BY title')]; }

  public function start(int $id, string $kind): Response {
    $this->hrOnly();
    if (!isset(PS::KINDS[$kind])) { $this->abort(404); }
    if ($id === $this->uid()) { $this->abort(422, 'Another HR person needs to start your own '.strtolower(PS::KINDS[$kind]).'.'); }
    DB::find('users', $id) ?? $this->abort(404, 'Employee not found.');
    $rules = ['template_id' => 'required|integer', 'start_date' => 'nullable|date', 'note' => 'nullable|max:1000'];
    if ($kind === 'exit') { $rules += ['exit_type' => 'required|in:'.implode(',', array_keys(PS::EXIT_TYPES)), 'last_working_day' => 'required|date', 'deactivate_on_lwd' => 'boolean']; }
    $d = $this->validate($rules, ['template_id' => 'Template', 'last_working_day' => 'Last working day', 'exit_type' => 'Exit type']);
    $pid = PS::start($id, $kind, (int) $d['template_id'], $d);
    $this->flash(PS::KINDS[$kind].' started.'.($kind === 'exit' && !empty($d['deactivate_on_lwd']) ? ' The account will be deactivated at the end of '.fdate($d['last_working_day']).'.' : ''));
    return $this->redirect('/hr/processes/'.$pid); }

  public function transition(int $id): Response {
    $this->hrOnly();
    $p = PS::find($id);
    $msg = PS::transition($p, $this->request->str('action'), $this->request->str('reason'));
    return $this->done($msg, $this->backTo($p)); }

  public function remove(int $id): Response {
    $this->hrOnly();
    $p = PS::find($id);
    PS::remove($p);
    $this->flash(PS::KINDS[$p['kind']].' removed. Nothing was kept because no files had been added.');
    return $this->redirect('/hr/employees/'.$p['user_id'].'?tab=checklists'); }

  public function updateExit(int $id): Response {
    $this->hrOnly();
    $p = PS::find($id);
    PS::updateExit($p, $this->validate(['exit_type' => 'required|in:'.implode(',', array_keys(PS::EXIT_TYPES)), 'last_working_day' => 'required|date', 'deactivate_on_lwd' => 'boolean', 'note' => 'nullable|max:1000'], ['last_working_day' => 'Last working day']));
    return $this->done('Exit details saved.', $this->backTo($p)); }

  private function taskInput(): array {
    $d = $this->validate(['title' => 'required|max:190', 'description' => 'nullable|max:1000', 'task_type' => 'required|in:'.implode(',', array_keys(PS::TYPES)), 'owner' => 'required|in:'.implode(',', array_keys(PS::OWNERS)), 'due_date' => 'nullable|date', 'company_doc_id' => 'nullable|integer'], ['task_type' => 'Type', 'company_doc_id' => 'Company document']);
    $d['company_doc_id'] = $d['company_doc_id'] && DB::value('SELECT 1 FROM company_documents WHERE id=?', [(int) $d['company_doc_id']]) ? (int) $d['company_doc_id'] : null;
    if (in_array($d['task_type'], ['upload', 'sign', 'ack'], true)) { $d['owner'] = 'employee'; }
    if ($d['task_type'] === 'provide') { $d['owner'] = 'hr'; }
    return $d; }

  public function addTask(int $id): Response {
    $this->hrOnly();
    $p = PS::find($id);
    if (in_array($p['status'], ['completed', 'cancelled'], true)) { throw new ValidationException(['title' => 'Reopen it first to add tasks.']); }
    $d = $this->taskInput();
    $tid = DB::insert('hr_process_tasks', $d + ['process_id' => $id, 'user_id' => (int) $p['user_id'], 'position' => (int) DB::value('SELECT COALESCE(MAX(position),0)+1 FROM hr_process_tasks WHERE process_id=?', [$id]), 'status' => 'pending']);
    if ($d['task_type'] === 'sign') { PS::recalc($tid); }
    Audit::log('hr.task.create', 'employee', (int) $p['user_id'], ['process_id' => $id, 'task' => $d['title'], 'type' => $d['task_type']]);
    return $this->done('Task added.', $this->backTo($p, '#task-'.$tid)); }

  public function updateTask(int $id): Response {
    $this->hrOnly();
    $t = PS::task($id); $p = PS::find((int) $t['process_id']);
    $d = $this->validate(['title' => 'required|max:190', 'description' => 'nullable|max:1000', 'owner' => 'required|in:'.implode(',', array_keys(PS::OWNERS)), 'due_date' => 'nullable|date']);
    if (in_array($t['task_type'], ['upload', 'sign', 'ack'], true)) { $d['owner'] = 'employee'; }
    DB::update('hr_process_tasks', $d + ['reminded_on' => $d['due_date'] !== $t['due_date'] ? null : $t['reminded_on']], 'id=?', [$id]);
    Audit::log('hr.task.update', 'employee', (int) $t['user_id'], ['task_id' => $id, 'title' => $d['title'], 'due_date' => $d['due_date'], 'owner' => $d['owner']]);
    return $this->done('Task saved.', $this->backTo($p, '#task-'.$id)); }

  public function destroyTask(int $id): Response {
    $this->hrOnly();
    $t = PS::task($id); $p = PS::find((int) $t['process_id']);
    if (DB::value('SELECT 1 FROM hr_uploads WHERE task_id=? LIMIT 1', [$id])) { throw new ValidationException(['task' => 'This task has files. Mark it as not needed instead.']); }
    DB::delete('hr_process_tasks', 'id=?', [$id]);
    Audit::log('hr.task.delete', 'employee', (int) $t['user_id'], ['process_id' => (int) $t['process_id'], 'task' => $t['title']]);
    return $this->done('Task removed.', $this->backTo($p)); }

  /** Upload a file to a task: employee submission, HR on behalf, or (provide=1) HR file for the employee. */
  public function upload(int $id): Response {
    $t = PS::task($id);
    $file = $this->request->file('file') ?? throw new ValidationException(['file' => 'Choose a file to upload.']);
    $provide = (bool) $this->request->input('provide');
    PS::addFile($t, $file, $provide);
    $mine = (int) $t['user_id'] === $this->uid();
    $min = PS::selfDeleteMinutes();
    return $this->done($provide ? 'File added for the employee.' : ($mine ? 'Uploaded. HR will review it.'.($min ? ' You can delete it yourself for the next '.$min.' minutes.' : '') : 'Uploaded and approved.'), $this->backTo(['id' => $t['process_id']], '#task-'.$id)); }

  public function acknowledge(int $id): Response {
    $t = PS::task($id);
    PS::acknowledge($t);
    return $this->done('Thanks, your acknowledgement was recorded.', $this->backTo(['id' => $t['process_id']], '#task-'.$id)); }

  public function toggle(int $id): Response {
    $t = PS::task($id);
    $done = PS::toggle($t);
    if ($this->request->wantsJson()) { return $this->json(['done' => $done]); }
    return $this->redirect($this->backTo(['id' => $t['process_id']], '#task-'.$id)); }

  public function skip(int $id): Response {
    $this->hrOnly();
    $t = PS::task($id);
    $s = PS::skip($t);
    return $this->done($s ? 'Marked as not needed.' : 'Task is needed again.', $this->backTo(['id' => $t['process_id']], '#task-'.$id)); }

  /* ---------- uploads ---------- */
  private function uploadReturn(array $up): string {
    $r = $this->request->str('return');
    if ($r !== '' && str_starts_with($r, '/hr/') && !str_contains($r, '//')) { return $r; }
    if ($up['task_id'] && ($pid = DB::value('SELECT process_id FROM hr_process_tasks WHERE id=?', [(int) $up['task_id']]))) { return '/hr/processes/'.$pid.'#task-'.$up['task_id']; }
    return $up['company_doc_id'] ? '/hr/documents#doc-'.$up['company_doc_id'] : '/hr/processes?view=review'; }

  public function review(int $id): Response {
    $up = PS::upload($id);
    $d = $this->validate(['decision' => 'required|in:approve,reject', 'note' => 'nullable|max:500']);
    PS::review($up, $d['decision'], (string) $d['note']);
    return $this->done($d['decision'] === 'approve' ? 'File approved.' : 'File rejected; the employee was asked to upload again.', $this->uploadReturn($up)); }

  public function deleteUpload(int $id): Response {
    $up = PS::upload($id);
    $to = $this->uploadReturn($up);
    PS::deleteUpload($up);
    return $this->done('File deleted.', $to); }

  public function requestDeletion(int $id): Response {
    $up = PS::upload($id);
    $d = $this->validate(['reason' => 'required|max:500'], ['reason' => 'Reason']);
    PS::requestDeletion($up, $d['reason']);
    return $this->done('Deletion requested. HR will review it.', $this->uploadReturn($up)); }

  public function decide(int $id): Response {
    $req = DB::find('hr_delete_requests', $id) ?? $this->abort(404, 'Request not found.');
    $d = $this->validate(['decision' => 'required|in:approve,reject', 'note' => 'nullable|max:500']);
    PS::decideDeletion($req, $d['decision'], (string) $d['note']);
    $r = $this->request->str('return');
    return $this->done($d['decision'] === 'approve' ? 'File deleted as requested.' : 'Request declined; the file is kept.', $r !== '' && str_starts_with($r, '/hr/') && !str_contains($r, '//') ? $r : '/hr/processes?view=requests'); }

  /** Self-delete window in minutes, shared with the views. */
  public static function window(): int { return PS::selfDeleteMinutes(); }
  public static function autoDeactivateDefault(): bool { return Settings::bool('hr.exit_auto_deactivate', true); } }
