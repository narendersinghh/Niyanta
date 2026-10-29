<?php
namespace Modules\Crm\Controllers;
use Core\{Audit, DB, Modules, Notify, Response, Storage, ValidationException};
use Modules\Crm\Services\{ClientAccess, Finance};

/** Clients: list with KPIs & filters, create/edit (managers, tags), profile with journey/documents/finance, delete. */
final class ClientController extends CrmController {
  public const STATUSES = ['lead' => 'Lead', 'active' => 'Active', 'on_hold' => 'On hold', 'inactive' => 'Inactive'];

  public function index(): Response {
    $q = mb_substr($this->request->str('q'), 0, 100); $status = $this->request->str('status'); $tag = $this->request->int('tag'); $mgr = $this->request->int('manager');
    $view = $this->request->str('view') === 'cards' ? 'cards' : 'table';
    $sort = $this->request->str('sort');
    [$w, $p] = $this->scope();
    $sql = "SELECT c.id, c.name, c.company, c.email, c.phone, c.whatsapp, c.industry, c.status, c.city, c.country, c.created_at FROM clients c WHERE $w";
    if ($q !== '') { $sql .= ' AND (c.name LIKE ? OR c.company LIKE ? OR c.email LIKE ? OR c.phone LIKE ?)'; array_push($p, DB::like($q), DB::like($q), DB::like($q), DB::like($q)); }
    if (isset(self::STATUSES[$status])) { $sql .= ' AND c.status=?'; $p[] = $status; }
    if ($tag) { $sql .= ' AND EXISTS (SELECT 1 FROM client_tags ct WHERE ct.client_id=c.id AND ct.tag_id=?)'; $p[] = $tag; }
    if ($mgr) { $sql .= ' AND EXISTS (SELECT 1 FROM client_managers cmf WHERE cmf.client_id=c.id AND cmf.user_id=?)'; $p[] = $mgr; }
    $order = match ($sort) { 'name' => 'COALESCE(NULLIF(c.company,\'\'), c.name)', 'oldest' => 'c.id', default => 'c.id DESC' };
    $page = DB::paginate($sql.' ORDER BY '.$order, $p, $view === 'cards' ? 18 : 20);
    $ids = array_map(static fn ($r) => (int) $r['id'], $page['items']);
    $tags = $managers = $due = [];
    if ($ids) {
      foreach (DB::all('SELECT ct.client_id, t.id, t.name, t.color FROM client_tags ct JOIN tags t ON t.id=ct.tag_id WHERE ct.client_id IN '.DB::in($ids).' ORDER BY t.name', $ids) as $r) { $tags[$r['client_id']][] = $r; }
      foreach (DB::all('SELECT cm.client_id, u.id, u.name, u.avatar FROM client_managers cm JOIN users u ON u.id=cm.user_id WHERE cm.client_id IN '.DB::in($ids).' ORDER BY u.name', $ids) as $r) { $managers[$r['client_id']][] = $r; }
      if ($this->can('crm.finance.view')) { $due = DB::pairs('SELECT client_id, SUM(total-amount_paid) FROM invoices WHERE client_id IN '.DB::in($ids).' AND status IN '.Finance::OPEN.' GROUP BY client_id', $ids); } }
    [$w2, $p2] = $this->scope();
    $k = DB::first("SELECT COUNT(*) AS total, COALESCE(SUM(c.status='active'),0) AS active, COALESCE(SUM(c.status='lead'),0) AS leads FROM clients c WHERE $w2", $p2);
    if ($this->can('crm.finance.view')) {
      [$w3, $p3] = $this->scope('i.client_id');
      $k['outstanding'] = (float) DB::value('SELECT COALESCE(SUM(i.total-i.amount_paid),0) FROM invoices i WHERE i.status IN '.Finance::OPEN." AND $w3", $p3);
      [$w4, $p4] = $this->scope('py.client_id');
      $k['collected'] = (float) DB::value("SELECT COALESCE(SUM(py.amount),0) FROM payments py WHERE py.paid_on>=? AND $w4", [date('Y-m-01'), ...$p4]); }
    return $this->view('crm::clients/index', ['title' => 'Clients', 'p' => $page, 'tags' => $tags, 'managers' => $managers, 'due' => $due, 'k' => $k, 'view' => $view,
      'tagOptions' => DB::pairs('SELECT t.id, t.name FROM tags t WHERE EXISTS (SELECT 1 FROM client_tags ct WHERE ct.tag_id=t.id) ORDER BY t.name'), 'managerOptions' => $this->managerCandidates(), 'styles' => ['css/crm.css'], 'scripts' => ['js/crm.js']]); }

  public function create(): Response {
    $c = ['id' => null, 'status' => 'lead', 'country' => '', 'currency' => setting('general.currency_code', 'INR')];
    return $this->view('crm::clients/form', ['title' => 'New client', 'c' => $c, 'tagStr' => '', 'selected' => [$this->uid()], 'candidates' => $this->managerCandidates(), 'styles' => ['css/crm.css'], 'scripts' => ['js/crm.js']]); }

  public function edit(int $id): Response {
    $c = $this->client($id);
    $tagStr = implode(', ', DB::column('SELECT t.name FROM client_tags ct JOIN tags t ON t.id=ct.tag_id WHERE ct.client_id=? ORDER BY t.name', [$id]));
    $sel = array_map('intval', DB::column('SELECT user_id FROM client_managers WHERE client_id=?', [$id]));
    return $this->view('crm::clients/form', ['title' => 'Edit '.$c['name'], 'c' => $c, 'tagStr' => $tagStr, 'selected' => $sel, 'candidates' => $this->managerCandidates(), 'styles' => ['css/crm.css'], 'scripts' => ['js/crm.js']]); }

  public function store(): Response {
    [$d, $tags, $mgrs] = $this->input();
    $id = DB::transaction(function () use ($d, $tags, $mgrs) {
      $id = DB::insert('clients', $d + ['created_by' => $this->uid(), 'created_at' => $this->now(), 'updated_at' => $this->now()]);
      $this->syncTags($id, $tags);
      $this->syncManagers($id, $mgrs, []);
      return $id; });
    ClientAccess::flush();
    Audit::log('crm.client.create', 'client', $id, ['name' => $d['name']]);
    $this->flash('Client created.');
    return $this->redirect('/clients/'.$id); }

  public function update(int $id): Response {
    $old = $this->client($id);
    [$d, $tags, $mgrs] = $this->input();
    $before = array_map('intval', DB::column('SELECT user_id FROM client_managers WHERE client_id=?', [$id]));
    DB::transaction(function () use ($id, $d, $tags, $mgrs, $before) {
      DB::update('clients', $d + ['updated_at' => $this->now()], 'id=?', [$id]);
      $this->syncTags($id, $tags);
      $this->syncManagers($id, $mgrs, $before); });
    ClientAccess::flush();
    Audit::log('crm.client.update', 'client', $id, ['changed' => array_keys(array_diff_assoc(array_map('strval', $d), array_map('strval', array_intersect_key($old, $d))))]);
    $this->flash('Client updated.');
    return $this->redirect('/clients/'.$id); }

  public function destroy(int $id): Response {
    $c = $this->client($id);
    if (DB::value('SELECT 1 FROM invoices WHERE client_id=? LIMIT 1', [$id])) { $this->flash('This client has invoices and cannot be deleted. Mark the client inactive instead.', 'error'); return $this->redirect('/clients/'.$id); }
    $files = DB::column("SELECT id FROM files WHERE module='crm' AND entity_type='client' AND entity_id=?", [$id]);
    DB::transaction(static function () use ($id) {
      DB::delete('quotations', 'client_id=?', [$id]);
      DB::delete('clients', 'id=?', [$id]); });
    foreach ($files as $f) { Storage::remove((int) $f); }
    ClientAccess::flush();
    Audit::log('crm.client.delete', 'client', $id, ['name' => $c['name'], 'company' => $c['company']]);
    $this->flash('Client deleted.');
    return $this->redirect('/clients'); }

  public function show(int $id): Response {
    $c = $this->client($id);
    // Quotations and invoices are separate features: each part of the Finance tab follows its own permission.
    $fin = $this->can('crm.finance.view'); $qv = $this->can('crm.quotations.view');
    $projOn = feature_on('projects');
    $data = ['title' => $c['company'] ?: $c['name'], 'c' => $c, 'fin' => $fin, 'qv' => $qv, 'projOn' => $projOn, 'styles' => ['css/crm.css'], 'scripts' => ['js/crm.js']];
    $data['tags'] = DB::all('SELECT t.id, t.name, t.color FROM client_tags ct JOIN tags t ON t.id=ct.tag_id WHERE ct.client_id=? ORDER BY t.name', [$id]);
    $data['managers'] = DB::all('SELECT u.id, u.name, u.avatar, u.email, u.designation FROM client_managers cm JOIN users u ON u.id=cm.user_id WHERE cm.client_id=? ORDER BY u.name', [$id]);
    $data['contacts'] = DB::all('SELECT * FROM client_contacts WHERE client_id=? ORDER BY is_primary DESC, name', [$id]);
    $data['projects'] = $projOn ? DB::all('SELECT id, name, code, status, progress, deadline, budget FROM projects WHERE client_id=? ORDER BY FIELD(status,\'active\',\'planning\',\'on_hold\',\'completed\',\'cancelled\'), id DESC LIMIT 50', [$id]) : [];
    $jp = max(1, $this->request->int('jp', 1)); $per = 25;
    $type = $this->request->str('jt');
    $aw = 'a.client_id=?'; $ap = [$id];
    if (isset(JourneyController::TYPES[$type])) { $aw .= ' AND a.type=?'; $ap[] = $type; }
    $acts = DB::all('SELECT a.*, u.name AS user_name, u.avatar AS user_avatar'.($projOn ? ', pr.name AS project_name' : ', NULL AS project_name').' FROM client_activities a LEFT JOIN users u ON u.id=a.user_id'.($projOn ? ' LEFT JOIN projects pr ON pr.id=a.project_id' : '')." WHERE $aw ORDER BY a.occurred_at DESC, a.id DESC LIMIT ".($per + 1).' OFFSET '.(($jp - 1) * $per), $ap);
    $data['more'] = count($acts) > $per;
    $data['acts'] = array_slice($acts, 0, $per);
    $data['jp'] = $jp; $data['jt'] = $type;
    $data['typeCounts'] = DB::pairs('SELECT type, COUNT(*) FROM client_activities WHERE client_id=? GROUP BY type', [$id]);
    $data['followUps'] = DB::all('SELECT id, title, follow_up_at, type FROM client_activities WHERE client_id=? AND follow_up_at IS NOT NULL AND follow_up_done=0 ORDER BY follow_up_at LIMIT 10', [$id]);
    $data['docs'] = DB::all("SELECT f.id, f.original_name, f.mime, f.size, f.created_at, u.name AS uploader FROM files f LEFT JOIN users u ON u.id=f.uploaded_by WHERE f.module='crm' AND f.entity_type='client' AND f.entity_id=? ORDER BY f.id DESC", [$id]);
    $data['sum'] = ['invoiced' => 0, 'paid' => 0, 'outstanding' => 0, 'overdue' => 0];
    $data['quotes'] = $data['invoices'] = $data['payments'] = [];
    if ($qv) { $data['quotes'] = DB::all('SELECT id, number, title, issue_date, valid_until, status, total, invoice_id FROM quotations WHERE client_id=? ORDER BY id DESC LIMIT 50', [$id]); }
    if ($fin) {
      $data['sum'] = DB::first("SELECT COALESCE(SUM(CASE WHEN status NOT IN ('draft','cancelled') THEN total END),0) AS invoiced, COALESCE(SUM(CASE WHEN status<>'cancelled' THEN amount_paid END),0) AS paid, COALESCE(SUM(CASE WHEN status IN ".Finance::OPEN." THEN total-amount_paid END),0) AS outstanding, COALESCE(SUM(status='overdue'),0) AS overdue FROM invoices WHERE client_id=?", [$id]);
      $data['invoices'] = DB::all('SELECT id, number, title, issue_date, due_date, status, total, amount_paid FROM invoices WHERE client_id=? ORDER BY id DESC LIMIT 50', [$id]);
      $data['payments'] = DB::all('SELECT py.*, i.number FROM payments py JOIN invoices i ON i.id=py.invoice_id WHERE py.client_id=? ORDER BY py.paid_on DESC, py.id DESC LIMIT 50', [$id]); }
    $data['projectOptions'] = array_column($data['projects'], 'name', 'id');
    return $this->view('crm::clients/show', $data); }

  /** Active users who may be account managers: manager / super admin roles or anyone holding crm.clients.manage. */
  private function managerCandidates(): array {
    return DB::pairs("SELECT u.id, u.name FROM users u JOIN roles r ON r.id=u.role_id LEFT JOIN role_permissions rp ON rp.role_id=r.id AND rp.permission='crm.clients.manage' WHERE u.status='active' AND (r.slug IN ('super_admin','manager') OR rp.permission IS NOT NULL) GROUP BY u.id, u.name ORDER BY u.name"); }

  private function input(): array {
    $d = $this->validate(['name' => 'required|max:150', 'company' => 'nullable|max:190', 'email' => 'nullable|email|max:190', 'phone' => 'nullable|phone', 'whatsapp' => 'nullable|phone', 'website' => 'nullable|max:255', 'industry' => 'nullable|max:120',
      'address' => 'nullable|max:1000', 'city' => 'nullable|max:100', 'state' => 'nullable|max:100', 'country' => 'nullable|max:100', 'postal_code' => 'nullable|max:20', 'tax_id' => 'nullable|max:60', 'status' => 'required|in:lead,active,on_hold,inactive',
      'source' => 'nullable|max:100', 'notes' => 'nullable|max:5000', 'currency' => 'nullable|regex:/^[A-Za-z]{3}$/'], ['tax_id' => 'Tax ID', 'whatsapp' => 'WhatsApp']);
    if ($d['website']) {
      if (!preg_match('#^https?://#i', $d['website'])) { $d['website'] = 'https://'.$d['website']; }
      if (!filter_var($d['website'], FILTER_VALIDATE_URL)) { throw new ValidationException(['website' => 'Website must be a valid URL.']); } }
    if ($d['currency']) { $d['currency'] = strtoupper($d['currency']); }
    $tags = [];
    foreach (explode(',', $this->request->str('tags')) as $t) {
      $t = trim(preg_replace('/\s+/', ' ', $t));
      if ($t === '') { continue; }
      if (mb_strlen($t) > 60) { throw new ValidationException(['tags' => 'Tags may not exceed 60 characters each.']); }
      $tags[mb_strtolower($t)] = $t; }
    if (count($tags) > 15) { throw new ValidationException(['tags' => 'Use at most 15 tags.']); }
    $allowed = array_map('intval', array_keys($this->managerCandidates()));
    $mgrs = array_values(array_intersect(array_unique(array_map('intval', $this->request->arr('managers'))), $allowed));
    // A user who only sees assigned clients must stay assigned, otherwise they would lose access to what they just saved.
    if (!ClientAccess::unrestricted($this->user()) && !in_array($this->uid(), $mgrs, true)) { $mgrs[] = $this->uid(); }
    return [$d, array_values($tags), $mgrs]; }

  private function syncTags(int $id, array $names): void {
    DB::delete('client_tags', 'client_id=?', [$id]);
    if (!$names) { return; }
    $colors = ['lav', 'mint', 'lemon', 'peach', 'sky', 'pink'];
    foreach ($names as $n) { DB::query('INSERT IGNORE INTO tags (name, color) VALUES (?,?)', [$n, $colors[crc32(mb_strtolower($n)) % 6]]); }
    $tagIds = DB::column('SELECT id FROM tags WHERE name IN '.DB::in($names), $names);
    DB::insertMany('client_tags', array_map(static fn ($t) => ['client_id' => $id, 'tag_id' => (int) $t], array_unique($tagIds))); }

  private function syncManagers(int $id, array $new, array $before): void {
    DB::delete('client_managers', 'client_id=?', [$id]);
    DB::insertMany('client_managers', array_map(static fn ($u) => ['client_id' => $id, 'user_id' => $u], $new));
    $added = array_values(array_diff($new, $before));
    $removed = array_values(array_diff($before, $new));
    if (!$added && !$removed) { return; }
    $names = DB::pairs('SELECT id, name FROM users WHERE id IN '.DB::in([...$added, ...$removed]), [...$added, ...$removed]);
    $client = DB::value("SELECT COALESCE(NULLIF(company,''), name) FROM clients WHERE id=?", [$id]);
    $parts = [];
    if ($added) { $parts[] = 'Assigned: '.implode(', ', array_map(static fn ($u) => $names[$u] ?? '#'.$u, $added)); }
    if ($removed) { $parts[] = 'Unassigned: '.implode(', ', array_map(static fn ($u) => $names[$u] ?? '#'.$u, $removed)); }
    DB::insert('client_activities', ['client_id' => $id, 'type' => 'assignment', 'title' => 'Account team updated', 'body' => implode("\n", $parts), 'is_internal' => 1, 'occurred_at' => $this->now(), 'user_id' => $this->uid(), 'created_at' => $this->now()]);
    foreach ($added as $u) { if ($u !== $this->uid()) { Notify::user($u, 'You were assigned to '.$client, 'You are now an account manager for this client.', '/clients/'.$id, false, 'briefcase'); } } } }
