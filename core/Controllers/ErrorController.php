<?php
namespace Core\Controllers;
use Core\{Controller, ErrorLog, Response};

/** Receives JavaScript errors from the browser (beacon). Unauthenticated pages may report too; input is size-capped and rate-limited. */
final class ErrorController extends Controller {
  public function client(): Response {
    $d = $this->request->json() ?: $this->request->all();
    $msg = trim((string) ($d['message'] ?? ''));
    if ($msg === '' || str_contains($msg, 'ResizeObserver loop')) { return new Response('', 204); }
    ErrorLog::record('browser', 'js', $msg, isset($d['source']) ? mb_substr((string) $d['source'], 0, 200) : null, isset($d['line']) ? (int) $d['line'] : null,
      array_filter(['column' => isset($d['column']) ? (int) $d['column'] : null, 'stack' => isset($d['stack']) ? mb_substr((string) $d['stack'], 0, 4000) : null]), isset($d['page']) ? mb_substr((string) $d['page'], 0, 500) : null);
    return new Response('', 204); } }
