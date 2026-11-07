<?php
namespace Modules\Crm\Services;
use Core\{DB, Gate, Request};
use Modules\Crm\Controllers\JourneyController;

/**
 * Client logs (client_activities) organised by project: the client page Journey (type + project chips, grouped "All" view)
 * and the project page Client log share these queries and the crm::partials/journey_* views.
 */
final class Journey {
  public const PER = 25;

  /** Journey tab data of a client: counts per type and project (one GROUP BY), the filtered page and, in the "All" view, entries grouped by project. */
  public static function clientData(int $clientId, array $projects, bool $projOn, Request $r): array {
    $jp = max(1, $r->int('jp', 1));
    $jt = $r->str('jt'); $jt = isset(JourneyController::TYPES[$jt]) ? $jt : '';
    $jpr = $r->str('jpr');
    $counts = DB::all('SELECT type, '.($projOn ? 'project_id' : 'NULL AS project_id').', COUNT(*) AS n FROM client_activities WHERE client_id=? GROUP BY type'.($projOn ? ', project_id' : ''), [$clientId]);
    $names = array_column($projects, 'name', 'id');
    $missing = array_values(array_diff(array_filter(array_map(static fn ($c) => (int) $c['project_id'], $counts)), array_map('intval', array_keys($names))));
    if ($projOn && $missing) { $names += DB::pairs('SELECT id, name FROM projects WHERE id IN '.DB::in($missing), $missing); }
    if (!$projOn || ($jpr !== 'none' && !(ctype_digit($jpr) && isset($names[(int) $jpr])))) { $jpr = ''; }
    [$total, $typeCounts, $projCounts] = self::tally($counts, $jt, $jpr);
    $conds = ['client_id=?']; $p = [$clientId];
    if ($jt !== '') { $conds[] = 'type=?'; $p[] = $jt; }
    $grouped = $projOn && $jpr === '' && array_diff(array_map('strval', array_keys($projCounts)), ['none']);
    if ($jpr === 'none') { $conds[] = 'project_id IS NULL'; } elseif ($jpr !== '') { $conds[] = 'project_id=?'; $p[] = (int) $jpr; }
    $acts = self::page($conds, $p, $jp, $projOn, (bool) $grouped);
    return ['acts' => array_slice($acts, 0, self::PER), 'more' => count($acts) > self::PER, 'jp' => $jp, 'jt' => $jt, 'jpr' => $jpr, 'grouped' => (bool) $grouped,
      'typeCounts' => $typeCounts, 'projCounts' => $projCounts, 'projNames' => $names, 'journeyTotal' => $total, 'transcripts' => self::transcripts(array_column(array_slice($acts, 0, self::PER), 'id'))]; }

  /** [overall total, type => count (within the project filter), project id|'none' => count (within the type filter)] */
  public static function tally(array $counts, string $jt, string $jpr): array {
    $total = 0; $types = []; $projs = [];
    foreach ($counts as $c) {
      $n = (int) $c['n']; $total += $n;
      $pk = $c['project_id'] === null ? 'none' : (string) (int) $c['project_id'];
      if ($jpr === '' || $jpr === $pk) { $types[$c['type']] = ($types[$c['type']] ?? 0) + $n; }
      if ($jt === '' || $jt === $c['type']) { $projs[$pk] = ($projs[$pk] ?? 0) + $n; } }
    return [$total, $types, $projs]; }

  /**
   * One page (+1 row to detect more) of entries matching $conds (column conditions without alias).
   * Grouped: entries of the same project stay together, groups ordered by their latest entry, newest first inside a group.
   */
  public static function page(array $conds, array $p, int $page, bool $projOn, bool $grouped = false): array {
    $w = implode(' AND ', array_map(static fn ($c) => 'a.'.$c, $conds));
    $sql = 'SELECT a.*, u.name AS user_name, u.avatar AS user_avatar'.($projOn ? ', pr.name AS project_name' : ', NULL AS project_name').' FROM client_activities a LEFT JOIN users u ON u.id=a.user_id'.($projOn ? ' LEFT JOIN projects pr ON pr.id=a.project_id' : '');
    $order = 'a.occurred_at DESC, a.id DESC'; $params = $p;
    if ($grouped) { $sql .= ' LEFT JOIN (SELECT project_id AS gp, MAX(occurred_at) AS glast FROM client_activities WHERE '.implode(' AND ', $conds).' GROUP BY project_id) g ON g.gp <=> a.project_id'; $order = 'g.glast DESC, a.project_id IS NULL, a.project_id, '.$order; $params = [...$p, ...$p]; }
    return DB::all($sql.' WHERE '.$w.' ORDER BY '.$order.' LIMIT '.(self::PER + 1).' OFFSET '.(($page - 1) * self::PER), $params); }

  /** activity id => meeting transcript id, for "View full transcript" links (Meetings feature, meetings.use). */
  public static function transcripts(array $activityIds): array {
    $ids = array_values(array_filter(array_map('intval', $activityIds)));
    if (!$ids || !feature_on('meetings') || !Gate::allows('meetings.use') || !class_exists(\Modules\Meetings\Services\Attach::class)) { return []; }
    return \Modules\Meetings\Services\Attach::forActivities($ids); }

  /** Edit payload of an entry for the activity dialog. */
  public static function editData(array $a, string $base): string {
    $dt = static fn (?string $v) => $v ? substr(str_replace(' ', 'T', $v), 0, 16) : '';
    return json_encode(['action' => url($base.'/activities/'.$a['id']), 'type' => $a['type'], 'title' => $a['title'], 'body' => $a['body'], 'occurred_at' => $dt($a['occurred_at']), 'follow_up_at' => $dt($a['follow_up_at']), 'is_internal' => (int) $a['is_internal'], 'project_id' => $a['project_id']]); } }
