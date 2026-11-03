<?php
namespace Modules\Todos\Services;
use Core\{DB, Gate};
use Modules\Projects\Services\{ProjectAccess as PA, ProjectService as PS};

/**
 * The one "New task" dialog (views/partials/task_dialog.php) used on /todos, /tasks and project pages.
 * "No project" creates a to-do or routine (POST /todos); a chosen project creates a project task (POST /projects/{id}/tasks).
 */
final class TaskDialog {
  /** May the user create a to-do / routine (Tasks & routines feature on)? */
  public static function todos(array $u): bool { return feature_on('todos') && Todos::canCreate($u); }

  /** Projects the user may add tasks to (PA::canManageTasks): [id => name]. Finished projects only when $keep. */
  public static function projects(array $u, ?int $keep = null): array {
    if (!feature_on('projects') || !Gate::allows('projects.tasks.manage', $u)) { return []; }
    [$w, $p] = PA::scope($u, 'p.id');
    return DB::pairs("SELECT p.id, p.name FROM projects p WHERE $w AND (p.status NOT IN ('completed','cancelled') OR p.id=?) ORDER BY p.name", [...$p, (int) $keep]); }

  /** Whether the dialog has anything to offer (show the New task button). */
  public static function available(array $u): bool { return self::todos($u) || (bool) self::projects($u); }

  /** Team, milestones and billing default of a project (dialog fields and the JSON endpoint). */
  public static function projectData(int $id): array {
    return ['team' => PS::team($id), 'milestones' => DB::pairs('SELECT id, title FROM milestones WHERE project_id=? ORDER BY due_date IS NULL, due_date, position', [$id]), 'billable' => DB::value('SELECT billing_type FROM projects WHERE id=?', [$id]) !== 'non_billable']; }

  /**
   * View data for the dialog, or null when the user cannot create anything.
   * $o: project (preselected id), status (preselected project status), return (local path), open (bool).
   */
  public static function vars(array $u, array $o = []): ?array {
    $todo = self::todos($u);
    $pre = (int) ($o['project'] ?? 0);
    $re = errors() && old('_form') === 'task-new';
    $pid = $re ? (int) old('project_id', 0) : $pre;
    $projects = self::projects($u, $pid ?: null);
    if (!$todo && !$projects) { return null; }
    if ($pid && !isset($projects[$pid])) { $pid = 0; }
    if (!$pid && !$todo) { $pid = (int) array_key_first($projects); }
    $today = date('Y-m-d');
    $v = ['assignee_id' => (int) $u['id'], 'priority' => 'medium', 'repeat' => 'once', 'weekdays' => [(int) date('N')], 'month_day' => (int) date('j'), 'start_date' => $today, 'status' => $o['status'] ?? 'todo', 'passignee_id' => ''];
    if ($re) {
      foreach (['title', 'description', 'priority', 'repeat', 'due_date', 'workdays_only', 'weekdays', 'month_day', 'start_date', 'end_date', 'status', 'milestone_id', 'estimated_hours', 'billable'] as $k) { $v[$k] = old($k, $k === 'weekdays' ? [] : ''); }
      $v[$pid ? 'passignee_id' : 'assignee_id'] = old('assignee_id');
      $v = array_merge($v, ['priority' => $v['priority'] ?: 'medium', 'repeat' => $v['repeat'] ?: 'once', 'status' => $v['status'] ?: 'todo']); }
    $pd = $pid ? self::projectData($pid) : ['team' => [], 'milestones' => [], 'billable' => true];
    $v['billable'] = $re ? (bool) $v['billable'] : $pd['billable'];
    $v['pstart_date'] = $re && $pid ? $v['start_date'] : ''; $v['pdue_date'] = $re && $pid ? ($v['due_date'] ?? '') : '';
    return ['todo' => $todo, 'projects' => $projects, 'pid' => $pid, 'pd' => $pd, 'assignees' => $todo ? Todos::assignable($u) : [], 'v' => $v, 'open' => $re || !empty($o['open']), 'return' => (string) ($o['return'] ?? '/todos')]; } }
