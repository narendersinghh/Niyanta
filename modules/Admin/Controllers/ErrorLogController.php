<?php
namespace Modules\Admin\Controllers;
use Core\{Audit, Controller, DB, Response};

/** Audit log › Error logs: grouped errors from the server, browser and desktop tracker. */
final class ErrorLogController extends Controller {
  /** Filters shared by the list and the download: [where, params, filters]. */
  private function filters(): array {
    $src = $this->request->str('source'); $state = $this->request->str('state', 'open'); $q = mb_substr($this->request->str('q'), 0, 100);
    $w = ['1=1']; $p = [];
    if (in_array($src, ['server', 'browser', 'desktop'], true)) { $w[] = 'e.source=?'; $p[] = $src; }
    if ($state === 'open') { $w[] = 'e.resolved_at IS NULL'; } elseif ($state === 'resolved') { $w[] = 'e.resolved_at IS NOT NULL'; }
    if ($q !== '') { $w[] = '(e.message LIKE ? OR e.file LIKE ? OR e.url LIKE ?)'; array_push($p, DB::like($q), DB::like($q), DB::like($q)); }
    return [$w, $p, ['source' => $src, 'state' => $state, 'q' => $q]]; }

  public function index(): Response {
    [$w, $p, $f] = $this->filters(); ['source' => $src, 'state' => $state, 'q' => $q] = $f;
    $page = DB::paginate('SELECT e.*, u.name AS user_name FROM error_logs e LEFT JOIN users u ON u.id=e.user_id WHERE '.implode(' AND ', $w).' ORDER BY e.last_seen DESC', $p, 25);
    $stats = DB::first("SELECT SUM(resolved_at IS NULL) AS open, SUM(resolved_at IS NULL AND source='server') AS server, SUM(resolved_at IS NULL AND source='browser') AS browser, SUM(resolved_at IS NULL AND source='desktop') AS desktop, SUM(last_seen >= ?) AS today FROM error_logs", [date('Y-m-d 00:00:00')]);
    return $this->view('admin::audit/errors', ['title' => 'Error logs', 'p' => $page, 'stats' => $stats, 'f' => ['source' => $src, 'state' => $state, 'q' => $q]]); }

  /** Plain-text download of the errors matching the current filters (all of them, newest first), streamed row by row. */
  public function export(): Response {
    [$w, $p, $f] = $this->filters();
    $sql = 'SELECT e.*, u.name AS user_name FROM error_logs e LEFT JOIN users u ON u.id=e.user_id WHERE '.implode(' AND ', $w).' ORDER BY e.last_seen DESC';
    $name = 'niyanta-error-logs-'.date('Y-m-d-His').'.txt';
    Audit::log('error_log.export', 'error_log', null, $f);
    return Response::stream(static function ($out) use ($sql, $p, $f): void {
      $line = str_repeat('=', 78)."\n";
      fwrite($out, 'Niyanta error logs, exported '.date('d M Y h:i A').' ('.NY_VERSION.")\nFilters: source ".($f['source'] ?: 'all').', '.($f['state'] ?: 'all').($f['q'] !== '' ? ', search "'.$f['q'].'"' : '')."\n\n");
      $n = 0;
      foreach (DB::cursor($sql, $p) as $e) {
        $n++;
        $t = $line.'#'.$n.'  '.strtoupper((string) $e['level']).' · '.ucfirst((string) $e['source']).' · '.(int) $e['occurrences'].'x'.($e['resolved_at'] ? ' · resolved '.$e['resolved_at'] : ' · open')."\n";
        $t .= 'Message:    '.$e['message']."\n";
        foreach (['First seen' => $e['first_seen'], 'Last seen' => $e['last_seen'], 'Where' => $e['file'] ? $e['file'].($e['line'] ? ':'.$e['line'] : '') : null, 'Page/request' => $e['url'], 'User' => $e['user_name'], 'Version' => $e['app_version'], 'Client' => trim(($e['user_agent'] ?? '').($e['ip'] ? ' · '.$e['ip'] : ''), ' ·')] as $k => $v) { if ($v !== null && $v !== '') { $t .= str_pad($k.':', 14).$v."\n"; } }
        if ($e['context']) { $c = json_decode((string) $e['context'], true); $t .= "Details:\n".(is_array($c) ? json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : (string) $e['context'])."\n"; }
        fwrite($out, $t."\n"); }
      fwrite($out, $n ? $line.$n.' error'.($n === 1 ? '' : 's')."\n" : "No errors match these filters.\n"); }, $name, 'text/plain; charset=utf-8'); }

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
