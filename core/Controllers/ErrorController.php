<?php
namespace Core\Controllers;
use Core\{Auth, Controller, ErrorLog, RateLimiter, Response};

/** Receives JavaScript errors from the browser (beacon). Unauthenticated pages may report too; input is size-capped and rate-limited. */
final class ErrorController extends Controller {
  public function client(): Response {
    $d = $this->request->json() ?: $this->request->all();
    $msg = trim((string) ($d['message'] ?? ''));
    if ($msg === '' || str_contains($msg, 'ResizeObserver loop')) { return new Response('', 204); }
    /* bounded per day so anonymous or scripted reports cannot fill the database: 300 new reports a day from signed-out pages in total, 500 per person */
    $uid = Auth::id();
    if (!RateLimiter::hit($uid ? 'errors:user:'.$uid : 'errors:guest', $uid ? 500 : 300, 86400)) { return new Response('', 204); }
    ErrorLog::record('browser', 'js', $msg, isset($d['source']) ? mb_substr((string) $d['source'], 0, 200) : null, isset($d['line']) ? (int) $d['line'] : null,
      array_filter(['column' => isset($d['column']) ? (int) $d['column'] : null, 'stack' => isset($d['stack']) ? mb_substr((string) $d['stack'], 0, 4000) : null]), isset($d['page']) ? mb_substr((string) $d['page'], 0, 500) : null);
    return new Response('', 204); } }
