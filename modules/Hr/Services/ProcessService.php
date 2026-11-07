<?php
namespace Modules\Hr\Services;
use Core\{Audit, Auth, DB, Gate, HttpException, Notify, Settings, Storage, ValidationException};

/**
 * Onboarding and exit processes: a run per employee copied from a template, tasks of five types
 * (employee upload, sign & upload, acknowledge, checklist task, HR-provided file), reviews of uploads,
 * the self-delete window and deletion requests. Record-level access: the employee sees their own,
 * the direct manager (hr.employees.review_team) their reports, HR (hr.employees.manage) everyone.
 */
final class ProcessService {
  public const KINDS = ['onboarding' => 'Onboarding', 'exit' => 'Exit'];
  public const TYPES = ['upload' => 'Employee uploads a document', 'sign' => 'Sign & upload a document', 'ack' => 'Read & acknowledge', 'task' => 'Checklist task', 'provide' => 'HR provides a file to download'];
  public const OWNERS = ['employee' => 'Employee', 'hr' => 'HR', 'manager' => 'Manager', 'it' => 'IT'];
  public const EXIT_TYPES = ['resignation' => 'Resignation', 'termination' => 'Termination', 'contract_end' => 'Contract end', 'other' => 'Other'];
  public const STATUS = ['active' => ['In progress', 'lemon'], 'paused' => ['Paused', 'sky'], 'completed' => ['Completed', 'mint'], 'cancelled' => ['Cancelled', 'gray']];
  public const TASK_STATUS = ['pending' => ['To do', 'gray'], 'submitted' => ['Waiting for review', 'lemon'], 'rejected' => ['Re-upload needed', 'red'], 'done' => ['Done', 'mint'], 'skipped' => ['Not needed', 'sky']];
  public const EXT = ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'doc', 'docx'];

  public static function selfDeleteMinutes(): int { $v = Settings::get('hr.upload_self_delete_minutes'); return $v === null || $v === '' || !is_numeric($v) ? 20 : max(0, min(1440, (int) $v)); }
  private static function now(): string { return date('Y-m-d H:i:s'); }
  private static function me(): int { return (int) Auth::id(); }

  /* ---------- access ---------- */
  public static function isHr(?array $u = null): bool { return Gate::allows('hr.employees.manage', $u); }

  public static function isManagerOf(int $empId, ?array $u = null): bool {
    $u ??= Auth::user();
    return $u && (int) $u['id'] !== $empId && Gate::allows('hr.employees.review_team', $u) && (int) DB::value('SELECT manager_id FROM users WHERE id=?', [$empId]) === (int) $u['id']; }

  public static function canView(int $empId, ?array $u = null): bool {
    $u ??= Auth::user();
    return $u && ((int) $u['id'] === $empId || Gate::allows('hr.employees.view', $u) || self::isHr($u) || self::isManagerOf($empId, $u)); }

  /** Review uploads, delete files and decide deletion requests: HR or the direct manager, never on their own record. */
  public static function canModerate(int $empId, ?array $u = null): bool {
    $u ??= Auth::user();
    return $u && (int) $u['id'] !== $empId && (self::isHr($u) || self::isManagerOf($empId, $u)); }

  /** Signed company documents can also be reviewed by whoever manages company documents. */
  public static function canModerateUpload(array $up, ?array $u = null): bool {
    $u ??= Auth::user();
    if (self::canModerate((int) $up['user_id'], $u)) { return true; }
    return $u && $up['company_doc_id'] && !$up['task_id'] && (int) $u['id'] !== (int) $up['user_id'] && Gate::allows('hr.company_docs.manage', $u); }

  public static function canViewUpload(int $uploadId, ?array $u = null): bool {
    $up = DB::find('hr_uploads', $uploadId);
    return $up && (self::canView((int) $up['user_id'], $u) || self::canModerateUpload($up, $u)); }

  /** Who may tick a checklist task or act on a task of this owner. */
  public static function canAct(array $task, ?array $u = null): bool {
    $u ??= Auth::user();
    if (!$u) { return false; }
    $emp = (int) $task['user_id']; $self = (int) $u['id'] === $emp;
    return match ($task['owner']) { 'employee' => $self || self::canModerate($emp, $u), 'manager' => self::canModerate($emp, $u), default => !$self && self::isHr($u) }; }

  public static function canSelfDelete(array $up, ?array $u = null): bool {
    $u ??= Auth::user();
    return $u && (int) $up['uploaded_by'] === (int) $u['id'] && $up['purpose'] === 'submission' && $up['status'] !== 'approved' && strtotime($up['created_at']) >= time() - self::selfDeleteMinutes() * 60; }

  public static function canDelete(array $up, ?array $u = null): bool { return self::canModerateUpload($up, $u) || self::canSelfDelete($up, $u); }

  /** SQL condition on users alias `u` limiting rows to what the current reviewer may moderate. */
  public static function scope(string $alias = 'u'): array {
    $me = self::me();
    if (self::isHr()) { return ["$alias.id<>?", [$me]]; }
    if (Gate::allows('hr.employees.review_team')) { return ["$alias.manager_id=? AND $alias.id<>?", [$me, $me]]; }
    return ['1=0', []]; }

  /* ---------- loading ---------- */
  public static function find(int $id): array {
    $p = DB::first('SELECT p.*, u.name AS emp_name, u.email AS emp_email, u.avatar AS emp_avatar, u.status AS emp_status, u.designation, u.department, u.joined_on, u.manager_id, m.name AS manager_name, s.name AS started_by_name FROM hr_processes p JOIN users u ON u.id=p.user_id LEFT JOIN users m ON m.id=u.manager_id LEFT JOIN users s ON s.id=p.started_by WHERE p.id=?', [$id]);
    if (!$p) { throw new HttpException(404, 'Process not found.'); }
    if (!self::canView((int) $p['user_id'])) { Audit::log('access.denied', 'hr_process', $id); throw new HttpException(403, 'You cannot open this process.'); }
    return $p; }

  public static function task(int $id): array {
    $t = DB::find('hr_process_tasks', $id) ?? throw new HttpException(404, 'Task not found.');
    if (!self::canView((int) $t['user_id'])) { Audit::log('access.denied', 'hr_task', $id); throw new HttpException(403, 'You cannot open this task.'); }
    return $t; }

  public static function upload(int $id): array {
    $u = DB::find('hr_uploads', $id) ?? throw new HttpException(404, 'File not found.');
    if (!self::canView((int) $u['user_id']) && !self::canModerateUpload($u)) { Audit::log('access.denied', 'hr_upload', $id); throw new HttpException(403, 'You cannot open this file.'); }
    return $u; }

  /** Tasks, uploads grouped per task, linked company documents and pending deletion requests of a process. */
  public static function details(array $p): array {
    $tasks = DB::all('SELECT t.*, d.name AS done_by_name FROM hr_process_tasks t LEFT JOIN users d ON d.id=t.done_by WHERE t.process_id=? ORDER BY t.position, t.id', [(int) $p['id']]);
    $ids = array_map('intval', array_column($tasks, 'id'));
    $docIds = array_values(array_unique(array_filter(array_map('intval', array_column($tasks, 'company_doc_id')))));
    $docs = $docIds ? array_column(DB::all('SELECT d.id, d.title, d.file_id, d.requires_ack, d.requires_signature, f.original_name FROM company_documents d LEFT JOIN files f ON f.id=d.file_id WHERE d.id IN '.DB::in($docIds), $docIds), null, 'id') : [];
    $ups = [];
    if ($ids) {
      $sql = 'SELECT h.*, f.size, f.mime, ub.name AS uploader, rb.name AS reviewer FROM hr_uploads h LEFT JOIN files f ON f.id=h.file_id LEFT JOIN users ub ON ub.id=h.uploaded_by LEFT JOIN users rb ON rb.id=h.reviewed_by WHERE h.task_id IN '.DB::in($ids);
      $prm = $ids;
      if ($docIds) { $sql .= ' OR (h.task_id IS NULL AND h.user_id=? AND h.company_doc_id IN '.DB::in($docIds).')'; $prm = array_merge($prm, [(int) $p['user_id']], $docIds); }
      $rows = DB::all($sql.' ORDER BY h.id DESC', $prm);
      foreach ($rows as $r) {
        if ($r['task_id']) { $ups[(int) $r['task_id']][] = $r; continue; }
        foreach ($tasks as $t) { if ($t['task_type'] === 'sign' && (int) $t['company_doc_id'] === (int) $r['company_doc_id']) { $ups[(int) $t['id']][] = $r; } } } }
    $upIds = array_map('intval', array_merge(...array_map(static fn ($l) => array_column($l, 'id'), array_values($ups ?: [[]]))));
    $reqs = $upIds ? array_column(DB::all("SELECT r.*, u.name AS requester FROM hr_delete_requests r LEFT JOIN users u ON u.id=r.requested_by WHERE r.status='pending' AND r.upload_id IN ".DB::in($upIds), $upIds), null, 'upload_id') : [];
    return ['tasks' => $tasks, 'uploads' => $ups, 'docs' => $docs, 'requests' => $reqs]; }

  /** [done, total, pct] of a task list (skipped tasks count as done). */
  public static function progress(array $tasks): array {
    $total = count($tasks); $done = count(array_filter($tasks, static fn ($t) => in_array($t['status'], ['done', 'skipped'], true)));
    return [$done, $total, $total ? (int) round($done / $total * 100) : 0]; }

  /** Latest process of each kind for an employee (active/paused first). */
  public static function forEmployee(int $empId): array {
    return DB::all("SELECT p.*, (SELECT COUNT(*) FROM hr_process_tasks t WHERE t.process_id=p.id) AS total, (SELECT COUNT(*) FROM hr_process_tasks t WHERE t.process_id=p.id AND t.status IN ('done','skipped')) AS done, (SELECT COUNT(*) FROM hr_uploads h JOIN hr_process_tasks t ON t.id=h.task_id WHERE t.process_id=p.id) AS uploads FROM hr_processes p WHERE p.user_id=? ORDER BY p.status IN ('active','paused') DESC, p.id DESC", [$empId]); }

  /** Dashboard card: the employee's own active or paused processes with progress and their open tasks. */
  public static function openFor(int $uid): array {
    $procs = DB::all("SELECT * FROM hr_processes WHERE user_id=? AND status IN ('active','paused') ORDER BY kind='onboarding' DESC, id DESC", [$uid]);
    foreach ($procs as &$p) {
      $tasks = DB::all('SELECT id, title, task_type, owner, status, due_date FROM hr_process_tasks WHERE process_id=? ORDER BY position, id', [(int) $p['id']]);
      $p['progress'] = self::progress($tasks);
      $p['mine'] = array_values(array_filter($tasks, static fn ($t) => $t['owner'] === 'employee' && in_array($t['status'], ['pending', 'rejected', 'submitted'], true))); }
    return $procs; }

  /** Reviewer widget: files waiting for review, deletion requests and tasks due within 3 days, within the reviewer's scope. */
  public static function reviewCounts(): array {
    [$w, $p] = self::scope('u');
    return ['review' => (int) DB::value("SELECT COUNT(*) FROM hr_uploads h JOIN users u ON u.id=h.user_id LEFT JOIN hr_process_tasks t ON t.id=h.task_id LEFT JOIN hr_processes p ON p.id=t.process_id WHERE h.status='pending' AND h.purpose='submission' AND (p.id IS NULL OR p.status<>'cancelled') AND $w", $p),
      'requests' => (int) DB::value("SELECT COUNT(*) FROM hr_delete_requests r JOIN users u ON u.id=r.user_id WHERE r.status='pending' AND $w", $p),
      'due' => (int) DB::value("SELECT COUNT(*) FROM hr_process_tasks t JOIN hr_processes p ON p.id=t.process_id JOIN users u ON u.id=p.user_id WHERE p.status='active' AND t.owner<>'employee' AND t.status IN ('pending','rejected') AND t.due_date<=? AND $w", array_merge([date('Y-m-d')], $p))]; }

  public static function openProcess(int $empId, string $kind): ?array { return DB::first("SELECT * FROM hr_processes WHERE user_id=? AND kind=? AND status IN ('active','paused') ORDER BY id DESC LIMIT 1", [$empId, $kind]); }

  /* ---------- templates ---------- */
  public static function templates(?string $kind = null): array {
    return DB::all('SELECT t.*, (SELECT COUNT(*) FROM hr_template_tasks x WHERE x.template_id=t.id) AS tasks FROM hr_templates t'.($kind ? ' WHERE t.kind=?' : '').' ORDER BY t.kind, t.is_default DESC, t.name', $kind ? [$kind] : []); }

  /* ---------- lifecycle ---------- */
  /** Start an onboarding or exit from a template. $o: start_date, exit_type, last_working_day, deactivate_on_lwd, note. */
  public static function start(int $empId, string $kind, int $templateId, array $o = []): int {
    if (!isset(self::KINDS[$kind])) { throw new HttpException(404); }
    $emp = DB::first('SELECT id, name, joined_on, manager_id, status FROM users WHERE id=?', [$empId]) ?? throw new HttpException(404, 'Employee not found.');
    $tpl = DB::first('SELECT * FROM hr_templates WHERE id=? AND kind=?', [$templateId, $kind]) ?? throw new ValidationException(['template_id' => 'Choose a '.strtolower(self::KINDS[$kind]).' template.']);
    $start = (string) ($o['start_date'] ?? '') ?: date('Y-m-d');
    $lwd = $kind === 'exit' ? (string) ($o['last_working_day'] ?? '') : null;
    if ($kind === 'exit') {
      if (!$lwd) { throw new ValidationException(['last_working_day' => 'Last working day is required.']); }
      if ($emp['joined_on'] && $lwd < $emp['joined_on']) { throw new ValidationException(['last_working_day' => 'Last working day cannot be before the joining date.']); }
      if (!isset(self::EXIT_TYPES[$o['exit_type'] ?? ''])) { throw new ValidationException(['exit_type' => 'Choose the exit type.']); } }
    $id = DB::transaction(static function () use ($emp, $kind, $tpl, $start, $lwd, $o): int {
      if (DB::value("SELECT 1 FROM hr_processes WHERE user_id=? AND kind=? AND status IN ('active','paused') LIMIT 1 FOR UPDATE", [(int) $emp['id'], $kind])) { throw new ValidationException(['template_id' => 'An '.strtolower(self::KINDS[$kind]).' is already in progress for '.$emp['name'].'.']); }
      $id = DB::insert('hr_processes', ['user_id' => (int) $emp['id'], 'kind' => $kind, 'template_id' => (int) $tpl['id'], 'template_name' => $tpl['name'], 'status' => 'active', 'start_date' => $start, 'exit_type' => $kind === 'exit' ? $o['exit_type'] : null,
        'last_working_day' => $lwd, 'deactivate_on_lwd' => $kind === 'exit' && !empty($o['deactivate_on_lwd']) ? 1 : 0, 'note' => ($o['note'] ?? null) ?: null, 'started_by' => self::me() ?: null, 'started_at' => self::now()]);
      $base = $kind === 'exit' ? $lwd : $start;
      $acked = array_map('intval', DB::column('SELECT document_id FROM company_document_acks WHERE user_id=?', [(int) $emp['id']]));
      foreach (DB::all('SELECT * FROM hr_template_tasks WHERE template_id=? ORDER BY position, id', [(int) $tpl['id']]) as $i => $t) {
        $doc = $t['company_doc_id'] && DB::value('SELECT 1 FROM company_documents WHERE id=?', [(int) $t['company_doc_id']]) ? (int) $t['company_doc_id'] : null;
        $done = $t['task_type'] === 'ack' && $doc && in_array($doc, $acked, true);
        $tid = DB::insert('hr_process_tasks', ['process_id' => $id, 'user_id' => (int) $emp['id'], 'title' => $t['title'], 'description' => $t['description'], 'task_type' => $t['task_type'], 'owner' => $t['owner'], 'due_date' => date('Y-m-d', strtotime($base.' '.((int) $t['due_days'] >= 0 ? '+' : '').(int) $t['due_days'].' days')),
          'company_doc_id' => $doc, 'position' => $i + 1, 'status' => $done ? 'done' : 'pending', 'done_at' => $done ? self::now() : null]);
        if ($t['task_type'] === 'sign' && $doc) { self::recalc($tid); } }
      if ($kind === 'exit') { self::saveProfile((int) $emp['id'], ['exit_date' => $lwd, 'exit_reason' => mb_substr(self::EXIT_TYPES[$o['exit_type']].(($o['note'] ?? '') ? ': '.$o['note'] : ''), 0, 255)]); }
      Audit::log('hr.process.start', 'employee', (int) $emp['id'], ['process_id' => $id, 'kind' => $kind, 'template' => $tpl['name'], 'last_working_day' => $lwd]);
      return $id; });
    $label = strtolower(self::KINDS[$kind]);
    if (DB::value("SELECT 1 FROM hr_process_tasks WHERE process_id=? AND owner='employee' LIMIT 1", [$id]) && $emp['status'] !== 'inactive') { Notify::user($empId, $kind === 'exit' ? 'Your exit process has started' : 'Welcome! Your onboarding has started', 'Open your '.$label.' to see your tasks and upload the documents HR needs.', '/hr/processes/'.$id, true, $kind === 'exit' ? 'log-out' : 'flag'); }
    if ($emp['manager_id'] && (int) $emp['manager_id'] !== self::me()) { Notify::user((int) $emp['manager_id'], ucfirst($label).' started for '.$emp['name'], 'Some tasks may be yours to complete.', '/hr/processes/'.$id, false, 'clipboard'); }
    return $id; }

  /** pause | resume | complete | reopen | cancel. */
  public static function transition(array $p, string $action, string $reason = ''): string {
    $id = (int) $p['id']; $st = $p['status'];
    $allowed = ['pause' => ['active'], 'resume' => ['paused'], 'complete' => ['active', 'paused'], 'reopen' => ['completed', 'cancelled'], 'cancel' => ['active', 'paused']];
    if (!isset($allowed[$action])) { throw new HttpException(404); }
    if (!in_array($st, $allowed[$action], true)) { throw new ValidationException(['action' => 'This '.strtolower(self::KINDS[$p['kind']]).' is '.self::STATUS[$st][0].'.']); }
    if ($action === 'cancel' && trim($reason) === '') { throw new ValidationException(['reason' => 'Give a reason for cancelling.']); }
    $now = self::now();
    $msg = DB::transaction(static function () use ($p, $id, $action, $reason, $now): string {
      switch ($action) {
        case 'pause': DB::update('hr_processes', ['status' => 'paused', 'paused_at' => $now], 'id=?', [$id]); return 'Paused. The employee can see it but cannot add files until you resume.';
        case 'resume': DB::update('hr_processes', ['status' => 'active', 'paused_at' => null], 'id=?', [$id]); return 'Resumed.';
        case 'complete': DB::update('hr_processes', ['status' => 'completed', 'completed_at' => $now, 'completed_by' => self::me() ?: null], 'id=?', [$id]); return 'Marked complete.';
        case 'reopen':
          if (DB::value("SELECT 1 FROM hr_processes WHERE user_id=? AND kind=? AND status IN ('active','paused') AND id<>? LIMIT 1 FOR UPDATE", [(int) $p['user_id'], $p['kind'], $id])) { throw new ValidationException(['action' => 'Another '.strtolower(self::KINDS[$p['kind']]).' is already in progress for this employee.']); }
          DB::update('hr_processes', ['status' => 'active', 'completed_at' => null, 'completed_by' => null, 'cancelled_at' => null, 'cancelled_by' => null, 'cancel_reason' => null, 'paused_at' => null], 'id=?', [$id]);
          if ($p['kind'] === 'exit' && $p['last_working_day']) { self::saveProfile((int) $p['user_id'], ['exit_date' => $p['last_working_day'], 'exit_reason' => self::EXIT_TYPES[$p['exit_type']] ?? 'Exit']); }
          return 'Reopened.';
        default:
          DB::update('hr_processes', ['status' => 'cancelled', 'cancelled_at' => $now, 'cancelled_by' => self::me() ?: null, 'cancel_reason' => mb_substr(trim($reason), 0, 500)], 'id=?', [$id]);
          if ($p['kind'] === 'exit') { self::clearExit($p); }
          return 'Cancelled. All tasks and files are kept; you can reopen it later.'; } });
    Audit::log('hr.process.'.$action, 'employee', (int) $p['user_id'], ['process_id' => $id, 'kind' => $p['kind'], 'reason' => $reason ?: null]);
    $label = strtolower(self::KINDS[$p['kind']]);
    if ($action === 'cancel') { Notify::user((int) $p['user_id'], 'Your '.$label.' was cancelled', 'HR cancelled it: '.$reason, '/hr/processes/'.$id, false, 'x'); }
    if ($action === 'complete') {
      Notify::user((int) $p['user_id'], 'Your '.$label.' is complete', $p['kind'] === 'exit' ? 'Thank you for your time with us.' : 'All set. Welcome aboard!', '/hr/processes/'.$id, false, 'check-circle');
      if ($p['kind'] === 'exit' && (int) $p['deactivate_on_lwd'] && $p['last_working_day'] && $p['last_working_day'] < date('Y-m-d')) { self::deactivate($p); $msg .= ' The account was deactivated (last working day has passed).'; } }
    return $msg; }

  /** Remove a process started by mistake: only while nobody added a file. */
  public static function remove(array $p): void {
    $n = (int) DB::value('SELECT COUNT(*) FROM hr_uploads h JOIN hr_process_tasks t ON t.id=h.task_id WHERE t.process_id=?', [(int) $p['id']]);
    if ($n) { throw new ValidationException(['action' => 'Files were already added ('.$n.'). Cancel it instead; the data is kept.']); }
    DB::transaction(static function () use ($p): void {
      DB::delete('hr_processes', 'id=?', [(int) $p['id']]);
      if ($p['kind'] === 'exit' && in_array($p['status'], ['active', 'paused', 'completed'], true)) { self::clearExit($p); }
      Audit::log('hr.process.remove', 'employee', (int) $p['user_id'], ['process_id' => (int) $p['id'], 'kind' => $p['kind'], 'template' => $p['template_name']]); }); }

  /** Exit details: last working day (open task due dates move with it), exit type, auto-deactivation, note. */
  public static function updateExit(array $p, array $d): void {
    if ($p['kind'] !== 'exit') { throw new HttpException(404); }
    if (!isset(self::EXIT_TYPES[$d['exit_type'] ?? ''])) { throw new ValidationException(['exit_type' => 'Choose the exit type.']); }
    $joined = DB::value('SELECT joined_on FROM users WHERE id=?', [(int) $p['user_id']]);
    if ($joined && $d['last_working_day'] < $joined) { throw new ValidationException(['last_working_day' => 'Last working day cannot be before the joining date.']); }
    DB::transaction(static function () use ($p, $d): void {
      $shift = (int) round((strtotime($d['last_working_day']) - strtotime((string) $p['last_working_day'])) / 86400);
      if ($shift) { DB::query("UPDATE hr_process_tasks SET due_date=DATE_ADD(due_date, INTERVAL ? DAY), reminded_on=NULL WHERE process_id=? AND status IN ('pending','submitted','rejected') AND due_date IS NOT NULL", [$shift, (int) $p['id']]); }
      DB::update('hr_processes', ['last_working_day' => $d['last_working_day'], 'exit_type' => $d['exit_type'], 'deactivate_on_lwd' => empty($d['deactivate_on_lwd']) ? 0 : 1, 'note' => ($d['note'] ?? null) ?: null], 'id=?', [(int) $p['id']]);
      if (in_array($p['status'], ['active', 'paused', 'completed'], true)) { self::saveProfile((int) $p['user_id'], ['exit_date' => $d['last_working_day'], 'exit_reason' => mb_substr(self::EXIT_TYPES[$d['exit_type']].(($d['note'] ?? '') ? ': '.$d['note'] : ''), 0, 255)]); }
      Audit::log('hr.process.update', 'employee', (int) $p['user_id'], ['process_id' => (int) $p['id'], 'last_working_day' => $d['last_working_day'], 'exit_type' => $d['exit_type'], 'deactivate_on_lwd' => !empty($d['deactivate_on_lwd']), 'due_shift_days' => $shift]); }); }

  private static function clearExit(array $p): void { DB::query('UPDATE employee_profiles SET exit_date=NULL, exit_reason=NULL WHERE user_id=? AND (exit_date=? OR exit_date IS NULL)', [(int) $p['user_id'], $p['last_working_day']]); }

  public static function saveProfile(int $id, array $d): void {
    $cols = array_keys($d);
    DB::query('INSERT INTO employee_profiles (user_id,'.implode(',', array_map([DB::class, 'id'], $cols)).',updated_at) VALUES (?'.str_repeat(',?', count($cols)).',?) ON DUPLICATE KEY UPDATE '.implode(',', array_map(static fn ($c) => DB::id($c).'=VALUES('.DB::id($c).')', $cols)).',updated_at=VALUES(updated_at)', [$id, ...array_values($d), self::now()]); }

  /** Deactivate the leaving employee: signed out everywhere, pending leave cancelled. */
  public static function deactivateUser(int $uid): void {
    DB::transaction(static function () use ($uid): void {
      DB::update('users', ['status' => 'inactive', 'updated_at' => self::now()], 'id=?', [$uid]);
      DB::query("UPDATE leave_requests SET status='cancelled', review_note='Cancelled automatically on exit' WHERE user_id=? AND status='pending'", [$uid]);
      Auth::bumpSessions($uid); }); }

  private static function deactivate(array $p): bool {
    $u = DB::first('SELECT u.status, r.slug FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=?', [(int) $p['user_id']]);
    if (!$u || $u['status'] === 'inactive' || $u['slug'] === 'super_admin') { return false; }
    self::deactivateUser((int) $p['user_id']);
    DB::update('hr_processes', ['deactivated_at' => self::now()], 'id=?', [(int) $p['id']]);
    Audit::log('hr.employee.deactivate', 'employee', (int) $p['user_id'], ['via' => 'exit', 'process_id' => (int) $p['id'], 'last_working_day' => $p['last_working_day']]);
    return true; }

  /* ---------- tasks ---------- */
  public static function requireActive(array $task): array {
    $p = DB::find('hr_processes', (int) $task['process_id']) ?? throw new HttpException(404);
    if ($p['status'] !== 'active') { throw new ValidationException(['task' => 'This '.strtolower(self::KINDS[$p['kind']]).' is '.strtolower(self::STATUS[$p['status']][0]).'; changes are not possible right now.']); }
    return $p; }

  /** Recompute an upload / sign / provide task from its files. Returns the new status. */
  public static function recalc(int $taskId): string {
    $t = DB::find('hr_process_tasks', $taskId);
    if (!$t || $t['status'] === 'skipped' || !in_array($t['task_type'], ['upload', 'sign', 'provide'], true)) { return $t['status'] ?? ''; }
    $sql = "SELECT purpose, status FROM hr_uploads WHERE task_id=?"; $prm = [$taskId];
    if ($t['task_type'] === 'sign' && $t['company_doc_id']) { $sql .= ' OR (task_id IS NULL AND user_id=? AND company_doc_id=?)'; array_push($prm, (int) $t['user_id'], (int) $t['company_doc_id']); }
    $rows = DB::all($sql, $prm);
    $sub = array_column(array_filter($rows, static fn ($r) => $r['purpose'] === 'submission'), 'status');
    $new = $t['task_type'] === 'provide' ? (array_filter($rows, static fn ($r) => $r['purpose'] === 'provided') ? 'done' : 'pending')
      : (in_array('approved', $sub, true) ? 'done' : (in_array('pending', $sub, true) ? 'submitted' : (in_array('rejected', $sub, true) ? 'rejected' : 'pending')));
    if ($new !== $t['status']) { DB::update('hr_process_tasks', ['status' => $new, 'done_at' => $new === 'done' ? self::now() : null, 'done_by' => $new === 'done' ? (self::me() ?: null) : null], 'id=?', [$taskId]); }
    return $new; }

  private static function recalcFor(array $up): void {
    if ($up['task_id']) { self::recalc((int) $up['task_id']); }
    if ($up['company_doc_id']) { foreach (DB::column("SELECT id FROM hr_process_tasks WHERE user_id=? AND company_doc_id=? AND task_type='sign'", [(int) $up['user_id'], (int) $up['company_doc_id']]) as $tid) { self::recalc((int) $tid); } } }

  /** Employee (or HR on their behalf) adds a file to an upload/sign task; HR adds a file for the employee to download ('provided'). */
  public static function addFile(array $task, array $file, bool $provide = false): int {
    $p = self::requireActive($task);
    $emp = (int) $task['user_id']; $me = self::me();
    if ($provide) {
      if (!in_array($task['task_type'], ['provide', 'sign'], true) || !self::isHr() || $me === $emp) { throw new HttpException(403, 'Only HR can add a file for the employee here.'); } }
    else {
      if (!in_array($task['task_type'], ['upload', 'sign'], true)) { throw new ValidationException(['file' => 'This task does not take uploads.']); }
      if ($me !== $emp && !self::canModerate($emp)) { throw new HttpException(403, 'Only the employee or HR can upload here.'); }
      if ($task['status'] === 'skipped') { throw new ValidationException(['file' => 'This task was marked as not needed.']); } }
    $auto = $provide || $me !== $emp;
    $id = DB::transaction(static function () use ($task, $file, $provide, $auto, $emp, $me, $p): int {
      $id = DB::insert('hr_uploads', ['user_id' => $emp, 'task_id' => (int) $task['id'], 'company_doc_id' => $task['task_type'] === 'sign' && !$provide ? ($task['company_doc_id'] ?: null) : null, 'purpose' => $provide ? 'provided' : 'submission', 'uploaded_by' => $me, 'status' => $auto ? 'approved' : 'pending',
        'reviewed_by' => $auto ? $me : null, 'reviewed_at' => $auto ? self::now() : null, 'created_at' => self::now()]);
      $fid = Storage::attach($file, 'hr', 'hr_upload', $id, $p['kind'], self::EXT);
      DB::update('hr_uploads', ['file_id' => $fid, 'original_name' => DB::value('SELECT original_name FROM files WHERE id=?', [$fid])], 'id=?', [$id]);
      self::recalc((int) $task['id']);
      Audit::log('hr.upload.create', 'employee', $emp, ['upload_id' => $id, 'task' => $task['title'], 'purpose' => $provide ? 'provided' : 'submission', 'on_behalf' => $me !== $emp]);
      return $id; });
    $name = (string) DB::value('SELECT name FROM users WHERE id=?', [$emp]);
    if ($provide) { Notify::user($emp, 'HR added a document for you: '.$task['title'], 'Open your '.strtolower(self::KINDS[$p['kind']]).' to download it.', '/hr/processes/'.$p['id'], true, 'download'); }
    elseif ($me === $emp) { self::notifyModerators($emp, $name.' uploaded: '.$task['title'], 'Please review the file.', '/hr/processes/'.$p['id'].'#task-'.$task['id']); }
    return $id; }

  /** Signed copy of a company document uploaded from the documents page. */
  public static function addSignature(array $doc, array $file): int {
    if (!(int) $doc['requires_signature']) { throw new ValidationException(['file' => 'This document does not need a signed copy.']); }
    $me = self::me();
    $id = DB::transaction(static function () use ($doc, $file, $me): int {
      $id = DB::insert('hr_uploads', ['user_id' => $me, 'company_doc_id' => (int) $doc['id'], 'purpose' => 'submission', 'uploaded_by' => $me, 'status' => 'pending', 'created_at' => self::now()]);
      $fid = Storage::attach($file, 'hr', 'hr_upload', $id, 'signed_doc', self::EXT);
      DB::update('hr_uploads', ['file_id' => $fid, 'original_name' => DB::value('SELECT original_name FROM files WHERE id=?', [$fid])], 'id=?', [$id]);
      self::recalcFor(['task_id' => null, 'company_doc_id' => (int) $doc['id'], 'user_id' => $me]);
      Audit::log('hr.company_doc.sign', 'company_doc', (int) $doc['id'], ['upload_id' => $id]);
      return $id; });
    $name = (string) DB::value('SELECT name FROM users WHERE id=?', [$me]);
    foreach (self::moderatorIds($me, true) as $uid) { Notify::user($uid, $name.' uploaded a signed copy: '.$doc['title'], 'Please review the signed document.', '/hr/documents#doc-'.$doc['id'], false, 'file-text'); }
    return $id; }

  /** HR approves or rejects a submitted file. */
  public static function review(array $up, string $decision, string $note = ''): void {
    if (!self::canModerateUpload($up)) { throw new HttpException(403, 'You cannot review this file.'); }
    if ($up['purpose'] !== 'submission') { throw new ValidationException(['decision' => 'Files added by HR do not need a review.']); }
    if (!in_array($decision, ['approve', 'reject'], true)) { throw new ValidationException(['decision' => 'Choose approve or reject.']); }
    if ($decision === 'reject' && trim($note) === '') { throw new ValidationException(['note' => 'Tell the employee what to fix.']); }
    if ($up['task_id']) { $p = DB::first('SELECT p.status FROM hr_processes p JOIN hr_process_tasks t ON t.process_id=p.id WHERE t.id=?', [(int) $up['task_id']]); if ($p && $p['status'] === 'cancelled') { throw new ValidationException(['decision' => 'This process was cancelled.']); } }
    $status = $decision === 'approve' ? 'approved' : 'rejected';
    DB::transaction(static function () use ($up, $status, $note): void {
      DB::update('hr_uploads', ['status' => $status, 'review_note' => trim($note) !== '' ? mb_substr(trim($note), 0, 500) : null, 'reviewed_by' => self::me(), 'reviewed_at' => self::now()], 'id=?', [(int) $up['id']]);
      self::recalcFor($up);
      Audit::log('hr.upload.'.($status === 'approved' ? 'approve' : 'reject'), 'employee', (int) $up['user_id'], ['upload_id' => (int) $up['id'], 'file' => $up['original_name'], 'note' => $note ?: null]); });
    [$title, $url] = self::uploadContext($up);
    Notify::user((int) $up['user_id'], $status === 'approved' ? 'Approved: '.$title : 'Please upload again: '.$title, $status === 'approved' ? 'HR approved '.$up['original_name'].'.' : 'HR rejected '.$up['original_name'].': '.$note, $url, $status !== 'approved', $status === 'approved' ? 'check-circle' : 'alert'); }

  /** Delete an upload: the uploader inside the self-delete window, or HR / the manager at any time. */
  public static function deleteUpload(array $up, string $via = 'direct', string $note = ''): void {
    $self = self::canSelfDelete($up);
    if (!$self && !self::canModerateUpload($up)) {
      $min = self::selfDeleteMinutes();
      throw new HttpException(403, (int) $up['uploaded_by'] === self::me() ? ($up['status'] === 'approved' ? 'This file was already approved. Request deletion and HR will remove it.' : 'Files can be deleted by you only within '.$min.' minutes of uploading. Request deletion and HR will remove it.') : 'You cannot delete this file.'); }
    if ($self && !self::canModerateUpload($up) && $up['task_id']) { self::requireActive(DB::find('hr_process_tasks', (int) $up['task_id']) ?? throw new HttpException(404)); }
    DB::transaction(static function () use ($up, $via, $self, $note): void {
      DB::delete('hr_uploads', 'id=?', [(int) $up['id']]);
      if ($up['file_id']) { Storage::remove((int) $up['file_id']); }
      DB::query("UPDATE hr_delete_requests SET status='approved', decided_by=?, decided_at=?, decision_note=? WHERE upload_id=? AND status='pending'", [self::me(), self::now(), $note !== '' ? mb_substr($note, 0, 500) : 'Deleted', (int) $up['id']]);
      self::recalcFor($up);
      Audit::log('hr.upload.delete', 'employee', (int) $up['user_id'], ['upload_id' => (int) $up['id'], 'file' => $up['original_name'], 'via' => $via, 'by_uploader' => $self]); }); }

  public static function requestDeletion(array $up, string $reason): int {
    if ((int) $up['user_id'] !== self::me() || $up['purpose'] !== 'submission') { throw new HttpException(403, 'You can only request deletion of your own files.'); }
    if (trim($reason) === '') { throw new ValidationException(['reason' => 'Tell HR why the file should be deleted.']); }
    if (self::canSelfDelete($up)) { throw new ValidationException(['reason' => 'You can still delete this file yourself.']); }
    if (DB::value("SELECT 1 FROM hr_delete_requests WHERE upload_id=? AND status='pending'", [(int) $up['id']])) { throw new ValidationException(['reason' => 'A deletion request for this file is already waiting for HR.']); }
    $id = DB::insert('hr_delete_requests', ['upload_id' => (int) $up['id'], 'user_id' => (int) $up['user_id'], 'task_id' => $up['task_id'], 'file_name' => $up['original_name'], 'requested_by' => self::me(), 'reason' => mb_substr(trim($reason), 0, 500), 'status' => 'pending', 'created_at' => self::now()]);
    Audit::log('hr.upload.delete_request', 'employee', (int) $up['user_id'], ['request_id' => $id, 'upload_id' => (int) $up['id'], 'file' => $up['original_name'], 'reason' => $reason]);
    $name = (string) DB::value('SELECT name FROM users WHERE id=?', [(int) $up['user_id']]);
    foreach (self::moderatorIds((int) $up['user_id'], !$up['task_id']) as $uid) { Notify::user($uid, $name.' asks to delete a file', $up['original_name'].': '.$reason, '/hr/processes?view=requests', true, 'trash'); }
    return $id; }

  public static function decideDeletion(array $req, string $decision, string $note = ''): void {
    if ($req['status'] !== 'pending') { throw new ValidationException(['decision' => 'This request was already decided.']); }
    $up = DB::find('hr_uploads', (int) $req['upload_id']);
    $ctx = $up ?? ['user_id' => $req['user_id'], 'task_id' => $req['task_id'], 'company_doc_id' => null];
    if (!self::canModerateUpload($ctx + ['company_doc_id' => null])) { throw new HttpException(403, 'You cannot decide this request.'); }
    if (!in_array($decision, ['approve', 'reject'], true)) { throw new ValidationException(['decision' => 'Choose approve or reject.']); }
    if ($decision === 'approve') {
      if ($up) { self::deleteUpload($up, 'request', $note); }
      DB::update('hr_delete_requests', ['status' => 'approved', 'decided_by' => self::me(), 'decided_at' => self::now(), 'decision_note' => $note !== '' ? mb_substr($note, 0, 500) : 'Deleted'], 'id=?', [(int) $req['id']]); }
    else {
      DB::update('hr_delete_requests', ['status' => 'rejected', 'decided_by' => self::me(), 'decided_at' => self::now(), 'decision_note' => $note !== '' ? mb_substr($note, 0, 500) : null], 'id=?', [(int) $req['id']]); }
    Audit::log('hr.upload.delete_request.'.($decision === 'approve' ? 'approve' : 'reject'), 'employee', (int) $req['user_id'], ['request_id' => (int) $req['id'], 'file' => $req['file_name'], 'note' => $note ?: null]);
    [, $url] = $up ? self::uploadContext($up) : ['', '/hr/me'];
    Notify::user((int) $req['requested_by'], $decision === 'approve' ? 'File deleted: '.$req['file_name'] : 'Deletion request declined: '.$req['file_name'], $decision === 'approve' ? 'HR deleted the file as you asked. You can upload the right one now.' : 'HR kept the file.'.($note !== '' ? ' Note: '.$note : ''), $url, false, 'trash'); }

  public static function acknowledge(array $task): void {
    self::requireActive($task);
    if ($task['task_type'] !== 'ack') { throw new ValidationException(['task' => 'This task is not an acknowledgement.']); }
    if ((int) $task['user_id'] !== self::me()) { throw new HttpException(403, 'Only the employee can acknowledge.'); }
    if ($task['status'] === 'done') { return; }
    DB::transaction(static function () use ($task): void {
      if ($task['company_doc_id']) { DB::query('INSERT IGNORE INTO company_document_acks (document_id, user_id, acknowledged_at) VALUES (?,?,?)', [(int) $task['company_doc_id'], self::me(), self::now()]); }
      DB::update('hr_process_tasks', ['status' => 'done', 'done_at' => self::now(), 'done_by' => self::me()], 'id=?', [(int) $task['id']]);
      Audit::log('hr.task.ack', 'employee', (int) $task['user_id'], ['task' => $task['title'], 'company_doc_id' => $task['company_doc_id']]); }); }

  /** Acknowledging a document on the documents page completes matching open acknowledgement tasks. */
  public static function syncAck(int $uid, int $docId): void {
    DB::query("UPDATE hr_process_tasks t JOIN hr_processes p ON p.id=t.process_id SET t.status='done', t.done_at=?, t.done_by=? WHERE t.user_id=? AND t.company_doc_id=? AND t.task_type='ack' AND t.status IN ('pending','rejected') AND p.status='active'", [self::now(), $uid, $uid, $docId]); }

  public static function toggle(array $task): bool {
    self::requireActive($task);
    if ($task['task_type'] !== 'task') { throw new ValidationException(['task' => 'This task completes through its files or acknowledgement.']); }
    if (!self::canAct($task)) { throw new HttpException(403, 'This task belongs to '.self::OWNERS[$task['owner']].'.'); }
    $done = $task['status'] !== 'done';
    DB::update('hr_process_tasks', ['status' => $done ? 'done' : 'pending', 'done_at' => $done ? self::now() : null, 'done_by' => $done ? self::me() : null], 'id=?', [(int) $task['id']]);
    Audit::log('hr.task.'.($done ? 'done' : 'undone'), 'employee', (int) $task['user_id'], ['task' => $task['title'], 'process_id' => (int) $task['process_id']]);
    return $done; }

  public static function skip(array $task): bool {
    self::requireActive($task);
    $skip = $task['status'] !== 'skipped';
    DB::update('hr_process_tasks', ['status' => $skip ? 'skipped' : 'pending', 'done_at' => $skip ? self::now() : null, 'done_by' => $skip ? self::me() : null], 'id=?', [(int) $task['id']]);
    if (!$skip) { self::recalc((int) $task['id']); }
    Audit::log('hr.task.'.($skip ? 'skip' : 'unskip'), 'employee', (int) $task['user_id'], ['task' => $task['title'], 'process_id' => (int) $task['process_id']]);
    return $skip; }

  /* ---------- notifications ---------- */
  /** HR (and the direct manager with the team permission), never the employee. $docs adds company document managers. */
  public static function moderatorIds(int $empId, bool $docs = false): array {
    $ids = array_map('intval', array_column(Gate::usersWith('hr.employees.manage'), 'id'));
    if ($docs) { $ids = array_merge($ids, array_map('intval', array_column(Gate::usersWith('hr.company_docs.manage'), 'id'))); }
    $mgr = (int) DB::value('SELECT manager_id FROM users WHERE id=?', [$empId]);
    if ($mgr && in_array($mgr, array_map('intval', array_column(Gate::usersWith('hr.employees.review_team'), 'id')), true)) { $ids[] = $mgr; }
    return array_values(array_diff(array_unique($ids), [$empId, self::me()])); }

  private static function notifyModerators(int $empId, string $title, string $body, string $url): void { foreach (self::moderatorIds($empId) as $uid) { Notify::user($uid, $title, $body, $url, false, 'upload'); } }

  /** [title, url] for notifications about an upload. */
  private static function uploadContext(array $up): array {
    if ($up['task_id'] && ($t = DB::first('SELECT title, process_id FROM hr_process_tasks WHERE id=?', [(int) $up['task_id']]))) { return [$t['title'], '/hr/processes/'.$t['process_id'].'#task-'.$up['task_id']]; }
    $doc = $up['company_doc_id'] ? DB::value('SELECT title FROM company_documents WHERE id=?', [(int) $up['company_doc_id']]) : null;
    return [$doc ? 'signed '.$doc : (string) $up['original_name'], $up['company_doc_id'] ? '/hr/documents#doc-'.$up['company_doc_id'] : '/hr/me']; }

  /* ---------- cron ---------- */
  /** Daily: remind task owners of tasks due tomorrow, today, or overdue (overdue again every 3 days). */
  public static function reminders(): string {
    $today = date('Y-m-d');
    $rows = DB::all("SELECT t.id, t.title, t.owner, t.due_date, t.process_id, p.kind, p.user_id, u.name, u.manager_id FROM hr_process_tasks t JOIN hr_processes p ON p.id=t.process_id JOIN users u ON u.id=p.user_id WHERE p.status='active' AND t.status IN ('pending','rejected') AND t.due_date IS NOT NULL AND t.due_date<=? AND (t.reminded_on IS NULL OR (t.due_date<? AND t.reminded_on<=?)) AND u.status<>'inactive'",
      [date('Y-m-d', strtotime('+1 day')), $today, date('Y-m-d', strtotime('-3 days'))]);
    if (!$rows) { return 'nothing due'; }
    $emp = []; $mgr = []; $hr = 0;
    foreach ($rows as $r) {
      if ($r['owner'] === 'employee') { $emp[(int) $r['user_id']][(int) $r['process_id']][] = $r; }
      elseif ($r['owner'] === 'manager' && $r['manager_id']) { $mgr[(int) $r['manager_id']][] = $r; }
      else { $hr++; } }
    foreach ($emp as $uid => $procs) { foreach ($procs as $pid => $list) { $over = count(array_filter($list, static fn ($r) => $r['due_date'] < $today));
      Notify::user($uid, count($list).' '.self::KINDS[$list[0]['kind']].' task'.(count($list) === 1 ? '' : 's').($over ? ' overdue' : ' due soon'), implode(', ', array_map(static fn ($r) => $r['title'], array_slice($list, 0, 4))).(count($list) > 4 ? '…' : ''), '/hr/processes/'.$pid, true, 'clock'); } }
    foreach ($mgr as $uid => $list) { Notify::user($uid, count($list).' onboarding/exit task'.(count($list) === 1 ? '' : 's').' for your team due', implode(', ', array_map(static fn ($r) => $r['title'].' ('.$r['name'].')', array_slice($list, 0, 3))), '/hr/processes?view=due', false, 'clock'); }
    if ($hr) { foreach (Gate::usersWith('hr.employees.manage') as $u) { Notify::user((int) $u['id'], $hr.' onboarding/exit task'.($hr === 1 ? '' : 's').' due or overdue', 'HR and IT tasks waiting.', '/hr/processes?view=due', false, 'clock'); } }
    DB::query('UPDATE hr_process_tasks SET reminded_on=? WHERE id IN '.DB::in($ids = array_map('intval', array_column($rows, 'id'))), [$today, ...$ids]);
    return count($rows).' task reminder(s)'; }

  /** Daily late evening: deactivate leaving employees whose last working day is today or past. */
  public static function autoDeactivate(): string {
    $n = 0;
    foreach (DB::all("SELECT p.* FROM hr_processes p JOIN users u ON u.id=p.user_id WHERE p.kind='exit' AND p.status IN ('active','paused','completed') AND p.deactivate_on_lwd=1 AND p.deactivated_at IS NULL AND p.last_working_day<=? AND u.status<>'inactive'", [date('Y-m-d')]) as $p) {
      if (self::deactivate($p)) { $n++; } }
    return $n.' account(s) deactivated'; } }
