<?php
namespace Modules\Ai\Controllers;
use Core\{Audit, Controller, DB, Response, ValidationException};
use Modules\Ai\Services\Scope;

/** The user's own AI actions; pending task proposals are approved (optionally edited) or rejected here. */
final class ActionController extends Controller {
  public const TYPES = ['create_tasks' => ['Create tasks', 'check-square', 'lemon'], 'save_journey' => ['Journey entry', 'message', 'mint'], 'send_reminder' => ['Payment reminder', 'mail', 'sky']];

  private function own(int $id): array {
    $a = DB::first('SELECT * FROM ai_actions WHERE id=? AND user_id=?', [$id, $this->uid()]) ?? $this->abort(404, 'Action not found.');
    $a['data'] = json_decode((string) $a['payload'], true) ?: [];
    return $a; }

  public function index(): Response {
    $st = $this->request->str('status');
    $sql = 'SELECT id, type, title, status, result, created_at, decided_at FROM ai_actions WHERE user_id=?';
    $p = [$this->uid()];
    if (in_array($st, ['pending', 'executed', 'rejected', 'failed'], true)) { $sql .= ' AND status=?'; $p[] = $st; }
    $counts = DB::pairs('SELECT status, COUNT(*) FROM ai_actions WHERE user_id=? GROUP BY status', [$this->uid()]);
    return $this->view('ai::actions/index', ['title' => 'AI actions', 'p' => DB::paginate($sql." ORDER BY status='pending' DESC, id DESC", $p, 20), 'counts' => $counts, 'status' => $st, 'styles' => ['css/ai.css']]); }

  public function show(int $id): Response {
    $a = $this->own($id);
    $canApprove = false;
    if ($a['type'] === 'create_tasks' && $a['status'] === 'pending') { $canApprove = Scope::can('projects.tasks.manage', $this->user()) && Scope::canSeeProject($this->user(), (int) ($a['data']['project_id'] ?? 0)); }
    return $this->view('ai::actions/show', ['title' => $a['title'], 'a' => $a, 'canApprove' => $canApprove, 'styles' => ['css/ai.css'], 'scripts' => ['js/ai.js']]); }

  public function approve(int $id): Response {
    $u = $this->user();
    $a = $this->own($id);
    if ($a['type'] !== 'create_tasks' || $a['status'] !== 'pending') { $this->abort(422, 'This action is no longer pending.'); }
    $pid = (int) ($a['data']['project_id'] ?? 0);
    if (!Scope::can('projects.tasks.manage', $u) || !Scope::canSeeProject($u, $pid)) { $this->abort(403, 'You can no longer create tasks in this project.'); }
    $tasks = []; $errors = [];
    foreach ($this->request->arr('tasks') as $i => $t) {
      if (!is_array($t) || empty($t['include'])) { continue; }
      $title = trim((string) ($t['title'] ?? ''));
      $due = trim((string) ($t['due_date'] ?? ''));
      $prio = (string) ($t['priority'] ?? 'medium');
      if ($title === '' || mb_strlen($title) > 190) { $errors['tasks'] = 'Every selected task needs a title (max 190 characters).'; continue; }
      if ($due !== '' && !(($dt = \DateTime::createFromFormat('!Y-m-d', $due)) && $dt->format('Y-m-d') === $due)) { $errors['tasks'] = 'Due dates must be valid dates.'; continue; }
      $tasks[] = ['title' => $title, 'description' => mb_substr(trim((string) ($t['description'] ?? '')), 0, 5000) ?: null, 'priority' => in_array($prio, ['low', 'medium', 'high', 'urgent'], true) ? $prio : 'medium', 'due_date' => $due ?: null];
      if (count($tasks) > 30) { break; } }
    if (!$tasks && !$errors) { $errors['tasks'] = 'Select at least one task to create, or reject the suggestion.'; }
    if ($errors) { throw new ValidationException($errors); }
    $now = date('Y-m-d H:i:s');
    try {
      $ids = DB::transaction(static function () use ($a, $pid, $tasks, $u, $now) {
        if (DB::value('SELECT status FROM ai_actions WHERE id=? FOR UPDATE', [$a['id']]) !== 'pending') { throw new \RuntimeException('Already decided'); }
        $pos = (int) DB::value('SELECT COALESCE(MAX(position),0) FROM tasks WHERE project_id=?', [$pid]);
        $ids = [];
        foreach ($tasks as $t) { $ids[] = DB::insert('tasks', $t + ['project_id' => $pid, 'status' => 'todo', 'position' => ++$pos, 'billable' => 1, 'created_by' => $u['id'], 'created_at' => $now, 'updated_at' => $now]); }
        $payload = $a['data']; $payload['approved_tasks'] = $tasks; $payload['created_task_ids'] = $ids;
        DB::update('ai_actions', ['status' => 'executed', 'result' => 'Created '.count($ids).' task'.(count($ids) === 1 ? '' : 's'), 'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE), 'decided_by' => $u['id'], 'decided_at' => $now], 'id=?', [$a['id']]);
        return $ids; }); }
    catch (\Throwable $e) {
      if ($e->getMessage() === 'Already decided') { $this->abort(422, 'This action was already decided.'); }
      error_log('AI approve '.$id.': '.$e->getMessage());
      DB::update('ai_actions', ['status' => 'failed', 'result' => 'Could not create tasks: '.mb_substr($e->getMessage(), 0, 200), 'decided_by' => $u['id'], 'decided_at' => $now], 'id=? AND status=\'pending\'', [$a['id']]);
      Audit::log('ai.tasks.failed', 'ai_action', $id);
      $this->flash('The tasks could not be created. Nothing was saved.', 'error');
      return $this->redirect('/ai/actions/'.$id); }
    Audit::log('ai.tasks.approved', 'project', $pid, ['ai_action_id' => $id, 'task_ids' => $ids]);
    $this->flash(count($ids).' task'.(count($ids) === 1 ? '' : 's').' created.');
    return $this->redirect('/ai/actions/'.$id); }

  public function reject(int $id): Response {
    $a = $this->own($id);
    if ($a['status'] !== 'pending') { $this->abort(422, 'This action is no longer pending.'); }
    DB::update('ai_actions', ['status' => 'rejected', 'result' => 'Rejected by user', 'decided_by' => $this->uid(), 'decided_at' => date('Y-m-d H:i:s')], 'id=? AND status=\'pending\'', [$id]);
    Audit::log('ai.action.rejected', 'ai_action', $id, ['type' => $a['type']]);
    $this->flash('Suggestion rejected. Nothing was created.');
    return $this->redirect('/ai/actions'); } }
