<?php
namespace Modules\Projects\Services;
use Core\{DB, Notify, Settings};

/** Shared project/task logic: lookups, progress, actual hours, reminders and search. */
final class ProjectService {
  public const STATUSES = ['planning' => 'Planning', 'active' => 'Active', 'on_hold' => 'On hold', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];
  public const PRIORITIES = ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'urgent' => 'Urgent'];
  public const TASK_STATUSES = ['todo' => 'To do', 'in_progress' => 'In progress', 'review' => 'In review', 'done' => 'Done'];
  public const TASK_COLORS = ['todo' => 'gray', 'in_progress' => 'sky', 'review' => 'lav', 'done' => 'mint'];
  public const BILLING = ['fixed' => 'Fixed price', 'hourly' => 'Hourly', 'retainer' => 'Retainer', 'non_billable' => 'Non-billable'];
  public const COLORS = ['lav', 'mint', 'lemon', 'peach', 'sky', 'pink'];

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
    [$w, $p] = ProjectAccess::scope($user, 'p.id');
    $like = DB::like($q);
    $projects = DB::all("SELECT p.id, p.name, p.code, p.status, c.name AS client FROM projects p LEFT JOIN clients c ON c.id=p.client_id WHERE $w AND (p.name LIKE ? OR p.code LIKE ?) ORDER BY p.updated_at DESC LIMIT 8", [...$p, $like, $like]);
    $tasks = DB::all("SELECT t.id, t.title, t.status, t.due_date, p.name AS project FROM tasks t JOIN projects p ON p.id=t.project_id WHERE $w AND t.title LIKE ? ORDER BY t.status='done', t.due_date IS NULL, t.due_date LIMIT 8", [...$p, $like]);
    return [
      'Projects' => array_map(static fn ($r) => ['title' => $r['name'], 'meta' => trim(($r['code'] ? $r['code'].' · ' : '').(self::STATUSES[$r['status']] ?? '').($r['client'] ? ' · '.$r['client'] : '')), 'url' => '/projects/'.$r['id'], 'icon' => 'folder'], $projects),
      'Tasks' => array_map(static fn ($r) => ['title' => $r['title'], 'meta' => $r['project'].' · '.self::TASK_STATUSES[$r['status']].($r['due_date'] ? ' · due '.fdate($r['due_date']) : ''), 'url' => '/tasks/'.$r['id'], 'icon' => 'check-square'], $tasks)]; }

  /** Hidden "return" field holding the current local path + query (used instead of the Referer header). */
  public static function returnField(): string {
    $q = (string) ($_SERVER['QUERY_STRING'] ?? '');
    return '<input type="hidden" name="return" value="'.e(\Core\Request::current()->path().($q !== '' ? '?'.$q : '')).'">'; }

  /** A safe local return path from the request, or the fallback. */
  public static function returnPath(\Core\Request $r, string $fallback): string {
    $p = $r->str('return');
    return preg_match('#^/(?![/\\\\])[^\s]*$#', $p) && !str_contains($p, '://') ? $p : $fallback; }

  /** Due-date bucket for grouping. */
  public static function bucket(?string $due, string $status = 'todo'): string {
    if (!$due) { return 'later'; }
    $today = date('Y-m-d');
    if ($due < $today && $status !== 'done') { return 'overdue'; }
    if ($due === $today) { return 'today'; }
    return $due <= date('Y-m-d', strtotime('sunday this week')) && $due > $today ? 'week' : ($due < $today ? 'today' : 'later'); }

  /** Due date chip HTML (red when overdue). */
  public static function dueChip(?string $due, string $status = 'todo'): string {
    if (!$due) { return '<span class="xs muted">No due date</span>'; }
    $late = $status !== 'done' && $due < date('Y-m-d');
    $label = $due === date('Y-m-d') ? 'Today' : ($due === date('Y-m-d', strtotime('+1 day')) ? 'Tomorrow' : fdate($due, 'd M'));
    return '<span class="due'.($late ? ' late' : '').'">'.icon('calendar', 'sm').' '.e($label).'</span>'; } }
