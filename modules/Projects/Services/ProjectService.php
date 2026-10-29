<?php
namespace Modules\Projects\Services;
use Core\{DB, Features, Modules, Notify, Settings};

/** Shared project/task logic: lookups, progress, actual hours, reminders and search. */
final class ProjectService {
  public const STATUSES = ['planning' => 'Planning', 'active' => 'Active', 'on_hold' => 'On hold', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];
  public const PRIORITIES = ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'urgent' => 'Urgent'];
  public const TASK_STATUSES = ['todo' => 'To do', 'in_progress' => 'In progress', 'review' => 'In review', 'done' => 'Done'];
  public const TASK_COLORS = ['todo' => 'gray', 'in_progress' => 'sky', 'review' => 'lav', 'done' => 'mint'];
  public const BILLING = ['fixed' => 'Fixed price', 'hourly' => 'Hourly', 'retainer' => 'Retainer', 'non_billable' => 'Non-billable'];
  public const COLORS = ['lav', 'mint', 'lemon', 'peach', 'sky', 'pink'];
  public const PRIO_COLORS = ['low' => 'mint', 'medium' => 'lemon', 'high' => 'peach', 'urgent' => 'red'];
  private static array $on = [];

  /** Optional integrations: work reports feature, attendance (time entries) and CRM client pages. */
  public static function on(string $what): bool {
    return self::$on[$what] ??= match ($what) {
      'reports' => Features::enabled('work_reports') && (can('projects.reports.submit') || can('projects.reports.approve')),
      'report_submit' => Features::enabled('work_reports') && can('projects.reports.submit'),
      'time' => feature_on('attendance'),
      'crm' => feature_on('clients') && can('crm.clients.view'),
      default => false }; }

  /** Client name, linked to the client page only when Client Management is on and the user may open it. */
  public static function client(?int $id, ?string $name, string $fallback = 'Internal project'): string {
    if (!$id || !$name) { return '<span class="muted">'.e($fallback).'</span>'; }
    return self::on('crm') ? '<a class="client-link" href="'.e(url('/clients/'.$id)).'">'.e($name).'</a>' : '<span>'.e($name).'</span>'; }

  /** Deadline countdown: [label, tone] where tone is red / lemon / mint / gray. */
  public static function countdown(?string $deadline, string $status = 'active'): array {
    if ($status === 'completed') { return ['Completed', 'mint']; }
    if ($status === 'cancelled') { return ['Cancelled', 'gray']; }
    if (!$deadline) { return ['No deadline', 'gray']; }
    $d = (int) round((strtotime($deadline) - strtotime(date('Y-m-d'))) / 86400);
    return match (true) { $d < 0 => [abs($d).' day'.($d < -1 ? 's' : '').' overdue', 'red'], $d === 0 => ['Due today', 'lemon'], $d === 1 => ['Due tomorrow', 'lemon'], $d <= 7 => [$d.' days left', 'lemon'], default => [$d.' days left', 'gray'] }; }

  /** Overlapping avatar stack with a "+n" bubble (people: rows with name, avatar). */
  public static function stack(array $people, int $max = 4, string $none = ''): string {
    if (!$people) { return $none !== '' ? '<span class="xs muted">'.e($none).'</span>' : ''; }
    $h = '<span class="avatars" title="'.e(implode(', ', array_column($people, 'name'))).'">';
    foreach (array_slice($people, 0, $max) as $m) { $h .= avatar($m['name'], $m['avatar'] ?? null, 'sm'); }
    return $h.(count($people) > $max ? '<span class="avatar sm more">+'.(count($people) - $max).'</span>' : '').'</span>'; }

  /** Logged seconds per project for many projects at once: [project_id => ['total','billable']]. */
  public static function projectsSeconds(array $ids): array {
    if (!$ids || !self::on('time')) { return []; }
    $ids = array_values(array_map('intval', $ids)); $out = [];
    foreach (DB::all("SELECT COALESCE(te.project_id, t.project_id) AS pid, SUM(te.duration_seconds) AS total, SUM(CASE WHEN te.billable=1 THEN te.duration_seconds ELSE 0 END) AS billable FROM time_entries te LEFT JOIN tasks t ON t.id=te.task_id WHERE te.status<>'rejected' AND (te.project_id IN ".DB::in($ids).' OR t.project_id IN '.DB::in($ids).') GROUP BY pid', [...$ids, ...$ids]) as $r) { $out[(int) $r['pid']] = ['total' => (int) $r['total'], 'billable' => (int) $r['billable']]; }
    return $out; }

  /** Progress = done top-level tasks / all top-level tasks. */
  public static function recalcProgress(int $projectId): int {
    DB::query("UPDATE projects SET progress=(SELECT IFNULL(ROUND(100*SUM(status='done')/COUNT(*)),0) FROM tasks WHERE project_id=? AND parent_id IS NULL) WHERE id=?", [$projectId, $projectId]);
    return (int) DB::value('SELECT progress FROM projects WHERE id=?', [$projectId]); }

  /** Logged time (non-rejected) for a project: ['total' => sec, 'billable' => sec]. */
  public static function projectSeconds(int $projectId): array {
    $r = DB::first("SELECT IFNULL(SUM(duration_seconds),0) AS total, IFNULL(SUM(CASE WHEN billable=1 THEN duration_seconds ELSE 0 END),0) AS billable FROM time_entries WHERE status<>'rejected' AND (project_id=? OR task_id IN (SELECT id FROM tasks WHERE project_id=?))", [$projectId, $projectId]);
    return ['total' => (int) $r['total'], 'billable' => (int) $r['billable']]; }

  /** Logged time per task id (one query): [task_id => ['total','billable']]. */
  public static function taskSeconds(array $taskIds): array {
    if (!$taskIds) { return []; }
    $out = [];
    foreach (DB::all("SELECT task_id, SUM(duration_seconds) AS total, SUM(CASE WHEN billable=1 THEN duration_seconds ELSE 0 END) AS billable FROM time_entries WHERE status<>'rejected' AND task_id IN ".DB::in($taskIds).' GROUP BY task_id', array_values($taskIds)) as $r) { $out[(int) $r['task_id']] = ['total' => (int) $r['total'], 'billable' => (int) $r['billable']]; }
    return $out; }

  /** Seconds a user logged between two dates (inclusive). */
  public static function userSeconds(int $uid, string $from, string $to): int {
    return (int) DB::value("SELECT IFNULL(SUM(duration_seconds),0) FROM time_entries WHERE user_id=? AND status<>'rejected' AND started_at>=? AND started_at<?", [$uid, $from.' 00:00:00', date('Y-m-d', strtotime($to.' +1 day')).' 00:00:00']); }

  /** Active users for pickers: id => name. */
  public static function users(): array { return DB::pairs("SELECT id, name FROM users WHERE status='active' ORDER BY name"); }

  /** Members + manager of a project: id => name. */
  public static function team(int $projectId): array {
    return DB::pairs('SELECT u.id, u.name FROM users u WHERE u.status=\'active\' AND (u.id IN (SELECT user_id FROM project_members WHERE project_id=?) OR u.id=(SELECT manager_id FROM projects WHERE id=?)) ORDER BY u.name', [$projectId, $projectId]); }

  public static function notifyAssignee(array $task, int $actorId): void {
    $a = (int) ($task['assignee_id'] ?? 0);
    if (!$a || $a === $actorId) { return; }
    $p = DB::value('SELECT name FROM projects WHERE id=?', [(int) $task['project_id']]);
    Notify::user($a, 'New task assigned: '.$task['title'], 'Project: '.$p.(!empty($task['due_date']) ? ' · Due '.fdate($task['due_date']) : ''), '/tasks/'.$task['id'], true, 'check-square'); }

  /** Daily cron: remind assignees about tasks due tomorrow and overdue tasks (once per day). */
  public static function deadlineReminders(): string {
    $today = date('Y-m-d');
    if (Settings::get('projects.reminders_sent_on') === $today) { return 'already sent today'; }
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    $rows = DB::all("SELECT t.id, t.title, t.due_date, t.assignee_id, p.name AS project FROM tasks t JOIN projects p ON p.id=t.project_id JOIN users u ON u.id=t.assignee_id AND u.status='active' WHERE t.status<>'done' AND t.due_date IS NOT NULL AND t.due_date<=? AND p.status NOT IN ('completed','cancelled') ORDER BY t.due_date", [$tomorrow]);
    $by = [];
    foreach ($rows as $r) { $by[(int) $r['assignee_id']][$r['due_date'] === $tomorrow ? 'soon' : ($r['due_date'] < $today ? 'late' : 'today')][] = $r; }
    foreach ($by as $uid => $g) {
      $all = array_merge($g['late'] ?? [], $g['today'] ?? [], $g['soon'] ?? []);
      if (count($all) === 1) {
        $t = $all[0];
        $when = $t['due_date'] === $tomorrow ? 'is due tomorrow' : ($t['due_date'] < $today ? 'is overdue (was due '.fdate($t['due_date']).')' : 'is due today');
        Notify::user($uid, 'Task '.$when.': '.$t['title'], 'Project: '.$t['project'], '/tasks/'.$t['id'], true, 'clock');
        continue; }
      $parts = array_filter([count($g['late'] ?? []) ? count($g['late']).' overdue' : '', count($g['today'] ?? []) ? count($g['today']).' due today' : '', count($g['soon'] ?? []) ? count($g['soon']).' due tomorrow' : '']);
      Notify::user($uid, 'Task reminder: '.implode(', ', $parts), implode("\n", array_map(static fn ($t) => '• '.$t['title'].' ('.$t['project'].', '.fdate($t['due_date']).')', array_slice($all, 0, 8))), '/tasks', true, 'clock'); }
    Settings::set('projects.reminders_sent_on', $today);
    return 'reminded '.count($by).' users about '.count($rows).' tasks'; }

  /** Global search: projects by name/code and tasks by title, limited to visible projects. */
  public static function search(string $q, array $user): array {
    if (!feature_on('projects')) { return []; }
    [$w, $p] = ProjectAccess::scope($user, 'p.id');
    $like = DB::like($q);
    $projects = DB::all("SELECT p.id, p.name, p.code, p.status, c.name AS client FROM projects p LEFT JOIN clients c ON c.id=p.client_id WHERE $w AND (p.name LIKE ? OR p.code LIKE ?) ORDER BY p.updated_at DESC LIMIT 8", [...$p, $like, $like]);
    $tasks = DB::all("SELECT t.id, t.title, t.status, t.due_date, p.name AS project FROM tasks t JOIN projects p ON p.id=t.project_id WHERE $w AND t.title LIKE ? ORDER BY t.status='done', t.due_date IS NULL, t.due_date LIMIT 8", [...$p, $like]);
    return [
      'Projects' => array_map(static fn ($r) => ['title' => $r['name'], 'meta' => trim(($r['code'] ? $r['code'].' · ' : '').(self::STATUSES[$r['status']] ?? '').($r['client'] && feature_on('clients') ? ' · '.$r['client'] : '')), 'url' => '/projects/'.$r['id'], 'icon' => 'folder'], $projects),
      'Tasks' => array_map(static fn ($r) => ['title' => $r['title'], 'meta' => $r['project'].' · '.self::TASK_STATUSES[$r['status']].($r['due_date'] ? ' · due '.fdate($r['due_date']) : ''), 'url' => '/tasks/'.$r['id'], 'icon' => 'check-square'], $tasks)]; }

  /** Hidden "return" field holding the current local path + query (used instead of the Referer header). */
  public static function returnField(): string {
    $q = (string) ($_SERVER['QUERY_STRING'] ?? '');
    return '<input type="hidden" name="return" value="'.e(\Core\Request::current()->path().($q !== '' ? '?'.$q : '')).'">'; }

  /** A safe local return path from the request, or the fallback. */
  public static function returnPath(\Core\Request $r, string $fallback): string {
    $p = $r->str('return');
    return preg_match('#^/(?![/\\\\])[^\s]*$#', $p) && !str_contains($p, '://') ? $p : $fallback; }

  /** Due-date bucket for grouping: overdue, today, week (rest of this week), later, none. */
  public static function bucket(?string $due, string $status = 'todo'): string {
    if (!$due) { return 'none'; }
    $today = date('Y-m-d');
    if ($due < $today) { return $status === 'done' ? 'today' : 'overdue'; }
    if ($due === $today) { return 'today'; }
    return $due <= date('Y-m-d', strtotime('sunday this week')) ? 'week' : 'later'; }

  /** Due date chip HTML (red when overdue). */
  public static function dueChip(?string $due, string $status = 'todo', bool $empty = true): string {
    if (!$due) { return $empty ? '<span class="due none">'.icon('calendar', 'sm').' No date</span>' : ''; }
    $today = date('Y-m-d');
    $tone = $status === 'done' ? 'done' : ($due < $today ? 'late' : ($due === $today ? 'today' : ($due === date('Y-m-d', strtotime('+1 day')) ? 'soon' : '')));
    $label = $due === $today ? 'Today' : ($due === date('Y-m-d', strtotime('+1 day')) ? 'Tomorrow' : ($due === date('Y-m-d', strtotime('-1 day')) ? 'Yesterday' : fdate($due, substr($due, 0, 4) === date('Y') ? 'd M' : 'd M Y')));
    return '<span class="due '.$tone.'" title="Due '.e(fdate($due)).'">'.icon($tone === 'late' ? 'alert' : 'calendar', 'sm').' '.e($label).'</span>'; } }
