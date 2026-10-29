<?php
namespace Modules\Crm\Controllers;
use Core\{Audit, DB, Mailer, Modules, Response, ValidationException};
use Modules\Crm\Services\{DocumentPdf, Finance};

/** Invoices: status tabs, create/edit with line items, payments (partial), send/cancel, branded PDF and e-mail link. */
final class InvoiceController extends CrmController {
  public function index(): Response {
    $q = mb_substr($this->request->str('q'), 0, 100); $status = $this->request->str('status'); $client = $this->request->int('client_id');
    $from = $this->request->str('from'); $to = $this->request->str('to');
    [$w, $p] = $this->scope('d.client_id');
    $sql = "SELECT d.id, d.number, d.title, d.issue_date, d.due_date, d.status, d.total, d.amount_paid, d.currency, c.id AS client_id, c.name AS client_name, c.company AS client_company FROM invoices d JOIN clients c ON c.id=d.client_id WHERE $w";
    if ($q !== '') { $sql .= ' AND (d.number LIKE ? OR d.title LIKE ? OR c.name LIKE ? OR c.company LIKE ?)'; array_push($p, DB::like($q), DB::like($q), DB::like($q), DB::like($q)); }
    if (in_array($status, Finance::INVOICE_STATUSES, true)) { $sql .= ' AND d.status=?'; $p[] = $status; }
    if ($client) { $sql .= ' AND d.client_id=?'; $p[] = $client; }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $sql .= ' AND d.issue_date>=?'; $p[] = $from; }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) { $sql .= ' AND d.issue_date<=?'; $p[] = $to; }
    $page = DB::paginate($sql.' ORDER BY d.issue_date DESC, d.id DESC', $p, 20);
    [$w2, $p2] = $this->scope('d.client_id');
    $s = DB::first("SELECT COUNT(*) AS n, COALESCE(SUM(d.status='draft'),0) AS drafts, COALESCE(SUM(CASE WHEN d.status IN ".Finance::OPEN." THEN d.total-d.amount_paid END),0) AS outstanding,
      COALESCE(SUM(d.status IN ".Finance::OPEN."),0) AS open_n, COALESCE(SUM(CASE WHEN d.status='overdue' THEN d.total-d.amount_paid END),0) AS overdue, COALESCE(SUM(d.status='overdue'),0) AS overdue_n FROM invoices d WHERE $w2", $p2);
    $counts = DB::pairs("SELECT d.status, COUNT(*) FROM invoices d WHERE $w2 GROUP BY d.status", $p2);
    [$w3, $p3] = $this->scope('py.client_id');
    $s['collected'] = (float) DB::value("SELECT COALESCE(SUM(py.amount),0) FROM payments py WHERE py.paid_on>=? AND $w3", [date('Y-m-01'), ...$p3]);
    return $this->view('crm::invoices/index', ['title' => 'Invoices', 'p' => $page, 's' => $s, 'counts' => $counts, 'status' => $status, 'clients' => $this->clientOptions(), 'styles' => ['css/crm.css'], 'scripts' => ['js/crm.js']]); }

  public function create(): Response {
    $days = max(0, (int) setting('crm.invoice_due_days', 15));
    $doc = ['id' => null, 'client_id' => $this->request->int('client_id') ?: null, 'project_id' => $this->request->int('project_id') ?: null, 'issue_date' => date('Y-m-d'), 'due_date' => date('Y-m-d', strtotime("+$days days")), 'discount' => 0, 'notes' => '', 'terms' => (string) setting('crm.invoice_terms', ''), 'title' => '', 'currency' => setting('general.currency_code', 'INR'), 'amount_paid' => 0];
    return $this->view('crm::invoices/form', ['title' => 'New invoice', 'doc' => $doc, 'items' => []] + $this->formLookups()); }

  public function store(): Response {
    [$d, $items] = $this->docInput('invoice');
    $id = Finance::numbered(function () use ($d, $items) {
      $id = DB::insert('invoices', $d + ['number' => Finance::nextNumber('invoices', Finance::prefix('invoice')), 'status' => 'draft', 'amount_paid' => 0, 'created_by' => $this->uid(), 'created_at' => $this->now(), 'updated_at' => $this->now()]);
      Finance::saveItems('invoice_items', 'invoice_id', $id, $items);
      return $id; });
    Audit::log('crm.invoice.create', 'invoice', $id, ['client_id' => $d['client_id'], 'total' => $d['total']]);
    $this->flash('Invoice created as draft.');
    return $this->redirect('/invoices/'.$id); }

  public function show(int $id): Response {
    $doc = $this->doc('invoices', $id);
    $client = DB::find('clients', (int) $doc['client_id']);
    $items = DB::all('SELECT * FROM invoice_items WHERE invoice_id=? ORDER BY position, id', [$id]);
    $payments = DB::all('SELECT py.*, u.name AS recorder FROM payments py LEFT JOIN users u ON u.id=py.recorded_by WHERE py.invoice_id=? ORDER BY py.paid_on DESC, py.id DESC', [$id]);
    $quote = $doc['quotation_id'] ? DB::first('SELECT id, number FROM quotations WHERE id=?', [$doc['quotation_id']]) : null;
    $contactEmail = $client['email'] ?: DB::value("SELECT email FROM client_contacts WHERE client_id=? AND email IS NOT NULL AND email<>'' ORDER BY is_primary DESC, id LIMIT 1", [$client['id']]);
    return $this->view('crm::invoices/show', ['title' => 'Invoice '.$doc['number'], 'doc' => $doc, 'client' => $client, 'items' => $items, 'payments' => $payments, 'quote' => $quote, 'contactEmail' => $contactEmail, 'styles' => ['css/crm.css'], 'scripts' => ['js/crm.js']]); }

  public function edit(int $id): Response {
    $doc = $this->doc('invoices', $id);
    if (in_array($doc['status'], ['paid', 'cancelled'], true)) { $this->flash(ucfirst($doc['status']).' invoices cannot be edited.', 'error'); return $this->redirect('/invoices/'.$id); }
    $l = $this->formLookups();
    $l['clients'] += [$doc['client_id'] => $doc['client_company'] ?: $doc['client_name']];
    return $this->view('crm::invoices/form', ['title' => 'Edit '.$doc['number'], 'doc' => $doc, 'items' => DB::all('SELECT * FROM invoice_items WHERE invoice_id=? ORDER BY position, id', [$id])] + $l); }

  public function update(int $id): Response {
    $doc = $this->doc('invoices', $id);
    if (in_array($doc['status'], ['paid', 'cancelled'], true)) { $this->abort(422, 'This invoice can no longer be edited.'); }
    [$d, $items] = $this->docInput('invoice');
    if ((float) $doc['amount_paid'] > 0 && (int) $d['client_id'] !== (int) $doc['client_id']) { throw new ValidationException(['client_id' => 'The client cannot be changed after payments were recorded.']); }
    DB::transaction(function () use ($id, $d, $items) {
      $paid = (float) DB::value('SELECT amount_paid FROM invoices WHERE id=? FOR UPDATE', [$id]);
      if ($d['total'] + 0.004 < $paid) { throw new ValidationException(['items' => 'The new total ('.money($d['total']).') is lower than the amount already paid ('.money($paid).').']); }
      DB::update('invoices', $d + ['updated_at' => $this->now()], 'id=?', [$id]);
      DB::update('payments', ['client_id' => $d['client_id']], 'invoice_id=?', [$id]);
      Finance::saveItems('invoice_items', 'invoice_id', $id, $items);
      Finance::refreshInvoice($id); });
    Audit::log('crm.invoice.update', 'invoice', $id, ['total' => $d['total']]);
    $this->flash('Invoice updated.');
    return $this->redirect('/invoices/'.$id); }

  public function markSent(int $id): Response {
    $this->doc('invoices', $id);
    $st = DB::transaction(static function () use ($id) {
      $inv = DB::first('SELECT * FROM invoices WHERE id=? FOR UPDATE', [$id]);
      if ($inv['status'] !== 'draft') { return null; }
      $s = Finance::statusFor('sent', (float) $inv['total'], (float) $inv['amount_paid'], $inv['due_date']);
      DB::update('invoices', ['status' => $s, 'updated_at' => date('Y-m-d H:i:s')], 'id=?', [$id]);
      return $s; });
    if ($st === null) { $this->flash('Only draft invoices can be marked as sent.', 'error'); return $this->redirect('/invoices/'.$id); }
    Audit::log('crm.invoice.sent', 'invoice', $id, ['status' => $st]);
    $this->flash('Invoice marked as sent.');
    return $this->redirect('/invoices/'.$id); }

  public function cancel(int $id): Response {
    $doc = $this->doc('invoices', $id);
    if ((float) $doc['amount_paid'] > 0) { $this->flash('Remove the recorded payments before cancelling this invoice.', 'error'); return $this->redirect('/invoices/'.$id); }
    if ($doc['status'] === 'cancelled') { return $this->redirect('/invoices/'.$id); }
    DB::update('invoices', ['status' => 'cancelled', 'updated_at' => $this->now()], 'id=?', [$id]);
    Audit::log('crm.invoice.cancel', 'invoice', $id, ['from' => $doc['status']]);
    $this->flash('Invoice cancelled.');
    return $this->redirect('/invoices/'.$id); }

  public function destroy(int $id): Response {
    $doc = $this->doc('invoices', $id);
    if (!in_array($doc['status'], ['draft', 'cancelled'], true) || DB::value('SELECT 1 FROM payments WHERE invoice_id=? LIMIT 1', [$id])) { $this->flash('Only draft or cancelled invoices without payments can be deleted.', 'error'); return $this->redirect('/invoices/'.$id); }
    DB::transaction(static function () use ($id) {
      DB::update('quotations', ['invoice_id' => null], 'invoice_id=?', [$id]);
      DB::delete('invoices', 'id=?', [$id]); });
    Audit::log('crm.invoice.delete', 'invoice', $id, ['number' => $doc['number']]);
    $this->flash('Invoice deleted.');
    return $this->redirect('/invoices'); }

  public function recordPayment(int $id): Response {
    $doc = $this->doc('invoices', $id);
    $d = $this->validate(['amount' => 'required|numeric|min:0.01', 'paid_on' => 'required|date', 'method' => 'required|in:'.implode(',', array_keys(Finance::METHODS)), 'reference' => 'nullable|max:120', 'note' => 'nullable|max:500'], ['paid_on' => 'Payment date']);
    if ($d['paid_on'] > date('Y-m-d')) { throw new ValidationException(['paid_on' => 'Payment date cannot be in the future.']); }
    $amt = round((float) $d['amount'], 2);
    $inv = DB::transaction(function () use ($id, $d, $amt, $doc) {
      $inv = DB::first('SELECT * FROM invoices WHERE id=? FOR UPDATE', [$id]);
      if ($inv['status'] === 'cancelled') { throw new ValidationException(['amount' => 'Payments cannot be recorded on a cancelled invoice.']); }
      $bal = round((float) $inv['total'] - (float) $inv['amount_paid'], 2);
      if ($amt > $bal + 0.004) { throw new ValidationException(['amount' => 'Amount cannot exceed the balance due of '.money($bal).'.']); }
      DB::insert('payments', ['invoice_id' => $id, 'client_id' => $inv['client_id'], 'amount' => $amt, 'paid_on' => $d['paid_on'], 'method' => $d['method'], 'reference' => $d['reference'], 'note' => $d['note'], 'recorded_by' => $this->uid(), 'created_at' => $this->now()]);
      $inv = Finance::refreshInvoice($id);
      DB::insert('client_activities', ['client_id' => $inv['client_id'], 'project_id' => $inv['project_id'], 'type' => 'payment', 'title' => 'Payment of '.money($amt).' received for '.$doc['number'], 'body' => trim((Finance::METHODS[$d['method']] ?? '').($d['reference'] ? ' · Ref '.$d['reference'] : '')."\n".($d['note'] ?? '')), 'is_internal' => 0, 'occurred_at' => $d['paid_on'].' '.date('H:i:s'), 'user_id' => $this->uid(), 'created_at' => $this->now()]);
      return $inv; });
    Audit::log('crm.payment.create', 'invoice', $id, ['amount' => $amt, 'status' => $inv['status']]);
    $this->flash('Payment of '.money($amt).' recorded. Invoice is now '.str_replace('_', ' ', $inv['status']).'.');
    return $this->redirect('/invoices/'.$id); }

  public function deletePayment(int $id, int $paymentId): Response {
    $this->doc('invoices', $id);
    $amt = DB::transaction(static function () use ($id, $paymentId) {
      $p = DB::first('SELECT * FROM payments WHERE id=? AND invoice_id=? FOR UPDATE', [$paymentId, $id]);
      if (!$p) { return null; }
      DB::delete('payments', 'id=?', [$paymentId]);
      Finance::refreshInvoice($id);
      return $p['amount']; });
    if ($amt === null) { $this->abort(404, 'Payment not found.'); }
    Audit::log('crm.payment.delete', 'invoice', $id, ['payment_id' => $paymentId, 'amount' => $amt]);
    $this->flash('Payment removed and balance recalculated.');
    return $this->redirect('/invoices/'.$id); }

  /** Queue an e-mail to the client with a signed link to the PDF (no attachment). */
  public function email(int $id): Response {
    $doc = $this->doc('invoices', $id);
    if ($doc['status'] === 'cancelled') { $this->flash('Cancelled invoices cannot be e-mailed.', 'error'); return $this->redirect('/invoices/'.$id); }
    $d = $this->validate(['to' => 'required|email|max:190', 'message' => 'nullable|max:2000'], ['to' => 'Recipient']);
    $app = company_name();
    $link = absolute_url('/shared/invoice/'.$id.'/'.Finance::shareToken($doc));
    $bal = max(0, (float) $doc['total'] - (float) $doc['amount_paid']);
    $body = '<p>Hello '.e($doc['client_company'] ?: $doc['client_name']).',</p>'.($d['message'] ? '<p>'.nl2br(e($d['message'])).'</p>' : '<p>Please find your invoice <b>'.e($doc['number']).'</b> below.</p>')
      .'<table style="border-collapse:collapse;margin:12px 0"><tr><td style="padding:4px 16px 4px 0;color:#777">Invoice</td><td><b>'.e($doc['number']).'</b></td></tr><tr><td style="padding:4px 16px 4px 0;color:#777">Total</td><td>'.e(money($doc['total'])).'</td></tr><tr><td style="padding:4px 16px 4px 0;color:#777">Balance due</td><td><b>'.e(money($bal)).'</b></td></tr><tr><td style="padding:4px 16px 4px 0;color:#777">Due date</td><td>'.e(fdate($doc['due_date'])).'</td></tr></table>';
    Mailer::queue($d['to'], 'Invoice '.$doc['number'].' from '.$app, Mailer::template('Invoice '.$doc['number'], $body, $link, 'Download invoice (PDF)'), $doc['client_company'] ?: $doc['client_name']);
    DB::transaction(function () use ($id, $doc, $d) {
      if ($doc['status'] === 'draft') { $inv = DB::first('SELECT * FROM invoices WHERE id=? FOR UPDATE', [$id]); DB::update('invoices', ['status' => Finance::statusFor('sent', (float) $inv['total'], (float) $inv['amount_paid'], $inv['due_date']), 'updated_at' => $this->now()], 'id=?', [$id]); }
      DB::insert('client_activities', ['client_id' => $doc['client_id'], 'project_id' => $doc['project_id'], 'type' => 'email', 'title' => 'Invoice '.$doc['number'].' e-mailed', 'body' => 'Sent to '.$d['to'], 'is_internal' => 0, 'occurred_at' => $this->now(), 'user_id' => $this->uid(), 'created_at' => $this->now()]); });
    Audit::log('crm.invoice.email', 'invoice', $id, ['to' => $d['to']]);
    $this->flash('Invoice e-mail queued to '.$d['to'].'.');
    return $this->redirect('/invoices/'.$id); }

  public function pdf(int $id): Response {
    $doc = $this->doc('invoices', $id);
    Audit::log('crm.invoice.pdf', 'invoice', $id);
    return Response::download($this->render($doc), $doc['number'].'.pdf', 'application/pdf'); }

  /** Public, token-protected PDF for the e-mailed link. Returns a plain 404 for any mismatch. */
  public function sharedPdf(int $id, string $token): Response {
    if (!feature_on('invoices')) { return Response::error(404, 'Not found.'); }
    $doc = DB::first('SELECT d.*'.(feature_on('projects') ? ', pr.name AS project_name' : ', NULL AS project_name').' FROM invoices d'.(feature_on('projects') ? ' LEFT JOIN projects pr ON pr.id=d.project_id' : '').' WHERE d.id=?', [$id]);
    if (!$doc || $doc['status'] === 'cancelled' || !hash_equals(Finance::shareToken($doc), $token)) { return Response::error(404, 'This invoice link is invalid or has expired.'); }
    Audit::log('crm.invoice.shared_view', 'invoice', $id);
    return new Response($this->render($doc), 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="'.preg_replace('/[^\w.\-]+/', '_', $doc['number']).'.pdf"', 'Cache-Control' => 'private, no-store']); }

  private function render(array $doc): string {
    $items = DB::all('SELECT * FROM invoice_items WHERE invoice_id=? ORDER BY position, id', [$doc['id']]);
    $payments = DB::all('SELECT amount, paid_on, method, reference FROM payments WHERE invoice_id=? ORDER BY paid_on, id', [$doc['id']]);
    return DocumentPdf::render('invoice', $doc, $items, DB::find('clients', (int) $doc['client_id']), $payments); } }
