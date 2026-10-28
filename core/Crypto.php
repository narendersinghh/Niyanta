<?php
namespace Core;

/** Authenticated encryption (libsodium secretbox) keyed by the app key in config. */
final class Crypto {
  private static function key(): string {
    $k = (string) App::config('app_key', '');
    $raw = base64_decode(str_starts_with($k, 'base64:') ? substr($k, 7) : $k, true);
    if ($raw === false || strlen($raw) < 32) { throw new \RuntimeException('Application key missing or invalid.'); }
    return substr(hash('sha256', $raw, true), 0, SODIUM_CRYPTO_SECRETBOX_KEYBYTES); }

  public static function encrypt(string $plain): string {
    $n = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return 'enc:'.base64_encode($n.sodium_crypto_secretbox($plain, $n, self::key())); }

  public static function decrypt(?string $enc): ?string {
    if ($enc === null || $enc === '') { return null; }
    if (!str_starts_with($enc, 'enc:')) { return $enc; }
    $b = base64_decode(substr($enc, 4), true);
    if ($b === false || strlen($b) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) { return null; }
    $r = sodium_crypto_secretbox_open(substr($b, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($b, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), self::key());
    return $r === false ? null : $r; }

  public static function token(int $bytes = 32): string { return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '='); }
  public static function generateKey(): string { return 'base64:'.base64_encode(random_bytes(32)); } }
