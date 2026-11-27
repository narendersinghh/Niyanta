<?php
namespace Core;

/** Authenticated encryption (libsodium secretbox) keyed by the app key in config. */
final class Crypto {
  private static function raw(): string {
    $k = (string) App::config('app_key', '');
    $raw = base64_decode(str_starts_with($k, 'base64:') ? substr($k, 7) : $k, true);
    if ($raw === false || strlen($raw) < 32) { throw new \RuntimeException('Application key missing or invalid.'); }
    return $raw; }

  private static function key(): string { return substr(hash('sha256', self::raw(), true), 0, 32); }

  /** Is libsodium available? Hosts can switch it off (e.g. a PHP version change resets the extensions on Hostinger). */
  public static ?bool $simulateMissing = null; /* tests only */
  public static function available(): bool { return !self::$simulateMissing && function_exists('sodium_crypto_secretbox'); }

  private static bool $warned = false;
  private static function missing(): void {
    if (self::$warned) { return; }
    self::$warned = true;
    try { ErrorLog::record('server', 'error', 'The PHP sodium extension is disabled: stored secrets (GitHub token, API keys, bank details) cannot be read. Enable "sodium" in the hosting PHP extensions.', __FILE__, __LINE__); } catch (\Throwable) { error_log('Niyanta: PHP sodium extension missing'); } }

  /** HMAC-SHA256 (raw bytes) of $data with a subkey of the app key that is bound to $purpose, so a signature for one purpose never verifies for another. */
  public static function sign(string $purpose, string $data): string { return hash_hmac('sha256', $data, hash_hmac('sha256', 'niyanta.sign.'.$purpose, self::raw(), true), true); }

  public static function encrypt(string $plain): string {
    if (!self::available()) { self::missing(); throw new \RuntimeException('Cannot store this securely: the PHP sodium extension is disabled on the server. Enable it in the hosting PHP settings.'); }
    $n = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return 'enc:'.base64_encode($n.sodium_crypto_secretbox($plain, $n, self::key())); }

  public static function decrypt(?string $enc): ?string {
    if ($enc === null || $enc === '') { return null; }
    if (!str_starts_with($enc, 'enc:')) { return $enc; }
    if (!self::available()) { self::missing(); return null; } /* the page keeps working without the secret instead of a fatal error */
    $b = base64_decode(substr($enc, 4), true);
    if ($b === false || strlen($b) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) { return null; }
    $r = sodium_crypto_secretbox_open(substr($b, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($b, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), self::key());
    return $r === false ? null : $r; }

  public static function token(int $bytes = 32): string { return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '='); }
  public static function generateKey(): string { return 'base64:'.base64_encode(random_bytes(32)); } }
