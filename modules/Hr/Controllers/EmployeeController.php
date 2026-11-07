<?php
namespace Modules\Hr\Controllers;
use Core\{Audit, Auth, Controller, Crypto, DB, Modules, Notify, Response, Storage, ValidationException};
use Modules\Hr\Services\{HrAccess, LeaveService, ProcessService as PS};

/** Employee list / directory, HR profile, documents, onboarding & offboarding checklists. */
final class EmployeeController extends Controller {
  public const TYPES = ['full_time' => 'Full-time', 'part_time' => 'Part-time', 'contract' => 'Contract', 'intern' => 'Intern', 'consultant' => 'Consultant'];
  public const DOC_CATS = ['id_proof' => 'ID proof', 'agreement' => 'Agreement', 'other' => 'Other'];
  private const CHECKLISTS = ['onboarding', 'offboarding'];

  public function index(): Response {
    if (!$this->can('hr.employees.view')) { return $this->directory(); }
    $q = $this->request->str('q'); $dept = $this->request->str('department'); $status = $this->request->str('status'); $type = $this->request->str('type');
    $sql = 'SELECT u.id, u.name, u.email, u.phone, u.whatsapp, u.avatar, u.status, u.designation, u.department, u.employee_code, u.joined_on, m.name AS manager_name, p.employment_type, p.exit_date FROM users u LEFT JOIN users m ON m.id=u.manager_id LEFT JOIN employee_profiles p ON p.user_id=u.id WHERE 1=1';
    $p = [];
    if ($q !== '') { $sql .= ' AND (u.name LIKE ? OR u.email LIKE ? OR u.employee_code LIKE ? OR u.designation LIKE ?)'; array_push($p, DB::like($q), DB::like($q), DB::like($q), DB::like($q)); }
    if ($dept !== '') { $sql .= ' AND u.department=?'; $p[] = $dept; }
    if (in_array($status, ['active', 'inactive', 'invited'], true)) { $sql .= ' AND u.status=?'; $p[] = $status; }
    if (isset(self::TYPES[$type])) { $sql .= " AND COALESCE(p.employment_type,'full_time')=?"; $p[] = $type; }
    $page = DB::paginate($sql." ORDER BY u.status='active' DESC, u.name", $p, 20);
    $ids = array_map('intval', array_column($page['items'], 'id'));
    $progress = $ids ? DB::all("SELECT user_id, type, COUNT(*) AS total, SUM(done_at IS NOT NULL) AS done FROM employee_checklists WHERE user_id IN ".DB::in($ids).' GROUP BY user_id, type', $ids) : [];
    $prog = [];
    foreach ($progress as $r) { $prog[(int) $r['user_id']][$r['type']] = $r; }
    if ($ids) { foreach (DB::all("SELECT p.user_id, COUNT(t.id) AS total, SUM(t.status IN ('done','skipped')) AS done FROM hr_processes p JOIN hr_process_tasks t ON t.process_id=p.id WHERE p.kind='onboarding' AND p.status<>'cancelled' AND p.user_id IN ".DB::in($ids).' AND p.id=(SELECT MAX(x.id) FROM hr_processes x WHERE x.user_id=p.user_id AND x.kind=\'onboarding\' AND x.status<>\'cancelled\') GROUP BY p.user_id', $ids) as $r) { $prog[(int) $r['user_id']]['onboarding'] = $r; } }
    $today = date('Y-m-d');
    $out = array_fill_keys(array_map('intval', DB::column("SELECT DISTINCT user_id FROM leave_requests WHERE status='approved' AND start_date<=? AND end_date>=?", [$today, $today])), true);
    $kpi = DB::first("SELECT SUM(status='active') AS headcount, SUM(status='active' AND joined_on BETWEEN ? AND ?) AS joined FROM users", [date('Y-m-01'), date('Y-m-t')]);
    $kpi['out'] = (int) DB::value("SELECT COUNT(DISTINCT lr.user_id) FROM leave_requests lr JOIN users u ON u.id=lr.user_id WHERE u.status='active' AND lr.status='approved' AND lr.start_date<=? AND lr.end_date>=?", [$today, $today]);
    $kpi['onboarding'] = (int) DB::value("SELECT COUNT(DISTINCT p.user_id) FROM hr_processes p JOIN users u ON u.id=p.user_id WHERE p.kind='onboarding' AND p.status IN ('active','paused') AND u.status<>'inactive'") + (int) DB::value("SELECT COUNT(*) FROM (SELECT c.user_id FROM employee_checklists c JOIN users u ON u.id=c.user_id WHERE c.type='onboarding' AND u.status<>'inactive' AND NOT EXISTS (SELECT 1 FROM hr_processes p WHERE p.user_id=c.user_id AND p.kind='onboarding') GROUP BY c.user_id HAVING SUM(c.done_at IS NULL)>0) x");
    return $this->view('hr::employees/index', ['title' => 'Employees', 'p' => $page, 'prog' => $prog, 'out' => $out, 'kpi' => $kpi, 'departments' => $this->departments(), 'sched' => $ids ? LeaveService::schedules($ids) : [], 'styles' => ['css/hr.css']]); }

  private function directory(): Response {
    $q = $this->request->str('q'); $dept = $this->request->str('department');
    $sql = "SELECT id, name, email, phone, whatsapp, avatar, designation, department FROM users WHERE status='active'";
    $p = [];
    if ($q !== '') { $sql .= ' AND (name LIKE ? OR designation LIKE ? OR department LIKE ? OR email LIKE ?)'; array_push($p, DB::like($q), DB::like($q), DB::like($q), DB::like($q)); }
    if ($dept !== '') { $sql .= ' AND department=?'; $p[] = $dept; }
    $page = DB::paginate($sql.' ORDER BY name', $p, 24);
    $today = date('Y-m-d');
    $out = array_fill_keys(array_map('intval', DB::column("SELECT DISTINCT user_id FROM leave_requests WHERE status='approved' AND start_date<=? AND end_date>=?", [$today, $today])), true);
    return $this->view('hr::employees/directory', ['title' => 'Team directory', 'p' => $page, 'out' => $out, 'departments' => $this->departments(), 'total' => (int) DB::value("SELECT COUNT(*) FROM users WHERE status='active'"), 'styles' => ['css/hr.css']]); }

  private function departments(): array { return DB::column("SELECT DISTINCT department FROM users WHERE department IS NOT NULL AND department<>'' AND status<>'inactive' ORDER BY department"); }

  public function me(): Response { return $this->redirect('/hr/employees/'.$this->uid()); }

  /** Loads the employee and enforces record-level access. */
  private function employee(int $id): array {
    $u = DB::first('SELECT u.*, r.name AS role_name, r.slug AS role_slug, m.name AS manager_name FROM users u JOIN roles r ON r.id=u.role_id LEFT JOIN users m ON m.id=u.manager_id WHERE u.id=?', [$id]) ?? $this->abort(404, 'Employee not found.');
    if (!HrAccess::canViewProfile($id)) { Audit::log('access.denied', 'employee', $id, ['path' => $this->request->path()]); $this->abort(403, 'You can only view your own HR profile.'); }
    unset($u['password_hash']);
    return $u; }

  private function profile(int $id): array { return DB::first('SELECT * FROM employee_profiles WHERE user_id=?', [$id]) ?? ['user_id' => $id, 'employment_type' => 'full_time']; }

  public function show(int $id): Response {
    $emp = $this->employee($id);
    $prof = $this->profile($id);
    $self = $id === $this->uid();
    $hr = $this->can('hr.employees.view');
    $leaveOn = feature_on('leave');
    $tabs = ['overview' => 'Overview', 'schedule' => $leaveOn ? 'Work schedule & leave' : 'Work schedule'] + ($leaveOn ? ['leave' => 'Leave history'] : []);
    if (HrAccess::canViewDocuments($id)) { $tabs['documents'] = 'Documents'; }
    $tabs['checklists'] = 'Onboarding & exit';
    $tab = $this->request->str('tab', 'overview');
    if (!isset($tabs[$tab])) { $tab = 'overview'; }
    $year = (int) date('Y');
    $data = ['title' => $emp['name'], 'emp' => $emp, 'prof' => $prof, 'self' => $self, 'hr' => $hr, 'tab' => $tab, 'tabs' => $tabs, 'types' => self::TYPES, 'year' => $year, 'styles' => ['css/hr.css'], 'scripts' => ['js/hr.js'],
      'masked' => ['bank' => HrAccess::mask($prof['bank_account_enc'] ?? null), 'tax' => HrAccess::mask($prof['tax_id_enc'] ?? null)],
      'onLeave' => $leaveOn && LeaveService::onLeave($id, date('Y-m-d')), 'balances' => $leaveOn ? LeaveService::balances($id, $year) : []];
    $cl = DB::all('SELECT c.*, d.name AS done_by_name FROM employee_checklists c LEFT JOIN users d ON d.id=c.done_by WHERE c.user_id=? ORDER BY c.type, c.position, c.id', [$id]);
    $data['checklists'] = ['onboarding' => [], 'offboarding' => []];
    foreach ($cl as $c) { $data['checklists'][$c['type']][] = $c; }
    if ($tab === 'overview') { $data['procs'] = PS::forEmployee($id); $data['reports'] = DB::all("SELECT id, name, avatar, designation FROM users WHERE manager_id=? AND status='active' ORDER BY name LIMIT 12", [$id]); }
    if ($tab === 'documents') { $data['docs'] = DB::all("SELECT f.*, u.name AS uploader FROM files f LEFT JOIN users u ON u.id=f.uploaded_by WHERE f.module='hr' AND f.entity_type='employee' AND f.entity_id=? ORDER BY f.category, f.id DESC", [$id]); $data['cats'] = self::DOC_CATS; }
    if ($tab === 'leave') { $data['history'] = DB::paginate('SELECT lr.*, t.name AS type_name, t.color, r.name AS reviewer FROM leave_requests lr JOIN leave_types t ON t.id=lr.leave_type_id LEFT JOIN users r ON r.id=lr.reviewed_by WHERE lr.user_id=? ORDER BY lr.start_date DESC, lr.id DESC', [$id], 15); }
    if ($tab === 'checklists') { $data += $this->processData($id); }
    $data['schedule'] = LeaveService::scheduleFor($id);
    if ($tab === 'schedule') { $data += $this->scheduleData($id, $year); }
    return $this->view('hr::employees/show', $data); }

  /** Onboarding & exit tab: every process of the employee, full task lists of the open ones, and templates for HR to start a new one. */
  private function processData(int $id): array {
    $procs = PS::forEmployee($id);
    $open = [];
    foreach ($procs as $p) { if (in_array($p['status'], ['active', 'paused'], true) && !isset($open[$p['kind']])) { $open[$p['kind']] = ['proc' => $p] + PS::details($p); } }
    return ['procs' => $procs, 'open' => $open, 'templates' => PS::isHr() ? ['onboarding' => PS::templates('onboarding'), 'exit' => PS::templates('exit')] : []]; }

  /** Work schedule tab: effective hours/off days, per-type quotas with this year's balances, and the current pay rate (read-only). */
  private function scheduleData(int $id, int $year): array {
    $bal = array_column(LeaveService::balances($id, $year), null, 'id');
    $pay = null;
    if (feature_on('payroll')) {
      try { $pay = DB::first('SELECT pay_type, amount, effective_from FROM compensations WHERE user_id=? AND effective_from<=CURDATE() ORDER BY effective_from DESC, id DESC LIMIT 1', [$id]); }
      catch (\Throwable $e) { error_log('HR schedule tab: compensation lookup failed: '.$e->getMessage()); } }
    return ['quotas' => LeaveService::quotasFor($id), 'bal' => $bal, 'pay' => $pay, 'companyDays' => LeaveService::weekendDays(), 'companyHours' => LeaveService::companyHours(),
      'monthDays' => LeaveService::workingDaysFor($id, date('Y-m-01'), date('Y-m-t')), 'companyMonthDays' => LeaveService::workingDays(date('Y-m-01'), date('Y-m-t'))]; }

  /** HR: employee-specific hours per day, weekly off days and yearly quota overrides (current-year balances follow the change). */
  public function updateSchedule(int $id): Response {
    $emp = $this->employee($id);
    $in = $this->request->all();
    $errors = [];
    $hours = null;
    if (($in['hours_mode'] ?? 'default') === 'custom') {
      $h = (string) ($in['work_hours_per_day'] ?? '');
      if (!is_numeric($h) || (float) $h < 0.5 || (float) $h > 16) { $errors['work_hours_per_day'] = 'Enter working hours between 0.5 and 16.'; } else { $hours = round((float) $h * 4) / 4; } }
    $days = null; $sat = null;
    if (($in['days_mode'] ?? 'default') === 'custom') {
      $raw = array_map('intval', (array) ($in['off_days'] ?? []));
      if (array_diff($raw, range(1, 7))) { $errors['off_days'] = 'Choose valid weekdays.'; }
      elseif (count(array_unique($raw)) > 6) { $errors['off_days'] = 'At least one working day per week is required.'; }
      else { $days = implode(',', LeaveService::parseDays(implode(',', $raw))); }
      $sat = (string) ($in['saturday_rule'] ?? 'full');
      if (!\Core\WorkWeek::valid($sat)) { $errors['saturday_rule'] = 'Choose a valid Saturday option.'; } }
    $types = DB::pairs('SELECT id, annual_quota FROM leave_types WHERE active=1');
    $over = [];
    foreach ((array) ($in['quota'] ?? []) as $tid => $v) {
      $tid = (int) $tid; $v = trim((string) $v);
      if (!isset($types[$tid])) { continue; }
      if ($v === '') { $over[$tid] = null; continue; }
      if (!is_numeric($v) || (float) $v < 0 || (float) $v > 365) { $errors['quota_'.$tid] = 'Quota must be between 0 and 365 days.'; continue; }
      $over[$tid] = round((float) $v * 2) / 2; }
    if ($errors) { throw new ValidationException($errors); }
    $year = (int) date('Y');
    $changed = DB::transaction(function () use ($id, $hours, $days, $sat, $over, $types, $year): array {
      $this->saveProfile($id, ['work_hours_per_day' => $hours, 'weekly_off_days' => $days, 'saturday_rule' => $days === null ? null : ($sat ?? 'full')]);
      $old = DB::pairs('SELECT leave_type_id, annual_quota FROM employee_leave_quotas WHERE user_id=?', [$id]);
      $log = [];
      foreach ($over as $tid => $q) {
        $before = isset($old[$tid]) ? (float) $old[$tid] : null;
        if ($q !== null && (float) $q === (float) $types[$tid]) { $q = null; }
        if ($before === $q) { continue; }
        if ($q === null) { DB::delete('employee_leave_quotas', 'user_id=? AND leave_type_id=?', [$id, $tid]); }
        else { DB::query('INSERT INTO employee_leave_quotas (user_id, leave_type_id, annual_quota) VALUES (?,?,?) ON DUPLICATE KEY UPDATE annual_quota=VALUES(annual_quota)', [$id, $tid, $q]); }
        $delta = (float) ($q ?? $types[$tid]) - (float) ($before ?? $types[$tid]);
        if ($delta != 0) { DB::query('UPDATE leave_balances SET allocated=GREATEST(0, allocated + ?) WHERE user_id=? AND leave_type_id=? AND year=?', [$delta, $id, $tid, $year]); }
        $log[$tid] = ['from' => $before, 'to' => $q, 'balance_delta' => $delta]; }
      return $log; });
    LeaveService::flush();
    Audit::log('hr.employee.schedule', 'employee', $id, ['hours_per_day' => $hours, 'off_days' => $days, 'quotas' => $changed]);
    $this->flash('Work schedule saved for '.$emp['name'].($changed ? ', '.count($changed).' quota change(s) applied to the '.$year.' balance.' : '.'));
    return $this->redirect('/hr/employees/'.$id.'?tab=schedule'); }

  public function edit(int $id): Response {
    $emp = $this->employee($id);
    $prof = $this->profile($id);
    return $this->view('hr::employees/edit', $this->workData($id) + ['title' => 'Edit HR profile', 'emp' => $emp, 'prof' => $prof, 'types' => self::TYPES, 'masked' => ['bank' => HrAccess::mask($prof['bank_account_enc'] ?? null), 'tax' => HrAccess::mask($prof['tax_id_enc'] ?? null)], 'styles' => ['css/hr.css'], 'scripts' => ['js/hr.js']]); }

  /** Work hours, pay and this year's leave balances for the edit form (each part only when its feature and permission apply). */
  private function workData(int $id): array {
    $year = (int) date('Y');
    $payOn = feature_on('payroll') && $this->can('payroll.compensation.manage');
    $leaveOn = feature_on('leave') && $this->can('hr.leave.manage');
    return ['schedule' => LeaveService::scheduleFor($id), 'companyDays' => LeaveService::weekendDays(), 'companyHours' => LeaveService::companyHours(), 'year' => $year,
      'payEdit' => $payOn, 'pay' => $payOn ? DB::first('SELECT pay_type, amount, effective_from FROM compensations WHERE user_id=? AND effective_from<=CURDATE() ORDER BY effective_from DESC, id DESC LIMIT 1', [$id]) : null,
      'leaveEdit' => $leaveOn, 'balances' => $leaveOn ? LeaveService::balances($id, $year) : []]; }

  /** Validated work-hours, pay and leave-balance input of the edit form: [profile columns, pay revision|null, [type_id => allocated], errors]. */
  private function workInput(int $id, array $in): array {
    $err = []; $prof = []; $pay = null; $alloc = [];
    $h = trim((string) ($in['work_hours_per_day'] ?? ''));
    if ($h === '') { $prof['work_hours_per_day'] = null; }
    elseif (!is_numeric($h) || (float) $h < 0.5 || (float) $h > 16) { $err['work_hours_per_day'] = 'Enter working hours between 0.5 and 16, or leave empty for the company default.'; }
    else { $prof['work_hours_per_day'] = round((float) $h * 4) / 4; }
    if (($in['days_mode'] ?? 'default') === 'custom') {
      $raw = array_map('intval', (array) ($in['off_days'] ?? []));
      $sat = (string) ($in['saturday_rule'] ?? 'full');
      if (array_diff($raw, range(1, 7))) { $err['off_days'] = 'Choose valid weekdays.'; }
      elseif (count(array_unique($raw)) > 6) { $err['off_days'] = 'At least one working day per week is required.'; }
      elseif (!\Core\WorkWeek::valid($sat)) { $err['saturday_rule'] = 'Choose a valid Saturday option.'; }
      else { $prof['weekly_off_days'] = implode(',', LeaveService::parseDays(implode(',', $raw))); $prof['saturday_rule'] = $sat; } }
    else { $prof['weekly_off_days'] = null; $prof['saturday_rule'] = null; }
    if (feature_on('payroll') && $this->can('payroll.compensation.manage') && trim((string) ($in['pay_amount'] ?? '')) !== '') {
      $amt = (string) $in['pay_amount']; $type = (string) ($in['pay_type'] ?? 'monthly'); $from = (string) ($in['pay_effective'] ?? '');
      if (!in_array($type, ['monthly', 'hourly'], true)) { $err['pay_type'] = 'Choose monthly salary or hourly rate.'; }
      elseif (!is_numeric($amt) || (float) $amt <= 0 || (float) $amt > 999999999) { $err['pay_amount'] = 'Enter an amount greater than zero.'; }
      elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !strtotime($from)) { $err['pay_effective'] = 'Choose the date the pay applies from.'; }
      else { $pay = ['pay_type' => $type, 'amount' => round((float) $amt, 2), 'effective_from' => $from]; } }
    if (feature_on('leave') && $this->can('hr.leave.manage')) {
      foreach ((array) ($in['leave_alloc'] ?? []) as $tid => $v) {
        $v = trim((string) $v);
        if ($v === '') { continue; }
        if (!is_numeric($v) || (float) $v < 0 || (float) $v > 999) { $err['leave_alloc_'.(int) $tid] = 'Balance must be between 0 and 999 days.'; continue; }
        $alloc[(int) $tid] = round((float) $v * 2) / 2; } }
    return [$prof, $pay, $alloc, $err]; }

  public function update(int $id): Response {
    $this->employee($id);
    [$work, $pay, $alloc, $werr] = $this->workInput($id, $this->request->all());
    if ($werr) { throw new ValidationException($werr); }
    $d = $this->validate(['employment_type' => 'required|in:'.implode(',', array_keys(self::TYPES)), 'date_of_birth' => 'nullable|date', 'gender' => 'nullable|max:20', 'personal_email' => 'nullable|email|max:190', 'address' => 'nullable|max:1000',
      'emergency_name' => 'nullable|max:150', 'emergency_phone' => 'nullable|phone', 'bank_name' => 'nullable|max:120', 'bank_account' => 'nullable|regex:/^[A-Za-z0-9 \-]{4,34}$/', 'bank_ifsc' => 'nullable|regex:/^[A-Za-z0-9 \-]{3,30}$/',
      'tax_id' => 'nullable|regex:/^[A-Za-z0-9 \-\/]{4,30}$/', 'probation_end' => 'nullable|date', 'notes' => 'nullable|max:5000'], ['date_of_birth' => 'Date of birth', 'bank_ifsc' => 'IFSC / routing code', 'tax_id' => 'Tax ID']);
    if ($d['date_of_birth'] && $d['date_of_birth'] > date('Y-m-d', strtotime('-14 years'))) { throw new ValidationException(['date_of_birth' => 'Date of birth looks incorrect.']); }
    $bank = $d['bank_account']; $tax = $d['tax_id'];
    unset($d['bank_account'], $d['tax_id']);
    $changed = [];
    if ($bank !== null) { $d['bank_account_enc'] = Crypto::encrypt(preg_replace('/\s+/', '', $bank)); $changed[] = 'bank_account'; }
    elseif ($this->request->input('clear_bank')) { $d['bank_account_enc'] = null; $changed[] = 'bank_account(cleared)'; }
    if ($tax !== null) { $d['tax_id_enc'] = Crypto::encrypt(strtoupper(preg_replace('/\s+/', '', $tax))); $changed[] = 'tax_id'; }
    elseif ($this->request->input('clear_tax')) { $d['tax_id_enc'] = null; $changed[] = 'tax_id(cleared)'; }
    $year = (int) date('Y');
    $notes = DB::transaction(function () use ($id, $d, $work, $pay, $alloc, $year): array {
      $this->saveProfile($id, $d + $work);
      $notes = [];
      if ($pay) {
        $cur = DB::first('SELECT id, pay_type, amount, effective_from FROM compensations WHERE user_id=? AND effective_from<=? ORDER BY effective_from DESC, id DESC LIMIT 1', [$id, $pay['effective_from']]);
        if (!$cur || $cur['pay_type'] !== $pay['pay_type'] || (float) $cur['amount'] !== $pay['amount']) {
          if ($cur && $cur['effective_from'] === $pay['effective_from']) { DB::update('compensations', $pay, 'id=?', [(int) $cur['id']]); }
          else { DB::insert('compensations', $pay + ['user_id' => $id, 'note' => 'Set on the HR profile', 'created_by' => $this->uid(), 'created_at' => date('Y-m-d H:i:s')]); }
          Audit::log('payroll.compensation.create', 'user', $id, $pay + ['via' => 'hr_profile']);
          $notes[] = 'pay'; } }
      if ($alloc) {
        $types = array_map('intval', DB::column('SELECT id FROM leave_types'));
        $old = DB::pairs('SELECT leave_type_id, allocated FROM leave_balances WHERE user_id=? AND year=?', [$id, $year]);
        foreach ($alloc as $tid => $v) {
          if (!in_array($tid, $types, true) || (float) ($old[$tid] ?? 0) === $v) { continue; }
          DB::query('INSERT INTO leave_balances (user_id, leave_type_id, year, allocated, used) VALUES (?,?,?,?,0) ON DUPLICATE KEY UPDATE allocated=VALUES(allocated)', [$id, $tid, $year, $v]);
          Audit::log('hr.leave.adjust', 'employee', $id, ['type_id' => $tid, 'year' => $year, 'allocated' => $v, 'before' => isset($old[$tid]) ? (float) $old[$tid] : null, 'via' => 'hr_profile']);
          $notes[] = 'leave'; } }
      return array_unique($notes); });
    LeaveService::flush();
    if (in_array('leave', $notes, true)) { Notify::user($id, 'Your leave balance was updated', 'HR updated your '.$year.' leave balance.', '/hr/leave', false, 'plane'); }
    Audit::log('hr.employee.update', 'employee', $id, ['fields' => array_merge(array_keys(array_diff_key($d + $work, ['bank_account_enc' => 1, 'tax_id_enc' => 1])), $changed)]);
    $this->flash('HR profile saved.');
    return $this->redirect('/hr/employees/'.$id); }

  /** Employees may update their own emergency contact & personal email. */
  public function updateSelf(int $id): Response {
    if ($id !== $this->uid()) { $this->abort(403, 'You can only update your own details.'); }
    $d = $this->validate(['personal_email' => 'nullable|email|max:190', 'emergency_name' => 'nullable|max:150', 'emergency_phone' => 'nullable|phone'], ['emergency_name' => 'Emergency contact name', 'emergency_phone' => 'Emergency contact phone']);
    $this->saveProfile($id, $d);
    Audit::log('hr.employee.self_update', 'employee', $id, ['fields' => array_keys($d)]);
    $this->flash('Your contact details were updated.');
    return $this->redirect('/hr/employees/'.$id); }

  private function saveProfile(int $id, array $d): void {
    $d['updated_at'] = date('Y-m-d H:i:s');
    $cols = array_keys($d);
    $sql = 'INSERT INTO employee_profiles (user_id,'.implode(',', array_map([DB::class, 'id'], $cols)).') VALUES (?'.str_repeat(',?', count($cols)).') ON DUPLICATE KEY UPDATE '.implode(',', array_map(static fn ($c) => DB::id($c).'=VALUES('.DB::id($c).')', $cols));
    DB::query($sql, [$id, ...array_values($d)]); }

  /** Explicit, audit-logged reveal of encrypted bank account / tax id. */
  public function reveal(int $id): Response {
    $this->employee($id);
    $p = $this->profile($id);
    Audit::log('hr.employee.reveal', 'employee', $id, ['fields' => ['bank_account', 'tax_id']]);
    return $this->json(['bank' => Crypto::decrypt($p['bank_account_enc'] ?? null) ?? '', 'tax' => Crypto::decrypt($p['tax_id_enc'] ?? null) ?? '']); }

  public function upload(int $id): Response {
    $this->employee($id);
    $cat = $this->request->str('category');
    if (!isset(self::DOC_CATS[$cat])) { throw new ValidationException(['category' => 'Choose a document category.']); }
    $file = $this->request->file('file') ?? throw new ValidationException(['file' => 'Choose a file to upload.']);
    Storage::attach($file, 'hr', 'employee', $id, $cat, ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'doc', 'docx']);
    $this->flash('Document uploaded securely.');
    return $this->redirect('/hr/employees/'.$id.'?tab=documents'); }

  public function generateChecklist(int $id, string $type): Response {
    $this->employee($id);
    if (!in_array($type, self::CHECKLISTS, true)) { $this->abort(404); }
    $n = $this->generate($id, $type);
    $this->flash($n ? ucfirst($type).' checklist created with '.$n.' items.' : 'Checklist already exists or there are no template items.', $n ? 'success' : 'error');
    return $this->redirect('/hr/employees/'.$id.'?tab=checklists'); }

  private function generate(int $id, string $type): int {
    return DB::transaction(static function () use ($id, $type): int {
      if (DB::value('SELECT 1 FROM employee_checklists WHERE user_id=? AND type=? LIMIT 1 FOR UPDATE', [$id, $type])) { return 0; }
      $n = DB::query('INSERT INTO employee_checklists (user_id, type, title, position) SELECT ?, type, title, position FROM checklist_templates WHERE type=? ORDER BY position, id', [$id, $type])->rowCount();
      if ($n) { Audit::log('hr.checklist.generate', 'employee', $id, ['type' => $type, 'items' => $n]); }
      return $n; }); }

  public function toggleItem(int $id): Response {
    $item = $this->findOr404('employee_checklists', $id);
    $this->employee((int) $item['user_id']);
    $done = $item['done_at'] === null;
    DB::update('employee_checklists', ['done_at' => $done ? date('Y-m-d H:i:s') : null, 'done_by' => $done ? $this->uid() : null], 'id=?', [$id]);
    Audit::log('hr.checklist.'.($done ? 'done' : 'undone'), 'employee', (int) $item['user_id'], ['item' => $item['title'], 'type' => $item['type']]);
    if ($this->request->wantsJson()) { return $this->json(['done' => $done]); }
    return $this->redirect('/hr/employees/'.$item['user_id'].'?tab=checklists'); }

  public function offboard(int $id): Response {
    $emp = $this->employee($id);
    $d = $this->validate(['exit_date' => 'required|date', 'exit_reason' => 'required|max:255'], ['exit_reason' => 'Exit reason']);
    if ($emp['joined_on'] && $d['exit_date'] < $emp['joined_on']) { throw new ValidationException(['exit_date' => 'Exit date cannot be before the joining date.']); }
    $this->saveProfile($id, $d);
    $n = $this->generate($id, 'offboarding');
    Audit::log('hr.employee.offboard', 'employee', $id, $d);
    $this->flash('Exit recorded'.($n ? ' and offboarding checklist created.' : '.'));
    return $this->redirect('/hr/employees/'.$id.'?tab=checklists'); }

  public function deactivate(int $id): Response {
    $emp = $this->employee($id);
    if ($id === $this->uid()) { $this->abort(422, 'You cannot deactivate your own account.'); }
    if ($emp['role_slug'] === 'super_admin' && !Auth::isSuperAdmin()) { $this->abort(403, 'Only a Super Admin can deactivate a Super Admin.'); }
    if ($emp['status'] === 'inactive') { $this->flash('This account is already inactive.', 'error'); return $this->redirect('/hr/employees/'.$id.'?tab=checklists'); }
    PS::deactivateUser($id);
    Audit::log('hr.employee.deactivate', 'employee', $id);
    $this->flash($emp['name'].' has been deactivated and signed out.');
    return $this->redirect('/hr/employees/'.$id.'?tab=checklists'); } }
