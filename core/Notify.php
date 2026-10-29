<?php
namespace Core;

/** In-app notifications with optional email. */
final class Notify {
  /** Notifications pointing at a page of a switched-off feature are not sent. */
  public static function user(int $uid, string $title, string $body = '', ?string $url = null, bool $email = false, string $icon = 'bell'): void {
    if ($url && !route_on($url)) { return; }
    DB::insert('notifications', ['user_id' => $uid, 'title' => mb_substr($title, 0, 190), 'body' => mb_substr($body, 0, 1000), 'url' => $url, 'icon' => $icon, 'created_at' => date('Y-m-d H:i:s')]);
    if ($email && Settings::bool('email.notifications', true) && ($u = DB::first('SELECT name, email FROM users WHERE id=? AND status=\'active\'', [$uid]))) {
      Mailer::queue($u['email'], $title, Mailer::template($title, nl2br(e($body)), $url ? absolute_url($url) : null, 'View in '.app_name()), $u['name']); } }

  /** Notify every user holding a permission (e.g. approvers), except the actor. */
  public static function permission(string $perm, string $title, string $body = '', ?string $url = null, bool $email = false): void {
    foreach (Gate::usersWith($perm) as $u) { if ((int) $u['id'] !== Auth::id()) { self::user((int) $u['id'], $title, $body, $url, $email); } } } }
