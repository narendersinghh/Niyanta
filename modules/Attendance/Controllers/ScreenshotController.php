<?php
namespace Modules\Attendance\Controllers;
use Core\{Audit, DB, Response, Storage};
use Modules\Attendance\Services\Monitoring;

/** Screenshot gallery and private image serving (owner, or permitted team lead). Captures arrive from the desktop tracker API. */
final class ScreenshotController extends BaseController {
  /** One day of screenshots grouped by hour and 10-minute slot, with the day summary. */
  public function index(): Response {
    [$target, $name, $people] = $this->member('attendance.screenshots.view', 'screenshots');
    $date = min(date('Y-m-d'), $this->dateParam('date', date('Y-m-d')));
    $rows = DB::all('SELECT s.id, s.taken_at, s.source, s.activity_pct, s.display_index, p.name AS project_name, t.title AS task_title, te.description FROM screenshots s LEFT JOIN time_entries te ON te.id=s.time_entry_id LEFT JOIN projects p ON p.id=te.project_id LEFT JOIN tasks t ON t.id=te.task_id WHERE s.user_id=? AND s.taken_at>=? AND s.taken_at<? ORDER BY s.taken_at, s.display_index LIMIT 3000', [$target, $date.' 00:00:00', date('Y-m-d', strtotime($date.' +1 day')).' 00:00:00']);
    $hours = [];
    foreach ($rows as $r) { $t = strtotime($r['taken_at']); $hours[(int) date('G', $t)][intdiv((int) date('i', $t), 10)][] = $r; }
    if ($target !== $this->uid()) { Audit::log('monitoring.view', 'user', $target, ['date' => $date]); }
    return $this->view('attendance::screenshots/index', ['title' => 'Screenshots', 'hours' => $hours, 'count' => count($rows), 'date' => $date, 'target' => $target, 'targetName' => $name, 'people' => $people,
      'retention' => Monitoring::retention(), 'enabled' => Monitoring::enabled(), 'styles' => ['css/attendance.css'], 'scripts' => ['js/attendance.js']] + self::day($target, $date)); }

  public function image(int $id): Response {
    $u = $this->user();
    $s = DB::first('SELECT id, user_id, disk_path, taken_at FROM screenshots WHERE id=? LIMIT 1', [$id]) ?? $this->abort(404, 'Screenshot not found.');
    if (!Monitoring::canView($u, (int) $s['user_id'])) { Audit::log('access.denied', 'screenshot', $id); $this->abort(403, 'You cannot view this screenshot.'); }
    $path = Storage::path($s['disk_path']);
    return Response::file($path, 'screenshot-'.date('Ymd-His', strtotime($s['taken_at'])).'.'.pathinfo($path, PATHINFO_EXTENSION), null, true); } }
