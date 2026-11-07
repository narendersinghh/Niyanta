<?php
namespace Modules\Payroll\Services;
use Core\{Audit, Auth, DB};

/**
 * HR edits of draft payslips: manual earnings/deductions (bonus, one-time deduction, other lines, each with a note), amount overrides or
 * removal of calculated lines, and payable/overtime hour overrides. Edits are stored on the payslip (edits JSON) next to its calculation
 * inputs (calc JSON), so totals are recomputed on the server for previews and saves and survive "Regenerate". Every save is audit-logged
 * with before/after figures. Only drafts can be edited: a published run must be reopened first (PayrollService::reopen()).
 */
final class PayslipEditor {
  public const TYPES = ['bonus' => ['Bonus', 'earning'], 'earning' => ['Other earning', 'earning'], 'one_time' => ['One-time deduction', 'deduction'], 'deduction' => ['Other deduction', 'deduction']];
  private const MAX_AMOUNT = 100000000; private const MAX_HOURS = 744;

  /** Payslip with its run status, or null. */
  public static function find(int $slipId): ?array { return DB::first('SELECT s.*, r.status AS run_status, r.period_month FROM payslips s JOIN payroll_runs r ON r.id=s.run_id WHERE s.id=?', [$slipId]); }

  public static function calc(array $slip): ?array { $c = json_decode((string) ($slip['calc'] ?? ''), true); return is_array($c) && isset($c['comp']) ? $c : null; }
  public static function edits(array $slip): array { $e = json_decode((string) ($slip['edits'] ?? ''), true); return is_array($e) ? $e : []; }

  /** Calculated lines of a payslip before line edits and manual lines (with the current hour overrides and a cover preview), for the edit form. */
  public static function autoLines(array $slip, array $edits): array {
    $calc = self::calc($slip);
    if (!$calc) { return ['earnings' => [], 'deductions' => []]; }
    $s = PayrollService::computeSlip($calc, ['hours' => $edits['hours'] ?? []], PayrollService::coverFor($calc, (int) $slip['user_id'], $slip['period_month'], false, true));
    return ['earnings' => $s['earnings'], 'deductions' => $s['deductions']]; }

  /**
   * Edits from the posted form: hours[payable|overtime|hours] (blank = calculated), lines[code][amount|removed|note] for calculated lines,
   * manual[i][id|type|name|amount|note]. Throws \DomainException with a readable message on invalid input.
   */
  public static function normalize(array $in, array $slip): array {
    $calc = self::calc($slip);
    if (!$calc) { throw new \DomainException('This payslip was created before payslip editing existed. Regenerate the run first.'); }
    $num = static function (mixed $v, string $what, float $max): ?float {
      if ($v === null || (is_string($v) && trim($v) === '')) { return null; }
      if (!is_numeric($v) || (float) $v < 0 || (float) $v > $max) { throw new \DomainException("$what must be a number between 0 and ".number_format($max, 0, '.', '').'.'); }
      return round((float) $v, 2); };
    $out = ['hours' => [], 'lines' => [], 'manual' => []];
    $h = is_array($in['hours'] ?? null) ? $in['hours'] : [];
    if ($calc['hx'] ?? null) {
      foreach (['payable' => 'Payable hours', 'overtime' => 'Overtime hours'] as $k => $l) { if (($v = $num($h[$k] ?? null, $l, self::MAX_HOURS)) !== null) { $out['hours'][$k] = $v; } } }
    elseif (($calc['comp']['pay_type'] ?? '') === 'hourly' && ($v = $num($h['hours'] ?? null, 'Hours', self::MAX_HOURS)) !== null) { $out['hours']['hours'] = $v; }
    $auto = self::autoLines($slip, $out);
    $codes = [];
    foreach ([...$auto['earnings'], ...$auto['deductions']] as $l) { if (($l['code'] ?? 'reimb') !== 'reimb') { $codes[$l['code']] = $l['name']; } }
    foreach (is_array($in['lines'] ?? null) ? $in['lines'] : [] as $code => $l) {
      $code = (string) $code;
      if (!isset($codes[$code]) || !is_array($l)) { continue; }
      $removed = !empty($l['removed']); $amt = $removed ? null : $num($l['amount'] ?? null, 'Amount of '.$codes[$code], self::MAX_AMOUNT);
      $note = mb_substr(trim((string) ($l['note'] ?? '')), 0, 255);
      if ($removed) { $out['lines'][$code] = ['removed' => true, 'note' => $note]; }
      elseif ($amt !== null) { $out['lines'][$code] = ['amount' => $amt, 'note' => $note]; } }
    $seen = [];
    foreach (is_array($in['manual'] ?? null) ? $in['manual'] : [] as $m) {
      if (!is_array($m)) { continue; }
      $type = (string) ($m['type'] ?? '');
      $name = trim((string) ($m['name'] ?? '')); $amtRaw = $m['amount'] ?? '';
      if ($name === '' && trim((string) $amtRaw) === '') { continue; }
      if (!isset(self::TYPES[$type])) { throw new \DomainException('Choose the kind of each added line.'); }
      $name = $name === '' ? self::TYPES[$type][0] : $name;
      if (mb_strlen($name) > 120) { throw new \DomainException('Line names can be at most 120 characters.'); }
      $amt = $num($amtRaw, 'Amount of '.$name, self::MAX_AMOUNT);
      if (!$amt) { throw new \DomainException('Enter an amount above zero for '.$name.'.'); }
      $id = preg_match('/^[a-f0-9]{8}$/', (string) ($m['id'] ?? '')) && !isset($seen[$m['id']]) ? (string) $m['id'] : bin2hex(random_bytes(4));
      $seen[$id] = true;
      $out['manual'][] = ['id' => $id, 'kind' => self::TYPES[$type][1], 'type' => $type, 'name' => $name, 'amount' => $amt, 'note' => mb_substr(trim((string) ($m['note'] ?? '')), 0, 255)]; }
    return array_filter($out); }

  /** Totals and lines with $edits applied, without saving or recording leave (shortage cover is a preview). */
  public static function preview(array $slip, array $edits): array {
    $calc = self::calc($slip) ?? throw new \DomainException('Regenerate the run before editing this payslip.');
    return PayrollService::computeSlip($calc, $edits, PayrollService::coverFor($calc, (int) $slip['user_id'], $slip['period_month'], false, true)); }

  /** Save edits of a draft payslip (recorded shortage cover included), update the run total and audit-log before/after. Returns the new slip figures. */
  public static function save(int $slipId, array $edits, string $reason, string $action = 'payroll.payslip.edit'): array {
    $reason = trim($reason);
    return DB::transaction(static function () use ($slipId, $edits, $reason, $action): array {
      $slip = DB::first('SELECT s.*, r.status AS run_status, r.period_month FROM payslips s JOIN payroll_runs r ON r.id=s.run_id WHERE s.id=? FOR UPDATE', [$slipId]);
      if (!$slip) { throw new \DomainException('Payslip not found.'); }
      if ($slip['run_status'] !== 'draft') { throw new \DomainException('This payslip is published and locked. Reopen the run to edit it.'); }
      $calc = self::calc($slip) ?? throw new \DomainException('This payslip was created before payslip editing existed. Regenerate the run first.');
      $old = self::edits($slip);
      $changes = self::changes($old, $edits, $slip);
      if (!$changes) { return ['changed' => false] + self::figures($slip); }
      if (mb_strlen($reason) < 3) { throw new \DomainException('Add a short note explaining the change (at least 3 characters).'); }
      $s = PayrollService::computeSlip($calc, $edits, PayrollService::coverFor($calc, (int) $slip['user_id'], $slip['period_month'], true, PayrollService::coverOf($slip) !== null));
      DB::update('payslips', PayrollService::slipRow($s) + ['edits' => $s['edited'] ? json_encode($edits, JSON_UNESCAPED_UNICODE) : null, 'edited_by' => $s['edited'] ? Auth::id() : null, 'edited_at' => $s['edited'] ? date('Y-m-d H:i:s') : null], 'id=?', [$slipId]);
      DB::query('UPDATE payroll_runs SET total_net=(SELECT COALESCE(SUM(net),0) FROM payslips WHERE run_id=?) WHERE id=?', [$slip['run_id'], $slip['run_id']]);
      $after = self::figures($s);
      Audit::log($action, 'payslip', $slipId, ['run_id' => (int) $slip['run_id'], 'user_id' => (int) $slip['user_id'], 'reason' => mb_substr($reason, 0, 500), 'changes' => $changes,
        'before' => self::figures($slip) + ['edits' => $old], 'after' => $after + ['edits' => $s['edited'] ? $edits : []]]);
      return ['changed' => true] + $after; }); }

  /** Add one manual line to several draft payslips of a run (each saved and audit-logged like a single edit). Returns how many changed. */
  public static function addToMany(int $runId, array $slipIds, array $line, string $reason): int {
    $slipIds = array_values(array_unique(array_filter(array_map('intval', $slipIds))));
    if (!$slipIds) { throw new \DomainException('Select at least one employee.'); }
    return DB::transaction(static function () use ($runId, $slipIds, $line, $reason): int {
      $n = 0;
      foreach (DB::all('SELECT s.*, r.status AS run_status, r.period_month FROM payslips s JOIN payroll_runs r ON r.id=s.run_id WHERE s.run_id=? AND s.id IN '.DB::in($slipIds), [$runId, ...$slipIds]) as $slip) {
        $e = self::edits($slip);
        $e['manual'] = [...($e['manual'] ?? []), ...(self::normalize(['manual' => [$line]], $slip)['manual'] ?? [])];
        $n += (int) self::save((int) $slip['id'], $e, $reason, 'payroll.payslip.bulk_line')['changed']; }
      return $n; }); }

  /** Figures compared in the audit log. */
  public static function figures(array $s): array {
    $c = PayrollService::coverOf($s);
    return ['gross' => (float) $s['gross'], 'deductions' => (float) $s['total_deductions'], 'net' => (float) $s['net'], 'base_pay' => (float) $s['base_pay'], 'payable_hours' => $s['payable_hours'] === null ? null : (float) $s['payable_hours'], 'overtime_hours' => (float) $s['overtime_hours'], 'shortage_cover_hours' => $c ? (float) $c['hours'] : 0.0]; }

  /** Human-readable list of what changed between two edit sets. */
  public static function changes(array $old, array $new, array $slip): array {
    $out = []; $n = static fn ($v) => $v === null ? 'calculated' : rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
    foreach (['payable' => 'Payable hours', 'overtime' => 'Overtime hours', 'hours' => 'Hours'] as $k => $l) { $a = isset($old['hours'][$k]) ? (float) $old['hours'][$k] : null; $b = isset($new['hours'][$k]) ? (float) $new['hours'][$k] : null; if ($a !== $b) { $out[] = "$l: ".$n($a).' → '.$n($b); } }
    $names = [];
    foreach (array_merge(json_decode((string) $slip['earnings'], true) ?: [], json_decode((string) $slip['deductions'], true) ?: []) as $l) { if (isset($l['code'])) { $names[$l['code']] = $l['name']; } }
    foreach (array_unique(array_merge(array_keys($old['lines'] ?? []), array_keys($new['lines'] ?? []))) as $code) {
      $a = $old['lines'][$code] ?? null; $b = $new['lines'][$code] ?? null; $nm = $names[$code] ?? $code;
      if (($a['removed'] ?? false) !== ($b['removed'] ?? false)) { $out[] = ($b['removed'] ?? false) ? "Removed line $nm" : "Restored line $nm"; }
      elseif ((isset($a['amount']) ? (float) $a['amount'] : null) !== (isset($b['amount']) ? (float) $b['amount'] : null)) { $out[] = "$nm: ".($a ? $n($a['amount'] ?? null) : 'calculated').' → '.($b ? $n($b['amount'] ?? null) : 'calculated'); }
      elseif (($a['note'] ?? '') !== ($b['note'] ?? '')) { $out[] = "Note on $nm changed"; } }
    $om = array_column($old['manual'] ?? [], null, 'id'); $nmn = array_column($new['manual'] ?? [], null, 'id');
    foreach ($nmn as $id => $m) {
      $label = (self::TYPES[$m['type']][0] ?? 'Line').' "'.$m['name'].'"';
      if (!isset($om[$id])) { $out[] = "Added $label ".$n($m['amount']); continue; }
      $o = $om[$id];
      if ([$o['type'], $o['name'], (float) $o['amount'], $o['note'] ?? ''] !== [$m['type'], $m['name'], (float) $m['amount'], $m['note'] ?? '']) { $out[] = "Changed $label ".((float) $o['amount'] !== (float) $m['amount'] ? $n($o['amount']).' → '.$n($m['amount']) : ''); } }
    foreach (array_diff_key($om, $nmn) as $m) { $out[] = 'Removed '.(self::TYPES[$m['type']][0] ?? 'line').' "'.$m['name'].'" '.$n($m['amount']); }
    return array_map('trim', $out); } }
