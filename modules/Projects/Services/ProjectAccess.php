<?php
namespace Modules\Projects\Services;
use Core\{DB, Gate, Modules};

/**
 * Record-level access rules for projects and tasks (also used by the Attendance and AI modules).
 * Super Admin / projects.view_all see every project; everyone else sees projects they are a member of or manage.
 */
final class ProjectAccess {
  private static array $ids = [];
  private static array $exists = [];

  /** Make sure the user array carries role_slug / role_id (callers may pass a bare users row). */
  private static function norm(array $user): array {
    if (!isset($user['role_slug']) && !empty($user['id'])) { $user += DB::first('SELECT r.slug AS role_slug, u.role_id FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=?', [(int) $user['id']]) ?? []; }
    return $user; }

  public static function unrestricted(array $user): bool {
    $user = self::norm($user);
    return ($user['role_slug'] ?? '') === 'super_admin' || (Modules::enabled('projects') && Gate::allows('projects.view_all', $user)); }

  /** Project ids the user may see; null means unrestricted (all projects). */
  public static function visibleProjectIds(array $user): ?array {
    $user = self::norm($user);
    if (empty($user['id'])) { return []; }
    if (self::unrestricted($user)) { return null; }
    if (!Modules::enabled('projects') || !Gate::allows('projects.view', $user)) { return []; }
    $uid = (int) $user['id'];
    return self::$ids[$uid] ??= array_map('intval', DB::column('SELECT project_id FROM project_members WHERE user_id=? UNION SELECT id FROM projects WHERE manager_id=?', [$uid, $uid])); }

  public static function flush(): void { self::$ids = []; self::$exists = []; }

  public static function canSee(array $user, int $projectId): bool {
    if ($projectId <= 0) { return false; }
    $ids = self::visibleProjectIds($user);
    return $ids === null ? (self::$exists[$projectId] ??= (bool) DB::value('SELECT 1 FROM projects WHERE id=?', [$projectId])) : in_array($projectId, $ids, true); }

  /** Managers (projects.tasks.manage) may edit any task in visible projects; others only tasks assigned to them (projects.tasks.update_own). */
  public static function canEditTask(array $user, array $task): bool {
    $user = self::norm($user);
    if (empty($task['project_id']) || !self::canSee($user, (int) $task['project_id'])) { return false; }
    if (Gate::allows('projects.tasks.manage', $user)) { return true; }
    return Gate::allows('projects.tasks.update_own', $user) && (int) ($task['assignee_id'] ?? 0) === (int) $user['id']; }

  public static function canManageTasks(array $user, int $projectId): bool { return Gate::allows('projects.tasks.manage', self::norm($user)) && self::canSee($user, $projectId); }
  public static function canManageProject(array $user, int $projectId): bool { return Gate::allows('projects.manage', self::norm($user)) && self::canSee($user, $projectId); }

  /** SQL fragment restricting a project id column to visible projects: [sql, params]. */
  public static function scope(array $user, string $col = 'p.id'): array {
    $ids = self::visibleProjectIds($user);
    if ($ids === null) { return ['1=1', []]; }
    return $ids ? [$col.' IN '.DB::in($ids), $ids] : ['0=1', []]; } }
