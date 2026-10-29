<?php
namespace Modules\Crm\Controllers;
use Core\{Controller, DB};
use Modules\Crm\Services\ClientAccess;

/** Shared record-level scoping for every CRM controller. */
abstract class CrmController extends Controller {
  /** [sql, params] restricting a client-id column to clients the current user may see. */
  protected function scope(string $col = 'c.id'): array { return ClientAccess::where($this->user(), $col); }

  /** A visible client or 404 (hidden clients are indistinguishable from missing ones). */
  protected function client(int $id): array {
    if (!ClientAccess::canSee($this->user(), $id)) { $this->abort(404, 'Client not found.'); }
    return $this->findOr404('clients', $id); }

  /** Visible clients as id => label for selects. */
  protected function clientOptions(): array {
    [$w, $p] = $this->scope();
    return DB::pairs("SELECT c.id, CASE WHEN c.company IS NULL OR c.company='' THEN c.name ELSE CONCAT(c.company, ' (', c.name, ')') END FROM clients c WHERE $w AND c.status<>'inactive' ORDER BY COALESCE(NULLIF(c.company,''), c.name) LIMIT 2000", $p); }

  /** Load a quotation/invoice joined to its client, enforcing client visibility. */
  protected function doc(string $table, int $id): array {
    [$w, $p] = $this->scope('d.client_id');
    $proj = feature_on('projects') ? ', pr.name AS project_name' : ', NULL AS project_name';
    $join = feature_on('projects') ? ' LEFT JOIN projects pr ON pr.id=d.project_id' : '';
    $row = DB::first('SELECT d.*, c.name AS client_name, c.company AS client_company, c.email AS client_email'.$proj.' FROM '.DB::id($table).' d JOIN clients c ON c.id=d.client_id'.$join.' WHERE d.id=? AND '.$w, [$id, ...$p]);
    return $row ?? $this->abort(404, 'Record not found.'); }

  /** Validated quotation/invoice header + items with totals recalculated on the server. */
  protected function docInput(string $kind): array {
    $rules = ['client_id' => 'required|integer', 'project_id' => 'nullable|integer', 'title' => 'nullable|max:190', 'issue_date' => 'required|date', 'currency' => 'nullable|regex:/^[A-Za-z]{3}$/', 'discount' => 'nullable|numeric|min:0', 'notes' => 'nullable|max:5000', 'terms' => 'nullable|max:5000'];
    $rules += $kind === 'invoice' ? ['due_date' => 'nullable|date'] : ['valid_until' => 'nullable|date'];
    $d = $this->validate($rules, ['client_id' => 'Client', 'project_id' => 'Project', 'valid_until' => 'Valid until']);
    if (!ClientAccess::canSee($this->user(), (int) $d['client_id'])) { throw new \Core\ValidationException(['client_id' => 'Choose one of your clients.']); }
    if ($d['project_id'] && !isset(\Modules\Crm\Services\Finance::projects([(int) $d['client_id']])[(int) $d['project_id']])) { throw new \Core\ValidationException(['project_id' => 'That project does not belong to the selected client.']); }
    $d['project_id'] = $d['project_id'] ?: null;
    $end = $kind === 'invoice' ? 'due_date' : 'valid_until';
    if ($d[$end] && $d[$end] < $d['issue_date']) { throw new \Core\ValidationException([$end => ($kind === 'invoice' ? 'Due date' : 'Valid until').' cannot be before the issue date.']); }
    $items = \Modules\Crm\Services\Finance::items($this->request->arr('items'));
    $t = \Modules\Crm\Services\Finance::totals($items, $d['discount']);
    $d['currency'] = strtoupper((string) ($d['currency'] ?: setting('general.currency_code', 'INR')));
    unset($d['discount']);
    return [$t + $d, $items]; }

  /** Lookups for the quotation/invoice form. */
  protected function formLookups(): array {
    $clients = $this->clientOptions();
    return ['clients' => $clients, 'projects' => \Modules\Crm\Services\Finance::projects(array_map('intval', array_keys($clients))), 'taxRate' => (float) setting('crm.default_tax_rate', 0), 'styles' => ['css/crm.css'], 'scripts' => ['js/crm.js']]; }

  protected function now(): string { return date('Y-m-d H:i:s'); } }
