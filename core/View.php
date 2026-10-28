<?php
namespace Core;

/**
 * Plain PHP templates. "crm::clients/index" resolves to modules/Crm/views/clients/index.php,
 * "dashboard/index" to views/dashboard/index.php. Always escape output with e().
 */
final class View {
  private static ?string $nonce = null;
  private static array $shared = [];

  public static function nonce(): string { return self::$nonce ??= base64_encode(random_bytes(16)); }
  public static function share(string $k, mixed $v): void { self::$shared[$k] = $v; }

  public static function path(string $name): string {
    if (str_contains($name, '::')) {
      [$mod, $rest] = explode('::', $name, 2);
      $f = Modules::path($mod).'/views/'.$rest.'.php'; }
    else { $f = NY_ROOT.'/views/'.$name.'.php'; }
    if (!is_file($f)) { throw new \RuntimeException("View not found: $name"); }
    return $f; }

  public static function partial(string $name, array $data = []): string {
    extract(self::$shared + $data, EXTR_SKIP);
    ob_start();
    try { include self::path($name); }
    catch (\Throwable $e) { ob_end_clean(); throw $e; }
    return (string) ob_get_clean(); }

  public static function render(string $name, array $data = [], ?string $layout = 'layouts/app'): string {
    $content = self::partial($name, $data);
    return $layout ? self::partial($layout, $data + ['content' => $content]) : $content; } }
