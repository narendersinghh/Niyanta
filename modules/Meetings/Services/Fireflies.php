<?php
namespace Modules\Meetings\Services;
use Core\{Audit, Cache, Crypto, DB, Settings};
use Modules\Ai\Services\{AiException, Http};

/**
 * Fireflies.ai GraphQL API (the free plan includes it): per-user keys (Meetings › Connections) and an optional company-wide key (Settings › Meetings),
 * stored encrypted. Sync lists transcripts since the last sync with their sentences and summary; external id "fireflies:<id>" dedupes against
 * uploads and recap e-mails. The free plan allows about 50 requests a day, so each connection is checked at most every 30 minutes and backs off on 429.
 */
final class Fireflies {
  public const URL = 'https://api.fireflies.ai/graphql';
  public const MIN_INTERVAL = 30;
  public const BACKOFF = 60;
  /** Tests: fn(url, headers, body, timeout): ['code','json','raw']. */
  public static ?\Closure $transport = null;
  private const FIELDS = 'id title date duration organizer_email participants meeting_attendees { displayName email } sentences { speaker_name text } summary { overview action_items keywords }';

  public static function request(string $key, string $query, array $vars = [], int $timeout = 30): array {
    $headers = ['Authorization: Bearer '.$key];
    $body = ['query' => $query, 'variables' => (object) $vars];
    try { $r = self::$transport ? (self::$transport)(self::URL, $headers, $body, $timeout) : Http::post(self::URL, $headers, $body, $timeout); }
    catch (AiException $e) { throw new FirefliesError('Could not reach Fireflies. Try again later.', 'network'); }
    $err = $r['json']['errors'][0] ?? null;
    $code = strtolower((string) ($err['extensions']['code'] ?? ''));
    if ($r['code'] === 429 || str_contains($code, 'too_many') || str_contains(strtolower((string) ($err['message'] ?? '')), 'rate limit')) { throw new FirefliesError('Fireflies is rate limiting requests (the free plan allows about 50 a day).', 'rate'); }
    if ($r['code'] === 401 || $r['code'] === 403 || str_contains($code, 'auth') || str_contains($code, 'invalid_api_key')) { throw new FirefliesError('Fireflies rejected the API key. Check it under Meetings › Connections.', 'auth'); }
    if ($r['code'] >= 400 || $err || !is_array($r['json']['data'] ?? null)) { throw new FirefliesError('Fireflies returned an error'.($err ? ': '.mb_substr((string) ($err['message'] ?? ''), 0, 160) : ' (HTTP '.$r['code'].')').'.', 'error'); }
    return $r['json']['data']; }

  /** Recent transcripts with sentences (one request). */
  public static function list(string $key, ?string $since = null, int $limit = 10): array {
    $vars = ['limit' => max(1, min(50, $limit))];
    if ($since) { $vars['fromDate'] = gmdate('Y-m-d\TH:i:s.000\Z', strtotime($since)); }
    return self::request($key, 'query Transcripts($limit: Int, $fromDate: DateTime) { transcripts(limit: $limit, fromDate: $fromDate) { '.self::FIELDS.' } }', $vars)['transcripts'] ?? []; }

  /** Titles of recent transcripts for the picker (cached 5 minutes per key). */
  public static function recent(string $key): array {
    return Cache::remember('ff_recent_'.substr(hash('sha256', $key), 0, 16), 300, static fn () => array_map(static fn ($t) => ['id' => (string) $t['id'], 'title' => (string) ($t['title'] ?? 'Meeting'), 'date' => Parser::when($t['date'] ?? null), 'duration' => isset($t['duration']) ? (int) round((float) $t['duration']) : null],
      self::request($key, 'query Recent($limit: Int) { transcripts(limit: $limit) { id title date duration } }', ['limit' => 15])['transcripts'] ?? [])); }

  public static function one(string $key, string $id): ?array {
    if (!preg_match('/^[A-Za-z0-9_\-]{4,64}$/', $id)) { return null; }
    return Cache::remember('ff_one_'.substr(hash('sha256', $key.$id), 0, 24), 600, static fn () => self::request($key, 'query Transcript($id: String!) { transcript(id: $id) { '.self::FIELDS.' } }', ['id' => $id])['transcript'] ?? null); }

  /** Fireflies transcript object (API or JSON export) to a parsed document. */
  public static function toDoc(array $t): array {
    $lines = [];
    foreach ($t['sentences'] ?? [] as $s) {
      $who = trim((string) ($s['speaker_name'] ?? '')) ?: null; $text = trim((string) ($s['text'] ?? $s['raw_text'] ?? ''));
      if ($text === '') { continue; }
      $n = count($lines);
      if ($n && $lines[$n - 1][0] === $who) { $lines[$n - 1][1] .= ' '.$text; } else { $lines[] = [$who, $text]; } }
    $people = [];
    foreach ($t['meeting_attendees'] ?? [] as $a) { $people[] = ['name' => $a['displayName'] ?? $a['name'] ?? null, 'email' => $a['email'] ?? null]; }
    foreach ((array) ($t['participants'] ?? []) as $p) { foreach (preg_split('/\s*,\s*/', (string) $p) ?: [] as $e) { if ($e !== '') { $people[] = str_contains($e, '@') ? ['name' => null, 'email' => $e] : ['name' => $e, 'email' => null]; } } }
    if (!empty($t['organizer_email'])) { $people[] = ['name' => null, 'email' => $t['organizer_email']]; }
    $sum = $t['summary'] ?? [];
    $parts = [];
    if (!empty($sum['overview'])) { $parts[] = trim((string) $sum['overview']); }
    if (!empty($sum['action_items'])) { $parts[] = "Action items:\n".trim(is_array($sum['action_items']) ? implode("\n", $sum['action_items']) : (string) $sum['action_items']); }
    if (!empty($sum['keywords'])) { $parts[] = 'Keywords: '.(is_array($sum['keywords']) ? implode(', ', $sum['keywords']) : (string) $sum['keywords']); }
    return ['title' => mb_substr(trim((string) ($t['title'] ?? '')) ?: 'Fireflies meeting', 0, 190), 'meeting_at' => Parser::when($t['date'] ?? $t['dateString'] ?? null), 'duration_min' => isset($t['duration']) && is_numeric($t['duration']) ? (int) round((float) $t['duration']) : null,
      'participants' => Parser::people('', $people), 'text' => implode("\n", array_map(static fn ($l) => ($l[0] !== null ? $l[0].': ' : '').$l[1], $lines)), 'summary' => $parts ? implode("\n\n", $parts) : null,
      'external_id' => !empty($t['id']) ? 'fireflies:'.$t['id'] : null, 'tool' => 'fireflies', 'format' => 'fireflies', 'file_name' => null]; }

  /* ---------- connections ---------- */

  public static function userKey(int $uid): ?string { $enc = DB::value("SELECT api_key_enc FROM meeting_connections WHERE user_id=? AND provider='fireflies'", [$uid]); return $enc ? Crypto::decrypt((string) $enc) : null; }
  public static function companyKey(): ?string { $k = (string) Settings::get('meetings.fireflies_key', ''); return $k !== '' ? $k : null; }

  /** Key the user may fetch with in the picker: their own, else the company key for meetings managers. */
  public static function keyFor(array $u): ?string { return self::userKey((int) $u['id']) ?? (MeetingAccess::manager($u) ? self::companyKey() : null); }

  public static function saveKey(int $uid, string $key): void {
    $now = date('Y-m-d H:i:s');
    DB::query("INSERT INTO meeting_connections (user_id, provider, api_key_enc, created_at, updated_at) VALUES (?, 'fireflies', ?, ?, ?) ON DUPLICATE KEY UPDATE api_key_enc=VALUES(api_key_enc), last_status=NULL, backoff_until=NULL, updated_at=VALUES(updated_at)", [$uid, Crypto::encrypt($key), $now, $now]); }

  /** Cron (every 15 minutes): each per-user connection and the company key. */
  public static function syncAll(bool $force = false): string {
    $out = [];
    foreach (DB::all("SELECT c.*, u.status AS user_status FROM meeting_connections c JOIN users u ON u.id=c.user_id WHERE c.provider='fireflies'") as $c) {
      if ($c['user_status'] !== 'active') { continue; }
      $out[] = self::sync($c, $force); }
    if (self::companyKey()) { $out[] = self::sync(null, $force); }
    return $out ? implode('; ', $out) : 'no Fireflies connections'; }

  /**
   * Sync one connection (a meeting_connections row, or null for the company key). Respects the minimum interval (unless forced) and the 429 back-off.
   * Returns a short status line (also stored as the connection's last status).
   */
  public static function sync(?array $c, bool $force = false): string {
    $state = $c ?? Settings::json('meetings.fireflies_company_state');
    $who = $c ? 'user '.$c['user_id'] : 'company key';
    if (!empty($state['backoff_until']) && strtotime($state['backoff_until']) > time()) { return "$who: waiting after rate limit"; }
    if (!$force && !empty($state['last_sync_at']) && strtotime($state['last_sync_at']) > time() - self::MIN_INTERVAL * 60 + 30) { return "$who: synced recently"; }
    $key = $c ? Crypto::decrypt((string) $c['api_key_enc']) : self::companyKey();
    if (!$key) { return "$who: no key"; }
    $user = $c ? DB::first('SELECT u.*, r.slug AS role_slug FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=?', [(int) $c['user_id']]) : null;
    $since = $state['last_sync_at'] ?? null;
    $new = 0; $dup = 0; $logs = 0;
    try {
      foreach (self::list($key, $since ? date('Y-m-d H:i:s', strtotime($since) - 86400) : date('Y-m-d H:i:s', strtotime('-14 days')), 10) as $t) {
        $doc = self::toDoc($t);
        if (trim($doc['text']) === '' && trim((string) $doc['summary']) === '') { continue; }
        $r = MeetingService::ingest($doc, ['source' => 'fireflies', 'owner_id' => $c ? (int) $c['user_id'] : null, 'user' => $user, 'auto' => true]);
        $r['duplicate'] ? $dup++ : $new++; $logs += (int) $r['created_log']; }
      $status = 'OK, '.$new.' new'.($dup ? ", $dup already here" : '').($logs ? ", $logs logged" : '');
      self::state($c, ['last_sync_at' => date('Y-m-d H:i:s'), 'last_status' => $status, 'backoff_until' => null]);
      return "$who: $status"; }
    catch (FirefliesError $e) {
      $back = $e->kind === 'rate' ? date('Y-m-d H:i:s', time() + self::BACKOFF * 60) : null;
      self::state($c, ['last_status' => mb_substr($e->getMessage(), 0, 250), 'backoff_until' => $back]);
      if ($e->kind === 'auth' && $c) { Audit::log('meetings.fireflies.auth_failed', 'user', (int) $c['user_id'], [], null); }
      return "$who: ".$e->getMessage(); } }

  private static function state(?array $c, array $set): void {
    if ($c) { DB::update('meeting_connections', $set + ['updated_at' => date('Y-m-d H:i:s')], 'id=?', [$c['id']]); return; }
    Settings::set('meetings.fireflies_company_state', array_filter($set + Settings::json('meetings.fireflies_company_state'), static fn ($v) => $v !== null)); } }

