<?php
namespace Modules\Payroll\Services;
use Core\{Audit, Auth, DB, Gate, HttpException};

/**
 * Who may open draft runs and edit draft payslips. payroll.manage (HR, Super Admin): every run and payslip.
 * payroll.payslips.edit (managers): only draft runs, and inside them only the payslips of their direct reports (users.manager_id),
 * never their own. Everything else (create, generate, finalize, reopen, delete, export, paid, notes) stays payroll.manage.
 */
final class PayrollAccess {
  public static function full(?array $u = null): bool { return Gate::allows('payroll.manage', $u); }
  /** Team-scoped reviewer: holds payroll.payslips.edit but not payroll.manage. */
  public static function team(?array $u = null): bool { return !self::full($u) && Gate::allows('payroll.payslips.edit', $u); }

  /** Direct reports of $u (excluding $u). */
  public static function teamIds(?array $u = null): array {
    $uid = (int) (($u ?? Auth::user())['id'] ?? 0);
    if (!$uid) { return []; }
    return array_map('intval', DB::column('SELECT id FROM users WHERE manager_id=? AND id<>?', [$uid, $uid])); }

  /** [sql, params] restricting a payslip user column to what $u may see; null = everything. */
  public static function scope(string $col, ?array $u = null): ?array {
    if (self::full($u)) { return null; }
    $ids = self::team($u) ? self::teamIds($u) : [];
    return [$col.' IN '.DB::in($ids), $ids]; }

  /** May $u open and edit this payslip (row with user_id and run_status)? Team reviewers only while the run is a draft. */
  public static function canEdit(array $slip, ?array $u = null): bool {
    if (self::full($u)) { return true; }
    return self::team($u) && $slip['run_status'] === 'draft' && in_array((int) $slip['user_id'], self::teamIds($u), true); }

  /** Record-level guard for controllers: 403 with an access.denied audit entry. */
  public static function authorizeSlip(array $slip): void {
    if (self::canEdit($slip)) { return; }
    Audit::log('access.denied', 'payslip', (int) $slip['id'], ['area' => 'payroll.payslip.edit', 'run_status' => $slip['run_status']]);
    throw new HttpException(403, $slip['run_status'] === 'draft' ? 'You can only review payslips of your own team.' : 'This payslip is finalized. Only payroll managers can reopen the run.'); }

  /** Draft runs holding at least one payslip of $u's team (sidebar badge for managers). */
  public static function draftRunsForTeam(?array $u = null): int {
    if (!self::team($u) || !($ids = self::teamIds($u))) { return 0; }
    return (int) DB::value("SELECT COUNT(DISTINCT s.run_id) FROM payslips s JOIN payroll_runs r ON r.id=s.run_id WHERE r.status='draft' AND s.user_id IN ".DB::in($ids), $ids); } }
