<?php
namespace Modules\Hr\Services;
use Core\{Audit, DB, Gate, Notify, ValidationException};

/**
 * Corrections to leave requests after they were made, with a visible history (leave_request_events) and exact balance math:
 *  - edit (approvers, pending or approved): dates, half day, type, reason. An approved request first gives its days back to the old
 *    type ('reversal' ledger entry, used -= days), then charges the new type ('usage'), so the ledger always nets to the balance.
 *  - revoke (approvers): an approved request becomes cancelled and its days return to the balance. A note is required.
 *  - employees cancel their own pending request, or ask to cancel approved leave that has not started; the approver confirms (days return) or declines.
 *    Leave types that need no approval can be cancelled directly before they start.
 *  - book (approvers): leave applied on behalf of an employee, booked as approved.
 *  - decide (approvers, pending only): approve (optional note, charged to the balance) or reject (reason required); recorded in the history.
 * Day counts always come from LeaveService::requestDaysFor (the employee's own weekly off days, Saturday rule and fixed holidays).
 */
final class LeaveChanges {
  public const ACTIONS = ['approve' => 'Approved', 'reject' => 'Rejected', 'withdraw' => 'Withdrawn', 'edit' => 'Edited', 'revoke' => 'Revoked', 'cancel' => 'Cancelled', 'cancel_request' => 'Cancellation requested', 'cancel_approve' => 'Cancellation approved', 'cancel_decline' => 'Cancellation declined', 'on_behalf' => 'Applied on behalf'];

  /** Request with requester, manager and type details (FOR UPDATE inside a transaction), or null. */
  public static function find(int $id, bool $lock = false): ?array {
    return DB::first('SELECT lr.*, u.manager_id, u.name, t.name AS type_name, t.code, t.paid, t.needs_approval FROM leave_requests lr JOIN users u ON u.id=lr.user_id JOIN leave_types t ON t.id=lr.leave_type_id WHERE lr.id=?'.($lock ? ' FOR UPDATE' : ''), [$id]); }

  /** May the current user correct this request (the approver rules of HrAccess::canReview: HR / leave managers everyone, managers their direct reports, never their own)? */
  public static function canManage(array $lr): bool { return HrAccess::canReview($lr); }

  /** "12 Nov - 14 Nov 2026 (half day)" */
  public static function range(string $from, string $to, bool $half = false): string {
    return ($from === $to ? date('d M Y', strtotime($from)) : date('d M', strtotime($from)).' - '.date('d M Y', strtotime($to))).($half ? ' (half day)' : ''); }

  /**
   * Validate dates and policy for a request of $userId and return the days it consumes. $override (approvers correcting or booking) skips the
   * notice period and the 60-day look-back; everything else (same year, 3 months, half-day rules, working days, max in a row, eligibility) applies.
   */
  public static function check(int $userId, array $type, string $from, string $to, bool $half, bool $override): float {
    if ($to < $from) { throw new ValidationException(['end_date' => 'To date must be on or after the from date.']); }
    if (substr($from, 0, 4) !== substr($to, 0, 4)) { throw new ValidationException(['end_date' => 'Requests cannot span two calendar years. Please split them.']); }
    if ((strtotime($to) - strtotime($from)) / 86400 > 92) { throw new ValidationException(['end_date' => 'A single request can cover at most 3 months.']); }
    if ($half && $from !== $to) { throw new ValidationException(['half_day' => 'A half day must start and end on the same date.']); }
    if (!$override && $from < date('Y-m-d', strtotime('-60 days'))) { throw new ValidationException(['start_date' => 'Leave older than 60 days cannot be requested. Contact HR.']); }
    $days = LeaveService::requestDaysFor($userId, $from, $to, $half);
    if ($days <= 0) { throw new ValidationException(['start_date' => 'The selected dates fall on weekly off days or fixed holidays, no working days to take.']); }
    $e = LeaveService::validateRequest($userId, $type, $from, $to, $half, $days, $override ? $from : null);
    if ($override && isset($e['start_date']) && str_contains($e['start_date'], 'notice')) { unset($e['start_date']); }
    if ($e) { throw new ValidationException($e); }
    return $days; }

  /** Another pending / approved request of the user overlapping the range (call under the user row lock). */
  private static function clash(int $userId, string $from, string $to, ?int $except): void {
    $c = DB::first("SELECT start_date, end_date, status FROM leave_requests WHERE user_id=? AND status IN ('pending','approved') AND start_date<=? AND end_date>=?".($except ? ' AND id<>?' : '').' LIMIT 1', array_merge([$userId, $to, $from], $except ? [$except] : []));
    if ($c) { throw new ValidationException(['start_date' => 'Overlaps '.($except ? 'another' : 'a').' '.$c['status'].' leave from '.fdate($c['start_date']).' to '.fdate($c['end_date']).'.']); } }

  /** Days still free on an approved booking: allocated - used of the type/year (paid types only). */
  private static function chargeable(int $userId, array $type, int $year, float $days): void {
    if ((int) $type['paid'] !== 1) { return; }
    $b = DB::first('SELECT allocated, used FROM leave_balances WHERE user_id=? AND leave_type_id=? AND year=? FOR UPDATE', [$userId, $type['id'], $year]);
    $left = $b ? round((float) $b['allocated'] - (float) $b['used'], 2) : 0.0;
    if ($left < $days) { throw new ValidationException(['leave_type_id' => 'Insufficient '.$type['name'].' balance for '.$year.': '.LeaveService::num($left).' day(s) left, '.LeaveService::num($days).' needed. Adjust the balance first or choose another leave type.']); } }

  /** Give the days of an approved request back to its type/year ('reversal' ledger entry). Call inside a transaction. */
  public static function giveBack(array $lr, ?int $by, string $note): void {
    $year = (int) substr($lr['start_date'], 0, 4);
    DB::query('UPDATE leave_balances SET used=GREATEST(0, used-?) WHERE user_id=? AND leave_type_id=? AND year=?', [$lr['days'], $lr['user_id'], $lr['leave_type_id'], $year]);
    LeaveService::ledger((int) $lr['user_id'], (int) $lr['leave_type_id'], $year, 'reversal', (float) $lr['days'], null, (int) $lr['id'], $note, $by); }

  public static function event(int $id, ?int $by, string $action, ?string $note = null, array $changes = []): void {
    DB::insert('leave_request_events', ['request_id' => $id, 'actor_id' => $by, 'action' => $action, 'note' => $note === null || $note === '' ? null : mb_substr($note, 0, 500), 'changes' => $changes ? json_encode($changes, JSON_UNESCAPED_UNICODE) : null, 'created_at' => date('Y-m-d H:i:s')]); }

  /**
   * Approver edit. $in: leave_type_id, start_date, end_date, half_day, reason. Returns ['lr' => updated row, 'changes' => [label => [from, to]]].
   * Throws ValidationException (nothing changed, invalid dates, overlap, balance) or HttpException-style RuntimeException via the caller for access.
   */
  public static function edit(int $id, array $in, string $note, array $actor): array {
    $note = trim($note);
    if ($note === '') { throw new ValidationException(['note' => 'Add a note explaining the change. The employee sees it.']); }
    return DB::transaction(static function () use ($id, $in, $note, $actor): array {
      $lr = self::find($id, true) ?? throw new ValidationException(['note' => 'Request not found.']);
      if (!self::canManage($lr)) { throw new \Core\HttpException(403, 'You can only correct leave of people you approve.'); }
      if (!in_array($lr['status'], ['pending', 'approved'], true)) { throw new ValidationException(['note' => 'Only pending or approved leave can be edited. This one is '.$lr['status'].'.']); }
      $type = DB::first('SELECT * FROM leave_types WHERE id=? AND active=1', [(int) $in['leave_type_id']]) ?? throw new ValidationException(['leave_type_id' => 'This leave type is not available.']);
      $from = (string) $in['start_date']; $to = (string) ($in['end_date'] ?: $from); $half = (bool) $in['half_day'];
      $reason = trim((string) ($in['reason'] ?? '')) ?: null;
      $uid = (int) $lr['user_id'];
      DB::value('SELECT id FROM users WHERE id=? FOR UPDATE', [$uid]);
      $days = self::check($uid, $type, $from, $to, $half, true);
      $changes = [];
      if ((int) $type['id'] !== (int) $lr['leave_type_id']) { $changes['Type'] = [$lr['type_name'], $type['name']]; }
      $old = self::range($lr['start_date'], $lr['end_date'], (bool) $lr['half_day']); $new = self::range($from, $to, $half);
      if ($old !== $new) { $changes['Dates'] = [$old, $new]; }
      if ((float) $days !== (float) $lr['days']) { $changes['Days'] = [LeaveService::num($lr['days']), LeaveService::num($days)]; }
      if ((string) $reason !== (string) ($lr['reason'] ?? '')) { $changes['Reason'] = [(string) ($lr['reason'] ?? ''), (string) $reason]; }
      if (!$changes) { throw new ValidationException(['start_date' => 'Nothing changed. Change the dates, type, half day or reason.']); }
      self::clash($uid, $from, $to, $id);
      $year = (int) substr($from, 0, 4); $by = (int) $actor['id'];
      $row = ['leave_type_id' => (int) $type['id'], 'start_date' => $from, 'end_date' => $to, 'half_day' => $half ? 1 : 0, 'days' => $days, 'reason' => $reason, 'updated_at' => date('Y-m-d H:i:s')];
      if ($lr['status'] === 'approved') {
        self::giveBack($lr, $by, 'Returned: '.$old.' edited by '.$actor['name']);
        self::chargeable($uid, $type, $year, $days);
        LeaveService::recordUsage(['id' => $id, 'user_id' => $uid] + $row, $by); }
      else {
        $avail = null;
        if ((int) $type['paid'] === 1) {
          $bal = array_column(LeaveService::balances($uid, $year), null, 'id')[(int) $type['id']] ?? null;
          $avail = $bal && $bal['allocated_row'] ? (float) $bal['available'] + ((int) $lr['leave_type_id'] === (int) $type['id'] && (int) substr($lr['start_date'], 0, 4) === $year ? (float) $lr['days'] : 0.0) : 0.0;
          if ($avail < $days) { throw new ValidationException(['leave_type_id' => 'Insufficient '.$type['name'].' balance for '.$year.': '.LeaveService::num($avail).' day(s) available, '.LeaveService::num($days).' needed.']); } } }
      DB::update('leave_requests', $row, 'id=?', [$id]);
      self::event($id, $by, 'edit', $note, $changes);
      Audit::log('hr.leave.edit', 'leave_request', $id, ['user_id' => $uid, 'status' => $lr['status'], 'changes' => $changes, 'note' => $note]);
      $lr2 = self::find($id);
      Notify::user($uid, 'Your leave was edited', 'Edited by '.$actor['name'].': '.self::summary($changes)."\nNote: ".$note, self::url($id), true, 'plane');
      return ['lr' => $lr2, 'changes' => $changes]; }); }

  /** "Dates: 12 Nov 2026 to 13 Nov 2026; Type: PL to Sick" */
  public static function summary(array $changes): string {
    return implode('; ', array_map(static fn ($k, $v) => $k === 'Reason' ? 'reason updated' : $k.': '.$v[0].' to '.$v[1], array_keys($changes), $changes)); }

  /** Approver revokes approved leave, or confirms the employee's cancellation request ($action cancel_approve). Days return to the balance. */
  public static function revoke(int $id, string $note, array $actor, string $action = 'revoke'): array {
    $note = trim($note);
    if ($note === '' && $action === 'revoke') { throw new ValidationException(['note' => 'Add a note explaining why the leave is revoked. The employee sees it.']); }
    return DB::transaction(static function () use ($id, $note, $actor, $action): array {
      $lr = self::find($id, true) ?? throw new ValidationException(['note' => 'Request not found.']);
      if (!self::canManage($lr)) { throw new \Core\HttpException(403, 'You can only change leave of people you approve.'); }
      if ($lr['status'] !== 'approved') { throw new ValidationException(['note' => 'Only approved leave can be revoked. This one is '.$lr['status'].'.']); }
      if ($action === 'cancel_approve' && !$lr['cancel_requested_at']) { throw new ValidationException(['note' => 'There is no cancellation request on this leave.']); }
      $by = (int) $actor['id'];
      self::giveBack($lr, $by, ($action === 'revoke' ? 'Revoked by ' : 'Cancelled, approved by ').$actor['name']);
      DB::update('leave_requests', ['status' => 'cancelled', 'cancel_requested_at' => null, 'updated_at' => date('Y-m-d H:i:s')], 'id=?', [$id]);
      self::event($id, $by, $action, $note ?: null, ['Status' => ['Approved', 'Cancelled'], 'Days returned' => ['', LeaveService::num($lr['days']).' '.$lr['type_name']]]);
      Audit::log('hr.leave.'.($action === 'revoke' ? 'revoke' : 'cancel_approve'), 'leave_request', $id, ['user_id' => (int) $lr['user_id'], 'days' => (float) $lr['days'], 'note' => $note]);
      $rng = self::range($lr['start_date'], $lr['end_date'], (bool) $lr['half_day']);
      Notify::user((int) $lr['user_id'], $action === 'revoke' ? 'Your approved leave was revoked' : 'Your leave cancellation was approved', $lr['type_name'].' · '.$rng.' · '.LeaveService::num($lr['days']).' day(s) returned to your balance by '.$actor['name'].($note ? "\nNote: ".$note : ''), self::url($id), true, 'plane');
      return $lr; }); }

  /** Employee: cancel own pending request; or own approved leave of a type that needs no approval, before it starts. */
  public static function cancelOwn(int $id, array $actor): string {
    return DB::transaction(static function () use ($id, $actor): string {
      $lr = self::find($id, true);
      if (!$lr || (int) $lr['user_id'] !== (int) $actor['id']) { throw new \Core\HttpException(403, 'You can only cancel your own requests.'); }
      if ($lr['status'] === 'pending') {
        DB::update('leave_requests', ['status' => 'cancelled', 'updated_at' => date('Y-m-d H:i:s')], 'id=?', [$id]);
        self::event($id, (int) $actor['id'], 'withdraw', null, ['Status' => ['Pending', 'Cancelled']]);
        foreach (self::approvers($lr, (int) $actor['id']) as $u) { Notify::user($u, $actor['name'].' withdrew a leave request', $lr['type_name'].' · '.self::range($lr['start_date'], $lr['end_date'], (bool) $lr['half_day']).' · '.LeaveService::num($lr['days']).' day(s). Nothing to review.', self::url($id), false, 'plane'); }
        return 'Leave request withdrawn. Your approver has been told.'; }
      if ($lr['status'] === 'approved' && !(int) $lr['needs_approval'] && $lr['start_date'] > date('Y-m-d')) {
        self::giveBack($lr, (int) $actor['id'], 'Cancelled by '.$actor['name']);
        DB::update('leave_requests', ['status' => 'cancelled', 'updated_at' => date('Y-m-d H:i:s')], 'id=?', [$id]);
        self::event($id, (int) $actor['id'], 'cancel', null, ['Status' => ['Approved', 'Cancelled'], 'Days returned' => ['', LeaveService::num($lr['days']).' '.$lr['type_name']]]);
        return 'Leave cancelled. '.LeaveService::num($lr['days']).' day(s) are back in your balance.'; }
      throw new ValidationException(['note' => $lr['status'] === 'approved' ? 'Approved leave needs your approver to confirm the cancellation. Use "Request cancellation".' : 'Only pending requests can be cancelled.']); }); }

  /** Employee asks to cancel approved leave that has not started yet. Notifies the approvers. */
  public static function requestCancel(int $id, string $reason, array $actor): array {
    $reason = trim($reason);
    if ($reason === '') { throw new ValidationException(['cancel_reason' => 'Tell your approver why you want to cancel.']); }
    $lr = DB::transaction(static function () use ($id, $reason, $actor): array {
      $lr = self::find($id, true);
      if (!$lr || (int) $lr['user_id'] !== (int) $actor['id']) { throw new \Core\HttpException(403, 'You can only cancel your own leave.'); }
      if ($lr['status'] !== 'approved') { throw new ValidationException(['cancel_reason' => 'Only approved leave needs a cancellation request.']); }
      if ($lr['start_date'] <= date('Y-m-d')) { throw new ValidationException(['cancel_reason' => 'Leave that has already started cannot be cancelled here. Ask HR to correct it.']); }
      if ($lr['cancel_requested_at']) { throw new ValidationException(['cancel_reason' => 'You already asked to cancel this leave.']); }
      DB::update('leave_requests', ['cancel_requested_at' => date('Y-m-d H:i:s'), 'cancel_reason' => mb_substr($reason, 0, 500)], 'id=?', [$id]);
      self::event($id, (int) $actor['id'], 'cancel_request', $reason);
      return $lr; });
    Audit::log('hr.leave.cancel_request', 'leave_request', $id, ['reason' => $reason]);
    foreach (self::approvers($lr, (int) $actor['id']) as $u) { Notify::user($u, 'Leave cancellation request from '.$actor['name'], $lr['type_name'].' · '.self::range($lr['start_date'], $lr['end_date'], (bool) $lr['half_day'])."\nReason: ".$reason, self::url($id), true, 'plane'); }
    return $lr; }

  /** Approver declines the cancellation request: the leave stays approved. */
  public static function declineCancel(int $id, string $note, array $actor): array {
    return DB::transaction(static function () use ($id, $note, $actor): array {
      $lr = self::find($id, true) ?? throw new ValidationException(['note' => 'Request not found.']);
      if (!self::canManage($lr)) { throw new \Core\HttpException(403, 'You can only change leave of people you approve.'); }
      if ($lr['status'] !== 'approved' || !$lr['cancel_requested_at']) { throw new ValidationException(['note' => 'There is no open cancellation request on this leave.']); }
      DB::update('leave_requests', ['cancel_requested_at' => null], 'id=?', [$id]);
      self::event($id, (int) $actor['id'], 'cancel_decline', trim($note) ?: null);
      Audit::log('hr.leave.cancel_decline', 'leave_request', $id, ['note' => $note]);
      Notify::user((int) $lr['user_id'], 'Your leave cancellation was declined', $lr['type_name'].' · '.self::range($lr['start_date'], $lr['end_date'], (bool) $lr['half_day']).' stays approved. Declined by '.$actor['name'].(trim($note) ? "\nNote: ".trim($note) : ''), self::url($id), true, 'plane');
      return $lr; }); }

  /** Approver books leave for an employee they approve; it is approved right away and charged to the balance. Returns the request id. */
  public static function book(int $userId, array $in, array $actor): int {
    $emp = DB::first("SELECT id, name, manager_id FROM users WHERE id=? AND status='active'", [$userId]) ?? throw new ValidationException(['user_id' => 'Choose an active employee.']);
    if (!self::canManage(['user_id' => $userId, 'manager_id' => $emp['manager_id']])) { throw new ValidationException(['user_id' => (int) $actor['id'] === $userId ? 'Request your own leave from My leave.' : 'You can only apply leave for people you approve.']); }
    $type = DB::first('SELECT * FROM leave_types WHERE id=? AND active=1', [(int) $in['leave_type_id']]) ?? throw new ValidationException(['leave_type_id' => 'This leave type is not available.']);
    $from = (string) $in['start_date']; $to = (string) ($in['end_date'] ?: $from); $half = (bool) $in['half_day'];
    $note = trim((string) ($in['note'] ?? ''));
    $id = DB::transaction(static function () use ($userId, $type, $from, $to, $half, $in, $actor, $note): int {
      DB::value('SELECT id FROM users WHERE id=? FOR UPDATE', [$userId]);
      $days = self::check($userId, $type, $from, $to, $half, true);
      self::clash($userId, $from, $to, null);
      $year = (int) substr($from, 0, 4);
      DB::query('INSERT IGNORE INTO leave_balances (user_id, leave_type_id, year, allocated, used) VALUES (?,?,?,0,0)', [$userId, $type['id'], $year]);
      self::chargeable($userId, $type, $year, $days);
      $now = date('Y-m-d H:i:s');
      $row = ['user_id' => $userId, 'leave_type_id' => (int) $type['id'], 'start_date' => $from, 'end_date' => $to, 'half_day' => $half ? 1 : 0, 'days' => $days, 'reason' => trim((string) ($in['reason'] ?? '')) ?: null, 'status' => 'approved',
        'reviewed_by' => (int) $actor['id'], 'reviewed_at' => $now, 'review_note' => $note !== '' ? mb_substr($note, 0, 500) : 'Applied on behalf by '.$actor['name'], 'created_by' => (int) $actor['id'], 'created_at' => $now];
      $id = DB::insert('leave_requests', $row);
      LeaveService::recordUsage(['id' => $id] + $row, (int) $actor['id']);
      self::event($id, (int) $actor['id'], 'on_behalf', $note ?: null, ['Booked' => ['', $type['name'].' · '.self::range($from, $to, $half).' · '.LeaveService::num($days).' day(s)']]);
      return $id; });
    $lr = self::find($id);
    Audit::log('hr.leave.on_behalf', 'leave_request', $id, ['user_id' => $userId, 'type' => $type['code'], 'days' => (float) $lr['days'], 'from' => $from, 'to' => $to]);
    Notify::user($userId, 'Leave booked for you', $type['name'].' · '.self::range($from, $to, $half).' · '.LeaveService::num($lr['days']).' day(s), booked by '.$actor['name'].($note !== '' ? "\nNote: ".$note : ''), self::url($id), true, 'plane');
    return $id; }

  /** JSON the leave dialogs (leave.js) fill themselves from: ids, dates, reasons and a one-line summary. $balance: optional "12 left, 9 after approval". */
  public static function dialogData(array $r, string $balance = ''): string {
    return (string) json_encode(['id' => (int) $r['id'], 'user_id' => (int) $r['user_id'], 'name' => (string) ($r['name'] ?? ''), 'leave_type_id' => (int) $r['leave_type_id'], 'start_date' => $r['start_date'], 'end_date' => $r['end_date'], 'half_day' => (int) $r['half_day'], 'reason' => (string) $r['reason'], 'status' => $r['status'],
      'summary' => $r['type_name'].' · '.self::range($r['start_date'], $r['end_date'], (bool) $r['half_day']).' · '.LeaveService::num($r['days']).' day(s)', 'cancel_reason' => (string) ($r['cancel_reason'] ?? ''), 'balance' => $balance]); }

  /** Detail page of a request (links in notifications, lists and widgets). */
  public static function url(int $id): string { return '/hr/leave/requests/'.$id; }

  /** Who decides a request: the requester's active manager plus everyone who manages leave, never $except (the actor). */
  public static function approvers(array $lr, int $except): array {
    $to = array_map('intval', array_column(Gate::usersWith('hr.leave.manage'), 'id'));
    if (!empty($lr['manager_id']) && DB::value("SELECT 1 FROM users WHERE id=? AND status='active'", [$lr['manager_id']])) { $to[] = (int) $lr['manager_id']; }
    return array_values(array_filter(array_unique($to), static fn ($u) => $u !== $except && $u !== (int) $lr['user_id'])); }

  /**
   * Approver decision on a pending request: approve (optional note; paid types need allocated - used >= days, charged with a usage ledger entry)
   * or reject (reason required). Recorded as an approve / reject event, audited, and the employee is notified with the note. Returns the request.
   */
  public static function decide(int $id, bool $approve, string $note, array $actor): array {
    $note = mb_substr(trim($note), 0, 500);
    if (!$approve && $note === '') { throw new ValidationException(['review_note' => 'Give a reason for rejecting. The employee sees it.']); }
    $by = (int) $actor['id'];
    $lr = DB::transaction(static function () use ($id, $approve, $note, $by): array {
      $lr = self::find($id, true) ?? throw new ValidationException(['review_note' => 'Request not found.']);
      if (!self::canManage($lr)) { Audit::log('access.denied', 'leave_request', $id, ['action' => 'review']); throw new \Core\HttpException(403, (int) $lr['user_id'] === $by ? 'You cannot approve your own leave.' : 'You can only review leave of your direct reports.'); }
      if ($lr['status'] !== 'pending') { throw new ValidationException(['review_note' => 'This request was already '.$lr['status'].'.']); }
      if ($approve) {
        $year = (int) substr($lr['start_date'], 0, 4);
        if ((int) $lr['paid'] === 1) {
          $b = DB::first('SELECT allocated, used FROM leave_balances WHERE user_id=? AND leave_type_id=? AND year=? FOR UPDATE', [$lr['user_id'], $lr['leave_type_id'], $year]);
          if (!$b || (float) $b['allocated'] - (float) $b['used'] < (float) $lr['days']) { throw new ValidationException(['review_note' => 'Insufficient '.$lr['type_name'].' balance to approve '.$lr['name'].'\'s leave. Adjust the balance or ask them to change the leave type.']); } }
        LeaveService::recordUsage($lr, $by); }
      DB::update('leave_requests', ['status' => $approve ? 'approved' : 'rejected', 'reviewed_by' => $by, 'reviewed_at' => date('Y-m-d H:i:s'), 'review_note' => $note !== '' ? $note : null], 'id=?', [$id]);
      self::event($id, $by, $approve ? 'approve' : 'reject', $note ?: null);
      Audit::log('hr.leave.'.($approve ? 'approve' : 'reject'), 'leave_request', $id, ['user_id' => (int) $lr['user_id'], 'days' => (float) $lr['days'], 'note' => $note]);
      return $lr; });
    $word = $approve ? 'approved' : 'rejected';
    Notify::user((int) $lr['user_id'], 'Your leave was '.$word, $lr['type_name'].' · '.self::range($lr['start_date'], $lr['end_date'], (bool) $lr['half_day']).' · '.LeaveService::num($lr['days']).' day(s) '.$word.' by '.$actor['name'].'.'.($note !== '' ? "\n".($approve ? 'Note: ' : 'Reason: ').$note : ''), self::url($id), true, 'plane');
    return $lr; }

  /**
   * Full timeline of a request, oldest first: [{action, label, actor, at, note, changes}]. Starts with "Requested" (or booked on behalf / without
   * approval); a decision made before decisions were recorded as events is rebuilt from reviewed_by / reviewed_at / review_note.
   */
  public static function timeline(array $lr, array $events): array {
    $acts = array_column($events, 'action');
    $behalf = in_array('on_behalf', $acts, true);
    $auto = !$behalf && $lr['status'] !== 'pending' && $lr['reviewed_at'] && !$lr['reviewed_by'] && ($lr['created_at'] ?? '') !== '' && substr((string) $lr['reviewed_at'], 0, 16) === substr((string) $lr['created_at'], 0, 16);
    $out = [];
    if (!$behalf) { $out[] = ['action' => 'apply', 'label' => $auto ? 'Booked, no approval needed' : 'Requested', 'actor' => $lr['name'] ?? null, 'at' => $lr['created_at'], 'note' => $lr['reason'] ?: null, 'changes' => []]; }
    $wasApproved = in_array($lr['status'], ['approved', 'rejected'], true) || ($lr['status'] === 'cancelled' && array_intersect(['revoke', 'cancel_approve', 'cancel_request'], $acts));
    if (!$auto && !$behalf && $lr['reviewed_at'] && $wasApproved && !array_intersect(['approve', 'reject'], $acts)) {
      $rej = $lr['status'] === 'rejected';
      $out[] = ['action' => $rej ? 'reject' : 'approve', 'label' => $rej ? 'Rejected' : 'Approved', 'actor' => $lr['reviewer'] ?? null, 'at' => $lr['reviewed_at'], 'note' => $lr['review_note'] ?: null, 'changes' => []]; }
    foreach ($events as $e) { $out[] = ['action' => $e['action'], 'label' => $e['label'], 'actor' => $e['actor'] ?? null, 'at' => $e['created_at'], 'note' => $e['note'], 'changes' => $e['changes']]; }
    if ($lr['status'] === 'cancelled' && !array_intersect(['cancel', 'withdraw', 'revoke', 'cancel_approve'], $acts)) { $out[] = ['action' => 'cancel', 'label' => 'Cancelled', 'actor' => null, 'at' => $lr['updated_at'] ?: null, 'note' => null, 'changes' => []]; }
    usort($out, static fn ($a, $b) => strcmp((string) $a['at'], (string) $b['at']));
    return $out; }

  /**
   * Other people's pending / approved leave overlapping each request, among the requester's team (same manager, same department, or their manager).
   * $rows need id, user_id, start_date, end_date, manager_id, department. Returns [request_id => [{name, status, start_date, end_date, half_day, type_name}]].
   */
  public static function overlaps(array $rows): array {
    if (!$rows) { return []; }
    $from = min(array_column($rows, 'start_date')); $to = max(array_column($rows, 'end_date'));
    $all = DB::all("SELECT lr.user_id, lr.start_date, lr.end_date, lr.half_day, lr.status, u.name, u.manager_id, u.department, t.name AS type_name FROM leave_requests lr JOIN users u ON u.id=lr.user_id JOIN leave_types t ON t.id=lr.leave_type_id WHERE u.status='active' AND lr.status IN ('pending','approved') AND lr.start_date<=? AND lr.end_date>=? ORDER BY lr.start_date, u.name LIMIT 2000", [$to, $from]);
    $out = [];
    foreach ($rows as $r) {
      $list = [];
      foreach ($all as $o) {
        if ((int) $o['user_id'] === (int) $r['user_id'] || $o['start_date'] > $r['end_date'] || $o['end_date'] < $r['start_date']) { continue; }
        $team = ($r['manager_id'] && (int) $o['manager_id'] === (int) $r['manager_id']) || ((string) ($r['department'] ?? '') !== '' && (string) $o['department'] === (string) $r['department']) || ($r['manager_id'] && (int) $o['user_id'] === (int) $r['manager_id']);
        if ($team) { $list[] = $o; } }
      $out[(int) $r['id']] = $list; }
    return $out; }

  /** Days of reminder (setting hr.leave.reminder_days, 0 = off, default 2). */
  public static function reminderDays(): int { return max(0, min(30, (int) \Core\Settings::get('hr.leave.reminder_days', 2))); }

  /**
   * Cron (daily): one digest per approver listing requests pending for at least hr.leave.reminder_days days whose leave has not ended.
   * Managers are reminded about their direct reports; leave managers (HR) about everyone. Returns a status line.
   */
  public static function remindPending(?string $now = null): string {
    $n = self::reminderDays();
    if ($n <= 0) { return 'Reminders are off'; }
    $now ??= date('Y-m-d H:i:s');
    $rows = DB::all("SELECT lr.id, lr.user_id, lr.start_date, lr.end_date, lr.half_day, lr.days, lr.created_at, u.name, u.manager_id, t.name AS type_name FROM leave_requests lr JOIN users u ON u.id=lr.user_id JOIN leave_types t ON t.id=lr.leave_type_id WHERE lr.status='pending' AND lr.created_at<=? AND lr.end_date>=? ORDER BY lr.start_date", [date('Y-m-d H:i:s', strtotime($now.' -'.$n.' days')), substr($now, 0, 10)]);
    if (!$rows) { return 'No pending requests older than '.$n.' day(s)'; }
    $hr = array_map('intval', array_column(Gate::usersWith('hr.leave.manage'), 'id'));
    $active = array_fill_keys(array_map('intval', DB::column("SELECT id FROM users WHERE status='active'")), true);
    $per = [];
    foreach ($rows as $r) {
      $to = $hr;
      if ($r['manager_id'] && isset($active[(int) $r['manager_id']])) { $to[] = (int) $r['manager_id']; }
      foreach (array_unique($to) as $u) { if ($u !== (int) $r['user_id']) { $per[$u][] = $r; } } }
    foreach ($per as $u => $list) {
      $lines = array_map(static fn ($r) => '- '.$r['name'].': '.$r['type_name'].', '.self::range($r['start_date'], $r['end_date'], (bool) $r['half_day']).' ('.LeaveService::num($r['days']).' day(s)), waiting since '.date('d M', strtotime($r['created_at'])), array_slice($list, 0, 10));
      $c = count($list);
      Notify::user($u, $c === 1 ? 'A leave request is waiting for your review' : $c.' leave requests are waiting for your review', implode("\n", $lines).($c > 10 ? "\n+".($c - 10).' more' : ''), $c === 1 ? self::url((int) $list[0]['id']) : '/hr/leave/approvals', true, 'plane'); }
    return 'Reminded '.count($per).' approver(s) about '.count($rows).' request(s)'; }

  /**
   * Holidays changed between $from and $to: recount the days of pending and approved leave there (fixed holidays are not leave days).
   * Approved leave gives its old days back and is charged the new count (ledger reversal + usage); leave left with no working day is cancelled.
   * Each change is recorded in the request history with $why and the employee is notified. Returns the number of requests changed.
   */
  public static function recount(string $from, string $to, string $why, ?array $actor = null): int {
    LeaveService::flush();
    $rows = DB::all("SELECT lr.*, u.name, u.manager_id, t.name AS type_name, t.code, t.paid, t.needs_approval FROM leave_requests lr JOIN users u ON u.id=lr.user_id JOIN leave_types t ON t.id=lr.leave_type_id WHERE lr.status IN ('pending','approved') AND lr.start_date<=? AND lr.end_date>=?", [$to, $from]);
    $by = $actor ? (int) $actor['id'] : null; $n = 0;
    foreach ($rows as $lr) {
      $days = LeaveService::requestDaysFor((int) $lr['user_id'], $lr['start_date'], $lr['end_date'], (bool) $lr['half_day']);
      if (abs($days - (float) $lr['days']) < 0.001) { continue; }
      $id = (int) $lr['id'];
      DB::transaction(static function () use ($lr, $days, $by, $why, $id): void {
        if ($lr['status'] === 'approved') { self::giveBack($lr, $by, 'Recounted: '.$why); }
        if ($days <= 0) {
          DB::update('leave_requests', ['status' => 'cancelled', 'cancel_requested_at' => null, 'updated_at' => date('Y-m-d H:i:s')], 'id=?', [$id]);
          self::event($id, $by, 'cancel', $why.' No working days are left in this leave.', ['Status' => [ucfirst($lr['status']), 'Cancelled'], 'Days' => [LeaveService::num($lr['days']), '0']]); }
        else {
          DB::update('leave_requests', ['days' => $days, 'updated_at' => date('Y-m-d H:i:s')], 'id=?', [$id]);
          if ($lr['status'] === 'approved') { LeaveService::recordUsage(['days' => $days] + $lr, $by); }
          self::event($id, $by, 'edit', $why, ['Days' => [LeaveService::num($lr['days']), LeaveService::num($days)]]); } });
      Audit::log('hr.leave.recount', 'leave_request', $id, ['user_id' => (int) $lr['user_id'], 'from' => (float) $lr['days'], 'to' => $days, 'why' => $why]);
      Notify::user((int) $lr['user_id'], $days <= 0 ? 'Your leave was cancelled by a holiday change' : 'Your leave days were recounted', $lr['type_name'].' · '.self::range($lr['start_date'], $lr['end_date'], (bool) $lr['half_day']).': '.LeaveService::num($lr['days']).' to '.LeaveService::num($days).' day(s). '.$why, self::url($id), false, 'plane');
      $n++; }
    return $n; }

  /** History per request: [request_id => [{action, label, note, changes, actor, created_at}]] oldest first. */
  public static function history(array $ids): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) { return []; }
    $out = [];
    foreach (DB::all('SELECT e.*, u.name AS actor FROM leave_request_events e LEFT JOIN users u ON u.id=e.actor_id WHERE e.request_id IN '.DB::in($ids).' ORDER BY e.id', $ids) as $r) {
      $r['label'] = self::ACTIONS[$r['action']] ?? ucfirst($r['action']); $r['changes'] = json_decode((string) $r['changes'], true) ?: [];
      $out[(int) $r['request_id']][] = $r; }
    return $out; } }
