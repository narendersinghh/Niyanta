<?php
namespace Modules\Crm\Services;
use Core\{DB, Gate, Notify, View};

/** Dashboard widgets, global search and scheduled jobs for the CRM module. All reads are scoped through ClientAccess. */
final class Hooks {
  /** Compact money for tiles: lakh/crore for INR, K/M otherwise. */
  public static function compact(float $v): string {
    $a = abs($v);
    if (setting('general.currency_code', 'INR') === 'INR') { [$d, $s] = $a >= 1e7 ? [1e7, 'Cr'] : ($a >= 1e5 ? [1e5, 'L'] : ($a >= 1e4 ? [1e3, 'K'] : [1, ''])); }
    else { [$d, $s] = $a >= 1e6 ? [1e6, 'M'] : ($a >= 1e4 ? [1e3, 'K'] : [1, '']); }
    return currency_symbol().($d > 1 ? rtrim(rtrim(number_format($v / $d, $d === 1e3 ? 1 : 2), '0'), '.').$s : number_format($v, 0)); }

  public static function activeClientsTile(array $u): string {
    [$w, $p] = ClientAccess::where($u);
    $r = DB::first("SELECT COALESCE(SUM(c.status='active'),0) AS active, COALESCE(SUM(c.status='lead'),0) AS leads FROM clients c WHERE $w", $p);
    return Ui::tile('lav', 'briefcase', (string) (int) $r['active'], 'Active clients · '.(int) $r['leads'].' leads', '/clients?status=active'); }

  public static function outstandingTile(array $u): string {
    [$w, $p] = ClientAccess::where($u, 'i.client_id');
    $r = DB::first('SELECT COALESCE(SUM(i.total-i.amount_paid),0) AS due, COALESCE(SUM(i.status=\'overdue\'),0) AS overdue FROM invoices i WHERE i.status IN '.Finance::OPEN." AND $w", $p);
    return Ui::tile('peach', 'wallet', self::compact((float) $r['due']), 'Outstanding · '.(int) $r['overdue'].' overdue', (int) $r['overdue'] ? '/invoices?status=overdue' : '/invoices', money($r['due'])); }

  /** Quotations awaiting a decision (draft/sent), shown only while the quotations feature is on. */
  public static function quotationsWidget(array $u): string {
    [$w, $p] = ClientAccess::where($u, 'q.client_id');
    $rows = DB::all("SELECT q.id, q.number, q.title, q.status, q.total, q.valid_until, COALESCE(NULLIF(c.company,''), c.name) AS client FROM quotations q JOIN clients c ON c.id=q.client_id WHERE q.status IN ('draft','sent') AND q.invoice_id IS NULL AND $w ORDER BY q.status='sent' DESC, q.valid_until IS NULL, q.valid_until LIMIT 5", $p);
    if (!$rows) { return ''; }
    $sum = DB::first("SELECT COUNT(*) AS n, COALESCE(SUM(q.total),0) AS v FROM quotations q WHERE q.status IN ('draft','sent') AND q.invoice_id IS NULL AND $w", $p);
    return View::partial('crm::widgets/quotations', ['rows' => $rows, 'sum' => $sum]); }

  public static function followUpsWidget(array $u): string {
    [$w, $p] = ClientAccess::where($u, 'a.client_id');
    $rows = DB::all("SELECT a.id, a.client_id, a.title, a.type, a.follow_up_at, COALESCE(NULLIF(c.company,''), c.name) AS client FROM client_activities a JOIN clients c ON c.id=a.client_id WHERE a.follow_up_done=0 AND a.follow_up_at IS NOT NULL AND a.follow_up_at<? AND $w ORDER BY a.follow_up_at LIMIT 6", [date('Y-m-d 00:00:00', strtotime('+8 days')), ...$p]);
    $recent = $rows ? [] : DB::all("SELECT a.id, a.client_id, a.title, a.type, a.occurred_at, a.is_internal, COALESCE(NULLIF(c.company,''), c.name) AS client, us.name AS user_name FROM client_activities a JOIN clients c ON c.id=a.client_id LEFT JOIN users us ON us.id=a.user_id WHERE $w ORDER BY a.occurred_at DESC LIMIT 6", $p);
    return View::partial('crm::widgets/followups', ['rows' => $rows, 'recent' => $recent]); }

  public static function overdueWidget(array $u): string {
    [$w, $p] = ClientAccess::where($u, 'i.client_id');
    $rows = DB::all("SELECT i.id, i.number, i.due_date, i.total-i.amount_paid AS balance, COALESCE(NULLIF(c.company,''), c.name) AS client FROM invoices i JOIN clients c ON c.id=i.client_id WHERE i.status='overdue' AND $w ORDER BY i.due_date LIMIT 6", $p);
    $sum = $rows ? (float) DB::value("SELECT COALESCE(SUM(i.total-i.amount_paid),0) FROM invoices i WHERE i.status='overdue' AND $w", $p) : 0.0;
    return View::partial('crm::widgets/overdue', ['rows' => $rows, 'sum' => $sum]); }

  public static function search(string $q, array $u): array {
    $out = [];
    if (!Gate::allows('crm.clients.view', $u)) { return $out; }
    [$w, $p] = ClientAccess::where($u);
    $l = DB::like($q);
    $out['Clients'] = array_map(static fn ($r) => ['title' => $r['company'] ?: $r['name'], 'meta' => implode(' · ', array_filter([$r['company'] ? $r['name'] : '', $r['email'] ?: $r['phone'] ?: '', ucwords(str_replace('_', ' ', $r['status']))])), 'url' => '/clients/'.$r['id'], 'icon' => 'briefcase'],
      DB::all("SELECT c.id, c.name, c.company, c.email, c.phone, c.status FROM clients c WHERE $w AND (c.name LIKE ? OR c.company LIKE ? OR c.email LIKE ? OR c.phone LIKE ?) ORDER BY c.status='active' DESC, c.name LIMIT 8", [...$p, $l, $l, $l, $l]));
    [$wi, $pi] = ClientAccess::where($u, 'd.client_id');
    if (Gate::allows('crm.finance.view', $u)) {
      $out['Invoices'] = array_map(static fn ($r) => ['title' => $r['number'].($r['title'] ? ', '.$r['title'] : ''), 'meta' => $r['client'].' · '.money($r['total']).' · '.ucfirst($r['status']), 'url' => '/invoices/'.$r['id'], 'icon' => 'receipt'],
        DB::all("SELECT d.id, d.number, d.title, d.total, d.status, COALESCE(NULLIF(c.company,''), c.name) AS client FROM invoices d JOIN clients c ON c.id=d.client_id WHERE $wi AND (d.number LIKE ? OR d.title LIKE ?) ORDER BY d.id DESC LIMIT 8", [...$pi, $l, $l])); }
    if (Gate::allows('crm.quotations.view', $u)) {
      $out['Quotations'] = array_map(static fn ($r) => ['title' => $r['number'].($r['title'] ? ', '.$r['title'] : ''), 'meta' => $r['client'].' · '.money($r['total']).' · '.ucfirst($r['status']), 'url' => '/quotations/'.$r['id'], 'icon' => 'file-text'],
        DB::all("SELECT d.id, d.number, d.title, d.total, d.status, COALESCE(NULLIF(c.company,''), c.name) AS client FROM quotations d JOIN clients c ON c.id=d.client_id WHERE $wi AND (d.number LIKE ? OR d.title LIKE ?) ORDER BY d.id DESC LIMIT 8", [...$pi, $l, $l])); }
    return $out; }

  /** Daily: sent/partial invoices past their due date become overdue. */
  public static function markOverdue(): string {
    $n = DB::query("UPDATE invoices SET status='overdue', updated_at=? WHERE status IN ('sent','partial') AND due_date IS NOT NULL AND due_date<? AND total>amount_paid", [date('Y-m-d H:i:s'), date('Y-m-d')])->rowCount();
    return "marked $n overdue"; }

  /** Daily: one digest per account manager about overdue invoices + a notification per follow-up due today. */
  public static function reminders(): string {
    $sent = 0;
    $admins = DB::column("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE r.slug='super_admin' AND u.status='active'");
    $digest = !feature_on('invoices') ? [] : DB::all("SELECT u.id AS uid, COUNT(DISTINCT i.id) AS n, SUM(i.total-i.amount_paid) AS due FROM invoices i JOIN client_managers cm ON cm.client_id=i.client_id JOIN users u ON u.id=cm.user_id AND u.status='active' WHERE i.status='overdue' GROUP BY u.id");
    foreach ($digest as $d) { Notify::user((int) $d['uid'], $d['n'].' overdue invoice'.($d['n'] > 1 ? 's need' : ' needs').' attention', 'Outstanding on overdue invoices of your clients: '.money($d['due']).'.', '/invoices?status=overdue', true, 'receipt'); $sent++; }
    $orphan = !feature_on('invoices') ? ['n' => 0] : DB::first("SELECT COUNT(*) AS n, COALESCE(SUM(i.total-i.amount_paid),0) AS due FROM invoices i WHERE i.status='overdue' AND NOT EXISTS (SELECT 1 FROM client_managers cm JOIN users u ON u.id=cm.user_id AND u.status='active' WHERE cm.client_id=i.client_id)");
    if ((int) $orphan['n'] > 0) { foreach ($admins as $a) { Notify::user((int) $a, $orphan['n'].' overdue invoice(s) without an account manager', 'Balance due: '.money($orphan['due']).'. Assign a manager to these clients.', '/invoices?status=overdue', false, 'receipt'); $sent++; } }
    if (feature_on('invoices')) { DB::query("UPDATE invoices SET last_reminder_at=? WHERE status='overdue'", [date('Y-m-d H:i:s')]); }
    $fu = !feature_on('clients') ? [] : DB::all("SELECT a.id, a.client_id, a.title, a.follow_up_at, a.user_id, COALESCE(NULLIF(c.company,''), c.name) AS client, GROUP_CONCAT(cm.user_id) AS mgrs FROM client_activities a JOIN clients c ON c.id=a.client_id LEFT JOIN client_managers cm ON cm.client_id=a.client_id WHERE a.follow_up_done=0 AND a.follow_up_at BETWEEN ? AND ? GROUP BY a.id, a.client_id, a.title, a.follow_up_at, a.user_id, c.company, c.name LIMIT 1000", [date('Y-m-d 00:00:00'), date('Y-m-d 23:59:59')]);
    $active = $fu ? array_fill_keys(DB::column("SELECT id FROM users WHERE status='active'"), true) : [];
    foreach ($fu as $f) {
      $to = array_unique(array_filter(array_map('intval', explode(',', (string) $f['mgrs']))) ?: ($f['user_id'] ? [(int) $f['user_id']] : $admins));
      foreach ($to as $uid) { if (isset($active[$uid])) { Notify::user((int) $uid, 'Follow-up due today: '.$f['client'], $f['title'].' · '.ftime($f['follow_up_at']), '/clients/'.$f['client_id'].'#journey', false, 'clock'); $sent++; } } }
    return "sent $sent notifications"; } }
