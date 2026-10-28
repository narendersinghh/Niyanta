<?php
namespace Core;

/** Role-based permission checks. Super Admin implicitly holds every permission. */
final class Gate {
  private static array $perms = [];

  public static function permissionsFor(int $roleId): array {
    return self::$perms[$roleId] ??= Cache::remember('perms_role_'.$roleId, 3600, static fn () => array_fill_keys(DB::column('SELECT permission FROM role_permissions WHERE role_id=?', [$roleId]), true)); }

  public static function allows(string $perm, ?array $user = null): bool {
    $user ??= Auth::user();
    if (!$user) { return false; }
    if ($user['role_slug'] === 'super_admin') { return true; }
    $mod = strstr($perm, '.', true);
    if ($mod && Modules::exists($mod) && !Modules::enabled($mod)) { return false; }
    return isset(self::permissionsFor((int) $user['role_id'])[$perm]); }

  public static function any(array $perms, ?array $user = null): bool {
    foreach ($perms as $p) { if (self::allows($p, $user)) { return true; } }
    return false; }

  public static function authorize(string $perm): void { if (!self::allows($perm)) { throw new HttpException(403, 'You do not have permission to perform this action.'); } }

  public static function flush(): void { self::$perms = []; foreach (DB::column('SELECT id FROM roles') as $id) { Cache::forget('perms_role_'.$id); } }

  /** Users holding a permission (for notifications / approver lists). */
  public static function usersWith(string $perm): array {
    return DB::all("SELECT u.id, u.name, u.email FROM users u JOIN roles r ON r.id=u.role_id LEFT JOIN role_permissions rp ON rp.role_id=r.id AND rp.permission=? WHERE u.status='active' AND (r.slug='super_admin' OR rp.permission IS NOT NULL) GROUP BY u.id, u.name, u.email", [$perm]); } }
