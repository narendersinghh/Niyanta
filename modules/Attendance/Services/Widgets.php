<?php
namespace Modules\Attendance\Services;
use Core\{DB, View};

/** Dashboard widgets. */
final class Widgets {
  private static bool $assets = false;

  /** Module stylesheet & script for the dashboard (emitted once; external files are CSP-safe). */
  private static function assets(): string {
    if (self::$assets) { return ''; }
    self::$assets = true;
    return '<link rel="stylesheet" href="'.e(asset('css/attendance.css')).'"><script src="'.e(asset('js/attendance.js')).'" defer></script>'; }
  public static function side(array $u): string {
    return self::assets().View::partial('attendance::widgets/side', ['st' => Tracker::state((int) $u['id'])]); }

  public static function kpi(array $u): string {
    $ws = TimeService::weekStart();
    $sec = TimeService::secondsForUser((int) $u['id'], $ws, date('Y-m-d', strtotime($ws.' +6 days')));
    $target = AttendanceService::workSeconds((int) $u['id']) * max(1, 7 - count(AttendanceService::offDays((int) $u['id'])));
    return '<a class="tile bg-sky" href="'.e(url('/timesheets')).'"><span class="tile-ic">'.icon('timer').'</span><div><div class="num">'.e(sprintf('%.1f', $sec / 3600)).'<small class="tile-unit">h</small></div><div class="lbl">Hours this week · '.e((string) round(100 * $sec / max(1, $target))).'% of target</div></div>'.illus('clock-worker', 'corner').'</a>'.self::assets(); }

  public static function team(array $u): string {
    [$w, $p] = Team::where($u, 'u.id');
    $rows = DB::all("SELECT u.id, u.name, u.avatar, u.designation, a.clock_in, (SELECT 1 FROM attendance_breaks ab WHERE ab.attendance_id=a.id AND ab.end_at IS NULL LIMIT 1) AS on_break, te.started_at AS timer_start, te.discarded_seconds, pr.name AS project_name FROM users u LEFT JOIN attendance a ON a.user_id=u.id AND a.work_date=? AND a.clock_out IS NULL AND a.clock_in IS NOT NULL LEFT JOIN time_entries te ON te.user_id=u.id AND te.is_running=1 LEFT JOIN projects pr ON pr.id=te.project_id WHERE u.status='active' AND u.id<>? AND $w AND (a.id IS NOT NULL OR te.id IS NOT NULL) ORDER BY te.started_at IS NULL, u.name LIMIT 9", [date('Y-m-d'), $u['id'], ...$p]);
    $ids = Team::memberIds($u);
    if ($ids === []) { return ''; }
    return self::assets().View::partial('attendance::widgets/team', ['rows' => $rows]); } }
