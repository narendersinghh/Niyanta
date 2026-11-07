<?php
namespace Modules\Meetings\Services;
use Core\{DB, Settings};
use Modules\Crm\Services\ClientAccess;
use Modules\Projects\Services\ProjectAccess;

/**
 * Notes inbox ("invite Niyanta"): one mailbox read over IMAP every 5 minutes (or piped: php cron.php --meeting-mail < message.eml).
 * Per-project and per-client plus-addresses (notes+p12@, notes+c5@) target a log directly. Mail is accepted only from active users or
 * allowed notetaker domains; everything else is dropped and counted in meeting_mail_log. Recap e-mails (Gemini, Zoom, Fireflies, Otter,
 * tl;dv, Tactiq, Read.ai) are recognised and cleaned; transcript attachments (.txt .vtt .srt .docx .md .html .json) are parsed, PDFs skipped.
 */
final class Inbox {
  public const DOMAINS = ['google.com', 'zoom.us', 'fireflies.ai', 'otter.ai', 'tldv.io', 'tactiq.io', 'read.ai'];
  public const MAX_PER_RUN = 20;

  public static function address(): ?string { $a = strtolower(trim((string) Settings::get('meetings.inbox_address', ''))); return filter_var($a, FILTER_VALIDATE_EMAIL) ? $a : null; }

  /** notes+p12@domain / notes+c5@domain, or null when no inbox address is set. */
  public static function plusAddress(string $kind, int $id): ?string {
    $a = self::address();
    if (!$a || !in_array($kind, ['p', 'c'], true) || $id <= 0) { return null; }
    [$local, $dom] = explode('@', $a, 2);
    return explode('+', $local)[0].'+'.$kind.$id.'@'.$dom; }

  /** Target from the recipients: ['p' | 'c', id] for a plus-address of our inbox, else null. */
  public static function target(array $recipients): ?array {
    $a = self::address();
    if (!$a) { return null; }
    [$local, $dom] = explode('@', $a, 2); $local = explode('+', $local)[0];
    foreach ($recipients as $r) { if (preg_match('/^'.preg_quote($local, '/').'\+([pc])(\d{1,10})@'.preg_quote($dom, '/').'$/i', trim((string) $r), $m)) { return [strtolower($m[1]), (int) $m[2]]; } }
    return null; }

  public static function domains(): array {
    $v = trim((string) Settings::get('meetings.allowed_domains', ''));
    $list = $v === '' ? self::DOMAINS : preg_split('/[\s,;]+/', strtolower($v));
    return array_values(array_unique(array_filter(array_map(static fn ($d) => ltrim(trim((string) $d), '@.'), $list ?: [])))); }

  /** [allowed, user row|null, reason] for a sender address. */
  public static function sender(?string $from): array {
    $from = strtolower(trim((string) $from));
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) { return [false, null, 'no valid sender']; }
    $u = DB::first("SELECT u.*, r.slug AS role_slug FROM users u JOIN roles r ON r.id=u.role_id WHERE LOWER(u.email)=? AND u.status='active'", [$from]);
    if ($u) { return [true, $u, 'team member']; }
    $d = Detector::domain($from);
    foreach (self::domains() as $x) { if ($d === $x || str_ends_with($d, '.'.$x)) { return [true, null, 'notetaker '.$x]; } }
    return [false, null, 'sender '.$d.' is not allowed']; }

  public static function configured(): bool { return (string) Settings::get('meetings.imap_host', '') !== '' && (string) Settings::get('meetings.imap_username', '') !== '' && (string) Settings::get('meetings.imap_password', '') !== ''; }

  /** Cron: read up to MAX_PER_RUN unseen messages, process each, mark it seen. $client is injectable for tests. */
  public static function run(?Imap $client = null): string {
    if (!$client && !self::configured()) { return 'inbox not configured'; }
    $start = time(); $n = 0; $acc = 0;
    try {
      $c = $client ?? Imap::connect((string) Settings::get('meetings.imap_host'), (int) Settings::get('meetings.imap_port', 993) ?: 993, (string) Settings::get('meetings.imap_encryption', 'ssl'), 20);
      if (!$client) { $c->login((string) Settings::get('meetings.imap_username'), (string) Settings::get('meetings.imap_password')); }
      $c->select((string) Settings::get('meetings.imap_folder', 'INBOX') ?: 'INBOX');
      foreach (array_slice($c->unseen(), 0, self::MAX_PER_RUN) as $uid) {
        if (time() - $start > 90) { break; }
        $raw = $c->fetch($uid);
        $r = self::process($raw, 'imap');
        $c->seen($uid);
        $n++; $acc += (int) in_array($r['outcome'], ['accepted', 'duplicate'], true); }
      $c->logout();
      $msg = "checked $n message".($n === 1 ? '' : 's').", $acc accepted";
      Settings::set('meetings.inbox_state', ['at' => date('Y-m-d H:i:s'), 'status' => $msg, 'ok' => true]);
      return $msg; }
    catch (\Throwable $e) {
      Settings::set('meetings.inbox_state', ['at' => date('Y-m-d H:i:s'), 'status' => mb_substr($e->getMessage(), 0, 250), 'ok' => false]);
      throw new \RuntimeException('Notes inbox: '.$e->getMessage()); } }

  /** Process one raw message. Returns ['outcome' => accepted|duplicate|dropped|ignored|error, 'reason', 'transcript_id']. */
  public static function process(string $raw, string $channel = 'pipe'): array {
    $log = static function (array $m, string $outcome, string $reason, ?int $tid = null) use ($channel): array {
      DB::insert('meeting_mail_log', ['channel' => $channel, 'message_id' => $m['message_id'] ? mb_substr($m['message_id'], 0, 190) : null, 'from_email' => $m['from'] ? mb_substr($m['from'], 0, 190) : null, 'subject' => mb_substr((string) $m['subject'], 0, 190), 'outcome' => $outcome, 'reason' => mb_substr($reason, 0, 255), 'transcript_id' => $tid, 'created_at' => date('Y-m-d H:i:s')]);
      return ['outcome' => $outcome, 'reason' => $reason, 'transcript_id' => $tid]; };
    if (strlen($raw) > 26214400) { $raw = substr($raw, 0, 26214400); }
    $m = Mime::parse($raw);
    $h = $m['headers'];
    if ($m['message_id'] && DB::value("SELECT 1 FROM meeting_mail_log WHERE message_id=? AND outcome IN ('accepted','duplicate')", [$m['message_id']])) { return ['outcome' => 'duplicate', 'reason' => 'message already processed', 'transcript_id' => null]; }
    $auth = strtolower((string) ($h['authentication-results'] ?? ''));
    if ($auth !== '' && (str_contains($auth, 'dmarc=fail') || (str_contains($auth, 'spf=fail') && !str_contains($auth, 'dkim=pass')))) { return $log($m, 'dropped', 'failed sender authentication (SPF/DMARC)'); }
    if (preg_match('/auto-replied|auto-generated/i', (string) ($h['auto-submitted'] ?? '')) && !Recap::tool($m['from'], (string) $m['subject']) || preg_match('/^(automatic reply|out of office|auto:)/i', (string) $m['subject'])) { return $log($m, 'ignored', 'automatic reply'); }
    if ($m['from'] && $m['from'] === self::address()) { return $log($m, 'ignored', 'sent by the notes inbox itself'); }
    [$ok, $user, $why] = self::sender($m['from']);
    if (!$ok) { return $log($m, 'dropped', $why); }
    $body = Mime::bodyText($m);
    $fwd = Recap::forwarded($body);
    $tool = Recap::tool($fwd['from'] ?? $m['from'], (string) $m['subject'].' '.$body);
    $clean = Recap::clean($body);
    /* the largest transcript attachment, if any */
    $att = null; $skipped = [];
    foreach ($m['attachments'] as $a) {
      $ext = strtolower(pathinfo($a['name'], PATHINFO_EXTENSION));
      if (!in_array($ext, Parser::EXT, true)) { if ($ext === 'pdf') { $skipped[] = $a['name']; } continue; }
      if ($a['size'] > Parser::MAX_BYTES) { continue; }
      if (!$att || $a['size'] > $att['size']) { $att = $a; } }
    if ($att) {
      $doc = Parser::parse($att['content'], $att['name']);
      if (mb_strlen($clean) > 40) { $doc['summary'] = trim(($doc['summary'] ? $doc['summary']."\n\n" : '').$clean); } }
    else { $doc = Parser::parse($clean, 'email.txt'); $doc['text'] = $clean; }
    if (trim((string) $doc['text']) === '' && trim((string) $doc['summary']) === '') { return $log($m, 'ignored', $skipped ? 'only a PDF attachment (not supported)' : 'no notes or transcript in the message'); }
    $subject = (string) ($fwd['subject'] ?? $m['subject']);
    $doc['title'] = $subject !== '' ? Recap::title($subject) : ($doc['title'] ?: 'Meeting notes');
    $doc['tool'] = $doc['tool'] ?: $tool;
    $doc['meeting_at'] = $doc['meeting_at'] ?: Parser::findDate($subject.' '.mb_substr($clean, 0, 3000)) ?: (!empty($fwd['date']) && ($ts = strtotime($fwd['date'])) ? date('Y-m-d H:i:s', $ts) : $m['date']);
    if (!$doc['external_id'] && ($ff = Recap::firefliesId($body.' '.$m['html']))) { $doc['external_id'] = 'fireflies:'.$ff; }
    $inbox = self::address();
    $emails = array_values(array_filter(array_merge($m['to'], $m['cc'], [$fwd['from'] ?? null]), static fn ($e) => $e && $e !== $inbox && !self::target([$e]) && !Recap::tool($e)));
    /* owner: the sending team member, else the first team member among the recipients */
    if (!$user && $emails) { $user = DB::first("SELECT u.*, r.slug AS role_slug FROM users u JOIN roles r ON r.id=u.role_id WHERE u.status='active' AND LOWER(u.email) IN ".DB::in($emails).' ORDER BY u.id LIMIT 1', $emails); }
    $meta = ['source' => 'email', 'owner_id' => $user ? (int) $user['id'] : null, 'user' => $user, 'auto' => true, 'emails' => $emails, 'sender_email' => $m['from']];
    if ($t = self::target($m['recipients'])) {
      [$kind, $id] = $t;
      $row = $kind === 'p' ? DB::first('SELECT id, name, client_id FROM projects WHERE id=?', [$id]) : DB::first("SELECT id, COALESCE(NULLIF(company,''), name) AS name FROM clients WHERE id=?", [$id]);
      $allowed = $row && (!$user || ($kind === 'p' ? feature_on('projects') && ProjectAccess::canSee($user, $id) : ClientAccess::canSee($user, $id)));
      if ($allowed) { $meta[$kind === 'p' ? 'project_id' : 'client_id'] = $id; $meta['explicit_reason'] = 'Sent to '.self::plusAddress($kind, $id).' ('.$row['name'].')'; $meta['user'] = null; } }
    try { $r = MeetingService::ingest($doc, $meta); }
    catch (\InvalidArgumentException $e) { return $log($m, 'ignored', $e->getMessage()); }
    return $log($m, $r['duplicate'] ? 'duplicate' : 'accepted', ($r['duplicate'] ? 'merged with an existing meeting' : ($r['created_log'] ? 'logged' : 'to sort')).' · '.$why.($skipped ? ' · PDF skipped' : ''), (int) $r['id']); }

  /** Inbox log counts of the last 7 days: outcome => n. */
  public static function stats(): array { return DB::pairs('SELECT outcome, COUNT(*) FROM meeting_mail_log WHERE created_at >= ? GROUP BY outcome', [date('Y-m-d H:i:s', strtotime('-7 days'))]); } }
