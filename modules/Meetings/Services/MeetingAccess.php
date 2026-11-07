<?php
namespace Modules\Meetings\Services;
use Core\Gate;
use Modules\Crm\Services\ClientAccess;
use Modules\Projects\Services\ProjectAccess;

/**
 * Record-level access to meeting transcripts: managers (meetings.manage, Super Admin) see everything including the unmatched inbox;
 * everyone else sees transcripts they own, transcripts of clients they may see, and transcripts of client-less (internal) projects they may see.
 */
final class MeetingAccess {
  public static function manager(array $u): bool { return ($u['role_slug'] ?? '') === 'super_admin' || Gate::allows('meetings.manage', $u); }

  /** [sql, params] limiting meeting_transcripts alias $a to visible rows. */
  public static function scope(array $u, string $a = 'mt'): array {
    if (self::manager($u)) { return ['1=1', []]; }
    [$cw, $cp] = ClientAccess::where($u, "$a.client_id");
    [$pw, $pp] = feature_on('projects') ? ProjectAccess::scope($u, "$a.project_id") : ['0=1', []];
    return ["($a.owner_id=? OR ($a.client_id IS NOT NULL AND $cw) OR ($a.client_id IS NULL AND $a.project_id IS NOT NULL AND $pw))", [(int) $u['id'], ...$cp, ...$pp]]; }

  public static function canSee(array $u, array $t): bool {
    if (self::manager($u) || (int) ($t['owner_id'] ?? 0) === (int) $u['id']) { return true; }
    if (!empty($t['client_id'])) { return ClientAccess::canSee($u, (int) $t['client_id']); }
    return !empty($t['project_id']) && feature_on('projects') && ProjectAccess::canSee($u, (int) $t['project_id']); }

  /** Sort, re-run the summary, create tasks from action items: whoever sees it and may write client logs, or its owner / a manager. */
  public static function canEdit(array $u, array $t): bool { return self::canSee($u, $t) && (self::manager($u) || (int) ($t['owner_id'] ?? 0) === (int) $u['id'] || Gate::allows('crm.clients.manage', $u)); }

  public static function canDelete(array $u, array $t): bool { return self::manager($u) || (int) ($t['owner_id'] ?? 0) === (int) $u['id']; }

  /** May the user put a log on this client / project (target of sorting or a manual upload)? */
  public static function canTarget(array $u, ?int $clientId, ?int $projectId): bool {
    if (!Gate::allows('crm.clients.manage', $u) || (!$clientId && !$projectId)) { return false; }
    if ($clientId && !ClientAccess::canSee($u, $clientId)) { return false; }
    return !$projectId || (feature_on('projects') && ProjectAccess::canSee($u, $projectId)); } }
