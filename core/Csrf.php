<?php
namespace Core;

final class Csrf {
  public static function token(): string {
    if (empty($_SESSION['_csrf'])) { $_SESSION['_csrf'] = bin2hex(random_bytes(32)); }
    return $_SESSION['_csrf']; }
  public static function verify(Request $r): bool {
    $t = $r->input('_token') ?? $r->header('X-CSRF-Token');
    return is_string($t) && !empty($_SESSION['_csrf']) && hash_equals($_SESSION['_csrf'], $t); } }
