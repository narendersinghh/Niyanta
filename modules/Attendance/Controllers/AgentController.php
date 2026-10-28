<?php
namespace Modules\Attendance\Controllers;
use Core\{Controller, DB, Gate, Modules, Response};
use Modules\Attendance\Services\{Monitoring, TimeService as T};

/** Desktop agent JSON API. Authenticated by a personal bearer token (no session, no CSRF); see modules/Attendance/AGENT_API.md. */
final class AgentController extends Controller {
  private ?array $agent = null;

  private function guard(): ?Response {
    if (!Modules::enabled('attendance')) { return $this->json(['error' => 'Attendance module is disabled.'], 403); }
    $h = (string) ($this->request->header('Authorization') ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    $tok = preg_match('/^Bearer\s+(\S+)$/i', trim($h), $m) ? $m[1] : (string) $this->request->header('X-Agent-Token');
    $u = $tok !== '' ? Monitoring::tokenUser($tok) : null;
    if (!$u) { return $this->json(['error' => 'Invalid or revoked token.'], 401); }
    if (!Gate::allows('attendance.track', $u)) { return $this->json(['error' => 'Your role is not allowed to track time.'], 403); }
    $this->agent = $u;
    return null; }

  private function run(callable $fn): Response {
    if ($r = $this->guard()) { return $r; }
    try { return $this->json(['ok' => true] + $fn((int) $this->agent['id'])); }
    catch (\DomainException $e) { return $this->json(['ok' => false, 'error' => $e->getMessage()], 422); }
    catch (\Core\ValidationException $e) { return $this->json(['ok' => false, 'error' => (string) (array_values($e->errors)[0] ?? 'Invalid input.')], 422); } }

  private function input(): array { $j = str_contains((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'json') ? $this->request->json() : []; return $j + $this->request->all(); }

  private function entry(int $uid): ?array {
    $e = T::running($uid);
    return $e ? ['id' => (int) $e['id'], 'started_at' => date('c', strtotime($e['started_at'])), 'elapsed' => T::elapsed($e), 'idle_seconds' => (int) $e['idle_seconds'], 'project' => $e['project_name'], 'task' => $e['task_title'], 'description' => $e['description'], 'source' => $e['source']] : null; }

  public function status(): Response {
    return $this->run(fn (int $uid): array => ['user' => ['id' => $uid, 'name' => $this->agent['name']], 'running' => $this->entry($uid), 'server_time' => date('c'),
      'settings' => ['heartbeat_seconds' => 60, 'idle_minutes' => (int) \Core\Settings::get('attendance.idle_minutes', 5), 'screenshots_enabled' => Monitoring::enabled(), 'screenshot_interval_minutes' => Monitoring::interval(), 'retention_days' => Monitoring::retention()],
      'consent' => ($c = Monitoring::consent($uid)) ? ['policy_version' => $c['policy_version'], 'consented_at' => date('c', strtotime($c['consented_at']))] : null,
      'projects' => array_map(static fn ($id, $n) => ['id' => $id, 'name' => $n], array_keys($p = T::projects($this->agent)), $p)]); }

  public function start(): Response {
    return $this->run(function (int $uid): array {
      $in = $this->input();
      T::start($this->agent, ['project_id' => (int) ($in['project_id'] ?? 0), 'task_id' => (int) ($in['task_id'] ?? 0), 'description' => mb_substr((string) ($in['description'] ?? ''), 0, 500), 'billable' => !isset($in['billable']) || filter_var($in['billable'], FILTER_VALIDATE_BOOLEAN)], 'agent');
      return ['running' => $this->entry($uid)]; }); }

  public function stop(): Response {
    return $this->run(static function (int $uid): array {
      $r = T::stop($uid, null, 'agent');
      if (!$r) { throw new \DomainException('No timer is running.'); }
      return ['stopped' => $r]; }); }

  public function heartbeat(): Response {
    return $this->run(function (int $uid): array {
      $in = $this->input();
      $r = T::heartbeat($uid, min(3600, max(0, (int) ($in['active_seconds'] ?? 0))), min(3600, max(0, (int) ($in['idle_seconds'] ?? 0))));
      return ['running' => (bool) $r, 'entry' => $r]; }); }

  public function screenshot(): Response {
    return $this->run(function (int $uid): array {
      $f = $this->request->file('screenshot') ?? throw new \DomainException('Send the image as multipart field "screenshot".');
      $act = $this->request->input('activity_pct');
      return ['id' => Monitoring::store($uid, $f, 'agent', is_numeric($act) ? (int) $act : null)]; }); } }
