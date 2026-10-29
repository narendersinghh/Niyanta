<?php
namespace Core\Controllers;
use Core\{Controller, DB, Response};

/** In-app notifications. Notifications that link to a page of a switched-off feature are hidden (and not counted). */
final class NotificationController extends Controller {
  private static function visible(array $rows): array { return array_values(array_filter($rows, static fn ($r) => empty($r['url']) || route_on((string) $r['url']))); }

  /** Unread notifications the user can still open. */
  public static function unread(int $uid): int { return count(self::visible(DB::all('SELECT url FROM notifications WHERE user_id=? AND read_at IS NULL', [$uid]))); }

  public function index(): Response {
    $p = DB::paginate('SELECT * FROM notifications WHERE user_id=? ORDER BY id DESC', [$this->uid()], 25);
    $p['items'] = self::visible($p['items']);
    return $this->view('notifications/index', ['title' => 'Notifications', 'p' => $p]); }

  public function feed(): Response {
    $rows = array_slice(self::visible(DB::all('SELECT id, title, body, url, icon, read_at, created_at FROM notifications WHERE user_id=? ORDER BY id DESC LIMIT 40', [$this->uid()])), 0, 8);
    foreach ($rows as &$r) { $r['ago'] = ago($r['created_at']); $r['url'] = $r['url'] ? url($r['url']) : null; }
    return $this->json(['unread' => self::unread($this->uid()), 'items' => $rows]); }

  public function markRead(): Response {
    $id = $this->request->int('id');
    if ($id) { DB::query('UPDATE notifications SET read_at=NOW() WHERE id=? AND user_id=? AND read_at IS NULL', [$id, $this->uid()]); }
    else { DB::query('UPDATE notifications SET read_at=NOW() WHERE user_id=? AND read_at IS NULL', [$this->uid()]); }
    return $this->request->wantsJson() ? $this->json(['ok' => true]) : $this->back(); } }
