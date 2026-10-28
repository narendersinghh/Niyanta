<?php
namespace Modules\Attendance\Controllers;
use Core\{Audit, Auth, DB, Notify, Response};
use Modules\Attendance\Services\{Team, TimeService as T};

/** Weekly timesheets: own summary & submission; approvals scoped to the approver's team. */
final class TimesheetController extends BaseController {
  private function summary(int $uid, string $ws): array {
    $we = date('Y-m-d', strtotime($ws.' +7 days'));
    $rows = DB::all('SELECT DATE(te.started_at) AS d, te.project_id, p.name AS project_name, p.color, te.billable, te.status, SUM(CASE WHEN te.is_running=1 THEN GREATEST(0, TIMESTAMPDIFF(SECOND, te.started_at, NOW()) - te.discarded_seconds) ELSE te.duration_seconds END) AS secs, COUNT(*) AS n, MAX(te.is_running) AS running FROM time_entries te LEFT JOIN projects p ON p.id=te.project_id WHERE te.user_id=? AND te.started_at>=? AND te.started_at<? GROUP BY d, te.project_id, p.name, p.color, te.billable, te.status', [$uid, $ws.' 00:00:00', $we.' 00:00:00']);
    $s = ['days' => array_fill_keys(array_map(static fn ($i) => date('Y-m-d', strtotime($ws." +$i days")), range(0, 6)), 0), 'projects' => [], 'total' => 0, 'billable' => 0, 'status' => [], 'running' => false];
    foreach ($rows as $r) {
      $sec = (int) $r['secs'];
      $s['days'][$r['d']] = ($s['days'][$r['d']] ?? 0) + $sec;
      $k = (int) $r['project_id'];
      $s['projects'][$k] ??= ['name' => $r['project_name'] ?? 'No project', 'color' => $r['color'] ?: 'gray', 'total' => 0, 'billable' => 0, 'days' => []];
      $s['projects'][$k]['total'] += $sec;
      $s['projects'][$k]['days'][$r['d']] = ($s['projects'][$k]['days'][$r['d']] ?? 0) + $sec;
      if ($r['billable']) { $s['projects'][$k]['billable'] += $sec; $s['billable'] += $sec; }
      $s['total'] += $sec;
      $s['status'][$r['status']] = ($s['status'][$r['status']] ?? 0) + (int) $r['n'];
      if ($r['running']) { $s['running'] = true; } }
    uasort($s['projects'], static fn ($a, $b) => $b['total'] <=> $a['total']);
    $s['attendance'] = DB::pairs('SELECT work_date, worked_seconds FROM attendance WHERE user_id=? AND work_date BETWEEN ? AND ?', [$uid, $ws, date('Y-m-d', strtotime($ws.' +6 days'))]);
    return $s; }

  public function index(): Response {
    $u = $this->user();
    $ws = $this->weekParam();
    $recent = DB::all('SELECT t.*, r.name AS reviewer FROM timesheets t LEFT JOIN users r ON r.id=t.reviewed_by WHERE t.user_id=? ORDER BY t.week_start DESC LIMIT 8', [$u['id']]);
    $pending = $this->can('attendance.timesheets.approve') ? $this->pendingCount($u) : 0;
    return $this->view('attendance::timesheets/index', ['title' => 'Timesheets', 'ws' => $ws, 's' => $this->summary((int) $u['id'], $ws), 'ts' => DB::first('SELECT t.*, r.name AS reviewer FROM timesheets t LEFT JOIN users r ON r.id=t.reviewed_by WHERE t.user_id=? AND t.week_start=?', [$u['id'], $ws]),
      'entries' => T::weekEntries((int) $u['id'], $ws), 'recent' => $recent, 'pending' => $pending, 'styles' => ['css/attendance.css'], 'scripts' => ['js/attendance.js']]); }

  public function submit(): Response {
    $u = $this->user(); $uid = (int) $u['id'];
    $d = $this->validate(['week' => 'required|date']);
    $ws = T::weekStart($d['week']);
    if ($ws > date('Y-m-d')) { $this->flash('You cannot submit a future week.', 'error'); return $this->back(); }
    return $this->act(function () use ($uid, $ws, $u): string {
      $id = DB::transaction(static function () use ($uid, $ws): int {
        \Modules\Attendance\Services\AttendanceService::lock($uid);
        if (DB::value('SELECT 1 FROM time_entries WHERE user_id=? AND is_running=1 LIMIT 1', [$uid])) { throw new \DomainException('Stop your running timer before submitting the week.'); }
        $range = [$uid, $ws.' 00:00:00', date('Y-m-d', strtotime($ws.' +7 days')).' 00:00:00'];
        $open = (int) DB::value("SELECT COUNT(*) FROM time_entries WHERE user_id=? AND started_at>=? AND started_at<? AND status IN ('draft','rejected')", $range);
        if (!$open) { throw new \DomainException('There are no draft entries to submit for this week.'); }
        $tot = DB::first('SELECT COALESCE(SUM(duration_seconds),0) AS total, COALESCE(SUM(CASE WHEN billable=1 THEN duration_seconds ELSE 0 END),0) AS billable FROM time_entries WHERE user_id=? AND started_at>=? AND started_at<?', $range);
        DB::query("INSERT INTO timesheets (user_id, week_start, total_seconds, billable_seconds, status, submitted_at) VALUES (?,?,?,?,'submitted',NOW()) ON DUPLICATE KEY UPDATE total_seconds=VALUES(total_seconds), billable_seconds=VALUES(billable_seconds), status='submitted', submitted_at=NOW(), reviewed_by=NULL, reviewed_at=NULL, review_note=NULL", [$uid, $ws, $tot['total'], $tot['billable']]);
        $id = (int) DB::value('SELECT id FROM timesheets WHERE user_id=? AND week_start=?', [$uid, $ws]);
        DB::query("UPDATE time_entries SET status='submitted', timesheet_id=? WHERE user_id=? AND started_at>=? AND started_at<? AND status IN ('draft','rejected')", [$id, ...$range]);
        DB::query('UPDATE time_entries SET timesheet_id=? WHERE user_id=? AND started_at>=? AND started_at<? AND timesheet_id IS NULL', [$id, ...$range]);
        return $id; });
      Audit::log('timesheet.submit', 'timesheet', $id, ['week' => $ws]);
      $title = $u['name'].' submitted a timesheet'; $body = 'Week of '.fdate($ws).' is waiting for your approval.'; $url = '/timesheets/'.$id;
      $mgr = (int) ($u['manager_id'] ?? 0);
      $to = $mgr ? [$mgr] : array_map('intval', DB::column("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE u.status='active' AND r.slug IN ('hr','super_admin') AND u.id<>?", [$uid]));
      foreach ($to as $m) { Notify::user($m, $title, $body, $url, false, 'calendar'); }
      return 'Week submitted for approval.'; }); }

  private function pendingCount(array $u): int {
    [$w, $p] = Team::where($u, 't.user_id');
    return (int) DB::value("SELECT COUNT(*) FROM timesheets t WHERE t.status='submitted' AND t.user_id<>? AND $w", [$u['id'], ...$p]); }

  public function approvals(): Response {
    $u = $this->user();
    $status = in_array($s = $this->request->str('status', 'submitted'), ['submitted', 'approved', 'rejected', 'all'], true) ? $s : 'submitted';
    [$w, $p] = Team::where($u, 't.user_id');
    $sql = "SELECT t.*, u.name, u.avatar, u.designation, r.name AS reviewer FROM timesheets t JOIN users u ON u.id=t.user_id LEFT JOIN users r ON r.id=t.reviewed_by WHERE t.user_id<>? AND $w";
    $params = [$u['id'], ...$p];
    if ($status !== 'all') { $sql .= ' AND t.status=?'; $params[] = $status; }
    if ($q = $this->request->str('q')) { $sql .= ' AND u.name LIKE ?'; $params[] = DB::like($q); }
    $page = DB::paginate($sql.' ORDER BY t.status=\'submitted\' DESC, t.week_start DESC, u.name', $params, 20);
    return $this->view('attendance::timesheets/approvals', ['title' => 'Timesheet approvals', 'p' => $page, 'status' => $status, 'pending' => $this->pendingCount($u), 'styles' => ['css/attendance.css']]); }

  private function visible(int $id): array {
    $u = $this->user();
    $t = DB::first('SELECT t.*, u.name, u.avatar, u.designation, u.manager_id, r.name AS reviewer FROM timesheets t JOIN users u ON u.id=t.user_id LEFT JOIN users r ON r.id=t.reviewed_by WHERE t.id=? LIMIT 1', [$id]) ?? $this->abort(404, 'Timesheet not found.');
    $own = (int) $t['user_id'] === (int) $u['id'];
    if (!$own && !($this->can('attendance.timesheets.approve') && Team::includes($u, (int) $t['user_id']))) { Audit::log('access.denied', 'timesheet', $id); $this->abort(403, 'You cannot view this timesheet.'); }
    $t['can_review'] = !$own && $this->can('attendance.timesheets.approve') && Team::includes($u, (int) $t['user_id']);
    return $t; }

  public function show(int $id): Response {
    $t = $this->visible($id);
    return $this->view('attendance::timesheets/show', ['title' => 'Timesheet · '.$t['name'], 't' => $t, 's' => $this->summary((int) $t['user_id'], $t['week_start']), 'entries' => T::weekEntries((int) $t['user_id'], $t['week_start']), 'styles' => ['css/attendance.css'], 'scripts' => ['js/attendance.js']]); }

  public function approve(int $id): Response { return $this->review($id, 'approved'); }
  public function reject(int $id): Response { return $this->review($id, 'rejected'); }

  private function review(int $id, string $to): Response {
    $t = $this->visible($id);
    if (!$t['can_review']) { $this->abort(403, 'You cannot review your own timesheet.'); }
    $d = $this->validate(['note' => ($to === 'rejected' ? 'required' : 'nullable').'|max:500'], ['note' => 'Note']);
    return $this->act(function () use ($t, $id, $to, $d): string {
      DB::transaction(function () use ($id, $to, $d): void {
        $cur = DB::value('SELECT status FROM timesheets WHERE id=? FOR UPDATE', [$id]);
        if ($cur !== 'submitted') { throw new \DomainException('Only submitted timesheets can be reviewed.'); }
        DB::update('timesheets', ['status' => $to, 'reviewed_by' => $this->uid(), 'reviewed_at' => date('Y-m-d H:i:s'), 'review_note' => $d['note'] ?: null], 'id=?', [$id]);
        DB::query("UPDATE time_entries SET status=? WHERE timesheet_id=? AND status='submitted'", [$to, $id]); });
      Audit::log('timesheet.'.($to === 'approved' ? 'approve' : 'reject'), 'timesheet', $id, ['user_id' => $t['user_id'], 'week' => $t['week_start'], 'note' => $d['note']]);
      Notify::user((int) $t['user_id'], 'Timesheet '.$to, 'Your timesheet for the week of '.fdate($t['week_start']).' was '.$to.' by '.Auth::user()['name'].($d['note'] ? ': '.$d['note'] : '.'), '/timesheets?week='.$t['week_start'], true, $to === 'approved' ? 'check-circle' : 'alert');
      return 'Timesheet '.$to.'.'; }, '/timesheets/approvals'); } }
