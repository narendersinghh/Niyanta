<?php
namespace Modules\Admin\Controllers;
use Core\{Audit, Controller, DB, Response};

/** Audit log › Error logs: grouped errors from the server, browser and desktop tracker. */
final class ErrorLogController extends Controller {
  public function index(): Response {
    $src = $this->request->str('source'); $state = $this->request->str('state', 'open'); $q = mb_substr($this->request->str('q'), 0, 100);
    $w = ['1=1']; $p = [];
    if (in_array($src, ['server', 'browser', 'desktop'], true)) { $w[] = 'e.source=?'; $p[] = $src; }
    if ($state === 'open') { $w[] = 'e.resolved_at IS NULL'; } elseif ($state === 'resolved') { $w[] = 'e.resolved_at IS NOT NULL'; }
    if ($q !== '') { $w[] = '(e.message LIKE ? OR e.file LIKE ? OR e.url LIKE ?)'; array_push($p, DB::like($q), DB::like($q), DB::like($q)); }
    $page = DB::paginate('SELECT e.*, u.name AS user_name FROM error_logs e LEFT JOIN users u ON u.id=e.user_id WHERE '.implode(' AND ', $w).' ORDER BY e.last_seen DESC', $p, 25);
    $stats = DB::first("SELECT SUM(resolved_at IS NULL) AS open, SUM(resolved_at IS NULL AND source='server') AS server, SUM(resolved_at IS NULL AND source='browser') AS browser, SUM(resolved_at IS NULL AND source='desktop') AS desktop, SUM(last_seen >= ?) AS today FROM error_logs", [date('Y-m-d 00:00:00')]);
    return $this->view('admin::audit/errors', ['title' => 'Error logs', 'p' => $page, 'stats' => $stats, 'f' => ['source' => $src, 'state' => $state, 'q' => $q]]); }

  public function resolve(int $id): Response {
    $this->findOr404('error_logs', $id);
    DB::update('error_logs', ['resolved_at' => date('Y-m-d H:i:s')], 'id=?', [$id]);
    Audit::log('error_log.resolve', 'error_log', $id);
    $this->flash('Marked as resolved. It reopens automatically if it happens again.');
    return $this->back(); }

  public function clear(): Response {
    $n = DB::delete('error_logs', 'resolved_at IS NOT NULL');
    Audit::log('error_log.clear', 'error_log', null, ['removed' => $n]);
    $this->flash("Removed $n resolved errors.");
    return $this->back(); } }
