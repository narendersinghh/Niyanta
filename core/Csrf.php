<?php
namespace Core;

final class Csrf {
  public static function token(): string {
    if (empty($_SESSION['_csrf'])) { $_SESSION['_csrf'] = bin2hex(random_bytes(32)); }
    return $_SESSION['_csrf']; }
  /** Token check plus, when the browser sent one, an Origin check: a token leaked to another site still cannot post from there. */
  public static function verify(Request $r): bool {
    $t = $r->input('_token') ?? $r->header('X-CSRF-Token');
    if (!is_string($t) || empty($_SESSION['_csrf']) || !hash_equals($_SESSION['_csrf'], $t)) { return false; }
    $origin = (string) $r->header('Origin');
    return $origin === '' || $origin === 'null' || self::sameOrigin($origin, $r); }

  public static function sameOrigin(string $origin, Request $r): bool {
    $host = strtolower((string) ($r->header('Host') ?? ''));
    $o = parse_url($origin);
    if (!$o || empty($o['host'])) { return false; }
    $oh = strtolower($o['host']).(isset($o['port']) ? ':'.$o['port'] : '');
    return $oh === $host || strtolower($o['host']) === $host; } }
