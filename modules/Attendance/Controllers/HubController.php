<?php
namespace Modules\Attendance\Controllers;
use Core\{DB, Response};
use Modules\Attendance\Services\{Monitoring, TimeService as T};

/** Time hub views: daily timeline and monthly calendar, for yourself or (attendance.view_all) a team member; the day activity chart. */
final class HubController extends BaseController {
  private static array $meet = [];
  public function daily(): Response {
    [$target, $name, $people] = $this->member('attendance.view_all', 'time.daily');
    $date = min(date('Y-m-d'), $this->dateParam('date', date('Y-m-d')));
    $own = $target === $this->uid();
    $day = self::day($target, $date);
    /* the chart's bars open the day gallery at that slot's first screenshot when the viewer may see them */
    $gallery = []; $first = [];
    if (Monitoring::canView($this->user(), $target)) {
      [$gallery, $first] = self::gallery(self::dayShots($target, $date));
      if ($gallery && !$own) { \Core\Audit::log('monitoring.view', 'user', $target, ['date' => $date, 'area' => 'daily']); } }
    return $this->view('attendance::hub/daily', ['title' => 'Daily timesheet', 'date' => $date, 'target' => $target, 'targetName' => $name, 'people' => $people, 'own' => $own,
      'projects' => $own && $this->can('attendance.track') ? T::projects($this->user()) : [], 'chart' => self::chart($target, $date, $day['entries']), 'gallery' => $gallery, 'shots' => $first] + $day + ['styles' => ['css/attendance.css'], 'scripts' => ['js/attendance.js']]); }

  /** One person's screenshots of a day, oldest first, with the tracked entry's project, task and description. Callers check Monitoring::canView first. */
  public static function dayShots(int $uid, string $date): array {
    return DB::all('SELECT s.id, s.taken_at, s.activity_pct, s.display_index, '.(self::meetingFlag('screenshots') ? 's.meeting' : '0 AS meeting').', p.name AS project_name, t.title AS task_title, te.description FROM screenshots s LEFT JOIN time_entries te ON te.id=s.time_entry_id LEFT JOIN projects p ON p.id=te.project_id LEFT JOIN tasks t ON t.id=te.task_id WHERE s.user_id=? AND s.taken_at>=? AND s.taken_at<? ORDER BY s.taken_at, s.display_index LIMIT 3000', [$uid, $date.' 00:00:00', date('Y-m-d', strtotime($date.' +1 day')).' 00:00:00']); }

  /**
   * The whole-day gallery (partials/shot_viewer): one item per screenshot (self::shot plus id, hour label h, 10-minute slot range r, work label p)
   * and the first item of every slot (slot index hour*6+tens of minutes => item index) so cards and chart bars open it at the right image.
   */
  public static function gallery(array $rows): array {
    $pj = feature_on('projects'); $tf = (string) setting('general.time_format', 'h:i A'); $h24 = str_contains($tf, 'H');
    $hm = static fn (int $m): string => date($tf, mktime(intdiv($m, 60) % 24, $m % 60, 0));
    $items = []; $first = [];
    foreach ($rows as $n => $r) {
      $h = (int) substr($r['taken_at'], 11, 2); $k = intdiv((int) substr($r['taken_at'], 14, 2), 10);
      $first[$h * 6 + $k] ??= $n;
      $sub = ($pj ? $r['task_title'] : null) ?: $r['description'];
      $items[] = ['id' => (int) $r['id']] + self::shot($r) + ['h' => $h24 ? sprintf('%02d:00', $h) : ($h % 12 ?: 12).' '.($h < 12 ? 'AM' : 'PM'), 'r' => $hm($h * 60 + $k * 10).' - '.$hm($h * 60 + $k * 10 + 10),
        'p' => ($pj ? ($r['project_name'] ?? 'No project') : 'Tracked time').($sub ? ' · '.str_limit($sub, 80) : '')]; }
    return [$items, $first]; }

  /** One screenshot for the gallery viewer: image URL, time, activity %, screen number, meeting mode (keystroke and mouse counts are not shown). */
  public static function shot(array $r): array {
    return ['src' => url('/time/screenshots/'.$r['id'].'/image'), 't' => ftime($r['taken_at']), 'a' => $r['activity_pct'] === null ? null : (int) $r['activity_pct'], 'd' => $r['display_index'] === null ? null : (int) $r['display_index'] + 1,
      'g' => !empty($r['meeting'])]; }

  /** Whether $table (activity_slots, screenshots) has the meeting-mode flag (migration 2026_11_07_000100). */
  public static function meetingFlag(string $table): bool { return self::$meet[$table] ??= (bool) DB::value("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME='meeting'", [$table]); }

  /**
   * Activity per 10-minute slot of one person's day (bar chart in the day summary). Activity comes from activity_slots
   * (active_seconds / slot_seconds; meeting slots, when that column exists, are drawn apart and left out of the average),
   * else from the screenshots' activity_pct; tracked minutes per slot from the day's entries. Null when the day is empty.
   * Returns ['slots' => [[i, h, k, pct, lvl, meet, min, range, future]], 'hours' => [[h, label, now]], 'avg', 'src', 'meetings', 'now' (minute of the hour or null), 'from', 'to'].
   */
  public static function chart(int $uid, string $date, array $entries): ?array {
    $from = $date.' 00:00:00'; $to = date('Y-m-d', strtotime($date.' +1 day')).' 00:00:00';
    $idx = static fn (string $dt): int => (int) substr($dt, 11, 2) * 6 + intdiv((int) substr($dt, 14, 2), 10);
    $meet = self::meetingFlag('activity_slots'); $b = [];
    $rows = DB::all('SELECT slot_start, slot_seconds, active_seconds, '.($meet ? 'meeting' : '0').' AS meeting FROM activity_slots WHERE user_id=? AND slot_start>=? AND slot_start<?', [$uid, $from, $to]);
    foreach ($rows as $r) {
      $i = $idx($r['slot_start']); $s = (int) $r['slot_seconds'];
      if ((int) $r['meeting']) { $b[$i]['m'] = ($b[$i]['m'] ?? 0) + $s; continue; }
      $b[$i]['s'] = ($b[$i]['s'] ?? 0) + $s; $b[$i]['a'] = ($b[$i]['a'] ?? 0) + min($s, (int) $r['active_seconds']); }
    if (!$rows) {
      $sm = self::meetingFlag('screenshots');
      foreach (DB::all('SELECT HOUR(taken_at) * 6 + FLOOR(MINUTE(taken_at) / 10) AS i, AVG('.($sm ? 'CASE WHEN meeting=0 THEN activity_pct END' : 'activity_pct').') AS a, '.($sm ? 'MAX(meeting)' : '0').' AS m FROM screenshots WHERE user_id=? AND taken_at>=? AND taken_at<? AND activity_pct IS NOT NULL GROUP BY i', [$uid, $from, $to]) as $r) {
        if ($r['a'] !== null) { $b[(int) $r['i']]['p'] = (float) $r['a']; }
        if ((int) $r['m']) { $b[(int) $r['i']]['m'] = 1; } } }
    $d0 = strtotime($from); $d1 = strtotime($to);
    foreach ($entries as $e) {
      $s = max($d0, strtotime($e['started_at'])); $x = min($d1, strtotime($e['end']));
      for ($i = intdiv($s - $d0, 600); $i < 144 && $d0 + $i * 600 < $x; $i++) { $b[$i]['t'] = ($b[$i]['t'] ?? 0) + min($x, $d0 + ($i + 1) * 600) - max($s, $d0 + $i * 600); } }
    $b = array_filter($b, static fn ($x) => array_sum($x) > 0);
    if (!$b) { return null; }
    $S = array_sum(array_column($b, 's')); $P = array_column($b, 'p');
    $avg = $S ? (int) round(100 * array_sum(array_column($b, 'a')) / $S) : ($P ? (int) round(array_sum($P) / count($P)) : null);
    $today = $date === date('Y-m-d'); $nowI = $today ? $idx(date('Y-m-d H:i:s')) : 999;
    $h0 = intdiv(min(array_keys($b)), 6); $h1 = intdiv(max(array_keys($b)), 6) + 1;
    if ($today) { $h1 = max($h1, (int) date('G') + 1); }
    while ($h1 - $h0 < 6) { if ($h1 < 24) { $h1++; } else { $h0--; } }
    $tf = (string) setting('general.time_format', 'h:i A'); $h24 = str_contains($tf, 'H');
    $lbl = static fn (int $m): string => date($tf, mktime(intdiv($m, 60) % 24, $m % 60, 0));
    $slots = []; $hours = []; $meetings = 0;
    for ($h = $h0; $h < $h1; $h++) { $hours[] = ['h' => $h, 'label' => $h24 ? sprintf('%02d', $h) : ($h % 12 ?: 12).($h === $h0 || $h % 12 === 0 ? ($h < 12 ? ' am' : ' pm') : ''), 'now' => $today && $h === (int) date('G')]; }
    for ($i = $h0 * 6; $i < $h1 * 6; $i++) {
      $x = $b[$i] ?? [];
      $pct = !empty($x['s']) ? (int) round(100 * $x['a'] / $x['s']) : (isset($x['p']) ? (int) round($x['p']) : null);
      $m = !empty($x['m']); $meetings += (int) $m;
      $slots[] = ['i' => $i, 'h' => intdiv($i, 6), 'k' => $i % 6, 'pct' => $pct, 'meet' => $m, 'mpct' => $m ? min(100, (int) round(($x['m'] > 1 ? $x['m'] : ($x['t'] ?? 300)) / 6)) : 0, /* share of the slot in a meeting (screenshots only flag it: tracked share) */ 'lvl' => $pct !== null ? ($pct >= 60 ? 'hi' : ($pct >= 30 ? 'mid' : 'lo')) : ($m ? 'meet' : 'none'),
        'min' => (int) round(($x['t'] ?? 0) / 60), 'range' => $lbl($i * 10).' - '.$lbl($i * 10 + 10), 'future' => $i > $nowI]; }
    return ['slots' => $slots, 'hours' => $hours, 'avg' => $avg, 'src' => $rows ? 'slots' : 'shots', 'meetings' => $meetings, 'now' => $today ? (int) date('i') : null, 'from' => $lbl($h0 * 60), 'to' => $lbl($h1 * 60)]; }

  public function monthly(): Response {
    [$target, $name, $people] = $this->member('attendance.view_all', 'time.monthly');
    $m = (string) $this->request->query('month', '');
    $month = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m) && $m <= date('Y-m') ? $m : date('Y-m');
    $from = $month.'-01'; $to = date('Y-m-d', strtotime($from.' +1 month'));
    $days = DB::pairs('SELECT DATE(started_at) AS d, SUM('.T::secondsSql().') AS s FROM time_entries WHERE user_id=? AND started_at>=? AND started_at<? GROUP BY DATE(started_at)', [$target, $from.' 00:00:00', $to.' 00:00:00']);
    $att = DB::pairs("SELECT work_date, status FROM attendance WHERE user_id=? AND work_date>=? AND work_date<? AND status IN ('leave','holiday','absent','half_day')", [$target, $from, $to]);
    $hol = feature_on('leave') ? DB::pairs('SELECT holiday_date, MIN(name) FROM holidays WHERE holiday_date>=? AND holiday_date<? AND optional=0 GROUP BY holiday_date', [$from, $to]) : [];
    return $this->view('attendance::hub/monthly', ['title' => 'Monthly timesheet', 'month' => $month, 'target' => $target, 'targetName' => $name, 'people' => $people, 'days' => array_map('intval', $days), 'att' => $att, 'hol' => $hol,
      'styles' => ['css/attendance.css'], 'scripts' => ['js/attendance.js']]); } }
