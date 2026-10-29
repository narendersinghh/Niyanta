<?php
namespace Modules\Crm\Controllers;
use Core\{Audit, DB, Modules, Response, Storage, ValidationException};

/** Client sub-records: contacts, journey (activities & follow-ups) and documents. Every action re-checks client visibility. */
final class JourneyController extends CrmController {
  public const TYPES = ['meeting' => ['Meeting', 'users', 'lav'], 'call' => ['Call', 'phone', 'mint'], 'discussion' => ['Discussion', 'message', 'sky'], 'email' => ['Email', 'mail', 'peach'],
    'report' => ['Report shared', 'file-text', 'lemon'], 'requirement' => ['New requirement', 'flag', 'pink'], 'follow_up' => ['Follow-up', 'clock', 'lemon'], 'note' => ['Internal note', 'edit', 'lav'],
    'assignment' => ['Team assignment', 'user-plus', 'sky'], 'payment' => ['Payment', 'wallet', 'mint'], 'other' => ['Other', 'star', 'peach']];

  public function saveContact(int $id, ?int $contactId = null): Response {
    $this->client($id);
    if ($contactId && !DB::value('SELECT 1 FROM client_contacts WHERE id=? AND client_id=?', [$contactId, $id])) { $this->abort(404, 'Contact not found.'); }
    $d = $this->validate(['name' => 'required|max:150', 'designation' => 'nullable|max:120', 'email' => 'nullable|email|max:190', 'phone' => 'nullable|phone', 'is_primary' => 'boolean']);
    DB::transaction(static function () use ($id, $contactId, &$d) {
      if ($d['is_primary']) { DB::update('client_contacts', ['is_primary' => 0], 'client_id=?', [$id]); }
      if ($contactId) { DB::update('client_contacts', $d, 'id=? AND client_id=?', [$contactId, $id]); }
      else { $contactId = DB::insert('client_contacts', $d + ['client_id' => $id]); } });
    Audit::log('crm.contact.save', 'client', $id, ['contact_id' => $contactId, 'name' => $d['name']]);
    $this->flash('Contact saved.');
    return $this->redirect('/clients/'.$id.'#overview'); }

  public function deleteContact(int $id, int $contactId): Response {
    $this->client($id);
    $n = DB::delete('client_contacts', 'id=? AND client_id=?', [$contactId, $id]);
    if (!$n) { $this->abort(404, 'Contact not found.'); }
    Audit::log('crm.contact.delete', 'client', $id, ['contact_id' => $contactId]);
    $this->flash('Contact removed.');
    return $this->redirect('/clients/'.$id.'#overview'); }

  public function saveActivity(int $id, ?int $activityId = null): Response {
    $this->client($id);
    if ($activityId && !DB::value('SELECT 1 FROM client_activities WHERE id=? AND client_id=?', [$activityId, $id])) { $this->abort(404, 'Entry not found.'); }
    $d = $this->validate(['type' => 'required|in:'.implode(',', array_keys(self::TYPES)), 'title' => 'required|max:190', 'body' => 'nullable|max:5000', 'occurred_at' => 'required|datetime', 'follow_up_at' => 'nullable|datetime', 'is_internal' => 'boolean', 'project_id' => 'nullable|integer'], ['occurred_at' => 'Date & time', 'follow_up_at' => 'Follow-up date']);
    if ($d['project_id'] && (!feature_on('projects') || !DB::value('SELECT 1 FROM projects WHERE id=? AND client_id=?', [$d['project_id'], $id]))) { throw new ValidationException(['project_id' => 'Pick a project that belongs to this client.']); }
    if (!$d['project_id']) { $d['project_id'] = null; }
    if ($d['type'] === 'note') { $d['is_internal'] = 1; }
    if ($activityId) {
      if (!$d['follow_up_at']) { $d['follow_up_done'] = 0; }
      DB::update('client_activities', $d, 'id=? AND client_id=?', [$activityId, $id]); }
    else { $activityId = DB::insert('client_activities', $d + ['client_id' => $id, 'user_id' => $this->uid(), 'created_at' => $this->now()]); }
    Audit::log('crm.activity.save', 'client', $id, ['activity_id' => $activityId, 'type' => $d['type']]);
    $this->flash('Journey updated.');
    return $this->redirect('/clients/'.$id.'#journey'); }

  public function deleteActivity(int $id, int $activityId): Response {
    $this->client($id);
    if (!DB::delete('client_activities', 'id=? AND client_id=?', [$activityId, $id])) { $this->abort(404, 'Entry not found.'); }
    Audit::log('crm.activity.delete', 'client', $id, ['activity_id' => $activityId]);
    $this->flash('Entry deleted.');
    return $this->redirect('/clients/'.$id.'#journey'); }

  public function followUpDone(int $id, int $activityId): Response {
    $this->client($id);
    if (!DB::update('client_activities', ['follow_up_done' => 1], 'id=? AND client_id=? AND follow_up_at IS NOT NULL', [$activityId, $id]) && !DB::value('SELECT 1 FROM client_activities WHERE id=? AND client_id=?', [$activityId, $id])) { $this->abort(404, 'Entry not found.'); }
    Audit::log('crm.followup.done', 'client', $id, ['activity_id' => $activityId]);
    if ($this->request->wantsJson()) { return $this->json(['message' => 'Follow-up completed.']); }
    $this->flash('Follow-up marked as done.');
    return $this->back(); }

  public function upload(int $id): Response {
    $this->client($id);
    $f = $this->request->file('file') ?? throw new ValidationException(['file' => 'Choose a file to upload.']);
    Storage::attach($f, 'crm', 'client', $id, 'document');
    $this->flash('Document uploaded.');
    return $this->redirect('/clients/'.$id.'#documents'); } }
