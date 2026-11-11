<?php
namespace Modules\Meetings\Controllers;
use Core\{Audit, Controller, DB, Response, ValidationException};
use Modules\Meetings\Services\{Attach, MeetingAccess as MA, Schedule, Summarizer};

/** New meeting dialog (Meetings, client and project pages), the meeting page, edit / reschedule / cancel, and "Add notes / transcript" on a planned meeting. */
final class PlanController extends Controller {
  /** A visible meeting or 404 (attendees always see it). */
  private function meeting(int $id): array {
    $a = Schedule::find($id);
    if (!$a || !Schedule::canSee($this->user(), $a)) { $this->abort(404, 'Meeting not found.'); }
    return $a; }

  private function editable(int $id): array { $a = $this->meeting($id); if (!Schedule::canEdit($this->user(), $a)) { $this->abort(403, 'You cannot change this meeting.'); } return $a; }

  /** Validated [client id, project id]: the project decides the client; both visible and writable for the user. */
  private function target(): array {
    $cid = $this->request->int('client_id') ?: null; $pid = $this->request->int('project_id') ?: null;
    if ($pid) {
      $pr = feature_on('projects') ? DB::first('SELECT id, client_id FROM projects WHERE id=?', [$pid]) : null;
      if (!$pr) { throw new ValidationException(['project_id' => 'Pick one of your projects.']); }
      if ($cid && (int) $pr['client_id'] !== $cid) { throw new ValidationException(['project_id' => 'That project does not belong to the chosen client.']); }
      $cid = $pr['client_id'] ? (int) $pr['client_id'] : null; }
    if (!$cid && !$pid) { throw new ValidationException(['client_id' => 'Pick the client, the project, or both.']); }
    if (!MA::canTarget($this->user(), $cid, $pid)) { throw new ValidationException(['client_id' => 'Choose one of your clients or projects.']); }
    return [$cid, $pid]; }

  private function input(): array {
    [$cid, $pid] = $this->target();
    return Schedule::fields($this->request->all()) + ['client_id' => $cid, 'project_id' => $pid, 'users' => $this->request->arr('users'), 'contacts' => $this->request->arr('contacts')]; }

  /** Where the dialog was opened: back there (Meetings page, client Journey, project Client log). */
  private function back2(string $ret, ?int $cid, ?int $pid): Response {
    return $this->redirect(match (true) { $ret === 'client' && $cid => '/clients/'.$cid.'#overview', $ret === 'project' && $pid => '/projects/'.$pid.'?tab=log', default => '/meetings?tab=upcoming' }); }

  public function store(): Response {
    if (!$this->can('crm.clients.manage')) { $this->abort(403, 'You cannot log meetings on clients.'); }
    $d = $this->input();
    $id = Schedule::create($d, $this->user());
    $n = count(array_filter(array_map('intval', $d['users']), fn ($x) => $x !== $this->uid()));
    $future = strtotime($d['occurred_at']) > time();
    $this->flash(($future ? 'Meeting scheduled for '.Schedule::when(Schedule::find($id)) : 'Meeting logged').'.'.($n ? ' '.$n.' team member'.($n === 1 ? ' was' : 's were').' notified.' : ''));
    if ($this->request->int('notes_later')) { return $this->redirect('/meetings/event/'.$id.'#notes'); }
    return $this->back2($this->request->str('return'), $d['client_id'], $d['project_id']); }

  public function show(int $id): Response {
    $u = $this->user();
    $a = $this->meeting($id);
    $edit = Schedule::canEdit($u, $a);
    $data = ['title' => $a['title'], 'a' => $a, 'people' => Schedule::attendees($id), 'canEdit' => $edit, 'ai' => Summarizer::available(), 'logUrl' => Attach::logUrl($a), 'styles' => ['css/crm.css', 'css/meetings.css'], 'scripts' => ['js/meetings.js']];
    if ($edit) { $data['form'] = Schedule::formData($u, $a['client_id'] ? (int) $a['client_id'] : null, $a['project_id'] ? (int) $a['project_id'] : null); }
    return $this->view('meetings::event', $data); }

  public function update(int $id): Response {
    $a = $this->editable($id);
    $d = $this->input();
    if ($a['transcript_id'] && ((int) $d['client_id'] !== (int) $a['client_id'] || (int) $d['project_id'] !== (int) $a['project_id'])) { DB::update('meeting_transcripts', ['client_id' => $d['client_id'], 'project_id' => $d['project_id']], 'activity_id=?', [$id]); }
    [$moved, $added] = Schedule::update($id, $d, $this->user());
    $this->flash(($moved ? 'Meeting rescheduled to '.Schedule::when(Schedule::find($id)).'.' : 'Meeting updated.').($added ? ' '.count($added).' new attendee'.(count($added) === 1 ? '' : 's').' notified.' : ''));
    return $this->redirect('/meetings/event/'.$id); }

  public function cancel(int $id): Response {
    $a = $this->editable($id);
    $restore = $a['meeting_status'] === 'cancelled';
    Schedule::cancel($id, mb_substr($this->request->str('note'), 0, 500), $this->user(), $restore);
    $this->flash($restore ? 'Meeting is back on. Attendees were told.' : 'Meeting cancelled. Attendees were told.');
    return $this->redirect('/meetings/event/'.$id); }

  /** "Add notes / transcript": the same pipeline as the Log activity dialog (paste, upload, Fireflies), linked to this meeting. */
  public function notes(int $id): Response {
    $u = $this->user();
    $a = $this->editable($id);
    $doc = Attach::fromRequest($this->request, $u) ?? throw new ValidationException(['mt_text' => 'Paste notes, choose a file or pick a Fireflies meeting.']);
    if (empty($doc['meeting_at'])) { $doc['meeting_at'] = $a['occurred_at']; }
    $doc['title'] = $a['title'];
    if ($dup = Attach::duplicateLog($doc, $u, $id)) { $this->flash('These notes are already logged as "'.$dup['title'].'".', 'error'); return $this->redirect('/meetings/'.$dup['transcript_id']); }
    $msg = Attach::save($doc, $u, $id, $a['client_id'] ? (int) $a['client_id'] : null, $a['project_id'] ? (int) $a['project_id'] : null);
    Audit::log('meetings.schedule.notes', 'meeting_log', $id);
    $this->flash($msg);
    $tid = (int) DB::value('SELECT MIN(id) FROM meeting_transcripts WHERE activity_id=?', [$id]);
    return $this->redirect($tid ? '/meetings/'.$tid : '/meetings/event/'.$id); } }
