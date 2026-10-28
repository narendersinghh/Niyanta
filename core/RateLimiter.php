<?php
namespace Core;

/** Fixed-window limiter stored in MySQL (works on shared hosting without Redis). */
final class RateLimiter {
  public static function hit(string $key, int $max, int $window): bool {
    $k = hash('sha256', $key);
    $now = time();
    DB::query('INSERT INTO rate_limits (k, hits, reset_at) VALUES (?,1,?) ON DUPLICATE KEY UPDATE hits=IF(reset_at<?,1,hits+1), reset_at=IF(reset_at<?,VALUES(reset_at),reset_at)', [$k, $now + $window, $now, $now]);
    return (int) DB::value('SELECT hits FROM rate_limits WHERE k=?', [$k]) <= $max; }
  public static function clear(string $key): void { DB::delete('rate_limits', 'k=?', [hash('sha256', $key)]); }
  public static function prune(): int { return DB::delete('rate_limits', 'reset_at<?', [time()]); } }
