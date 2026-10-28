<?php
namespace Modules\Hr\Services;
use Core\{Auth, Crypto, Gate};

/** Record-level access rules for HR data. */
final class HrAccess {
  /** HR staff see full records; everybody else only their own profile. */
  public static function canViewProfile(int $userId, ?array $u = null): bool {
    $u ??= Auth::user();
    return $u && (Gate::allows('hr.employees.view', $u) || (int) $u['id'] === $userId); }

  public static function canViewDocuments(int $userId, ?array $u = null): bool {
    $u ??= Auth::user();
    return $u && (Gate::allows('hr.documents.manage', $u) || (int) $u['id'] === $userId); }

  /** HR role, leave managers and Super Admins review every request; other approvers only their direct reports. */
  public static function seesAllLeave(?array $u = null): bool {
    $u ??= Auth::user();
    return $u && ($u['role_slug'] === 'super_admin' || $u['role_slug'] === 'hr' || Gate::allows('hr.leave.manage', $u)); }

  /** SQL condition (aliases lr = leave_requests, u = requester) limiting requests the current approver may review. */
  public static function reviewScope(): array {
    $me = (int) Auth::id();
    if (!Gate::allows('hr.leave.approve')) { return ['1=0', []]; }
    return self::seesAllLeave() ? ['lr.user_id<>?', [$me]] : ['lr.user_id<>? AND u.manager_id=?', [$me, $me]]; }

  /** $lr must carry user_id and the requester's manager_id. */
  public static function canReview(array $lr): bool {
    $me = (int) Auth::id();
    return Gate::allows('hr.leave.approve') && (int) $lr['user_id'] !== $me && (self::seesAllLeave() || (int) $lr['manager_id'] === $me); }

  /** "••••1234" style mask of an encrypted value. */
  public static function mask(?string $enc): string {
    $v = Crypto::decrypt($enc);
    if ($v === null || $v === '') { return ''; }
    $v = preg_replace('/\s+/', '', $v);
    return '••••'.mb_substr($v, -min(4, max(1, mb_strlen($v) - 2))); } }
