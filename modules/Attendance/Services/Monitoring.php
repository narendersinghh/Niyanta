<?php
namespace Modules\Attendance\Services;
use Core\{Audit, DB, Gate, Settings, Storage};

/** Consent-based screenshot monitoring and personal desktop-agent tokens. */
final class Monitoring {
  public const POLICY_VERSION = '2026-1';
  public const MAX_BYTES = 3145728;

  public static function enabled(): bool { return Settings::bool('attendance.screenshots_enabled'); }
  public static function interval(): int { return max(1, min(60, (int) Settings::get('attendance.screenshot_interval', 3))); }
  public static function retention(): int { return max(1, (int) Settings::get('attendance.screenshot_retention_days', 30)); }

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

  /** Store an uploaded capture for the user's running entry. */
  public static function store(int $uid, array $file, string $source, ?int $activity): int {
    if (!self::enabled()) { throw new \DomainException('Screenshot monitoring is turned off.'); }
    if (!self::consent($uid)) { throw new \DomainException('Monitoring consent is required before capturing.'); }
    $e = DB::first('SELECT id FROM time_entries WHERE user_id=? AND is_running=1 LIMIT 1', [$uid]);
    if (!$e) { throw new \DomainException('Screenshots are only accepted while your timer is running.'); }
    $last = DB::value('SELECT MAX(taken_at) FROM screenshots WHERE user_id=?', [$uid]);
    if ($last && strtotime($last) > time() - 20) { throw new \DomainException('Screenshots are arriving too quickly.'); }
    $info = @getimagesize($file['tmp_name'] ?? '');
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true) || $info[0] > 4096 || $info[1] > 4096) { throw new \DomainException('The screenshot must be a JPEG, PNG or WebP image up to 4096px.'); }
    try { $s = Storage::store($file, 'screenshots/u'.$uid, ['jpg', 'jpeg', 'png', 'webp'], self::MAX_BYTES); }
    catch (\Core\ValidationException $e) { throw new \DomainException((string) (array_values($e->errors)[0] ?? 'Upload failed.')); }
    $id = DB::insert('screenshots', ['user_id' => $uid, 'time_entry_id' => $e['id'], 'disk_path' => $s['path'], 'taken_at' => date('Y-m-d H:i:s'), 'source' => $source, 'activity_pct' => $activity === null ? null : max(0, min(100, $activity))]);
    Audit::log('monitoring.screenshot', 'screenshot', $id, ['source' => $source], $uid);
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

  /** Create a personal agent token; returns the plain token (shown once). */
  public static function createToken(int $uid, string $name): string {
    if ((int) DB::value('SELECT COUNT(*) FROM agent_tokens WHERE user_id=? AND revoked_at IS NULL', [$uid]) >= 5) { throw new \DomainException('You can have at most 5 active agent tokens. Revoke one first.'); }
    $plain = 'nyt_'.bin2hex(random_bytes(24));
    $id = DB::insert('agent_tokens', ['user_id' => $uid, 'name' => mb_substr($name, 0, 100), 'token_hash' => hash('sha256', $plain), 'created_at' => date('Y-m-d H:i:s')]);
    Audit::log('agent.token_create', 'agent_token', $id, ['name' => $name], $uid);
    return $plain; }

  public static function revokeToken(int $uid, int $id): bool {
    $n = DB::query('UPDATE agent_tokens SET revoked_at=NOW() WHERE id=? AND user_id=? AND revoked_at IS NULL', [$id, $uid])->rowCount();
    if ($n) { Audit::log('agent.token_revoke', 'agent_token', $id, [], $uid); }
    return $n > 0; }

  /** Resolve a bearer token to an active user (with role) or null. */
  public static function tokenUser(string $plain): ?array {
    if (!preg_match('/^nyt_[a-f0-9]{48}$/', $plain)) { return null; }
    $t = DB::first('SELECT id, user_id, last_used_at FROM agent_tokens WHERE token_hash=? AND revoked_at IS NULL LIMIT 1', [hash('sha256', $plain)]);
    if (!$t) { return null; }
    $u = DB::first("SELECT u.*, r.slug AS role_slug, r.name AS role_name FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=? AND u.status='active' LIMIT 1", [$t['user_id']]);
    if (!$u) { return null; }
    unset($u['password_hash']);
    if (!$t['last_used_at'] || strtotime($t['last_used_at']) < time() - 60) { DB::update('agent_tokens', ['last_used_at' => date('Y-m-d H:i:s')], 'id=?', [$t['id']]); }
    return $u + ['token_id' => (int) $t['id']]; } }
