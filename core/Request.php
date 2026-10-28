<?php
namespace Core;

final class Request {
  private static ?Request $current = null;
  private function __construct(private array $get, private array $post, private array $files, private array $server) {}

  public static function capture(): self { return self::$current = new self($_GET, $_POST, $_FILES, $_SERVER); }
  public static function current(): self { return self::$current ??= self::capture(); }

  public function method(): string { return strtoupper($this->server['REQUEST_METHOD'] ?? 'GET'); }
  public function uri(): string { return $this->server['REQUEST_URI'] ?? '/'; }
  public static function base(): string {
    $d = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
    return $d === '/' || $d === '.' ? '' : rtrim($d, '/'); }
  public function path(): string {
    $p = parse_url($this->uri(), PHP_URL_PATH) ?: '/';
    $b = self::base();
    if ($b !== '' && str_starts_with($p, $b)) { $p = substr($p, strlen($b)) ?: '/'; }
    if (str_starts_with($p, '/index.php')) { $p = substr($p, 10) ?: '/'; }
    return '/'.ltrim($p, '/'); }
  public function input(string $k, mixed $d = null): mixed { return $this->post[$k] ?? $this->get[$k] ?? $d; }
  public function query(string $k, mixed $d = null): mixed { return $this->get[$k] ?? $d; }
  public function str(string $k, string $d = ''): string { $v = $this->input($k, $d); return is_string($v) ? trim($v) : $d; }
  public function int(string $k, int $d = 0): int { $v = $this->input($k); return is_numeric($v) ? (int) $v : $d; }
  public function arr(string $k): array { $v = $this->input($k, []); return is_array($v) ? $v : []; }
  public function all(): array { return array_merge($this->get, $this->post); }
  public function only(array $keys): array { $a = $this->all(); return array_intersect_key($a, array_flip($keys)); }
  public function has(string $k): bool { return isset($this->post[$k]) || isset($this->get[$k]); }
  public function file(string $k): ?array {
    $f = $this->files[$k] ?? null;
    return is_array($f) && ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE && !is_array($f['name']) ? $f : null; }
  public function header(string $k): ?string { return $this->server['HTTP_'.strtoupper(str_replace('-', '_', $k))] ?? null; }
  public function isAjax(): bool { return $this->header('X-Requested-With') === 'XMLHttpRequest'; }
  public function wantsJson(): bool { return $this->isAjax() || str_contains((string) $this->header('Accept'), 'application/json'); }
  public function ip(): string { return (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0'); }
  public function userAgent(): string { return substr((string) ($this->server['HTTP_USER_AGENT'] ?? ''), 0, 255); }
  public function isSecure(): bool { return ($this->server['HTTPS'] ?? '') === 'on' || ($this->server['SERVER_PORT'] ?? '') == 443 || ($this->server['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'; }
  public function json(): array { $d = json_decode((string) file_get_contents('php://input'), true); return is_array($d) ? $d : []; } }
