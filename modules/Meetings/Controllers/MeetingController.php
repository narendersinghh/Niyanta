<?php
namespace Modules\Meetings\Controllers;
use Core\{Audit, Controller, DB, Gate, Notify, Response, ValidationException};
use Modules\Crm\Services\ClientAccess;
use Modules\Meetings\Services\{Attach, Detector, Fireflies, FirefliesError, Inbox, MeetingAccess as MA, MeetingService, Parser, Recap, Summarizer};
use Modules\Projects\Services\{ProjectAccess as PA, ProjectService as PS};

/** Meetings page (To review / To sort, Recent, Connections), transcript detail, upload preview, sorting, summaries, action items and connections. */
final class MeetingController extends Controller {
  private const TABS = ['sort', 'recent', 'connections'];

  /** A visible transcript or 404. */
  private function transcript(int $id): array {
    $t = DB::first('SELECT mt.*, a.title AS log_title, a.client_id AS log_client, a.project_id AS log_project, COALESCE(NULLIF(c.company,\'\'), c.name) AS client_name, p.name AS project_name, o.name AS owner_name FROM meeting_transcripts mt LEFT JOIN client_activities a ON a.id=mt.activity_id LEFT JOIN clients c ON c.id=mt.client_id LEFT JOIN projects p ON p.id=mt.project_id LEFT JOIN users o ON o.id=mt.owner_id WHERE mt.id=?', [$id]);
    if (!$t || !MA::canSee($this->user(), $t)) { $this->abort(404, 'Meeting not found.'); }
    return $t; }

  private function editable(int $id): array { $t = $this->transcript($id); if (!MA::canEdit($this->user(), $t)) { $this->abort(403, 'You cannot change this meeting.'); } return $t; }

  /** Visible clients and projects for the sort / upload selects. */
  private function targets(): array {
    $u = $this->user();
    [$w, $p] = ClientAccess::where($u, 'c.id');
    $clients = $this->can('crm.clients.manage') ? DB::pairs("SELECT c.id, COALESCE(NULLIF(c.company,''), c.name) FROM clients c WHERE $w AND c.status<>'inactive' ORDER BY 2 LIMIT 2000", $p) : [];
    $projects = [];
    if (feature_on('projects') && $this->can('crm.clients.manage')) { [$pw, $pp] = PA::scope($u, 'p.id'); $projects = DB::all("SELECT p.id, p.name, p.client_id FROM projects p WHERE $pw AND p.status NOT IN ('completed','cancelled') ORDER BY p.name LIMIT 2000", $pp); }
    return ['clientOptions' => $clients, 'projectRows' => array_values(array_filter($projects, static fn ($r) => !$r['client_id'] || isset($clients[$r['client_id']])))]; }

  /** Validated [client id, project id]: the project decides the client; both must be visible and writable for the user. */
  private function target(?int $cid, ?int $pid, bool $required): array {
    if ($pid) {
      $pr = feature_on('projects') ? DB::first('SELECT id, client_id FROM projects WHERE id=?', [$pid]) : null;
      if (!$pr) { throw new ValidationException(['project_id' => 'Pick one of your projects.']); }
      if ($cid && (int) $pr['client_id'] !== $cid) { throw new ValidationException(['project_id' => 'That project does not belong to the chosen client.']); }
      $cid = $pr['client_id'] ? (int) $pr['client_id'] : null; }
    if (!$cid && !$pid) { if ($required) { throw new ValidationException(['client_id' => 'Pick a client, a project, or both.']); } return [null, null]; }
    if (!MA::canTarget($this->user(), $cid, $pid)) { throw new ValidationException(['client_id' => 'Choose one of your clients or projects.']); }
    return [$cid, $pid]; }

  public function index(): Response {
    $u = $this->user();
    $tab = in_array($t = $this->request->str('tab', 'sort'), self::TABS, true) ? $t : 'sort';
    [$w, $p] = MA::scope($u, 'mt');
    $sel = "SELECT mt.id, mt.title, mt.source, mt.tool, mt.meeting_at, mt.created_at, mt.duration_min, mt.status, mt.confidence, mt.reasons, mt.summary_status, mt.summary_error, mt.client_id, mt.project_id, mt.activity_id, mt.chars, mt.owner_id, COALESCE(NULLIF(c.company,''), c.name) AS client_name, p.name AS project_name, o.name AS owner_name FROM meeting_transcripts mt LEFT JOIN clients c ON c.id=mt.client_id LEFT JOIN projects p ON p.id=mt.project_id LEFT JOIN users o ON o.id=mt.owner_id WHERE $w";
    $counts = DB::first("SELECT COALESCE(SUM(mt.status='to_sort'),0) AS sort, COALESCE(SUM(mt.status='linked' AND mt.reviewed_at IS NULL AND mt.summary_status IN ('done','failed')),0) AS review, COUNT(*) AS total FROM meeting_transcripts mt WHERE $w", $p);
    $data = ['title' => 'Meetings', 'tab' => $tab, 'counts' => array_map('intval', $counts), 'manager' => MA::manager($u), 'ai' => Summarizer::available(), 'styles' => ['css/crm.css', 'css/meetings.css'], 'scripts' => ['js/meetings.js']] + $this->targets();
    if ($tab === 'sort') {
      $data['toSort'] = DB::all("$sel AND mt.status='to_sort' ORDER BY mt.id DESC LIMIT 50", $p);
      $data['toReview'] = DB::all("$sel AND mt.status='linked' AND mt.reviewed_at IS NULL AND mt.summary_status IN ('done','failed') ORDER BY mt.id DESC LIMIT 30", $p); }
    elseif ($tab === 'recent') {
      $q = mb_substr($this->request->str('q'), 0, 100); $src = $this->request->str('source');
      $sql = $sel; $pp = $p;
      if ($q !== '') { $sql .= ' AND mt.title LIKE ?'; $pp[] = DB::like($q); }
      if (isset(Recap::SOURCES[$src])) { $sql .= ' AND mt.source=?'; $pp[] = $src; }
      $data['p'] = DB::paginate($sql.' ORDER BY COALESCE(mt.meeting_at, mt.created_at) DESC, mt.id DESC', $pp, 20);
      $data['q'] = $q; $data['source'] = $src; }
    else {
      $data['conn'] = DB::first("SELECT id, last_sync_at, last_status, backoff_until, updated_at FROM meeting_connections WHERE user_id=? AND provider='fireflies'", [(int) $u['id']]);
      $data['company'] = Fireflies::companyKey() ? \Core\Settings::json('meetings.fireflies_company_state') + ['on' => true] : null;
      $data['inbox'] = Inbox::address(); $data['inboxState'] = \Core\Settings::json('meetings.inbox_state'); $data['inboxOn'] = Inbox::configured();
      $data['stats'] = $data['manager'] ? Inbox::stats() : [];
      $data['dropped'] = $data['manager'] ? DB::all("SELECT from_email, subject, reason, created_at FROM meeting_mail_log WHERE outcome='dropped' ORDER BY id DESC LIMIT 8") : []; }
    return $this->view('meetings::index', $data); }

  public function show(int $id): Response {
    $u = $this->user();
    $t = $this->transcript($id);
    if (!$t['reviewed_at'] && $t['status'] === 'linked' && in_array($t['summary_status'], ['done', 'failed'], true) && ((int) $t['owner_id'] === (int) $u['id'] || MA::manager($u))) { DB::update('meeting_transcripts', ['reviewed_at' => date('Y-m-d H:i:s')], 'id=?', [$id]); }
    $pid = $t['project_id'] ? (int) $t['project_id'] : null;
    $taskOk = $pid && feature_on('projects') && PA::canManageTasks($u, $pid);
    $todoOk = feature_on('todos') && class_exists(\Modules\Todos\Services\Todos::class) && \Modules\Todos\Services\Todos::canCreate($u);
    return $this->view('meetings::show', ['title' => $t['title'], 't' => $t, 'summary' => json_decode((string) $t['summary'], true), 'people' => json_decode((string) $t['participants'], true) ?: [], 'reasons' => json_decode((string) $t['reasons'], true) ?: [],
      'actions' => json_decode((string) $t['actions'], true) ?: [], 'canEdit' => MA::canEdit($u, $t), 'canDelete' => MA::canDelete($u, $t), 'ai' => Summarizer::available(),
      'taskOk' => $taskOk, 'team' => $taskOk ? PS::team($pid) : [], 'todoOk' => $todoOk, 'assignable' => $todoOk ? \Modules\Todos\Services\Todos::assignable($u) : [],
      'logUrl' => $t['activity_id'] ? Attach::logUrl(['client_id' => $t['log_client'], 'project_id' => $t['log_project']]) : null, 'styles' => ['css/crm.css', 'css/meetings.css'], 'scripts' => ['js/meetings.js']] + ($t['status'] === 'to_sort' ? $this->targets() : [])); }

  /** AJAX preview of an upload / paste / Fireflies pick: parsed details and the detected client + project with reasons (JSON). */
  public function preview(): Response {
    $u = $this->user();
    $doc = Attach::fromRequest($this->request, $u);
    if (!$doc) { return $this->json(['error' => 'Paste notes, choose a file or pick a Fireflies meeting first.'], 422); }
    $cid = $this->request->int('mt_ctx_client') ?: null; $pid = $this->request->int('mt_ctx_project') ?: null;
    if ($cid && !ClientAccess::canSee($u, $cid)) { $cid = null; }
    if ($pid && !(feature_on('projects') && PA::canSee($u, $pid))) { $pid = null; }
    $emails = array_values(array_filter(array_column($doc['participants'], 'email')));
    $free = Detector::detect(['user' => $u, 'title' => $doc['title'], 'text' => $doc['text'], 'emails' => $emails]);
    $det = $pid || $cid ? Detector::detect(['user' => $u, 'project_id' => $pid, 'client_id' => $cid, 'title' => $doc['title'], 'text' => $doc['text'], 'emails' => $emails, 'explicit_reason' => $pid ? 'Logged from this project' : 'Logged on this client']) : $free;
    if ($det !== $free && $free['client_id'] && (int) $free['client_id'] === (int) $det['client_id']) { $det['reasons'] = array_values(array_unique([...$free['reasons'], ...array_slice($det['reasons'], 1)])); }
    $warn = [];
    if (!$pid && $cid && $free['client_id'] && (int) $free['client_id'] !== $cid && $free['confidence'] !== 'low') { $warn[] = 'These notes look like they belong to '.$free['client_name'].' ('.implode('; ', $free['reasons']).'). Saving here logs them on this client.'; }
    if ($pid && $free['project_id'] && (int) $free['project_id'] !== $pid && $free['confidence'] === 'high') { $warn[] = 'These notes look like project '.$free['project_name'].'. Saving here logs them on this project.'; }
    $dup = Attach::duplicateLog($doc, $u);
    $at = $doc['meeting_at'] ? date('Y-m-d\TH:i', strtotime($doc['meeting_at'])) : null;
    return $this->json(['ok' => true, 'title' => $doc['title'], 'meeting_at' => $at, 'meeting_label' => $doc['meeting_at'] ? fdatetime($doc['meeting_at']) : null, 'duration_min' => $doc['duration_min'],
      'participants' => array_slice(array_map(static fn ($x) => $x['name'] ? $x['name'].($x['email'] ? ' <'.$x['email'].'>' : '') : $x['email'], $doc['participants']), 0, 12), 'participant_count' => count($doc['participants']),
      'format' => $doc['format'], 'tool' => Recap::label($doc['tool'] ?? null, $doc['source']), 'chars' => mb_strlen((string) $doc['text']),
      'client' => $det['client_id'] ? ['id' => (int) $det['client_id'], 'name' => $det['client_name']] : null, 'project' => $det['project_id'] ? ['id' => (int) $det['project_id'], 'name' => $det['project_name']] : null,
      'confidence' => $det['confidence'], 'reasons' => $det['reasons'], 'warnings' => $warn, 'duplicate' => $dup ? ['title' => $dup['title'], 'url' => url($dup['url'])] : null,
      'ai' => Summarizer::available(), 'notice' => Summarizer::available() ? 'Goraiya writes the summary into the log after saving.' : 'AI summaries are off: the notes are attached without a summary.']); }

  /** Standalone upload on the Meetings page: logged now when a client or project is chosen, else kept in To sort. */
  public function upload(): Response {
    $u = $this->user();
    $doc = Attach::fromRequest($this->request, $u) ?? throw new ValidationException(['mt_text' => 'Paste notes, choose a file or pick a Fireflies meeting.']);
    $d = $this->validate(['title' => 'nullable|max:190', 'occurred_at' => 'nullable|datetime', 'client_id' => 'nullable|integer', 'project_id' => 'nullable|integer'], ['occurred_at' => 'Date & time']);
    [$cid, $pid] = $this->target($d['client_id'] ?: null, $d['project_id'] ?: null, false);
    if ($d['title']) { $doc['title'] = $d['title']; }
    if ($d['occurred_at']) { $doc['meeting_at'] = date('Y-m-d H:i:s', strtotime($d['occurred_at'])); }
    $dup = Attach::duplicateLog($doc, $u);
    if ($dup) { $this->flash('This meeting is already logged as "'.$dup['title'].'".', 'error'); return $this->redirect('/meetings/'.$dup['transcript_id']); }
    $r = MeetingService::ingest($doc, ['source' => $doc['source'], 'owner_id' => (int) $u['id'], 'user' => $u, 'client_id' => $cid, 'project_id' => $pid, 'confirmed' => (bool) ($cid || $pid), 'explicit_reason' => 'Chosen when uploading']);
    $this->flash($r['duplicate'] ? 'Merged with the copy of this meeting that arrived earlier.' : ($r['created_log'] ? 'Meeting logged.' : 'Saved to To sort: pick the client and project.'));
    return $this->redirect('/meetings/'.$r['id']); }

  /** To sort > pick client / project (or non-project activity of a client) and create the log. */
  public function assign(int $id): Response {
    $u = $this->user();
    $t = $this->editable($id);
    if ($t['status'] === 'linked' && $t['activity_id']) { $this->flash('This meeting is already logged.'); return $this->redirect('/meetings/'.$id); }
    $d = $this->validate(['client_id' => 'nullable|integer', 'project_id' => 'nullable|integer']);
    [$cid, $pid] = $this->target($d['client_id'] ?: null, $d['project_id'] ?: null, true);
    DB::update('meeting_transcripts', ['client_id' => $cid, 'project_id' => $pid, 'confidence' => 'high', 'reasons' => json_encode(['Sorted by '.$u['name']]), 'updated_at' => date('Y-m-d H:i:s')], 'id=?', [$id]);
    $aid = MeetingService::createLog(DB::find('meeting_transcripts', $id));
    if ($t['summary_status'] === 'skipped') { MeetingService::fillBody(DB::find('meeting_transcripts', $id)); }
    Audit::log('meetings.transcript.assign', 'meeting', $id, ['client_id' => $cid, 'project_id' => $pid, 'activity_id' => $aid]);
    $this->flash('Meeting logged'.($pid ? ' on the project.' : ' as non-project activity.'));
    return $this->redirect($this->request->str('return') === 'list' ? '/meetings?tab=sort' : '/meetings/'.$id); }

  public function dismiss(int $id): Response {
    $t = $this->editable($id);
    DB::update('meeting_transcripts', ['status' => $t['status'] === 'dismissed' ? 'to_sort' : 'dismissed', 'updated_at' => date('Y-m-d H:i:s')], 'id=?', [$id]);
    Audit::log('meetings.transcript.dismiss', 'meeting', $id);
    $this->flash($t['status'] === 'dismissed' ? 'Back in To sort.' : 'Dismissed. It stays under Recent.');
    return $this->redirect('/meetings?tab=sort'); }

  /** Queue (or re-run) the Goraiya summary. */
  public function summarise(int $id): Response {
    $t = $this->editable($id);
    if (!Summarizer::available()) { $this->flash('AI summaries are off or no AI model is set up (Settings › AI assistant).', 'error'); return $this->redirect('/meetings/'.$id); }
    if ($t['summary_status'] === 'summarising') { $this->flash('The summary is being written right now.'); return $this->redirect('/meetings/'.$id); }
    if (!$t['transcript'] && !$t['source_summary']) { $this->flash('The transcript text was removed by the retention policy.', 'error'); return $this->redirect('/meetings/'.$id); }
    DB::update('meeting_transcripts', ['summary_status' => 'new', 'summary_error' => null, 'updated_at' => date('Y-m-d H:i:s')], 'id=?', [$id]);
    Audit::log('meetings.summary.queue', 'meeting', $id);
    $this->flash('Summary queued. Goraiya writes it in the background within a minute or two.');
    return $this->redirect('/meetings/'.$id); }

  /** Ticked action items become project tasks (needs task rights on the project) or to-dos (Todos module), with assignee and due date. */
  public function actions(int $id): Response {
    $u = $this->user();
    $t = $this->editable($id);
    $s = json_decode((string) $t['summary'], true);
    $items = $s['action_items'] ?? [];
    $done = json_decode((string) $t['actions'], true) ?: [];
    $pick = $this->request->arr('items');
    if (!$pick) { throw new ValidationException(['items' => 'Tick at least one action item.']); }
    $pid = $t['project_id'] ? (int) $t['project_id'] : null;
    $now = date('Y-m-d H:i:s'); $made = ['task' => 0, 'todo' => 0];
    DB::transaction(function () use ($pick, $items, &$done, $pid, $u, $now, &$made, $t) {
      foreach ($pick as $i => $row) {
        $i = (int) $i;
        if (!isset($items[$i]) || isset($done[$i]) || !is_array($row) || empty($row['on'])) { continue; }
        $kind = ($row['kind'] ?? 'task') === 'todo' ? 'todo' : 'task';
        $title = mb_substr(trim((string) ($row['title'] ?? '')) ?: $items[$i]['text'], 0, 255);
        $due = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($row['due'] ?? '')) ? (string) $row['due'] : null;
        $who = (int) ($row['assignee'] ?? 0) ?: null;
        $desc = 'From the meeting "'.$t['title'].'"'.($t['meeting_at'] ? ' on '.fdate($t['meeting_at']) : '').'. '.url('/meetings/'.$t['id']);
        if ($kind === 'task') {
          if (!$pid || !feature_on('projects') || !PA::canManageTasks($u, $pid)) { throw new ValidationException(['items' => 'You cannot add tasks to this project. Create to-dos instead.']); }
          if ($who && !isset(PS::team($pid)[$who])) { throw new ValidationException(['items' => 'Task assignees must be members of the project.']); }
          $tid = DB::insert('tasks', ['project_id' => $pid, 'title' => $title, 'description' => $desc, 'status' => 'todo', 'priority' => 'medium', 'assignee_id' => $who, 'due_date' => $due, 'billable' => 0, 'position' => (int) DB::value('SELECT IFNULL(MAX(position),0)+1 FROM tasks WHERE project_id=? AND status=\'todo\'', [$pid]), 'created_by' => (int) $u['id'], 'created_at' => $now, 'updated_at' => $now]);
          PS::notifyAssignee(['id' => $tid, 'project_id' => $pid, 'title' => $title, 'assignee_id' => $who, 'due_date' => $due], (int) $u['id']);
          Audit::log('projects.task.create', 'task', $tid, ['project_id' => $pid, 'title' => $title, 'from' => 'meeting '.$t['id']]);
          $done[$i] = ['task', $tid]; $made['task']++; }
        else {
          if (!feature_on('todos') || !class_exists(\Modules\Todos\Services\Todos::class) || !\Modules\Todos\Services\Todos::canCreate($u)) { throw new ValidationException(['items' => 'To-dos are not available to you.']); }
          $who = $who ?: (int) $u['id'];
          if (!isset(\Modules\Todos\Services\Todos::assignable($u)[$who])) { throw new ValidationException(['items' => 'You cannot assign to-dos to that person.']); }
          $tid = DB::insert('todos', ['title' => $title, 'description' => $desc, 'priority' => 'medium', 'assignee_id' => $who, 'created_by' => (int) $u['id'], 'due_date' => $due, 'status' => 'open', 'created_at' => $now, 'updated_at' => $now]);
          if ($who !== (int) $u['id']) { Notify::user($who, 'New task from '.$u['name'].': '.$title, $due ? 'Due '.fdate($due).'.' : '', '/todos', false, 'check-square'); }
          Audit::log('todos.create', 'todo', $tid, ['assignee_id' => $who, 'from' => 'meeting '.$t['id']]);
          $done[$i] = ['todo', $tid]; $made['todo']++; } }
      DB::update('meeting_transcripts', ['actions' => json_encode($done), 'updated_at' => $now], 'id=?', [$t['id']]); });
    if ($made['task']) { PS::recalcProgress($pid); }
    $this->flash(trim(($made['task'] ? $made['task'].' task'.($made['task'] === 1 ? '' : 's').' created. ' : '').($made['todo'] ? $made['todo'].' to-do'.($made['todo'] === 1 ? '' : 's').' created.' : '')) ?: 'Nothing new to create.');
    return $this->redirect('/meetings/'.$id.'#actions'); }

  /** Delete the transcript (managers or its owner). The meeting log and its summary stay. */
  public function destroy(int $id): Response {
    $t = $this->transcript($id);
    if (!MA::canDelete($this->user(), $t)) { $this->abort(403, 'Only the owner or a meetings manager can delete this transcript.'); }
    DB::delete('meeting_transcripts', 'id=?', [$id]);
    Audit::log('meetings.transcript.delete', 'meeting', $id, ['title' => $t['title'], 'activity_id' => $t['activity_id']]);
    $this->flash('Transcript deleted. The meeting log stays.');
    return $this->redirect('/meetings?tab=recent'); }

  /* ---------- connections ---------- */

  public function saveFireflies(): Response {
    $key = $this->request->str('api_key');
    if (!preg_match('/^[A-Za-z0-9._\-]{16,200}$/', $key)) { throw new ValidationException(['api_key' => 'Paste the API key from Fireflies › Integrations › Fireflies API.']); }
    try { Fireflies::request($key, 'query { user { email } }'); }
    catch (FirefliesError $e) { if ($e->kind !== 'rate') { throw new ValidationException(['api_key' => $e->getMessage()]); } }
    Fireflies::saveKey($this->uid(), $key);
    Audit::log('meetings.fireflies.connect', 'user', $this->uid());
    $this->flash('Fireflies connected. New meetings arrive every 30 minutes, or use Fetch now.');
    return $this->redirect('/meetings?tab=connections'); }

  public function deleteFireflies(): Response {
    DB::delete('meeting_connections', "user_id=? AND provider='fireflies'", [$this->uid()]);
    Audit::log('meetings.fireflies.disconnect', 'user', $this->uid());
    $this->flash('Fireflies disconnected. Meetings already imported stay.');
    return $this->redirect('/meetings?tab=connections'); }

  public function fetchFireflies(): Response {
    $u = $this->user(); $out = [];
    if ($c = DB::first("SELECT * FROM meeting_connections WHERE user_id=? AND provider='fireflies'", [(int) $u['id']])) { $out[] = Fireflies::sync($c, true); }
    if (MA::manager($u) && Fireflies::companyKey()) { $out[] = Fireflies::sync(null, true); }
    $this->flash($out ? 'Fireflies: '.implode('; ', array_map(static fn ($s) => preg_replace('/^(user \d+|company key): /', '', $s), $out)) : 'Connect Fireflies first.', $out ? 'success' : 'error');
    return $this->redirect('/meetings?tab=connections'); }

  /** Recent Fireflies meetings for the dialog picker (JSON). */
  public function fireflyRecent(): Response {
    $key = Fireflies::keyFor($this->user());
    if (!$key) { return $this->json(['error' => 'Connect Fireflies under Meetings › Connections first.'], 422); }
    try { return $this->json(['items' => array_map(static fn ($t) => $t + ['label' => $t['date'] ? fdatetime($t['date']) : ''], Fireflies::recent($key))]); }
    catch (FirefliesError $e) { return $this->json(['error' => $e->getMessage()], 422); } }

  /** Read the notes inbox now (meetings managers). */
  public function checkInbox(): Response {
    try { $msg = Inbox::run(); $this->flash('Notes inbox: '.$msg.'.'); }
    catch (\Throwable $e) { $this->flash($e->getMessage(), 'error'); }
    return $this->redirect($this->request->str('return') === 'settings' && \Core\Auth::isSuperAdmin() ? '/admin/settings/meetings' : '/meetings?tab=connections'); } }
