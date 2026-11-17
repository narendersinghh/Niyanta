<?php
namespace Modules\Attendance\Services;
use Core\{Audit, DB, Notify};

/**
 * Review of single time entries and whole days. Entries are approved as they are logged; a reviewer (Timesheets::canReview: approver
 * permission, in the team, never self) may reject an entry or a day with a reason, or approve rejected time again. Rejected entries
 * stay out of payroll (TimeService::approvedHours*). The owner may fix a rejected entry even in a closed week; saving it approves it
 * again and tells the reviewer (fixed). Running entries and finalized payroll months cannot be reviewed.
 */
final class EntryReview {
  /** The entry with its reviewer's name, or null. */
  public static function find(int $id): ?array {
    return DB::first('SELECT te.*, r.name AS reviewer_name FROM time_entries te LEFT JOIN users r ON r.id=te.reviewed_by WHERE te.id=? LIMIT 1', [$id]); }

  /** May $u review this entry now? Returns null when allowed, else the reason. */
  public static function refusal(array $u, array $e): ?string {
    if (!Timesheets::canReview($u, (int) $e['user_id'])) { return 'You cannot review this time entry.'; }
    if ($e['is_running']) { return 'This entry is still being tracked. It can be reviewed once it stops.'; }
    if (Timesheets::payrollLocked(substr($e['started_at'], 0, 10))) { return 'Payroll for that month is finalized, so its time can no longer change.'; }
    return null; }

  /** Can $u review the entries of $uid on $date (in scope, not self, payroll open)? */
  public static function canReviewDay(array $u, int $uid, string $date): bool { return Timesheets::canReview($u, $uid) && !Timesheets::payrollLocked($date); }

  /** Reject one entry with a reason; the owner is notified. */
  public static function reject(array $by, int $id, string $note): array {
    $note = self::note($note);
    $e = self::guard($by, $id);
    if ($e['status'] === 'rejected' && $e['review_note'] === $note) { throw new \DomainException('This entry is already rejected.'); }
    self::set([$id], 'rejected', $by, $note);
    Audit::log('time.entry_reject', 'time_entry', $id, ['user_id' => (int) $e['user_id'], 'date' => substr($e['started_at'], 0, 10), 'seconds' => (int) $e['duration_seconds'], 'note' => $note], (int) $by['id']);
    self::tell($e, $by, 'rejected', 1, (int) $e['duration_seconds'], $note);
    return $e; }

  /** Approve a rejected entry again; the owner is notified. */
  public static function approve(array $by, int $id): array {
    $e = self::guard($by, $id);
    if ($e['status'] !== 'rejected') { throw new \DomainException('This entry is already approved.'); }
    self::set([$id], 'approved', $by, null);
    Audit::log('time.entry_approve', 'time_entry', $id, ['user_id' => (int) $e['user_id'], 'date' => substr($e['started_at'], 0, 10), 'was' => $e['review_note']], (int) $by['id']);
    self::tell($e, $by, 'approved', 1, (int) $e['duration_seconds']);
    return $e; }

  /**
   * Approve or reject every stopped entry of $uid on $date ($to 'approved' | 'rejected', a reason is required to reject).
   * One notification and one audit entry for the day. Returns the number of entries changed.
   */
  public static function day(array $by, int $uid, string $date, string $to, ?string $note = null): int {
    if (!in_array($to, ['approved', 'rejected'], true) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { throw new \DomainException('Choose approve or reject for a valid day.'); }
    if (!Timesheets::canReview($by, $uid)) { Audit::log('access.denied', 'user', $uid, ['area' => 'time.review_day', 'date' => $date], (int) $by['id']); throw new \DomainException('You cannot review this person\'s time.'); }
    if (Timesheets::payrollLocked($date)) { throw new \DomainException('Payroll for that month is finalized, so its time can no longer change.'); }
    $note = $to === 'rejected' ? self::note((string) $note) : null;
    $rows = DB::all('SELECT id, user_id, started_at, duration_seconds, status FROM time_entries WHERE user_id=? AND started_at>=? AND started_at<? AND is_running=0', [$uid, $date.' 00:00:00', date('Y-m-d', strtotime($date.' +1 day')).' 00:00:00']);
    if (!$rows) { throw new \DomainException('There is no stopped time on this day to review.'); }
    $ids = array_map(static fn ($r) => (int) $r['id'], $to === 'approved' ? array_filter($rows, static fn ($r) => $r['status'] === 'rejected') : $rows);
    if (!$ids) { throw new \DomainException('Every entry of this day is already approved.'); }
    self::set($ids, $to, $by, $note);
    $secs = (int) array_sum(array_map(static fn ($r) => in_array((int) $r['id'], $ids, true) ? (int) $r['duration_seconds'] : 0, $rows));
    Audit::log('time.day_'.($to === 'approved' ? 'approve' : 'reject'), 'user', $uid, ['date' => $date, 'entries' => $ids, 'seconds' => $secs, 'note' => $note], (int) $by['id']);
    self::tell($rows[0], $by, $to, count($ids), $secs, $note, true);
    return count($ids); }

  /** The owner (or HR) saved a rejected entry: it is approved again and the reviewer who rejected it is told. Call after the update. */
  public static function fixed(array $editor, array $before): void {
    if ($before['status'] !== 'rejected') { return; }
    DB::update('time_entries', ['review_note' => null, 'reviewed_by' => null, 'reviewed_at' => null], 'id=?', [(int) $before['id']]);
    Audit::log('time.entry_fix', 'time_entry', (int) $before['id'], ['rejected_by' => $before['reviewed_by'] ? (int) $before['reviewed_by'] : null, 'note' => $before['review_note']], (int) $editor['id']);
    $rid = (int) $before['reviewed_by'];
    if ($rid && $rid !== (int) $editor['id']) {
      $own = (int) $editor['id'] === (int) $before['user_id'];
      $who = $own ? $editor['name'] : (string) DB::value('SELECT name FROM users WHERE id=?', [$before['user_id']]);
      $d = substr($before['started_at'], 0, 10);
      Notify::user($rid, $own ? $who.' fixed a rejected time entry' : $editor['name'].' fixed a rejected time entry of '.$who, 'The entry on '.fdate($d).' ('.ftime($before['started_at']).') was corrected'.($own ? '' : ' by '.$editor['name']).' and is approved again. Reject it again if something is still wrong.', '/time/daily?user='.(int) $before['user_id'].'&date='.$d, false, 'check-circle'); } }

  /** Rejected entries waiting for a fix: the person's own ($team false) or their team's, without their own (reviewers' Review badge). */
  public static function rejectedCount(array $u, bool $team = false): int {
    if (!$team) { return (int) DB::value("SELECT COUNT(*) FROM time_entries WHERE user_id=? AND status='rejected'", [$u['id']]); }
    [$w, $p] = Team::where($u, 'user_id');
    return (int) DB::value("SELECT COUNT(*) FROM time_entries WHERE status='rejected' AND user_id<>? AND $w", [$u['id'], ...$p]); }

  /** Dialog data for an entry (partials/review_dialog, attendance.js): labels and times are formatted here, texts are set with textContent. */
  public static function view(array $e, ?array $edit = null, bool $review = false, string $who = ''): array {
    $end = $e['is_running'] || !$e['ended_at'] ? null : $e['ended_at'];
    $pj = feature_on('projects');
    return ['id' => (int) $e['id'], 'who' => $who, 'title' => TimeService::label($e), 'task' => $pj ? (string) ($e['task_title'] ?? '') : '', 'desc' => (string) ($e['description'] ?? ''),
      'date' => fdate(substr($e['started_at'], 0, 10), 'l, d M Y'), 'range' => ftime($e['started_at']).' - '.($end ? ftime($end) : 'now'), 'dur' => hours(TimeService::elapsed($e)),
      'status' => $e['is_running'] ? 'running' : ($e['status'] === 'rejected' ? 'rejected' : 'approved'), 'note' => (string) ($e['review_note'] ?? ''), 'by' => (string) ($e['reviewer_name'] ?? ''),
      'at' => $e['reviewed_at'] ? fdatetime($e['reviewed_at']) : '', 'review' => $review && !$e['is_running'], 'edit' => $edit]; }

  private static function note(string $note): string {
    $note = trim($note);
    if ($note === '') { throw new \DomainException('Add a reason so the employee knows what to fix.'); }
    return mb_substr($note, 0, 500); }

  private static function guard(array $by, int $id): array {
    $e = self::find($id) ?? throw new \DomainException('Time entry not found.');
    if ($why = self::refusal($by, $e)) {
      if (!Timesheets::canReview($by, (int) $e['user_id'])) { Audit::log('access.denied', 'time_entry', $id, ['area' => 'time.review'], (int) $by['id']); }
      throw new \DomainException($why); }
    return $e; }

  private static function set(array $ids, string $to, array $by, ?string $note): void {
    DB::query('UPDATE time_entries SET status=?, review_note=?, reviewed_by=?, reviewed_at=? WHERE is_running=0 AND id IN '.DB::in($ids), [$to, $note, (int) $by['id'], date('Y-m-d H:i:s'), ...$ids]); }

  /** One notification to the owner per action. */
  private static function tell(array $e, array $by, string $to, int $n, int $secs, ?string $note = null, bool $day = false): void {
    $d = substr($e['started_at'], 0, 10);
    $what = $day ? ($n === 1 ? '1 entry' : $n.' entries').' on '.fdate($d).' ('.hours($secs).')' : 'Your time entry on '.fdate($d).' at '.ftime($e['started_at']).' ('.hours($secs).')';
    if ($to === 'rejected') { Notify::user((int) $e['user_id'], $day ? 'Day rejected' : 'Time entry rejected', ($day ? 'Your time, ' : '').$what.' was rejected by '.$by['name'].': '.$note.' Fix '.($n === 1 ? 'it' : 'them').' and save; '.($n === 1 ? 'it is' : 'they are').' approved again automatically.', '/time/daily?date='.$d, true, 'alert'); }
    else { Notify::user((int) $e['user_id'], $day ? 'Day approved' : 'Time entry approved', ($day ? 'Your time, ' : '').$what.' was approved by '.$by['name'].'.', '/time/daily?date='.$d, false, 'check-circle'); } } }
