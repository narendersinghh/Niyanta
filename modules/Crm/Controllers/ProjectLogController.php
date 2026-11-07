<?php
namespace Modules\Crm\Controllers;
use Core\{Audit, DB, Response};
use Modules\Crm\Services\ProjectLog;

/** Client log entries written from the project page (POST /projects/{id}/activities...). Always returns to the project's Client log tab. */
final class ProjectLogController extends CrmController {
  /** Project whose log the user may write (404 otherwise, hidden projects look missing). */
  private function project(int $id): array {
    $pr = DB::first('SELECT id, name, client_id FROM projects WHERE id=?', [$id]);
    if (!$pr || !ProjectLog::access($this->user(), $pr)[1]) { $this->abort(404, 'Project not found.'); }
    return $pr; }

  private function entry(int $pid, int $activityId): array { return DB::first('SELECT * FROM client_activities WHERE id=? AND project_id=?', [$activityId, $pid]) ?? $this->abort(404, 'Entry not found.'); }
  private function toLog(int $pid): Response { return $this->redirect('/projects/'.$pid.'?tab=log'); }

  public function save(int $id, ?int $activityId = null): Response {
    $pr = $this->project($id);
    $old = $activityId ? $this->entry($id, $activityId) : null;
    $d = $this->validate(...JourneyController::activityRules());
    $d['project_id'] = $id;
    if ($d['type'] === 'note') { $d['is_internal'] = 1; }
    $mt = $this->meetingInput($d['type']);
    if ($mt && ($dup = $this->meetingDuplicate($mt, $activityId))) { return $dup; }
    if ($old) {
      if (!$d['follow_up_at']) { $d['follow_up_done'] = 0; }
      DB::update('client_activities', $d, 'id=?', [$activityId]); }
    else { $activityId = DB::insert('client_activities', $d + ['client_id' => $pr['client_id'] ?: null, 'user_id' => $this->uid(), 'created_at' => $this->now()]); }
    $cid = $old ? ($old['client_id'] ? (int) $old['client_id'] : null) : ($pr['client_id'] ? (int) $pr['client_id'] : null);
    Audit::log('crm.activity.save', $cid ? 'client' : 'project', $cid ?: $id, ['activity_id' => $activityId, 'type' => $d['type'], 'project_id' => $id, 'from' => 'project']);
    $this->flash('Client log updated.'.($mt ? ' '.$this->meetingSave($mt, $activityId, $cid, $id) : ''));
    return $this->toLog($id); }

  public function delete(int $id, int $activityId): Response {
    $this->project($id);
    $a = $this->entry($id, $activityId);
    DB::delete('client_activities', 'id=?', [$activityId]);
    Audit::log('crm.activity.delete', $a['client_id'] ? 'client' : 'project', $a['client_id'] ? (int) $a['client_id'] : $id, ['activity_id' => $activityId, 'project_id' => $id]);
    $this->flash('Entry deleted.');
    return $this->toLog($id); }

  public function done(int $id, int $activityId): Response {
    $this->project($id);
    $a = $this->entry($id, $activityId);
    if ($a['follow_up_at']) { DB::update('client_activities', ['follow_up_done' => 1], 'id=?', [$activityId]); }
    Audit::log('crm.followup.done', $a['client_id'] ? 'client' : 'project', $a['client_id'] ? (int) $a['client_id'] : $id, ['activity_id' => $activityId]);
    if ($this->request->wantsJson()) { return $this->json(['message' => 'Follow-up completed.']); }
    $this->flash('Follow-up marked as done.');
    return $this->toLog($id); } }
