<?php
namespace Modules\Projects\Controllers;
use Core\{Audit, Controller, DB, Response};
use Modules\Projects\Services\{ProjectAccess as PA, ProjectService as PS};

/** Milestones CRUD (projects.manage within visible projects). */
final class MilestoneController extends Controller {
  private function project(int $pid): void { if (!PA::canManageProject($this->user(), $pid)) { $this->abort(404, 'Project not found.'); } }

  private function milestone(int $id): array {
    $m = DB::find('milestones', $id) ?? $this->abort(404, 'Milestone not found.');
    $this->project((int) $m['project_id']);
    return $m; }

  private function data(): array {
    $d = $this->validate(['title' => 'required|max:190', 'description' => 'nullable|max:5000', 'due_date' => 'nullable|date', 'amount' => 'nullable|numeric|min:0|max:999999999999', 'status' => 'required|in:pending,in_progress,completed']);
    if (isset($d['amount']) && $d['amount'] === '') { $d['amount'] = null; }
    return $d; }

  public function store(int $id): Response {
    $this->project($id);
    $d = $this->data();
    $mid = DB::insert('milestones', $d + ['project_id' => $id, 'completed_at' => $d['status'] === 'completed' ? date('Y-m-d H:i:s') : null, 'position' => (int) DB::value('SELECT IFNULL(MAX(position),0)+1 FROM milestones WHERE project_id=?', [$id])]);
    Audit::log('projects.milestone.create', 'project', $id, ['milestone_id' => $mid, 'title' => $d['title']]);
    $this->flash('Milestone added.');
    return $this->redirect('/projects/'.$id.'?tab=milestones'); }

  public function update(int $id): Response {
    $m = $this->milestone($id);
    $d = $this->data();
    $d['completed_at'] = $d['status'] === 'completed' ? ($m['completed_at'] ?? date('Y-m-d H:i:s')) : null;
    DB::update('milestones', $d, 'id=?', [$id]);
    Audit::log('projects.milestone.update', 'project', (int) $m['project_id'], ['milestone_id' => $id]);
    $this->flash('Milestone updated.');
    return $this->redirect('/projects/'.$m['project_id'].'?tab=milestones'); }

  public function complete(int $id): Response {
    $m = $this->milestone($id);
    $done = $m['status'] !== 'completed';
    if ($done && ($open = (int) DB::value("SELECT COUNT(*) FROM tasks WHERE milestone_id=? AND parent_id IS NULL AND status<>'done'", [$id])) && !$this->request->int('force')) {
      $this->flash("This milestone still has $open open task(s). Complete them first.", 'error');
      return $this->redirect(PS::returnPath($this->request, '/projects/'.$m['project_id'].'?tab=milestones')); }
    DB::update('milestones', ['status' => $done ? 'completed' : 'in_progress', 'completed_at' => $done ? date('Y-m-d H:i:s') : null], 'id=?', [$id]);
    Audit::log('projects.milestone.'.($done ? 'complete' : 'reopen'), 'project', (int) $m['project_id'], ['milestone_id' => $id]);
    $this->flash($done ? 'Milestone completed 🎉' : 'Milestone reopened.');
    return $this->redirect(PS::returnPath($this->request, '/projects/'.$m['project_id'].'?tab=milestones')); }

  public function destroy(int $id): Response {
    $m = $this->milestone($id);
    DB::delete('milestones', 'id=?', [$id]);
    Audit::log('projects.milestone.delete', 'project', (int) $m['project_id'], ['milestone_id' => $id, 'title' => $m['title']]);
    $this->flash('Milestone deleted.');
    return $this->redirect('/projects/'.$m['project_id'].'?tab=milestones'); } }
