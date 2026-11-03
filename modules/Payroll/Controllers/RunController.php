<?php
namespace Modules\Payroll\Controllers;
use Core\{Audit, Controller, Crypto, DB, Response};
use Modules\Payroll\Services\PayrollService;

/** Monthly payroll runs: create (draft) → generate payslips → finalize → mark paid; bank transfer CSV export. */
final class RunController extends Controller {
  public function index(): Response {
    if (!$this->can('payroll.manage')) { return $this->redirect('/payroll/employees'); }
    $last = DB::first("SELECT r.*, (SELECT COUNT(*) FROM payslips s WHERE s.run_id=r.id) AS slips FROM payroll_runs r WHERE r.status IN ('finalized','paid') ORDER BY r.period_month DESC LIMIT 1");
    $reimb = DB::first("SELECT SUM(status='pending') AS pending, COALESCE(SUM(CASE WHEN status='pending' THEN amount END),0) AS pending_amt, SUM(status='approved' AND payslip_id IS NULL) AS queued FROM reimbursements");
    $p = DB::paginate('SELECT r.*, u.name AS creator, COUNT(s.id) AS slips, COALESCE(SUM(s.gross),0) AS gross, COALESCE(SUM(s.total_deductions),0) AS deductions, COALESCE(SUM(s.total_deductions > s.gross),0) AS flagged FROM payroll_runs r LEFT JOIN users u ON u.id=r.created_by LEFT JOIN payslips s ON s.run_id=r.id GROUP BY r.id ORDER BY r.period_month DESC', [], 12);
    $staff = (int) DB::value("SELECT COUNT(DISTINCT c.user_id) FROM compensations c JOIN users u ON u.id=c.user_id WHERE u.status='active' AND c.effective_from<=?", [date('Y-m-t')]);
    return $this->view('payroll::runs/index', ['title' => 'Payroll', 'last' => $last, 'reimb' => $reimb, 'p' => $p, 'next' => PayrollService::nextRunMonth(), 'staff' => $staff, 'styles' => ['css/payroll.css']]); }

  public function store(): Response {
    $d = $this->validate(['month' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/']], ['month' => 'Payroll month']);
    if ($d['month'] > date('Y-m', strtotime('+1 month'))) { $this->flash('Payroll can be prepared at most one month ahead.', 'error'); return $this->redirect('/payroll'); }
    try { $id = PayrollService::createRun($d['month']); }
    catch (\DomainException $e) { $this->flash($e->getMessage(), 'error'); return $this->redirect('/payroll'); }
    $n = (int) DB::value('SELECT COUNT(*) FROM payslips WHERE run_id=?', [$id]);
    $this->flash("Draft run created with $n payslip".($n === 1 ? '' : 's').'.');
    return $this->redirect('/payroll/runs/'.$id); }

  public function show(int $id): Response {
    $run = DB::first('SELECT r.*, c.name AS creator, f.name AS finalizer FROM payroll_runs r LEFT JOIN users c ON c.id=r.created_by LEFT JOIN users f ON f.id=r.finalized_by WHERE r.id=?', [$id]) ?? $this->abort(404, 'Payroll run not found.');
    $q = $this->request->str('q');
    $where = 's.run_id=?'; $params = [$id];
    if ($q !== '') { $where .= ' AND (u.name LIKE ? OR u.employee_code LIKE ? OR u.department LIKE ?)'; array_push($params, DB::like($q), DB::like($q), DB::like($q)); }
    $p = DB::paginate("SELECT s.*, u.name, u.avatar, u.employee_code, u.designation, u.department FROM payslips s JOIN users u ON u.id=s.user_id WHERE $where ORDER BY u.name", $params, 25);
    $totals = DB::first('SELECT COUNT(*) AS n, COALESCE(SUM(base_pay),0) AS base, COALESCE(SUM(gross - base_pay - reimbursement_total),0) AS allowances, COALESCE(SUM(reimbursement_total),0) AS reimb, COALESCE(SUM(gross),0) AS gross, COALESCE(SUM(total_deductions),0) AS deductions, COALESCE(SUM(net),0) AS net, COALESCE(SUM(total_deductions > gross),0) AS flagged, SUM(pay_type=\'hourly\') AS hourly, MAX(flex_mode) AS flex_mode, COALESCE(SUM(expected_hours IS NOT NULL),0) AS by_hours, COALESCE(SUM(overtime_pay),0) AS overtime FROM payslips WHERE run_id=?', [$id]);
    $missing = DB::all("SELECT u.id, u.name FROM users u WHERE u.status='active' AND NOT EXISTS (SELECT 1 FROM compensations c WHERE c.user_id=u.id AND c.effective_from<=?) ORDER BY u.name LIMIT 12", [date('Y-m-t', strtotime($run['period_month']))]);
    return $this->view('payroll::runs/show', ['title' => 'Payroll · '.PayrollService::monthLabel($run['period_month']), 'run' => $run, 'p' => $p, 'totals' => $totals, 'missing' => $missing, 'styles' => ['css/payroll.css']]); }

  public function generate(int $id): Response {
    try { $r = PayrollService::generate($id); $this->flash('Payslips regenerated: '.$r['count'].' employees, net '.money($r['total']).($r['flagged'] ? ', '.$r['flagged'].' flagged' : '').'.'); }
    catch (\DomainException $e) { $this->flash($e->getMessage(), 'error'); }
    return $this->redirect('/payroll/runs/'.$id); }

  public function finalize(int $id): Response {
    try { $n = PayrollService::finalize($id); $this->flash("Payroll finalized. $n employee".($n === 1 ? ' was' : 's were').' notified.'); }
    catch (\DomainException $e) { $this->flash($e->getMessage(), 'error'); }
    return $this->redirect('/payroll/runs/'.$id); }

  public function paid(int $id): Response {
    try { $n = PayrollService::markPaid($id); $this->flash('Run marked as paid'.($n ? " and $n reimbursement".($n === 1 ? '' : 's').' settled' : '').'.'); }
    catch (\DomainException $e) { $this->flash($e->getMessage(), 'error'); }
    return $this->redirect('/payroll/runs/'.$id); }

  public function destroy(int $id): Response {
    try { PayrollService::deleteRun($id); $this->flash('Draft run deleted.'); return $this->redirect('/payroll'); }
    catch (\DomainException $e) { $this->flash($e->getMessage(), 'error'); return $this->redirect('/payroll/runs/'.$id); } }

  /** Bank transfer sheet. The only place account numbers are decrypted; every export is audit-logged. */
  public function export(int $id): Response {
    $run = $this->findOr404('payroll_runs', $id);
    if ($run['status'] === 'draft') { $this->flash('Finalize the run before exporting the bank transfer sheet.', 'error'); return $this->redirect('/payroll/runs/'.$id); }
    $rows = DB::all('SELECT u.name, u.employee_code, ep.bank_name, ep.bank_account_enc, ep.bank_ifsc, s.net FROM payslips s JOIN users u ON u.id=s.user_id LEFT JOIN employee_profiles ep ON ep.user_id=s.user_id WHERE s.run_id=? ORDER BY u.name', [$id]);
    $cell = static function (mixed $v): string { $v = (string) $v; if ($v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true)) { $v = "'".$v; } return '"'.str_replace('"', '""', $v).'"'; };
    $out = "\xEF\xBB\xBF".implode(',', array_map($cell, ['Employee name', 'Employee code', 'Bank name', 'Account number', 'IFSC', 'Net pay']))."\r\n";
    $total = 0.0; $missing = 0;
    foreach ($rows as $r) {
      $acct = Crypto::decrypt($r['bank_account_enc']);
      if (!$acct) { $missing++; }
      $total += (float) $r['net'];
      $out .= implode(',', array_map($cell, [$r['name'], $r['employee_code'] ?? '', $r['bank_name'] ?? '', $acct ?? '', $r['bank_ifsc'] ?? '', number_format((float) $r['net'], 2, '.', '')]))."\r\n"; }
    $out .= implode(',', array_map($cell, ['TOTAL', '', '', '', '', number_format(round($total, 2), 2, '.', '')]))."\r\n";
    Audit::log('payroll.run.export', 'payroll_run', $id, ['rows' => count($rows), 'missing_bank' => $missing, 'month' => $run['period_month']]);
    return Response::download($out, 'bank-transfer-'.date('Y-m', strtotime($run['period_month'])).'.csv', 'text/csv; charset=utf-8'); } }
