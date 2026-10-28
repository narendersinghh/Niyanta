<?php
namespace Modules\Hr\Controllers;
use Core\{Audit, Controller, DB, Response, ValidationException};

/** Public / optional holidays per year. */
final class HolidayController extends Controller {
  public function index(): Response {
    $year = max(2000, min(2100, $this->request->int('year', (int) date('Y'))));
    $rows = DB::all('SELECT * FROM holidays WHERE holiday_date BETWEEN ? AND ? ORDER BY holiday_date, name', ["$year-01-01", "$year-12-31"]);
    $byDate = [];
    foreach ($rows as $h) { $byDate[$h['holiday_date']][] = $h; }
    $next = DB::first('SELECT * FROM holidays WHERE holiday_date>=? AND optional=0 ORDER BY holiday_date LIMIT 1', [date('Y-m-d')]);
    return $this->view('hr::holidays/index', ['title' => 'Holidays', 'year' => $year, 'rows' => $rows, 'byDate' => $byDate, 'next' => $next, 'styles' => ['css/hr.css'], 'scripts' => ['js/hr.js'], 'manage' => $this->can('hr.leave.manage'),
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
    $this->flash('Holiday added.');
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
