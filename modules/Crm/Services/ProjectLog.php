<?php
namespace Modules\Crm\Services;
use Core\{DB, Gate, Request};
use Modules\Projects\Services\ProjectAccess;

/**
 * Client log on the project page: the client_activities of one project, newest first.
 * Visible to holders of crm.clients.view who can see the project and its client (internal projects have no client);
 * logging needs crm.clients.manage, the permission of the client Journey routes.
 */
final class ProjectLog {
  /** [may view, may log] for a projects row (id, client_id). */
  public static function access(array $u, array $pr): array {
    if (!feature_on('clients') || !feature_on('projects') || !ProjectAccess::canSee($u, (int) $pr['id'])) { return [false, false]; }
    $cid = (int) ($pr['client_id'] ?? 0);
    if ($cid && !ClientAccess::canSee($u, $cid)) { return [false, false]; }
    $view = Gate::allows('crm.clients.view', $u);
    return [$view, $view && Gate::allows('crm.clients.manage', $u)]; }

  public static function count(int $projectId): int { return (int) DB::value('SELECT COUNT(*) FROM client_activities WHERE project_id=?', [$projectId]); }

  /** Data of the project page "Client log" tab. */
  public static function tab(array $pr, Request $r, bool $canLog): array {
    $jp = max(1, $r->int('jp', 1));
    $acts = Journey::page(['project_id=?'], [(int) $pr['id']], $jp, true);
    $page = array_slice($acts, 0, Journey::PER);
    return ['acts' => $page, 'logMore' => count($acts) > Journey::PER, 'jp' => $jp, 'canLog' => $canLog, 'transcripts' => Journey::transcripts(array_column($page, 'id')),
      'clientLabel' => $pr['client_id'] ? (string) DB::value("SELECT COALESCE(NULLIF(company,''), name) FROM clients WHERE id=?", [(int) $pr['client_id']]) : null]; } }
