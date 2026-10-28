<?php
namespace Core\Controllers;
use Core\{Controller, Cron, Response, Settings};

/** Fallback web-cron for hosts without crontab: GET /cron/run/{token}. Token compared in constant time. */
final class CronController extends Controller {
  public function web(string $token): Response {
    $expected = (string) Settings::get('cron.web_token', '');
    if ($expected === '' || !Settings::bool('cron.web_enabled', true) || !hash_equals($expected, $token)) { return Response::json(['error' => 'Forbidden'], 403); }
    ignore_user_abort(true);
    return Response::json(['ok' => true, 'ran' => Cron::run()]); } }
