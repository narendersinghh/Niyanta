<?php
namespace Modules\Payroll\Controllers;
use Core\{Audit, Controller, DB, Response};
use Modules\Payroll\Services\{PayrollService, PayslipPdf};

/** Employees see their own published payslips; payroll managers may open any payslip (including drafts). */
final class PayslipController extends Controller {
  public function index(): Response {
    $uid = $this->uid();
    $base = "FROM payslips s JOIN payroll_runs r ON r.id=s.run_id WHERE s.user_id=? AND r.status IN ('finalized','paid')";
    $p = DB::paginate("SELECT s.id, s.pay_type, s.paid_days, s.working_days, s.worked_hours, s.gross, s.total_deductions, s.net, r.period_month, r.status AS run_status $base ORDER BY r.period_month DESC", [$uid], 12);
    $ytd = DB::first("SELECT COALESCE(SUM(s.net),0) AS net, COALESCE(SUM(s.gross),0) AS gross, COALESCE(SUM(s.total_deductions),0) AS deductions, COUNT(*) AS n $base AND YEAR(r.period_month)=?", [$uid, (int) date('Y')]);
    $trend = array_reverse(DB::all("SELECT s.net, r.period_month $base ORDER BY r.period_month DESC LIMIT 6", [$uid]));
    return $this->view('payroll::payslips/index', ['title' => 'My Payslips', 'p' => $p, 'ytd' => $ytd, 'trend' => $trend, 'latest' => $p['page'] === 1 ? ($p['items'][0] ?? null) : DB::first("SELECT s.id, s.net, r.period_month $base ORDER BY r.period_month DESC LIMIT 1", [$uid]), 'styles' => ['css/payroll.css']]); }

  /** Record-level guard: owner of a published slip, or payroll.manage. Everyone else gets 403. */
  private function visible(int $id): array {
    $s = DB::first('SELECT s.*, r.period_month, r.status AS run_status, u.name, u.email, u.employee_code, u.designation, u.department, u.avatar, u.joined_on, ep.bank_name, ep.bank_ifsc FROM payslips s JOIN payroll_runs r ON r.id=s.run_id JOIN users u ON u.id=s.user_id LEFT JOIN employee_profiles ep ON ep.user_id=s.user_id WHERE s.id=?', [$id]);
    if ($this->can('payroll.manage')) { return $s ?? $this->abort(404, 'Payslip not found.'); }
    if (!$s || (int) $s['user_id'] !== $this->uid()) { \Core\Audit::log('payroll.payslip.denied', 'payslip', $id); $this->abort(403, 'You can only view your own payslips.'); }
    if ($s['run_status'] === 'draft') { $this->abort(404, 'This payslip has not been published yet.'); }
    return $s; }

  public function show(int $id): Response {
    $s = $this->visible($id);
    $claims = DB::all('SELECT id, title, amount, expense_date, status FROM reimbursements WHERE payslip_id=? ORDER BY expense_date', [$id]);
    return $this->view('payroll::payslips/show', ['title' => 'Payslip · '.PayrollService::monthLabel($s['period_month']), 's' => $s, 'claims' => $claims, 'styles' => ['css/payroll.css']]); }

  public function pdf(int $id): Response {
    $s = $this->visible($id);
    Audit::log('payroll.payslip.download', 'payslip', $id, ['user_id' => (int) $s['user_id']]);
    $name = 'payslip-'.date('Y-m', strtotime($s['period_month'])).'-'.(preg_replace('/[^a-z0-9]+/', '-', strtolower($s['employee_code'] ?: $s['name'])) ?: 'employee').'.pdf';
    return Response::download(PayslipPdf::render($s), $name, 'application/pdf'); } }
