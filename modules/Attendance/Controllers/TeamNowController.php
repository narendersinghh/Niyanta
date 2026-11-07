<?php
namespace Modules\Attendance\Controllers;
use Core\{DB, Response};
use Modules\Attendance\Services\{AttendanceService, Team, TimeService};

/**
 * Top-bar "active members" widget (attendance.view_all): how many team members track right now, and on click who is
 * active, paused / idle or not working today. Scoped with Team::where like the team attendance page; two queries.
 */
final class TeamNowController extends BaseController {
  private const CAP = 40;

  /** GET /time/team/now: full lists for the popup, or ?count=1 for the pill refresh. */
  public function index(): Response {
    $u = $this->user();
    if ($this->request->query('count')) { return $this->json(['ok' => true, 'active' => self::count($u)]); }
    return $this->json(['ok' => true] + self::lists($u)); }

  /** People of the viewer's team with a running entry whose app still answers (heartbeat within STALE_SECONDS). */
  public static function count(array $u): int {
    [$w, $p] = Team::where($u, 'te.user_id');
    return (int) DB::value("SELECT COUNT(DISTINCT te.user_id) FROM time_entries te JOIN users u ON u.id=te.user_id AND u.status='active' WHERE te.is_running=1 AND COALESCE(te.last_heartbeat_at, te.started_at)>=? AND $w", [self::fresh(), ...$p]); }

  private static function fresh(): string { return date('Y-m-d H:i:s', time() - TimeService::STALE_SECONDS); }

  /** ['active' => rows, 'away' => rows (paused, idle, clocked in but not tracking), 'off' => rows (not started, on leave, day ended), 'counts' => [...]] */
  public static function lists(array $u): array {
    [$w, $p] = Team::where($u, 'u.id');
    $today = date('Y-m-d'); $now = time(); $pj = feature_on('projects');
    $rows = DB::all("SELECT u.id, u.name, u.avatar, a.clock_in, a.clock_out, a.status AS att_status,"
      ." (SELECT ab.start_at FROM attendance_breaks ab WHERE ab.attendance_id=a.id AND ab.end_at IS NULL ORDER BY ab.id DESC LIMIT 1) AS break_start,"
      ." te.started_at, te.last_heartbeat_at, te.discarded_seconds, te.description, p.name AS project_name, t.title AS task_title"
      ." FROM users u LEFT JOIN attendance a ON a.user_id=u.id AND a.work_date=? LEFT JOIN time_entries te ON te.user_id=u.id AND te.is_running=1"
      ." LEFT JOIN projects p ON p.id=te.project_id LEFT JOIN tasks t ON t.id=te.task_id WHERE u.status='active' AND $w ORDER BY u.name LIMIT 1000", [$today, ...$p]);
    $ids = array_values(array_unique(array_map(static fn ($r) => (int) $r['id'], $rows)));
    $leave = feature_on('leave') && $ids ? AttendanceService::leaveMap($ids, $today, $today) : [];
    $fresh = strtotime(self::fresh());
    $out = ['active' => [], 'away' => [], 'off' => []]; $seen = [];
    foreach ($rows as $r) {
      $id = (int) $r['id'];
      if (isset($seen[$id])) { continue; }
      $seen[$id] = true;
      $row = ['id' => $id, 'name' => $r['name'], 'avatar' => avatar($r['name'], $r['avatar'], 'sm'), 'url' => url('/time/daily?user='.$id), 'project' => null, 'task' => null];
      if ($r['started_at']) {
        $hb = strtotime($r['last_heartbeat_at'] ?: $r['started_at']);
        $row['project'] = $pj ? ($r['project_name'] ?? 'No project') : 'Tracked time';
        $row['task'] = $pj && $r['task_title'] ? str_limit($r['task_title'], 40) : ($r['description'] ? str_limit($r['description'], 40) : null);
        if ($hb >= $fresh) { $out['active'][] = $row + ['status' => 'tracking', 'label' => 'Tracking', 'since' => 'since '.ftime($r['started_at']), 'dur' => self::dur($now - strtotime($r['started_at']) - (int) $r['discarded_seconds']), 't' => strtotime($r['started_at'])]; continue; }
        $out['away'][] = $row + ['status' => 'idle', 'label' => 'Idle, no signal from the app', 'since' => 'since '.ftime(date('Y-m-d H:i:s', $hb)), 'dur' => self::dur($now - $hb)];
        continue; }
      if ($r['clock_in'] && !$r['clock_out']) {
        $out['away'][] = $r['break_start'] ? $row + ['status' => 'paused', 'label' => 'Paused', 'since' => 'since '.ftime($r['break_start']), 'dur' => self::dur($now - strtotime($r['break_start']))]
          : $row + ['status' => 'stopped', 'label' => 'Clocked in, not tracking', 'since' => 'in at '.ftime($r['clock_in']), 'dur' => null];
        continue; }
      $lv = $leave[$id][$today] ?? null;
      if ($r['clock_out']) { $out['off'][] = $row + ['status' => 'ended', 'label' => 'Day ended', 'since' => 'at '.ftime($r['clock_out']), 'dur' => null]; }
      elseif ($lv || in_array($r['att_status'], ['leave', 'holiday'], true)) { $out['off'][] = $row + ['status' => 'leave', 'label' => $r['att_status'] === 'holiday' ? 'Holiday' : ($lv === 0.5 ? 'Half-day leave' : 'On leave'), 'since' => null, 'dur' => null]; }
      else { $out['off'][] = $row + ['status' => 'not_started', 'label' => 'Not started', 'since' => null, 'dur' => null]; } }
    usort($out['active'], static fn ($a, $b) => $a['t'] <=> $b['t']);
    $counts = array_map('count', $out);
    foreach ($out as $k => $list) { $out[$k] = array_map(static function (array $r): array { unset($r['t']); return $r; }, array_slice($list, 0, self::CAP)); }
    return $out + ['counts' => $counts, 'team_url' => url('/attendance/team'), 'updated' => ftime(date('Y-m-d H:i:s', $now))]; }

  private static function dur(int $s): string { $s = max(0, $s); $h = intdiv($s, 3600); $m = intdiv($s % 3600, 60); return $h ? $h.'h '.sprintf('%02d', $m).'m' : $m.'m'; } }
