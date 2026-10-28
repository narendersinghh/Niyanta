<?php
namespace Modules\Payroll\Controllers;
use Core\{Audit, Controller, DB, Response, ValidationException};

/** Salaries / hourly rates (with revision history) and allowance & deduction components (global or per employee). */
final class CompensationController extends Controller {
  private const CURRENT = 'LEFT JOIN compensations c ON c.id=(SELECT c2.id FROM compensations c2 WHERE c2.user_id=u.id AND c2.effective_from<=CURDATE() ORDER BY c2.effective_from DESC, c2.id DESC LIMIT 1)';

  public function index(): Response {
    $q = $this->request->str('q'); $f = $this->request->str('filter');
    $sql = 'SELECT u.id, u.name, u.email, u.avatar, u.designation, u.department, u.employee_code, c.pay_type, c.amount, c.effective_from,
      (SELECT COUNT(*) FROM pay_components pc WHERE pc.user_id=u.id AND pc.active=1) AS comps, (SELECT MIN(cf.effective_from) FROM compensations cf WHERE cf.user_id=u.id AND cf.effective_from>CURDATE()) AS upcoming
      FROM users u '.self::CURRENT." WHERE u.status='active'";
    $p = [];
    if ($q !== '') { $sql .= ' AND (u.name LIKE ? OR u.email LIKE ? OR u.employee_code LIKE ? OR u.department LIKE ?)'; array_push($p, DB::like($q), DB::like($q), DB::like($q), DB::like($q)); }
    if ($f === 'missing') { $sql .= ' AND c.id IS NULL'; }
    elseif (in_array($f, ['monthly', 'hourly'], true)) { $sql .= ' AND c.pay_type=?'; $p[] = $f; }
    $page = DB::paginate($sql.' ORDER BY u.name', $p, 20);
    $stats = DB::first("SELECT COUNT(u.id) AS active, SUM(c.pay_type='monthly') AS monthly, SUM(c.pay_type='hourly') AS hourly, SUM(c.id IS NULL) AS missing, COALESCE(SUM(CASE WHEN c.pay_type='monthly' THEN c.amount END),0) AS monthly_cost FROM users u ".self::CURRENT." WHERE u.status='active'");
    return $this->view('payroll::employees/index', ['title' => 'Compensation', 'p' => $page, 'stats' => $stats, 'styles' => ['css/payroll.css']]); }

  public function show(int $id): Response {
    $emp = DB::first('SELECT u.*, r.name AS role_name, ep.bank_name, ep.bank_ifsc, (ep.bank_account_enc IS NOT NULL AND ep.bank_account_enc<>\'\') AS has_account FROM users u JOIN roles r ON r.id=u.role_id LEFT JOIN employee_profiles ep ON ep.user_id=u.id WHERE u.id=?', [$id]) ?? $this->abort(404, 'Employee not found.');
    $history = DB::all('SELECT c.*, u.name AS creator FROM compensations c LEFT JOIN users u ON u.id=c.created_by WHERE c.user_id=? ORDER BY c.effective_from DESC, c.id DESC', [$id]);
    $current = null;
    foreach ($history as $h) { if ($h['effective_from'] <= date('Y-m-d')) { $current = $h; break; } }
    $personal = DB::all('SELECT * FROM pay_components WHERE user_id=? ORDER BY kind, name', [$id]);
    $global = DB::all('SELECT * FROM pay_components WHERE user_id IS NULL AND active=1 ORDER BY kind, name');
    $slips = DB::all('SELECT s.id, s.net, s.gross, r.period_month, r.status FROM payslips s JOIN payroll_runs r ON r.id=s.run_id WHERE s.user_id=? ORDER BY r.period_month DESC LIMIT 6', [$id]);
    return $this->view('payroll::employees/show', ['title' => $emp['name'].' · Compensation', 'emp' => $emp, 'history' => $history, 'current' => $current, 'personal' => $personal, 'global' => $global, 'slips' => $slips, 'styles' => ['css/payroll.css']]); }

  public function storeCompensation(int $id): Response {
    $emp = $this->findOr404('users', $id);
    $d = $this->validate(['pay_type' => 'required|in:monthly,hourly', 'amount' => 'required|numeric|min:0.01|max:999999999', 'effective_from' => 'required|date', 'note' => 'nullable|max:255'], ['pay_type' => 'Pay type', 'effective_from' => 'Effective date']);
    if (DB::value('SELECT 1 FROM compensations WHERE user_id=? AND effective_from=?', [$id, $d['effective_from']])) { throw new ValidationException(['effective_from' => 'A revision already exists for this date. Delete it first or choose another date.']); }
    $cid = DB::insert('compensations', ['user_id' => $id, 'pay_type' => $d['pay_type'], 'amount' => round((float) $d['amount'], 2), 'effective_from' => $d['effective_from'], 'note' => $d['note'], 'created_by' => $this->uid(), 'created_at' => date('Y-m-d H:i:s')]);
    Audit::log('payroll.compensation.create', 'user', $id, ['compensation_id' => $cid, 'pay_type' => $d['pay_type'], 'amount' => round((float) $d['amount'], 2), 'effective_from' => $d['effective_from']]);
    $this->flash('Compensation revision saved for '.$emp['name'].'.');
    return $this->redirect('/payroll/employees/'.$id); }

  public function destroyCompensation(int $id): Response {
    $c = $this->findOr404('compensations', $id);
    DB::delete('compensations', 'id=?', [$id]);
    Audit::log('payroll.compensation.delete', 'user', (int) $c['user_id'], ['compensation_id' => $id, 'pay_type' => $c['pay_type'], 'amount' => $c['amount'], 'effective_from' => $c['effective_from']]);
    $this->flash('Revision deleted. Existing payslips are not affected.');
    return $this->redirect('/payroll/employees/'.$c['user_id']); }

  public function components(): Response {
    $rows = DB::all('SELECT * FROM pay_components WHERE user_id IS NULL ORDER BY kind, active DESC, name');
    $personal = (int) DB::value('SELECT COUNT(*) FROM pay_components WHERE user_id IS NOT NULL AND active=1');
    return $this->view('payroll::components/index', ['title' => 'Pay components', 'rows' => $rows, 'personal' => $personal, 'styles' => ['css/payroll.css']]); }

  private function componentData(): array {
    $d = $this->validate(['name' => 'required|max:120', 'kind' => 'required|in:allowance,deduction', 'calc' => 'required|in:fixed,percent', 'value' => 'required|numeric|min:0|max:99999999', 'active' => 'boolean'], ['calc' => 'Calculation']);
    if ($d['calc'] === 'percent' && (float) $d['value'] > 100) { throw new ValidationException(['value' => 'A percentage component cannot exceed 100% of base pay.']); }
    $d['value'] = round((float) $d['value'], 2);
    return $d; }

  private function target(array $comp): string { return $comp['user_id'] ? '/payroll/employees/'.$comp['user_id'] : '/payroll/components'; }

  public function storeComponent(): Response {
    $d = $this->componentData();
    $uid = $this->request->int('user_id') ?: null;
    if ($uid && !DB::value('SELECT 1 FROM users WHERE id=?', [$uid])) { $this->abort(404, 'Employee not found.'); }
    $id = DB::insert('pay_components', $d + ['user_id' => $uid, 'created_at' => date('Y-m-d H:i:s')]);
    Audit::log('payroll.component.create', 'pay_component', $id, ['user_id' => $uid] + $d);
    $this->flash(ucfirst($d['kind']).' "'.$d['name'].'" added.');
    return $this->redirect($this->target(['user_id' => $uid])); }

  public function updateComponent(int $id): Response {
    $c = $this->findOr404('pay_components', $id);
    $d = $this->componentData();
    DB::update('pay_components', $d, 'id=?', [$id]);
    Audit::log('payroll.component.update', 'pay_component', $id, ['user_id' => $c['user_id']] + $d);
    $this->flash('Component updated.');
    return $this->redirect($this->target($c)); }

  public function destroyComponent(int $id): Response {
    $c = $this->findOr404('pay_components', $id);
    DB::delete('pay_components', 'id=?', [$id]);
    Audit::log('payroll.component.delete', 'pay_component', $id, ['user_id' => $c['user_id'], 'name' => $c['name']]);
    $this->flash('Component removed.');
    return $this->redirect($this->target($c)); } }
