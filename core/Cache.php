<?php
namespace Core;

/** File cache using var_export so OPcache can serve values from shared memory. */
final class Cache {
  private static array $mem = [];
  /** Keys are namespaced per database so several installs/test DBs never share cached values. */
  private static function path(string $k): string { return NY_ROOT.'/storage/cache/'.substr(md5((string) App::config('db.name', '')), 0, 6).'_'.preg_replace('/[^a-z0-9_\-]/i', '_', $k).'.php'; }

  public static function get(string $k, mixed $d = null): mixed {
    if (array_key_exists($k, self::$mem)) { return self::$mem[$k]; }
    $f = self::path($k);
    if (!is_file($f)) { return $d; }
    $x = @include $f;
    if (!is_array($x) || ($x['e'] && $x['e'] < time())) { return $d; }
    return self::$mem[$k] = $x['v']; }

  public static function put(string $k, mixed $v, int $ttl = 0): void {
    self::$mem[$k] = $v;
    $f = self::path($k);
    $tmp = $f.'.'.bin2hex(random_bytes(4));
    if (@file_put_contents($tmp, '<?php return '.var_export(['e' => $ttl ? time() + $ttl : 0, 'v' => $v], true).';', LOCK_EX) !== false) { @rename($tmp, $f); if (function_exists('opcache_invalidate')) { @opcache_invalidate($f, true); } } }

  public static function remember(string $k, int $ttl, callable $fn): mixed {
    $miss = new \stdClass();
    $v = self::get($k, $miss);
    if ($v !== $miss) { return $v; }
    $v = $fn();
    self::put($k, $v, $ttl);
    return $v; }

  public static function forget(string $k): void { unset(self::$mem[$k]); $f = self::path($k); if (is_file($f)) { @unlink($f); if (function_exists('opcache_invalidate')) { @opcache_invalidate($f, true); } } }
  public static function flush(): void { self::$mem = []; foreach (glob(NY_ROOT.'/storage/cache/'.substr(md5((string) App::config('db.name', '')), 0, 6).'_*.php') ?: [] as $f) { @unlink($f); } if (function_exists('opcache_reset')) { @opcache_reset(); } } }
