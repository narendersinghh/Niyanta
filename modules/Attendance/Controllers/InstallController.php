<?php
namespace Modules\Attendance\Controllers;
use Core\{Audit, Controller, Response};
use Modules\Attendance\Services\{DesktopApp, DesktopConnect, DesktopInstall};

/**
 * Public, key-protected install routes for the one-line commands on Time Tracking (see DesktopInstall): the bash and
 * PowerShell scripts and the installer files they download. No session or CSRF (curl / PowerShell have neither); the signed
 * key ?k= decides, the routes are throttled and every script and download is audit-logged for the key's user.
 */
final class InstallController extends Controller {
  private const SCRIPT = ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex'];

  public function shell(): Response { return $this->script('sh'); }
  public function powershell(): Response { return $this->script('ps1'); }

  /** Invalid links still answer 200 with a script that explains the problem: curl -f would otherwise hide it behind "error 403". */
  private function script(string $kind): Response {
    $err = $kind === 'sh' ? [DesktopInstall::class, 'shellError'] : [DesktopInstall::class, 'powershellError'];
    $key = (string) $this->request->query('k', '');
    $u = DesktopInstall::user($key);
    if (!$u) { return new Response($err('This install command has expired or is not valid. Open Time Tracking in Niyanta, copy the command again and run it within 2 hours.'), 200, self::SCRIPT); }
    $f = DesktopInstall::files();
    if (!$f || ($kind === 'ps1' && !$f['windows']) || ($kind === 'sh' && !$f['mac_arm64'] && !$f['mac_x64'] && !$f['linux'])) { return new Response($err('The desktop app is not available on this workspace yet. Please try again later.'), 200, self::SCRIPT); }
    Audit::log('tracker.install_script', 'settings', null, ['version' => $f['version'], 'script' => $kind], (int) $u['id']);
    /* a fresh install signs in by itself: a one-time connect code (30 minutes) opened as niyanta-tracker://connect after installing */
    $link = DesktopConnect::deepLink(DesktopConnect::issue((int) $u['id'], 'install', null, null, null, $kind === 'sh' ? null : 'win', $this->request->ip()));
    return new Response($kind === 'sh' ? DesktopInstall::shell($key, $f, $link) : DesktopInstall::powershell($key, $f, $link), 200, self::SCRIPT); }

  /** An installer of the mirrored release (installers only, never the update files or the manifest). */
  public function file(string $file): Response {
    $file = rawurldecode($file);
    $u = DesktopInstall::user((string) $this->request->query('k', ''));
    if (!$u) { return new Response('This download link has expired. Copy the install command again from Time Tracking.', 403, ['Content-Type' => 'text/plain; charset=utf-8']); }
    $path = DesktopApp::path($file);
    $c = DesktopApp::classify($file);
    if (!$path || !$c) { return new Response('That installer is no longer available. Copy the install command again from Time Tracking.', 404, ['Content-Type' => 'text/plain; charset=utf-8']); }
    Audit::log('tracker.download', 'settings', null, ['version' => $c['version'], 'file' => $file, 'via' => 'install command'], (int) $u['id']);
    return Response::file($path, $file, 'application/octet-stream'); } }
