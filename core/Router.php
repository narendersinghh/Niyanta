<?php
namespace Core;

/** Minimal router with auth, permission, module and CSRF guards applied server-side on every route. */
final class Router {
  private array $routes = [];
  private ?string $module = null;
  private array $groupOpts = [];

  public function get(string $p, callable|array $h, array|string $o = []): self { return $this->add('GET', $p, $h, $o); }
  public function post(string $p, callable|array $h, array|string $o = []): self { return $this->add('POST', $p, $h, $o); }
  public function any(string $p, callable|array $h, array|string $o = []): self { $this->add('GET', $p, $h, $o); return $this->add('POST', $p, $h, $o); }

  /** Options: perm (string|array any-of), auth (bool, default true), csrf (bool, default true), module (string), throttle ([max, seconds]) */
  public function add(string $m, string $p, callable|array $h, array|string $o = []): self {
    if (is_string($o)) { $o = ['perm' => $o]; }
    $o += $this->groupOpts + ['auth' => true, 'csrf' => true, 'module' => $this->module];
    $rx = '#^'.preg_replace_callback('#\{(\w+)(\?)?\}#', static fn ($x) => $x[1] === 'id' || str_ends_with($x[1], '_id') ? '(?P<'.$x[1].'>\d+)' : '(?P<'.$x[1].'>[^/]+)', rtrim($p, '/') ?: '/').'$#';
    $this->routes[$m][] = [$rx, $h, $o];
    return $this; }

  public function group(array $opts, callable $fn): void {
    $prev = [$this->module, $this->groupOpts];
    if (isset($opts['module'])) { $this->module = $opts['module']; unset($opts['module']); }
    $this->groupOpts = $opts + $this->groupOpts;
    $fn($this);
    [$this->module, $this->groupOpts] = $prev; }

  public function dispatch(Request $req): Response {
    $path = rtrim($req->path(), '/') ?: '/';
    foreach ($this->routes[$req->method()] ?? [] as [$rx, $h, $o]) {
      if (!preg_match($rx, $path, $mm)) { continue; }
      $params = array_filter($mm, 'is_string', ARRAY_FILTER_USE_KEY);
      if ($o['csrf'] && $req->method() === 'POST' && !Csrf::verify($req)) { return Response::error(419, 'Your session expired. Please refresh and try again.', $req); }
      if (!empty($o['throttle']) && App::installed() && !RateLimiter::hit('route:'.$path.':'.$req->ip(), $o['throttle'][0], $o['throttle'][1])) { return Response::error(429, 'Too many requests. Please slow down.', $req); }
      if ($o['auth']) {
        if (!Auth::check()) { return $req->wantsJson() ? Response::json(['error' => 'Unauthenticated'], 401) : Response::redirect(url('/login').'?next='.rawurlencode($req->uri())); }
        if (!empty($o['module']) && !Modules::enabled($o['module'])) { return Response::error(403, 'This module has been disabled by the administrator.', $req, 'module'); }
        if (!empty($o['perm']) && !Gate::any((array) $o['perm'])) { Audit::log('access.denied', null, null, ['path' => $path]); return Response::error(403, 'You do not have permission to access this page.', $req); } }
      try { $res = is_array($h) ? (new $h[0]($req))->{$h[1]}(...array_values($params)) : $h($req, ...array_values($params)); }
      catch (HttpException $e) { return Response::error($e->status, $e->getMessage() ?: 'Request failed.', $req); }
      catch (ValidationException $e) {
        if ($req->wantsJson()) { return Response::json(['errors' => $e->errors, 'error' => reset($e->errors)], 422); }
        Session::flash('errors', $e->errors);
        Session::flash('old', array_diff_key($req->all(), array_flip(['password', 'password_confirmation', '_token', 'current_password'])));
        return Response::back(); }
      return $res instanceof Response ? $res : new Response((string) $res); }
    return Response::error(404, 'The page you were looking for could not be found.', $req); } }
