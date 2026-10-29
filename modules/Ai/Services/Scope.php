<?php
namespace Modules\Ai\Services;
use Core\{DB, Gate, Modules};

/**
 * Record-level visibility, delegated to the owning module's access service when available,
 * otherwise a conservative fallback (super admin / *.view_all see all, others only assigned records).
 * Null = all records visible.
 */
final class Scope {
  private static array $memo = [];

  /** Permission check that also requires the owning module to be enabled (Gate lets super admins through disabled modules). */
  public static function can(string $perm, array $u): bool { $m = strstr($perm, '.', true); return (!$m || !Modules::exists($m) || Modules::enabled($m)) && Gate::allows($perm, $u); }
  public static function isSuper(array $u): bool { return ($u['role_slug'] ?? '') === 'super_admin'; }

  public static function clientIds(array $u): ?array {
    if (!self::can('crm.clients.view', $u)) { return []; }
    return self::$memo['c'.$u['id']] ??= (function () use ($u) {
      if (feature_on('clients') && class_exists('Modules\\Crm\\Services\\ClientAccess')) { $r = \Modules\Crm\Services\ClientAccess::visibleClientIds($u); return $r === null ? null : array_map('intval', $r); }
      if (self::isSuper($u) || self::can('crm.clients.view_all', $u)) { return null; }
      return array_map('intval', DB::column('SELECT client_id FROM client_managers WHERE user_id=?', [$u['id']])); })(); }

  public static function canSeeClient(array $u, int $id): bool {
    if ($id <= 0 || !self::can('crm.clients.view', $u)) { return false; }
    if (feature_on('clients') && class_exists('Modules\\Crm\\Services\\ClientAccess')) { return \Modules\Crm\Services\ClientAccess::canSee($u, $id); }
    $ids = self::clientIds($u);
    return $ids === null ? (bool) DB::value('SELECT 1 FROM clients WHERE id=?', [$id]) : in_array($id, $ids, true); }

  public static function projectIds(array $u): ?array {
    if (!self::can('projects.view', $u)) { return []; }
    return self::$memo['p'.$u['id']] ??= (function () use ($u) {
      if (feature_on('projects') && class_exists('Modules\\Projects\\Services\\ProjectAccess')) { $r = \Modules\Projects\Services\ProjectAccess::visibleProjectIds($u); return $r === null ? null : array_map('intval', $r); }
      if (self::isSuper($u) || self::can('projects.view_all', $u)) { return null; }
      return array_map('intval', DB::column('SELECT project_id FROM project_members WHERE user_id=? UNION SELECT id FROM projects WHERE manager_id=?', [$u['id'], $u['id']])); })(); }

  public static function canSeeProject(array $u, int $id): bool {
    if ($id <= 0 || !self::can('projects.view', $u)) { return false; }
    if (feature_on('projects') && class_exists('Modules\\Projects\\Services\\ProjectAccess')) { return \Modules\Projects\Services\ProjectAccess::canSee($u, $id); }
    $ids = self::projectIds($u);
    return $ids === null ? (bool) DB::value('SELECT 1 FROM projects WHERE id=?', [$id]) : in_array($id, $ids, true); }

  /** SQL fragment restricting $col to visible ids ('' when unrestricted). Appends params by reference. */
  public static function where(?array $ids, string $col, array &$p): string {
    if ($ids === null) { return ''; }
    if (!$ids) { return ' AND 1=0'; }
    array_push($p, ...$ids);
    return ' AND '.$col.' IN '.DB::in($ids); }

  public static function flush(): void { self::$memo = []; } }
