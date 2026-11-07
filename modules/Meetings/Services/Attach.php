<?php
namespace Modules\Meetings\Services;
use Core\{DB, Request, ValidationException};

/** Transcript or notes added from the Log activity dialog (client page and project page), via the same pipeline as automatic sources. */
final class Attach {
  /** Parsed document from the dialog fields (mt_file upload, mt_fireflies_id pick, mt_text paste) or null when nothing was attached. */
  public static function fromRequest(Request $r, array $u): ?array {
    $f = $r->file('mt_file');
    if ($f) { return self::file($f); }
    if (($ff = $r->str('mt_fireflies_id')) !== '') {
      $key = Fireflies::keyFor($u) ?? throw new ValidationException(['mt_fireflies_id' => 'Connect Fireflies under Meetings › Connections first.']);
      try { $t = Fireflies::one($key, $ff); } catch (FirefliesError $e) { throw new ValidationException(['mt_fireflies_id' => $e->getMessage()]); }
      if (!$t) { throw new ValidationException(['mt_fireflies_id' => 'That Fireflies meeting was not found.']); }
      return Fireflies::toDoc($t) + ['source' => 'fireflies']; }
    $text = trim(mb_substr((string) ($r->input('mt_text') ?? ''), 0, MeetingService::MAX_TEXT));
    if ($text === '') { return null; }
    return Parser::parse($text, 'notes.txt') + ['source' => 'paste']; }

  /** Uploaded transcript file (size and type checked) to a document. */
  public static function file(array $f): array {
    if (($f['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) { throw new ValidationException(['mt_file' => 'The transcript file could not be uploaded.']); }
    $ext = strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, Parser::EXT, true)) { throw new ValidationException(['mt_file' => 'Use a .txt, .vtt, .srt, .docx, .md, .html or .json transcript. PDFs are not supported.']); }
    if ((int) $f['size'] > Parser::MAX_BYTES) { throw new ValidationException(['mt_file' => 'The transcript file is larger than 8 MB.']); }
    if (!is_file((string) $f['tmp_name']) || (!is_uploaded_file((string) $f['tmp_name']) && !defined('NY_TESTING'))) { throw new ValidationException(['mt_file' => 'The transcript file could not be read.']); }
    $doc = Parser::parse((string) file_get_contents((string) $f['tmp_name']), (string) $f['name']);
    if (trim($doc['text']) === '') { throw new ValidationException(['mt_file' => 'No text found in that file.']); }
    return $doc + ['source' => 'upload']; }

  /** The visible log that already holds this meeting (dedupe across uploads, e-mail and Fireflies), or null. */
  public static function duplicateLog(array $doc, array $u, ?int $editing = null): ?array {
    $dup = MeetingService::duplicate($doc['external_id'] ?? null, Parser::hash((string) $doc['text']), (string) $doc['title'], $doc['meeting_at'] ?? null, (string) $doc['text']);
    if (!$dup || !$dup['activity_id'] || (int) $dup['activity_id'] === (int) $editing) { return null; }
    $a = DB::first('SELECT id, title, client_id, project_id FROM client_activities WHERE id=?', [$dup['activity_id']]);
    if (!$a || !MeetingAccess::canSee($u, ['owner_id' => $dup['owner_id'], 'client_id' => $a['client_id'], 'project_id' => $a['project_id']])) { return null; }
    return ['activity_id' => (int) $a['id'], 'title' => $a['title'], 'url' => self::logUrl($a), 'transcript_id' => (int) $dup['id']]; }

  /** Store the document on the saved log entry; returns a short note for the flash message. */
  public static function save(array $doc, array $u, int $activityId, ?int $clientId, ?int $projectId): string {
    $r = MeetingService::ingest($doc, ['source' => $doc['source'] ?? 'upload', 'owner_id' => (int) $u['id'], 'user' => $u, 'activity_id' => $activityId, 'client_id' => $clientId, 'project_id' => $projectId]);
    $t = DB::find('meeting_transcripts', $r['id']);
    if ($t['summary_status'] === 'new' && trim((string) DB::value('SELECT body FROM client_activities WHERE id=?', [$activityId])) === '') { DB::update('client_activities', ['body' => MeetingService::PENDING], 'id=?', [$activityId]); }
    $note = $r['duplicate'] ? 'Transcript attached (merged with the copy that arrived earlier).' : 'Transcript attached.';
    return $note.' '.match ($t['summary_status']) { 'new', 'summarising' => 'Goraiya is writing the summary, it appears in this entry shortly.', 'done' => 'The AI summary is in the entry.', default => 'AI summaries are off, so the notes are kept without a summary.' }; }

  /** activity id => transcript id. */
  public static function forActivities(array $ids): array { return $ids ? array_map('intval', DB::pairs('SELECT activity_id, MIN(id) FROM meeting_transcripts WHERE activity_id IN '.DB::in($ids).' GROUP BY activity_id', $ids)) : []; }

  public static function logUrl(array $a): string { return $a['client_id'] ? '/clients/'.(int) $a['client_id'].'#journey' : '/projects/'.(int) $a['project_id'].'?tab=log'; } }
