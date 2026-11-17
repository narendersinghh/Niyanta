<?php
namespace Core;

final class Response {
  private ?\Closure $stream = null;
  public function __construct(private string $body = '', private int $status = 200, private array $headers = [], private ?string $file = null) {}

  public static function json(mixed $data, int $status = 200): self { return new self(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $status, ['Content-Type' => 'application/json; charset=utf-8']); }
  public static function redirect(string $to, int $status = 302): self { return new self('', $status, ['Location' => $to]); }
  public static function back(): self {
    $ref = (string) ($_SERVER['HTTP_REFERER'] ?? '');
    $host = parse_url($ref, PHP_URL_HOST);
    $port = parse_url($ref, PHP_URL_PORT);
    /* only this site: an http(s) URL on our host, or a clean local path (never javascript:, data: or other schemes) */
    $same = $host === null ? local_path($ref, '') !== '' : in_array(strtolower((string) parse_url($ref, PHP_URL_SCHEME)), ['http', 'https'], true) && (($host.($port ? ':'.$port : '')) === ($_SERVER['HTTP_HOST'] ?? '') || $host === parse_url('http://'.($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST));
    return self::redirect($ref !== '' && $same && !preg_match('/[\x00-\x1f\x7f]/', $ref) ? $ref : url('/')); }
  public static function view(string $view, array $data = [], string $layout = 'layouts/app'): self { return new self(View::render($view, $data, $layout)); }

  /**
   * Stream a private file; caller must have authorised access. Inline files (screenshots, avatars, previews) carry an ETag
   * and Last-Modified and answer 304 to conditional requests (the caller's authorisation ran first), so a revisit costs no
   * bandwidth; attachments are never cached.
   */
  public static function file(string $path, string $name, ?string $mime = null, bool $inline = false): self {
    if (!is_file($path)) { return self::error(404, 'File not found.'); }
    $mime ??= (new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';
    $safe = preg_replace('/[^\w.\- ]+/', '_', $name);
    $mtime = (int) filemtime($path); $size = (int) filesize($path);
    $h = ['Content-Type' => $mime, 'Content-Disposition' => ($inline ? 'inline' : 'attachment').'; filename="'.$safe.'"; filename*=UTF-8\'\''.rawurlencode($name), 'Cache-Control' => $inline ? 'private, no-cache' : 'private, no-store', 'X-Content-Type-Options' => 'nosniff'];
    if ($inline) {
      $etag = '"'.substr(hash('sha256', $path.'|'.$mtime.'|'.$size), 0, 32).'"';
      $h += ['ETag' => $etag, 'Last-Modified' => gmdate('D, d M Y H:i:s', $mtime).' GMT'];
      $req = Request::current();
      $inm = (string) $req->header('If-None-Match'); $ims = (string) $req->header('If-Modified-Since');
      if (($inm !== '' && hash_equals($etag, trim($inm))) || ($inm === '' && $ims !== '' && strtotime($ims) >= $mtime)) { return new self('', 304, array_diff_key($h, array_flip(['Content-Type', 'Content-Disposition']))); } }
    return new self('', 200, $h + ['Content-Length' => (string) $size], $path); }

  /**
   * A download written row by row straight to the client: $write receives an output stream. Large exports never build the
   * whole file in memory (with DB::cursor the rows are not all held as PHP arrays either).
   */
  public static function stream(callable $write, string $name, string $mime): self {
    $r = self::download('', $name, $mime);
    $r->stream = \Closure::fromCallable($write);
    return $r; }

  /**
   * UTF-8 BOM + CSV of $rows (any iterable, e.g. DB::cursor) via Response::stream; $map turns a row into its cells, $done
   * gets the row count afterwards (audit). Cells starting with = + - @ tab or CR get a leading ' (spreadsheet formula injection).
   */
  public static function csv(array $head, iterable $rows, string $name, ?callable $map = null, ?callable $done = null): self {
    return self::stream(static function ($out) use ($head, $rows, $map, $done): void {
      $safe = static fn ($v) => is_string($v) && $v !== '' && strpbrk($v[0], "=+-@\t\r") !== false ? "'".$v : $v;
      fwrite($out, "\xEF\xBB\xBF");
      fputcsv($out, $head, ',', '"', '');
      $n = 0;
      foreach ($rows as $r) { fputcsv($out, array_map($safe, $map ? $map($r) : array_values($r)), ',', '"', ''); $n++; }
      if ($done) { $done($n); } }, $name, 'text/csv; charset=utf-8'); }

  public static function download(string $content, string $name, string $mime): self { return new self($content, 200, ['Content-Type' => $mime, 'Content-Disposition' => 'attachment; filename="'.preg_replace('/[^\w.\- ]+/', '_', $name).'"', 'Cache-Control' => 'private, no-store']); }

  public static function error(int $code, string $msg, ?Request $req = null, string $kind = ''): self {
    $req ??= Request::current();
    if ($req->wantsJson()) { return self::json(['error' => $msg], $code); }
    $layout = App::installed() && Auth::check() ? 'layouts/app' : 'layouts/blank';
    return new self(View::render('errors/error', ['code' => $code, 'message' => $msg, 'kind' => $kind, 'title' => 'Error '.$code], $layout), $code); }

  public function header(string $k, string $v): self { $this->headers[$k] = $v; return $this; }
  public function status(): int { return $this->status; }
  public function body(): string {
    /* a stream runs once (its rows come from a cursor): reading the body renders it into memory for good (tests, callers that post-process) */
    if ($this->stream) { $out = fopen('php://temp', 'w+'); ($this->stream)($out); rewind($out); $this->body = (string) stream_get_contents($out); fclose($out); $this->stream = null; }
    return $this->body; }
  public function headers(): array { return $this->headers; }

  /**
   * Security headers added to every response (a response's own headers win). The CSP allows no inline scripts except the
   * one nonce'd theme snippet in the head; dynamic pages are never stored by browsers or shared caches.
   */
  public static function securityHeaders(?Request $req = null): array {
    $req ??= Request::current();
    $nonce = View::nonce();
    $secure = $req->isSecure();
    $h = ['X-Content-Type-Options' => 'nosniff', 'X-Frame-Options' => 'SAMEORIGIN', 'Referrer-Policy' => 'strict-origin-when-cross-origin', 'Cross-Origin-Opener-Policy' => 'same-origin', 'Cross-Origin-Resource-Policy' => 'same-origin', 'X-Permitted-Cross-Domain-Policies' => 'none',
      'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=(), display-capture=(self)',
      'Content-Security-Policy' => "default-src 'self'; script-src 'self' 'nonce-$nonce'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com data:; img-src 'self' data: blob:; connect-src 'self'; frame-src 'none'; worker-src 'self'; manifest-src 'self'; frame-ancestors 'self'; form-action 'self'; base-uri 'self'; object-src 'none'".($secure ? '; upgrade-insecure-requests' : ''),
      'Cache-Control' => 'no-store, max-age=0'];
    if ($secure) { $h['Strict-Transport-Security'] = 'max-age=31536000'; }
    return $h; }

  /** Opt-in request profile (config debug or profile): total time, query count and time, peak memory. Never sent in production by default. */
  public static function timing(): string {
    return sprintf('app;dur=%.1f, db;desc="%d queries";dur=%.1f, mem;desc="%.2f MB peak"', (microtime(true) - NY_START) * 1000, DB::$queries, DB::$time * 1000, memory_get_peak_usage() / 1048576); }

  public function send(): void {
    if (!headers_sent()) {
      http_response_code($this->status);
      header_remove('X-Powered-By');
      foreach ($this->headers + self::securityHeaders() + ['Content-Type' => 'text/html; charset=utf-8'] as $k => $v) { header($k.': '.$v); }
      if (App::config('debug') || App::config('profile')) { header('Server-Timing: '.self::timing()); } }
    if ($this->file) { while (ob_get_level()) { ob_end_clean(); } readfile($this->file); return; }
    if ($this->stream) { $out = fopen('php://output', 'w'); ($this->stream)($out); fclose($out); return; }
    echo $this->body; } }
