<?php
namespace Modules\Attendance\Controllers;
use Core\{Audit, DB, Response, Storage};
use Modules\Attendance\Services\Monitoring;

/** Screenshot gallery and private image serving (owner, or permitted team lead). Captures arrive from the desktop tracker API. */
final class ScreenshotController extends BaseController {
  /** One day of screenshots grouped by hour and 10-minute slot, with the device split (?device=), the day summary, the activity chart (its bars scroll to the slot cards) and the whole-day gallery. */
  public function index(): Response {
    [$target, $name, $people] = $this->member('attendance.screenshots.view', 'screenshots');
    $date = min(date('Y-m-d'), $this->dateParam('date', date('Y-m-d')));
    $day = self::day($target, $date);
    [$devices, $dev] = $this->devices($target, $date, $day); $dk = $dev ? $dev['key'] : null;
    $rows = HubController::dayShots($target, $date, $dk);
    $hours = [];
    foreach ($rows as $n => $r) { $hours[(int) substr($r['taken_at'], 11, 2)][intdiv((int) substr($r['taken_at'], 14, 2), 10)][$n] = $r; }
    [$gallery] = HubController::gallery($rows);
    if ($target !== $this->uid()) { Audit::log('monitoring.view', 'user', $target, ['date' => $date]); }
    return $this->view('attendance::screenshots/index', ['title' => 'Screenshots', 'hours' => $hours, 'gallery' => $gallery, 'count' => count($rows), 'date' => $date, 'target' => $target, 'targetName' => $name, 'people' => $people,
      'retention' => Monitoring::retention(), 'enabled' => Monitoring::enabled(), 'chart' => HubController::chart($target, $date, $day['entries'], $dk), 'devices' => $devices, 'device' => $dev, 'styles' => ['css/attendance.css'], 'scripts' => ['js/attendance.js']] + $day); }

  public function image(int $id): Response {
    $u = $this->user();
    $s = DB::first('SELECT id, user_id, disk_path, taken_at FROM screenshots WHERE id=? LIMIT 1', [$id]) ?? $this->abort(404, 'Screenshot not found.');
    if (!Monitoring::canView($u, (int) $s['user_id'])) { Audit::log('access.denied', 'screenshot', $id); $this->abort(403, 'You cannot view this screenshot.'); }
    /* free the session lock: a day of thumbnails loads in parallel and must not queue behind each other (shared hosting limits concurrent PHP) */
    if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
    $path = Storage::path($s['disk_path']);
    if ($this->request->query('s') === 't' && ($t = Monitoring::thumb($path))) { $path = $t; }
    $res = Response::file($path, 'screenshot-'.date('Ymd-His', strtotime($s['taken_at'])).'.'.pathinfo($path, PATHINFO_EXTENSION), null, true);
    /* a capture never changes: let the browser keep it for a day instead of asking again on every view (access was checked above) */
    return $res->status() === 200 ? $res->header('Cache-Control', 'private, max-age=86400') : $res; } }
