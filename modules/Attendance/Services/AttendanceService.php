<?php
namespace Modules\Attendance\Services;
use Core\{Audit, Auth, DB, Modules, Settings};

/** Clock-in/out & break logic. All timestamps are taken on the server; per-user actions are serialised with a row lock. */
final class AttendanceService {
  private static function hr(): bool { return Modules::enabled('hr') && class_exists(\Modules\Hr\Services\LeaveService::class) && method_exists(\Modules\Hr\Services\LeaveService::class, 'scheduleFor'); }

  /** Target work seconds per day: the employee's own hours (HR schedule) or the company setting. Defaults to the signed-in user. */
  public static function workSeconds(?int $uid = null): int {
    $uid ??= Auth::id();
    $h = $uid && self::hr() ? (float) \Modules\Hr\Services\LeaveService::scheduleFor((int) $uid)['hours_per_day'] : (float) Settings::get('hr.work_hours_per_day', 8);
    return max(3600, (int) round($h * 3600)); }

  /** Weekly off days of an employee (ISO-N); company weekend when HR is off or no user is given. */
  public static function offDays(?int $uid = null): array {
    $uid ??= Auth::id();
    return $uid && self::hr() ? \Modules\Hr\Services\LeaveService::scheduleFor((int) $uid)['off_days'] : self::weekendDays(); }
  /** Share of a working day for the user on a date (0 off, 0.5 half day, 1 full), before holidays and leave. */
  public static function dayWeight(?int $uid, string $date): float {
    $uid ??= Auth::id();
    if ($uid && self::hr()) { $s = \Modules\Hr\Services\LeaveService::scheduleFor((int) $uid); return \Core\WorkWeek::weight($date, $s['off_days'], $s['saturday']); }
    return \Core\WorkWeek::weight($date, self::weekendDays(), \Core\WorkWeek::companyRule()); }

  public static function lock(int $uid): void { DB::value('SELECT id FROM users WHERE id=? FOR UPDATE', [$uid]); }

  public static function today(int $uid, ?string $date = null): ?array {
    $r = DB::first('SELECT * FROM attendance WHERE user_id=? AND work_date=? LIMIT 1', [$uid, $date ?? date('Y-m-d')]);
    if ($r) { $r['open_break'] = DB::first('SELECT * FROM attendance_breaks WHERE attendance_id=? AND end_at IS NULL ORDER BY id DESC LIMIT 1', [$r['id']]); }
    return $r; }

  /** out | in | break | done */
  public static function state(?array $r): string {
    if (!$r || !$r['clock_in']) { return 'out'; }
    if ($r['clock_out']) { return 'done'; }
    return empty($r['open_break']) ? 'in' : 'break'; }

  /**
   * Seconds tracked in time entries that started on $date (running entries up to now, discarded idle excluded), or null
   * when the day has no entries. The tracker's day is the attendance day: only tracked time counts as worked time.
   */
  public static function trackedSeconds(int $uid, string $date): ?int {
    $r = DB::first('SELECT COUNT(*) AS n, COALESCE(SUM(CASE WHEN is_running=1 THEN GREATEST(0, TIMESTAMPDIFF(SECOND, started_at, NOW()) - discarded_seconds) ELSE duration_seconds END),0) AS s FROM time_entries WHERE user_id=? AND started_at>=? AND started_at<?', [$uid, $date.' 00:00:00', date('Y-m-d', strtotime($date.' +1 day')).' 00:00:00']);
    return (int) $r['n'] ? (int) $r['s'] : null; }

  /** Live worked & break seconds (open days computed up to now). Worked time is the tracked time when the day has entries. */
  public static function live(?array $r, ?int $now = null): array {
    if (!$r || !$r['clock_in']) { return ['worked' => 0, 'break' => 0]; }
    if ($r['clock_out']) { return ['worked' => (int) $r['worked_seconds'], 'break' => (int) $r['break_seconds']]; }
    $now ??= time();
    $br = (int) $r['break_seconds'] + (empty($r['open_break']) ? 0 : max(0, $now - strtotime($r['open_break']['start_at'])));
    $tracked = isset($r['user_id'], $r['work_date']) ? self::trackedSeconds((int) $r['user_id'], $r['work_date']) : null;
    return ['worked' => $tracked ?? max(0, $now - strtotime($r['clock_in']) - $br), 'break' => $br]; }

  public static function clockIn(int $uid, string $ip, ?string $at = null): string {
    return DB::transaction(static function () use ($uid, $ip, $at): string {
      self::lock($uid);
      $today = date('Y-m-d'); $now = $at ?? date('Y-m-d H:i:s');
      foreach (DB::all('SELECT * FROM attendance WHERE user_id=? AND clock_out IS NULL AND clock_in IS NOT NULL AND work_date<?', [$uid, $today]) as $old) { self::autoCloseRow($old); }
      $r = self::today($uid);
      $st = self::state($r);
      if ($st === 'in' || $st === 'break') { throw new \DomainException('You are already clocked in.'); }
      if ($st === 'done') {
        DB::insert('attendance_breaks', ['attendance_id' => $r['id'], 'start_at' => $r['clock_out'], 'end_at' => $now]);
        DB::update('attendance', ['clock_out' => null, 'ip_out' => null, 'status' => in_array($r['status'], ['present', 'half_day'], true) ? 'present' : $r['status']], 'id=?', [$r['id']]);
        self::recompute((int) $r['id']);
        Audit::log('attendance.resume', 'attendance', (int) $r['id'], [], $uid);
        return 'Welcome back! Your time away was recorded as a break.'; }
      if ($r) { DB::update('attendance', ['clock_in' => $now, 'status' => 'present', 'ip_in' => $ip], 'id=?', [$r['id']]); $id = (int) $r['id']; }
      else { $id = DB::insert('attendance', ['user_id' => $uid, 'work_date' => $today, 'clock_in' => $now, 'status' => 'present', 'ip_in' => $ip]); }
      Audit::log('attendance.clock_in', 'attendance', $id, [], $uid);
      return 'Clocked in at '.ftime($now).'. Have a great day!'; }); }

  public static function startBreak(int $uid, ?string $at = null): string {
    return DB::transaction(static function () use ($uid, $at): string {
      self::lock($uid);
      $r = self::today($uid);
      if (self::state($r) !== 'in') { throw new \DomainException(self::state($r) === 'break' ? 'You are already on a break.' : 'Clock in before starting a break.'); }
      DB::insert('attendance_breaks', ['attendance_id' => $r['id'], 'start_at' => $at ?? date('Y-m-d H:i:s')]);
      Audit::log('attendance.break_start', 'attendance', (int) $r['id'], [], $uid);
      return 'Break started. Enjoy!'; }); }

  public static function endBreak(int $uid, ?string $at = null): string {
    return DB::transaction(static function () use ($uid, $at): string {
      self::lock($uid);
      $r = self::today($uid);
      if (self::state($r) !== 'break') { throw new \DomainException('You are not on a break.'); }
      DB::update('attendance_breaks', ['end_at' => max($at ?? date('Y-m-d H:i:s'), $r['open_break']['start_at'])], 'id=?', [$r['open_break']['id']]);
      self::recompute((int) $r['id']);
      Audit::log('attendance.break_end', 'attendance', (int) $r['id'], [], $uid);
      return 'Welcome back, break ended.'; }); }

  public static function clockOut(int $uid, string $ip, ?string $at = null): string {
    return DB::transaction(static function () use ($uid, $ip, $at): string {
      self::lock($uid);
      $r = self::today($uid) ?? DB::first('SELECT * FROM attendance WHERE user_id=? AND clock_out IS NULL AND clock_in IS NOT NULL ORDER BY work_date DESC LIMIT 1', [$uid]);
      if ($r && !array_key_exists('open_break', $r)) { $r['open_break'] = DB::first('SELECT * FROM attendance_breaks WHERE attendance_id=? AND end_at IS NULL LIMIT 1', [$r['id']]); }
      if (!in_array(self::state($r), ['in', 'break'], true)) { throw new \DomainException('You are not clocked in.'); }
      $now = max($at ?? date('Y-m-d H:i:s'), $r['clock_in']);
      $stopped = TimeService::stop($uid, $now, 'clock-out');
      self::closeRow($r, $now);
      DB::update('attendance', ['ip_out' => $ip], 'id=?', [$r['id']]);
      Audit::log('attendance.clock_out', 'attendance', (int) $r['id'], ['timer_stopped' => (bool) $stopped], $uid);
      $w = (int) DB::value('SELECT worked_seconds FROM attendance WHERE id=?', [$r['id']]);
      return 'Day ended. You worked '.hours($w).' today.'; }); }

  /** Recalculate break & worked seconds (and present/half-day) from the stored breaks. */
  public static function recompute(int $id): void {
    $r = DB::find('attendance', $id);
    if (!$r) { return; }
    $br = (int) DB::value('SELECT COALESCE(SUM(GREATEST(0, TIMESTAMPDIFF(SECOND, start_at, end_at))),0) FROM attendance_breaks WHERE attendance_id=? AND end_at IS NOT NULL', [$id]);
    $d = ['break_seconds' => $br, 'worked_seconds' => 0];
    if ($r['clock_in'] && $r['clock_out']) {
      $d['worked_seconds'] = self::trackedSeconds((int) $r['user_id'], $r['work_date']) ?? max(0, strtotime($r['clock_out']) - strtotime($r['clock_in']) - $br);
      if (in_array($r['status'], ['present', 'half_day'], true)) { $d['status'] = $d['worked_seconds'] < self::workSeconds((int) $r['user_id']) / 2 ? 'half_day' : 'present'; } }
    DB::update('attendance', $d, 'id=?', [$id]); }

  /** After a manual time change: recompute a closed, not HR-edited day so its worked time stays equal to the tracked time. */
  public static function resync(int $uid, string $date): void {
    $id = DB::value('SELECT id FROM attendance WHERE user_id=? AND work_date=? AND clock_out IS NOT NULL AND edited_by IS NULL', [$uid, $date]);
    if ($id) { self::recompute((int) $id); } }

  public static function closeRow(array $r, string $at, ?string $note = null): void {
    DB::delete('attendance_breaks', 'attendance_id=? AND end_at IS NULL AND start_at>=?', [$r['id'], $at]);
    DB::query('UPDATE attendance_breaks SET end_at=? WHERE attendance_id=? AND end_at IS NULL', [$at, $r['id']]);
    $d = ['clock_out' => $at];
    if ($note) { $d['note'] = mb_substr(trim(($r['note'] ? $r['note'].' · ' : '').$note), 0, 255); }
    DB::update('attendance', $d, 'id=?', [$r['id']]);
    self::recompute((int) $r['id']); }

  /**
   * Close a forgotten day: at the end of its last tracked entry when the day was tracked, otherwise at clock-in + work hours
   * + breaks (or at the start of a never-ended break); never past the end of that day (or now).
   */
  public static function autoCloseRow(array $r): void {
    $in = strtotime($r['clock_in']);
    $last = DB::value('SELECT MAX(COALESCE(ended_at, last_heartbeat_at, started_at)) FROM time_entries WHERE user_id=? AND started_at>=? AND started_at<?', [$r['user_id'], $r['work_date'].' 00:00:00', date('Y-m-d', strtotime($r['work_date'].' +1 day')).' 00:00:00']);
    $end = min($last ? strtotime($last) : $in + self::workSeconds((int) $r['user_id']) + (int) $r['break_seconds'], strtotime($r['work_date'].' 23:59:59'), time());
    $open = DB::value('SELECT MIN(start_at) FROM attendance_breaks WHERE attendance_id=? AND end_at IS NULL', [$r['id']]);
    if ($open && !$last) { $end = min($end, strtotime($open)); }
    self::closeRow($r, date('Y-m-d H:i:s', max($in, $end)), 'auto-closed'); }

  /**
   * Cron (23:55): stop stale timers at their last heartbeat and close open days before today. Today is not closed here: the
   * day ends at midnight, where a running entry continues into the new day (Tracker::settle(), run by the app's next call and
   * by the stale_timers job every 5 minutes, which also closes the day once it is over).
   */
  public static function autoClose(): string { return TimeService::staleTimers(); }

  /** HR/manager edit or create of a record (audit-logged, edited_by set). */
  public static function saveManual(array $d, int $editor): int {
    return DB::transaction(static function () use ($d, $editor): int {
      $uid = (int) $d['user_id'];
      self::lock($uid);
      $r = DB::first('SELECT * FROM attendance WHERE user_id=? AND work_date=? LIMIT 1', [$uid, $d['work_date']]);
      $in = $d['clock_in'] ? strtotime($d['work_date'].' '.substr($d['clock_in'], 0, 5).':00') : null;
      $out = $d['clock_out'] ? strtotime($d['work_date'].' '.substr($d['clock_out'], 0, 5).':00') : null;
      if ($out && !$in) { throw new \DomainException('Clock-in time is required when clock-out is set.'); }
      if ($in && $out && $out <= $in) { $out += 86400; }
      if ($in && $in > time()) { throw new \DomainException('Clock-in cannot be in the future.'); }
      $break = max(0, (int) $d['break_minutes']) * 60;
      if ($in && $out && $break >= $out - $in) { throw new \DomainException('Breaks cannot be longer than the time between clock-in and clock-out.'); }
      $row = ['clock_in' => $in ? date('Y-m-d H:i:s', $in) : null, 'clock_out' => $out ? date('Y-m-d H:i:s', $out) : null, 'status' => $d['status'], 'note' => $d['note'] ?: null, 'edited_by' => $editor,
        'break_seconds' => $in ? $break : 0, 'worked_seconds' => $in && $out ? $out - $in - $break : 0];
      if ($r) { DB::update('attendance', $row, 'id=?', [$r['id']]); $id = (int) $r['id']; }
      else { $id = DB::insert('attendance', $row + ['user_id' => $uid, 'work_date' => $d['work_date']]); }
      DB::delete('attendance_breaks', 'attendance_id=?', [$id]);
      if ($in && $break) {
        $start = $out ? $in + intdiv($out - $in - $break, 2) : $in;
        DB::insert('attendance_breaks', ['attendance_id' => $id, 'start_at' => date('Y-m-d H:i:s', $start), 'end_at' => date('Y-m-d H:i:s', $start + $break)]); }
      $before = $r ? array_intersect_key($r, array_flip(['clock_in', 'clock_out', 'break_seconds', 'status', 'note'])) : null;
      Audit::log($r ? 'attendance.edit' : 'attendance.create', 'attendance', $id, ['user_id' => $uid, 'date' => $d['work_date'], 'before' => $before, 'after' => array_intersect_key($row, array_flip(['clock_in', 'clock_out', 'break_seconds', 'status', 'note']))]);
      return $id; }); }

  public static function weekendDays(): array {
    if (Modules::enabled('hr') && class_exists(\Modules\Hr\Services\LeaveService::class)) { return \Modules\Hr\Services\LeaveService::weekendDays(); }
    return array_map('intval', array_filter(explode(',', (string) Settings::get('general.weekend_days', '6,7')))); }

  public static function holidays(string $from, string $to): array { return DB::pairs('SELECT holiday_date, name FROM holidays WHERE holiday_date BETWEEN ? AND ? AND optional=0 ORDER BY holiday_date', [$from, $to]); }

  public static function isHoliday(string $date): bool {
    if (Modules::enabled('hr') && class_exists(\Modules\Hr\Services\LeaveService::class)) { return \Modules\Hr\Services\LeaveService::isHoliday($date); }
    return (bool) DB::value('SELECT 1 FROM holidays WHERE holiday_date=? AND optional=0 LIMIT 1', [$date]); }

  /** Approved leave per user per day in a range: [uid][Y-m-d] => 1 | 0.5. $uids null = everyone. */
  public static function leaveMap(?array $uids, string $from, string $to): array {
    if ($uids === []) { return []; }
    $sql = "SELECT user_id, start_date, end_date, half_day FROM leave_requests WHERE status='approved' AND start_date<=? AND end_date>=?";
    $p = [$to, $from];
    if ($uids !== null) { $sql .= ' AND user_id IN '.DB::in($uids); array_push($p, ...$uids); }
    $out = [];
    foreach (DB::all($sql, $p) as $l) {
      for ($t = strtotime(max($l['start_date'], $from)), $e = strtotime(min($l['end_date'], $to)); $t <= $e; $t = strtotime('+1 day', $t)) { $out[(int) $l['user_id']][date('Y-m-d', $t)] = $l['half_day'] ? 0.5 : 1; } }
    return $out; }

  /** Calendar for one user's month: days[date] => [status, worked, row], totals. */
  public static function month(array $user, string $ym): array {
    $from = $ym.'-01'; $to = date('Y-m-t', strtotime($from)); $today = date('Y-m-d');
    $uid = (int) $user['id'];
    $rows = [];
    foreach (DB::all('SELECT * FROM attendance WHERE user_id=? AND work_date BETWEEN ? AND ?', [$uid, $from, $to]) as $r) { $rows[$r['work_date']] = $r; }
    $leave = self::leaveMap([$uid], $from, $to)[$uid] ?? [];
    $hol = self::holidays($from, $to);
    $wk = self::offDays($uid);
    $start = $user['joined_on'] ?: substr((string) ($user['created_at'] ?? $from), 0, 10);
    $days = []; $tot = ['present' => 0.0, 'half_day' => 0, 'absent' => 0, 'leave' => 0.0, 'holiday' => 0, 'weekend' => 0, 'worked' => 0, 'days_worked' => 0];
    for ($t = strtotime($from); $t <= strtotime($to); $t = strtotime('+1 day', $t)) {
      $d = date('Y-m-d', $t); $r = $rows[$d] ?? null; $worked = 0;
      if ($r && $r['clock_in']) {
        $worked = $r['clock_out'] ? (int) $r['worked_seconds'] : self::live(self::today($uid, $d))['worked'];
        $st = $r['clock_out'] ? $r['status'] : 'present'; }
      elseif ($r) { $st = $r['status']; }
      elseif (isset($hol[$d])) { $st = 'holiday'; }
      elseif (isset($leave[$d])) { $st = 'leave'; }
      elseif (self::dayWeight($uid, $d) <= 0) { $st = 'weekend'; }
      elseif ($d < $today && $d >= $start) { $st = 'absent'; }
      else { $st = ''; }
      $days[$d] = ['status' => $st, 'worked' => $worked, 'row' => $r, 'holiday' => $hol[$d] ?? null, 'leave' => $leave[$d] ?? null];
      if ($st !== '') { $tot[$st] += $st === 'leave' ? ($leave[$d] ?? 1) : 1; }
      $tot['worked'] += $worked;
      if ($worked > 0) { $tot['days_worked']++; } }
    return ['days' => $days, 'totals' => $tot, 'from' => $from, 'to' => $to]; } }
