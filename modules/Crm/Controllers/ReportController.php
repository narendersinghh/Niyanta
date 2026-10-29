<?php
namespace Modules\Crm\Controllers;
use Core\{Audit, DB, Modules, Response};
use Modules\Crm\Services\Finance;

/** Financial reports over visible clients only: collections, invoiced vs collected, outstanding, aging, per-project. */
final class ReportController extends CrmController {
  private function range(): array {
    $from = $this->request->str('from'); $to = $this->request->str('to');
    $ok = static fn ($d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
    $to = $ok($to) ? $to : date('Y-m-d');
    $from = $ok($from) ? $from : date('Y-m-01', strtotime('-11 months', strtotime(date('Y-m-01', strtotime($to)))));
    if ($from > $to) { [$from, $to] = [$to, $from]; }
    if (strtotime($to) - strtotime($from) > 3 * 366 * 86400) { $from = date('Y-m-d', strtotime('-3 years', strtotime($to))); }
    return [$from, $to]; }

  public function index(): Response {
    [$from, $to] = $this->range();
    [$wp, $pp] = $this->scope('py.client_id');
    [$wi, $pi] = $this->scope('i.client_id');
    $collected = DB::pairs("SELECT DATE_FORMAT(py.paid_on,'%Y-%m') m, SUM(py.amount) FROM payments py WHERE py.paid_on BETWEEN ? AND ? AND $wp GROUP BY m", [$from, $to, ...$pp]);
    $invoiced = DB::pairs("SELECT DATE_FORMAT(i.issue_date,'%Y-%m') m, SUM(i.total) FROM invoices i WHERE i.status NOT IN ('draft','cancelled') AND i.issue_date BETWEEN ? AND ? AND $wi GROUP BY m", [$from, $to, ...$pi]);
    $months = [];
    for ($t = strtotime(date('Y-m-01', strtotime($from))); $t <= strtotime($to) && count($months) < 36; $t = strtotime('+1 month', $t)) {
      $k = date('Y-m', $t);
      $months[$k] = ['label' => date('M y', $t), 'collected' => (float) ($collected[$k] ?? 0), 'invoiced' => (float) ($invoiced[$k] ?? 0)]; }
    $today = date('Y-m-d');
    $aging = DB::first("SELECT COALESCE(SUM(CASE WHEN COALESCE(i.due_date,i.issue_date)>=? THEN i.total-i.amount_paid END),0) AS b_current,
      COALESCE(SUM(CASE WHEN DATEDIFF(?, COALESCE(i.due_date,i.issue_date)) BETWEEN 1 AND 30 THEN i.total-i.amount_paid END),0) AS b30,
      COALESCE(SUM(CASE WHEN DATEDIFF(?, COALESCE(i.due_date,i.issue_date)) BETWEEN 31 AND 60 THEN i.total-i.amount_paid END),0) AS b60,
      COALESCE(SUM(CASE WHEN DATEDIFF(?, COALESCE(i.due_date,i.issue_date)) BETWEEN 61 AND 90 THEN i.total-i.amount_paid END),0) AS b90,
      COALESCE(SUM(CASE WHEN DATEDIFF(?, COALESCE(i.due_date,i.issue_date))>90 THEN i.total-i.amount_paid END),0) AS b90p FROM invoices i WHERE i.status IN ".Finance::OPEN." AND $wi", [$today, $today, $today, $today, $today, ...$pi]);
    $top = DB::all("SELECT c.id, c.name, c.company, SUM(i.total-i.amount_paid) AS outstanding, COUNT(*) AS n, SUM(i.status='overdue') AS overdue_n, MIN(i.due_date) AS oldest_due FROM invoices i JOIN clients c ON c.id=i.client_id WHERE i.status IN ".Finance::OPEN." AND $wi GROUP BY c.id, c.name, c.company HAVING outstanding>0 ORDER BY outstanding DESC LIMIT 10", $pi);
    $tot = DB::first("SELECT COALESCE(SUM(CASE WHEN i.status NOT IN ('draft','cancelled') THEN i.total END),0) AS invoiced, COUNT(CASE WHEN i.status NOT IN ('draft','cancelled') THEN 1 END) AS n FROM invoices i WHERE i.issue_date BETWEEN ? AND ? AND $wi", [$from, $to, ...$pi]);
    $tot['collected'] = array_sum($collected);
    $tot['outstanding'] = array_sum($aging);
    $projects = [];
    if (feature_on('projects')) {
      [$wr, $pr] = $this->scope('pr.client_id');
      $projects = DB::all("SELECT pr.id, pr.name, pr.status, pr.budget, c.name AS client_name, c.company AS client_company, COALESCE(SUM(i.total),0) AS invoiced, COALESCE(SUM(i.amount_paid),0) AS collected FROM projects pr JOIN clients c ON c.id=pr.client_id LEFT JOIN invoices i ON i.project_id=pr.id AND i.status NOT IN ('draft','cancelled') WHERE $wr GROUP BY pr.id, pr.name, pr.status, pr.budget, c.name, c.company HAVING invoiced>0 OR pr.budget>0 ORDER BY invoiced DESC, pr.budget DESC LIMIT 25", $pr); }
    return $this->view('crm::reports/index', ['title' => 'Financial reports', 'from' => $from, 'to' => $to, 'months' => $months, 'aging' => $aging, 'top' => $top, 'tot' => $tot, 'projects' => $projects, 'projOn' => feature_on('projects'), 'styles' => ['css/crm.css'], 'scripts' => ['js/crm.js']]); }

  public function outstandingCsv(): Response {
    [$wi, $pi] = $this->scope('i.client_id');
    $rows = DB::all('SELECT c.name, c.company, c.email, i.number, i.issue_date, i.due_date, i.status, i.total, i.amount_paid, i.total-i.amount_paid AS balance FROM invoices i JOIN clients c ON c.id=i.client_id WHERE i.status IN '.Finance::OPEN." AND $wi ORDER BY balance DESC LIMIT 10000", $pi);
    $fh = fopen('php://temp', 'w+');
    fputcsv($fh, ['Client', 'Company', 'Email', 'Invoice', 'Issue date', 'Due date', 'Days overdue', 'Status', 'Total', 'Paid', 'Balance'], ',', '"', '\\');
    $safe = static fn ($v) => is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'".$v : $v;
    foreach ($rows as $r) {
      $days = $r['due_date'] ? max(0, (int) floor((strtotime(date('Y-m-d')) - strtotime($r['due_date'])) / 86400)) : 0;
      fputcsv($fh, array_map($safe, [$r['name'], $r['company'], $r['email'], $r['number'], $r['issue_date'], $r['due_date'], $days, $r['status'], number_format((float) $r['total'], 2, '.', ''), number_format((float) $r['amount_paid'], 2, '.', ''), number_format((float) $r['balance'], 2, '.', '')]), ',', '"', '\\'); }
    rewind($fh);
    $csv = "\xEF\xBB\xBF".stream_get_contents($fh);
    fclose($fh);
    Audit::log('crm.report.export', null, null, ['report' => 'outstanding', 'rows' => count($rows)]);
    return Response::download($csv, 'outstanding-'.date('Y-m-d').'.csv', 'text/csv; charset=utf-8'); } }
