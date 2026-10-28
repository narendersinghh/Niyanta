<?php
namespace Modules\Ai\Services;
use Core\DB;

/** Executes a validated filter spec as scoped, parameterised SQL. The model never writes SQL. */
final class NlSearch {
  public const PERMS = ['clients' => ['crm.clients.view'], 'projects' => ['projects.view'], 'tasks' => ['projects.view'], 'invoices' => ['crm.finance.view'], 'employees' => ['hr.directory.view', 'hr.employees.view']];
  public const LABELS = ['clients' => 'Clients', 'projects' => 'Projects', 'tasks' => 'Tasks', 'invoices' => 'Invoices', 'employees' => 'Employees'];

  public static function allowed(array $u): array {
    return array_values(array_filter(array_keys(self::PERMS), static fn ($e) => array_filter(self::PERMS[$e], static fn ($p) => Scope::can($p, $u)) !== [])); }

  private static function words(string $kw): array { return array_slice(array_values(array_filter(preg_split('/\s+/', $kw) ?: [], static fn ($w) => mb_strlen($w) >= 2)), 0, 5); }

  /** Each keyword must match at least one of the columns. */
  private static function kw(array $cols, string $kw, array &$p): string {
    $sql = '';
    foreach (self::words($kw) as $w) { $sql .= ' AND ('.implode(' OR ', array_map(static fn ($c) => "$c LIKE ?", $cols)).')'; foreach ($cols as $c) { $p[] = DB::like($w); } }
    return $sql; }

  private static function dates(string $col, array $s, array &$p): string {
    $sql = '';
    if ($s['date_from']) { $sql .= " AND $col>=?"; $p[] = $s['date_from']; }
    if ($s['date_to']) { $sql .= " AND $col<=?"; $p[] = $s['date_to'].(str_contains($col, 'created_at') ? ' 23:59:59' : ''); }
    return $sql; }

  /** @return array{label:string, rows:list<array{title:string,meta:string,url:string,icon:string,status:?string}>, notes:list<string>} */
  public static function run(array $s, array $u, int $limit = 30): array {
    $e = $s['entity'];
    if (!in_array($e, self::allowed($u), true)) { throw new AiException('You do not have access to search '.$e.'.', 'entity not permitted: '.$e); }
    $me = (int) $u['id']; $today = date('Y-m-d'); $p = []; $notes = []; $rows = [];
    switch ($e) {
      case 'clients':
        $sql = 'SELECT c.id, c.name, c.company, c.status, c.industry, c.created_at FROM clients c WHERE 1=1'.Scope::where(Scope::clientIds($u), 'c.id', $p).self::kw(['c.name', 'c.company', 'c.industry', 'c.city'], $s['keywords'], $p);
        if ($s['status']) { $sql .= ' AND c.status=?'; $p[] = $s['status']; }
        $sql .= self::dates('c.created_at', $s, $p);
        if ($s['assignee_me']) { $sql .= ' AND c.id IN (SELECT client_id FROM client_managers WHERE user_id=?)'; $p[] = $me; }
        if ($s['overdue']) {
          if (Scope::can('crm.finance.view', $u)) { $sql .= " AND EXISTS (SELECT 1 FROM invoices i WHERE i.client_id=c.id AND (i.status='overdue' OR (i.status IN ('sent','partial') AND i.due_date<?)))"; $p[] = $today; }
          else { $notes[] = 'Overdue filter ignored (requires invoice access).'; } }
        foreach (DB::all($sql.' ORDER BY c.name LIMIT '.$limit, $p) as $r) { $rows[] = ['title' => $r['name'], 'meta' => trim(($r['company'] ?? '').' · '.($r['industry'] ?? ''), ' ·'), 'url' => '/clients/'.$r['id'], 'icon' => 'briefcase', 'status' => $r['status']]; }
        break;
      case 'projects':
        $sql = 'SELECT p.id, p.name, p.code, p.status, p.deadline, p.progress, c.name AS client FROM projects p LEFT JOIN clients c ON c.id=p.client_id WHERE 1=1'.Scope::where(Scope::projectIds($u), 'p.id', $p).self::kw(['p.name', 'p.code', 'c.name'], $s['keywords'], $p);
        if ($s['status']) { $sql .= ' AND p.status=?'; $p[] = $s['status']; }
        $sql .= self::dates('p.deadline', $s, $p);
        if ($s['assignee_me']) { $sql .= ' AND (p.manager_id=? OR p.id IN (SELECT project_id FROM project_members WHERE user_id=?))'; array_push($p, $me, $me); }
        if ($s['overdue']) { $sql .= " AND p.deadline<? AND p.status NOT IN ('completed','cancelled')"; $p[] = $today; }
        foreach (DB::all($sql.' ORDER BY p.deadline IS NULL, p.deadline, p.name LIMIT '.$limit, $p) as $r) { $rows[] = ['title' => $r['name'], 'meta' => trim(($r['client'] ?? 'Internal').' · '.(int) $r['progress'].'% · deadline '.fdate($r['deadline'])), 'url' => '/projects/'.$r['id'], 'icon' => 'folder', 'status' => $r['status']]; }
        break;
      case 'tasks':
        $ids = Scope::projectIds($u);
        $sql = 'SELECT t.id, t.title, t.status, t.priority, t.due_date, p.name AS project, a.name AS assignee FROM tasks t JOIN projects p ON p.id=t.project_id LEFT JOIN users a ON a.id=t.assignee_id WHERE 1=1';
        if ($ids !== null) { $sql .= ' AND (t.assignee_id=?'; $p[] = $me; if ($ids) { $sql .= ' OR t.project_id IN '.DB::in($ids); array_push($p, ...$ids); } $sql .= ')'; }
        $sql .= self::kw(['t.title', 'p.name'], $s['keywords'], $p);
        if ($s['status']) { $sql .= ' AND t.status=?'; $p[] = $s['status']; }
        $sql .= self::dates('t.due_date', $s, $p);
        if ($s['assignee_me']) { $sql .= ' AND t.assignee_id=?'; $p[] = $me; }
        if ($s['overdue']) { $sql .= " AND t.due_date<? AND t.status<>'done'"; $p[] = $today; }
        foreach (DB::all($sql.' ORDER BY t.due_date IS NULL, t.due_date, t.id DESC LIMIT '.$limit, $p) as $r) { $rows[] = ['title' => $r['title'], 'meta' => $r['project'].' · due '.fdate($r['due_date']).' · '.($r['assignee'] ?? 'Unassigned'), 'url' => '/tasks/'.$r['id'], 'icon' => 'check-square', 'status' => $r['status']]; }
        break;
      case 'invoices':
        $sql = 'SELECT i.id, i.number, i.status, i.due_date, i.issue_date, i.total, i.amount_paid, c.name AS client FROM invoices i JOIN clients c ON c.id=i.client_id WHERE 1=1'.Scope::where(Scope::clientIds($u), 'i.client_id', $p).self::kw(['i.number', 'i.title', 'c.name', 'c.company'], $s['keywords'], $p);
        if ($s['status']) { $sql .= ' AND i.status=?'; $p[] = $s['status']; }
        $sql .= self::dates('i.issue_date', $s, $p);
        if ($s['assignee_me']) { $sql .= ' AND i.client_id IN (SELECT client_id FROM client_managers WHERE user_id=?)'; $p[] = $me; }
        if ($s['overdue']) { $sql .= " AND (i.status='overdue' OR (i.status IN ('sent','partial') AND i.due_date<?))"; $p[] = $today; }
        foreach (DB::all($sql.' ORDER BY i.issue_date DESC LIMIT '.$limit, $p) as $r) { $rows[] = ['title' => $r['number'].' · '.$r['client'], 'meta' => 'Balance '.money((float) $r['total'] - (float) $r['amount_paid']).' · due '.fdate($r['due_date']), 'url' => '/invoices/'.$r['id'], 'icon' => 'receipt', 'status' => $r['status']]; }
        break;
      case 'employees':
        $sql = "SELECT u.id, u.name, u.designation, u.department, u.status, u.joined_on FROM users u WHERE u.status<>'invited'".self::kw(['u.name', 'u.designation', 'u.department'], $s['keywords'], $p);
        $st = $s['status'];
        if (!Scope::can('hr.employees.view', $u)) { if ($st === 'inactive') { $notes[] = 'Only active team members are listed in the directory.'; } $st = 'active'; }
        if ($st) { $sql .= ' AND u.status=?'; $p[] = $st; }
        $sql .= self::dates('u.joined_on', $s, $p);
        if ($s['assignee_me']) { $sql .= ' AND u.manager_id=?'; $p[] = $me; }
        if ($s['overdue']) { $notes[] = 'Overdue filter does not apply to employees.'; }
        foreach (DB::all($sql.' ORDER BY u.name LIMIT '.$limit, $p) as $r) { $rows[] = ['title' => $r['name'], 'meta' => trim(($r['designation'] ?? '').' · '.($r['department'] ?? ''), ' ·'), 'url' => '/hr/employees/'.$r['id'], 'icon' => 'user', 'status' => $r['status']]; }
        break; }
    return ['label' => self::LABELS[$e], 'rows' => $rows, 'notes' => $notes]; } }
