<?php
namespace Core;

/** Session authentication with lockout, rate limiting and idle timeout. */
final class Auth {
  private static ?array $user = null;
  private static bool $loaded = false;

  public static function user(): ?array {
    if (self::$loaded) { return self::$user; }
    self::$loaded = true;
    $id = (int) Session::get('uid', 0);
    if (!$id) { return null; }
    $idle = max(5, (int) Settings::get('security.session_idle_minutes', 120)) * 60;
    if (time() - (int) Session::get('last_seen', 0) > $idle || Session::get('fp') !== self::fingerprint()) { self::logout(); return null; }
    $u = DB::first('SELECT u.*, r.slug AS role_slug, r.name AS role_name FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=? AND u.status=\'active\' LIMIT 1', [$id]);
    if (!$u || (int) $u['session_version'] !== (int) Session::get('sv', -1)) { self::logout(); return null; }
    Session::put('last_seen', time());
    unset($u['password_hash']);
    return self::$user = $u; }

  /** Reset the per-request user cache (used by tests and after login). */
  public static function forget(): void { self::$user = null; self::$loaded = false; }

  public static function check(): bool { return self::user() !== null; }
  public static function id(): ?int { return self::user()['id'] ?? null; }
  public static function role(): ?string { return self::user()['role_slug'] ?? null; }
  public static function isSuperAdmin(): bool { return self::role() === 'super_admin'; }

  private static function fingerprint(): string { return hash('sha256', Request::current()->userAgent()); }

  /** @return string|null error message, null on success */
  public static function attempt(string $email, string $password): ?string {
    $req = Request::current();
    $key = 'login:'.strtolower($email).'|'.$req->ip();
    if (!RateLimiter::hit($key, (int) Settings::get('security.login_max_attempts', 5), 900)) { return 'Too many attempts. Try again in 15 minutes.'; }
    if (!RateLimiter::hit('login-ip:'.$req->ip(), 30, 900)) { return 'Too many attempts from your network. Try again later.'; }
    $u = DB::first('SELECT id, password_hash, status, locked_until, failed_logins, session_version FROM users WHERE email=? LIMIT 1', [strtolower(trim($email))]);
    if ($u && $u['locked_until'] && strtotime($u['locked_until']) > time()) { return 'Account temporarily locked. Try again later.'; }
    if (!$u || !password_verify($password, $u['password_hash'])) {
      if (!$u) { password_verify($password, '$2y$12$abcdefghijklmnopqrstuuJ2yd7JEcoQqfMv0bwjVbHn6NDj2D1Ga'); }
      else {
        $f = (int) $u['failed_logins'] + 1;
        DB::update('users', ['failed_logins' => $f, 'locked_until' => $f >= 10 ? date('Y-m-d H:i:s', time() + 1800) : null], 'id=?', [$u['id']]);
        Audit::log('auth.failed', 'user', (int) $u['id'], [], (int) $u['id']); }
      return 'Invalid email or password.'; }
    if ($u['status'] !== 'active') { return 'Your account is not active. Contact your administrator.'; }
    if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) { DB::update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], 'id=?', [$u['id']]); }
    RateLimiter::clear($key);
    self::login((int) $u['id'], (int) $u['session_version']);
    return null; }

  public static function login(int $id, int $sv): void {
    Session::regenerate();
    Session::put('uid', $id);
    Session::put('sv', $sv);
    Session::put('fp', self::fingerprint());
    Session::put('last_seen', time());
    unset($_SESSION['_csrf']);
    DB::update('users', ['last_login_at' => date('Y-m-d H:i:s'), 'last_login_ip' => Request::current()->ip(), 'failed_logins' => 0, 'locked_until' => null], 'id=?', [$id]);
    self::$loaded = false;
    Audit::log('auth.login', 'user', $id, [], $id); }

  public static function logout(): void {
    if ($id = (int) Session::get('uid', 0)) { Audit::log('auth.logout', 'user', $id, [], $id); }
    self::$user = null;
    Session::destroy(); }

  /** Invalidate all sessions of a user (password change, deactivation). */
  public static function bumpSessions(int $uid): void { DB::query('UPDATE users SET session_version=session_version+1 WHERE id=?', [$uid]); }

  public static function validatePassword(string $p): ?string {
    $min = max(8, (int) Settings::get('security.password_min_length', 10));
    if (strlen($p) < $min) { return "Password must be at least $min characters."; }
    if (!preg_match('/[A-Za-z]/', $p) || !preg_match('/\d/', $p)) { return 'Password must contain letters and numbers.'; }
    return null; } }
