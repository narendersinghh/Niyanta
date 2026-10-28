<?php
namespace Core\Controllers;
use Core\{Controller, DB, Response};

final class NotificationController extends Controller {
  public function index(): Response {
    $p = DB::paginate('SELECT * FROM notifications WHERE user_id=? ORDER BY id DESC', [$this->uid()], 25);
    return $this->view('notifications/index', ['title' => 'Notifications', 'p' => $p]); }

  public function feed(): Response {
    $rows = DB::all('SELECT id, title, body, url, icon, read_at, created_at FROM notifications WHERE user_id=? ORDER BY id DESC LIMIT 8', [$this->uid()]);
    foreach ($rows as &$r) { $r['ago'] = ago($r['created_at']); $r['url'] = $r['url'] ? url($r['url']) : null; }
    return $this->json(['unread' => (int) DB::value('SELECT COUNT(*) FROM notifications WHERE user_id=? AND read_at IS NULL', [$this->uid()]), 'items' => $rows]); }

  public function markRead(): Response {
    $id = $this->request->int('id');
    if ($id) { DB::query('UPDATE notifications SET read_at=NOW() WHERE id=? AND user_id=? AND read_at IS NULL', [$id, $this->uid()]); }
    else { DB::query('UPDATE notifications SET read_at=NOW() WHERE user_id=? AND read_at IS NULL', [$this->uid()]); }
    return $this->request->wantsJson() ? $this->json(['ok' => true]) : $this->back(); } }
