<?php
namespace Core;

/** Fixed-window limiter stored in MySQL (works on shared hosting without Redis). */
final class RateLimiter {
  public static function hit(string $key, int $max, int $window): bool {
    $k = hash('sha256', $key);
    $now = time();
    /* one round trip: LAST_INSERT_ID(expr) hands the updated hit count back (a fresh row affects 1 row and has 1 hit) */
    $st = DB::query('INSERT INTO rate_limits (k, hits, reset_at) VALUES (?,1,?) ON DUPLICATE KEY UPDATE hits=LAST_INSERT_ID(IF(reset_at<?,1,hits+1)), reset_at=IF(reset_at<?,VALUES(reset_at),reset_at)', [$k, $now + $window, $now, $now]);
    return ($st->rowCount() === 1 ? 1 : (int) DB::pdo()->lastInsertId()) <= $max; }
  /** True when $key already has $max or more hits in its current window (does not count a hit). */
  public static function blocked(string $key, int $max): bool { return (int) DB::value('SELECT hits FROM rate_limits WHERE k=? AND reset_at>=?', [hash('sha256', $key), time()]) >= $max; }
  public static function clear(string $key): void { DB::delete('rate_limits', 'k=?', [hash('sha256', $key)]); }
  public static function prune(): int { return DB::delete('rate_limits', 'reset_at<?', [time()]); } }
