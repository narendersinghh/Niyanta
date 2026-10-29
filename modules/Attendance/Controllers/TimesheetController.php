<?php
namespace Modules\Attendance\Controllers;
use Core\{Audit, DB, Response};
use Modules\Attendance\Services\{Team, Timesheets as TS, TimeService as T};

/** Weekly timesheets, approved automatically: own week grid, resubmit after a rejection, and team review (reject / re-approve) scoped to the approver's team. */
final class TimesheetController extends BaseController {
  private function weekView(array $person, string $ws, bool $own): array {
    $uid = (int) $person['id'];
    $ts = TS::find($uid, $ws);
    return ['ws' => $ws, 's' => TS::summary($uid, $ws), 'ts' => $ts, 'person' => $person, 'own' => $own, 'locked' => TS::weekLocked($ws),
      'canReview' => !$own && TS::canReview($this->user(), $uid), 'entries' => T::weekEntries($uid, $ws), 'styles' => ['css/attendance.css'], 'scripts' => ['js/attendance.js']]; }

  public function index(): Response {
    $u = $this->user();
    $ws = $this->weekParam();
    $recent = DB::all('SELECT t.week_start, t.total_seconds, t.billable_seconds, t.status FROM timesheets t WHERE t.user_id=? ORDER BY t.week_start DESC LIMIT 8', [$u['id']]);
    $rejected = $this->can('attendance.timesheets.approve') ? $this->rejectedCount($u) : 0;
    return $this->view('attendance::timesheets/index', ['title' => 'Timesheets', 'recent' => $recent, 'rejectedTeam' => $rejected, 'projects' => T::projects($u)] + $this->weekView($u, $ws, true)); }

  /** Owner resubmits a rejected week after fixing it: approved again automatically. */
  public function resubmit(): Response {
    $d = $this->validate(['week' => 'required|date']);
    $ws = T::weekStart($d['week']);
    return $this->act(function () use ($ws): string { TS::resubmit($this->user(), $ws); return 'Week resubmitted and approved again.'; }, '/timesheets?week='.$ws); }

  private function rejectedCount(array $u): int {
    [$w, $p] = Team::where($u, 't.user_id');
    return (int) DB::value("SELECT COUNT(*) FROM timesheets t WHERE t.status='rejected' AND t.user_id<>? AND $w", [$u['id'], ...$p]); }

  /** Team weeks with status, totals and quick reject / approve; filters by week, person and status. */
  public function approvals(): Response {
    $u = $this->user();
    $status = in_array($s = $this->request->str('status', 'all'), ['approved', 'rejected', 'all'], true) ? $s : 'all';
    $week = preg_match('/^\d{4}-\d{2}-\d{2}$/', $w = $this->request->str('week')) ? T::weekStart($w) : '';
    $person = $this->request->int('user');
    $people = array_diff_key(Team::people($u), [(int) $u['id'] => 1]);
    if ($person && !isset($people[$person])) { $person = 0; }
    [$w, $p] = Team::where($u, 't.user_id');
    $base = " FROM timesheets t JOIN users u ON u.id=t.user_id WHERE t.user_id<>? AND $w";
    $params = [$u['id'], ...$p];
    if ($week !== '') { $base .= ' AND t.week_start=?'; $params[] = $week; }
    if ($person) { $base .= ' AND t.user_id=?'; $params[] = $person; }
    $stats = DB::first("SELECT COUNT(*) AS n, COALESCE(SUM(t.status='rejected'),0) AS rejected, COALESCE(SUM(t.total_seconds),0) AS total, COALESCE(SUM(t.billable_seconds),0) AS billable, COUNT(DISTINCT t.user_id) AS people".$base, $params);
    if ($status !== 'all') { $base .= ' AND t.status=?'; $params[] = $status; }
    $page = DB::paginate('SELECT t.*, u.name, u.avatar, u.designation, r.name AS reviewer'.str_replace(' WHERE ', ' LEFT JOIN users r ON r.id=t.reviewed_by WHERE ', $base)." ORDER BY t.week_start DESC, t.status='rejected' DESC, u.name", $params, 20);
    $weeks = [];
    for ($i = 0; $i < 12; $i++) { $ws = T::weekStart(date('Y-m-d', strtotime("-$i weeks"))); $weeks[$ws] = ($i === 0 ? 'This week' : ($i === 1 ? 'Last week' : 'Week of '.fdate($ws, 'd M'))).' ('.fdate($ws, 'd M').' - '.fdate(date('Y-m-d', strtotime($ws.' +6 days')), 'd M').')'; }
    if ($week !== '' && !isset($weeks[$week])) { $weeks[$week] = 'Week of '.fdate($week, 'd M Y'); }
    return $this->view('attendance::timesheets/approvals', ['title' => 'Timesheet approvals', 'p' => $page, 'status' => $status, 'week' => $week, 'person' => $person, 'people' => $people, 'weeks' => $weeks, 'stats' => $stats,
      'styles' => ['css/attendance.css'], 'scripts' => ['js/attendance.js']]); }

  /** A team member's week (or your own), by user and ?week=. */
  public function person(int $userId): Response {
    $u = $this->user();
    if ($userId === (int) $u['id']) { return $this->redirect('/timesheets?week='.$this->weekParam()); }
    $p = DB::first('SELECT id, name, avatar, designation, manager_id FROM users WHERE id=? LIMIT 1', [$userId]) ?? $this->abort(404, 'Person not found.');
    if (!($this->can('attendance.timesheets.approve') && Team::includes($u, $userId))) { Audit::log('access.denied', 'user', $userId, ['area' => 'timesheets']); $this->abort(403, 'You cannot view this timesheet.'); }
    $ws = $this->weekParam();
    return $this->view('attendance::timesheets/show', ['title' => 'Timesheet: '.$p['name']] + $this->weekView($p, $ws, false)); }

  public function show(int $id): Response {
    $t = DB::first('SELECT user_id, week_start FROM timesheets WHERE id=? LIMIT 1', [$id]) ?? $this->abort(404, 'Timesheet not found.');
    $path = (int) $t['user_id'] === $this->uid() ? '/timesheets' : '/timesheets/user/'.$t['user_id'];
    return $this->redirect($path.'?week='.$t['week_start']); }

  private function reviewable(int $id): void {
    $uid = DB::value('SELECT user_id FROM timesheets WHERE id=? LIMIT 1', [$id]) ?? $this->abort(404, 'Timesheet not found.');
    if (!TS::canReview($this->user(), (int) $uid)) { Audit::log('access.denied', 'timesheet', $id); $this->abort(403, 'You cannot review this timesheet.'); } }

  public function approve(int $id): Response {
    $this->reviewable($id);
    $d = $this->validate(['note' => 'nullable|max:500'], ['note' => 'Note']);
    return $this->act(function () use ($id, $d): string { TS::approve($this->user(), $id, $d['note'] ?? null); return 'Timesheet approved.'; }); }

  public function reject(int $id): Response {
    $this->reviewable($id);
    $d = $this->validate(['note' => 'required|max:500'], ['note' => 'Reason']);
    return $this->act(function () use ($id, $d): string { TS::reject($this->user(), $id, (string) $d['note']); return 'Timesheet rejected. The employee has been notified.'; }); } }
