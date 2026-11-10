<?php
namespace Core;

final class Session {
  public static function start(): void {
    if (session_status() === PHP_SESSION_ACTIVE || PHP_SAPI === 'cli') { return; }
    $dir = NY_ROOT.'/storage/sessions';
    if (is_writable($dir)) { session_save_path($dir); }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.sid_length', '48');
    ini_set('session.sid_bits_per_character', '6');
    ini_set('session.gc_maxlifetime', (string) max(86400, self::lifetime()));
    session_name('niyanta_sid');
    session_set_cookie_params(self::cookieParams(Request::current()));
    session_start();
    $flashOld = $_SESSION['_flash_next'] ?? [];
    $_SESSION['_flash'] = $flashOld;
    $_SESSION['_flash_next'] = []; }

  /** How long a sign-in lasts (Settings › Users, default 7 days): the cookie survives closing the browser for that long. */
  public static function lifetime(): int { try { return max(1, (int) Settings::get('security.session_absolute_hours', 168)) * 3600; } catch (\Throwable) { return 168 * 3600; } }

  /** Session cookie flags: kept for the sign-in lifetime, HttpOnly, SameSite=Lax, Secure whenever the request came over HTTPS. */
  public static function cookieParams(Request $r): array { return ['lifetime' => self::lifetime(), 'path' => (Request::base() ?: '').'/', 'domain' => '', 'secure' => $r->isSecure(), 'httponly' => true, 'samesite' => 'Lax']; }

  public static function get(string $k, mixed $d = null): mixed { return $_SESSION[$k] ?? $d; }
  public static function put(string $k, mixed $v): void { $_SESSION[$k] = $v; }
  public static function forget(string $k): void { unset($_SESSION[$k]); }
  public static function regenerate(): void { if (session_status() === PHP_SESSION_ACTIVE) { session_regenerate_id(true); } }
  public static function destroy(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) { return; }
    $_SESSION = [];
    $p = session_get_cookie_params();
    setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => $p['path'], 'secure' => $p['secure'], 'httponly' => true, 'samesite' => 'Lax']);
    session_destroy(); }

  /** Flash data is available on the next request only. */
  public static function flash(string $k, mixed $v): void { $_SESSION['_flash_next'][$k] = $v; }
  public static function getFlash(string $k, mixed $d = null): mixed { return $_SESSION['_flash'][$k] ?? $d; } }
