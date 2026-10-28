<?php
namespace Modules\Attendance\Controllers;
use Core\{Audit, DB, Response, Storage};
use Modules\Attendance\Services\{Monitoring, Team};

/** Browser screenshot upload, gallery and private image serving (owner, or permitted team lead). */
final class ScreenshotController extends BaseController {
  public function upload(): Response {
    $f = $this->request->file('shot');
    if (!$f) { return $this->json(['error' => 'No image received.'], 422); }
    $act = $this->request->input('activity_pct');
    return $this->act(fn (): array => ['message' => 'Screenshot saved.', 'id' => Monitoring::store($this->uid(), $f, 'browser', is_numeric($act) ? (int) $act : null)]); }

  public function index(): Response {
    $u = $this->user();
    $target = $this->request->int('user', (int) $u['id']);
    if (!Monitoring::canView($u, $target)) { Audit::log('access.denied', 'screenshot', null, ['owner' => $target]); $this->abort(403, 'You cannot view this person\'s screenshots.'); }
    $date = $this->dateParam('date', '');
    $sql = 'SELECT s.id, s.taken_at, s.source, s.activity_pct, p.name AS project_name, te.description FROM screenshots s LEFT JOIN time_entries te ON te.id=s.time_entry_id LEFT JOIN projects p ON p.id=te.project_id WHERE s.user_id=?';
    $p = [$target];
    if ($date !== '') { $sql .= ' AND s.taken_at>=? AND s.taken_at<?'; array_push($p, $date.' 00:00:00', date('Y-m-d', strtotime($date.' +1 day')).' 00:00:00'); }
    $page = DB::paginate($sql.' ORDER BY s.taken_at DESC', $p, 24);
    $people = $this->can('attendance.screenshots.view') ? Team::people($u, true) : [];
    if ($target !== (int) $u['id']) { Audit::log('monitoring.view', 'user', $target, ['date' => $date]); }
    return $this->view('attendance::screenshots/index', ['title' => 'Screenshots', 'p' => $page, 'target' => $target, 'targetName' => DB::value('SELECT name FROM users WHERE id=?', [$target]), 'date' => $date, 'people' => $people,
      'retention' => Monitoring::retention(), 'enabled' => Monitoring::enabled(), 'styles' => ['css/attendance.css'], 'scripts' => ['js/attendance.js']]); }

  public function image(int $id): Response {
    $u = $this->user();
    $s = DB::first('SELECT id, user_id, disk_path, taken_at FROM screenshots WHERE id=? LIMIT 1', [$id]) ?? $this->abort(404, 'Screenshot not found.');
    if (!Monitoring::canView($u, (int) $s['user_id'])) { Audit::log('access.denied', 'screenshot', $id); $this->abort(403, 'You cannot view this screenshot.'); }
    $path = Storage::path($s['disk_path']);
    return Response::file($path, 'screenshot-'.date('Ymd-His', strtotime($s['taken_at'])).'.'.pathinfo($path, PATHINFO_EXTENSION), null, true); } }
