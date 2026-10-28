<?php
namespace Modules\Attendance\Services;
use Core\{DB, Gate, Modules};

/** Team scoping: HR / Super Admin / attendance managers see everyone; managers see direct reports + members of projects they manage. */
final class Team {
  private static array $cache = [];

  public static function seesAll(array $u): bool { return in_array($u['role_slug'] ?? '', ['super_admin', 'hr'], true) || Gate::allows('attendance.manage', $u); }

  /** User ids in this user's team (excluding self), or null when unrestricted. */
  public static function memberIds(array $u): ?array {
    if (self::seesAll($u)) { return null; }
    $uid = (int) $u['id'];
    if (isset(self::$cache[$uid])) { return self::$cache[$uid]; }
    $sql = "SELECT id FROM users WHERE manager_id=? AND status='active'";
    $p = [$uid];
    if (Modules::enabled('projects')) { $sql .= " UNION SELECT pm.user_id FROM project_members pm JOIN projects p ON p.id=pm.project_id JOIN users u ON u.id=pm.user_id AND u.status='active' WHERE p.manager_id=? AND p.status IN ('planning','active','on_hold')"; $p[] = $uid; }
    $ids = array_values(array_diff(array_unique(array_map('intval', DB::column($sql, $p))), [$uid]));
    return self::$cache[$uid] = $ids; }

  public static function includes(array $u, int $target): bool { $ids = self::memberIds($u); return $ids === null || in_array($target, $ids, true); }

  /** [sql, params] restricting a user-id column to the team. */
  public static function where(array $u, string $col): array {
    $ids = self::memberIds($u);
    return $ids === null ? ['1=1', []] : [$col.' IN '.DB::in($ids), $ids]; }

  /** id => name of active team members for pickers. */
  public static function people(array $u, bool $withSelf = false): array {
    [$w, $p] = self::where($u, 'id');
    if ($withSelf && $w !== '1=1') { $w = '('.$w.' OR id=?)'; $p[] = (int) $u['id']; }
    return DB::pairs("SELECT id, name FROM users WHERE status='active' AND $w ORDER BY name", $p); } }
