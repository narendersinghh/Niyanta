<?php
namespace Modules\Ai\Services;
use Core\{DB, Modules};

/**
 * Server-side tools the model may call. Every tool runs as the CURRENT user and applies the same permission,
 * feature and record-level rules as the owning module. A refusal is returned as {"error": "..."} for the model to relay.
 * HR-only data (salary and compensation, bank/tax ids, ID documents, other people's leave reasons, HR notes) and
 * secrets are never selected here.
 */
final class Tools {
  public const MAX_JSON = 7000;
  private const RANGES = ['today', 'yesterday', 'this_week', 'last_week', 'next_week', 'this_month', 'last_month', 'this_year'];

  /** name => [description, properties, required, availability check fn(array $u): bool] */
  private static function defs(): array {
    $person = ['type' => 'string', 'description' => 'Name, first name or email of the person. Omit or use "me" for the current user.'];
    $from = ['type' => 'string', 'description' => 'Start date YYYY-MM-DD, or one of: '.implode(', ', self::RANGES).'.'];
    $to = ['type' => 'string', 'description' => 'End date YYYY-MM-DD (optional).'];
    $leave = static fn (array $u) => Scope::can('hr.leave.request', $u) || Scope::can('hr.leave.approve', $u) || Scope::can('hr.leave.manage', $u);
    $att = static fn (array $u) => Scope::can('attendance.track', $u) || Scope::can('attendance.view_all', $u);
    return [
      'search_records' => ['Keyword search across everything the user may see (clients, projects, tasks, invoices, quotations, employees, payslips...). Use it to find records by name.', ['query' => ['type' => 'string'], 'types' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Optional record types to keep, e.g. ["projects","tasks"].']], ['query'], static fn () => true],
      'my_profile' => ['The current user\'s own profile: role, designation, department, manager, joining date, open tasks and leave available.', [], [], static fn () => true],
      'my_tasks' => ['Tasks assigned to the current user.', ['range' => ['type' => 'string', 'enum' => ['open', 'overdue', 'today', 'this_week', 'next_week', 'done_this_week', 'all'], 'description' => 'Default open.']], [], static fn (array $u) => Scope::can('projects.view', $u)],
      'tasks_for' => ['Tasks assigned to a person the user may see (managers: their team).', ['person' => $person, 'range' => ['type' => 'string', 'enum' => ['open', 'overdue', 'today', 'this_week', 'next_week', 'done_this_week', 'all']]], ['person'], static fn (array $u) => Scope::can('projects.view', $u)],
      'work_summary' => ['Summary of a person\'s work in a period: tasks completed and in progress, hours tracked by project, work reports, attendance days. Managers may ask about their team; HR about anyone; employees only about themselves.', ['person' => $person, 'from' => $from, 'to' => $to], [], static fn () => true],
      'attendance' => ['Attendance (clock in/out, worked hours, status per day) of a person in a date range. Default: this month.', ['person' => $person, 'from' => $from, 'to' => $to], [], $att],
      'timesheet' => ['Weekly timesheet: hours per project and task, per day, and review status.', ['person' => $person, 'week' => ['type' => 'string', 'description' => 'Any date inside the week (YYYY-MM-DD) or this_week / last_week. Default this week.']], [], $att],
      'leave_balance' => ['Leave balances per leave type (allocated, used, pending, available), including optional holidays (OH).', ['person' => $person, 'year' => ['type' => 'integer']], [], $leave],
      'leave_requests' => ['Leave requests of a person (dates, days, type, status).', ['person' => $person, 'status' => ['type' => 'string', 'enum' => ['pending', 'approved', 'rejected', 'cancelled', 'all']], 'year' => ['type' => 'integer']], [], $leave],
      'holidays' => ['Company holiday calendar for a year: fixed holidays and optional festival days.', ['year' => ['type' => 'integer']], [], static fn (array $u) => $leave($u) || Scope::can('hr.directory.view', $u)],
      'team_on_leave' => ['Who is on approved leave on a date (names only).', ['date' => ['type' => 'string', 'description' => 'YYYY-MM-DD, today or tomorrow. Default today.']], [], static fn (array $u) => Scope::can('hr.directory.view', $u) && \Core\Features::permEnabled('hr.leave.request')],
      'find_people' => ['Look up colleagues in the team directory (designation, department, manager, email).', ['query' => ['type' => 'string']], ['query'], static fn (array $u) => Scope::can('hr.directory.view', $u) || Scope::can('hr.employees.view', $u)],
      'projects_overview' => ['Projects the user can see with status, progress, deadline and task counts.', ['status' => ['type' => 'string', 'enum' => ['planning', 'active', 'on_hold', 'completed', 'cancelled', 'open', 'all'], 'description' => 'Default open (not completed or cancelled).']], [], static fn (array $u) => Scope::can('projects.view', $u)],
      'project_status' => ['Detailed status of one project: progress, milestones, overdue tasks, hours, team.', ['project' => ['type' => 'string', 'description' => 'Project name, code or id.']], ['project'], static fn (array $u) => Scope::can('projects.view', $u)],
      'clients_overview' => ['Clients the user can see.', ['status' => ['type' => 'string', 'enum' => ['lead', 'active', 'on_hold', 'inactive', 'all']]], [], static fn (array $u) => Scope::can('crm.clients.view', $u)],
      'client_summary' => ['One client: profile, recent journey entries, projects and money owed.', ['client' => ['type' => 'string', 'description' => 'Client or company name, or id.']], ['client'], static fn (array $u) => Scope::can('crm.clients.view', $u)],
      'invoices' => ['Invoices the user can see, optionally by status or client.', ['status' => ['type' => 'string', 'enum' => ['draft', 'sent', 'partial', 'paid', 'overdue', 'cancelled', 'unpaid', 'all']], 'client' => ['type' => 'string']], [], static fn (array $u) => Scope::can('crm.finance.view', $u)],
      'overdue_invoices' => ['Unpaid invoices past their due date, with days overdue and balance.', [], [], static fn (array $u) => Scope::can('crm.finance.view', $u)],
      'quotations' => ['Quotations the user can see, optionally by status or client.', ['status' => ['type' => 'string', 'enum' => ['draft', 'sent', 'accepted', 'rejected', 'expired', 'all']], 'client' => ['type' => 'string']], [], static fn (array $u) => Scope::can('crm.quotations.view', $u)],
      'payslips' => ['Finalised payslips (net pay). Only the user\'s own, unless they run payroll.', ['person' => $person], [], static fn (array $u) => Scope::can('payroll.view_own', $u) || Scope::can('payroll.manage', $u)],
      'company_documents' => ['Company policies, handbooks and forms shared with the team.', ['query' => ['type' => 'string']], [], static fn (array $u) => Scope::can('hr.company_docs.view', $u)],
    ]; }

  /** Tool definitions offered to the model for this user. */
  public static function forUser(array $u): array {
    $out = [];
    foreach (self::defs() as $name => [$desc, $props, $req, $ok]) {
      if (!$ok($u)) { continue; }
      $schema = ['type' => 'object', 'properties' => $props ?: new \stdClass()];
      if ($req) { $schema['required'] = $req; }
      $out[] = ['name' => $name, 'description' => $desc, 'schema' => $schema]; }
    return $out; }

  public static function label(string $name): string { return ucfirst(str_replace('_', ' ', $name)); }

  /** Execute a tool call for $u. Always returns a compact JSON string. */
  public static function run(array $u, string $name, array $args): string {
    $defs = self::defs();
    try {
      if (!isset($defs[$name]) || !method_exists(self::class, 't_'.$name)) { $r = self::deny('there is no such tool'); }
      elseif (!$defs[$name][3]($u)) { $r = self::deny('this information is not available to your role'); }
      else { $m = 't_'.$name; $r = self::$m($u, $args); } }
    catch (\Throwable $e) { error_log('AI tool '.$name.': '.$e->getMessage()); $r = ['error' => 'The lookup failed. Try again or ask differently.']; }
    return self::fit($r); }

  /* ---------- helpers ---------- */

  private static function deny(string $why): array { return ['error' => 'Not permitted: '.$why.'.']; }
  private static function str(array $a, string $k, int $max = 120): string { $v = $a[$k] ?? ''; return is_scalar($v) ? mb_substr(trim((string) $v), 0, $max) : ''; }
  private static function cut(?string $s, int $n): string { $s = trim(preg_replace('/\s+/', ' ', strip_tags((string) $s)) ?? ''); return mb_strlen($s) > $n ? mb_substr($s, 0, $n - 1).'…' : $s; }
  private static function h(int|float|string|null $sec): float { return round(((float) $sec) / 3600, 1); }
  private static function money(mixed $v): string { return number_format((float) $v, 2, '.', ''); }
  private static function first(?string $name): string { return $name ? explode(' ', trim($name))[0] : ''; }

  /** Encode, shrinking the longest lists until the JSON fits. */
  public static function fit(array $r): string {
    $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;
    $j = (string) json_encode($r, $flags);
    for ($i = 0; strlen($j) > self::MAX_JSON && $i < 12; $i++) {
      $longest = null; $n = 1;
      foreach ($r as $k => $v) { if (is_array($v) && array_is_list($v) && count($v) > $n) { $longest = $k; $n = count($v); } }
      if ($longest === null) { break; }
      $r[$longest] = array_slice($r[$longest], 0, (int) ceil($n / 2));
      $r['truncated'] = true;
      $j = (string) json_encode($r, $flags); }
    return strlen($j) > self::MAX_JSON ? mb_strcut($j, 0, self::MAX_JSON).'… (truncated)' : $j; }

  /** [from, to] from keywords or dates; $max days. */
  public static function period(mixed $from, mixed $to, string $default, int $max = 93): array {
    $kw = static function (string $k): ?array {
      $t = strtotime('today'); $ws = date('Y-m-d', strtotime('-'.(date('N', $t) - 1).' days', $t));
      return match ($k) {
        'today' => [date('Y-m-d'), date('Y-m-d')], 'yesterday' => [date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-1 day'))],
        'this_week' => [$ws, date('Y-m-d', strtotime($ws.' +6 days'))], 'last_week' => [date('Y-m-d', strtotime($ws.' -7 days')), date('Y-m-d', strtotime($ws.' -1 day'))],
        'next_week' => [date('Y-m-d', strtotime($ws.' +7 days')), date('Y-m-d', strtotime($ws.' +13 days'))],
        'this_month' => [date('Y-m-01'), date('Y-m-t')], 'last_month' => [date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('first day of last month'))],
        'this_year' => [date('Y-01-01'), date('Y-12-31')], default => null }; };
    $f = is_string($from) ? strtolower(str_replace([' ', '-'], '_', trim($from))) : '';
    if ($f !== '' && ($r = $kw($f))) { return $r; }
    $date = static fn ($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($v)) && strtotime(trim($v)) ? date('Y-m-d', strtotime(trim($v))) : null;
    $a = $date($from); $b = $date($to);
    if (!$a && !$b) { return $kw($default); }
    $a ??= $b; $b ??= max($a, date('Y-m-d'));
    if ($b < $a) { [$a, $b] = [$b, $a]; }
    if ((strtotime($b) - strtotime($a)) / 86400 > $max) { $a = date('Y-m-d', strtotime($b.' -'.$max.' days')); }
    return [$a, $b]; }

  /** Resolve the person argument inside $ids. Returns [row, null] or [null, error array]. */
  private static function who(array $u, array $a, ?array $ids, string $what): array {
    $r = People::resolve($u, $a['person'] ?? '', $ids);
    if (isset($r['person'])) { return [$r['person'], null]; }
    if (isset($r['candidates'])) { return [null, ['error' => 'More than one person matches. Ask which one.', 'candidates' => $r['candidates']]]; }
    $q = self::str($a, 'person', 60);
    return [null, $ids !== null && count($ids) <= 1 ? self::deny('you can only see your own '.$what) : ['error' => 'No one called "'.$q.'" among the people whose '.$what.' you can see.']]; }

  private static function self(array $u, array $p): bool { return (int) $p['id'] === (int) $u['id']; }
  private static function personOut(array $p): array { return ['name' => $p['name'], 'designation' => $p['designation'], 'department' => $p['department']]; }

  /** May $u see the leave data of $id? HR / leave managers: everyone; approvers: direct reports; everyone: self. */
  private static function leaveAccess(array $u, int $id): bool {
    if ($id === (int) $u['id']) { return Scope::can('hr.leave.request', $u) || Scope::can('hr.leave.approve', $u) || Scope::can('hr.leave.manage', $u); }
    if (Scope::isSuper($u) || ($u['role_slug'] ?? '') === 'hr' || Scope::can('hr.leave.manage', $u)) { return true; }
    return Scope::can('hr.leave.approve', $u) && People::isDirectReport($u, $id); }
  private static function leaveAllIds(array $u): ?array {
    if (Scope::isSuper($u) || ($u['role_slug'] ?? '') === 'hr' || Scope::can('hr.leave.manage', $u)) { return null; }
    $ids = [(int) $u['id']];
    if (Scope::can('hr.leave.approve', $u)) { array_push($ids, ...array_map('intval', DB::column("SELECT id FROM users WHERE manager_id=? AND status='active'", [$u['id']]))); }
    return $ids; }

  /** Attendance / time data of $id: self with attendance.track, else attendance.view_all within the team. */
  private static function timeAccess(array $u, int $id): bool {
    if ($id === (int) $u['id']) { return Scope::can('attendance.track', $u) || Scope::can('attendance.view_all', $u); }
    return Scope::can('attendance.view_all', $u) && People::inScope($u, $id); }

  /** Tasks of $id visible to $u: all of them for self, HR, unrestricted project viewers and the direct manager; otherwise only in projects $u can see. */
  private static function taskScope(array $u, int $id, array &$p): string {
    if (!Scope::can('projects.view', $u)) { return ' AND 1=0'; }
    $ids = Scope::projectIds($u);
    if ($ids === null || $id === (int) $u['id'] || People::isHr($u) || People::isDirectReport($u, $id)) { return ''; }
    return Scope::where($ids, 't.project_id', $p); }

  /* ---------- tools ---------- */

  private static function t_search_records(array $u, array $a): array {
    $q = self::str($a, 'query', 100);
    if (mb_strlen($q) < 2) { return ['error' => 'Give at least two characters to search for.']; }
    $types = array_map(static fn ($t) => strtolower((string) $t), array_filter((array) ($a['types'] ?? []), 'is_scalar'));
    $out = [];
    foreach (Modules::hooks('search') as $mod => $fn) {
      if ($mod === 'ai') { continue; }
      try { $groups = (array) $fn($q, $u); } catch (\Throwable $e) { error_log("AI search $mod: ".$e->getMessage()); continue; }
      foreach ($groups as $label => $rows) {
        if ($types && !array_filter($types, static fn ($t) => str_contains(strtolower((string) $label), rtrim($t, 's')))) { continue; }
        foreach (array_slice((array) $rows, 0, 6) as $r) { $out[] = ['type' => (string) $label, 'title' => self::cut($r['title'] ?? '', 120), 'meta' => self::cut($r['meta'] ?? '', 140), 'url' => (string) ($r['url'] ?? '')]; } } }
    return ['query' => $q, 'count' => count($out), 'results' => array_slice($out, 0, 40)]; }

  private static function t_my_profile(array $u, array $a): array {
    $me = DB::first('SELECT u.name, u.email, u.designation, u.department, u.employee_code, u.joined_on, r.name AS role, m.name AS manager FROM users u JOIN roles r ON r.id=u.role_id LEFT JOIN users m ON m.id=u.manager_id WHERE u.id=?', [$u['id']]);
    $out = ['profile' => $me + ['url' => '/hr/me']];
    if (Scope::can('projects.view', $u)) { $out['open_tasks'] = (int) DB::value("SELECT COUNT(*) FROM tasks WHERE assignee_id=? AND status<>'done'", [$u['id']]); $out['overdue_tasks'] = (int) DB::value("SELECT COUNT(*) FROM tasks WHERE assignee_id=? AND status<>'done' AND due_date<?", [$u['id'], date('Y-m-d')]); }
    if (Scope::can('hr.leave.request', $u) && class_exists('Modules\\Hr\\Services\\LeaveService')) { $out['leave_available'] = array_map(static fn ($b) => ['type' => $b['name'], 'available' => (float) $b['available']], array_slice(\Modules\Hr\Services\LeaveService::balances((int) $u['id'], (int) date('Y')), 0, 6)); }
    if (Scope::can('attendance.track', $u)) { $a = DB::first('SELECT status, clock_in, clock_out FROM attendance WHERE user_id=? AND work_date=?', [$u['id'], date('Y-m-d')]); $out['today'] = $a ? ['status' => $a['status'], 'in' => $a['clock_in'] ? substr($a['clock_in'], 11, 5) : null, 'out' => $a['clock_out'] ? substr($a['clock_out'], 11, 5) : null] : 'not clocked in'; }
    return $out; }

  private static function t_my_tasks(array $u, array $a): array { return self::t_tasks_for($u, ['person' => 'me'] + $a); }

  private static function t_tasks_for(array $u, array $a): array {
    [$p, $err] = self::who($u, $a, People::scope($u), 'tasks');
    if ($err) { return $err; }
    $range = in_array($a['range'] ?? '', ['open', 'overdue', 'today', 'this_week', 'next_week', 'done_this_week', 'all'], true) ? $a['range'] : 'open';
    $pp = [(int) $p['id']];
    $sql = 'SELECT t.id, t.title, t.status, t.priority, t.due_date, t.completed_at, pr.name AS project FROM tasks t JOIN projects pr ON pr.id=t.project_id WHERE t.assignee_id=?'.self::taskScope($u, (int) $p['id'], $pp);
    $today = date('Y-m-d'); [$ws, $we] = self::period('this_week', null, 'this_week'); [$nws, $nwe] = self::period('next_week', null, 'next_week');
    $sql .= match ($range) {
      'open' => " AND t.status<>'done'", 'overdue' => " AND t.status<>'done' AND t.due_date<'$today'", 'today' => " AND t.status<>'done' AND (t.due_date<='$today' OR t.status='in_progress')",
      'this_week' => " AND ((t.status<>'done' AND (t.due_date<='$we' OR t.status IN ('in_progress','review'))) OR (t.status='done' AND t.completed_at>='$ws'))", 'next_week' => " AND t.status<>'done' AND t.due_date BETWEEN '$nws' AND '$nwe'",
      'done_this_week' => " AND t.status='done' AND t.completed_at>='$ws'", default => '' };
    $rows = DB::all($sql." ORDER BY t.status='done', t.due_date IS NULL, t.due_date, FIELD(t.priority,'urgent','high','medium','low') LIMIT 30", $pp);
    return ['person' => $p['name'], 'range' => $range, 'today' => $today, 'count' => count($rows), 'tasks' => array_map(static fn ($t) => ['title' => self::cut($t['title'], 90), 'project' => $t['project'], 'status' => $t['status'], 'priority' => $t['priority'], 'due' => $t['due_date'], 'overdue' => $t['status'] !== 'done' && $t['due_date'] && $t['due_date'] < $today, 'url' => '/tasks/'.$t['id']], $rows)]; }

  private static function t_work_summary(array $u, array $a): array {
    [$p, $err] = self::who($u, $a, People::scope($u), 'work');
    if ($err) { return $err; }
    $pid = (int) $p['id'];
    [$from, $to] = self::period($a['from'] ?? null, $a['to'] ?? null, 'this_week', 62);
    $out = ['person' => self::personOut($p), 'from' => $from, 'to' => $to, 'today' => date('Y-m-d')]; $notes = [];
    if (Scope::can('projects.view', $u) && Modules::enabled('projects')) {
      $pp = [$pid]; $w = self::taskScope($u, $pid, $pp);
      $base = 'SELECT t.id, t.title, t.status, t.priority, t.due_date, t.completed_at, pr.name AS project FROM tasks t JOIN projects pr ON pr.id=t.project_id WHERE t.assignee_id=?'.$w;
      $map = static fn ($t) => ['title' => self::cut($t['title'], 80), 'project' => $t['project'], 'status' => $t['status'], 'due' => $t['due_date'], 'url' => '/tasks/'.$t['id']];
      $out['tasks_completed'] = array_map($map, DB::all($base." AND t.status='done' AND t.completed_at BETWEEN ? AND ? ORDER BY t.completed_at DESC LIMIT 15", [...$pp, $from.' 00:00:00', $to.' 23:59:59']));
      $out['tasks_in_progress'] = array_map($map, DB::all($base." AND t.status IN ('in_progress','review') ORDER BY t.due_date IS NULL, t.due_date LIMIT 15", $pp));
      $out['tasks_overdue'] = array_map($map, DB::all($base." AND t.status<>'done' AND t.due_date<? ORDER BY t.due_date LIMIT 10", [...$pp, date('Y-m-d')]));
      $out['open_tasks_total'] = (int) DB::value(str_replace('SELECT t.id, t.title, t.status, t.priority, t.due_date, t.completed_at, pr.name AS project', 'SELECT COUNT(*)', $base)." AND t.status<>'done'", $pp); }
    else { $notes[] = 'tasks: not available to you'; }
    if (self::timeAccess($u, $pid) && Modules::enabled('attendance')) {
      $rows = DB::all('SELECT COALESCE(pr.name, \'No project\') AS project, SUM(te.duration_seconds) AS secs, SUM(CASE WHEN te.billable=1 THEN te.duration_seconds ELSE 0 END) AS bill FROM time_entries te LEFT JOIN projects pr ON pr.id=te.project_id WHERE te.user_id=? AND te.started_at>=? AND te.started_at<? GROUP BY pr.name ORDER BY secs DESC LIMIT 12', [$pid, $from.' 00:00:00', date('Y-m-d', strtotime($to.' +1 day')).' 00:00:00']);
      $out['hours_by_project'] = array_map(static fn ($r) => ['project' => $r['project'], 'hours' => self::h($r['secs'])], $rows);
      $out['hours_total'] = self::h(array_sum(array_column($rows, 'secs')));
      $out['hours_billable'] = self::h(array_sum(array_column($rows, 'bill')));
      $att = DB::first("SELECT SUM(status='present') AS present, SUM(status='half_day') AS half, COALESCE(SUM(worked_seconds),0) AS worked FROM attendance WHERE user_id=? AND work_date BETWEEN ? AND ?", [$pid, $from, $to]);
      $out['attendance'] = ['days_present' => (int) $att['present'], 'half_days' => (int) $att['half'], 'hours_worked' => self::h($att['worked'])]; }
    else { $notes[] = 'hours and attendance: not available to you'; }
    if (self::leaveAccess($u, $pid) && Modules::enabled('hr') && class_exists('Modules\\Hr\\Services\\LeaveService')) { $out['leave_days'] = \Modules\Hr\Services\LeaveService::approvedLeaveDays($pid, $from, $to); }
    $self = self::self($u, $p);
    $canReports = $self ? Scope::can('projects.reports.submit', $u) : (Scope::can('projects.reports.approve', $u) && (Scope::projectIds($u) === null || People::isDirectReport($u, $pid) || (bool) DB::value('SELECT 1 FROM project_members pm JOIN projects mp ON mp.id=pm.project_id WHERE pm.user_id=? AND mp.manager_id=? LIMIT 1', [$pid, $u['id']])));
    if ($canReports && Modules::enabled('projects')) {
      $out['work_reports'] = array_map(static fn ($r) => ['type' => $r['type'], 'period' => $r['period_start'].($r['period_end'] !== $r['period_start'] ? ' to '.$r['period_end'] : ''), 'status' => $r['status'], 'hours' => $r['hours'] !== null ? (float) $r['hours'] : null, 'summary' => self::cut($r['summary'], 220), 'blockers' => self::cut($r['blockers'], 140), 'url' => '/work-reports/'.$r['id']],
        DB::all('SELECT id, type, period_start, period_end, status, hours, summary, blockers FROM work_reports WHERE user_id=? AND period_start<=? AND period_end>=? ORDER BY period_start DESC LIMIT 10', [$pid, $to, $from])); }
    else { $notes[] = 'work reports: not available to you'; }
    if ($notes) { $out['not_included'] = $notes; }
    return $out; }

  private static function t_attendance(array $u, array $a): array {
    [$p, $err] = self::who($u, $a, People::scope($u), 'attendance');
    if ($err) { return $err; }
    if (!self::timeAccess($u, (int) $p['id'])) { return self::deny('you cannot view the attendance of '.$p['name']); }
    [$from, $to] = self::period($a['from'] ?? null, $a['to'] ?? null, 'this_month');
    $to = min($to, date('Y-m-d'));
    $rows = DB::all('SELECT work_date, status, clock_in, clock_out, worked_seconds, break_seconds FROM attendance WHERE user_id=? AND work_date BETWEEN ? AND ? ORDER BY work_date DESC LIMIT 62', [$p['id'], $from, $to]);
    $sum = ['present' => 0, 'half_day' => 0, 'absent' => 0, 'leave' => 0];
    foreach ($rows as $r) { if (isset($sum[$r['status']])) { $sum[$r['status']]++; } }
    $worked = array_sum(array_column($rows, 'worked_seconds'));
    return ['person' => $p['name'], 'from' => $from, 'to' => $to, 'summary' => $sum + ['hours_worked' => self::h($worked), 'avg_hours_per_day' => $rows ? self::h($worked / max(1, $sum['present'] + $sum['half_day'] * 0.5)) : 0], 'url' => self::self($u, $p) ? '/attendance' : '/attendance/team',
      'days' => array_map(static fn ($r) => ['date' => $r['work_date'], 'status' => $r['status'], 'in' => $r['clock_in'] ? substr($r['clock_in'], 11, 5) : null, 'out' => $r['clock_out'] ? substr($r['clock_out'], 11, 5) : null, 'hours' => self::h($r['worked_seconds'])], array_slice($rows, 0, 31))]; }

  private static function t_timesheet(array $u, array $a): array {
    [$p, $err] = self::who($u, $a, People::scope($u), 'timesheets');
    if ($err) { return $err; }
    $self = self::self($u, $p);
    if (!($self ? Scope::can('attendance.track', $u) : Scope::can('attendance.timesheets.approve', $u) && People::inScope($u, (int) $p['id']))) { return self::deny('you cannot view the timesheet of '.$p['name']); }
    $wk = self::str($a, 'week', 20);
    $day = in_array($wk, ['this_week', 'last_week'], true) ? self::period($wk, null, 'this_week')[0] : (preg_match('/^\d{4}-\d{2}-\d{2}$/', $wk) ? $wk : date('Y-m-d'));
    $ws = date('Y-m-d', strtotime('-'.(date('N', strtotime($day)) - 1).' days', strtotime($day)));
    $we = date('Y-m-d', strtotime($ws.' +6 days'));
    $rows = DB::all('SELECT COALESCE(pr.name, \'No project\') AS project, t.title AS task, DATE(te.started_at) AS d, SUM(te.duration_seconds) AS secs, MAX(te.billable) AS bill FROM time_entries te LEFT JOIN projects pr ON pr.id=te.project_id LEFT JOIN tasks t ON t.id=te.task_id WHERE te.user_id=? AND te.started_at>=? AND te.started_at<? GROUP BY pr.name, t.title, d ORDER BY pr.name, t.title', [$p['id'], $ws.' 00:00:00', date('Y-m-d', strtotime($we.' +1 day')).' 00:00:00']);
    $lines = []; $days = [];
    foreach ($rows as $r) {
      $k = $r['project'].'|'.$r['task'];
      $lines[$k] ??= ['project' => $r['project'], 'task' => $r['task'] ? self::cut($r['task'], 70) : null, 'billable' => (bool) $r['bill'], 'hours' => 0.0];
      $lines[$k]['hours'] = round($lines[$k]['hours'] + $r['secs'] / 3600, 1);
      $days[$r['d']] = round(($days[$r['d']] ?? 0) + $r['secs'] / 3600, 1); }
    $ts = DB::first('SELECT id, status, review_note FROM timesheets WHERE user_id=? AND week_start=?', [$p['id'], $ws]);
    return ['person' => $p['name'], 'week_start' => $ws, 'week_end' => $we, 'total_hours' => round(array_sum(array_column($rows, 'secs')) / 3600, 1), 'status' => $ts['status'] ?? 'approved', 'review_note' => $ts && $ts['status'] === 'rejected' ? self::cut($ts['review_note'], 200) : null,
      'hours_per_day' => $days, 'lines' => array_values($lines), 'url' => $self ? '/timesheets' : '/timesheets/user/'.$p['id']]; }

  private static function t_leave_balance(array $u, array $a): array {
    [$p, $err] = self::who($u, $a, self::leaveAllIds($u), 'leave');
    if ($err) { return $err; }
    if (!self::leaveAccess($u, (int) $p['id']) || !class_exists('Modules\\Hr\\Services\\LeaveService')) { return self::deny('you cannot view the leave of '.$p['name']); }
    $year = (int) ($a['year'] ?? 0) ?: (int) date('Y');
    $year = max(2000, min(2100, $year));
    $rows = \Modules\Hr\Services\LeaveService::balances((int) $p['id'], $year);
    return ['person' => $p['name'], 'year' => $year, 'url' => '/hr/leave', 'balances' => array_map(static fn ($b) => ['type' => $b['name'], 'code' => $b['code'], 'allocated' => (float) $b['allocated'], 'used' => (float) $b['used'], 'pending' => (float) $b['pending'], 'available' => (float) $b['available'], 'paid' => (bool) $b['paid']], $rows)]; }

  private static function t_leave_requests(array $u, array $a): array {
    [$p, $err] = self::who($u, $a, self::leaveAllIds($u), 'leave');
    if ($err) { return $err; }
    if (!self::leaveAccess($u, (int) $p['id'])) { return self::deny('you cannot view the leave of '.$p['name']); }
    $year = max(2000, min(2100, (int) ($a['year'] ?? 0) ?: (int) date('Y')));
    $st = in_array($a['status'] ?? '', ['pending', 'approved', 'rejected', 'cancelled'], true) ? $a['status'] : null;
    $reasons = self::self($u, $p) || People::isHr($u) || Scope::can('hr.leave.manage', $u);
    $sql = 'SELECT lr.start_date, lr.end_date, lr.half_day, lr.days, lr.status, lr.reason, t.name AS type FROM leave_requests lr JOIN leave_types t ON t.id=lr.leave_type_id WHERE lr.user_id=? AND lr.end_date>=? AND lr.start_date<=?';
    $pp = [$p['id'], "$year-01-01", "$year-12-31"];
    if ($st) { $sql .= ' AND lr.status=?'; $pp[] = $st; }
    $rows = DB::all($sql.' ORDER BY lr.start_date DESC LIMIT 25', $pp);
    return ['person' => $p['name'], 'year' => $year, 'url' => '/hr/leave', 'requests' => array_map(static fn ($r) => ['type' => $r['type'], 'from' => $r['start_date'], 'to' => $r['end_date'], 'days' => (float) $r['days'], 'half_day' => (bool) $r['half_day'], 'status' => $r['status']] + ($reasons ? ['reason' => self::cut($r['reason'], 160)] : []), $rows)]; }

  private static function t_holidays(array $u, array $a): array {
    $year = max(2000, min(2100, (int) ($a['year'] ?? 0) ?: (int) date('Y')));
    $rows = DB::all('SELECT holiday_date, name, optional FROM holidays WHERE holiday_date BETWEEN ? AND ? ORDER BY holiday_date', ["$year-01-01", "$year-12-31"]);
    $today = date('Y-m-d');
    return ['year' => $year, 'today' => $today, 'url' => '/hr/holidays', 'fixed_count' => count(array_filter($rows, static fn ($r) => !$r['optional'])), 'optional_count' => count(array_filter($rows, static fn ($r) => $r['optional'])),
      'holidays' => array_map(static fn ($r) => ['date' => $r['holiday_date'], 'day' => date('D', strtotime($r['holiday_date'])), 'name' => $r['name'], 'kind' => $r['optional'] ? 'optional' : 'fixed', 'past' => $r['holiday_date'] < $today], $rows)]; }

  private static function t_team_on_leave(array $u, array $a): array {
    $d = self::str($a, 'date', 20);
    $date = $d === 'tomorrow' ? date('Y-m-d', strtotime('+1 day')) : (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : date('Y-m-d'));
    if (!class_exists('Modules\\Hr\\Services\\LeaveService')) { return ['error' => 'Leave is not available.']; }
    $rows = \Modules\Hr\Services\LeaveService::onLeaveOn($date, 100);
    $hr = People::isHr($u) || Scope::can('hr.leave.manage', $u);
    return ['date' => $date, 'count' => count($rows), 'people' => array_map(static fn ($r) => ['name' => $r['name'], 'designation' => $r['designation'], 'half_day' => (bool) $r['half_day'], 'until' => $r['end_date']] + ($hr ? ['type' => $r['type_name']] : []), $rows)]; }

  private static function t_find_people(array $u, array $a): array {
    $q = self::str($a, 'query', 80);
    $full = People::isHr($u) && Scope::can('hr.employees.view', $u);
    $sql = 'SELECT u.id, u.name, u.email, u.designation, u.department, u.status, u.employee_code, u.joined_on, m.name AS manager FROM users u LEFT JOIN users m ON m.id=u.manager_id WHERE '.($full ? "u.status<>'invited'" : "u.status='active'");
    $p = [];
    foreach (array_slice(array_filter(preg_split('/\s+/', $q) ?: []), 0, 4) as $w) { $sql .= ' AND (u.name LIKE ? OR u.designation LIKE ? OR u.department LIKE ? OR u.email LIKE ?)'; $l = DB::like($w); array_push($p, $l, $l, $l, $l); }
    $rows = DB::all($sql.' ORDER BY u.name LIMIT 15', $p);
    return ['query' => $q, 'count' => count($rows), 'people' => array_map(static fn ($r) => ['name' => $r['name'], 'designation' => $r['designation'], 'department' => $r['department'], 'manager' => $r['manager'], 'email' => $r['email']] + ($full ? ['employee_code' => $r['employee_code'], 'status' => $r['status'], 'joined_on' => $r['joined_on'], 'url' => '/hr/employees/'.$r['id']] : []), $rows)]; }

  private static function t_projects_overview(array $u, array $a): array {
    $st = (string) ($a['status'] ?? 'open');
    $p = [];
    $sql = 'SELECT p.id, p.name, p.code, p.status, p.priority, p.progress, p.deadline, c.name AS client, m.name AS manager, (SELECT COUNT(*) FROM tasks t WHERE t.project_id=p.id AND t.status<>\'done\') AS open_tasks, (SELECT COUNT(*) FROM tasks t WHERE t.project_id=p.id AND t.status<>\'done\' AND t.due_date<CURDATE()) AS overdue_tasks FROM projects p LEFT JOIN clients c ON c.id=p.client_id LEFT JOIN users m ON m.id=p.manager_id WHERE 1=1'.Scope::where(Scope::projectIds($u), 'p.id', $p);
    if (in_array($st, ['planning', 'active', 'on_hold', 'completed', 'cancelled'], true)) { $sql .= ' AND p.status=?'; $p[] = $st; }
    elseif ($st !== 'all') { $sql .= " AND p.status NOT IN ('completed','cancelled')"; }
    $rows = DB::all($sql.' ORDER BY p.deadline IS NULL, p.deadline, p.name LIMIT 30', $p);
    $today = date('Y-m-d');
    return ['status_filter' => $st, 'today' => $today, 'count' => count($rows), 'projects' => array_map(static fn ($r) => ['name' => $r['name'], 'client' => $r['client'] ?: 'Internal', 'status' => $r['status'], 'progress' => (int) $r['progress'].'%', 'deadline' => $r['deadline'], 'late' => $r['deadline'] && $r['deadline'] < $today && !in_array($r['status'], ['completed', 'cancelled'], true), 'manager' => $r['manager'], 'open_tasks' => (int) $r['open_tasks'], 'overdue_tasks' => (int) $r['overdue_tasks'], 'url' => '/projects/'.$r['id']], $rows)]; }

  /** Find one visible record by id / exact / partial name. Returns [row|null, error|null]. */
  private static function pick(string $q, string $sql, array $p, string $what): array {
    $q = trim($q);
    if ($q === '') { return [null, ['error' => 'Say which '.$what.'.']]; }
    $rows = ctype_digit($q) ? DB::all($sql.' AND x.id=? LIMIT 2', [...$p, (int) $q]) : [];
    if (!$rows) { $rows = DB::all($sql.' AND (x.name=? OR x.'.($what === 'project' ? 'code' : 'company').'=?) LIMIT 2', [...$p, $q, $q]); }
    if (!$rows) { $l = DB::like(preg_replace('/\s+(project|client)$/i', '', $q) ?? $q); $rows = DB::all($sql.' AND (x.name LIKE ? OR x.'.($what === 'project' ? 'code' : 'company').' LIKE ?) ORDER BY x.name LIMIT 6', [...$p, "%$l%", "%$l%"]); }
    if (!$rows) { return [null, ['error' => 'No '.$what.' matching "'.mb_substr($q, 0, 60).'" among the '.$what.'s you can see.']]; }
    if (count($rows) > 1) { return [null, ['error' => 'More than one '.$what.' matches. Ask which one.', 'candidates' => array_map(static fn ($r) => $r['name'], $rows)]]; }
    return [$rows[0], null]; }

  private static function t_project_status(array $u, array $a): array {
    $p = [];
    [$pr, $err] = self::pick(self::str($a, 'project', 120), 'SELECT x.id, x.name, x.code, x.status, x.priority, x.start_date, x.deadline, x.progress, x.estimated_hours, x.description, c.name AS client, m.name AS manager FROM projects x LEFT JOIN clients c ON c.id=x.client_id LEFT JOIN users m ON m.id=x.manager_id WHERE 1=1'.Scope::where(Scope::projectIds($u), 'x.id', $p), $p, 'project');
    if ($err) { return $err; }
    $id = (int) $pr['id']; $today = date('Y-m-d');
    $out = ['project' => ['name' => $pr['name'], 'code' => $pr['code'], 'client' => $pr['client'] ?: 'Internal', 'manager' => $pr['manager'], 'status' => $pr['status'], 'priority' => $pr['priority'], 'start' => $pr['start_date'], 'deadline' => $pr['deadline'], 'deadline_passed' => $pr['deadline'] && $pr['deadline'] < $today && !in_array($pr['status'], ['completed', 'cancelled'], true), 'progress' => (int) $pr['progress'].'%', 'description' => self::cut($pr['description'], 240), 'url' => '/projects/'.$id], 'today' => $today];
    $out['tasks_by_status'] = array_map('intval', DB::pairs('SELECT status, COUNT(*) FROM tasks WHERE project_id=? AND parent_id IS NULL GROUP BY status', [$id]));
    $out['overdue_tasks'] = array_map(static fn ($t) => ['title' => self::cut($t['title'], 80), 'assignee' => $t['assignee'] ?: 'Unassigned', 'due' => $t['due_date'], 'status' => $t['status'], 'url' => '/tasks/'.$t['id']], DB::all("SELECT t.id, t.title, t.due_date, t.status, u.name AS assignee FROM tasks t LEFT JOIN users u ON u.id=t.assignee_id WHERE t.project_id=? AND t.status<>'done' AND t.due_date<? ORDER BY t.due_date LIMIT 10", [$id, $today]));
    $out['milestones'] = array_map(static fn ($m) => ['title' => self::cut($m['title'], 80), 'due' => $m['due_date'], 'status' => $m['status'], 'late' => $m['due_date'] && $m['due_date'] < $today && $m['status'] !== 'completed'], DB::all('SELECT title, due_date, status FROM milestones WHERE project_id=? ORDER BY position, due_date LIMIT 12', [$id]));
    $out['team'] = DB::column('SELECT u.name FROM project_members pm JOIN users u ON u.id=pm.user_id WHERE pm.project_id=? ORDER BY u.name LIMIT 20', [$id]);
    try { $h = DB::first('SELECT COALESCE(SUM(duration_seconds),0) AS total, COALESCE(SUM(CASE WHEN started_at>=? THEN duration_seconds END),0) AS week FROM time_entries WHERE project_id=?', [date('Y-m-d', strtotime('-7 days')), $id]); $out['hours'] = ['total' => self::h($h['total']), 'last_7_days' => self::h($h['week']), 'estimated' => $pr['estimated_hours'] !== null ? (float) $pr['estimated_hours'] : null]; } catch (\Throwable) {}
    try { $out['pending_change_requests'] = (int) DB::value("SELECT COUNT(*) FROM change_requests WHERE project_id=? AND status='pending'", [$id]); } catch (\Throwable) {}
    return $out; }

  private static function clientWhere(array $u, string $col, array &$p): string {
    if (!class_exists('Modules\\Crm\\Services\\ClientAccess')) { return Scope::where(Scope::clientIds($u), $col, $p); }
    [$w, $pp] = \Modules\Crm\Services\ClientAccess::where($u, $col);
    array_push($p, ...$pp);
    return ' AND '.$w; }

  private static function t_clients_overview(array $u, array $a): array {
    $p = [];
    $fin = Scope::can('crm.finance.view', $u);
    $sql = 'SELECT c.id, c.name, c.company, c.status, c.industry, c.city'.($fin ? ", (SELECT COALESCE(SUM(i.total-i.amount_paid),0) FROM invoices i WHERE i.client_id=c.id AND i.status IN ('sent','partial','overdue')) AS outstanding" : '').' FROM clients c WHERE 1=1'.self::clientWhere($u, 'c.id', $p);
    $st = (string) ($a['status'] ?? 'all');
    if (in_array($st, ['lead', 'active', 'on_hold', 'inactive'], true)) { $sql .= ' AND c.status=?'; $p[] = $st; }
    $rows = DB::all($sql." ORDER BY c.status='active' DESC, c.name LIMIT 30", $p);
    return ['count' => count($rows), 'clients' => array_map(static fn ($r) => ['name' => $r['company'] ?: $r['name'], 'contact' => $r['company'] ? $r['name'] : null, 'status' => $r['status'], 'industry' => $r['industry'], 'city' => $r['city']] + ($fin ? ['outstanding' => self::money($r['outstanding'])] : []) + ['url' => '/clients/'.$r['id']], $rows)]; }

  private static function t_client_summary(array $u, array $a): array {
    $p = [];
    [$c, $err] = self::pick(self::str($a, 'client', 120), 'SELECT x.id, x.name, x.company, x.status, x.industry, x.city, x.country, x.source, x.created_at FROM clients x WHERE 1=1'.self::clientWhere($u, 'x.id', $p), $p, 'client');
    if ($err) { return $err; }
    $id = (int) $c['id'];
    $out = ['client' => ['name' => $c['company'] ?: $c['name'], 'contact' => $c['company'] ? $c['name'] : null, 'status' => $c['status'], 'industry' => $c['industry'], 'location' => trim(($c['city'] ?? '').', '.($c['country'] ?? ''), ', '), 'source' => $c['source'], 'since' => substr((string) $c['created_at'], 0, 10), 'url' => '/clients/'.$id]];
    $out['recent_activity'] = array_map(static fn ($r) => ['date' => substr($r['occurred_at'], 0, 10), 'type' => $r['type'], 'title' => self::cut($r['title'], 90), 'follow_up' => $r['follow_up_at'] && !$r['follow_up_done'] ? substr($r['follow_up_at'], 0, 10) : null], DB::all('SELECT type, title, occurred_at, follow_up_at, follow_up_done FROM client_activities WHERE client_id=? ORDER BY occurred_at DESC LIMIT 8', [$id]));
    if (Scope::can('projects.view', $u)) { $pp = [$id]; $out['projects'] = array_map(static fn ($r) => ['name' => $r['name'], 'status' => $r['status'], 'progress' => (int) $r['progress'].'%', 'deadline' => $r['deadline'], 'url' => '/projects/'.$r['id']], DB::all('SELECT id, name, status, progress, deadline FROM projects WHERE client_id=?'.Scope::where(Scope::projectIds($u), 'id', $pp).' ORDER BY created_at DESC LIMIT 8', $pp)); }
    if (Scope::can('crm.finance.view', $u)) {
      $inv = DB::first("SELECT COUNT(*) AS n, COALESCE(SUM(total-amount_paid),0) AS due, SUM(status='overdue' OR (due_date<CURDATE())) AS overdue FROM invoices WHERE client_id=? AND status IN ('sent','partial','overdue')", [$id]);
      $out['open_invoices'] = ['count' => (int) $inv['n'], 'outstanding' => self::money($inv['due']), 'overdue' => (int) $inv['overdue']]; }
    if (Scope::can('crm.quotations.view', $u)) { $out['quotations_by_status'] = array_map('intval', DB::pairs('SELECT status, COUNT(*) FROM quotations WHERE client_id=? GROUP BY status', [$id])); }
    return $out; }

  private static function t_invoices(array $u, array $a): array {
    $p = [];
    $sql = 'SELECT i.id, i.number, i.title, i.issue_date, i.due_date, i.status, i.currency, i.total, i.amount_paid, COALESCE(NULLIF(c.company,\'\'), c.name) AS client FROM invoices i JOIN clients c ON c.id=i.client_id WHERE 1=1'.self::clientWhere($u, 'i.client_id', $p);
    $st = (string) ($a['status'] ?? 'all');
    if ($st === 'unpaid') { $sql .= " AND i.status IN ('sent','partial','overdue')"; }
    elseif (in_array($st, ['draft', 'sent', 'partial', 'paid', 'overdue', 'cancelled'], true)) { $sql .= ' AND i.status=?'; $p[] = $st; }
    if (($cl = self::str($a, 'client', 100)) !== '') { $sql .= ' AND (c.name LIKE ? OR c.company LIKE ?)'; $l = '%'.DB::like($cl).'%'; array_push($p, $l, $l); }
    $rows = DB::all($sql.' ORDER BY i.issue_date DESC, i.id DESC LIMIT 30', $p);
    $open = array_filter($rows, static fn ($r) => in_array($r['status'], ['sent', 'partial', 'overdue'], true));
    return ['status_filter' => $st, 'count' => count($rows), 'outstanding_in_list' => self::money(array_sum(array_map(static fn ($r) => $r['total'] - $r['amount_paid'], $open))), 'invoices' => array_map(static fn ($r) => ['number' => $r['number'], 'client' => $r['client'], 'issued' => $r['issue_date'], 'due' => $r['due_date'], 'status' => $r['status'], 'total' => $r['currency'].' '.self::money($r['total']), 'balance' => $r['currency'].' '.self::money($r['total'] - $r['amount_paid']), 'url' => '/invoices/'.$r['id']], $rows)]; }

  private static function t_overdue_invoices(array $u, array $a): array {
    $p = [date('Y-m-d')];
    $rows = DB::all("SELECT i.id, i.number, i.due_date, i.currency, i.total, i.amount_paid, i.last_reminder_at, COALESCE(NULLIF(c.company,''), c.name) AS client FROM invoices i JOIN clients c ON c.id=i.client_id WHERE (i.status='overdue' OR (i.status IN ('sent','partial') AND i.due_date<?))".self::clientWhere($u, 'i.client_id', $p).' ORDER BY i.due_date LIMIT 30', $p);
    $by = [];
    foreach ($rows as $r) { $by[$r['currency']] = ($by[$r['currency']] ?? 0) + $r['total'] - $r['amount_paid']; }
    return ['today' => date('Y-m-d'), 'count' => count($rows), 'total_outstanding' => array_map(static fn ($v) => self::money($v), $by), 'invoices' => array_map(static fn ($r) => ['number' => $r['number'], 'client' => $r['client'], 'due' => $r['due_date'], 'days_overdue' => $r['due_date'] ? (int) floor((strtotime(date('Y-m-d')) - strtotime($r['due_date'])) / 86400) : null, 'balance' => $r['currency'].' '.self::money($r['total'] - $r['amount_paid']), 'last_reminder' => $r['last_reminder_at'] ? substr($r['last_reminder_at'], 0, 10) : null, 'url' => '/invoices/'.$r['id']], $rows)]; }

  private static function t_quotations(array $u, array $a): array {
    $p = [];
    $sql = 'SELECT q.id, q.number, q.title, q.issue_date, q.valid_until, q.status, q.currency, q.total, COALESCE(NULLIF(c.company,\'\'), c.name) AS client FROM quotations q JOIN clients c ON c.id=q.client_id WHERE 1=1'.self::clientWhere($u, 'q.client_id', $p);
    $st = (string) ($a['status'] ?? 'all');
    if (in_array($st, ['draft', 'sent', 'accepted', 'rejected', 'expired'], true)) { $sql .= ' AND q.status=?'; $p[] = $st; }
    if (($cl = self::str($a, 'client', 100)) !== '') { $sql .= ' AND (c.name LIKE ? OR c.company LIKE ?)'; $l = '%'.DB::like($cl).'%'; array_push($p, $l, $l); }
    $rows = DB::all($sql.' ORDER BY q.issue_date DESC, q.id DESC LIMIT 30', $p);
    return ['status_filter' => $st, 'count' => count($rows), 'quotations' => array_map(static fn ($r) => ['number' => $r['number'], 'title' => self::cut($r['title'], 70), 'client' => $r['client'], 'issued' => $r['issue_date'], 'valid_until' => $r['valid_until'], 'status' => $r['status'], 'total' => $r['currency'].' '.self::money($r['total']), 'url' => '/quotations/'.$r['id']], $rows)]; }

  private static function t_payslips(array $u, array $a): array {
    $manage = Scope::can('payroll.manage', $u);
    [$p, $err] = self::who($u, $a, $manage ? null : [(int) $u['id']], 'payslips');
    if ($err) { return $err; }
    if (!self::self($u, $p) && !$manage) { return self::deny('payslips are private to each employee'); }
    if (self::self($u, $p) && !Scope::can('payroll.view_own', $u) && !$manage) { return self::deny('payslips are not available to you'); }
    $rows = DB::all("SELECT s.id, s.net, s.gross, s.total_deductions, s.paid_days, s.working_days, r.period_month, r.status FROM payslips s JOIN payroll_runs r ON r.id=s.run_id WHERE s.user_id=? AND r.status IN ('finalized','paid') ORDER BY r.period_month DESC LIMIT 12", [$p['id']]);
    return ['person' => $p['name'], 'count' => count($rows), 'payslips' => array_map(static fn ($r) => ['month' => date('F Y', strtotime($r['period_month'])), 'net' => self::money($r['net']), 'gross' => self::money($r['gross']), 'deductions' => self::money($r['total_deductions']), 'paid_days' => (float) $r['paid_days'].' of '.(float) $r['working_days'], 'status' => $r['status'], 'url' => '/payslips/'.$r['id']], $rows)]; }

  private static function t_company_documents(array $u, array $a): array {
    $q = self::str($a, 'query', 80);
    $sql = 'SELECT d.id, d.title, d.category, d.description, d.requires_ack, d.updated_at, d.created_at, (SELECT 1 FROM company_document_acks k WHERE k.document_id=d.id AND k.user_id=?) AS acked FROM company_documents d WHERE 1=1';
    $p = [$u['id']];
    if ($q !== '') { $sql .= ' AND (d.title LIKE ? OR d.description LIKE ? OR d.category LIKE ?)'; $l = '%'.DB::like($q).'%'; array_push($p, $l, $l, $l); }
    $rows = DB::all($sql.' ORDER BY d.title LIMIT 20', $p);
    return ['count' => count($rows), 'url' => '/hr/documents', 'documents' => array_map(static fn ($r) => ['title' => $r['title'], 'category' => $r['category'], 'about' => self::cut($r['description'], 200), 'needs_acknowledgement' => (bool) $r['requires_ack'], 'acknowledged_by_you' => (bool) $r['acked'], 'updated' => substr((string) ($r['updated_at'] ?: $r['created_at']), 0, 10)], $rows)]; } }
