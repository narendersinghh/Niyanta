<?php
namespace Modules\Hr\Controllers;
use Core\{Audit, Controller, DB, Gate, Notify, Response, Storage, ValidationException};

/** Company-wide documents (policies, handbook, forms): everyone with hr.company_docs.view reads them, HR publishes; optional read acknowledgement. */
final class CompanyDocController extends Controller {
  public const CATS = ['policy' => ['Policy', 'shield', 'lav'], 'handbook' => ['Handbook', 'book', 'mint'], 'forms' => ['Forms & templates', 'clipboard', 'lemon'], 'benefits' => ['Benefits', 'gift', 'pink'], 'other' => ['Other', 'folder', 'sky']];

  /** Active people expected to read company documents: [id => name]. */
  private function audience(): array { return array_column(Gate::usersWith('hr.company_docs.view'), 'name', 'id'); }

  public function index(): Response {
    $cat = $this->request->str('category'); $q = $this->request->str('q'); $uid = $this->uid(); $manage = $this->can('hr.company_docs.manage');
    $sql = 'SELECT d.*, f.original_name, f.mime, f.size, f.created_at AS file_at, u.name AS author, a.acknowledged_at AS my_ack FROM company_documents d LEFT JOIN files f ON f.id=d.file_id LEFT JOIN users u ON u.id=d.created_by LEFT JOIN company_document_acks a ON a.document_id=d.id AND a.user_id=? WHERE 1=1';
    $p = [$uid];
    if (isset(self::CATS[$cat])) { $sql .= ' AND d.category=?'; $p[] = $cat; }
    if ($q !== '') { $sql .= ' AND (d.title LIKE ? OR d.description LIKE ?)'; array_push($p, DB::like($q), DB::like($q)); }
    if ($this->request->str('show') === 'pending') { $sql .= ' AND d.requires_ack=1 AND a.user_id IS NULL'; }
    $docs = DB::all($sql.' ORDER BY (d.requires_ack=1 AND a.user_id IS NULL) DESC, COALESCE(d.updated_at, d.created_at) DESC, d.id DESC', $p);
    $counts = DB::pairs('SELECT category, COUNT(*) FROM company_documents GROUP BY category');
    $pending = (int) DB::value('SELECT COUNT(*) FROM company_documents d LEFT JOIN company_document_acks a ON a.document_id=d.id AND a.user_id=? WHERE d.requires_ack=1 AND a.user_id IS NULL', [$uid]);
    $progress = []; $audience = [];
    if ($manage) {
      $audience = $this->audience();
      $ackDocs = array_map('intval', array_column(array_filter($docs, static fn ($d) => (int) $d['requires_ack'] === 1), 'id'));
      if ($ackDocs) { foreach (DB::all('SELECT document_id, user_id, acknowledged_at FROM company_document_acks WHERE document_id IN '.DB::in($ackDocs), $ackDocs) as $a) { if (isset($audience[(int) $a['user_id']])) { $progress[(int) $a['document_id']][(int) $a['user_id']] = $a['acknowledged_at']; } } } }
    return $this->view('hr::documents/index', ['title' => 'Company documents', 'docs' => $docs, 'cats' => self::CATS, 'cat' => $cat, 'counts' => $counts, 'total' => array_sum($counts), 'pending' => $pending, 'manage' => $manage, 'progress' => $progress, 'audience' => $audience,
      'styles' => ['css/hr.css'], 'scripts' => ['js/hr.js']]); }

  private function data(): array {
    $d = $this->validate(['title' => 'required|max:190', 'category' => 'required|in:'.implode(',', array_keys(self::CATS)), 'description' => 'nullable|max:2000', 'requires_ack' => 'boolean'], ['requires_ack' => 'Acknowledgement']);
    $d['requires_ack'] = (int) (bool) $d['requires_ack'];
    return $d; }

  public function store(): Response {
    $d = $this->data();
    $file = $this->request->file('file') ?? throw new ValidationException(['file' => 'Choose the document file to upload.']);
    $id = DB::transaction(function () use ($d, $file): int {
      $id = DB::insert('company_documents', $d + ['created_by' => $this->uid(), 'created_at' => date('Y-m-d H:i:s')]);
      DB::update('company_documents', ['file_id' => Storage::attach($file, 'hr', 'company_doc', $id, 'company_doc')], 'id=?', [$id]);
      Audit::log('hr.company_doc.create', 'company_doc', $id, ['title' => $d['title'], 'category' => $d['category'], 'requires_ack' => $d['requires_ack']]);
      return $id; });
    if ($d['requires_ack'] && $this->request->input('notify')) { Notify::permission('hr.company_docs.view', 'Please read: '.$d['title'], 'A new company document needs your acknowledgement.', '/hr/documents?show=pending'); }
    $this->flash('“'.$d['title'].'” published.');
    return $this->redirect('/hr/documents#doc-'.$id); }

  public function update(int $id): Response {
    $doc = $this->findOr404('company_documents', $id);
    $d = $this->data();
    $file = $this->request->file('file');
    $reset = $file && $d['requires_ack'] && $this->request->input('reset_acks');
    DB::transaction(function () use ($id, $d, $file, $doc, $reset): void {
      $d['updated_at'] = date('Y-m-d H:i:s');
      if ($file) { $d['file_id'] = Storage::attach($file, 'hr', 'company_doc', $id, 'company_doc'); }
      DB::update('company_documents', $d, 'id=?', [$id]);
      if ($reset) { DB::delete('company_document_acks', 'document_id=?', [$id]); }
      Audit::log('hr.company_doc.update', 'company_doc', $id, ['title' => $d['title'], 'category' => $d['category'], 'requires_ack' => $d['requires_ack'], 'file_replaced' => (bool) $file, 'acks_reset' => $reset]); });
    if ($file && $doc['file_id']) { Storage::remove((int) $doc['file_id']); }
    if ($reset) { Notify::permission('hr.company_docs.view', 'Updated: '.$d['title'], 'This document was updated. Please read it again and acknowledge.', '/hr/documents?show=pending'); }
    $this->flash('Document updated'.($file ? ' with the new file' : '').($reset ? ' — everyone has been asked to acknowledge again.' : '.'));
    return $this->redirect('/hr/documents#doc-'.$id); }

  public function destroy(int $id): Response {
    $doc = $this->findOr404('company_documents', $id);
    DB::transaction(static function () use ($id, $doc): void {
      DB::delete('company_documents', 'id=?', [$id]);
      Audit::log('hr.company_doc.delete', 'company_doc', $id, ['title' => $doc['title']]); });
    if ($doc['file_id']) { Storage::remove((int) $doc['file_id']); }
    $this->flash('“'.$doc['title'].'” deleted.');
    return $this->redirect('/hr/documents'); }

  /** "I have read this" — only for documents that ask for acknowledgement. Idempotent. */
  public function acknowledge(int $id): Response {
    $doc = $this->findOr404('company_documents', $id);
    if (!(int) $doc['requires_ack']) { $this->abort(422, 'This document does not need an acknowledgement.'); }
    if (DB::query('INSERT IGNORE INTO company_document_acks (document_id, user_id, acknowledged_at) VALUES (?,?,?)', [$id, $this->uid(), date('Y-m-d H:i:s')])->rowCount()) { Audit::log('hr.company_doc.ack', 'company_doc', $id, ['title' => $doc['title']]); }
    if ($this->request->wantsJson()) { return $this->json(['ok' => true]); }
    $this->flash('Thanks — your acknowledgement of “'.$doc['title'].'” was recorded.');
    return $this->back(); }

  /** Dashboard widget: documents still awaiting the user's acknowledgement. */
  public static function pendingFor(int $uid, int $limit = 4): array {
    return DB::all('SELECT d.id, d.title, d.category, COALESCE(d.updated_at, d.created_at) AS at FROM company_documents d LEFT JOIN company_document_acks a ON a.document_id=d.id AND a.user_id=? WHERE d.requires_ack=1 AND a.user_id IS NULL ORDER BY COALESCE(d.updated_at, d.created_at) DESC LIMIT '.max(1, $limit), [$uid]); } }
