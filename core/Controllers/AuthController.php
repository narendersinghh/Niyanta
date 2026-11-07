<?php
namespace Core\Controllers;
use Core\{Audit, Auth, Controller, Crypto, DB, Mailer, Response, Settings};

final class AuthController extends Controller {
  public function showLogin(): Response {
    if (Auth::check()) { return $this->redirect('/'); }
    return $this->view('auth/login', ['title' => 'Sign in'], 'layouts/blank'); }

  public function login(): Response {
    $email = $this->request->str('email');
    $err = Auth::attempt($email, (string) $this->request->input('password', ''));
    if ($err) { \Core\Session::flash('old', ['email' => $email]); $this->flash($err, 'error'); return $this->redirect('/login'); }
    $next = (string) $this->request->input('next', '');
    return $this->redirect(preg_match('#^/[^/\\\\]#', $next) ? $next : '/'); }

  /** Sign out of this browser only (desktop device tokens are separate and stay signed in); a safe local `next` comes back after the next sign-in. */
  public function logout(): Response {
    $next = (string) $this->request->input('next', '');
    Auth::logout();
    return $this->redirect('/login'.(preg_match('#^/[^/\\\\]#', $next) ? '?next='.rawurlencode($next) : '')); }

  public function showForgot(): Response { return $this->view('auth/forgot', ['title' => 'Forgot password'], 'layouts/blank'); }

  public function forgot(): Response {
    $email = strtolower($this->request->str('email'));
    $u = filter_var($email, FILTER_VALIDATE_EMAIL) ? DB::first("SELECT id, name, email FROM users WHERE email=? AND status='active'", [$email]) : null;
    if ($u) {
      $token = Crypto::token(32);
      DB::delete('password_resets', 'user_id=?', [$u['id']]);
      DB::insert('password_resets', ['user_id' => $u['id'], 'token_hash' => hash('sha256', $token), 'expires_at' => date('Y-m-d H:i:s', time() + 3600), 'created_at' => date('Y-m-d H:i:s')]);
      Mailer::queue($u['email'], 'Reset your password', Mailer::template('Reset your password', '<p>Hi '.e($u['name']).',</p><p>We received a request to reset your password. This link expires in 60 minutes. If you did not request it, you can ignore this email.</p>', absolute_url('/reset-password/'.$token), 'Reset password'), $u['name']);
      try { Mailer::processQueue(5); } catch (\Throwable) {}
      Audit::log('auth.reset_requested', 'user', (int) $u['id'], [], (int) $u['id']); }
    $this->flash('If that email exists, a reset link has been sent.');
    return $this->redirect('/login'); }

  public function showReset(string $token): Response { return $this->view('auth/reset', ['title' => 'Reset password', 'token' => $token], 'layouts/blank'); }

  public function reset(): Response {
    $token = $this->request->str('token');
    $row = DB::first('SELECT * FROM password_resets WHERE token_hash=? AND expires_at>NOW()', [hash('sha256', $token)]);
    if (!$row) { $this->flash('This reset link is invalid or has expired.', 'error'); return $this->redirect('/forgot-password'); }
    $p = (string) $this->request->input('password', '');
    if ($e = Auth::validatePassword($p)) { $this->flash($e, 'error'); return $this->back(); }
    if ($p !== $this->request->input('password_confirmation')) { $this->flash('Passwords do not match.', 'error'); return $this->back(); }
    DB::update('users', ['password_hash' => Auth::hash($p), 'failed_logins' => 0, 'locked_until' => null], 'id=?', [$row['user_id']]);
    Auth::bumpSessions((int) $row['user_id']);
    DB::delete('password_resets', 'user_id=?', [$row['user_id']]);
    Audit::log('auth.password_reset', 'user', (int) $row['user_id'], [], (int) $row['user_id']);
    $this->flash('Password updated. You can now sign in.');
    return $this->redirect('/login'); } }
