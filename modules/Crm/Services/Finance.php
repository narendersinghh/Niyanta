<?php
namespace Modules\Crm\Services;
use Core\{DB, Settings, ValidationException};

/** Shared money logic for quotations & invoices: line items, server-side totals, race-safe numbering, invoice status. */
final class Finance {
  public const INVOICE_STATUSES = ['draft', 'sent', 'partial', 'paid', 'overdue', 'cancelled'];
  public const QUOTE_STATUSES = ['draft', 'sent', 'accepted', 'rejected', 'expired'];
  public const OPEN = "('sent','partial','overdue')";
  public const METHODS = ['bank_transfer' => 'Bank transfer', 'upi' => 'UPI', 'cash' => 'Cash', 'card' => 'Card', 'cheque' => 'Cheque', 'online' => 'Online gateway', 'other' => 'Other'];

  /** Validate posted items[i][description|quantity|unit_price|tax_rate]; never trusts client-side amounts. */
  public static function items(array $rows): array {
    $out = [];
    foreach ($rows as $r) {
      if (!is_array($r)) { continue; }
      $desc = trim((string) ($r['description'] ?? ''));
      $q = $r['quantity'] ?? ''; $p = $r['unit_price'] ?? ''; $t = ($r['tax_rate'] ?? '') === '' ? 0 : $r['tax_rate'];
      if ($desc === '' && ($p === '' || (float) $p == 0.0)) { continue; }
      if ($desc === '') { throw new ValidationException(['items' => 'Every line needs a description.']); }
      if (mb_strlen($desc) > 500) { throw new ValidationException(['items' => 'Line descriptions may not exceed 500 characters.']); }
      if (!is_numeric($q) || $q <= 0 || $q > 999999) { throw new ValidationException(['items' => "Quantity for “{$desc}” must be a positive number."]); }
      if (!is_numeric($p) || $p < 0 || $p > 999999999) { throw new ValidationException(['items' => "Unit price for “{$desc}” must be zero or more."]); }
      if (!is_numeric($t) || $t < 0 || $t > 100) { throw new ValidationException(['items' => "Tax rate for “{$desc}” must be between 0 and 100."]); }
      $q = round((float) $q, 2); $p = round((float) $p, 2); $t = round((float) $t, 2);
      $out[] = ['description' => $desc, 'quantity' => $q, 'unit_price' => $p, 'tax_rate' => $t, 'amount' => round($q * $p, 2), 'position' => count($out)]; }
    if (!$out) { throw new ValidationException(['items' => 'Add at least one line item.']); }
    if (count($out) > 200) { throw new ValidationException(['items' => 'A document may have at most 200 lines.']); }
    return $out; }

  /** Totals computed on the server: subtotal, tax_total, discount (clamped), total. */
  public static function totals(array $items, mixed $discount): array {
    $sub = 0.0; $tax = 0.0;
    foreach ($items as $i) { $sub += $i['amount']; $tax += $i['amount'] * $i['tax_rate'] / 100; }
    $sub = round($sub, 2); $tax = round($tax, 2);
    $disc = is_numeric($discount) ? round(max(0, (float) $discount), 2) : 0.0;
    $disc = min($disc, $sub + $tax);
    return ['subtotal' => $sub, 'tax_total' => $tax, 'discount' => $disc, 'total' => round($sub + $tax - $disc, 2)]; }

  public static function saveItems(string $table, string $fk, int $id, array $items): void {
    DB::delete($table, DB::id($fk).'=?', [$id]);
    DB::insertMany($table, array_map(static fn ($i) => [$fk => $id] + $i, $items)); }

  /** Next document number; MUST run inside a transaction (locks the prefix range of the unique index). */
  public static function nextNumber(string $table, string $prefix): string {
    $like = addcslashes($prefix, '%_\\').'%';
    $max = (int) DB::value('SELECT MAX(CAST(SUBSTRING(number, ?) AS UNSIGNED)) FROM '.DB::id($table).' WHERE number LIKE ? FOR UPDATE', [strlen($prefix) + 1, $like]);
    return $prefix.str_pad((string) ($max + 1), 5, '0', STR_PAD_LEFT); }

  public static function prefix(string $kind): string {
    $p = (string) Settings::get($kind === 'invoice' ? 'crm.invoice_prefix' : 'crm.quotation_prefix', $kind === 'invoice' ? 'INV-' : 'QT-');
    return mb_substr(preg_replace('/[^A-Za-z0-9\/_-]/', '', $p), 0, 20); }

  /** Run a numbered insert in a transaction, retrying if a concurrent request took the same number. */
  public static function numbered(callable $fn): mixed {
    for ($try = 0; ; $try++) {
      try { return DB::transaction($fn); }
      catch (\PDOException $e) { if ($try >= 4 || (int) ($e->errorInfo[1] ?? 0) !== 1062) { throw $e; } usleep(random_int(20000, 80000)); } } }

  /** Derive an invoice status from its totals, payments and due date. */
  public static function statusFor(string $current, float $total, float $paid, ?string $due): string {
    if ($current === 'cancelled') { return 'cancelled'; }
    if ($current === 'draft' && $paid <= 0) { return 'draft'; }
    if ($total > 0 && $paid >= $total - 0.004) { return 'paid'; }
    if ($due && $due < date('Y-m-d')) { return 'overdue'; }
    return $paid > 0 ? 'partial' : 'sent'; }

  /** Recalculate amount_paid & status from the payments table (call inside a transaction). */
  public static function refreshInvoice(int $id): array {
    $inv = DB::first('SELECT * FROM invoices WHERE id=? FOR UPDATE', [$id]);
    $paid = round((float) DB::value('SELECT COALESCE(SUM(amount),0) FROM payments WHERE invoice_id=?', [$id]), 2);
    $status = self::statusFor($inv['status'], (float) $inv['total'], $paid, $inv['due_date']);
    DB::update('invoices', ['amount_paid' => $paid, 'status' => $status, 'updated_at' => date('Y-m-d H:i:s')], 'id=?', [$id]);
    return ['amount_paid' => $paid, 'status' => $status] + $inv; }

  /** Signed token for the e-mailed read-only PDF link. */
  public static function shareToken(array $inv): string {
    $key = (string) \Core\App::config('app_key', '');
    return substr(hash_hmac('sha256', 'crm-invoice|'.$inv['id'].'|'.$inv['number'].'|'.$inv['created_at'], $key), 0, 40); }

  /** Projects of the given clients, keyed by id (empty when the Projects module is off). */
  public static function projects(?array $clientIds = null): array {
    if (!feature_on('projects') || $clientIds === []) { return []; }
    $sql = 'SELECT id, client_id, name, code FROM projects WHERE client_id IS NOT NULL';
    $p = [];
    if ($clientIds !== null) { $sql .= ' AND client_id IN '.DB::in($clientIds); $p = $clientIds; }
    $rows = [];
    foreach (DB::all($sql.' ORDER BY name LIMIT 2000', $p) as $r) { $rows[(int) $r['id']] = $r; }
    return $rows; } }
