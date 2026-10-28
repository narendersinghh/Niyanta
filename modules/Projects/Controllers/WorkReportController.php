<?php
namespace Modules\Projects\Controllers;
use Core\{Audit, Controller, DB, Notify, Response, ValidationException};
use Modules\Projects\Services\{ProjectAccess as PA, ProjectService as PS};

/**
 * Daily / weekly work reports. Authors see their own; approvers see reports of direct reports and of members of
 * projects they manage (Super Admin / projects.view_all: everyone).
 */
final class WorkReportController extends Controller {
  /** SQL restricting work_reports w (joined to users a) to reports the current user may review. */
  private function reviewScope(): array {
    $u = $this->user(); $uid = $this->uid();
    if (!$this->can('projects.reports.approve')) { return ['0=1', []]; }
    if (PA::unrestricted($u)) { return ['w.user_id<>?', [$uid]]; }
    return ['w.user_id<>? AND (a.manager_id=? OR w.project_id IN (SELECT id FROM projects WHERE manager_id=?) OR w.user_id IN (SELECT pm.user_id FROM project_members pm JOIN projects mp ON mp.id=pm.project_id WHERE mp.manager_id=?))', [$uid, $uid, $uid, $uid]]; }

  private function canReview(array $r): bool {
    [$w, $p] = $this->reviewScope();
    return (bool) DB::value("SELECT 1 FROM work_reports w JOIN users a ON a.id=w.user_id WHERE w.id=? AND $w", [(int) $r['id'], ...$p]); }

  private function report(int $id): array {
    $r = DB::first('SELECT w.*, a.name AS author, a.avatar AS author_avatar, a.designation, p.name AS project_name, rv.name AS reviewer FROM work_reports w JOIN users a ON a.id=w.user_id LEFT JOIN projects p ON p.id=w.project_id LEFT JOIN users rv ON rv.id=w.reviewed_by WHERE w.id=?', [$id]);
    if (!$r || ((int) $r['user_id'] !== $this->uid() && !$this->canReview($r))) { $this->abort(404, 'Report not found.'); }
    return $r; }

  private function own(int $id): array {
    $r = $this->report($id);
    if ((int) $r['user_id'] !== $this->uid()) { $this->abort(403, 'You can only change your own reports.'); }
    if ($r['status'] === 'approved') { $this->abort(422, 'Approved reports can no longer be changed.'); }
    return $r; }

  public function index(): Response {
    $uid = $this->uid();
    $canApprove = $this->can('projects.reports.approve');
    $canSubmit = $this->can('projects.reports.submit');
    $tab = $canApprove && ($this->request->str('tab') === 'team' || !$canSubmit) ? 'team' : 'mine';
    [$rw, $rp] = $this->reviewScope();
    $sql = 'SELECT w.id, w.type, w.period_start, w.period_end, w.summary, w.hours, w.status, w.created_at, w.review_note, a.name AS author, a.avatar, p.name AS project FROM work_reports w JOIN users a ON a.id=w.user_id LEFT JOIN projects p ON p.id=w.project_id WHERE ';
    [$sql, $p] = $tab === 'team' ? [$sql.$rw, $rp] : [$sql.'w.user_id=?', [$uid]];
    $status = $this->request->str('status'); $type = $this->request->str('type'); $author = $this->request->int('user');
    if (in_array($status, ['submitted', 'approved', 'rejected'], true)) { $sql .= ' AND w.status=?'; $p[] = $status; }
    if (in_array($type, ['daily', 'weekly'], true)) { $sql .= ' AND w.type=?'; $p[] = $type; }
    if ($tab === 'team' && $author) { $sql .= ' AND w.user_id=?'; $p[] = $author; }
    $page = DB::paginate($sql." ORDER BY w.status='submitted' DESC, w.period_start DESC, w.id DESC", $p, 15);
    $mon = date('Y-m-d', strtotime('monday this week'));
    $stats = ['pending' => $canApprove ? (int) DB::value("SELECT COUNT(*) FROM work_reports w JOIN users a ON a.id=w.user_id WHERE $rw AND w.status='submitted'", $rp) : 0,
      'mine' => DB::pairs('SELECT status, COUNT(*) FROM work_reports WHERE user_id=? GROUP BY status', [$uid]),
      'week' => PS::userSeconds($uid, $mon, date('Y-m-d')), 'submittedToday' => (bool) DB::value("SELECT 1 FROM work_reports WHERE user_id=? AND type='daily' AND period_start=?", [$uid, date('Y-m-d')])];
    $people = $tab === 'team' ? DB::pairs("SELECT DISTINCT a.id, a.name FROM work_reports w JOIN users a ON a.id=w.user_id WHERE $rw ORDER BY a.name", $rp) : [];
    return $this->view('projects::reports/index', ['title' => 'Work reports', 'p' => $page, 'tab' => $tab, 'canApprove' => $canApprove, 'canSubmit' => $canSubmit, 'stats' => $stats, 'people' => $people, 'styles' => ['css/projects.css']]); }

  /** Visible, open projects for the report's project picker. */
  private function projectOptions(): array {
    [$w, $p] = PA::scope($this->user(), 'p.id');
    return DB::pairs("SELECT p.id, p.name FROM projects p WHERE $w AND p.status IN ('planning','active','on_hold') ORDER BY p.name", $p); }

  /** [start, end] for a report type and chosen start date. */
  private function period(string $type, string $start): array {
    if ($type === 'weekly') { $s = date('Y-m-d', strtotime('monday this week', strtotime($start))); return [$s, date('Y-m-d', strtotime($s.' +6 days'))]; }
    return [$start, $start]; }

  public function create(): Response {
    $type = $this->request->str('type') === 'weekly' ? 'weekly' : 'daily';
    [$s, $e] = $this->period($type, date('Y-m-d'));
    $r = ['id' => null, 'type' => $type, 'period_start' => $s, 'period_end' => $e, 'project_id' => null, 'hours' => round(PS::userSeconds($this->uid(), $s, $e) / 3600, 2) ?: null];
    return $this->view('projects::reports/form', ['title' => 'New work report', 'r' => $r, 'projects' => $this->projectOptions(), 'styles' => ['css/projects.css'], 'scripts' => ['js/projects.js']]); }

  public function edit(int $id): Response {
    $r = $this->own($id);
    return $this->view('projects::reports/form', ['title' => 'Edit work report', 'r' => $r, 'projects' => $this->projectOptions(), 'styles' => ['css/projects.css'], 'scripts' => ['js/projects.js']]); }

  /** JSON: hours logged in time entries for a report period (prefill). */
  public function hours(): Response {
    $type = $this->request->str('type') === 'weekly' ? 'weekly' : 'daily';
    $start = $this->request->str('start');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !strtotime($start)) { return $this->json(['error' => 'Invalid date'], 422); }
    [$s, $e] = $this->period($type, $start);
    return $this->json(['start' => $s, 'end' => $e, 'label' => fdate($s).($s !== $e ? ' – '.fdate($e) : ''), 'hours' => round(PS::userSeconds($this->uid(), $s, $e) / 3600, 2)]); }

  private function data(?int $id): array {
    $d = $this->validate(['type' => 'required|in:daily,weekly', 'period_start' => 'required|date', 'project_id' => 'nullable|integer', 'summary' => 'required|max:5000', 'accomplishments' => 'nullable|max:10000', 'blockers' => 'nullable|max:5000', 'next_steps' => 'nullable|max:5000', 'hours' => 'nullable|numeric|min:0|max:'.($this->request->str('type') === 'weekly' ? 168 : 24)], ['period_start' => 'Date', 'project_id' => 'Project']);
    [$d['period_start'], $d['period_end']] = $this->period($d['type'], $d['period_start']);
    if ($d['period_start'] > date('Y-m-d', strtotime('+1 day'))) { throw new ValidationException(['period_start' => 'You cannot report on a future period.']); }
    if ($d['project_id'] && !PA::canSee($this->user(), (int) $d['project_id'])) { throw new ValidationException(['project_id' => 'Project is invalid.']); }
    $dup = DB::value('SELECT id FROM work_reports WHERE user_id=? AND type=? AND period_start=? AND (project_id <=> ?)'.($id ? ' AND id<>?' : '').' LIMIT 1', [$this->uid(), $d['type'], $d['period_start'], $d['project_id'], ...($id ? [$id] : [])]);
    if ($dup) { throw new ValidationException(['period_start' => 'You already submitted a '.$d['type'].' report for this period'.($d['project_id'] ? ' and project' : '').'.']); }
    return $d; }

  /** Tell the reviewer (reporting manager, else the project manager) a report is waiting. */
  private function notifyReviewer(int $id, array $d): void {
    $me = $this->user();
    $to = (int) ($me['manager_id'] ?? 0) ?: (int) ($d['project_id'] ? DB::value('SELECT manager_id FROM projects WHERE id=?', [$d['project_id']]) : 0);
    if ($to && $to !== $this->uid()) { Notify::user($to, $me['name'].' submitted a '.$d['type'].' work report', fdate($d['period_start']).($d['type'] === 'weekly' ? ' – '.fdate($d['period_end']) : '').': '.str_limit($d['summary'], 120), '/work-reports/'.$id, false, 'clipboard'); } }

  public function store(): Response {
    $d = $this->data(null);
    $id = DB::insert('work_reports', $d + ['user_id' => $this->uid(), 'status' => 'submitted', 'created_at' => date('Y-m-d H:i:s')]);
    Audit::log('projects.report.submit', 'work_report', $id, ['type' => $d['type'], 'period' => $d['period_start']]);
    $this->notifyReviewer($id, $d);
    $this->flash('Report submitted. Nice work!');
    return $this->redirect('/work-reports'); }

  public function update(int $id): Response {
    $r = $this->own($id);
    $d = $this->data($id);
    DB::update('work_reports', $d + ['status' => 'submitted', 'reviewed_by' => null, 'reviewed_at' => null, 'review_note' => $r['status'] === 'rejected' ? $r['review_note'] : null], 'id=?', [$id]);
    Audit::log('projects.report.update', 'work_report', $id, ['resubmitted' => $r['status'] === 'rejected']);
    if ($r['status'] === 'rejected') { $this->notifyReviewer($id, $d); }
    $this->flash($r['status'] === 'rejected' ? 'Report resubmitted.' : 'Report updated.');
    return $this->redirect('/work-reports/'.$id); }

  public function destroy(int $id): Response {
    $this->own($id);
    DB::delete('work_reports', 'id=?', [$id]);
    Audit::log('projects.report.delete', 'work_report', $id);
    $this->flash('Report deleted.');
    return $this->redirect('/work-reports'); }

  public function show(int $id): Response {
    $r = $this->report($id);
    $mine = (int) $r['user_id'] === $this->uid();
    $tasks = DB::all("SELECT t.id, t.title, t.project_id, p.name AS project FROM tasks t JOIN projects p ON p.id=t.project_id WHERE t.assignee_id=? AND t.status='done' AND t.completed_at>=? AND t.completed_at<? ORDER BY t.completed_at LIMIT 30", [(int) $r['user_id'], $r['period_start'].' 00:00:00', date('Y-m-d', strtotime($r['period_end'].' +1 day')).' 00:00:00']);
    $vis = PA::visibleProjectIds($this->user());
    if ($vis !== null) { $tasks = array_values(array_filter($tasks, static fn ($t) => in_array((int) $t['project_id'], $vis, true))); }
    return $this->view('projects::reports/show', ['title' => 'Work report', 'r' => $r, 'mine' => $mine, 'canReview' => !$mine && $r['status'] === 'submitted' && $this->can('projects.reports.approve') && $this->canReview($r), 'logged' => PS::userSeconds((int) $r['user_id'], $r['period_start'], $r['period_end']), 'tasks' => $tasks, 'styles' => ['css/projects.css']]); }

  public function review(int $id): Response {
    $r = $this->report($id);
    if ((int) $r['user_id'] === $this->uid() || !$this->canReview($r)) { $this->abort(403, 'You cannot review this report.'); }
    if ($r['status'] !== 'submitted') { $this->flash('This report was already reviewed.', 'error'); return $this->redirect('/work-reports/'.$id); }
    $d = $this->validate(['decision' => 'required|in:approved,rejected', 'review_note' => ($this->request->str('decision') === 'rejected' ? 'required' : 'nullable').'|max:500'], ['review_note' => 'Note']);
    DB::update('work_reports', ['status' => $d['decision'], 'reviewed_by' => $this->uid(), 'reviewed_at' => date('Y-m-d H:i:s'), 'review_note' => $d['review_note']], 'id=?', [$id]);
    Audit::log('projects.report.'.$d['decision'], 'work_report', $id);
    Notify::user((int) $r['user_id'], 'Your '.$r['type'].' work report was '.$d['decision'], ($d['review_note'] ? $this->user()['name'].': '.$d['review_note'] : 'Reviewed by '.$this->user()['name']), '/work-reports/'.$id, $d['decision'] === 'rejected', $d['decision'] === 'approved' ? 'check-circle' : 'alert');
    $this->flash('Report '.$d['decision'].'.');
    return $this->redirect('/work-reports?tab=team'); } }
