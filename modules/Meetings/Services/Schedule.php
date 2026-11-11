<?php
namespace Modules\Meetings\Services;
use Core\{Audit, DB, Gate, Notify, ValidationException};
use Modules\Crm\Services\ClientAccess;
use Modules\Projects\Services\ProjectAccess;

/**
 * Planned meetings ("New meeting"): one client log entry (client_activities type meeting, meeting_status scheduled) on the chosen client and / or
 * project, with attendees (team members are notified when added, rescheduled or cancelled; client contacts are listed), agenda, follow-up date.
 * Notes and transcripts are attached later through the normal pipeline (Attach::save / MeetingService::link); an automatic transcript of the
 * same client / project within 3 hours of a scheduled meeting is linked to it instead of creating a second log (MeetingService::createLog).
 * Visibility follows MeetingAccess (owner, client / project access, meetings managers) plus the attendees themselves.
 */
final class Schedule {
  public const DURATIONS = [15 => '15 min', 30 => '30 min', 45 => '45 min', 60 => '1 hour', 90 => '1.5 hours', 120 => '2 hours', 180 => '3 hours'];

  /** [sql, params] limiting client_activities alias $a to meetings the user may see. */
  public static function scope(array $u, string $a = 'a'): array {
    if (MeetingAccess::manager($u)) { return ['1=1', []]; }
    [$cw, $cp] = ClientAccess::where($u, "$a.client_id");
    [$pw, $pp] = feature_on('projects') ? ProjectAccess::scope($u, "$a.project_id") : ['0=1', []];
    return ["($a.user_id=? OR EXISTS (SELECT 1 FROM meeting_attendees ma WHERE ma.activity_id=$a.id AND ma.user_id=?) OR ($a.client_id IS NOT NULL AND $cw) OR ($a.client_id IS NULL AND $a.project_id IS NOT NULL AND $pw))", [(int) $u['id'], (int) $u['id'], ...$cp, ...$pp]]; }

  public static function isAttendee(int $activityId, int $uid): bool { return (bool) DB::value('SELECT 1 FROM meeting_attendees WHERE activity_id=? AND user_id=?', [$activityId, $uid]); }

  public static function canSee(array $u, array $a): bool {
    return MeetingAccess::canSee($u, ['owner_id' => $a['user_id'], 'client_id' => $a['client_id'], 'project_id' => $a['project_id']]) || self::isAttendee((int) $a['id'], (int) $u['id']); }

  /** Edit, reschedule, cancel and add notes: meetings managers and whoever may log on its client / project. */
  public static function canEdit(array $u, array $a): bool {
    return Gate::allows('meetings.use', $u) && (MeetingAccess::manager($u) || MeetingAccess::canTarget($u, $a['client_id'] ? (int) $a['client_id'] : null, $a['project_id'] ? (int) $a['project_id'] : null)); }

  /** May the user plan a meeting here (optional fixed client / project)? */
  public static function canPlan(array $u, ?int $cid = null, ?int $pid = null): bool {
    if (!feature_on('meetings') || !Gate::allows('meetings.use', $u) || !Gate::allows('crm.clients.manage', $u)) { return false; }
    return !$cid && !$pid ? true : MeetingAccess::canTarget($u, $cid, $pid); }

  /** Meeting log entry with client / project names, or null. */
  public static function find(int $id): ?array {
    return DB::first("SELECT a.*, COALESCE(NULLIF(c.company,''), c.name) AS client_name, p.name AS project_name, o.name AS owner_name, (SELECT MIN(mt.id) FROM meeting_transcripts mt WHERE mt.activity_id=a.id) AS transcript_id
      FROM client_activities a LEFT JOIN clients c ON c.id=a.client_id LEFT JOIN projects p ON p.id=a.project_id LEFT JOIN users o ON o.id=a.user_id WHERE a.id=? AND a.type='meeting'", [$id]); }

  /** Attendees of one meeting: [{kind user|contact, id, name, email, avatar}]. */
  public static function attendees(int $id): array {
    $users = DB::all("SELECT 'user' AS kind, u.id, u.name, u.email, u.avatar FROM meeting_attendees ma JOIN users u ON u.id=ma.user_id WHERE ma.activity_id=? ORDER BY u.name", [$id]);
    $contacts = DB::all("SELECT 'contact' AS kind, c.id, c.name, c.email, NULL AS avatar FROM meeting_attendees ma JOIN client_contacts c ON c.id=ma.contact_id WHERE ma.activity_id=? ORDER BY c.name", [$id]);
    return [...$users, ...$contacts]; }

  /** Validated meeting fields from input (title, occurred_at, duration_min, body, follow_up_at). */
  public static function fields(array $in): array {
    $title = trim((string) ($in['title'] ?? ''));
    $e = [];
    if ($title === '') { $e['title'] = 'Give the meeting a title, e.g. Weekly sync or Design review.'; }
    $at = strtotime((string) ($in['occurred_at'] ?? ''));
    if (!$at) { $e['occurred_at'] = 'Choose the date and time.'; }
    $dur = (int) ($in['duration_min'] ?? 30); if (!isset(self::DURATIONS[$dur])) { $dur = 30; }
    $fu = trim((string) ($in['follow_up_at'] ?? '')); $fut = $fu !== '' ? strtotime($fu) : null;
    if ($fu !== '' && !$fut) { $e['follow_up_at'] = 'Enter a valid follow-up date.'; }
    if (mb_strlen((string) ($in['body'] ?? '')) > 5000) { $e['body'] = 'Keep the agenda under 5000 characters.'; }
    if ($e) { throw new ValidationException($e); }
    return ['title' => mb_substr($title, 0, 190), 'occurred_at' => date('Y-m-d H:i:00', $at), 'ends_at' => date('Y-m-d H:i:00', $at + $dur * 60), 'body' => trim((string) ($in['body'] ?? '')) ?: null, 'follow_up_at' => $fut ? date('Y-m-d H:i:00', $fut) : null]; }

  /** Create the meeting log. $d: fields() output + client_id, project_id (validated), users[], contacts[]. Returns the activity id. */
  public static function create(array $d, array $u): int {
    $id = DB::transaction(static function () use ($d, $u): int {
      $id = DB::insert('client_activities', ['client_id' => $d['client_id'] ?: null, 'project_id' => $d['project_id'] ?: null, 'type' => 'meeting', 'title' => $d['title'], 'body' => $d['body'], 'is_internal' => 0, 'occurred_at' => $d['occurred_at'], 'ends_at' => $d['ends_at'],
        'meeting_status' => 'scheduled', 'follow_up_at' => $d['follow_up_at'], 'follow_up_done' => 0, 'user_id' => (int) $u['id'], 'created_at' => date('Y-m-d H:i:s')]);
      self::syncAttendees($id, $d['users'] ?? [], $d['contacts'] ?? [], $d['client_id'] ?: null);
      return $id; });
    Audit::log('meetings.schedule.create', $d['client_id'] ? 'client' : 'project', (int) ($d['client_id'] ?: $d['project_id']), ['activity_id' => $id, 'project_id' => $d['project_id'] ?: null, 'at' => $d['occurred_at']]);
    $a = self::find($id);
    self::notify($a, array_map('intval', $d['users'] ?? []), $u, 'invite');
    return $id; }

  /** Edit / reschedule. Newly added team members get an invite, the others a "rescheduled" note when the time moved. Returns [moved, added]. */
  public static function update(int $id, array $d, array $u): array {
    $old = self::find($id) ?? throw new ValidationException(['title' => 'Meeting not found.']);
    $before = array_map('intval', DB::column('SELECT user_id FROM meeting_attendees WHERE activity_id=? AND user_id IS NOT NULL', [$id]));
    DB::transaction(static function () use ($id, $d): void {
      DB::update('client_activities', ['title' => $d['title'], 'body' => $d['body'], 'occurred_at' => $d['occurred_at'], 'ends_at' => $d['ends_at'], 'follow_up_at' => $d['follow_up_at'], 'project_id' => $d['project_id'] ?: null, 'client_id' => $d['client_id'] ?: null], 'id=?', [$id]);
      if (!$d['follow_up_at']) { DB::update('client_activities', ['follow_up_done' => 0], 'id=?', [$id]); }
      self::syncAttendees($id, $d['users'] ?? [], $d['contacts'] ?? [], $d['client_id'] ?: null); });
    $a = self::find($id);
    $moved = $old['occurred_at'] !== $a['occurred_at'];
    $now = array_map('intval', DB::column('SELECT user_id FROM meeting_attendees WHERE activity_id=? AND user_id IS NOT NULL', [$id]));
    $added = array_values(array_diff($now, $before));
    Audit::log('meetings.schedule.update', 'meeting_log', $id, ['from' => $old['occurred_at'], 'to' => $a['occurred_at'], 'added' => $added]);
    self::notify($a, $added, $u, 'invite');
    if ($moved && $a['meeting_status'] !== 'cancelled') { self::notify($a, array_values(array_intersect($now, $before)), $u, 'moved', $old['occurred_at']); }
    return [$moved, $added]; }

  /** Cancel (or restore) a planned meeting; attendees are told. */
  public static function cancel(int $id, string $note, array $u, bool $restore = false): void {
    $a = self::find($id) ?? throw new ValidationException(['note' => 'Meeting not found.']);
    DB::update('client_activities', ['meeting_status' => $restore ? 'scheduled' : 'cancelled'], 'id=?', [$id]);
    Audit::log($restore ? 'meetings.schedule.restore' : 'meetings.schedule.cancel', 'meeting_log', $id, ['note' => $note]);
    $to = array_map('intval', DB::column('SELECT user_id FROM meeting_attendees WHERE activity_id=? AND user_id IS NOT NULL', [$id]));
    self::notify(['meeting_status' => $restore ? 'scheduled' : 'cancelled'] + $a, $to, $u, $restore ? 'restored' : 'cancelled', null, $note); }

  /** Replace the attendee lists: active team members, and contacts of the meeting's client only. */
  public static function syncAttendees(int $id, array $users, array $contacts, ?int $clientId): void {
    $users = array_values(array_unique(array_filter(array_map('intval', $users))));
    $contacts = array_values(array_unique(array_filter(array_map('intval', $contacts))));
    if ($users) { $users = array_map('intval', DB::column("SELECT id FROM users WHERE status='active' AND id IN ".DB::in($users), $users)); }
    $contacts = $contacts && $clientId ? array_map('intval', DB::column('SELECT id FROM client_contacts WHERE client_id=? AND id IN '.DB::in($contacts), [$clientId, ...$contacts])) : [];
    DB::query('DELETE FROM meeting_attendees WHERE activity_id=? AND user_id IS NOT NULL'.($users ? ' AND user_id NOT IN '.DB::in($users) : ''), [$id, ...$users]);
    DB::query('DELETE FROM meeting_attendees WHERE activity_id=? AND contact_id IS NOT NULL'.($contacts ? ' AND contact_id NOT IN '.DB::in($contacts) : ''), [$id, ...$contacts]);
    $now = date('Y-m-d H:i:s');
    foreach ($users as $x) { DB::query('INSERT IGNORE INTO meeting_attendees (activity_id, user_id, created_at) VALUES (?,?,?)', [$id, $x, $now]); }
    foreach ($contacts as $x) { DB::query('INSERT IGNORE INTO meeting_attendees (activity_id, contact_id, created_at) VALUES (?,?,?)', [$id, $x, $now]); } }

  /** "Thu 12 Nov, 03:00 PM - 03:30 PM" */
  public static function when(array $a): string {
    $s = fdate($a['occurred_at'], 'D d M').', '.ftime($a['occurred_at']);
    return $a['ends_at'] && $a['ends_at'] > $a['occurred_at'] ? $s.' - '.ftime($a['ends_at']) : $s; }

  public static function where(array $a): string { return implode(' › ', array_filter([$a['client_name'] ?? null, $a['project_name'] ?? null])) ?: 'Internal'; }

  private static function notify(array $a, array $uids, array $by, string $kind, ?string $was = null, string $note = ''): void {
    $title = match ($kind) { 'invite' => 'Meeting invite: ', 'moved' => 'Meeting rescheduled: ', 'cancelled' => 'Meeting cancelled: ', default => 'Meeting back on: ' }.$a['title'];
    $body = self::when($a).' · '.self::where($a).($kind === 'moved' && $was ? ' (was '.fdatetime($was).')' : '').' · by '.$by['name'].(trim($note) !== '' ? "\nNote: ".trim($note) : '');
    foreach (array_unique($uids) as $uid) { if ($uid && $uid !== (int) $by['id']) { Notify::user($uid, $title, $body, '/meetings/event/'.(int) $a['id'], $kind !== 'invite' || strtotime($a['occurred_at']) > time(), 'calendar'); } } }

  /** Upcoming (from 30 minutes ago), not cancelled, visible meetings, optionally of one client / project: rows with names and attendee counts. */
  public static function upcoming(array $u, ?int $cid = null, ?int $pid = null, int $limit = 8): array {
    [$w, $p] = self::scope($u, 'a');
    $sql = "SELECT a.id, a.title, a.occurred_at, a.ends_at, a.meeting_status, a.client_id, a.project_id, a.user_id, COALESCE(NULLIF(c.company,''), c.name) AS client_name, pr.name AS project_name, (SELECT COUNT(*) FROM meeting_attendees ma WHERE ma.activity_id=a.id) AS people
      FROM client_activities a LEFT JOIN clients c ON c.id=a.client_id LEFT JOIN projects pr ON pr.id=a.project_id WHERE a.type='meeting' AND a.occurred_at>=? AND COALESCE(a.meeting_status,'')<>'cancelled' AND $w";
    $params = [date('Y-m-d H:i:s', time() - 1800), ...$p];
    if ($cid) { $sql .= ' AND a.client_id=?'; $params[] = $cid; }
    if ($pid) { $sql .= ' AND a.project_id=?'; $params[] = $pid; }
    return DB::all($sql.' ORDER BY a.occurred_at, a.id LIMIT '.max(1, $limit), $params); }

  /** Held in the last 30 days with no transcript or notes attached yet (not cancelled). */
  public static function needsNotes(array $u, int $limit = 10): array {
    [$w, $p] = self::scope($u, 'a');
    return DB::all("SELECT a.id, a.title, a.occurred_at, a.ends_at, a.meeting_status, a.client_id, a.project_id, COALESCE(NULLIF(c.company,''), c.name) AS client_name, pr.name AS project_name FROM client_activities a LEFT JOIN clients c ON c.id=a.client_id LEFT JOIN projects pr ON pr.id=a.project_id
      WHERE a.type='meeting' AND a.meeting_status='scheduled' AND a.occurred_at<? AND a.occurred_at>=? AND NOT EXISTS (SELECT 1 FROM meeting_transcripts mt WHERE mt.activity_id=a.id) AND $w ORDER BY a.occurred_at DESC LIMIT ".max(1, $limit), [date('Y-m-d H:i:s', time() - 1800), date('Y-m-d H:i:s', strtotime('-30 days')), ...$p]); }

  /** A scheduled meeting without notes on the same client / project within 3 hours of $at (auto-link target), or null. */
  public static function match(?int $cid, ?int $pid, string $at): ?int {
    if (!$cid && !$pid) { return null; }
    $ts = strtotime($at) ?: time();
    $sql = "SELECT a.id FROM client_activities a WHERE a.type='meeting' AND a.meeting_status='scheduled' AND a.occurred_at BETWEEN ? AND ? AND NOT EXISTS (SELECT 1 FROM meeting_transcripts mt WHERE mt.activity_id=a.id)";
    $p = [date('Y-m-d H:i:s', $ts - 10800), date('Y-m-d H:i:s', $ts + 10800)];
    if ($cid) { $sql .= ' AND a.client_id=?'; $p[] = $cid; } else { $sql .= ' AND a.client_id IS NULL'; }
    if ($pid) { $sql .= ' AND (a.project_id=? OR a.project_id IS NULL)'; $p[] = $pid; }
    $id = DB::value($sql.' ORDER BY a.project_id IS NULL, ABS(TIMESTAMPDIFF(MINUTE, a.occurred_at, ?)) LIMIT 1', [...$p, date('Y-m-d H:i:s', $ts)]);
    return $id ? (int) $id : null; }

  /** Scheduled meetings without notes that a To sort transcript may belong to (within a day of it, same client / project when one was detected), visible and editable for the user. */
  public static function candidates(array $u, array $t, int $limit = 3): array {
    [$w, $p] = self::scope($u, 'a');
    $ts = strtotime($t['meeting_at'] ?: $t['created_at']) ?: time();
    $rows = DB::all("SELECT a.id, a.title, a.occurred_at, a.ends_at, a.client_id, a.project_id, COALESCE(NULLIF(c.company,''), c.name) AS client_name, pr.name AS project_name FROM client_activities a LEFT JOIN clients c ON c.id=a.client_id LEFT JOIN projects pr ON pr.id=a.project_id
      WHERE a.type='meeting' AND a.meeting_status='scheduled' AND a.occurred_at BETWEEN ? AND ? AND NOT EXISTS (SELECT 1 FROM meeting_transcripts mt WHERE mt.activity_id=a.id) AND $w ORDER BY ABS(TIMESTAMPDIFF(MINUTE, a.occurred_at, ?)) LIMIT 10",
      [date('Y-m-d H:i:s', $ts - 86400), date('Y-m-d H:i:s', $ts + 86400), ...$p, date('Y-m-d H:i:s', $ts)]);
    $cid = (int) ($t['client_id'] ?? 0); $pid = (int) ($t['project_id'] ?? 0);
    $rows = array_filter($rows, static fn ($a) => self::canEdit($u, $a) && (!$cid || (int) $a['client_id'] === $cid) && (!$pid || !$a['project_id'] || (int) $a['project_id'] === $pid));
    return array_slice(array_values($rows), 0, $limit); }

  /** Options for the New meeting dialog: clients, projects, team members, contacts (only of visible clients). */
  public static function formData(array $u, ?int $cid, ?int $pid): array {
    [$w, $p] = ClientAccess::where($u, 'c.id');
    $clients = DB::pairs("SELECT c.id, COALESCE(NULLIF(c.company,''), c.name) FROM clients c WHERE $w AND (c.status<>'inactive' OR c.id=?) ORDER BY 2 LIMIT 2000", [...$p, (int) $cid]);
    $projects = [];
    if (feature_on('projects')) { [$pw, $pp] = ProjectAccess::scope($u, 'p.id'); $projects = DB::all("SELECT p.id, p.name, p.client_id FROM projects p WHERE $pw AND (p.status NOT IN ('completed','cancelled') OR p.id=?) ORDER BY p.name LIMIT 2000", [...$pp, (int) $pid]); }
    $projects = array_values(array_filter($projects, static fn ($r) => $cid ? (int) $r['client_id'] === $cid : !$r['client_id'] || isset($clients[$r['client_id']])));
    $cids = $cid ? [$cid] : array_map('intval', array_keys($clients));
    $contacts = $cids ? DB::all('SELECT id, client_id, name, email, designation FROM client_contacts WHERE client_id IN '.DB::in(array_slice($cids, 0, 2000)).' ORDER BY is_primary DESC, name LIMIT 3000', array_slice($cids, 0, 2000)) : [];
    return ['clients' => $clients, 'projects' => $projects, 'contacts' => $contacts, 'team' => DB::all("SELECT id, name, designation, avatar FROM users WHERE status='active' ORDER BY name LIMIT 1000")]; } }
