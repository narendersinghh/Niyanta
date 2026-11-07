<?php
namespace Core;

/**
 * Records errors from the server, the browser and the desktop tracker in error_logs (Audit log › Error logs).
 * Identical errors (same source, message, file and line) are grouped: occurrences++ and last_seen updated.
 * Never throws: logging must not cause a second failure.
 */
final class ErrorLog {
  private static bool $busy = false;

  /** Secrets never reach the log: reset and cron tokens in paths, install keys and tokens in query strings, bearer values in messages. */
  public static function redact(string $s): string {
    $s = preg_replace('#(/(?:reset-password|cron/run|shared/invoice/\d+)/)[^/?\s]+#', '$1[redacted]', $s);
    $s = preg_replace('#([?&](?:k|token|key|password|api_key|secret|_token)=)[^&\s]*#i', '$1[redacted]', $s);
    return preg_replace('#\b(nyt_[a-f0-9]{8})[a-f0-9]+\b|\b(Bearer\s+)\S+#i', '$1$2[redacted]', $s); }

  public static function record(string $source, string $level, string $message, ?string $file = null, ?int $line = null, array $context = [], ?string $url = null, ?string $version = null, ?int $uid = null): void {
    if (self::$busy || !App::installed()) { return; }
    self::$busy = true;
    try {
      $message = mb_substr(self::redact(trim(preg_replace('/\s+/', ' ', $message))), 0, 1000);
      $file = $file !== null ? mb_substr(str_replace(NY_ROOT.'/', '', $file), 0, 255) : null;
      $fp = sha1($source.'|'.$level.'|'.preg_replace('/\d{3,}/', 'N', $message).'|'.$file.'|'.$line);
      $req = PHP_SAPI === 'cli' ? null : Request::current();
      $now = date('Y-m-d H:i:s');
      if ($uid === null) { try { $uid = Auth::id(); } catch (\Throwable) { $uid = null; } }
      $ctx = $context ? mb_substr(json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR), 0, 60000) : null;
      DB::query('INSERT INTO error_logs (fingerprint, source, level, message, file, line, url, context, user_id, ip, user_agent, app_version, occurrences, first_seen, last_seen) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1,?,?)
        ON DUPLICATE KEY UPDATE occurrences=occurrences+1, last_seen=VALUES(last_seen), resolved_at=NULL, context=VALUES(context), url=VALUES(url), user_id=VALUES(user_id), app_version=VALUES(app_version)',
        [$fp, $source, mb_substr($level, 0, 20), $message, $file, $line, self::redact($url !== null ? mb_substr($url, 0, 500) : ($req ? mb_substr($req->method().' '.$req->uri(), 0, 500) : 'cli')), $ctx, $uid, $req ? $req->ip() : 'cli', $req ? $req->userAgent() : 'cron', $version !== null ? mb_substr($version, 0, 40) : NY_VERSION, $now, $now]); }
    catch (\Throwable $e) { error_log('ErrorLog failed: '.$e->getMessage()); }
    finally { self::$busy = false; } }

  public static function exception(\Throwable $e, string $ref = ''): void {
    self::record('server', 'exception', $e::class.': '.$e->getMessage(), $e->getFile(), $e->getLine(), ['ref' => $ref, 'trace' => mb_substr($e->getTraceAsString(), 0, 8000)]); }

  /** PHP warnings/notices (not deprecations). Returns false so PHP's normal logging still runs. */
  public static function phpError(int $no, string $str, string $file, int $line): bool {
    if (!(error_reporting() & $no) || in_array($no, [E_DEPRECATED, E_USER_DEPRECATED, E_STRICT], true)) { return false; }
    $lvl = match ($no) { E_WARNING, E_USER_WARNING, E_CORE_WARNING, E_COMPILE_WARNING => 'warning', E_NOTICE, E_USER_NOTICE => 'notice', default => 'error' };
    self::record('server', $lvl, $str, $file, $line);
    return false; } }
