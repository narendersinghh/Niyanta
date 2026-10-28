<?php
namespace Modules\Admin\Controllers;
use Core\{Audit, Controller, DB, Response};

/** Audit log viewer with filters, pagination and CSV export (capped at 10,000 rows). */
final class AuditController extends Controller {
  private const EXPORT_CAP = 10000;

  /** Build the WHERE clause from the current filters. */
  private function filters(): array {
    $f = ['user' => $this->request->int('user'), 'action' => mb_substr($this->request->str('action'), 0, 100), 'entity' => mb_substr($this->request->str('entity'), 0, 60), 'from' => $this->request->str('from'), 'to' => $this->request->str('to')];
    $w = ['1=1']; $p = [];
    if ($f['user']) { $w[] = 'a.user_id=?'; $p[] = $f['user']; }
    if ($f['action'] !== '') { $w[] = 'a.action LIKE ?'; $p[] = addcslashes($f['action'], '%_\\').'%'; }
    if ($f['entity'] !== '') { $w[] = 'a.entity_type=?'; $p[] = $f['entity']; }
    foreach (['from' => '>=', 'to' => '<='] as $k => $op) {
      if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $f[$k]) || !strtotime($f[$k])) { $f[$k] = ''; continue; }
      $w[] = "a.created_at $op ?"; $p[] = $f[$k].($k === 'from' ? ' 00:00:00' : ' 23:59:59'); }
    return [$f, 'SELECT a.*, u.name AS user_name, u.email AS user_email FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id WHERE '.implode(' AND ', $w).' ORDER BY a.id DESC', $p]; }

  public function index(): Response {
    [$f, $sql, $p] = $this->filters();
    if ($this->request->query('export') === 'csv') { return $this->export($f, $sql, $p); }
    return $this->view('admin::audit/index', ['title' => 'Audit log', 'f' => $f, 'p' => DB::paginate($sql, $p, 30), 'styles' => ['css/admin.css'],
      'users' => DB::pairs('SELECT id, name FROM users ORDER BY name'), 'entities' => DB::column('SELECT DISTINCT entity_type FROM audit_logs WHERE entity_type IS NOT NULL ORDER BY entity_type LIMIT 200'),
      'prefixes' => DB::column("SELECT DISTINCT SUBSTRING_INDEX(action, '.', 1) FROM audit_logs ORDER BY 1 LIMIT 100"),
      'today' => (int) DB::value('SELECT COUNT(*) FROM audit_logs WHERE created_at >= ?', [date('Y-m-d 00:00:00')]),
      'week' => DB::pairs("SELECT action, COUNT(*) FROM audit_logs WHERE created_at >= ? AND action IN ('auth.login','auth.failed','access.denied','settings.update') GROUP BY action", [date('Y-m-d H:i:s', strtotime('-7 days'))])]); }

  private function export(array $f, string $sql, array $p): Response {
    $rows = DB::all($sql.' LIMIT '.self::EXPORT_CAP, $p);
    $out = fopen('php://temp', 'w+');
    fputcsv($out, ['ID', 'Date', 'User', 'Email', 'Action', 'Entity', 'Entity ID', 'Details', 'IP', 'User agent'], ',', '"', '');
    $safe = static fn ($v) => is_string($v) && $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false ? "'".$v : $v;
    foreach ($rows as $r) { fputcsv($out, array_map($safe, [$r['id'], $r['created_at'], $r['user_name'] ?? 'System', $r['user_email'] ?? '', $r['action'], $r['entity_type'] ?? '', $r['entity_id'] ?? '', $r['meta'] ?? '', $r['ip'] ?? '', $r['user_agent'] ?? '']), ',', '"', ''); }
    rewind($out);
    $csv = (string) stream_get_contents($out);
    fclose($out);
    Audit::log('audit.export', 'audit', null, ['rows' => count($rows), 'filters' => array_filter($f)]);
    return Response::download("\xEF\xBB\xBF".$csv, 'audit-log-'.date('Ymd-His').'.csv', 'text/csv; charset=utf-8'); }

  /** Render JSON meta as compact key/value chips (used by the view). */
  public static function meta(?string $json): array {
    $m = $json ? json_decode($json, true) : null;
    if (!is_array($m)) { return []; }
    $out = [];
    foreach ($m as $k => $v) { $out[(string) $k] = is_scalar($v) || $v === null ? (is_bool($v) ? ($v ? 'yes' : 'no') : (string) $v) : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
    return $out; } }
