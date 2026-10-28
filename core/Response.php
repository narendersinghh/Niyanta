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

  /** Stream a private file; caller must have authorised access. */
  public static function file(string $path, string $name, ?string $mime = null, bool $inline = false): self {
    if (!is_file($path)) { return self::error(404, 'File not found.'); }
    $mime ??= (new \finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream';
    $safe = preg_replace('/[^\w.\- ]+/', '_', $name);
    return new self('', 200, ['Content-Type' => $mime, 'Content-Length' => (string) filesize($path), 'Content-Disposition' => ($inline ? 'inline' : 'attachment').'; filename="'.$safe.'"; filename*=UTF-8\'\''.rawurlencode($name), 'Cache-Control' => 'private, no-store'], $path); }

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

  public function send(): void {
    if (!headers_sent()) {
      http_response_code($this->status);
      $nonce = View::nonce();
      $sec = ['X-Content-Type-Options' => 'nosniff', 'X-Frame-Options' => 'SAMEORIGIN', 'Referrer-Policy' => 'strict-origin-when-cross-origin', 'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), display-capture=(self)',
        'Content-Security-Policy' => "default-src 'self'; script-src 'self' 'nonce-$nonce'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com data:; img-src 'self' data: blob:; connect-src 'self'; frame-ancestors 'self'; form-action 'self'; base-uri 'self'; object-src 'none'"];
      if (Request::current()->isSecure()) { $sec['Strict-Transport-Security'] = 'max-age=31536000'; }
      foreach ($this->headers + $sec + ['Content-Type' => 'text/html; charset=utf-8'] as $k => $v) { header($k.': '.$v); } }
    if ($this->file) { while (ob_get_level()) { ob_end_clean(); } readfile($this->file); return; }
    echo $this->body; } }
