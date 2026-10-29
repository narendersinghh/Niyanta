<?php
namespace Core;

/** Application kernel: loads config, boots services and dispatches the request. */
final class App {
  private static array $config = [];
  private static ?Router $router = null;

  public static function config(?string $key = null, mixed $default = null): mixed {
    if ($key === null) { return self::$config; }
    $v = self::$config;
    foreach (explode('.', $key) as $p) {
      if (!is_array($v) || !array_key_exists($p, $v)) { return $default; }
      $v = $v[$p]; }
    return $v; }

  public static function setConfig(array $c): void { self::$config = $c; }

  public static function installed(): bool { return is_file(NY_ROOT.'/config/config.php') && is_file(NY_ROOT.'/storage/installed.lock'); }

  public static function router(): Router { return self::$router ??= new Router(); }
  public static function resetRouter(): Router { return self::$router = new Router(); }

  /** Boot shared services (used by web, cron and tests). */
  public static function boot(): void {
    if (self::installed()) { self::$config = require NY_ROOT.'/config/config.php'; }
    date_default_timezone_set(self::config('timezone', 'UTC'));
    error_reporting(E_ALL);
    ini_set('display_errors', self::config('debug') ? '1' : '0');
    ini_set('log_errors', '1');
    ini_set('error_log', NY_ROOT.'/storage/logs/php-'.date('Y-m').'.log');
    if (self::installed()) {
      DB::init(self::config('db'));
      if (date_default_timezone_get() !== ($tz = (string) Settings::get('general.timezone', self::config('timezone', 'UTC')))) { date_default_timezone_set($tz); DB::syncTimezone(); }
      Modules::load(); } }

  public static function runHttp(): void {
    $req = Request::capture();
    set_exception_handler(static fn (\Throwable $e) => self::handleException($e, $req));
    self::boot();
    Session::start();
    $r = self::router();
    if (!self::installed()) { Installer::routes($r); }
    else {
      if (is_file(NY_ROOT.'/storage/maintenance.flag') && !str_starts_with($req->path(), '/login') && !(Auth::check() && Auth::user()['role_slug'] === 'super_admin')) {
        (new Response(View::render('errors/maintenance', [], 'layouts/blank'), 503))->send(); return; }
      require NY_ROOT.'/core/routes.php';
      Modules::registerRoutes($r); }
    $r->dispatch($req)->send();
    if (self::installed()) { AutoCron::afterResponse(); } }

  public static function handleException(\Throwable $e, ?Request $req = null): void {
    $id = substr(bin2hex(random_bytes(4)), 0, 8);
    error_log("[$id] ".$e::class.': '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine()."\n".$e->getTraceAsString());
    if (PHP_SAPI === 'cli') { fwrite(STDERR, "[$id] ".$e->getMessage()."\n"); return; }
    $debug = (bool) self::config('debug');
    if ($req && $req->wantsJson()) { (new Response(json_encode(['error' => $debug ? $e->getMessage() : 'Server error', 'ref' => $id]), 500, ['Content-Type' => 'application/json']))->send(); return; }
    try { $html = View::render('errors/500', ['ref' => $id, 'debug' => $debug ? $e : null], 'layouts/blank'); }
    catch (\Throwable) { $html = '<h1>Server error</h1><p>Reference '.$id.'</p>'; }
    (new Response($html, 500))->send(); } }
