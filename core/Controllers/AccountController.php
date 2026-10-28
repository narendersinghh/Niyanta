<?php
namespace Core\Controllers;
use Core\{Audit, Auth, Controller, DB, Response, Storage};

final class AccountController extends Controller {
  public function edit(): Response { return $this->view('account/edit', ['title' => 'My account', 'u' => $this->user()]); }

  public function update(): Response {
    $d = $this->validate(['name' => 'required|max:150', 'phone' => 'nullable|phone']);
    DB::update('users', $d + ['updated_at' => date('Y-m-d H:i:s')], 'id=?', [$this->uid()]);
    Audit::log('account.update', 'user', $this->uid());
    $this->flash('Profile updated.');
    return $this->back(); }

  public function password(): Response {
    $row = DB::first('SELECT password_hash FROM users WHERE id=?', [$this->uid()]);
    if (!password_verify((string) $this->request->input('current_password'), $row['password_hash'])) { $this->flash('Current password is incorrect.', 'error'); return $this->back(); }
    $p = (string) $this->request->input('password');
    if ($e = Auth::validatePassword($p)) { $this->flash($e, 'error'); return $this->back(); }
    if ($p !== $this->request->input('password_confirmation')) { $this->flash('Passwords do not match.', 'error'); return $this->back(); }
    DB::update('users', ['password_hash' => password_hash($p, PASSWORD_DEFAULT)], 'id=?', [$this->uid()]);
    Auth::bumpSessions($this->uid());
    Auth::login($this->uid(), (int) DB::value('SELECT session_version FROM users WHERE id=?', [$this->uid()]));
    Audit::log('account.password_changed', 'user', $this->uid());
    $this->flash('Password changed. Other sessions were signed out.');
    return $this->redirect('/account'); }

  public function theme(): Response {
    $t = $this->request->str('theme');
    if (!in_array($t, ['system', 'light', 'dark'], true)) { return $this->json(['ok' => false], 422); }
    DB::update('users', ['theme' => $t], 'id=?', [$this->uid()]);
    return $this->json(['ok' => true]); }

  public function avatar(): Response {
    $f = $this->request->file('avatar') ?? $this->abort(422, 'Choose an image.');
    $s = Storage::store($f, 'avatars', Storage::IMAGES, 2 * 1048576);
    $old = $this->user()['avatar'];
    DB::update('users', ['avatar' => $s['path']], 'id=?', [$this->uid()]);
    if ($old) { @unlink(Storage::path($old)); }
    $this->flash('Photo updated.');
    return $this->back(); }

  /** Avatars are private files; any signed-in user may view colleagues' avatars. */
  public function showAvatar(string $file): Response {
    if (!preg_match('/^[a-f0-9]{32}\.(png|jpe?g|webp|gif)$/', $file)) { return Response::error(404, 'Not found'); }
    $row = DB::first('SELECT avatar FROM users WHERE avatar LIKE ? LIMIT 1', ['avatars/%/'.$file]);
    if (!$row) { return Response::error(404, 'Not found'); }
    return Response::file(Storage::path($row['avatar']), $file, null, true)->header('Cache-Control', 'private, max-age=86400'); } }
