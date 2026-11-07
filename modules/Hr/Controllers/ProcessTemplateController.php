<?php
namespace Modules\Hr\Controllers;
use Core\{Audit, Controller, DB, Response, ValidationException};
use Modules\Hr\Services\ProcessService as PS;

/** HR: onboarding and exit templates (named, one default per kind) and their tasks. Started processes keep their own copy. */
final class ProcessTemplateController extends Controller {
  public function index(): Response {
    $all = PS::templates();
    $sel = $this->request->int('id') ?: (int) ($all[0]['id'] ?? 0);
    $tpl = $sel ? DB::find('hr_templates', $sel) : null;
    return $this->view('hr::checklists/templates', ['title' => 'Onboarding & exit templates', 'all' => $all, 'tpl' => $tpl, 'tasks' => $tpl ? DB::all('SELECT t.*, d.title AS doc_title FROM hr_template_tasks t LEFT JOIN company_documents d ON d.id=t.company_doc_id WHERE t.template_id=? ORDER BY t.position, t.id', [(int) $tpl['id']]) : [],
      'companyDocs' => DB::pairs('SELECT id, title FROM company_documents ORDER BY title'), 'styles' => ['css/hr.css'], 'scripts' => ['js/hr.js']]); }

  private function tpl(int $id): array { return $this->findOr404('hr_templates', $id); }
  private function to(int $id, string $hash = ''): Response { return $this->redirect('/hr/process-templates?id='.$id.$hash); }

  public function store(): Response {
    $d = $this->validate(['kind' => 'required|in:onboarding,exit', 'name' => 'required|max:150', 'description' => 'nullable|max:500', 'copy_from' => 'nullable|integer']);
    $id = DB::transaction(static function () use ($d): int {
      $first = !DB::value('SELECT 1 FROM hr_templates WHERE kind=? LIMIT 1', [$d['kind']]);
      $id = DB::insert('hr_templates', ['kind' => $d['kind'], 'name' => $d['name'], 'description' => $d['description'], 'is_default' => $first ? 1 : 0, 'created_at' => date('Y-m-d H:i:s')]);
      if ($d['copy_from'] && DB::value('SELECT 1 FROM hr_templates WHERE id=? AND kind=?', [$d['copy_from'], $d['kind']])) {
        DB::query('INSERT INTO hr_template_tasks (template_id, title, description, task_type, owner, due_days, company_doc_id, position) SELECT ?, title, description, task_type, owner, due_days, company_doc_id, position FROM hr_template_tasks WHERE template_id=?', [$id, $d['copy_from']]); }
      Audit::log('hr.template.create', 'hr_template', $id, ['name' => $d['name'], 'kind' => $d['kind']]);
      return $id; });
    $this->flash('Template created.');
    return $this->to($id); }

  public function update(int $id): Response {
    $t = $this->tpl($id);
    $d = $this->validate(['name' => 'required|max:150', 'description' => 'nullable|max:500', 'is_default' => 'boolean']);
    DB::transaction(static function () use ($t, $d, $id): void {
      if ($d['is_default']) { DB::update('hr_templates', ['is_default' => 0], 'kind=?', [$t['kind']]); }
      DB::update('hr_templates', ['name' => $d['name'], 'description' => $d['description']] + ($d['is_default'] ? ['is_default' => 1] : []), 'id=?', [$id]);
      Audit::log('hr.template.update', 'hr_template', $id, $d); });
    $this->flash('Template saved.');
    return $this->to($id); }

  public function destroy(int $id): Response {
    $t = $this->tpl($id);
    if ((int) $t['is_default']) { throw new ValidationException(['template' => 'Make another template the default first.']); }
    DB::delete('hr_templates', 'id=?', [$id]);
    Audit::log('hr.template.delete', 'hr_template', $id, ['name' => $t['name']]);
    $this->flash('Template deleted. Processes already started are not affected.');
    return $this->redirect('/hr/process-templates'); }

  private function taskInput(): array {
    $d = $this->validate(['title' => 'required|max:190', 'description' => 'nullable|max:1000', 'task_type' => 'required|in:'.implode(',', array_keys(PS::TYPES)), 'owner' => 'required|in:'.implode(',', array_keys(PS::OWNERS)), 'due_days' => 'required|integer|min:-365|max:365', 'company_doc_id' => 'nullable|integer'], ['task_type' => 'Type', 'due_days' => 'Due (days)', 'company_doc_id' => 'Company document']);
    $d['company_doc_id'] = $d['company_doc_id'] && in_array($d['task_type'], ['sign', 'ack'], true) && DB::value('SELECT 1 FROM company_documents WHERE id=?', [(int) $d['company_doc_id']]) ? (int) $d['company_doc_id'] : null;
    if (in_array($d['task_type'], ['upload', 'sign', 'ack'], true)) { $d['owner'] = 'employee'; }
    if ($d['task_type'] === 'provide') { $d['owner'] = 'hr'; }
    return $d; }

  public function storeTask(int $id): Response {
    $this->tpl($id);
    $d = $this->taskInput();
    $tid = DB::insert('hr_template_tasks', $d + ['template_id' => $id, 'position' => (int) DB::value('SELECT COALESCE(MAX(position),0)+1 FROM hr_template_tasks WHERE template_id=?', [$id])]);
    Audit::log('hr.template.task_create', 'hr_template', $id, ['title' => $d['title'], 'type' => $d['task_type']]);
    $this->flash('Task added to the template.');
    return $this->to($id, '#tt-'.$tid); }

  public function updateTask(int $id): Response {
    $t = $this->findOr404('hr_template_tasks', $id);
    $d = $this->taskInput();
    DB::update('hr_template_tasks', $d, 'id=?', [$id]);
    Audit::log('hr.template.task_update', 'hr_template', (int) $t['template_id'], ['task_id' => $id, 'title' => $d['title']]);
    $this->flash('Task saved.');
    return $this->to((int) $t['template_id'], '#tt-'.$id); }

  public function destroyTask(int $id): Response {
    $t = $this->findOr404('hr_template_tasks', $id);
    DB::delete('hr_template_tasks', 'id=?', [$id]);
    Audit::log('hr.template.task_delete', 'hr_template', (int) $t['template_id'], ['title' => $t['title']]);
    $this->flash('Task removed from the template.');
    return $this->to((int) $t['template_id']); }

  public function moveTask(int $id): Response {
    $t = $this->findOr404('hr_template_tasks', $id);
    $dir = $this->request->str('dir') === 'up' ? -1 : 1;
    DB::transaction(static function () use ($t, $dir): void {
      $ids = array_map('intval', DB::column('SELECT id FROM hr_template_tasks WHERE template_id=? ORDER BY position, id FOR UPDATE', [(int) $t['template_id']]));
      $i = array_search((int) $t['id'], $ids, true); $j = $i + $dir;
      if ($i === false || $j < 0 || $j >= count($ids)) { return; }
      [$ids[$i], $ids[$j]] = [$ids[$j], $ids[$i]];
      foreach ($ids as $pos => $tid) { DB::update('hr_template_tasks', ['position' => $pos + 1], 'id=?', [$tid]); } });
    return $this->to((int) $t['template_id'], '#tt-'.$id); } }
