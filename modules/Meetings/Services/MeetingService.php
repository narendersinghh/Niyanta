<?php
namespace Modules\Meetings\Services;
use Core\{Audit, DB, Notify, Settings};

/**
 * ONE ingestion pipeline for every source (manual upload / paste, notes inbox, Fireflies):
 * parse > dedupe (same external id, same normalised content as any copy merged before, or same title within ±3 hours on the same day with high text overlap) >
 * detect client / project > store > link to the given log, or create the meeting log (explicit target or high confidence from an automatic source),
 * otherwise "To sort" > queue the Goraiya summary. Niyanta never records audio: the text always comes from the meeting platform or a notes tool.
 */
final class MeetingService {
  public const PENDING = 'Goraiya is preparing the AI summary of this meeting. It appears here automatically when ready.';
  public const MAX_TEXT = 400000;

  public static function autoCreate(): bool { return Settings::bool('meetings.auto_create', true); }

  /**
   * @param array $doc parsed document (Parser::parse shape)
   * @param array $meta source, owner_id, user (acting user row for scoping, null = unrestricted), client_id / project_id (explicit target), explicit_reason,
   *   confirmed (user picked the target: create the log now), auto (automatic source), activity_id (attach to this log), emails (extra participant e-mails), sender_email
   * @return array id, duplicate, activity_id, status, confidence, created_log, client_id, project_id
   */
  public static function ingest(array $doc, array $meta): array {
    $text = trim(mb_substr((string) ($doc['text'] ?? ''), 0, self::MAX_TEXT));
    $summary = trim((string) ($doc['summary'] ?? ''));
    if ($text === '' && $summary === '') { throw new \InvalidArgumentException('The transcript or notes are empty.'); }
    if ($text === '') { $text = $summary; }
    $doc['participants'] = Parser::people('', $doc['participants'] ?? []);
    foreach ($meta['emails'] ?? [] as $e) { $doc['participants'][] = ['name' => null, 'email' => strtolower($e)]; }
    $doc['participants'] = Parser::people('', $doc['participants']);
    $hash = Parser::hash($text);
    $title = mb_substr(trim((string) ($doc['title'] ?? '')) ?: 'Meeting', 0, 190);
    $now = date('Y-m-d H:i:s');
    if ($dup = self::duplicate($doc['external_id'] ?? null, $hash, $title, $doc['meeting_at'] ?? null, $text)) { return self::merge($dup, $doc, $text, $hash, $meta); }
    $det = Detector::detect(['user' => $meta['user'] ?? null, 'project_id' => $meta['project_id'] ?? null, 'client_id' => $meta['client_id'] ?? null, 'explicit_reason' => $meta['explicit_reason'] ?? null,
      'title' => $title, 'text' => $text, 'emails' => array_filter(array_column($doc['participants'], 'email'))]);
    $row = ['owner_id' => $meta['owner_id'] ?? null, 'source' => $meta['source'] ?? 'upload', 'tool' => $doc['tool'] ?? null, 'external_id' => $doc['external_id'] ?? null, 'content_hash' => $hash, 'title' => $title,
      'meeting_at' => $doc['meeting_at'] ?? null, 'duration_min' => $doc['duration_min'] ?? null, 'participants' => json_encode($doc['participants'], JSON_UNESCAPED_UNICODE), 'transcript' => $text, 'source_summary' => $summary !== '' && $summary !== $text ? mb_substr($summary, 0, 60000) : null,
      'chars' => mb_strlen($text), 'file_name' => $doc['file_name'] ?? null, 'sender_email' => $meta['sender_email'] ?? null, 'client_id' => $det['client_id'], 'project_id' => $det['project_id'], 'confidence' => $det['confidence'],
      'reasons' => json_encode($det['reasons'], JSON_UNESCAPED_UNICODE), 'status' => 'to_sort', 'summary_status' => Summarizer::available() ? 'new' : 'skipped', 'created_at' => $now, 'updated_at' => $now];
    $id = DB::insert('meeting_transcripts', $row);
    if ($hash) { DB::query('INSERT IGNORE INTO meeting_hashes (hash, transcript_id) VALUES (?,?)', [$hash, $id]); }
    $t = DB::find('meeting_transcripts', $id);
    $created = false;
    if (!empty($meta['activity_id'])) { self::link($t, (int) $meta['activity_id']); }
    elseif ((!empty($meta['confirmed']) || (!empty($meta['auto']) && $det['confidence'] === 'high' && self::autoCreate())) && ($det['client_id'] || $det['project_id'])) { self::createLog($t); $created = true; }
    $t = DB::find('meeting_transcripts', $id);
    Audit::log('meetings.transcript.ingest', 'meeting', $id, ['source' => $row['source'], 'tool' => $row['tool'], 'confidence' => $det['confidence'], 'status' => $t['status'], 'log' => $t['activity_id']], $meta['owner_id'] ?? null);
    if (!empty($meta['auto'])) { self::notify($t, $created); }
    return ['id' => $id, 'duplicate' => false, 'activity_id' => $t['activity_id'] ? (int) $t['activity_id'] : null, 'status' => $t['status'], 'confidence' => $det['confidence'], 'created_log' => $created, 'client_id' => $t['client_id'], 'project_id' => $t['project_id']]; }

  /** An existing transcript of the same meeting, or null. */
  public static function duplicate(?string $ext, ?string $hash, string $title, ?string $at, string $text): ?array {
    if ($ext && ($r = DB::first('SELECT * FROM meeting_transcripts WHERE external_id=? ORDER BY id LIMIT 1', [$ext]))) { return $r; }
    if ($hash && ($r = DB::first('SELECT mt.* FROM meeting_hashes h JOIN meeting_transcripts mt ON mt.id=h.transcript_id WHERE h.hash=? ORDER BY mt.id LIMIT 1', [$hash]))) { return $r; }
    $ts = strtotime($at ?: 'now');
    $from = max(strtotime(date('Y-m-d 00:00:00', $ts)), $ts - 10800); $to = min(strtotime(date('Y-m-d 23:59:59', $ts)), $ts + 10800);
    foreach (DB::all('SELECT * FROM meeting_transcripts WHERE COALESCE(meeting_at, created_at) BETWEEN ? AND ? ORDER BY id LIMIT 30', [date('Y-m-d H:i:s', $from), date('Y-m-d H:i:s', $to)]) as $r) {
      if (Parser::sameTitle($title, (string) $r['title']) && Parser::overlap($text, (string) ($r['transcript'] ?? $r['source_summary'] ?? '')) >= 0.6) { return $r; } }
    return null; }

  /** Keep one record: the longer text wins, missing details are filled in, the existing log is reused. */
  private static function merge(array $old, array $doc, string $text, ?string $hash, array $meta): array {
    $up = [];
    if (mb_strlen($text) > (int) $old['chars'] && $old['purged_at'] === null) { $up += ['transcript' => $text, 'content_hash' => $hash, 'chars' => mb_strlen($text)]; }
    foreach (['external_id', 'meeting_at', 'duration_min', 'tool', 'file_name'] as $k) { if (empty($old[$k]) && !empty($doc[$k])) { $up[$k] = $doc[$k]; } }
    if (empty($old['source_summary']) && !empty($doc['summary']) && $doc['summary'] !== $text) { $up['source_summary'] = mb_substr((string) $doc['summary'], 0, 60000); }
    $people = Parser::people('', array_merge(json_decode((string) $old['participants'], true) ?: [], $doc['participants'] ?? []));
    if (count($people) > count(json_decode((string) $old['participants'], true) ?: [])) { $up['participants'] = json_encode($people, JSON_UNESCAPED_UNICODE); }
    if (empty($old['owner_id']) && !empty($meta['owner_id'])) { $up['owner_id'] = $meta['owner_id']; }
    if ($up) { DB::update('meeting_transcripts', $up + ['updated_at' => date('Y-m-d H:i:s')], 'id=?', [$old['id']]); }
    if ($hash) { DB::query('INSERT IGNORE INTO meeting_hashes (hash, transcript_id) VALUES (?,?)', [$hash, $old['id']]); }
    $t = DB::find('meeting_transcripts', (int) $old['id']);
    if (!$t['activity_id'] && !empty($meta['activity_id'])) { self::link($t, (int) $meta['activity_id']); }
    elseif (!$t['activity_id'] && $t['status'] === 'to_sort' && (!empty($meta['project_id']) || !empty($meta['client_id'])) && (!empty($meta['confirmed']) || !empty($meta['auto']))) {
      $det = Detector::detect(['user' => $meta['user'] ?? null, 'project_id' => $meta['project_id'] ?? null, 'client_id' => $meta['client_id'] ?? null, 'title' => $t['title'], 'text' => (string) $t['transcript']]);
      if ($det['client_id'] || $det['project_id']) { DB::update('meeting_transcripts', ['client_id' => $det['client_id'], 'project_id' => $det['project_id'], 'confidence' => $det['confidence'], 'reasons' => json_encode($det['reasons'])], 'id=?', [$t['id']]); self::createLog(DB::find('meeting_transcripts', (int) $t['id'])); } }
    $t = DB::find('meeting_transcripts', (int) $old['id']);
    Audit::log('meetings.transcript.merge', 'meeting', (int) $t['id'], ['source' => $meta['source'] ?? null, 'updated' => array_keys($up)], $meta['owner_id'] ?? null);
    return ['id' => (int) $t['id'], 'duplicate' => true, 'activity_id' => $t['activity_id'] ? (int) $t['activity_id'] : null, 'status' => $t['status'], 'confidence' => $t['confidence'], 'created_log' => false, 'client_id' => $t['client_id'], 'project_id' => $t['project_id']]; }

  /** Attach a transcript to an existing log entry (its client and project win). */
  public static function link(array $t, int $activityId): void {
    $a = DB::first('SELECT id, client_id, project_id FROM client_activities WHERE id=?', [$activityId]);
    if (!$a) { return; }
    DB::update('meeting_transcripts', ['activity_id' => $activityId, 'status' => 'linked', 'client_id' => $a['client_id'], 'project_id' => $a['project_id'], 'updated_at' => date('Y-m-d H:i:s')], 'id=?', [$t['id']]);
    $t = DB::find('meeting_transcripts', (int) $t['id']);
    if ($t['summary_status'] === 'done') { Summarizer::writeToLog($t); } elseif ($t['summary_status'] === 'skipped') { self::fillBody($t); } }

  /** Create the meeting log (client_activities type meeting) for a sorted transcript. */
  public static function createLog(array $t): int {
    $at = $t['meeting_at'] ?: $t['created_at'];
    $aid = DB::insert('client_activities', ['client_id' => $t['client_id'] ?: null, 'project_id' => $t['project_id'] ?: null, 'type' => 'meeting', 'title' => mb_substr((string) $t['title'], 0, 190), 'body' => self::placeholder($t), 'is_internal' => 0, 'occurred_at' => $at, 'user_id' => $t['owner_id'] ?: null, 'created_at' => date('Y-m-d H:i:s')]);
    DB::update('meeting_transcripts', ['activity_id' => $aid, 'status' => 'linked', 'updated_at' => date('Y-m-d H:i:s')], 'id=?', [$t['id']]);
    $t = DB::find('meeting_transcripts', (int) $t['id']);
    if ($t['summary_status'] === 'done') { Summarizer::writeToLog($t); }
    Audit::log('meetings.log.create', $t['client_id'] ? 'client' : 'project', (int) ($t['client_id'] ?: $t['project_id']), ['activity_id' => $aid, 'transcript_id' => (int) $t['id'], 'confidence' => $t['confidence']], $t['owner_id'] ? (int) $t['owner_id'] : null);
    return $aid; }

  /** Body of a new log until the summary is ready (or the notes themselves when AI summaries are off). */
  public static function placeholder(array $t): string {
    if ($t['summary_status'] !== 'skipped') { return self::PENDING; }
    return self::plainBody($t); }

  /** AI off or not set up: a readable excerpt of the notes tool's own summary or the notes. */
  public static function plainBody(array $t): string {
    $src = trim((string) ($t['source_summary'] ?: $t['transcript']));
    $head = 'Meeting notes from '.Recap::label($t['tool'], $t['source']).'. AI summary is off, open the full transcript for every detail.';
    return mb_substr($head."\n\n".$src, 0, 4900); }

  /** Write the notes excerpt into a linked log whose body is empty or still the placeholder. */
  public static function fillBody(array $t): void {
    if (!$t['activity_id']) { return; }
    $body = (string) DB::value('SELECT body FROM client_activities WHERE id=?', [$t['activity_id']]);
    if (trim($body) === '' || $body === self::PENDING) { DB::update('client_activities', ['body' => self::plainBody($t)], 'id=?', [$t['activity_id']]); } }

  /** Automatic arrivals: the owner (or the account team / project manager) hears about a new log; unmatched notes go to "To sort". */
  private static function notify(array $t, bool $created): void {
    $url = '/meetings/'.$t['id'];
    $to = $t['owner_id'] ? [(int) $t['owner_id']] : [];
    if (!$to && $t['client_id']) { $to = array_map('intval', DB::column('SELECT user_id FROM client_managers WHERE client_id=?', [$t['client_id']])); }
    if (!$to && $t['project_id']) { $to = array_filter([(int) DB::value('SELECT manager_id FROM projects WHERE id=?', [$t['project_id']])]); }
    if ($created) { foreach (array_unique($to) as $uid) { Notify::user($uid, 'Meeting notes logged: '.$t['title'], 'Added to the client log automatically. Review the summary and action items.', $url, false, 'message'); } return; }
    if ($t['owner_id']) { Notify::user((int) $t['owner_id'], 'Meeting notes to sort: '.$t['title'], 'Pick the client and project for these notes.', '/meetings?tab=sort', false, 'message'); return; }
    Notify::permission('meetings.manage', 'Meeting notes to sort: '.$t['title'], 'Notes arrived without a clear client or project.', '/meetings?tab=sort'); }

  /** Delete transcript text older than the retention period (the summary in the log stays). */
  public static function retention(): string {
    $days = trim((string) Settings::get('meetings.retention_days', '365'));
    if ($days === '' || (int) $days <= 0) { $n = 0; }
    else { $n = DB::query('UPDATE meeting_transcripts SET transcript=NULL, purged_at=NOW() WHERE transcript IS NOT NULL AND created_at < ?', [date('Y-m-d H:i:s', strtotime('-'.(int) $days.' days'))])->rowCount(); }
    $m = DB::delete('meeting_mail_log', 'created_at < ?', [date('Y-m-d H:i:s', strtotime('-90 days'))]);
    return "purged $n transcripts, $m inbox log rows"; } }
