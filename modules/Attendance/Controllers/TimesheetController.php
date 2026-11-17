<?php
namespace Modules\Attendance\Controllers;
use Core\{Audit, DB, Response};
use Modules\Attendance\Services\{EntryReview as R, Team, Timesheets as TS, TimeService as T};

/** Weekly timesheets (approved automatically): own week grid, a team member's week, and the Review page of rejected entries awaiting fixes. Entry and day review actions (EntryReview) live here too. */
final class TimesheetController extends BaseController {
  private function weekView(array $person, string $ws, bool $own): array {
    $uid = (int) $person['id'];
    return ['ws' => $ws, 's' => TS::summary($uid, $ws), 'person' => $person, 'own' => $own, 'locked' => TS::weekLocked($ws),
      'canReview' => !$own && TS::canReview($this->user(), $uid), 'entries' => T::weekEntries($uid, $ws), 'added' => \Modules\Attendance\Services\AttendanceService::addedTime($uid, $ws, date('Y-m-d', strtotime($ws.' +6 days'))),
      'manage' => !$own && TS::canManage($this->user(), $uid), 'styles' => ['css/attendance.css'], 'scripts' => ['js/attendance.js']]; }

  public function index(): Response {
    $u = $this->user();
    $ws = $this->weekParam();
    if (($other = $this->request->int('user')) && $other !== (int) $u['id']) { return $this->redirect('/timesheets/user/'.$other.'?week='.$ws); }
    $recent = DB::all("SELECT t.week_start, t.total_seconds, t.billable_seconds, (SELECT COUNT(*) FROM time_entries te WHERE te.timesheet_id=t.id AND te.status='rejected') AS rejected FROM timesheets t WHERE t.user_id=? ORDER BY t.week_start DESC LIMIT 8", [$u['id']]);
    return $this->view('attendance::timesheets/index', ['title' => 'Timesheets', 'recent' => $recent, 'projects' => T::projects($u), 'people' => $this->peers()] + $this->weekView($u, $ws, true)); }

  /** Team members the viewer may switch to in the Weekly tab (attendance.view_all or approvers), self included. */
  private function peers(): array { return $this->can('attendance.view_all') || $this->can('attendance.timesheets.approve') ? Team::people($this->user(), true) : []; }

  /** Old week approvals page: now the Review page. */
  public function approvals(): Response { return $this->redirect('/timesheets/review'); }

  /** Rejected entries of the reviewer's team awaiting fixes (never their own), by person and date range, with re-approve. */
  public function review(): Response {
    $u = $this->user();
    $person = $this->request->int('user');
    $people = array_diff_key(Team::people($u), [(int) $u['id'] => 1]);
    if ($person && !isset($people[$person])) { $person = 0; }
    $from = $this->dateParam('from', ''); $to = $this->dateParam('to', '');
    [$w, $p] = Team::where($u, 'te.user_id');
    $base = " FROM time_entries te JOIN users u ON u.id=te.user_id WHERE te.status='rejected' AND te.user_id<>? AND $w";
    $params = [$u['id'], ...$p];
    if ($person) { $base .= ' AND te.user_id=?'; $params[] = $person; }
    if ($from !== '') { $base .= ' AND te.started_at>=?'; $params[] = $from.' 00:00:00'; }
    if ($to !== '') { $base .= ' AND te.started_at<?'; $params[] = date('Y-m-d', strtotime($to.' +1 day')).' 00:00:00'; }
    $stats = DB::first('SELECT COUNT(*) AS n, COALESCE(SUM(te.duration_seconds),0) AS secs, COUNT(DISTINCT te.user_id) AS people, COUNT(DISTINCT te.user_id, DATE(te.started_at)) AS days'.$base, $params);
    $page = DB::paginate('SELECT te.*, u.name, u.avatar, u.designation, p.name AS project_name, p.color AS project_color, t.title AS task_title, r.name AS reviewer_name'.str_replace(' WHERE ', ' LEFT JOIN projects p ON p.id=te.project_id LEFT JOIN tasks t ON t.id=te.task_id LEFT JOIN users r ON r.id=te.reviewed_by WHERE ', $base).' ORDER BY te.started_at DESC', $params, 25);
    return $this->view('attendance::timesheets/review', ['title' => 'Time review', 'p' => $page, 'person' => $person, 'people' => $people, 'from' => $from, 'to' => $to, 'stats' => $stats,
      'styles' => ['css/attendance.css'], 'scripts' => ['js/attendance.js']]); }

  /** A team member's week (or your own), by user and ?week=. */
  public function person(int $userId): Response {
    $u = $this->user();
    if ($userId === (int) $u['id']) { return $this->redirect('/timesheets?week='.$this->weekParam()); }
    $p = DB::first('SELECT id, name, avatar, designation, manager_id FROM users WHERE id=? LIMIT 1', [$userId]) ?? $this->abort(404, 'Person not found.');
    if (!(($this->can('attendance.timesheets.approve') || $this->can('attendance.view_all')) && Team::includes($u, $userId))) { Audit::log('access.denied', 'user', $userId, ['area' => 'timesheets']); $this->abort(403, 'You cannot view this timesheet.'); }
    $ws = $this->weekParam();
    $v = $this->weekView($p, $ws, false);
    return $this->view('attendance::timesheets/show', ['title' => 'Timesheet: '.$p['name'], 'people' => $this->peers(), 'projects' => $v['manage'] ? T::projects(DB::first('SELECT * FROM users WHERE id=?', [$userId])) : []] + $v); }

  public function show(int $id): Response {
    $t = DB::first('SELECT user_id, week_start FROM timesheets WHERE id=? LIMIT 1', [$id]) ?? $this->abort(404, 'Timesheet not found.');
    $path = (int) $t['user_id'] === $this->uid() ? '/timesheets' : '/timesheets/user/'.$t['user_id'];
    return $this->redirect($path.'?week='.$t['week_start']); }

  /** The entry, if the viewer may review its owner (403 and audited otherwise; 404 when missing). */
  private function reviewable(int $id): array {
    $e = R::find($id) ?? $this->abort(404, 'Time entry not found.');
    if (!TS::canReview($this->user(), (int) $e['user_id'])) { Audit::log('access.denied', 'time_entry', $id, ['area' => 'time.review']); $this->abort(403, 'You cannot review this time entry.'); }
    return $e; }

  public function rejectEntry(int $id): Response {
    $this->reviewable($id);
    $d = $this->validate(['note' => 'required|max:500'], ['note' => 'Reason']);
    return $this->act(function () use ($id, $d): string { R::reject($this->user(), $id, (string) $d['note']); return 'Entry rejected. The employee has been notified.'; }); }

  public function approveEntry(int $id): Response {
    $this->reviewable($id);
    return $this->act(function () use ($id): string { R::approve($this->user(), $id); return 'Entry approved.'; }); }

  /** Approve or reject a whole day of a team member (decision approved|rejected, note required to reject). */
  public function reviewDay(): Response {
    $d = $this->validate(['user_id' => 'required|integer', 'date' => 'required|date', 'decision' => 'required|in:approved,rejected', 'note' => ($this->request->str('decision') === 'rejected' ? 'required' : 'nullable').'|max:500'], ['note' => 'Reason']);
    $uid = (int) $d['user_id'];
    if (!TS::canReview($this->user(), $uid)) { Audit::log('access.denied', 'user', $uid, ['area' => 'time.review_day']); $this->abort(403, 'You cannot review this person\'s time.'); }
    return $this->act(function () use ($uid, $d): string {
      $n = R::day($this->user(), $uid, (string) $d['date'], (string) $d['decision'], $d['note'] ?? null);
      return ($d['decision'] === 'rejected' ? 'Day rejected: ' : 'Day approved: ').$n.' entr'.($n === 1 ? 'y' : 'ies').'.'.($d['decision'] === 'rejected' ? ' The employee has been notified.' : ''); }); } }
