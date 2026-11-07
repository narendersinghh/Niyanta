<?php
namespace Modules\Attendance\Controllers;
use Core\{Response, View};
use Modules\Attendance\Services\DesktopConnect;

/**
 * "Sign in with your browser" pages for Niyanta Tracker (signed in, attendance.track). Flow A: the "Connect the desktop app"
 * button (POST /time/desktop/connect-link) mints a code and opens the deep link. Flow B: the app opens
 * GET /time/desktop/connect?challenge&state&device&os, the person allows it (POST, CSRF) and the browser opens the deep link
 * with a code bound to the PKCE challenge and state. The deep link is opened by a normal link plus js/tt-connect.js (no inline JS).
 */
final class ConnectController extends BaseController {
  private const PAGE = ['styles' => ['css/attendance.css']];

  /** Flow B parameters, or an error message. @return array{0: ?array, 1: ?string} */
  private function params(array $in): array {
    $ch = (string) ($in['challenge'] ?? ''); $st = (string) ($in['state'] ?? ''); $os = (string) ($in['os'] ?? '');
    if (!DesktopConnect::validChallenge($ch) || !DesktopConnect::validState($st)) { return [null, 'This sign-in request from Niyanta Tracker is incomplete. Start again from the app.']; }
    if ($os !== '' && !isset(DesktopConnect::OS[$os])) { return [null, 'This sign-in request names an unknown system. Start again from the app.']; }
    $dev = (string) ($in['device'] ?? '');
    if (mb_strlen($dev) > 100) { return [null, 'The device name is too long. Start again from the app.']; }
    return [['challenge' => $ch, 'state' => $st, 'device' => DesktopConnect::deviceName($dev), 'os' => $os !== '' ? $os : null], null]; }

  private function page(string $view, array $d, int $code = 200): Response {
    return new Response(View::render('attendance::time/'.$view, $d + self::PAGE, 'layouts/blank'), $code, ['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer']); }

  private function consent(?array $p, ?string $err, bool $cancelled = false): Response {
    return $this->page('connect', ['title' => 'Connect Niyanta Tracker', 'p' => $p, 'error' => $err, 'cancelled' => $cancelled, 'u' => $this->user(), 'self' => $this->request->uri()], $err ? 400 : 200); }

  private function opening(string $link, string $mode): Response { return $this->page('connect_open', ['title' => 'Open Niyanta Tracker', 'link' => $link, 'mode' => $mode, 'scripts' => ['js/tt-connect.js']]); }

  /** Flow A: "Connect the desktop app" on Time Tracking. */
  public function link(): Response {
    $code = DesktopConnect::issue($this->uid(), 'web', null, null, null, null, $this->request->ip());
    return $this->opening(DesktopConnect::deepLink($code), 'web'); }

  /** Flow B: the app asks to be connected. */
  public function show(): Response {
    [$p, $err] = $this->params($this->request->all());
    return $this->consent($p, $err); }

  public function allow(): Response {
    [$p, $err] = $this->params($this->request->all());
    if ($err || $this->request->input('decision') !== 'allow') { return $this->consent(null, $err, !$err); }
    $code = DesktopConnect::issue($this->uid(), 'browser', $p['challenge'], $p['state'], $p['device'], $p['os'], $this->request->ip());
    return $this->opening(DesktopConnect::deepLink($code, $p['state']), 'browser'); } }
