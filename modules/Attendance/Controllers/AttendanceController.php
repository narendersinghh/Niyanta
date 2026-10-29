<?php
namespace Modules\Attendance\Controllers;
use Core\{DB, Response};
use Modules\Attendance\Services\{AttendanceService as A, Team, TimeService};

/** Own attendance (today card + monthly calendar, read-only: the day is started and ended in the desktop tracker), team board, CSV export and HR edits. */
final class AttendanceController extends BaseController {
  public function index(): Response {
    $u = $this->user();
    $ym = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $this->request->query('month', '')) ? $this->request->query('month') : date('Y-m');
    $today = A::today((int) $u['id']);
    $ws = TimeService::weekStart();
    $week = DB::pairs('SELECT work_date, worked_seconds FROM attendance WHERE user_id=? AND work_date BETWEEN ? AND ?', [$u['id'], $ws, date('Y-m-d', strtotime($ws.' +6 days'))]);
    $week[date('Y-m-d')] = A::live($today)['worked'];
    $records = DB::all('SELECT a.*, e.name AS editor FROM attendance a LEFT JOIN users e ON e.id=a.edited_by WHERE a.user_id=? AND a.work_date BETWEEN ? AND ? ORDER BY a.work_date DESC', [$u['id'], $ym.'-01', date('Y-m-t', strtotime($ym.'-01'))]);
    return $this->view('attendance::attendance/index', ['title' => 'Attendance', 'today' => $today, 'state' => A::state($today), 'live' => A::live($today), 'month' => A::month($u, $ym), 'ym' => $ym, 'week' => $week, 'ws' => $ws,
      'records' => $records, 'running' => TimeService::running((int) $u['id']), 'tracker' => \Modules\Attendance\Services\Tracker::state((int) $u['id']), 'onLeave' => (bool) (A::leaveMap([(int) $u['id']], date('Y-m-d'), date('Y-m-d'))[(int) $u['id']] ?? false), 'holiday' => A::isHoliday(date('Y-m-d')),
      'styles' => ['css/attendance.css'], 'scripts' => ['js/attendance.js']]); }

  /** Team board for a date: who is in, on break, out, on leave. */
  public function team(): Response {
    $u = $this->user();
    $date = $this->dateParam('date', date('Y-m-d'));
    $q = $this->request->str('q');
    [$w, $p] = Team::where($u, 'u.id');
    $base = " FROM users u LEFT JOIN attendance a ON a.user_id=u.id AND a.work_date=? WHERE u.status='active' AND $w";
    $sql = 'SELECT u.id, u.name, u.avatar, u.designation, u.department, a.id AS att_id, a.clock_in, a.clock_out, a.break_seconds, a.worked_seconds, a.status, a.note, a.edited_by,'
      .' (SELECT ab.start_at FROM attendance_breaks ab WHERE ab.attendance_id=a.id AND ab.end_at IS NULL ORDER BY ab.id DESC LIMIT 1) AS break_start,'
      .' (SELECT CONCAT(te.started_at, \'|\', COALESCE(p.name, \'\')) FROM time_entries te LEFT JOIN projects p ON p.id=te.project_id WHERE te.user_id=u.id AND te.is_running=1 LIMIT 1) AS timer'.$base;
    $params = [$date, ...$p];
    if ($q !== '') { $sql .= ' AND (u.name LIKE ? OR u.email LIKE ?)'; array_push($params, DB::like($q), DB::like($q)); }
    $page = DB::paginate($sql.' ORDER BY (a.clock_in IS NOT NULL AND a.clock_out IS NULL) DESC, u.name', $params, 25);
    $stats = DB::first('SELECT COUNT(*) AS total, COALESCE(SUM(a.clock_in IS NOT NULL AND a.clock_out IS NULL AND ob.id IS NULL),0) AS working, COALESCE(SUM(ob.id IS NOT NULL),0) AS on_break, COALESCE(SUM(a.clock_out IS NOT NULL),0) AS done'.str_replace(' WHERE ', ' LEFT JOIN attendance_breaks ob ON ob.attendance_id=a.id AND ob.end_at IS NULL WHERE ', $base), [$date, ...$p]);
    $ids = Team::memberIds($u);
    $leave = A::leaveMap($ids, $date, $date);
    $pageLeave = A::leaveMap(array_map('intval', array_column($page['items'], 'id')), $date, $date);
    return $this->view('attendance::attendance/team', ['title' => 'Team attendance', 'p' => $page, 'date' => $date, 'q' => $q, 'stats' => $stats, 'leaveCount' => count($leave), 'pageLeave' => $pageLeave,
      'holiday' => A::holidays($date, $date)[$date] ?? null, 'weekend' => in_array((int) date('N', strtotime($date)), A::weekendDays(), true), 'people' => $this->can('attendance.manage') ? Team::people($u, true) : [],
      'styles' => ['css/attendance.css'], 'scripts' => ['js/attendance.js']]); }

  /** CSV export of team attendance for a date range (max 93 days). */
  public function export(): Response {
    $u = $this->user();
    $from = $this->dateParam('from', date('Y-m-01')); $to = $this->dateParam('to', date('Y-m-d'));
    if ($to < $from) { [$from, $to] = [$to, $from]; }
    if ((strtotime($to) - strtotime($from)) / 86400 > 93) { $this->flash('Export at most 93 days at a time.', 'error'); return $this->back(); }
    [$w, $p] = Team::where($u, 'a.user_id');
    $rows = DB::all("SELECT u.name, u.email, u.employee_code, u.department, a.work_date, a.status, a.clock_in, a.clock_out, a.break_seconds, a.worked_seconds, a.note, e.name AS editor FROM attendance a JOIN users u ON u.id=a.user_id LEFT JOIN users e ON e.id=a.edited_by WHERE a.work_date BETWEEN ? AND ? AND $w ORDER BY a.work_date, u.name", [$from, $to, ...$p]);
    $fh = fopen('php://temp', 'w+');
    fputcsv($fh, ['Date', 'Employee', 'Email', 'Code', 'Department', 'Status', 'Clock in', 'Clock out', 'Break (min)', 'Worked (h)', 'Note', 'Edited by'], ',', '"', '\\');
    $safe = static fn ($v) => is_string($v) && $v !== '' && str_contains('=+-@', $v[0]) ? "'".$v : $v;
    foreach ($rows as $r) { fputcsv($fh, array_map($safe, [$r['work_date'], $r['name'], $r['email'], $r['employee_code'], $r['department'], $r['status'], $r['clock_in'] ? substr($r['clock_in'], 0, 16) : '', $r['clock_out'] ? substr($r['clock_out'], 0, 16) : '', round($r['break_seconds'] / 60), round($r['worked_seconds'] / 3600, 2), $r['note'], $r['editor']]), ',', '"', '\\'); }
    rewind($fh);
    $csv = (string) stream_get_contents($fh);
    fclose($fh);
    \Core\Audit::log('attendance.export', null, null, ['from' => $from, 'to' => $to, 'rows' => count($rows)]);
    return Response::download($csv, "attendance-$from-to-$to.csv", 'text/csv; charset=utf-8'); }

  /** Create or edit a record (attendance.manage, target must be in scope). */
  public function save(): Response {
    $u = $this->user();
    $d = $this->validate(['user_id' => 'required|integer|exists:users,id', 'work_date' => 'required|date', 'clock_in' => 'nullable|time', 'clock_out' => 'nullable|time', 'break_minutes' => 'nullable|integer|min:0|max:1440', 'status' => 'required|in:present,half_day,absent,leave,holiday,weekend', 'note' => 'nullable|max:255'], ['user_id' => 'Employee']);
    if (!Team::includes($u, (int) $d['user_id'])) { $this->abort(403, 'That employee is outside your team.'); }
    if ($d['work_date'] > date('Y-m-d')) { $this->flash('You cannot create attendance for a future date.', 'error'); return $this->back(); }
    return $this->act(static function () use ($d, $u): string { A::saveManual($d, (int) $u['id']); return 'Attendance record saved.'; }); } }
