<?php
namespace Modules\Projects\Controllers;
use Core\{Audit, Controller, DB, Notify, Response};
use Modules\Projects\Services\ProjectAccess as PA;

/** Change requests: log scope changes with cost / hours / deadline impact and decide on them. */
final class ChangeRequestController extends Controller {
  private function project(int $pid): array {
    if (!PA::canSee($this->user(), $pid)) { $this->abort(404, 'Project not found.'); }
    return DB::find('projects', $pid); }

  private function notifyManager(array $p, string $title, string $body): void {
    if ($p['manager_id'] && (int) $p['manager_id'] !== $this->uid()) { Notify::user((int) $p['manager_id'], $title, $body, '/projects/'.$p['id'].'?tab=changes', true, 'flag'); } }

  public function store(int $id): Response {
    $p = $this->project($id);
    $d = $this->validate(['title' => 'required|max:190', 'description' => 'nullable|max:10000', 'requested_by' => 'nullable|max:150', 'requested_on' => 'nullable|date', 'cost_impact' => 'nullable|numeric|min:-999999999999|max:999999999999', 'hours_impact' => 'nullable|numeric|min:-999999|max:999999', 'deadline_impact_days' => 'nullable|integer|min:-3650|max:3650']);
    $d['requested_on'] ??= date('Y-m-d');
    $cid = DB::insert('change_requests', $d + ['project_id' => $id, 'status' => 'pending', 'created_by' => $this->uid(), 'created_at' => date('Y-m-d H:i:s')]);
    Audit::log('projects.cr.create', 'project', $id, ['change_request_id' => $cid, 'title' => $d['title']]);
    $this->notifyManager($p, 'New change request on '.$p['name'], $d['title']);
    $this->flash('Change request logged.');
    return $this->redirect('/projects/'.$id.'?tab=changes'); }

  /** pending → approved | rejected; approved → implemented. Approving can apply the impact to the project. */
  public function decide(int $id): Response {
    $cr = DB::find('change_requests', $id) ?? $this->abort(404, 'Change request not found.');
    $p = $this->project((int) $cr['project_id']);
    $to = $this->request->str('status');
    $allowed = ['pending' => ['approved', 'rejected'], 'approved' => ['implemented', 'rejected'], 'rejected' => ['pending'], 'implemented' => []][$cr['status']] ?? [];
    if (!in_array($to, $allowed, true)) { $this->flash('That status change is not allowed.', 'error'); return $this->redirect('/projects/'.$p['id'].'?tab=changes'); }
    $apply = $to === 'approved' && $this->request->int('apply') === 1 && PA::canManageProject($this->user(), (int) $p['id']);
    DB::transaction(function () use ($cr, $p, $to, $apply, $id) {
      DB::update('change_requests', ['status' => $to, 'decided_by' => $this->uid(), 'decided_at' => date('Y-m-d H:i:s')], 'id=?', [$id]);
      if ($apply) {
        $upd = ['updated_at' => date('Y-m-d H:i:s')];
        if ($cr['cost_impact'] !== null && $p['budget'] !== null) { $upd['budget'] = max(0, (float) $p['budget'] + (float) $cr['cost_impact']); }
        if ($cr['hours_impact'] !== null) { $upd['estimated_hours'] = max(0, (float) $p['estimated_hours'] + (float) $cr['hours_impact']); }
        if ($cr['deadline_impact_days'] && $p['deadline']) { $upd['deadline'] = date('Y-m-d', strtotime($p['deadline'].' '.((int) $cr['deadline_impact_days'] >= 0 ? '+' : '').(int) $cr['deadline_impact_days'].' days')); }
        DB::update('projects', $upd, 'id=?', [(int) $p['id']]); } });
    Audit::log('projects.cr.'.$to, 'project', (int) $p['id'], ['change_request_id' => $id, 'applied' => $apply]);
    $this->notifyManager($p, 'Change request '.$to.': '.$cr['title'], 'Project: '.$p['name']);
    if ($cr['created_by'] && (int) $cr['created_by'] !== $this->uid() && (int) $cr['created_by'] !== (int) $p['manager_id']) { Notify::user((int) $cr['created_by'], 'Change request '.$to.': '.$cr['title'], 'Project: '.$p['name'], '/projects/'.$p['id'].'?tab=changes', false, 'flag'); }
    $this->flash('Change request marked '.$to.($apply ? ' and applied to the project plan.' : '.'));
    return $this->redirect('/projects/'.$p['id'].'?tab=changes'); }

  public function destroy(int $id): Response {
    $cr = DB::find('change_requests', $id) ?? $this->abort(404, 'Change request not found.');
    $this->project((int) $cr['project_id']);
    if ($cr['status'] !== 'pending') { $this->flash('Only pending change requests can be deleted.', 'error'); return $this->redirect('/projects/'.$cr['project_id'].'?tab=changes'); }
    DB::delete('change_requests', 'id=?', [$id]);
    Audit::log('projects.cr.delete', 'project', (int) $cr['project_id'], ['change_request_id' => $id]);
    $this->flash('Change request deleted.');
    return $this->redirect('/projects/'.$cr['project_id'].'?tab=changes'); } }
