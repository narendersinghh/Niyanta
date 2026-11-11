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
 * Day counts always come from LeaveService::requestDaysFor (the employee's own weekly off days, Saturday rule and fixed holidays).
 */
final class LeaveChanges {
  public const ACTIONS = ['edit' => 'Edited', 'revoke' => 'Revoked', 'cancel' => 'Cancelled', 'cancel_request' => 'Cancellation requested', 'cancel_approve' => 'Cancellation approved', 'cancel_decline' => 'Cancellation declined', 'on_behalf' => 'Applied on behalf'];

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
      Notify::user($uid, 'Your leave was edited', 'Edited by '.$actor['name'].': '.self::summary($changes)."\nNote: ".$note, '/hr/leave', true, 'plane');
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
      Notify::user((int) $lr['user_id'], $action === 'revoke' ? 'Your approved leave was revoked' : 'Your leave cancellation was approved', $lr['type_name'].' · '.$rng.' · '.LeaveService::num($lr['days']).' day(s) returned to your balance by '.$actor['name'].($note ? "\nNote: ".$note : ''), '/hr/leave', true, 'plane');
      return $lr; }); }

  /** Employee: cancel own pending request; or own approved leave of a type that needs no approval, before it starts. */
  public static function cancelOwn(int $id, array $actor): string {
    return DB::transaction(static function () use ($id, $actor): string {
      $lr = self::find($id, true);
      if (!$lr || (int) $lr['user_id'] !== (int) $actor['id']) { throw new \Core\HttpException(403, 'You can only cancel your own requests.'); }
      if ($lr['status'] === 'pending') {
        DB::update('leave_requests', ['status' => 'cancelled', 'updated_at' => date('Y-m-d H:i:s')], 'id=?', [$id]);
        self::event($id, (int) $actor['id'], 'cancel', null, ['Status' => ['Pending', 'Cancelled']]);
        return 'Leave request cancelled.'; }
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
    $to = array_map('intval', array_column(Gate::usersWith('hr.leave.manage'), 'id'));
    if ($lr['manager_id'] && DB::value("SELECT 1 FROM users WHERE id=? AND status='active'", [$lr['manager_id']])) { $to[] = (int) $lr['manager_id']; }
    foreach (array_unique($to) as $u) { if ($u !== (int) $actor['id']) { Notify::user($u, 'Leave cancellation request from '.$actor['name'], $lr['type_name'].' · '.self::range($lr['start_date'], $lr['end_date'], (bool) $lr['half_day'])."\nReason: ".$reason, '/hr/leave/approvals?status=cancel', true, 'plane'); } }
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
      Notify::user((int) $lr['user_id'], 'Your leave cancellation was declined', $lr['type_name'].' · '.self::range($lr['start_date'], $lr['end_date'], (bool) $lr['half_day']).' stays approved. Declined by '.$actor['name'].(trim($note) ? "\nNote: ".trim($note) : ''), '/hr/leave', true, 'plane');
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
    Notify::user($userId, 'Leave booked for you', $type['name'].' · '.self::range($from, $to, $half).' · '.LeaveService::num($lr['days']).' day(s), booked by '.$actor['name'].($note !== '' ? "\nNote: ".$note : ''), '/hr/leave', true, 'plane');
    return $id; }

  /** History per request: [request_id => [{action, label, note, changes, actor, created_at}]] oldest first. */
  public static function history(array $ids): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) { return []; }
    $out = [];
    foreach (DB::all('SELECT e.*, u.name AS actor FROM leave_request_events e LEFT JOIN users u ON u.id=e.actor_id WHERE e.request_id IN '.DB::in($ids).' ORDER BY e.id', $ids) as $r) {
      $r['label'] = self::ACTIONS[$r['action']] ?? ucfirst($r['action']); $r['changes'] = json_decode((string) $r['changes'], true) ?: [];
      $out[(int) $r['request_id']][] = $r; }
    return $out; } }
