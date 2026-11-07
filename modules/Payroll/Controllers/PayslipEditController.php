<?php
namespace Modules\Payroll\Controllers;
use Core\{Controller, DB, Response};
use Modules\Payroll\Services\{PayrollService, PayslipEditor};

/** HR edits of one draft payslip (payroll.manage): edit form, live server-side preview of totals, save (audit-logged) and discard edits. */
final class PayslipEditController extends Controller {
  private function slip(int $id): array {
    $s = DB::first('SELECT s.*, r.status AS run_status, r.period_month, u.name, u.avatar, u.employee_code, u.designation, u.department, e.name AS editor FROM payslips s JOIN payroll_runs r ON r.id=s.run_id JOIN users u ON u.id=s.user_id LEFT JOIN users e ON e.id=s.edited_by WHERE s.id=?', [$id]);
    return $s ?? $this->abort(404, 'Payslip not found.'); }

  /** Posted edit form → edits array. */
  private function posted(array $slip): array { return PayslipEditor::normalize(['hours' => $this->request->arr('hours'), 'lines' => $this->request->arr('lines'), 'manual' => $this->request->arr('manual')], $slip); }

  public function edit(int $id): Response {
    $s = $this->slip($id);
    $edits = PayslipEditor::edits($s);
    $calc = PayslipEditor::calc($s);
    $history = DB::all("SELECT a.action, a.meta, a.created_at, u.name FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id WHERE a.entity_type='payslip' AND a.entity_id=? AND a.action IN ('payroll.payslip.edit','payroll.payslip.reset','payroll.payslip.bulk_line') ORDER BY a.id DESC LIMIT 10", [$id]);
    return $this->view('payroll::payslips/edit', ['title' => 'Edit payslip · '.$s['name'], 's' => $s, 'calc' => $calc, 'edits' => $edits, 'auto' => PayslipEditor::autoLines($s, $edits), 'history' => $history,
      'styles' => ['css/payroll.css'], 'scripts' => ['js/payroll.js']]); }

  /** Live totals for the edit form (nothing saved, leave is not recorded). */
  public function preview(int $id): Response {
    $s = $this->slip($id);
    try { $p = PayslipEditor::preview($s, $this->posted($s)); }
    catch (\DomainException $e) { return $this->json(['ok' => false, 'message' => $e->getMessage()], 422); }
    $line = static fn (array $l): array => ['name' => $l['name'], 'amount' => money($l['amount']), 'manual' => !empty($l['manual']), 'edited' => !empty($l['edited'])];
    $c = $p['shortage_cover'];
    return $this->json(['ok' => true, 'gross' => money($p['gross']), 'deductions' => money($p['total_deductions']), 'net' => money($p['net']), 'base' => money($p['base_pay']), 'flagged' => $p['flagged'],
      'payable' => $p['payable_hours'], 'overtime' => $p['overtime_hours'], 'cover' => $c ? 'Shortage covered by Paid Leave: '.PayrollService::coverLabel($c) : null,
      'earnings' => array_map($line, $p['earnings']), 'deductionLines' => array_map($line, $p['deductions']), 'delta' => round($p['net'] - (float) $s['net'], 2), 'deltaText' => ($p['net'] - (float) $s['net'] >= 0 ? '+' : '-').money(abs(round($p['net'] - (float) $s['net'], 2)))]); }

  public function update(int $id): Response {
    $s = $this->slip($id);
    try {
      $r = PayslipEditor::save($id, $this->posted($s), $this->request->str('reason'));
      $this->flash($r['changed'] ? 'Payslip of '.$s['name'].' saved. Net pay '.money($r['net']).'.' : 'No changes to save.'); }
    catch (\DomainException $e) { $this->flash($e->getMessage(), 'error'); }
    return $this->redirect('/payroll/payslips/'.$id.'/edit'); }

  /** Drop all HR edits of this payslip (back to the calculated figures). */
  public function reset(int $id): Response {
    $s = $this->slip($id);
    try { $r = PayslipEditor::save($id, [], 'Discarded edits'.($this->request->str('reason') !== '' ? ': '.$this->request->str('reason') : ''), 'payroll.payslip.reset'); $this->flash($r['changed'] ? 'Edits discarded. Net pay '.money($r['net']).'.' : 'This payslip has no edits.'); }
    catch (\DomainException $e) { $this->flash($e->getMessage(), 'error'); }
    return $this->redirect('/payroll/payslips/'.$id.'/edit'); } }
