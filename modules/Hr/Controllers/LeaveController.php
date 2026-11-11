<?php
namespace Modules\Hr\Controllers;
use Core\{Audit, Controller, DB, Gate, Notify, Response, Settings, ValidationException};
use Modules\Hr\Services\{HrAccess, LeaveChanges, LeaveService};

/** Leave requests, approvals, team calendar, balances and leave types. */
final class LeaveController extends Controller {
  public const COLORS = ['mint', 'peach', 'sky', 'lav', 'lemon', 'pink', 'gray'];

  private function base(string $tab, array $data): Response {
    [$w, $p] = HrAccess::reviewScope();
    $data['pendingCount'] = $this->can('hr.leave.approve') ? (int) DB::value("SELECT COUNT(*) FROM leave_requests lr JOIN users u ON u.id=lr.user_id WHERE lr.status='pending' AND $w", $p) : 0;
    return $this->view('hr::leave/'.$tab, $data + ['tab' => $tab, 'styles' => ['css/hr.css', 'css/leave.css'], 'scripts' => ['js/hr.js', 'js/leave.js']]); }

  public function index(): Response {
    if (!$this->can('hr.leave.request')) { return $this->redirect('/hr/leave/approvals'); }
    $uid = $this->uid(); $year = (int) date('Y');
    $status = $this->request->str('status');
    $sql = 'SELECT lr.*, t.name AS type_name, t.color, t.paid, t.needs_approval, r.name AS reviewer FROM leave_requests lr JOIN leave_types t ON t.id=lr.leave_type_id LEFT JOIN users r ON r.id=lr.reviewed_by WHERE lr.user_id=?';
    $p = [$uid];
    if (in_array($status, ['pending', 'approved', 'rejected', 'cancelled'], true)) { $sql .= ' AND lr.status=?'; $p[] = $status; }
    $mgr = DB::first('SELECT m.name FROM users u JOIN users m ON m.id=u.manager_id WHERE u.id=?', [$uid]);
    $stats = DB::first("SELECT SUM(status='pending') AS pending, COALESCE(SUM(CASE WHEN status='approved' THEN days END),0) AS taken FROM leave_requests WHERE user_id=? AND start_date BETWEEN ? AND ?", [$uid, "$year-01-01", "$year-12-31"]);
    $next = DB::first("SELECT lr.start_date, lr.end_date, t.name AS type_name FROM leave_requests lr JOIN leave_types t ON t.id=lr.leave_type_id WHERE lr.user_id=? AND lr.status='approved' AND lr.end_date>=? ORDER BY lr.start_date LIMIT 1", [$uid, date('Y-m-d')]);
    $page = DB::paginate($sql.' ORDER BY lr.start_date DESC, lr.id DESC', $p, 12);
    return $this->base('index', ['title' => 'Leave', 'year' => $year, 'balances' => LeaveService::balances($uid, $year), 'p' => $page, 'history' => LeaveChanges::history(array_column($page['items'], 'id')), 'today' => date('Y-m-d'), 'stats' => $stats, 'nextLeave' => $next,
      'manager' => $mgr['name'] ?? null, 'schedule' => LeaveService::scheduleFor($uid), 'optional' => LeaveService::optionalType(), 'paidType' => LeaveService::shortageType(), 'shortageOn' => \Core\Settings::bool('hr.leave.shortage_cover', true), 'upcoming' => DB::all('SELECT holiday_date, name, optional FROM holidays WHERE holiday_date>=? ORDER BY holiday_date LIMIT 4', [date('Y-m-d')])]); }

  /** JSON working-day preview for the request form. */
  public function preview(): Response {
    $from = $this->request->str('from'); $to = $this->request->str('to') ?: $from; $half = (bool) $this->request->int('half');
    if (!$this->isDate($from) || !$this->isDate($to) || $to < $from) { return $this->json(['days' => 0, 'note' => 'Choose a valid date range.']); }
    if ((strtotime($to) - strtotime($from)) / 86400 > 92) { return $this->json(['days' => 0, 'note' => 'Requests are limited to 3 months.']); }
    $hol = DB::column('SELECT name FROM holidays WHERE optional=0 AND holiday_date BETWEEN ? AND ? ORDER BY holiday_date', [$from, $to]);
    $uid = $this->uid();
    if (($for = $this->request->int('user')) && $for !== $uid) { $emp = DB::first('SELECT id AS user_id, manager_id FROM users WHERE id=?', [$for]); if ($emp && LeaveChanges::canManage($emp)) { $uid = $for; } }
    $days = LeaveService::requestDaysFor($uid, $from, $to, $half && $from === $to);
    $sc = LeaveService::scheduleFor($uid); $off = LeaveService::daysLabel($sc['off_days'], $sc['saturday']);
    return $this->json(['days' => $days, 'note' => $days ? ($days == 1 ? '1 working day' : rtrim(rtrim(number_format($days, 1), '0'), '.').' working days').($hol ? ' (excludes '.implode(', ', $hol).')' : '') : 'No working days in this range, '.($uid === $this->uid() ? 'your' : 'their').' weekly off: '.$off.'.']); }

  private function isDate(string $v): bool { $d = \DateTime::createFromFormat('Y-m-d', $v); return $d && $d->format('Y-m-d') === $v; }

  public function store(): Response {
    $d = $this->validate(['leave_type_id' => 'required|integer', 'start_date' => 'required|date', 'end_date' => 'nullable|date', 'half_day' => 'boolean', 'reason' => 'nullable|max:1000'], ['leave_type_id' => 'Leave type', 'start_date' => 'From date', 'end_date' => 'To date']);
    $d['end_date'] ??= $d['start_date'];
    $type = DB::first('SELECT * FROM leave_types WHERE id=? AND active=1', [$d['leave_type_id']]) ?? throw new ValidationException(['leave_type_id' => 'This leave type is not available.']);
    if ($d['end_date'] < $d['start_date']) { throw new ValidationException(['end_date' => 'To date must be on or after the from date.']); }
    if (substr($d['start_date'], 0, 4) !== substr($d['end_date'], 0, 4)) { throw new ValidationException(['end_date' => 'Requests cannot span two calendar years. Please split them.']); }
    if ((strtotime($d['end_date']) - strtotime($d['start_date'])) / 86400 > 92) { throw new ValidationException(['end_date' => 'A single request can cover at most 3 months.']); }
    if ($d['half_day'] && $d['start_date'] !== $d['end_date']) { throw new ValidationException(['half_day' => 'A half day must start and end on the same date.']); }
    if ($d['start_date'] < date('Y-m-d', strtotime('-60 days'))) { throw new ValidationException(['start_date' => 'Leave older than 60 days cannot be requested. Contact HR.']); }
    $uid = $this->uid(); $year = (int) substr($d['start_date'], 0, 4);
    $days = LeaveService::requestDaysFor($uid, $d['start_date'], $d['end_date'], (bool) $d['half_day']);
    if ($days <= 0) { throw new ValidationException(['start_date' => 'The selected dates fall on your weekly off days or fixed holidays, no working days to request.']); }
    if ($pe = LeaveService::validateRequest($uid, $type, $d['start_date'], $d['end_date'], (bool) $d['half_day'], $days)) { throw new ValidationException($pe); }
    $auto = !(int) $type['needs_approval'];
    $id = DB::transaction(function () use ($d, $type, $days, $uid, $year, $auto): int {
      DB::value('SELECT id FROM users WHERE id=? FOR UPDATE', [$uid]);
      $clash = DB::first("SELECT start_date, end_date, status FROM leave_requests WHERE user_id=? AND status IN ('pending','approved') AND start_date<=? AND end_date>=? LIMIT 1", [$uid, $d['end_date'], $d['start_date']]);
      if ($clash) { throw new ValidationException(['start_date' => 'Overlaps your '.$clash['status'].' leave from '.fdate($clash['start_date']).' to '.fdate($clash['end_date']).'.']); }
      if ($err = LeaveService::balanceError($uid, $type, $year, $days)) { throw new ValidationException(['leave_type_id' => $err]); }
      $now = date('Y-m-d H:i:s');
      $row = ['user_id' => $uid, 'leave_type_id' => $type['id'], 'start_date' => $d['start_date'], 'end_date' => $d['end_date'], 'half_day' => $d['half_day'], 'days' => $days, 'reason' => $d['reason'], 'status' => $auto ? 'approved' : 'pending', 'created_at' => $now];
      if ($auto) { $row += ['reviewed_at' => $now, 'review_note' => 'Booked without approval (leave type policy)']; }
      $id = DB::insert('leave_requests', $row);
      if ($auto) { LeaveService::recordUsage(['id' => $id] + $row, $uid); }
      return $id; });
    Audit::log('hr.leave.request', 'leave_request', $id, ['type' => $type['code'], 'days' => $days, 'from' => $d['start_date'], 'to' => $d['end_date']]);
    $this->notifyApprovers($id, $type['name'], $days, $d, $auto);
    $this->flash($auto ? LeaveService::num($days).' day(s) of '.$type['name'].' booked. This leave type needs no approval.' : 'Leave requested for '.LeaveService::num($days).' day(s). Your approver has been notified.');
    return $this->redirect($this->request->str('return') === 'holidays' ? '/hr/holidays?year='.$year : '/hr/leave'); }

  private function notifyApprovers(int $id, string $typeName, float $days, array $d, bool $auto = false): void {
    $me = $this->user();
    $to = array_map('intval', array_column(Gate::usersWith('hr.leave.manage'), 'id'));
    if ($me['manager_id'] && DB::value("SELECT 1 FROM users WHERE id=? AND status='active'", [$me['manager_id']])) { $to[] = (int) $me['manager_id']; }
    $range = fdate($d['start_date']).($d['end_date'] !== $d['start_date'] ? ' – '.fdate($d['end_date']) : '').($d['half_day'] ? ' (half day)' : '');
    foreach (array_unique($to) as $u) { if ($u !== (int) $me['id']) { Notify::user($u, ($auto ? 'Leave booked by ' : 'Leave request from ').$me['name'], "$typeName · $range · $days day(s)".($d['reason'] ? "\nReason: ".$d['reason'] : ''), '/hr/leave/approvals', true, 'plane'); } } }

  /** Employee: cancel an own pending request (or approved leave of a no-approval type before it starts). */
  public function cancel(int $id): Response {
    try { $msg = LeaveChanges::cancelOwn($id, $this->user()); }
    catch (ValidationException $e) { $this->flash(current($e->errors) ?: 'This request cannot be cancelled.', 'error'); return $this->redirect('/hr/leave'); }
    Audit::log('hr.leave.cancel', 'leave_request', $id);
    $this->flash($msg);
    return $this->redirect('/hr/leave'); }

  /** Employee: ask the approver to cancel approved leave that has not started. */
  public function requestCancel(int $id): Response {
    try { LeaveChanges::requestCancel($id, $this->request->str('cancel_reason'), $this->user()); }
    catch (ValidationException $e) { $this->flash(current($e->errors) ?: 'This leave cannot be cancelled.', 'error'); return $this->redirect('/hr/leave'); }
    $this->flash('Cancellation requested. Your leave stays approved until your approver confirms.');
    return $this->redirect('/hr/leave'); }

  /** Approver: edit a pending or approved request (dates, half day, type, reason) with a required note. */
  public function edit(int $id): Response {
    $d = $this->validate(['leave_type_id' => 'required|integer', 'start_date' => 'required|date', 'end_date' => 'nullable|date', 'half_day' => 'boolean', 'reason' => 'nullable|max:1000', 'note' => 'required|max:500'], ['leave_type_id' => 'Leave type', 'start_date' => 'From date', 'end_date' => 'To date', 'note' => 'Note to the employee']);
    $r = LeaveChanges::edit($id, $d, $d['note'], $this->user());
    $this->flash('Leave of '.$r['lr']['name'].' updated: '.LeaveChanges::summary($r['changes']).'.');
    return $this->back(); }

  /** Approver: revoke approved leave (days return to the balance), note required. */
  public function revoke(int $id): Response {
    $d = $this->validate(['note' => 'required|max:500'], ['note' => 'Note to the employee']);
    $lr = LeaveChanges::revoke($id, $d['note'], $this->user());
    $this->flash('Leave of '.$lr['name'].' revoked. '.LeaveService::num($lr['days']).' day(s) returned to '.$lr['type_name'].'.');
    return $this->back(); }

  /** Approver: confirm or decline an employee's cancellation request. */
  public function cancellation(int $id): Response {
    $d = $this->validate(['decision' => 'required|in:approve,decline', 'note' => 'nullable|max:500']);
    if ($d['decision'] === 'approve') { $lr = LeaveChanges::revoke($id, (string) $d['note'], $this->user(), 'cancel_approve'); $this->flash('Cancellation approved. '.LeaveService::num($lr['days']).' day(s) returned to '.$lr['name'].'.'); }
    else { $lr = LeaveChanges::declineCancel($id, (string) $d['note'], $this->user()); $this->flash('Cancellation declined. The leave of '.$lr['name'].' stays approved.'); }
    return $this->back(); }

  /** Approver: book leave for an employee they approve (approved right away). */
  public function onBehalf(): Response {
    $d = $this->validate(['user_id' => 'required|integer', 'leave_type_id' => 'required|integer', 'start_date' => 'required|date', 'end_date' => 'nullable|date', 'half_day' => 'boolean', 'reason' => 'nullable|max:1000', 'note' => 'nullable|max:500'], ['user_id' => 'Employee', 'leave_type_id' => 'Leave type', 'start_date' => 'From date', 'end_date' => 'To date']);
    $id = LeaveChanges::book((int) $d['user_id'], $d, $this->user());
    $lr = LeaveChanges::find($id);
    $this->flash(LeaveService::num($lr['days']).' day(s) of '.$lr['type_name'].' booked for '.$lr['name'].'. They have been notified.');
    return $this->redirect('/hr/leave/approvals?status=approved'); }

  /** Filters of the approvals list and its CSV export: status (incl. cancel = cancellation requested), person, type, date range. Returns [sql, params, filters]. */
  private function listQuery(): array {
    [$w, $p] = HrAccess::reviewScope();
    $f = ['status' => $this->request->str('status', 'pending'), 'q' => mb_substr($this->request->str('q'), 0, 100), 'user' => $this->request->int('user'), 'type' => $this->request->int('type'), 'from' => $this->request->str('from'), 'to' => $this->request->str('to')];
    $sql = "SELECT lr.*, u.name, u.avatar, u.designation, u.department, u.employee_code, u.manager_id, t.name AS type_name, t.code, t.color, t.paid, r.name AS reviewer FROM leave_requests lr JOIN users u ON u.id=lr.user_id JOIN leave_types t ON t.id=lr.leave_type_id LEFT JOIN users r ON r.id=lr.reviewed_by WHERE $w";
    if (in_array($f['status'], ['pending', 'approved', 'rejected', 'cancelled'], true)) { $sql .= ' AND lr.status=?'; $p[] = $f['status']; }
    elseif ($f['status'] === 'cancel') { $sql .= " AND lr.status='approved' AND lr.cancel_requested_at IS NOT NULL"; }
    else { $f['status'] = 'all'; }
    if ($f['q'] !== '') { $sql .= ' AND (u.name LIKE ? OR u.employee_code LIKE ?)'; array_push($p, DB::like($f['q']), DB::like($f['q'])); }
    if ($f['user']) { $sql .= ' AND lr.user_id=?'; $p[] = $f['user']; }
    if ($f['type']) { $sql .= ' AND lr.leave_type_id=?'; $p[] = $f['type']; }
    if (!$this->isDate($f['from'])) { $f['from'] = ''; }
    if (!$this->isDate($f['to'])) { $f['to'] = ''; }
    if ($f['from'] !== '') { $sql .= ' AND lr.end_date>=?'; $p[] = $f['from']; }
    if ($f['to'] !== '') { $sql .= ' AND lr.start_date<=?'; $p[] = $f['to']; }
    return [$sql.(in_array($f['status'], ['pending', 'cancel'], true) ? ' ORDER BY lr.start_date, lr.id' : ' ORDER BY lr.id DESC'), $p, $f]; }

  /** CSV of the filtered approvals list (up to 5000 rows). */
  public function export(): Response {
    [$sql, $p, $f] = $this->listQuery();
    $rows = DB::all($sql.' LIMIT 5000', $p);
    $out = fopen('php://temp', 'w+');
    $safe = static fn ($v) => is_string($v) && $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false ? "'".$v : $v;
    fputcsv($out, ['Employee', 'Employee code', 'Department', 'Leave type', 'From', 'To', 'Half day', 'Days', 'Status', 'Cancellation requested', 'Reason', 'Reviewed by', 'Reviewed at', 'Review note', 'Requested at'], ',', '"', '');
    foreach ($rows as $r) { fputcsv($out, array_map($safe, [$r['name'], $r['employee_code'] ?? '', $r['department'] ?? '', $r['type_name'], $r['start_date'], $r['end_date'], (int) $r['half_day'] ? 'Yes' : 'No', LeaveService::num($r['days']), ucfirst($r['status']), $r['cancel_requested_at'] ? 'Yes' : '', (string) $r['reason'], (string) $r['reviewer'], (string) $r['reviewed_at'], (string) $r['review_note'], (string) $r['created_at']]), ',', '"', ''); }
    rewind($out); $csv = (string) stream_get_contents($out); fclose($out);
    Audit::log('hr.leave.export', 'leave_request', null, ['rows' => count($rows), 'filters' => array_filter($f)]);
    return Response::download("\xEF\xBB\xBF".$csv, 'leave-'.($f['status'] ?: 'all').'-'.date('Ymd').'.csv', 'text/csv; charset=utf-8'); }

  public function approvals(): Response {
    [$sql, $p, $f] = $this->listQuery();
    $page = DB::paginate($sql, $p, 15);
    $bal = [];
    if ($page['items']) {
      $uids = array_values(array_unique(array_map('intval', array_column($page['items'], 'user_id'))));
      foreach (DB::all('SELECT user_id, leave_type_id, year, allocated-used AS left_days FROM leave_balances WHERE user_id IN '.DB::in($uids), $uids) as $b) { $bal[$b['user_id'].'-'.$b['leave_type_id'].'-'.$b['year']] = (float) $b['left_days']; } }
    [$w2, $p2] = HrAccess::reviewScope();
    $stats = DB::first("SELECT SUM(lr.status='approved' AND lr.reviewed_at>=?) AS approved, SUM(lr.status='rejected' AND lr.reviewed_at>=?) AS rejected, SUM(lr.status='approved' AND lr.start_date<=? AND lr.end_date>=?) AS out_today, SUM(lr.status='approved' AND lr.cancel_requested_at IS NOT NULL) AS cancel_req FROM leave_requests lr JOIN users u ON u.id=lr.user_id WHERE $w2", [date('Y-m-01'), date('Y-m-01'), date('Y-m-d'), date('Y-m-d'), ...$p2]);
    $all = HrAccess::seesAllLeave();
    $people = $all ? DB::pairs("SELECT id, name FROM users WHERE status='active' AND id<>? ORDER BY name", [$this->uid()]) : DB::pairs("SELECT id, name FROM users WHERE status='active' AND manager_id=? AND id<>? ORDER BY name", [$this->uid(), $this->uid()]);
    return $this->base('approvals', ['title' => 'Leave approvals', 'p' => $page, 'status' => $f['status'], 'f' => $f, 'bal' => $bal, 'all' => $all, 'stats' => $stats, 'people' => $people,
      'types' => DB::all('SELECT id, name, code, color, paid, allow_half_day FROM leave_types WHERE active=1 ORDER BY paid DESC, id'), 'allTypes' => DB::pairs('SELECT id, name FROM leave_types ORDER BY active DESC, name'),
      'history' => LeaveChanges::history(array_column($page['items'], 'id')), 'me' => $this->uid()]); }

  public function review(int $id): Response {
    $d = $this->validate(['decision' => 'required|in:approve,reject', 'review_note' => 'nullable|max:500'], ['review_note' => 'Note']);
    $res = DB::transaction(function () use ($id, $d): array {
      $lr = DB::first('SELECT lr.*, u.manager_id, u.name, t.paid, t.name AS type_name FROM leave_requests lr JOIN users u ON u.id=lr.user_id JOIN leave_types t ON t.id=lr.leave_type_id WHERE lr.id=? FOR UPDATE', [$id]) ?? $this->abort(404, 'Request not found.');
      if (!HrAccess::canReview($lr)) { Audit::log('access.denied', 'leave_request', $id, ['action' => 'review']); $this->abort(403, (int) $lr['user_id'] === $this->uid() ? 'You cannot approve your own leave.' : 'You can only review leave of your direct reports.'); }
      if ($lr['status'] !== 'pending') { return [$lr, 'This request was already '.$lr['status'].'.']; }
      $approve = $d['decision'] === 'approve';
      if ($approve) {
        $year = (int) substr($lr['start_date'], 0, 4);
        DB::value('SELECT 1 FROM leave_balances WHERE user_id=? AND leave_type_id=? AND year=? FOR UPDATE', [$lr['user_id'], $lr['leave_type_id'], $year]);
        if ((int) $lr['paid'] === 1) {
          $b = DB::first('SELECT allocated, used FROM leave_balances WHERE user_id=? AND leave_type_id=? AND year=? FOR UPDATE', [$lr['user_id'], $lr['leave_type_id'], $year]);
          if (!$b || (float) $b['allocated'] - (float) $b['used'] < (float) $lr['days']) { return [$lr, 'Insufficient '.$lr['type_name'].' balance to approve. Adjust the balance or ask the employee to change the leave type.']; } }
        LeaveService::recordUsage($lr, $this->uid()); }
      DB::update('leave_requests', ['status' => $approve ? 'approved' : 'rejected', 'reviewed_by' => $this->uid(), 'reviewed_at' => date('Y-m-d H:i:s'), 'review_note' => $d['review_note']], 'id=?', [$id]);
      Audit::log('hr.leave.'.($approve ? 'approve' : 'reject'), 'leave_request', $id, ['user_id' => (int) $lr['user_id'], 'days' => (float) $lr['days']]);
      return [$lr, null]; });
    [$lr, $err] = $res;
    if ($err) { $this->flash($err, 'error'); return $this->back(); }
    $word = $d['decision'] === 'approve' ? 'approved' : 'rejected';
    $range = fdate($lr['start_date']).($lr['end_date'] !== $lr['start_date'] ? ' – '.fdate($lr['end_date']) : '');
    Notify::user((int) $lr['user_id'], 'Your leave was '.$word, $lr['type_name'].' · '.$range.' · '.(float) $lr['days'].' day(s) '.$word.' by '.$this->user()['name'].($d['review_note'] ? "\nNote: ".$d['review_note'] : ''), '/hr/leave', true, 'plane');
    $this->flash('Leave '.$word.' for '.$lr['name'].'.');
    return $this->back(); }

  public function calendar(): Response {
    $m = $this->request->str('month');
    $start = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m) ? $m.'-01' : date('Y-m-01');
    $end = date('Y-m-t', strtotime($start));
    $rows = DB::all("SELECT lr.user_id, lr.start_date, lr.end_date, lr.half_day, lr.status, u.name, t.name AS type_name, t.code, t.color FROM leave_requests lr JOIN users u ON u.id=lr.user_id JOIN leave_types t ON t.id=lr.leave_type_id WHERE u.status='active' AND lr.start_date<=? AND lr.end_date>=? AND (lr.status='approved' OR (lr.status='pending' AND lr.user_id=?)) ORDER BY u.name", [$end, $start, $this->uid()]);
    $byDay = [];
    LeaveService::schedules(array_column($rows, 'user_id'));
    foreach ($rows as $r) {
      for ($t = strtotime(max($r['start_date'], $start)), $e = strtotime(min($r['end_date'], $end)); $t <= $e; $t = strtotime('+1 day', $t)) {
        $day = date('Y-m-d', $t);
        if (LeaveService::isWorkingDayFor((int) $r['user_id'], $day)) { $byDay[$day][] = $r; } } }
    $hol = [];
    foreach (DB::all('SELECT holiday_date, name, optional FROM holidays WHERE holiday_date BETWEEN ? AND ? ORDER BY holiday_date', [$start, $end]) as $h) { $hol[$h['holiday_date']][] = $h; }
    $types = $this->can('hr.leave.approve') || $this->can('hr.employees.view');
    return $this->base('calendar', ['title' => 'Team leave calendar', 'start' => $start, 'end' => $end, 'byDay' => $byDay, 'hol' => $hol, 'showTypes' => $types, 'legend' => DB::all('SELECT name, code, color FROM leave_types WHERE active=1 ORDER BY id'), 'weekStart' => (int) setting('general.week_start', 1) === 7 ? 7 : 1]); }

  public function balances(): Response {
    $year = $this->request->int('year', (int) date('Y'));
    $year = max(2000, min(2100, $year));
    $q = $this->request->str('q');
    $types = DB::all('SELECT id, name, code, color, paid, annual_quota, allowance FROM leave_types WHERE active=1 ORDER BY paid DESC, id');
    $sql = "SELECT id, name, avatar, department, employee_code FROM users WHERE status='active'";
    $p = [];
    if ($q !== '') { $sql .= ' AND (name LIKE ? OR employee_code LIKE ?)'; array_push($p, DB::like($q), DB::like($q)); }
    $page = DB::paginate($sql.' ORDER BY name', $p, 20);
    $map = [];
    if ($page['items']) {
      $ids = array_map('intval', array_column($page['items'], 'id'));
      foreach (DB::all('SELECT * FROM leave_balances WHERE year=? AND user_id IN '.DB::in($ids), [$year, ...$ids]) as $b) { $map[$b['user_id']][$b['leave_type_id']] = $b; } }
    $stats = DB::first("SELECT COUNT(DISTINCT b.user_id) AS users, COALESCE(SUM(b.allocated),0) AS allocated, COALESCE(SUM(b.used),0) AS used FROM leave_balances b JOIN users u ON u.id=b.user_id AND u.status='active' WHERE b.year=?", [$year]);
    $stats['active'] = (int) DB::value("SELECT COUNT(*) FROM users WHERE status='active'");
    return $this->base('balances', ['title' => 'Leave balances', 'year' => $year, 'types' => $types, 'p' => $page, 'map' => $map, 'stats' => $stats, 'users' => DB::pairs("SELECT id, name FROM users WHERE status='active' ORDER BY name")]); }

  public function allocate(): Response {
    $year = $this->request->int('year', (int) date('Y'));
    if ($year < 2000 || $year > (int) date('Y') + 1) { $this->abort(422, 'Invalid year.'); }
    $n = LeaveService::allocateYear($year);
    Audit::log('hr.leave.allocate', 'leave_year', $year, ['rows' => $n]);
    $this->flash($n ? "Allocated $n new balance rows for $year." : "All active employees already have $year balances.");
    return $this->redirect('/hr/leave/balances?year='.$year); }

  public function adjust(): Response {
    $d = $this->validate(['user_id' => 'required|integer|exists:users,id', 'leave_type_id' => 'required|integer|exists:leave_types,id', 'year' => 'required|integer|min:2000|max:2100', 'delta' => 'required|numeric|min:-365|max:365', 'reason' => 'required|max:255'], ['delta' => 'Adjustment', 'leave_type_id' => 'Leave type', 'user_id' => 'Employee']);
    $delta = round((float) $d['delta'] * 2) / 2;
    if ($delta == 0) { throw new ValidationException(['delta' => 'Enter a non-zero adjustment in steps of 0.5.']); }
    $new = LeaveService::adjust((int) $d['user_id'], (int) $d['leave_type_id'], (int) $d['year'], $delta, $d['reason'], $this->uid());
    Audit::log('hr.leave.adjust', 'employee', (int) $d['user_id'], ['type_id' => (int) $d['leave_type_id'], 'year' => (int) $d['year'], 'delta' => $delta, 'allocated' => $new, 'reason' => $d['reason']]);
    Notify::user((int) $d['user_id'], 'Your leave balance was adjusted', ($delta > 0 ? '+' : '').$delta.' day(s) for '.$d['year'].'. '.$d['reason'], '/hr/leave', false, 'plane');
    $this->flash('Balance adjusted ('.($delta > 0 ? '+' : '').$delta.'). New allocation: '.$new.'.');
    return $this->redirect($this->request->str('return') === 'ledger' ? '/hr/leave/ledger?user='.(int) $d['user_id'].'&year='.(int) $d['year'] : '/hr/leave/balances?year='.$d['year']); }

  /** Ledger of one person (HR: anyone via ?user=, everyone else: their own) with balances per type. */
  public function ledger(): Response {
    $manage = $this->can('hr.leave.manage');
    $uid = $manage && $this->request->int('user') ? $this->request->int('user') : $this->uid();
    if (!$manage && !$this->can('hr.leave.request')) { $this->abort(403); }
    $emp = DB::first('SELECT id, name, avatar, department, designation, joined_on, status FROM users WHERE id=?', [$uid]) ?? $this->abort(404, 'Employee not found.');
    $year = max(2000, min(2100, $this->request->int('year', (int) date('Y'))));
    $typeId = $this->request->int('type') ?: null;
    $rows = LeaveService::ledgerFor($uid, $year, $typeId);
    $sums = [];
    foreach (DB::all('SELECT leave_type_id, SUM(GREATEST(days,0)) AS cr, SUM(LEAST(days,0)) AS dr FROM leave_ledger WHERE user_id=? AND year=? GROUP BY leave_type_id', [$uid, $year]) as $r) { $sums[(int) $r['leave_type_id']] = $r; }
    return $this->base('ledger', ['title' => 'Leave ledger', 'emp' => $emp, 'year' => $year, 'typeId' => $typeId, 'rows' => $rows, 'sums' => $sums, 'balances' => LeaveService::balances($uid, $year), 'manage' => $manage, 'self' => $uid === $this->uid(),
      'types' => DB::all('SELECT id, name, code, color FROM leave_types WHERE active=1 ORDER BY paid DESC, id'), 'users' => $manage ? DB::pairs("SELECT id, name FROM users WHERE status='active' ORDER BY name") : []]); }

  /** HR: credit a month's accrual now (same idempotent routine as the daily job). */
  public function accrue(): Response {
    $m = $this->request->str('month', date('Y-m'));
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $m) || $m > date('Y-m')) { $this->flash('Choose a month up to the current one.', 'error'); return $this->redirect('/hr/leave/balances'); }
    $r = LeaveService::accrueMonth($m, null, $this->uid());
    Audit::log('hr.leave.accrue', 'leave_month', null, ['month' => $m] + $r);
    $this->flash($r['credited'] ? 'Accrued '.LeaveService::num($r['days']).' day(s) in '.$r['credited'].' balance(s) for '.date('M Y', strtotime($m.'-01')).'.' : 'Everyone already has the accrual for '.date('M Y', strtotime($m.'-01')).'.');
    return $this->redirect('/hr/leave/balances?year='.substr($m, 0, 4)); }

  /** HR: close a past year now (carry forward / expiry, same idempotent routine as the 1 January job). */
  public function carry(): Response {
    $y = $this->request->int('year', (int) date('Y') - 1);
    if ($y < 2000 || $y >= (int) date('Y')) { $this->flash('Only a past year can be closed.', 'error'); return $this->redirect('/hr/leave/balances'); }
    LeaveService::allocateYear($y + 1);
    $n = LeaveService::carryForward($y, $this->uid());
    Audit::log('hr.leave.carry_forward', 'leave_year', $y, ['balances' => $n]);
    $this->flash($n ? "Closed $n balance(s) of $y: remainders carried forward or lapsed per each leave type." : "All $y balances were already closed.");
    return $this->redirect('/hr/leave/balances?year='.($y + 1)); }

  public function types(): Response {
    $types = DB::all('SELECT t.*, (SELECT COUNT(*) FROM leave_requests r WHERE r.leave_type_id=t.id) AS uses, (SELECT COUNT(*) FROM employee_leave_quotas q WHERE q.leave_type_id=t.id) AS overrides FROM leave_types t ORDER BY t.active DESC, t.code=? DESC, t.paid DESC, t.id', [LeaveService::OPTIONAL_CODE]);
    $oh = LeaveService::optionalType();
    return $this->base('types', ['title' => 'Leave types', 'types' => $types, 'colors' => self::COLORS, 'optional' => $oh, 'cutoff' => LeaveService::accrualCutoff(), 'shortageOn' => Settings::bool('hr.leave.shortage_cover', true), 'ohDefault' => (float) Settings::get('hr.optional_holidays_default', $oh['annual_quota'] ?? 15),
      'optionalDays' => (int) DB::value('SELECT COUNT(*) FROM holidays WHERE optional=1 AND holiday_date BETWEEN ? AND ?', [date('Y-01-01'), date('Y-12-31')])]); }

  /** HR setting hr.optional_holidays_default: yearly optional-holiday pool; keeps the OH leave type quota in sync. */
  public function optionalQuota(): Response {
    $d = $this->validate(['quota' => 'required|numeric|min:0|max:365', 'apply_current' => 'boolean'], ['quota' => 'Optional days per year']);
    $q = round((float) $d['quota'] * 2) / 2;
    $oh = DB::first('SELECT * FROM leave_types WHERE code=?', [LeaveService::OPTIONAL_CODE]) ?? throw new ValidationException(['quota' => 'The Optional Holiday (OH) leave type is missing. Create a leave type with code OH first.']);
    $year = (int) date('Y');
    $n = DB::transaction(static function () use ($q, $oh, $d, $year): int {
      $old = (float) $oh['annual_quota'];
      Settings::set('hr.optional_holidays_default', (string) $q);
      DB::update('leave_types', ['annual_quota' => $q], 'id=?', [$oh['id']]);
      if (!$d['apply_current'] || $q == $old) { return 0; }
      return DB::query('UPDATE leave_balances b LEFT JOIN employee_leave_quotas eq ON eq.user_id=b.user_id AND eq.leave_type_id=b.leave_type_id SET b.allocated=GREATEST(0, b.allocated + ?) WHERE b.leave_type_id=? AND b.year=? AND eq.user_id IS NULL', [$q - $old, $oh['id'], $year])->rowCount(); });
    Audit::log('hr.leave.optional_quota', 'leave_type', (int) $oh['id'], ['from' => (float) $oh['annual_quota'], 'to' => $q, 'balances_updated' => $n]);
    $this->flash('Optional holidays set to '.rtrim(rtrim(number_format($q, 1), '0'), '.').' days per year'.($n ? ", $n employee balance(s) for $year updated." : '.'));
    return $this->redirect('/hr/leave/types'); }

  private function typeData(?int $id): array {
    $in = $this->request->all();
    $in['code'] = strtoupper(trim((string) ($in['code'] ?? '')));
    foreach (['carry_limit', 'max_balance', 'max_consecutive'] as $k) { if (trim((string) ($in[$k] ?? '')) === '') { $in[$k] = null; } }
    foreach (['annual_quota', 'monthly_accrual', 'notice_days', 'min_service_days'] as $k) { if (trim((string) ($in[$k] ?? '')) === '') { $in[$k] = 0; } }
    $d = \Core\Validator::make($in, ['name' => 'required|max:100', 'code' => 'required|regex:/^[A-Z0-9_]{1,20}$/|unique:leave_types,code'.($id ? ",$id" : ''), 'description' => 'nullable|max:255', 'paid' => 'boolean', 'color' => 'required|in:'.implode(',', self::COLORS), 'active' => 'boolean',
      'allowance' => 'required|in:'.implode(',', array_keys(LeaveService::ALLOWANCES)), 'annual_quota' => 'numeric|min:0|max:365', 'monthly_accrual' => 'numeric|min:0|max:31', 'carry_forward' => 'required|in:'.implode(',', array_keys(LeaveService::CARRY)),
      'carry_limit' => 'nullable|numeric|min:0|max:365', 'max_balance' => 'nullable|numeric|min:0|max:999', 'allow_half_day' => 'boolean', 'needs_approval' => 'boolean', 'notice_days' => 'integer|min:0|max:365', 'max_consecutive' => 'nullable|numeric|min:0.5|max:365',
      'min_service_days' => 'integer|min:0|max:3650', 'shortage_cover' => 'boolean'],
      ['annual_quota' => 'Yearly quota', 'monthly_accrual' => 'Days per month', 'carry_limit' => 'Carry forward limit', 'max_balance' => 'Maximum balance', 'notice_days' => 'Notice', 'max_consecutive' => 'Maximum consecutive days', 'min_service_days' => 'Service days']);
    $et = array_values(array_intersect(array_keys(LeaveService::EMPLOYMENT), array_map('strval', (array) ($in['employment_types'] ?? []))));
    $d['employment_types'] = $et && count($et) < count(LeaveService::EMPLOYMENT) ? implode(',', $et) : null;
    $half = static fn ($v) => $v === null ? null : round((float) $v * 2) / 2;
    $d['annual_quota'] = $half($d['annual_quota']); $d['monthly_accrual'] = round((float) $d['monthly_accrual'], 2);
    foreach (['carry_limit', 'max_balance', 'max_consecutive'] as $k) { $d[$k] = $half($d[$k]); }
    $err = [];
    if ($d['allowance'] === 'yearly' && $d['annual_quota'] <= 0 && $d['paid']) { $err['annual_quota'] = 'Enter the yearly quota, or choose another allowance.'; }
    if ($d['allowance'] === 'monthly' && $d['monthly_accrual'] <= 0) { $err['monthly_accrual'] = 'Enter the days credited every month, e.g. 2.5.'; }
    if ($d['carry_forward'] === 'limit' && $d['carry_limit'] === null) { $err['carry_limit'] = 'Enter how many days may be carried forward.'; }
    if (!$d['paid'] && $d['shortage_cover']) { $err['shortage_cover'] = 'Only a paid leave type can cover a shortage of hours.'; }
    if ($err) { throw new ValidationException($err); }
    if ($d['carry_forward'] !== 'limit') { $d['carry_limit'] = null; }
    if (!$d['paid']) { $d['allowance'] = 'none'; $d['carry_forward'] = 'none'; }
    return $d; }

  public function storeType(): Response {
    $d = $this->typeData(null);
    $id = DB::insert('leave_types', $d);
    Audit::log('hr.leave_type.create', 'leave_type', $id, $d);
    $this->flash($d['allowance'] === 'monthly' ? 'Leave type added. It accrues '.LeaveService::num($d['monthly_accrual']).' day(s) on the 1st of every month (run the accrual on the balances page to credit this month now).' : 'Leave type added. Use "Allocate" on the balances page to grant it.');
    return $this->redirect('/hr/leave/types'); }

  public function updateType(int $id): Response {
    $t = $this->findOr404('leave_types', $id);
    $d = $this->typeData($id);
    if ($t['code'] === LeaveService::OPTIONAL_CODE && $d['code'] !== $t['code']) { throw new ValidationException(['code' => 'The OH code powers optional festival days and cannot be changed.']); }
    DB::update('leave_types', $d, 'id=?', [$id]);
    if ($d['code'] === LeaveService::OPTIONAL_CODE) { Settings::set('hr.optional_holidays_default', (string) (float) $d['annual_quota']); }
    Audit::log('hr.leave_type.update', 'leave_type', $id, $d);
    $this->flash('Leave type updated.');
    return $this->redirect('/hr/leave/types'); }

  public function destroyType(int $id): Response {
    $t = $this->findOr404('leave_types', $id);
    if ($t['code'] === LeaveService::OPTIONAL_CODE) { $this->flash('The Optional Holiday type powers festival days and cannot be deleted. Edit it to deactivate instead.', 'error'); return $this->redirect('/hr/leave/types'); }
    if (DB::value('SELECT 1 FROM leave_requests WHERE leave_type_id=? LIMIT 1', [$id])) {
      DB::update('leave_types', ['active' => 0], 'id=?', [$id]);
      Audit::log('hr.leave_type.deactivate', 'leave_type', $id, ['name' => $t['name']]);
      $this->flash('This type has requests on record, so it was deactivated instead of deleted.');
      return $this->redirect('/hr/leave/types'); }
    DB::delete('leave_types', 'id=?', [$id]);
    Audit::log('hr.leave_type.delete', 'leave_type', $id, ['name' => $t['name']]);
    $this->flash('Leave type deleted.');
    return $this->redirect('/hr/leave/types'); } }
