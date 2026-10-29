<?php
namespace Modules\Hr\Controllers;
use Core\{Audit, Controller, DB, Response, ValidationException};
use Modules\Hr\Services\LeaveService;

/** Fixed holidays (company-wide days off) and optional festival days taken from the Optional Holiday (OH) pool. */
final class HolidayController extends Controller {
  public function index(): Response {
    $year = max(2000, min(2100, $this->request->int('year', (int) date('Y'))));
    $rows = DB::all('SELECT * FROM holidays WHERE holiday_date BETWEEN ? AND ? ORDER BY holiday_date, name', ["$year-01-01", "$year-12-31"]);
    $byDate = []; $fixed = []; $optional = [];
    foreach ($rows as $h) { $byDate[$h['holiday_date']][] = $h; if ((int) $h['optional']) { $optional[] = $h; } else { $fixed[] = $h; } }
    $next = DB::first('SELECT * FROM holidays WHERE holiday_date>=? AND optional=0 ORDER BY holiday_date LIMIT 1', [date('Y-m-d')]);
    $uid = $this->uid();
    $oh = LeaveService::optionalType();
    $bal = null; $taken = [];
    if ($oh && $this->can('hr.leave.request')) {
      $bal = array_column(LeaveService::balances($uid, $year), null, 'id')[(int) $oh['id']] ?? null;
      foreach (DB::all("SELECT start_date, end_date, status FROM leave_requests WHERE user_id=? AND status IN ('pending','approved') AND start_date<=? AND end_date>=?", [$uid, "$year-12-31", "$year-01-01"]) as $r) {
        for ($t = strtotime($r['start_date']), $e = strtotime($r['end_date']); $t <= $e; $t = strtotime('+1 day', $t)) { $taken[date('Y-m-d', $t)] = $r['status']; } } }
    return $this->view('hr::holidays/index', ['title' => 'Holidays', 'year' => $year, 'rows' => $rows, 'fixed' => $fixed, 'optional' => $optional, 'byDate' => $byDate, 'next' => $next, 'oh' => $oh, 'bal' => $bal, 'taken' => $taken,
      'offDays' => LeaveService::scheduleFor($uid)['off_days'], 'canRequest' => $this->can('hr.leave.request'), 'styles' => ['css/hr.css'], 'scripts' => ['js/hr.js'], 'manage' => $this->can('hr.leave.manage'),
      'weekStart' => (int) setting('general.week_start', 1) === 7 ? 7 : 1]); }

  private function data(?int $id): array {
    $d = $this->validate(['holiday_date' => 'required|date', 'name' => 'required|max:150', 'optional' => 'boolean'], ['holiday_date' => 'Date']);
    $dup = DB::value('SELECT id FROM holidays WHERE holiday_date=? AND name=?'.($id ? ' AND id<>?' : ''), $id ? [$d['holiday_date'], $d['name'], $id] : [$d['holiday_date'], $d['name']]);
    if ($dup) { throw new ValidationException(['name' => 'This holiday already exists on that date.']); }
    return $d; }

  public function store(): Response {
    $d = $this->data(null);
    $id = DB::insert('holidays', $d);
    Audit::log('hr.holiday.create', 'holiday', $id, $d);
    $this->flash(($d['optional'] ? 'Optional festival day' : 'Fixed holiday').' added.');
    return $this->redirect('/hr/holidays?year='.substr($d['holiday_date'], 0, 4)); }

  public function update(int $id): Response {
    $this->findOr404('holidays', $id);
    $d = $this->data($id);
    DB::update('holidays', $d, 'id=?', [$id]);
    Audit::log('hr.holiday.update', 'holiday', $id, $d);
    $this->flash('Holiday updated.');
    return $this->redirect('/hr/holidays?year='.substr($d['holiday_date'], 0, 4)); }

  public function destroy(int $id): Response {
    $h = $this->findOr404('holidays', $id);
    DB::delete('holidays', 'id=?', [$id]);
    Audit::log('hr.holiday.delete', 'holiday', $id, ['name' => $h['name'], 'date' => $h['holiday_date']]);
    $this->flash('Holiday removed.');
    return $this->redirect('/hr/holidays?year='.substr($h['holiday_date'], 0, 4)); } }
