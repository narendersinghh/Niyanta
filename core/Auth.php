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
    $idle = max(5, (int) Settings::get('security.session_idle_minutes', 10080)) * 60;
    $absolute = max(1, (int) Settings::get('security.session_absolute_hours', 168)) * 3600;
    if (time() - (int) Session::get('last_seen', 0) > $idle || time() - (int) Session::get('login_at', 0) > $absolute || Session::get('fp') !== self::fingerprint()) { self::logout(); return null; }
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

  /* browser family without version numbers: an automatic browser update must not sign people out */
  private static function fingerprint(): string { return hash('sha256', preg_replace('/[\d._]+/', '', Request::current()->userAgent())); }

  /** @return string|null error message, null on success */
  public static function attempt(string $email, string $password): ?string {
    [$u, $err] = self::verify($email, $password);
    if ($err !== null) { return $err; }
    self::login((int) $u['id'], (int) $u['session_version']);
    return null; }

  /**
   * Check credentials with the web sign-in's rate limits and lockout, without starting a session (also used by the desktop
   * tracker). @return array{0: ?array, 1: ?string} [user (id, status, session_version), error message]
   */
  public static function loginKey(string $email): string { return 'login:'.strtolower(trim($email)); }

  /** Clear a person's sign-in lock and failed-attempt counter (Users › Unlock sign-in). */
  public static function unlock(int $userId): void {
    $email = (string) DB::value('SELECT email FROM users WHERE id=?', [$userId]);
    DB::update('users', ['failed_logins' => 0, 'locked_until' => null], 'id=?', [$userId]);
    if ($email !== '') { RateLimiter::clear(self::loginKey($email)); } }

  public static function verify(string $email, string $password): array {
    $req = Request::current();
    /* Only wrong passwords count: successful sign-ins never block anyone, and a whole office behind one IP is only
       blocked after many failures (a password-guessing attack), not after normal use. */
    $key = self::loginKey($email); $ipKey = 'login-ip:'.$req->ip();
    $max = max(3, (int) Settings::get('security.login_max_attempts', 5));
    if (RateLimiter::blocked($key, $max)) { return [null, 'Too many wrong passwords. Try again in 15 minutes or ask an administrator to unlock your account.']; }
    if (RateLimiter::blocked($ipKey, 100)) { return [null, 'Too many wrong passwords from your network. Try again later.']; }
    $u = DB::first('SELECT id, password_hash, status, locked_until, failed_logins, session_version FROM users WHERE email=? LIMIT 1', [strtolower(trim($email))]);
    if ($u && $u['locked_until'] && strtotime($u['locked_until']) > time()) { return [null, 'Account temporarily locked. Try again later.']; }
    if (!$u || !password_verify($password, $u['password_hash'])) {
      if (!$u) { password_verify($password, '$2y$12$abcdefghijklmnopqrstuuJ2yd7JEcoQqfMv0bwjVbHn6NDj2D1Ga'); }
      else {
        $f = (int) $u['failed_logins'] + 1;
        DB::update('users', ['failed_logins' => $f, 'locked_until' => $f >= 10 ? date('Y-m-d H:i:s', time() + 1800) : null], 'id=?', [$u['id']]);
        Audit::log('auth.failed', 'user', (int) $u['id'], [], (int) $u['id']); }
      RateLimiter::hit($key, $max, 900); RateLimiter::hit($ipKey, 100, 900);
      return [null, 'Invalid email or password.']; }
    if ($u['status'] !== 'active') { return [null, 'Your account is not active. Contact your administrator.']; }
    if (password_needs_rehash($u['password_hash'], self::algo(), self::algoOptions())) { DB::update('users', ['password_hash' => self::hash($password)], 'id=?', [$u['id']]); }
    RateLimiter::clear($key);
    if ((int) $u['failed_logins'] || $u['locked_until']) { DB::update('users', ['failed_logins' => 0, 'locked_until' => null], 'id=?', [$u['id']]); }
    unset($u['password_hash']);
    return [$u, null]; }

  public static function login(int $id, int $sv): void {
    Session::regenerate();
    Session::put('uid', $id);
    Session::put('sv', $sv);
    Session::put('fp', self::fingerprint());
    Session::put('last_seen', time());
    Session::put('login_at', time());
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

  /** Argon2id when PHP was built with it (most hosts), otherwise bcrypt at cost 12. Old hashes are upgraded on the next successful sign-in. */
  public static function algo(): string { return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT; }
  public static function algoOptions(): array { return defined('PASSWORD_ARGON2ID') ? ['memory_cost' => 65536, 'time_cost' => 3, 'threads' => 1] : ['cost' => 12]; }
  public static function hash(string $password): string { return password_hash($password, self::algo(), self::algoOptions()); }

  public static function validatePassword(string $p): ?string {
    $min = max(8, (int) Settings::get('security.password_min_length', 10));
    if (strlen($p) < $min) { return "Password must be at least $min characters."; }
    if (strlen($p) > 1024) { return 'Password is too long.'; }
    if (!preg_match('/[A-Za-z]/', $p) || !preg_match('/\d/', $p)) { return 'Password must contain letters and numbers.'; }
    if (in_array(strtolower($p), ['password1', 'password123', 'admin12345', 'qwerty123', '12345678a', 'welcome123', 'letmein123', 'niyanta123'], true)) { return 'That password is too common. Choose something harder to guess.'; }
    return null; } }
