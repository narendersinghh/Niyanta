<?php
namespace Modules\Attendance\Controllers;
use Core\{DB, Response};
use Modules\Attendance\Services\TimeService as T;

/** Time hub views: daily timeline and monthly calendar, for yourself or (attendance.view_all) a team member. */
final class HubController extends BaseController {
  public function daily(): Response {
    [$target, $name, $people] = $this->member('attendance.view_all', 'time.daily');
    $date = min(date('Y-m-d'), $this->dateParam('date', date('Y-m-d')));
    $own = $target === $this->uid();
    return $this->view('attendance::hub/daily', ['title' => 'Daily timesheet', 'date' => $date, 'target' => $target, 'targetName' => $name, 'people' => $people, 'own' => $own,
      'projects' => $own && $this->can('attendance.track') ? T::projects($this->user()) : []] + self::day($target, $date) + ['styles' => ['css/attendance.css'], 'scripts' => ['js/attendance.js']]); }

  public function monthly(): Response {
    [$target, $name, $people] = $this->member('attendance.view_all', 'time.monthly');
    $m = (string) $this->request->query('month', '');
    $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m) && $m <= date('Y-m') ? $m : date('Y-m');
    $from = $month.'-01'; $to = date('Y-m-d', strtotime($from.' +1 month'));
    $days = DB::pairs("SELECT DATE(started_at) AS d, SUM(CASE WHEN is_running=1 THEN GREATEST(0, TIMESTAMPDIFF(SECOND, started_at, NOW()) - discarded_seconds) ELSE duration_seconds END) AS s FROM time_entries WHERE user_id=? AND started_at>=? AND started_at<? GROUP BY DATE(started_at)", [$target, $from.' 00:00:00', $to.' 00:00:00']);
    $att = DB::pairs("SELECT work_date, status FROM attendance WHERE user_id=? AND work_date>=? AND work_date<? AND status IN ('leave','holiday','absent','half_day')", [$target, $from, $to]);
    $hol = feature_on('leave') ? DB::pairs('SELECT holiday_date, MIN(name) FROM holidays WHERE holiday_date>=? AND holiday_date<? AND optional=0 GROUP BY holiday_date', [$from, $to]) : [];
    return $this->view('attendance::hub/monthly', ['title' => 'Monthly timesheet', 'month' => $month, 'target' => $target, 'targetName' => $name, 'people' => $people, 'days' => array_map('intval', $days), 'att' => $att, 'hol' => $hol,
      'styles' => ['css/attendance.css'], 'scripts' => ['js/attendance.js']]); } }
