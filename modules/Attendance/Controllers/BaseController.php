<?php
namespace Modules\Attendance\Controllers;
use Core\{Controller, Response};

/** Shared helpers: run an action and answer with JSON (fetch) or flash + redirect (forms). */
abstract class BaseController extends Controller {
  /** JSON callers get a 422 {error} directly (works around core Router's JSON validation path). */
  protected function validate(array $rules, array $labels = [], ?array $data = null): array {
    try { return \Core\Validator::make($data ?? $this->request->all(), $rules, $labels); }
    catch (\Core\ValidationException $e) {
      if (!$this->request->wantsJson()) { throw $e; }
      throw new \Core\HttpException(422, (string) (array_values($e->errors)[0] ?? 'Invalid input.')); } }

  protected function act(callable $fn, ?string $redirect = null): Response {
    try { $res = $fn(); }
    catch (\DomainException $e) {
      if ($this->request->wantsJson()) { return $this->json(['error' => $e->getMessage()], 422); }
      $this->flash($e->getMessage(), 'error');
      return $redirect ? $this->redirect($redirect) : $this->back(); }
    $msg = is_array($res) ? ($res['message'] ?? 'Done.') : (string) $res;
    if ($this->request->wantsJson()) { return $this->json(is_array($res) ? $res + ['ok' => true] : ['ok' => true, 'message' => $msg]); }
    $this->flash($msg);
    return $redirect ? $this->redirect($redirect) : $this->back(); }

  protected function weekParam(): string { return \Modules\Attendance\Services\TimeService::weekStart(preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $this->request->query('week', '')) ? $this->request->query('week') : null); }

  protected function dateParam(string $k, string $d): string { $v = (string) $this->request->query($k, ''); return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) ? $v : $d; }

  /**
   * Time hub member scope: the person shown (?user=, default self) and the people the viewer may pick.
   * $perm is the permission that grants access to other people's data (with Team scoping); denied requests are audited.
   */
  protected function member(string $perm, string $area): array {
    $u = $this->user(); $self = (int) $u['id'];
    $target = $this->request->int('user', $self) ?: $self;
    $can = $this->can($perm);
    if ($target !== $self && !($can && \Modules\Attendance\Services\Team::includes($u, $target))) { \Core\Audit::log('access.denied', 'user', $target, ['area' => $area]); $this->abort(403, 'You cannot view this person\'s time.'); }
    $people = $can ? \Modules\Attendance\Services\Team::people($u, true) : [];
    $name = $target === $self ? $u['name'] : (string) \Core\DB::value('SELECT name FROM users WHERE id=?', [$target]);
    if ($target !== $self && $name === '') { $this->abort(404, 'Person not found.'); }
    return [$target, $name, $people]; }

  /** One person's day: entries (with project/task) and the summary strip (clock in/out, total, active, idle, paused, activity %). */
  protected static function day(int $uid, string $date): array {
    $next = date('Y-m-d', strtotime($date.' +1 day'));
    $rows = \Core\DB::all('SELECT te.*, p.name AS project_name, p.color AS project_color, t.title AS task_title FROM time_entries te LEFT JOIN projects p ON p.id=te.project_id LEFT JOIN tasks t ON t.id=te.task_id WHERE te.user_id=? AND te.started_at>=? AND te.started_at<? ORDER BY te.started_at', [$uid, $date.' 00:00:00', $next.' 00:00:00']);
    $att = \Core\DB::first('SELECT clock_in, clock_out, break_seconds FROM attendance WHERE user_id=? AND work_date=? LIMIT 1', [$uid, $date]);
    $act = \Core\DB::value('SELECT AVG(activity_pct) FROM screenshots WHERE user_id=? AND taken_at>=? AND taken_at<? AND activity_pct IS NOT NULL', [$uid, $date.' 00:00:00', $next.' 00:00:00']);
    $total = 0; $idle = 0; $first = null; $last = null;
    foreach ($rows as &$r) {
      $r['dur'] = \Modules\Attendance\Services\TimeService::elapsed($r);
      $r['idle'] = min($r['dur'], max(0, (int) $r['idle_seconds'] - (int) $r['discarded_seconds']));
      $r['end'] = $r['is_running'] || !$r['ended_at'] ? date('Y-m-d H:i:s') : $r['ended_at'];
      $total += $r['dur']; $idle += $r['idle'];
      $first ??= $r['started_at']; $last = max((string) $last, $r['end']); }
    unset($r);
    $active = $total - $idle;
    return ['entries' => $rows, 'sum' => ['date' => $date, 'clock_in' => $att['clock_in'] ?? $first, 'clock_out' => $att['clock_out'] ?? ($rows && !end($rows)['is_running'] ? $last : null), 'running' => (bool) array_filter($rows, static fn ($r) => $r['is_running']),
      'total' => $total, 'active' => $active, 'idle' => $idle, 'paused' => (int) ($att['break_seconds'] ?? 0), 'activity' => $act !== null ? (int) round((float) $act) : ($total ? (int) round(100 * $active / $total) : 0)]]; } }
