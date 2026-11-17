<?php
namespace Core\Controllers;
use Core\{Controller, DB, Response};

/** In-app notifications. Notifications that link to a page of a switched-off feature are hidden (and not counted). */
final class NotificationController extends Controller {
  private static function visible(array $rows): array { return array_values(array_filter($rows, static fn ($r) => empty($r['url']) || route_on((string) $r['url']))); }

  /** Unread notifications the user can still open: counted per distinct link, so thousands of unread rows never load into memory. */
  public static function unread(int $uid): int {
    $n = 0;
    foreach (DB::all('SELECT url, COUNT(*) AS n FROM notifications FORCE INDEX (idx_notif_user) WHERE user_id=? AND read_at IS NULL GROUP BY url LIMIT 500', [$uid]) as $r) { if (empty($r['url']) || route_on((string) $r['url'])) { $n += (int) $r['n']; } }
    return $n; }

  /** True when at least one unread notification can still be opened (the top bar dot): the newest few decide in one index range read (FORCE INDEX: with idx_notif_read the optimiser picks an index merge plus filesort, 100x slower for a big backlog); only when all of those are hidden are the distinct links checked. */
  public static function hasUnread(int $uid): bool {
    $vis = static fn (array $urls): bool => (bool) array_filter($urls, static fn ($u) => empty($u) || route_on((string) $u));
    $top = DB::column('SELECT url FROM notifications FORCE INDEX (idx_notif_user) WHERE user_id=? AND read_at IS NULL ORDER BY id DESC LIMIT 20', [$uid]);
    if ($vis($top)) { return true; }
    return count($top) === 20 && $vis(DB::column('SELECT DISTINCT url FROM notifications FORCE INDEX (idx_notif_user) WHERE user_id=? AND read_at IS NULL LIMIT 200', [$uid])); }

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
