<?php
namespace Modules\Crm\Services;
use Core\{DB, Gate};

/**
 * Record-level client visibility, shared with other modules (AI, Projects, reports).
 * Super Admins and holders of crm.clients.view_all see every client; everyone else only the clients
 * they are assigned to through client_managers (and only while they hold crm.clients.view).
 */
final class ClientAccess {
  private static array $cache = [];

  public static function unrestricted(array $user): bool { return ($user['role_slug'] ?? '') === 'super_admin' || Gate::allows('crm.clients.view_all', $user); }

  /** null = unrestricted, otherwise the list of visible client ids (possibly empty). */
  public static function visibleClientIds(array $user): ?array {
    if (self::unrestricted($user)) { return null; }
    $uid = (int) ($user['id'] ?? 0);
    if (!$uid || !Gate::allows('crm.clients.view', $user)) { return []; }
    return self::$cache[$uid] ??= array_map('intval', DB::column('SELECT client_id FROM client_managers WHERE user_id=?', [$uid])); }

  public static function canSee(array $user, int $clientId): bool {
    if ($clientId <= 0) { return false; }
    $ids = self::visibleClientIds($user);
    return $ids === null ? (bool) DB::value('SELECT 1 FROM clients WHERE id=?', [$clientId]) : in_array($clientId, $ids, true); }

  /** SQL condition limiting a client-id column to visible clients: [sql, params]. Uses the indexed client_managers PK. */
  public static function where(array $user, string $col = 'c.id'): array {
    if (self::unrestricted($user)) { return ['1=1', []]; }
    if (!Gate::allows('crm.clients.view', $user)) { return ['1=0', []]; }
    return ['EXISTS (SELECT 1 FROM client_managers cm_s WHERE cm_s.client_id='.$col.' AND cm_s.user_id=?)', [(int) $user['id']]]; }

  public static function flush(): void { self::$cache = []; } }
