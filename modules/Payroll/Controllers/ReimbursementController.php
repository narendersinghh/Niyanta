<?php
namespace Modules\Payroll\Controllers;
use Core\{Audit, Auth, Controller, DB, Notify, Response, Storage, ValidationException};

/** Expense claims: employees submit with a receipt; approvers approve/reject; approved claims are paid with the next payroll run. */
final class ReimbursementController extends Controller {
  private const RECEIPTS = ['pdf', 'png', 'jpg', 'jpeg', 'webp'];

  public function index(): Response {
    $canReq = $this->can('payroll.reimbursements.request'); $canApp = $this->can('payroll.reimbursements.approve');
    $tab = $this->request->str('tab', $canReq ? 'mine' : 'queue');
    if ($tab === 'queue' && !$canApp) { $tab = 'mine'; }
    if ($tab === 'mine' && !$canReq) { $tab = 'queue'; }
    $status = $this->request->str('status', $tab === 'queue' ? 'pending' : '');
    $sql = 'SELECT r.*, u.name, u.avatar, u.employee_code, rv.name AS reviewer, pr.period_month FROM reimbursements r JOIN users u ON u.id=r.user_id LEFT JOIN users rv ON rv.id=r.reviewed_by LEFT JOIN payslips ps ON ps.id=r.payslip_id LEFT JOIN payroll_runs pr ON pr.id=ps.run_id WHERE 1=1';
    $p = [];
    if ($tab === 'mine') { $sql .= ' AND r.user_id=?'; $p[] = $this->uid(); }
    if (in_array($status, ['pending', 'approved', 'rejected', 'paid'], true)) { $sql .= ' AND r.status=?'; $p[] = $status; }
    $q = $this->request->str('q');
    if ($tab === 'queue' && $q !== '') { $sql .= ' AND (u.name LIKE ? OR r.title LIKE ?)'; array_push($p, DB::like($q), DB::like($q)); }
    $page = DB::paginate($sql.($tab === 'queue' ? ' ORDER BY r.status=\'pending\' DESC, r.created_at' : ' ORDER BY r.created_at DESC'), $p, 15);
    $files = [];
    if ($ids = array_column($page['items'], 'id')) { foreach (DB::all("SELECT id, entity_id, original_name, mime FROM files WHERE module='payroll' AND entity_type='reimbursement' AND entity_id IN ".DB::in($ids).' ORDER BY id', $ids) as $f) { $files[(int) $f['entity_id']][] = $f; } }
    $mine = DB::first("SELECT COALESCE(SUM(CASE WHEN status='pending' THEN amount END),0) AS pending, COALESCE(SUM(CASE WHEN status='approved' THEN amount END),0) AS approved, COALESCE(SUM(CASE WHEN status='paid' AND YEAR(expense_date)=? THEN amount END),0) AS paid, SUM(status='pending') AS pending_n FROM reimbursements WHERE user_id=?", [(int) date('Y'), $this->uid()]);
    $queue = $canApp ? DB::first("SELECT COUNT(*) AS n, COALESCE(SUM(amount),0) AS amt FROM reimbursements WHERE status='pending'") : null;
    return $this->view('payroll::reimbursements/index', ['title' => 'Reimbursements', 'tab' => $tab, 'status' => $status, 'p' => $page, 'files' => $files, 'mine' => $mine, 'queue' => $queue, 'canReq' => $canReq, 'canApp' => $canApp, 'styles' => ['css/payroll.css']]); }

  public function store(): Response {
    $d = $this->validate(['title' => 'required|max:190', 'amount' => 'required|numeric|min:0.01|max:10000000', 'expense_date' => 'required|date', 'description' => 'nullable|max:2000'], ['expense_date' => 'Expense date']);
    if ($d['expense_date'] > date('Y-m-d')) { throw new ValidationException(['expense_date' => 'Expense date cannot be in the future.']); }
    if (!preg_match('/^\d+(\.\d{1,2})?$/', (string) $d['amount'])) { throw new ValidationException(['amount' => 'Amount may have at most two decimals.']); }
    $file = $this->request->file('receipt');
    $id = DB::transaction(function () use ($d, $file): int {
      $id = DB::insert('reimbursements', ['user_id' => $this->uid(), 'title' => $d['title'], 'amount' => round((float) $d['amount'], 2), 'expense_date' => $d['expense_date'], 'description' => $d['description'], 'status' => 'pending', 'created_at' => date('Y-m-d H:i:s')]);
      if ($file) { Storage::attach($file, 'payroll', 'reimbursement', $id, 'receipt', self::RECEIPTS); }
      Audit::log('payroll.reimbursement.create', 'reimbursement', $id, ['amount' => round((float) $d['amount'], 2)]);
      return $id; });
    $me = $this->user();
    Notify::permission('payroll.reimbursements.approve', 'New reimbursement claim from '.$me['name'], $d['title'].', '.money($d['amount']), '/reimbursements?tab=queue');
    $this->flash('Claim submitted for approval.');
    return $this->redirect('/reimbursements'); }

  private function reviewable(int $id): array {
    $r = $this->findOr404('reimbursements', $id);
    if ($r['status'] !== 'pending') { throw new \DomainException('This claim has already been reviewed.'); }
    if ((int) $r['user_id'] === $this->uid() && !Auth::isSuperAdmin()) { throw new \DomainException('You cannot review your own claim.'); }
    return $r; }

  public function approve(int $id): Response { return $this->review($id, 'approved'); }
  public function reject(int $id): Response { return $this->review($id, 'rejected'); }

  private function review(int $id, string $status): Response {
    $d = $this->validate(['note' => ($status === 'rejected' ? 'required' : 'nullable').'|max:500'], ['note' => 'Review note']);
    try {
      $r = DB::transaction(function () use ($id, $status, $d): array {
        $r = $this->reviewable($id);
        DB::update('reimbursements', ['status' => $status, 'reviewed_by' => $this->uid(), 'reviewed_at' => date('Y-m-d H:i:s'), 'review_note' => $d['note']], 'id=? AND status=\'pending\'', [$id]);
        Audit::log('payroll.reimbursement.'.($status === 'approved' ? 'approve' : 'reject'), 'reimbursement', $id, ['amount' => (float) $r['amount'], 'note' => $d['note']]);
        return $r; }); }
    catch (\DomainException $e) { $this->flash($e->getMessage(), 'error'); return $this->back(); }
    $body = $r['title'].', '.money($r['amount']).($status === 'approved' ? '. It will be paid with your next payslip.' : '.').($d['note'] ? "\nNote: ".$d['note'] : '');
    Notify::user((int) $r['user_id'], 'Your reimbursement claim was '.$status, $body, '/reimbursements', true, 'receipt');
    $this->flash('Claim '.$status.'.');
    return $this->back(); }

  public function destroy(int $id): Response {
    $r = $this->findOr404('reimbursements', $id);
    if ((int) $r['user_id'] !== $this->uid()) { $this->abort(403, 'You can only withdraw your own claims.'); }
    if ($r['status'] !== 'pending') { $this->flash('Only pending claims can be withdrawn.', 'error'); return $this->back(); }
    $fileIds = DB::column("SELECT id FROM files WHERE module='payroll' AND entity_type='reimbursement' AND entity_id=?", [$id]);
    DB::transaction(static function () use ($id, $fileIds): void {
      DB::delete('reimbursements', "id=? AND status='pending'", [$id]);
      foreach ($fileIds as $f) { Storage::remove((int) $f); }
      Audit::log('payroll.reimbursement.withdraw', 'reimbursement', $id); });
    $this->flash('Claim withdrawn.');
    return $this->redirect('/reimbursements'); } }
