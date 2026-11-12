<?php
namespace Modules\Attendance\Controllers;
use Core\{Audit, Auth, Controller, DB, ErrorLog, Gate, Modules, RateLimiter, Response, Settings};
use Modules\Attendance\Services\{DesktopApp, DesktopConnect, Monitoring, Tracker, TimeService as T};

/**
 * Niyanta Tracker (desktop app) JSON API. Personal device token in "Authorization: Bearer nyt_…" or "X-Agent-Token"
 * (no session, no CSRF). Rule violations answer 422 {ok:false, error}; server failures are written to the error log
 * (source "desktop", with the app version) and answer 500. Contract: modules/Attendance/AGENT_API.md.
 */
final class AgentController extends Controller {
  private ?array $agent = null;

  private function appVersion(): ?string { $v = (string) ($this->request->header('X-App-Version') ?? $this->input()['app_version'] ?? ''); return $v !== '' ? mb_substr(preg_replace('/[^0-9A-Za-z.+_ -]/', '', $v), 0, 40) : null; }

  private function token(): string {
    $h = (string) ($this->request->header('Authorization') ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    return preg_match('/^Bearer\s+(\S+)$/i', trim($h), $m) ? $m[1] : trim((string) $this->request->header('X-Agent-Token')); }

  private function guard(): ?Response {
    if (!Modules::enabled('attendance') || !feature_on('attendance')) { return $this->json(['ok' => false, 'error' => 'Time tracking is turned off for this workspace.', 'code' => 'disabled'], 403); }
    $tok = $this->token();
    $u = $tok !== '' ? Monitoring::tokenUser($tok) : null;
    if (!$u) { return $this->json(['ok' => false, 'error' => 'You have been signed out. Please sign in again.', 'code' => 'unauthenticated'], 401); }
    if (!Gate::allows('attendance.track', $u)) { return $this->json(['ok' => false, 'error' => 'Your role is not allowed to track time.', 'code' => 'forbidden'], 403); }
    $v = $this->appVersion();
    if ($v !== null && $v !== $u['token_version']) { DB::update('agent_tokens', ['app_version' => $v, 'last_ip' => $this->request->ip()], 'id=?', [$u['token_id']]); }
    $this->agent = $u;
    return null; }

  /** Run an authenticated action: ok payload, 422 for rule violations, 500 + error log for failures. */
  private function run(callable $fn): Response {
    try {
      if ($r = $this->guard()) { return $r; }
      return $this->json(['ok' => true] + $fn((int) $this->agent['id'])); }
    catch (\DomainException $e) { return $this->json(['ok' => false, 'error' => $e->getMessage()], 422); }
    catch (\Core\ValidationException $e) { return $this->json(['ok' => false, 'error' => (string) (array_values($e->errors)[0] ?? 'Invalid input.')], 422); }
    catch (\Throwable $e) { return $this->fail($e); } }

  private function fail(\Throwable $e): Response {
    $ref = substr(bin2hex(random_bytes(4)), 0, 8);
    ErrorLog::record('desktop', 'exception', 'Tracker API '.$this->request->method().' '.$this->request->path().': '.$e::class.': '.$e->getMessage(), $e->getFile(), $e->getLine(), ['ref' => $ref, 'trace' => mb_substr($e->getTraceAsString(), 0, 6000)], null, $this->appVersion(), $this->agent ? (int) $this->agent['id'] : null);
    return $this->json(['ok' => false, 'error' => 'Something went wrong on the server (ref '.$ref.'). It has been logged.', 'ref' => $ref], 500); }

  private ?array $json = null;
  private function input(): array {
    $this->json ??= str_contains((string) ($this->request->header('Content-Type') ?? $_SERVER['CONTENT_TYPE'] ?? ''), 'json') ? $this->request->json() : [];
    return $this->json + $this->request->all(); }

  /** project/task/description from input (only keys that were sent). */
  private function target(): array {
    $in = $this->input(); $d = [];
    if (array_key_exists('project_id', $in)) { $d['project_id'] = (int) $in['project_id'] ?: null; $d['task_id'] = (int) ($in['task_id'] ?? 0) ?: null; }
    if (array_key_exists('description', $in)) { $d['description'] = mb_substr(trim((string) $in['description']), 0, 500); }
    if (array_key_exists('billable', $in)) { $d['billable'] = filter_var($in['billable'], FILTER_VALIDATE_BOOLEAN) ? 1 : 0; }
    return $d; }

  /** Idle minutes until tracking pauses by itself (2 to 240, always more than idle_minutes). */
  public static function idleAutoPause(): int { return max(max(1, (int) Settings::get('attendance.idle_minutes', 5)) + 1, min(240, max(2, (int) Settings::get('attendance.idle_auto_pause_minutes', 15)))); }

  public static function settings(): array {
    return ['heartbeat_seconds' => max(15, min(60, (int) Settings::get('attendance.heartbeat_seconds', 60))), 'idle_minutes' => max(1, (int) Settings::get('attendance.idle_minutes', 5)), 'idle_auto_pause_minutes' => self::idleAutoPause(), 'auto_pause_on_lock' => Settings::bool('attendance.auto_pause_lock', true),
      'screenshots_enabled' => Monitoring::enabled(), 'screenshot_interval_seconds' => Monitoring::intervalSeconds(), 'screenshot_notify' => Monitoring::notifyDefault(), 'screenshot_notify_user_can_disable' => Monitoring::notifyOptional(),
      'retention_days' => Monitoring::retention(), 'stale_minutes' => intdiv(T::STALE_SECONDS, 60), 'projects_enabled' => feature_on('projects')]; }

  /** Everything the app needs after sign-in or on start-up. */
  private function payload(array $u): array {
    $uid = (int) $u['id'];
    Tracker::settle($uid);
    $projects = [];
    foreach (T::projects($u) as $id => $n) { $projects[(int) $id] = ['id' => (int) $id, 'name' => $n, 'tasks' => []]; }
    if ($projects) {
      $ids = array_keys($projects);
      foreach (DB::all("SELECT id, project_id, title, assignee_id=? AS mine FROM tasks WHERE status<>'done' AND project_id IN ".DB::in($ids).' ORDER BY (assignee_id=?) DESC, position, id LIMIT 3000', [$uid, ...$ids, $uid]) as $t) { $projects[(int) $t['project_id']]['tasks'][] = ['id' => (int) $t['id'], 'title' => $t['title'], 'mine' => (bool) $t['mine']]; } }
    $c = Monitoring::consent($uid);
    return ['user' => ['id' => $uid, 'name' => $u['name'], 'email' => $u['email'], 'role' => $u['role_name'] ?? null, 'initials' => strtoupper(mb_substr(preg_replace('/[^\p{L} ]/u', '', $u['name']), 0, 1))],
      'workspace' => ['name' => company_name(), 'url' => absolute_url('/'), 'time_page' => absolute_url('/time'), 'screenshots_page' => absolute_url('/time/screenshots'), 'timezone' => date_default_timezone_get(), 'clock' => str_contains((string) Settings::get('general.time_format', 'h:i A'), 'H') ? 24 : 12],
      'permissions' => ['track' => true, 'manual_entries' => true, 'view_team' => Gate::allows('attendance.view_all', $u)],
      'settings' => self::settings(), 'consent' => $c ? ['policy_version' => $c['policy_version'], 'consented_at' => date('c', strtotime($c['consented_at']))] : null, 'policy' => Monitoring::policy(),
      'projects' => array_values($projects), 'state' => Tracker::state($uid, isset($u['token_id']) ? (int) $u['token_id'] : null), 'server_time' => date('c')]; }

  /** POST /api/agent/login {email, password, device_name, os, app_version}: personal device token + bootstrap. */
  public function login(): Response {
    try {
      if (!Modules::enabled('attendance') || !feature_on('attendance')) { return $this->json(['ok' => false, 'error' => 'Time tracking is turned off for this workspace.', 'code' => 'disabled'], 403); }
      $in = $this->input();
      $email = strtolower(trim((string) ($in['email'] ?? '')));
      if (!filter_var($email, FILTER_VALIDATE_EMAIL) || (string) ($in['password'] ?? '') === '') { return $this->json(['ok' => false, 'error' => 'Enter your email and password.'], 422); }
      [$row, $err] = Auth::verify($email, (string) $in['password']);
      if ($err !== null) { return $this->json(['ok' => false, 'error' => $err, 'code' => str_contains($err, 'Too many') || str_contains($err, 'locked') ? 'locked' : 'credentials'], str_contains($err, 'Too many') ? 429 : 401); }
      $u = DB::first("SELECT u.*, r.slug AS role_slug, r.name AS role_name FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=? AND u.status='active' LIMIT 1", [$row['id']]);
      unset($u['password_hash']);
      if (!Gate::allows('attendance.track', $u)) { Audit::log('agent.login_denied', 'user', (int) $u['id'], ['reason' => 'permission'], (int) $u['id']); return $this->json(['ok' => false, 'error' => 'Your role is not allowed to track time. Ask your administrator.', 'code' => 'forbidden'], 403); }
      return $this->signIn($u, (string) ($in['device_name'] ?? 'Desktop'), (string) ($in['os'] ?? ''), 'agent.login'); }
    catch (\DomainException $e) { return $this->json(['ok' => false, 'error' => $e->getMessage()], 422); }
    catch (\Throwable $e) { return $this->fail($e); } }

  /** Device token + bootstrap for a verified user (password sign-in and connect codes answer the same shape). */
  private function signIn(array $u, string $device, string $os, string $action): Response {
    $os = mb_substr(preg_replace('/[^0-9A-Za-z._ -]/', '', $os), 0, 40) ?: null;
    [$plain, $tid] = Monitoring::createToken((int) $u['id'], $device, 'desktop', $os, $this->appVersion());
    DB::update('agent_tokens', ['last_used_at' => date('Y-m-d H:i:s'), 'last_ip' => $this->request->ip()], 'id=?', [$tid]);
    DB::update('users', ['last_login_at' => date('Y-m-d H:i:s'), 'last_login_ip' => $this->request->ip()], 'id=?', [$u['id']]);
    Audit::log($action, 'agent_token', $tid, ['device' => $device, 'os' => $os, 'version' => $this->appVersion()], (int) $u['id']);
    $this->agent = $u + ['token_id' => $tid];
    return $this->json(['ok' => true, 'token' => $plain] + $this->payload($u)); }

  /**
   * POST /api/agent/connect {code, verifier?, device_name, device_os, app_version}: trade a one-time connect code from the
   * browser (deep link niyanta-tracker://connect, see DesktopConnect) for a device token; same answer as login.
   */
  public function connect(): Response {
    try {
      $err = ['code_invalid' => [401, 'This sign-in link is not valid any more. Please sign in again from the app.'], 'code_expired' => [401, 'This sign-in link has expired. Please sign in again from the app.'],
        'verifier_mismatch' => [401, 'This sign-in link was started on another device. Please sign in again from the app.'], 'forbidden' => [403, 'Your role is not allowed to track time. Ask your administrator.'], 'disabled' => [403, 'Time tracking is turned off for this workspace.']];
      $ipKey = 'connect-fail:'.$this->request->ip();
      if (RateLimiter::blocked($ipKey, 20)) { return $this->json(['ok' => false, 'error' => 'Too many failed sign-in attempts. Try again in 15 minutes.', 'code' => 'locked'], 429); }
      $in = $this->input();
      [$ok, $code] = DesktopConnect::consume(trim((string) ($in['code'] ?? '')), isset($in['verifier']) ? (string) $in['verifier'] : null, $this->request->ip());
      if (!$ok) {
        if ($code !== 'disabled') { RateLimiter::hit($ipKey, 20, 900); }
        return $this->json(['ok' => false, 'error' => $err[$code][1], 'code' => $code], $err[$code][0]); }
      $c = $ok['code'];
      $os = (string) ($in['device_os'] ?? $in['os'] ?? '') ?: (string) $c['device_os'];
      $res = $this->signIn($ok['user'], DesktopConnect::deviceName((string) ($in['device_name'] ?? '')) ?? $c['device_name'] ?? 'Desktop', $os, 'agent.login');
      Audit::log('desktop.connect.used', 'desktop_connect_code', (int) $c['id'], ['purpose' => $c['purpose'], 'token_id' => (int) $this->agent['token_id']], (int) $ok['user']['id']);
      return $res; }
    catch (\DomainException $e) { return $this->json(['ok' => false, 'error' => $e->getMessage()], 422); }
    catch (\Throwable $e) { return $this->fail($e); } }

  public function logout(): Response {
    return $this->run(function (int $uid): array { Monitoring::revokeToken($uid, (int) $this->agent['token_id'], $this->at()); Audit::log('agent.logout', 'agent_token', (int) $this->agent['token_id'], [], $uid); return []; }); }

  public function bootstrap(): Response { return $this->run(fn (int $uid): array => $this->payload($this->agent)); }

  /** Legacy agents: GET /api/agent/status (same as bootstrap). */
  public function status(): Response { return $this->bootstrap(); }

  public function state(): Response {
    return $this->run(function (int $uid): array { Tracker::settle($uid); return ['state' => Tracker::state($uid, $this->tok()), 'settings' => self::settings(), 'consent' => (bool) Monitoring::consent($uid)]; }); }

  /** The asking device's token id (running.here, guards for another device's entry). */
  private function tok(): int { return (int) $this->agent['token_id']; }

  public function tasks(): Response { return $this->run(fn (int $uid): array => ['tasks' => T::tasks($this->agent, (int) ($this->input()['project_id'] ?? 0))]); }

  /** Client time of a queued action (ISO 8601), clamped by Tracker::at(). */
  private function at(): ?string { $v = $this->input()['at'] ?? null; return is_string($v) && $v !== '' ? mb_substr($v, 0, 40) : null; }

  public function dayStart(): Response { return $this->run(fn (int $uid): array => ['state' => Tracker::startDay($this->agent, $this->target(), (int) $this->agent['token_id'], $this->request->ip(), $this->at())]); }
  /** anywhere=1: the app's explicit Pause on its read-only view (tracking runs on another device): pauses the person wherever it runs. */
  public function pause(): Response { return $this->run(fn (int $uid): array => ['state' => Tracker::pause($uid, $this->at(), $this->tok(), filter_var($this->input()['anywhere'] ?? false, FILTER_VALIDATE_BOOLEAN))]); }
  /** "Track on this computer instead": moves the running entry to this device now (see Tracker::takeover). */
  public function takeover(): Response { return $this->run(fn (int $uid): array => ['state' => Tracker::takeover($this->agent, $this->target(), $this->tok(), $this->request->ip())]); }
  public function resume(): Response { return $this->run(fn (int $uid): array => ['state' => Tracker::resume($this->agent, $this->target(), $this->tok(), $this->at(), $this->request->ip())]); }
  public function switchProject(): Response {
    return $this->run(function (int $uid): array {
      $d = $this->target();
      if (!array_key_exists('project_id', $d)) { throw new \DomainException('Choose a project (or "No project") to switch to.'); }
      return ['state' => Tracker::switchTo($this->agent, $d, (int) $this->agent['token_id'], $this->at())]; }); }
  /** Legacy (older apps): the day now ends by itself at midnight. */
  public function dayEnd(): Response { return $this->run(fn (int $uid): array => ['state' => Tracker::endDay($uid, $this->request->ip(), $this->at())]); }

  /**
   * Activity since the previous heartbeat, sent at `at` (queued beats keep their time), plus `idle_spans` [{from, to}] the app
   * closed since (saved as idle time, time_idle_spans action 'auto'). running=false tells the app the server stopped the entry
   * (stale: the app was not running in between, auto-close, or another device). A beat from a device that does not run the
   * entry (another computer tracks) changes nothing and answers 200 running_here=false (apps up to 1.4.6 ignore it and keep
   * going on state; newer apps stop tracking locally on state.running.here = false).
   */
  public function heartbeat(): Response {
    return $this->run(function (int $uid): array {
      $in = $this->input();
      $r = T::heartbeat($uid, min(86400, max(0, (int) ($in['active_seconds'] ?? 0))), min(86400, max(0, (int) ($in['idle_seconds'] ?? 0))), Tracker::at($uid, $this->at()), $this->tok());
      if (is_array($in['idle_spans'] ?? null) && $in['idle_spans']) { T::idleSpans($uid, array_values($in['idle_spans']), 'auto', $this->tok()); }
      $st = Tracker::state($uid, $this->tok());
      return ['running' => (bool) $r, 'running_here' => (bool) $r, 'entry' => $r, 'state' => $st]; }); }

  /**
   * Legacy (apps up to 1.0.2 asked "keep or discard?"): idle time is now always kept, so both answers only record the spans
   * as kept idle time and nothing is removed from tracked time (`discarded` is always 0).
   */
  public function idle(): Response {
    return $this->run(function (int $uid): array {
      $in = $this->input();
      $spans = is_array($in['spans'] ?? null) ? array_values($in['spans']) : (isset($in['from'], $in['to']) ? [['from' => $in['from'], 'to' => $in['to']]] : []);
      $r = $spans ? T::idleSpans($uid, $spans, 'keep', $this->tok()) : ['discarded' => 0, 'kept' => 0];
      return $r + ['state' => Tracker::state($uid, $this->tok())]; }); }

  /**
   * Activity per slot (the screenshot interval): {slots: [{slot_start, slot_seconds, active_seconds, meeting?}]}
   * (at most 500 per request). Idempotent per slot_start; see TimeService::activitySlots().
   */
  public function activity(): Response {
    return $this->run(function (int $uid): array {
      $slots = $this->input()['slots'] ?? null;
      if (!is_array($slots) || !$slots || count($slots) > 500) { throw new \DomainException('Send 1 to 500 activity slots as "slots".'); }
      return T::activitySlots($uid, $slots, (int) $this->agent['token_id']); }); }

  /** Accept the monitoring notice shown in the app (policy version must match). */
  public function consent(): Response {
    return $this->run(function (int $uid): array {
      $in = $this->input();
      if (!Monitoring::enabled()) { throw new \DomainException('Screenshot monitoring is not enabled.'); }
      if (!filter_var($in['agree'] ?? false, FILTER_VALIDATE_BOOLEAN)) { throw new \DomainException('Please confirm that you have read the notice.'); }
      if (($in['policy_version'] ?? Monitoring::POLICY_VERSION) !== Monitoring::POLICY_VERSION) { throw new \DomainException('The monitoring notice has changed. Please read it again.'); }
      Monitoring::giveConsent($uid, $this->request->ip());
      $c = Monitoring::consent($uid);
      return ['consent' => ['policy_version' => $c['policy_version'], 'consented_at' => date('c', strtotime($c['consented_at']))]]; }); }

  /** multipart: screenshot (JPEG/PNG/WebP), activity_pct, meeting (1/0, of the slot), display (0-based index), captured_at (ISO 8601). Keystroke and mouse counts are no longer collected (ignored if an old app sends them). */
  public function screenshot(): Response {
    return $this->run(function (int $uid): array {
      $f = $this->request->file('screenshot') ?? throw new \DomainException('Send the image as multipart field "screenshot".');
      $num = fn (string $k): ?int => is_numeric($v = $this->request->input($k)) ? (int) $v : null;
      $id = Monitoring::store($uid, $f, 'desktop', $num('activity_pct'), $num('display'), $this->request->str('captured_at') ?: null, $this->tok(), filter_var($this->request->input('meeting') ?? false, FILTER_VALIDATE_BOOLEAN));
      /* captured while another computer tracked: not stored; 200 so no app version retries or reports it */
      return $id === null ? ['id' => null, 'skipped' => 'other_device', 'running_here' => false] : ['id' => $id]; }); }

  /** Error report from the app: works without a token (sign-in problems), then attributed to nobody. */
  public function error(): Response {
    try {
      $in = $this->input();
      $tok = $this->token();
      $u = $tok !== '' ? Monitoring::tokenUser($tok) : null;
      $msg = trim((string) ($in['message'] ?? ''));
      if ($msg === '') { return $this->json(['ok' => false, 'error' => 'message is required'], 422); }
      $lvl = in_array($in['level'] ?? '', ['error', 'warning', 'info', 'fatal', 'exception', 'rejection'], true) ? $in['level'] : 'error';
      $ctx = array_filter(['os' => mb_substr((string) ($in['os'] ?? ''), 0, 60), 'arch' => mb_substr((string) ($in['arch'] ?? ''), 0, 20), 'where' => mb_substr((string) ($in['where'] ?? ''), 0, 120), 'stack' => mb_substr((string) ($in['stack'] ?? ''), 0, 8000),
        'device' => $u['token_name'] ?? null, 'details' => is_array($in['context'] ?? null) ? array_slice($in['context'], 0, 30) : null]);
      ErrorLog::record('desktop', $lvl, $msg, isset($in['file']) ? mb_substr((string) $in['file'], 0, 200) : null, isset($in['line']) && is_numeric($in['line']) ? (int) $in['line'] : null, $ctx, 'desktop:'.mb_substr((string) ($in['where'] ?? 'app'), 0, 100), $this->appVersion() ?? 'desktop', $u ? (int) $u['id'] : null);
      return $this->json(['ok' => true]); }
    catch (\Throwable $e) { error_log('Agent error report failed: '.$e->getMessage()); return $this->json(['ok' => false], 500); } }

  /** Latest tracker version for the app's update check (the server's mirror of the newest release, see DesktopApp). */
  public function version(): Response {
    return $this->run(function (int $uid): array {
      $l = DesktopApp::latest();
      $cur = $this->appVersion();
      $files = [];
      foreach ($l['platforms'] as $p => $list) { foreach ($list as $f) { $files[$p][] = ['name' => $f['name'], 'arch' => $f['arch'], 'ext' => $f['ext'], 'size' => $f['size'], 'version' => $f['version'], 'sha512' => DesktopApp::sha512($f['name'])]; } }
      return ['latest' => $l['version'], 'current' => $cur, 'update_available' => $l['version'] !== null && $cur !== null && version_compare($l['version'], preg_replace('/[^0-9.].*$/', '', $cur), '>'), 'released_at' => $l['date'],
        'download_page' => absolute_url('/time#desktop'), 'updates_feed' => absolute_url('/api/agent/updates/'), 'files' => $files]; }); }

  /**
   * electron-updater feed (generic provider, device token): latest.yml / latest-mac.yml / latest-linux*.yml with url/path
   * rewritten to this endpoint, and the mirrored installers and .blockmap files themselves (streamed).
   */
  public function updates(string $file): Response {
    try {
      if ($r = $this->guard()) { return $r; }
      $file = rawurldecode($file);
      if (in_array($file, DesktopApp::UPDATE_FILES, true)) {
        [$code, $body] = DesktopApp::updateInfo($file);
        return new Response($body, $code, ['Content-Type' => $code === 200 ? 'text/yaml; charset=utf-8' : 'text/plain; charset=utf-8', 'Cache-Control' => 'private, no-store']); }
      $path = DesktopApp::path($file);
      if (!$path) { return new Response('Not found.', 404, ['Content-Type' => 'text/plain; charset=utf-8']); }
      return Response::file($path, $file, 'application/octet-stream'); }
    catch (\Throwable $e) { return $this->fail($e); } } }
