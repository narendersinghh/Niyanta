<?php
namespace Modules\Crm\Controllers;
use Core\{Audit, DB, Response};
use Modules\Crm\Services\{DocumentPdf, Finance};

/** Quotations: list, create/edit with line items, status changes, convert to invoice, PDF. Scoped to visible clients. */
final class QuotationController extends CrmController {
  public function index(): Response {
    $q = mb_substr($this->request->str('q'), 0, 100); $status = $this->request->str('status'); $client = $this->request->int('client_id');
    [$w, $p] = $this->scope('d.client_id');
    $sql = "SELECT d.id, d.number, d.title, d.issue_date, d.valid_until, d.status, d.total, d.currency, d.invoice_id, c.id AS client_id, c.name AS client_name, c.company AS client_company FROM quotations d JOIN clients c ON c.id=d.client_id WHERE $w";
    if ($q !== '') { $sql .= ' AND (d.number LIKE ? OR d.title LIKE ? OR c.name LIKE ? OR c.company LIKE ?)'; array_push($p, DB::like($q), DB::like($q), DB::like($q), DB::like($q)); }
    if (in_array($status, Finance::QUOTE_STATUSES, true)) { $sql .= ' AND d.status=?'; $p[] = $status; }
    if ($client) { $sql .= ' AND d.client_id=?'; $p[] = $client; }
    $page = DB::paginate($sql.' ORDER BY d.id DESC', $p, 20);
    [$w2, $p2] = $this->scope('d.client_id');
    $stats = [];
    foreach (DB::all("SELECT d.status, COUNT(*) AS n, COALESCE(SUM(d.total),0) AS v FROM quotations d WHERE $w2 GROUP BY d.status", $p2) as $r) { $stats[$r['status']] = $r; }
    return $this->view('crm::quotations/index', ['title' => 'Quotations', 'p' => $page, 'stats' => $stats, 'clients' => $this->clientOptions(), 'styles' => ['css/crm.css'], 'scripts' => ['js/crm.js']]); }

  public function create(): Response {
    $cid = $this->request->int('client_id');
    $doc = ['id' => null, 'client_id' => $cid ?: null, 'project_id' => $this->request->int('project_id') ?: null, 'issue_date' => date('Y-m-d'), 'valid_until' => date('Y-m-d', strtotime('+30 days')), 'discount' => 0, 'notes' => '', 'terms' => (string) setting('crm.quotation_terms', ''), 'title' => '', 'currency' => setting('general.currency_code', 'INR')];
    return $this->view('crm::quotations/form', ['title' => 'New quotation', 'doc' => $doc, 'items' => []] + $this->formLookups()); }

  public function store(): Response {
    [$d, $items] = $this->docInput('quotation');
    $id = Finance::numbered(function () use ($d, $items) {
      $id = DB::insert('quotations', $d + ['number' => Finance::nextNumber('quotations', Finance::prefix('quotation')), 'status' => 'draft', 'created_by' => $this->uid(), 'created_at' => $this->now(), 'updated_at' => $this->now()]);
      Finance::saveItems('quotation_items', 'quotation_id', $id, $items);
      return $id; });
    Audit::log('crm.quotation.create', 'quotation', $id, ['client_id' => $d['client_id'], 'total' => $d['total']]);
    $this->flash('Quotation created.');
    return $this->redirect('/quotations/'.$id); }

  public function show(int $id): Response {
    $doc = $this->doc('quotations', $id);
    $client = DB::find('clients', (int) $doc['client_id']);
    $items = DB::all('SELECT * FROM quotation_items WHERE quotation_id=? ORDER BY position, id', [$id]);
    $inv = $doc['invoice_id'] ? DB::first('SELECT id, number, status FROM invoices WHERE id=?', [$doc['invoice_id']]) : null;
    return $this->view('crm::quotations/show', ['title' => 'Quotation '.$doc['number'], 'doc' => $doc, 'client' => $client, 'items' => $items, 'inv' => $inv, 'styles' => ['css/crm.css'], 'scripts' => ['js/crm.js']]); }

  public function edit(int $id): Response {
    $doc = $this->doc('quotations', $id);
    if ($doc['invoice_id']) { $this->flash('This quotation was converted to an invoice and can no longer be edited.', 'error'); return $this->redirect('/quotations/'.$id); }
    $l = $this->formLookups();
    $l['clients'] += [$doc['client_id'] => $doc['client_company'] ?: $doc['client_name']];
    return $this->view('crm::quotations/form', ['title' => 'Edit '.$doc['number'], 'doc' => $doc, 'items' => DB::all('SELECT * FROM quotation_items WHERE quotation_id=? ORDER BY position, id', [$id])] + $l); }

  public function update(int $id): Response {
    $doc = $this->doc('quotations', $id);
    if ($doc['invoice_id']) { $this->abort(422, 'Converted quotations cannot be edited.'); }
    [$d, $items] = $this->docInput('quotation');
    DB::transaction(function () use ($id, $d, $items) {
      DB::update('quotations', $d + ['updated_at' => $this->now()], 'id=?', [$id]);
      Finance::saveItems('quotation_items', 'quotation_id', $id, $items); });
    Audit::log('crm.quotation.update', 'quotation', $id, ['total' => $d['total']]);
    $this->flash('Quotation updated.');
    return $this->redirect('/quotations/'.$id); }

  public function status(int $id): Response {
    $doc = $this->doc('quotations', $id);
    $s = $this->request->str('status');
    if (!in_array($s, Finance::QUOTE_STATUSES, true)) { $this->abort(422, 'Invalid status.'); }
    if ($doc['invoice_id']) { $this->flash('This quotation has already been converted to an invoice.', 'error'); return $this->redirect('/quotations/'.$id); }
    DB::update('quotations', ['status' => $s, 'updated_at' => $this->now()], 'id=?', [$id]);
    Audit::log('crm.quotation.status', 'quotation', $id, ['from' => $doc['status'], 'to' => $s]);
    $this->flash('Quotation marked '.str_replace('_', ' ', $s).'.');
    return $this->redirect('/quotations/'.$id); }

  public function convert(int $id): Response {
    // Converting creates an invoice, so it also needs the (independently switchable) invoices feature.
    $this->authorize('crm.finance.manage');
    $this->doc('quotations', $id);
    $invId = Finance::numbered(function () use ($id) {
      $q = DB::first('SELECT * FROM quotations WHERE id=? FOR UPDATE', [$id]);
      if ($q['invoice_id']) { return -(int) $q['invoice_id']; }
      if ($q['status'] !== 'accepted') { return 0; }
      $days = max(0, (int) setting('crm.invoice_due_days', 15));
      $invId = DB::insert('invoices', ['client_id' => $q['client_id'], 'project_id' => $q['project_id'], 'quotation_id' => $id, 'number' => Finance::nextNumber('invoices', Finance::prefix('invoice')), 'title' => $q['title'], 'issue_date' => date('Y-m-d'), 'due_date' => date('Y-m-d', strtotime("+$days days")), 'status' => 'draft', 'currency' => $q['currency'],
        'subtotal' => $q['subtotal'], 'discount' => $q['discount'], 'tax_total' => $q['tax_total'], 'total' => $q['total'], 'amount_paid' => 0, 'notes' => $q['notes'], 'terms' => $q['terms'] ?: setting('crm.invoice_terms'), 'created_by' => $this->uid(), 'created_at' => $this->now(), 'updated_at' => $this->now()]);
      DB::query('INSERT INTO invoice_items (invoice_id, description, quantity, unit_price, tax_rate, amount, position) SELECT ?, description, quantity, unit_price, tax_rate, amount, position FROM quotation_items WHERE quotation_id=? ORDER BY position, id', [$invId, $id]);
      DB::update('quotations', ['invoice_id' => $invId, 'updated_at' => $this->now()], 'id=?', [$id]);
      return $invId; });
    if ($invId === 0) { $this->flash('Only accepted quotations can be converted to an invoice.', 'error'); return $this->redirect('/quotations/'.$id); }
    if ($invId < 0) { $this->flash('This quotation was already converted.', 'error'); return $this->redirect($this->can('crm.finance.view') ? '/invoices/'.(-$invId) : '/quotations/'.$id); }
    Audit::log('crm.quotation.convert', 'quotation', $id, ['invoice_id' => $invId]);
    $this->flash('Invoice created from quotation. Review it and mark it as sent.');
    return $this->redirect($this->can('crm.finance.view') ? '/invoices/'.$invId : '/quotations/'.$id); }

  public function destroy(int $id): Response {
    $doc = $this->doc('quotations', $id);
    if ($doc['invoice_id']) { $this->flash('Converted quotations cannot be deleted.', 'error'); return $this->redirect('/quotations/'.$id); }
    DB::delete('quotations', 'id=?', [$id]);
    Audit::log('crm.quotation.delete', 'quotation', $id, ['number' => $doc['number']]);
    $this->flash('Quotation deleted.');
    return $this->redirect('/quotations'); }

  public function pdf(int $id): Response {
    $doc = $this->doc('quotations', $id);
    $items = DB::all('SELECT * FROM quotation_items WHERE quotation_id=? ORDER BY position, id', [$id]);
    Audit::log('crm.quotation.pdf', 'quotation', $id);
    return Response::download(DocumentPdf::render('quotation', $doc, $items, DB::find('clients', (int) $doc['client_id'])), $doc['number'].'.pdf', 'application/pdf'); } }
