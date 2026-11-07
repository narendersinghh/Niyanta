<?php
namespace Modules\Attendance\Services;
use Core\{Audit, DB, Gate, Modules};

/**
 * "Sign in with your browser" for Niyanta Tracker: one-time connect codes the app trades for a device token at
 * POST /api/agent/connect (contract in AGENT_API.md). The browser hands a code to the app with the deep link
 * niyanta-tracker://connect?ws=<workspace URL>&code=<code>[&state=<state>]. Codes are 43 url-safe characters (256 bits),
 * stored as sha256 only, single use, and expire after 2 minutes ("web" button, "browser" = flow started by the app with a
 * PKCE S256 challenge) or 30 minutes ("install" = minted into a personal install script). Device tokens never depend on
 * the web session: signing out of the website leaves the app signed in.
 */
final class DesktopConnect {
  public const SCHEME = 'niyanta-tracker';
  public const TTL = ['web' => 120, 'browser' => 120, 'install' => 1800];
  public const OS = ['win' => 'Windows', 'mac' => 'macOS', 'linux' => 'Linux'];

  public static function b64url(string $raw): string { return rtrim(strtr(base64_encode($raw), '+/', '-_'), '='); }
  public static function validChallenge(string $c): bool { return (bool) preg_match('/^[A-Za-z0-9_-]{43}$/', $c); }
  public static function validState(string $s): bool { return (bool) preg_match('/^[A-Za-z0-9._~-]{16,64}$/', $s); }
  public static function deviceName(?string $n): ?string { $n = trim(preg_replace('/[\x00-\x1f\x7f]+/u', ' ', (string) $n)); return $n === '' ? null : mb_substr($n, 0, 100); }

  /** Mint a code for $uid; returns the plain code (shown once, in the deep link). */
  public static function issue(int $uid, string $purpose, ?string $challenge = null, ?string $state = null, ?string $device = null, ?string $os = null, ?string $ip = null, ?int $now = null): string {
    if (!isset(self::TTL[$purpose])) { throw new \InvalidArgumentException('Unknown connect purpose.'); }
    if ($challenge !== null && !self::validChallenge($challenge)) { throw new \DomainException('Invalid challenge.'); }
    if ($state !== null && !self::validState($state)) { throw new \DomainException('Invalid state.'); }
    $now ??= time();
    $code = self::b64url(random_bytes(32));
    $id = DB::insert('desktop_connect_codes', ['user_id' => $uid, 'code_hash' => hash('sha256', $code), 'challenge' => $challenge, 'state' => $state, 'device_name' => self::deviceName($device), 'device_os' => isset(self::OS[(string) $os]) ? $os : null,
      'purpose' => $purpose, 'expires_at' => date('Y-m-d H:i:s', $now + self::TTL[$purpose]), 'created_ip' => $ip !== null ? mb_substr($ip, 0, 45) : null, 'created_at' => date('Y-m-d H:i:s', $now)]);
    Audit::log('desktop.connect.issued', 'desktop_connect_code', $id, array_filter(['purpose' => $purpose, 'device' => self::deviceName($device), 'os' => $os, 'pkce' => $challenge !== null]), $uid);
    return $code; }

  /** The deep link that opens the app with a code. */
  public static function deepLink(string $code, ?string $state = null): string {
    return self::SCHEME.'://connect?'.http_build_query(array_filter(['ws' => absolute_url('/'), 'code' => $code, 'state' => $state], static fn ($v) => $v !== null && $v !== ''), '', '&', PHP_QUERY_RFC3986); }

  /**
   * Trade a code (once): [code row + active user (with role) | null, error code | null]. Errors: code_invalid (unknown,
   * used, user inactive), code_expired, verifier_mismatch (a code created with a challenge needs the matching verifier; a
   * wrong one burns the code), forbidden (role may not track), disabled (time tracking off). Failures are audit-logged.
   */
  public static function consume(string $code, ?string $verifier, string $ip): array {
    $fail = static function (string $err, ?array $row = null) use ($ip): array {
      Audit::log('desktop.connect.failed', 'desktop_connect_code', $row ? (int) $row['id'] : null, ['reason' => $err, 'ip' => $ip], $row ? (int) $row['user_id'] : null);
      return [null, $err]; };
    if (!Modules::enabled('attendance') || !feature_on('attendance')) { return [null, 'disabled']; }
    if (!preg_match('/^[A-Za-z0-9_-]{20,128}$/', $code)) { return $fail('code_invalid'); }
    $row = DB::first('SELECT * FROM desktop_connect_codes WHERE code_hash=? LIMIT 1', [hash('sha256', $code)]);
    if (!$row || $row['used_at'] !== null) { return $fail('code_invalid', $row); }
    $now = date('Y-m-d H:i:s');
    if ($row['expires_at'] <= $now) { return $fail('code_expired', $row); }
    $burn = static fn (): bool => DB::query('UPDATE desktop_connect_codes SET used_at=? WHERE id=? AND used_at IS NULL AND expires_at>?', [$now, $row['id'], $now])->rowCount() === 1;
    if ($row['challenge'] !== null) {
      $v = (string) $verifier;
      if (!preg_match('/^[A-Za-z0-9._~-]{43,128}$/', $v) || !hash_equals($row['challenge'], self::b64url(hash('sha256', $v, true)))) { $burn(); return $fail('verifier_mismatch', $row); } }
    if (!$burn()) { return $fail('code_invalid', $row); }
    $u = DB::first("SELECT u.*, r.slug AS role_slug, r.name AS role_name FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=? AND u.status='active' LIMIT 1", [$row['user_id']]);
    if (!$u) { return $fail('code_invalid', $row); }
    unset($u['password_hash']);
    if (!Gate::allows('attendance.track', $u)) { return $fail('forbidden', $row); }
    return [['code' => $row, 'user' => $u], null]; }

  /** Cron: delete expired codes (bounded per run). */
  public static function cleanup(): string {
    $n = 0; $cut = date('Y-m-d H:i:s');
    for ($i = 0; $i < 20; $i++) { $d = DB::query('DELETE FROM desktop_connect_codes WHERE expires_at<? ORDER BY id LIMIT 1000', [$cut])->rowCount(); $n += $d; if ($d < 1000) { break; } }
    return "deleted $n"; } }
