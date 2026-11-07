<?php
namespace Core;

final class Response {
  public function __construct(private string $body = '', private int $status = 200, private array $headers = [], private ?string $file = null) {}

  public static function json(mixed $data, int $status = 200): self { return new self(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $status, ['Content-Type' => 'application/json; charset=utf-8']); }
  public static function redirect(string $to, int $status = 302): self { return new self('', $status, ['Location' => $to]); }
  public static function back(): self {
    $ref = (string) ($_SERVER['HTTP_REFERER'] ?? '');
    $host = parse_url($ref, PHP_URL_HOST);
    $port = parse_url($ref, PHP_URL_PORT);
    $same = $host === null || ($host.($port ? ':'.$port : '')) === ($_SERVER['HTTP_HOST'] ?? '') || $host === parse_url('http://'.($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST);
    return self::redirect($ref !== '' && $same ? $ref : url('/')); }
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

  public static function download(string $content, string $name, string $mime): self { return new self($content, 200, ['Content-Type' => $mime, 'Content-Disposition' => 'attachment; filename="'.preg_replace('/[^\w.\- ]+/', '_', $name).'"', 'Cache-Control' => 'private, no-store']); }

  public static function error(int $code, string $msg, ?Request $req = null, string $kind = ''): self {
    $req ??= Request::current();
    if ($req->wantsJson()) { return self::json(['error' => $msg], $code); }
    $layout = App::installed() && Auth::check() ? 'layouts/app' : 'layouts/blank';
    return new self(View::render('errors/error', ['code' => $code, 'message' => $msg, 'kind' => $kind, 'title' => 'Error '.$code], $layout), $code); }

  public function header(string $k, string $v): self { $this->headers[$k] = $v; return $this; }
  public function status(): int { return $this->status; }
  public function body(): string { return $this->body; }
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

  public function send(): void {
    if (!headers_sent()) {
      http_response_code($this->status);
      header_remove('X-Powered-By');
      foreach ($this->headers + self::securityHeaders() + ['Content-Type' => 'text/html; charset=utf-8'] as $k => $v) { header($k.': '.$v); } }
    if ($this->file) { while (ob_get_level()) { ob_end_clean(); } readfile($this->file); return; }
    echo $this->body; } }
