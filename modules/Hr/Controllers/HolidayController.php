<?php
namespace Modules\Hr\Controllers;
use Core\{Audit, Controller, DB, Response, Session, Settings, ValidationException};
use Modules\Hr\Services\{HolidayService, LeaveService};

/** Fixed holidays (company-wide days off), optional festival days (OH pool or Paid Leave), next-year lists and AI date checks. */
final class HolidayController extends Controller {
  public function index(): Response {
    $year = max(2000, min(2100, $this->request->int('year', (int) date('Y'))));
    $auto = null;
    if ($year === (int) date('Y') + 1 && Settings::bool('hr.holidays.auto_next_year', true) && !DB::value('SELECT 1 FROM holidays WHERE holiday_date BETWEEN ? AND ? LIMIT 1', ["$year-01-01", "$year-12-31"])) {
      $auto = HolidayService::createYear($year, false, $this->uid()); }
    $rows = DB::all('SELECT * FROM holidays WHERE holiday_date BETWEEN ? AND ? ORDER BY holiday_date, name', ["$year-01-01", "$year-12-31"]);
    $byDate = []; $fixed = []; $optional = [];
    foreach ($rows as $h) { $byDate[$h['holiday_date']][] = $h; if ((int) $h['optional']) { $optional[] = $h; } else { $fixed[] = $h; } }
    $next = DB::first('SELECT * FROM holidays WHERE holiday_date>=? AND optional=0 ORDER BY holiday_date LIMIT 1', [date('Y-m-d')]);
    $uid = $this->uid();
    $oh = LeaveService::festivalType();
    $bal = null; $taken = [];
    if ($oh && $this->can('hr.leave.request')) {
      $bal = array_column(LeaveService::balances($uid, $year), null, 'id')[(int) $oh['id']] ?? null;
      foreach (DB::all("SELECT start_date, end_date, status FROM leave_requests WHERE user_id=? AND status IN ('pending','approved') AND start_date<=? AND end_date>=?", [$uid, "$year-12-31", "$year-01-01"]) as $r) {
        for ($t = strtotime($r['start_date']), $e = strtotime($r['end_date']); $t <= $e; $t = strtotime('+1 day', $t)) { $taken[date('Y-m-d', $t)] = $r['status']; } } }
    return $this->view('hr::holidays/index', ['title' => 'Holidays', 'year' => $year, 'rows' => $rows, 'fixed' => $fixed, 'optional' => $optional, 'byDate' => $byDate, 'next' => $next, 'oh' => $oh, 'bal' => $bal, 'taken' => $taken,
      'offDays' => LeaveService::scheduleFor($uid)['off_days'], 'canRequest' => $this->can('hr.leave.request'), 'styles' => ['css/hr.css', 'css/leave.css'], 'scripts' => ['js/hr.js', 'js/leave.js'], 'manage' => $this->can('hr.leave.manage'),
      'weekStart' => (int) setting('general.week_start', 1) === 7 ? 7 : 1, 'auto' => $auto, 'ai' => HolidayService::aiStatus(), 'unverified' => count(array_filter($rows, static fn ($h) => !(int) $h['verified'])),
      'nextCount' => (int) DB::value('SELECT COUNT(*) FROM holidays WHERE holiday_date BETWEEN ? AND ?', [($year + 1).'-01-01', ($year + 1).'-12-31']), 'aiFix' => ($f = Session::get('hr_ai_fix')) && (int) ($f['year'] ?? 0) === $year ? $f : null]); }

  private function data(?int $id): array {
    $d = $this->validate(['holiday_date' => 'required|date', 'name' => 'required|max:150', 'optional' => 'boolean', 'movable' => 'boolean'], ['holiday_date' => 'Date']);
    if (!$this->request->has('movable')) { $d['movable'] = HolidayService::isMovable($d['name']) ? 1 : 0; }
    $d['verified'] = 1;
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
    return $this->redirect('/hr/holidays?year='.substr($h['holiday_date'], 0, 4)); }
  /** Create next year's list from this year's (fixed dates copied, movable festivals from known dates or the AI). */
  public function nextYear(): Response {
    $year = $this->request->int('year', (int) date('Y') + 1);
    if ($year < 2000 || $year > (int) date('Y') + 2) { $this->abort(422, 'Invalid year.'); }
    $r = HolidayService::createYear($year, true, $this->uid());
    if (!$r['source']) { $this->flash('There are no holidays in '.($year - 1).' to copy from. Add them by hand first.', 'error'); return $this->redirect('/hr/holidays?year='.$year); }
    $msg = $r['created'] ? 'Created '.$r['created'].' holiday(s) for '.$year.($r['ai'] ? ', '.$r['ai'].' festival date(s) from AI' : '').'.' : 'All '.($year - 1).' holidays already exist in '.$year.'.';
    if ($r['unverified']) { $msg .= ' '.$r['unverified'].' festival date(s) are copied from last year and marked "Check date"'.($r['error'] ? ' (AI: '.$r['error'].')' : '').'.'; }
    $this->flash($msg);
    return $this->redirect('/hr/holidays?year='.$year); }

  /** Ask the AI for the correct dates of this year's holidays and keep the proposal for review. */
  public function aiFix(): Response {
    $year = max(2000, min(2100, $this->request->int('year', (int) date('Y'))));
    [$ok, $why] = HolidayService::aiStatus();
    if (!$ok) { $this->flash((string) $why, 'error'); return $this->redirect('/hr/holidays?year='.$year); }
    $r = HolidayService::fixPreview($year, $this->uid());
    if ($r['error']) { Session::forget('hr_ai_fix'); $this->flash($r['error'], 'error'); return $this->redirect('/hr/holidays?year='.$year); }
    Session::put('hr_ai_fix', ['year' => $year, 'rows' => $r['rows'], 'at' => date('Y-m-d H:i:s')]);
    return $this->redirect('/hr/holidays?year='.$year.'#ai-fix'); }

  /** Apply the dates HR ticked in the AI proposal. */
  public function aiApply(): Response {
    $year = max(2000, min(2100, $this->request->int('year', (int) date('Y'))));
    $f = Session::get('hr_ai_fix');
    Session::forget('hr_ai_fix');
    if ($this->request->str('discard') !== '') { $this->flash('AI suggestions discarded.'); return $this->redirect('/hr/holidays?year='.$year); }
    if (!$f || (int) $f['year'] !== $year) { $this->flash('That AI check has expired. Run "Fix dates with AI" again.', 'error'); return $this->redirect('/hr/holidays?year='.$year); }
    $pick = array_map('intval', (array) ($this->request->all()['apply'] ?? []));
    $map = [];
    foreach ($f['rows'] as $r) { if ($r['new'] && in_array((int) $r['id'], $pick, true)) { $map[(int) $r['id']] = $r['new']; } }
    $n = HolidayService::applyDates($map, $year, $this->uid());
    $this->flash($n ? "Updated $n holiday date(s) for $year." : 'No dates changed.');
    return $this->redirect('/hr/holidays?year='.$year); } }
