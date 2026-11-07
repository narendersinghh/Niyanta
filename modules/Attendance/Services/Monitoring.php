<?php
namespace Modules\Attendance\Services;
use Core\{Audit, DB, Gate, Settings, Storage};

/** Consent-based screenshot monitoring (desktop tracker) and personal device tokens. */
final class Monitoring {
  public const POLICY_VERSION = '2026-1';
  public const MAX_BYTES = 3145728;

  public static function enabled(): bool { return Settings::bool('attendance.screenshots_enabled'); }
  /** Seconds between captures (10 to 3600). Falls back to the old minutes setting until the settings are saved again. */
  public static function intervalSeconds(): int {
    $s = Settings::get('attendance.screenshot_interval_seconds');
    if ($s === null || $s === '') { $s = 60 * (int) Settings::get('attendance.screenshot_interval', 3); }
    return max(10, min(3600, (int) $s ?: 180)); }
  /** "every 3 min" / "every 45 s". */
  public static function intervalLabel(): string { $s = self::intervalSeconds(); return $s % 60 === 0 ? ($s === 60 ? 'every minute' : 'every '.intdiv($s, 60).' min') : 'every '.($s > 60 ? intdiv($s, 60).' min '.($s % 60).' s' : $s.' s'); }
  /** Show a small notice on the desktop each time a screenshot is taken (default on); may users turn it off? */
  public static function notifyDefault(): bool { return Settings::bool('attendance.screenshot_notify', true); }
  public static function notifyOptional(): bool { return Settings::bool('attendance.screenshot_notify_optional', true); }

  /** Monitoring notice shown before consent (web and desktop app). */
  public static function policy(): array {
    return ['version' => self::POLICY_VERSION, 'title' => 'Screen monitoring notice', 'points' => [
      'Screenshots of all your screens are taken only while you are tracking time, never while paused or after you end your day.',
      'One capture '.self::intervalLabel().', scaled down, together with your activity level (keyboard and mouse activity: whether there was input, never what you type or counts).',
      self::notifyDefault() ? 'A small notice appears each time a screenshot is taken.' : 'The tracker shows a visible indicator in the tray while capturing.',
      'You can always see your own screenshots. Your manager or HR may view them if they hold monitoring access.',
      'Images are stored privately and deleted automatically after '.self::retention().' days.',
      'You can revoke consent at any time in the web app. Capture stops immediately.']]; }
  public static function retention(): int { return max(1, (int) Settings::get('attendance.screenshot_retention_days', 60)); }

  public static function consent(int $uid): ?array { return DB::first('SELECT * FROM monitoring_consents WHERE user_id=? AND revoked_at IS NULL AND policy_version=? ORDER BY id DESC LIMIT 1', [$uid, self::POLICY_VERSION]); }

  public static function giveConsent(int $uid, string $ip): void {
    DB::transaction(static function () use ($uid, $ip): void {
      AttendanceService::lock($uid);
      if (self::consent($uid)) { return; }
      $id = DB::insert('monitoring_consents', ['user_id' => $uid, 'policy_version' => self::POLICY_VERSION, 'consented_at' => date('Y-m-d H:i:s'), 'ip' => $ip]);
      Audit::log('monitoring.consent', 'monitoring_consent', $id, ['version' => self::POLICY_VERSION], $uid); }); }

  public static function revokeConsent(int $uid): int {
    $n = DB::query('UPDATE monitoring_consents SET revoked_at=NOW() WHERE user_id=? AND revoked_at IS NULL', [$uid])->rowCount();
    if ($n) { Audit::log('monitoring.revoke', 'user', $uid, [], $uid); }
    return $n; }

  /** May $viewer see screenshots of $ownerId? Owners always; others need the permission and team scope. */
  public static function canView(array $viewer, int $ownerId): bool { return (int) $viewer['id'] === $ownerId || (Gate::allows('attendance.screenshots.view', $viewer) && Team::includes($viewer, $ownerId)); }

  /**
   * Store an uploaded capture. It is linked to the time entry that covered the capture moment (running now, or for
   * captures queued while offline, a stopped entry), so captures outside tracked time are refused.
   */
  public static function store(int $uid, array $file, string $source, ?int $activity, ?int $display = null, ?string $capturedAt = null, ?int $tokenId = null, bool $meeting = false): int {
    if (!self::enabled()) { throw new \DomainException('Screenshot monitoring is turned off.'); }
    if (!self::consent($uid)) { throw new \DomainException('Monitoring consent is required before capturing.'); }
    $now = time();
    $at = $capturedAt !== null && ($t = strtotime($capturedAt)) !== false && $t <= $now + 60 && $t >= $now - 86400 * 2 ? min($t, $now) : $now;
    $taken = date('Y-m-d H:i:s', $at);
    $e = DB::first('SELECT id FROM time_entries WHERE user_id=? AND started_at<=? AND (is_running=1 OR ended_at>?) ORDER BY is_running DESC, id DESC LIMIT 1', [$uid, $taken, $taken]);
    if (!$e) { throw new \DomainException('Screenshots are only accepted while you are tracking time.'); }
    $gap = max(5, min(20, intdiv(self::intervalSeconds(), 2)));
    if (DB::value('SELECT 1 FROM screenshots WHERE user_id=? AND COALESCE(display_index,0)=? AND taken_at>? AND taken_at<? LIMIT 1', [$uid, (int) $display, date('Y-m-d H:i:s', $at - $gap), date('Y-m-d H:i:s', $at + $gap)])) { throw new \DomainException('Screenshots are arriving too quickly.'); }
    $info = @getimagesize($file['tmp_name'] ?? '');
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true) || $info[0] > 4096 || $info[1] > 4096) { throw new \DomainException('The screenshot must be a JPEG, PNG or WebP image up to 4096px.'); }
    try { $s = Storage::store($file, 'screenshots/u'.$uid, ['jpg', 'jpeg', 'png', 'webp'], self::MAX_BYTES); }
    catch (\Core\ValidationException $ex) { throw new \DomainException((string) (array_values($ex->errors)[0] ?? 'Upload failed.')); }
    $id = DB::insert('screenshots', ['user_id' => $uid, 'time_entry_id' => $e['id'], 'disk_path' => $s['path'], 'taken_at' => $taken, 'source' => $source, 'activity_pct' => $activity === null ? null : max(0, min(100, $activity)), 'display_index' => $display === null ? null : max(0, min(15, $display)), 'agent_token_id' => $tokenId,
      'meeting' => $meeting ? 1 : 0]);
    Audit::log('monitoring.screenshot', 'screenshot', $id, ['source' => $source, 'display' => $display], $uid);
    return $id; }

  /** Cron: delete screenshots (files + rows) older than the retention period. */
  public static function cleanup(): string {
    $cut = date('Y-m-d H:i:s', strtotime('-'.self::retention().' days'));
    $n = 0;
    do {
      $rows = DB::all('SELECT id, disk_path FROM screenshots WHERE taken_at<? ORDER BY id LIMIT 500', [$cut]);
      foreach ($rows as $r) { @unlink(Storage::path($r['disk_path'])); }
      if ($rows) { DB::query('DELETE FROM screenshots WHERE id IN '.DB::in(array_column($rows, 'id')), array_column($rows, 'id')); $n += count($rows); } }
    while (count($rows) === 500);
    return "deleted $n"; }

  /**
   * Create a personal device token; returns [plain token (shown once), id]. A desktop sign-in replaces the previous token
   * of the same device name; at most 5 tokens stay active (the oldest desktop token is revoked to make room).
   */
  public static function createToken(int $uid, string $name, string $kind = 'token', ?string $os = null, ?string $version = null): array {
    $name = mb_substr(trim($name) ?: 'Desktop', 0, 100);
    return DB::transaction(static function () use ($uid, $name, $kind, $os, $version): array {
      AttendanceService::lock($uid);
      if ($kind === 'desktop') {
        DB::query("UPDATE agent_tokens SET revoked_at=NOW() WHERE user_id=? AND kind='desktop' AND name=? AND revoked_at IS NULL", [$uid, $name]);
        $n = (int) DB::value('SELECT COUNT(*) FROM agent_tokens WHERE user_id=? AND revoked_at IS NULL', [$uid]);
        if ($n >= 5) { DB::query("UPDATE agent_tokens SET revoked_at=NOW() WHERE user_id=? AND revoked_at IS NULL AND kind='desktop' ORDER BY COALESCE(last_used_at, created_at) LIMIT ".($n - 4), [$uid]); } }
      if ((int) DB::value('SELECT COUNT(*) FROM agent_tokens WHERE user_id=? AND revoked_at IS NULL', [$uid]) >= 5) { throw new \DomainException('You can have at most 5 connected devices. Disconnect one first.'); }
      $plain = 'nyt_'.bin2hex(random_bytes(24));
      $id = DB::insert('agent_tokens', ['user_id' => $uid, 'name' => $name, 'kind' => $kind, 'device_os' => $os !== null ? mb_substr($os, 0, 40) : null, 'app_version' => $version !== null ? mb_substr($version, 0, 40) : null, 'token_hash' => hash('sha256', $plain), 'created_at' => date('Y-m-d H:i:s')]);
      Audit::log('agent.token_create', 'agent_token', $id, ['name' => $name, 'kind' => $kind, 'os' => $os, 'version' => $version], $uid);
      return [$plain, $id]; }); }

  public static function revokeToken(int $uid, int $id): bool {
    $n = DB::query('UPDATE agent_tokens SET revoked_at=NOW() WHERE id=? AND user_id=? AND revoked_at IS NULL', [$id, $uid])->rowCount();
    if ($n) { Audit::log('agent.token_revoke', 'agent_token', $id, [], $uid); }
    return $n > 0; }

  /** Resolve a bearer token to an active user (with role) or null. */
  public static function tokenUser(string $plain): ?array {
    if (!preg_match('/^nyt_[a-f0-9]{48}$/', $plain)) { return null; }
    $t = DB::first('SELECT id, user_id, name, last_used_at, app_version FROM agent_tokens WHERE token_hash=? AND revoked_at IS NULL LIMIT 1', [hash('sha256', $plain)]);
    if (!$t) { return null; }
    $u = DB::first("SELECT u.*, r.slug AS role_slug, r.name AS role_name FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=? AND u.status='active' LIMIT 1", [$t['user_id']]);
    if (!$u) { return null; }
    unset($u['password_hash']);
    if (!$t['last_used_at'] || strtotime($t['last_used_at']) < time() - 60) { DB::update('agent_tokens', ['last_used_at' => date('Y-m-d H:i:s')], 'id=?', [$t['id']]); }
    return $u + ['token_id' => (int) $t['id'], 'token_name' => $t['name'], 'token_version' => $t['app_version']]; } }
