<?php
namespace Modules\Ai\Services;
use Core\{DB, HttpException};

/**
 * Builds the minimal, scoped data sent to the model for each feature.
 * Never includes contact emails/phones, tax or bank identifiers, salaries or HR documents.
 */
final class Context {
  public static function cut(?string $s, int $n): string { $s = trim(preg_replace('/\s+/', ' ', strip_tags((string) $s)) ?? ''); return mb_strlen($s) > $n ? mb_substr($s, 0, $n - 1).'…' : $s; }

  private static function kv(array $rows): string {
    $out = [];
    foreach ($rows as $k => $v) { if ($v !== null && $v !== '') { $out[] = $k.': '.$v; } }
    return implode("\n", $out); }

  public static function client(array $u, int $id): array {
    if (!Scope::canSeeClient($u, $id)) { throw new HttpException(404, 'Client not found.'); }
    $c = DB::first('SELECT id, name, company, industry, status, city, country, source, notes, currency, created_at FROM clients WHERE id=?', [$id]) ?? throw new HttpException(404, 'Client not found.');
    return $c; }

  /** Profile + last journey entries + open invoices (if the user may see finance) + visible projects. */
  public static function clientBrief(array $u, int $id, int $journey = 30, bool $invoices = true): string {
    $c = self::client($u, $id);
    $txt = "CLIENT\n".self::kv(['Name' => $c['name'], 'Company' => $c['company'], 'Industry' => $c['industry'], 'Status' => $c['status'], 'Location' => trim(($c['city'] ?? '').', '.($c['country'] ?? ''), ', '), 'Source' => $c['source'], 'Client since' => substr((string) $c['created_at'], 0, 10), 'Notes' => self::cut($c['notes'], 600)]);
    if ($journey > 0) {
      $acts = DB::all('SELECT type, title, body, is_internal, occurred_at, follow_up_at, follow_up_done FROM client_activities WHERE client_id=? ORDER BY occurred_at DESC, id DESC LIMIT '.(int) $journey, [$id]);
      $txt .= "\n\nCLIENT JOURNEY (latest first, ".count($acts)." entries)";
      foreach ($acts as $a) { $txt .= "\n- ".substr($a['occurred_at'], 0, 10).' ['.$a['type'].($a['is_internal'] ? ', internal' : '').'] '.self::cut($a['title'], 120).($a['body'] ? ', '.self::cut($a['body'], 300) : '').($a['follow_up_at'] ? ' (follow-up '.substr($a['follow_up_at'], 0, 10).($a['follow_up_done'] ? ' done' : ' pending').')' : ''); }
      if (!$acts) { $txt .= "\n(none recorded)"; } }
    if ($invoices && Scope::can('crm.finance.view', $u)) {
      $inv = DB::all("SELECT number, title, issue_date, due_date, status, currency, total, amount_paid FROM invoices WHERE client_id=? AND status IN ('sent','partial','overdue') ORDER BY due_date LIMIT 20", [$id]);
      $txt .= "\n\nOPEN INVOICES (".count($inv).')';
      foreach ($inv as $i) { $txt .= "\n- ".$i['number'].($i['title'] ? ' '.self::cut($i['title'], 60) : '').': '.$i['currency'].' '.number_format((float) $i['total'] - (float) $i['amount_paid'], 2, '.', '').' due of '.number_format((float) $i['total'], 2, '.', '').', issued '.$i['issue_date'].', due '.($i['due_date'] ?? 'n/a').', status '.$i['status'].($i['due_date'] && $i['due_date'] < date('Y-m-d') ? ' (OVERDUE '.(int) ((time() - strtotime($i['due_date'])) / 86400).' days)' : ''); }
      if (!$inv) { $txt .= "\n(no open invoices)"; } }
    $pids = Scope::projectIds($u);
    if ($pids !== []) {
      $p = [$id];
      $ps = DB::all('SELECT name, status, progress, deadline FROM projects WHERE client_id=?'.Scope::where($pids, 'id', $p).' ORDER BY created_at DESC LIMIT 10', $p);
      if ($ps) { $txt .= "\n\nPROJECTS"; foreach ($ps as $r) { $txt .= "\n- ".self::cut($r['name'], 80).': '.$r['status'].', '.(int) $r['progress'].'% done'.($r['deadline'] ? ', deadline '.$r['deadline'] : ''); } } }
    return $txt; }

  /** Short client line for email drafts. */
  public static function clientLine(array $u, int $id): string {
    $c = self::client($u, $id);
    $acts = DB::column('SELECT CONCAT(DATE(occurred_at), \' \', type, \': \', title) FROM client_activities WHERE client_id=? AND is_internal=0 ORDER BY occurred_at DESC LIMIT 5', [$id]);
    return 'Client: '.$c['name'].($c['company'] ? ' ('.$c['company'].')' : '').($c['industry'] ? ', '.$c['industry'] : '')."\nRecent interactions:\n".($acts ? '- '.implode("\n- ", array_map(static fn ($a) => self::cut($a, 140), $acts)) : '(none)'); }

  public static function projectBrief(array $u, int $id): string {
    if (!Scope::canSeeProject($u, $id)) { throw new HttpException(404, 'Project not found.'); }
    $pr = DB::first('SELECT p.name, p.code, p.description, p.status, p.priority, p.start_date, p.deadline, p.progress, p.estimated_hours, p.billing_type, c.name AS client, m.name AS manager FROM projects p LEFT JOIN clients c ON c.id=p.client_id LEFT JOIN users m ON m.id=p.manager_id WHERE p.id=?', [$id]) ?? throw new HttpException(404, 'Project not found.');
    $today = date('Y-m-d');
    $txt = "PROJECT\n".self::kv(['Name' => $pr['name'], 'Code' => $pr['code'], 'Client' => $pr['client'], 'Manager' => $pr['manager'], 'Status' => $pr['status'], 'Priority' => $pr['priority'], 'Start' => $pr['start_date'], 'Deadline' => $pr['deadline'].($pr['deadline'] && $pr['deadline'] < $today && !in_array($pr['status'], ['completed', 'cancelled'], true) ? ' (PASSED)' : ''), 'Progress' => (int) $pr['progress'].'%', 'Estimated hours' => $pr['estimated_hours'], 'Billing' => $pr['billing_type'], 'Description' => self::cut($pr['description'], 500), 'Today' => $today]);
    $counts = DB::pairs('SELECT status, COUNT(*) FROM tasks WHERE project_id=? AND parent_id IS NULL GROUP BY status', [$id]);
    $txt .= "\n\nTASKS BY STATUS: ".($counts ? implode(', ', array_map(static fn ($k, $v) => "$k $v", array_keys($counts), $counts)) : 'no tasks');
    $ms = DB::all('SELECT title, due_date, status FROM milestones WHERE project_id=? ORDER BY position, due_date LIMIT 20', [$id]);
    $txt .= "\n\nMILESTONES";
    foreach ($ms as $m) { $txt .= "\n- ".self::cut($m['title'], 100).': '.$m['status'].($m['due_date'] ? ', due '.$m['due_date'].($m['due_date'] < $today && $m['status'] !== 'completed' ? ' (LATE)' : '') : ''); }
    if (!$ms) { $txt .= "\n(none)"; }
    $od = DB::all("SELECT t.title, t.due_date, t.priority, t.status, u.name AS assignee FROM tasks t LEFT JOIN users u ON u.id=t.assignee_id WHERE t.project_id=? AND t.status<>'done' AND t.due_date<? ORDER BY t.due_date LIMIT 15", [$id, $today]);
    $txt .= "\n\nOVERDUE TASKS (".count($od).')';
    foreach ($od as $t) { $txt .= "\n- ".self::cut($t['title'], 100).', due '.$t['due_date'].', '.$t['priority'].', '.$t['status'].($t['assignee'] ? ', '.explode(' ', $t['assignee'])[0] : ', unassigned'); }
    try {
      $h = DB::first('SELECT COALESCE(SUM(duration_seconds),0) AS total, COALESCE(SUM(CASE WHEN started_at>=? THEN duration_seconds END),0) AS week FROM time_entries WHERE project_id=?', [date('Y-m-d', strtotime('-7 days')), $id]);
      $txt .= "\n\nHOURS LOGGED: ".round($h['total'] / 3600, 1).' total, '.round($h['week'] / 3600, 1).' in the last 7 days'; }
    catch (\Throwable) { $txt .= "\n\nHOURS LOGGED: not available"; }
    try { $cr = (int) DB::value("SELECT COUNT(*) FROM change_requests WHERE project_id=? AND status='pending'", [$id]); $txt .= "\nPENDING CHANGE REQUESTS: ".$cr; }
    catch (\Throwable) {}
    return $txt; }

  /** Overdue / unpaid invoices visible to the user (for the reminder picker). */
  public static function overdueInvoices(array $u, int $limit = 200): array {
    if (!Scope::can('crm.finance.view', $u)) { return []; }
    $p = [date('Y-m-d')];
    return DB::all("SELECT i.id, i.number, i.due_date, i.currency, i.total, i.amount_paid, c.name AS client FROM invoices i JOIN clients c ON c.id=i.client_id WHERE (i.status='overdue' OR (i.status IN ('sent','partial') AND i.due_date<?))".Scope::where(Scope::clientIds($u), 'i.client_id', $p).' ORDER BY i.due_date LIMIT '.(int) $limit, $p); }

  /** Invoice (must be visible & unpaid) plus client info needed to draft and send a reminder. */
  public static function invoice(array $u, int $id): array {
    if (!Scope::can('crm.finance.view', $u)) { throw new HttpException(403, 'You do not have permission to view invoices.'); }
    $i = DB::first('SELECT i.*, c.name AS client_name, c.company AS client_company, c.email AS client_email FROM invoices i JOIN clients c ON c.id=i.client_id WHERE i.id=?', [$id]);
    if (!$i || !Scope::canSeeClient($u, (int) $i['client_id'])) { throw new HttpException(404, 'Invoice not found.'); }
    return $i; }

  public static function invoiceBrief(array $i): string {
    $contact = DB::value('SELECT name FROM client_contacts WHERE client_id=? ORDER BY is_primary DESC, id LIMIT 1', [$i['client_id']]);
    $bal = (float) $i['total'] - (float) $i['amount_paid'];
    return "INVOICE\n".self::kv(['Number' => $i['number'], 'Title' => self::cut($i['title'], 120), 'Client' => $i['client_name'].($i['client_company'] ? ' ('.$i['client_company'].')' : ''), 'Contact name' => $contact, 'Issued' => $i['issue_date'], 'Due' => $i['due_date'], 'Days overdue' => $i['due_date'] && $i['due_date'] < date('Y-m-d') ? (int) ((time() - strtotime($i['due_date'])) / 86400) : 0, 'Currency' => $i['currency'], 'Total' => number_format((float) $i['total'], 2, '.', ''), 'Paid' => number_format((float) $i['amount_paid'], 2, '.', ''), 'Balance due' => number_format($bal, 2, '.', ''), 'Last reminder' => $i['last_reminder_at'] ? substr($i['last_reminder_at'], 0, 10) : 'never', 'Our company' => (string) \Core\Settings::get('branding.app_name', 'Niyanta')]); } }
