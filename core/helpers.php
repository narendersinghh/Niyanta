<?php
use Core\{Auth, Csrf, Gate, Request, Session, Settings, View};

function e(mixed $v): string { return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function url(string $path = '/'): string { return Request::base().'/'.ltrim($path, '/'); }
function absolute_url(string $path = '/'): string {
  $base = rtrim((string) Core\App::config('app_url', ''), '/');
  if ($base === '') { $r = Request::current(); $base = ($r->isSecure() ? 'https' : 'http').'://'.($_SERVER['HTTP_HOST'] ?? 'localhost').Request::base(); }
  return str_starts_with($path, 'http') ? $path : $base.'/'.ltrim(preg_replace('#^'.preg_quote(Request::base(), '#').'#', '', $path), '/'); }
function asset(string $path): string { $f = NY_ROOT.'/assets/'.ltrim($path, '/'); return url('assets/'.ltrim($path, '/')).(is_file($f) ? '?v='.filemtime($f) : ''); }
function csrf_token(): string { return Csrf::token(); }
function csrf_field(): string { return '<input type="hidden" name="_token" value="'.e(Csrf::token()).'">'; }
function nonce(): string { return View::nonce(); }
function user(): ?array { return Auth::user(); }
function can(string $perm): bool { return Gate::allows($perm); }
function setting(string $k, mixed $d = null): mixed { return Settings::get($k, $d); }
function old(string $k, mixed $d = ''): mixed { $o = Session::getFlash('old', []); return $o[$k] ?? $d; }
function errors(): array { return Session::getFlash('errors', []); }
function error_for(string $k): string { $e = errors()[$k] ?? null; return $e ? '<div class="field-error">'.e($e).'</div>' : ''; }
function flash(string $msg, string $type = 'success'): void { Session::flash('toast', ['type' => $type, 'msg' => $msg]); }
function partial(string $name, array $data = []): string { return View::partial($name, $data); }
function selected(mixed $a, mixed $b): string { return (string) $a === (string) $b ? ' selected' : ''; }
function checked(mixed $v): string { return $v ? ' checked' : ''; }

/** Inline SVG icon from the sprite. */
function icon(string $name, string $class = ''): string { return '<svg class="ic '.e($class).'" aria-hidden="true"><use href="'.e(asset('img/icons.svg')).'#'.e($name).'"/></svg>'; }

/** Inline cartoon illustration (assets/img/illustrations/<name>.svg). */
function illus(string $name, string $class = ''): string {
  static $cache = [];
  $f = NY_ROOT.'/assets/img/illustrations/'.basename($name).'.svg';
  if (!isset($cache[$name])) { $cache[$name] = is_file($f) ? (string) file_get_contents($f) : ''; }
  return '<div class="illus '.e($class).'" aria-hidden="true">'.$cache[$name].'</div>'; }

function currency_symbol(): string { return (string) setting('general.currency_symbol', '₹'); }
function money(mixed $v, bool $symbol = true): string {
  $n = number_format((float) $v, 2, '.', setting('general.thousand_sep', ','));
  return $symbol ? currency_symbol().$n : $n; }
function fdate(?string $d, ?string $fmt = null): string { if (!$d || str_starts_with($d, '0000')) { return '-'; } return date($fmt ?? setting('general.date_format', 'd M Y'), strtotime($d)); }
function fdatetime(?string $d): string { return $d ? fdate($d, setting('general.date_format', 'd M Y').' '.setting('general.time_format', 'h:i A')) : '-'; }
function ftime(?string $d): string { return $d ? date(setting('general.time_format', 'h:i A'), strtotime($d)) : '-'; }
function hours(mixed $seconds): string { $s = (int) $seconds; return sprintf('%dh %02dm', intdiv($s, 3600), intdiv($s % 3600, 60)); }
function ago(?string $d): string {
  if (!$d) { return '-'; }
  $s = time() - strtotime($d);
  if ($s < 60) { return 'just now'; }
  foreach ([31536000 => 'y', 2592000 => 'mo', 604800 => 'w', 86400 => 'd', 3600 => 'h', 60 => 'm'] as $u => $l) { if ($s >= $u) { return intdiv($s, $u).$l.' ago'; } }
  return ''; }

function initials(string $name): string { $p = preg_split('/\s+/', trim($name)) ?: ['?']; return mb_strtoupper(mb_substr($p[0], 0, 1).(isset($p[1]) ? mb_substr($p[1], 0, 1) : '')); }
/** Avatar circle; colour derived from the name. */
function avatar(?string $name, ?string $img = null, string $size = ''): string {
  $name ??= '?';
  $palette = ['lav', 'mint', 'lemon', 'peach', 'sky', 'pink'];
  $c = $palette[crc32($name) % count($palette)];
  if ($img) { return '<span class="avatar '.$size.'"><img src="'.e(url('avatar/'.basename($img))).'" alt="'.e($name).'"></span>'; }
  return '<span class="avatar bg-'.$c.' '.$size.'" title="'.e($name).'">'.e(initials($name)).'</span>'; }

/** Status pill with a colour mapped from common statuses. */
function badge(?string $status, ?string $label = null): string {
  $map = ['active' => 'mint', 'done' => 'mint', 'paid' => 'mint', 'approved' => 'mint', 'completed' => 'mint', 'accepted' => 'mint', 'present' => 'mint', 'success' => 'mint', 'finalized' => 'mint', 'won' => 'mint',
    'in_progress' => 'sky', 'sent' => 'sky', 'submitted' => 'sky', 'review' => 'lav', 'partial' => 'lemon', 'pending' => 'lemon', 'draft' => 'gray', 'todo' => 'gray', 'lead' => 'lav', 'on_hold' => 'lemon', 'planning' => 'lav',
    'overdue' => 'red', 'rejected' => 'red', 'cancelled' => 'red', 'failed' => 'red', 'inactive' => 'gray', 'absent' => 'red', 'urgent' => 'red', 'high' => 'peach', 'medium' => 'lemon', 'low' => 'mint', 'expired' => 'red', 'lost' => 'red'];
  $s = (string) $status;
  return '<span class="pill pill-'.($map[$s] ?? 'gray').'">'.e($label ?? ucwords(str_replace('_', ' ', $s))).'</span>'; }

/** Pagination links preserving the current query string. */
function pager(array $p): string {
  if (($p['pages'] ?? 1) <= 1) { return ''; }
  $q = $_GET;
  $link = static function (int $n, string $label, bool $active = false, bool $disabled = false) use ($q): string {
    $q['page'] = $n;
    return $disabled ? '<span class="pg disabled">'.$label.'</span>' : '<a class="pg'.($active ? ' active' : '').'" href="?'.e(http_build_query($q)).'">'.$label.'</a>'; };
  $h = '<nav class="pager"><span class="muted">'.(int) $p['total'].' records</span><div>'.$link($p['page'] - 1, '&lsaquo;', false, $p['page'] <= 1);
  for ($i = max(1, $p['page'] - 2); $i <= min($p['pages'], $p['page'] + 2); $i++) { $h .= $link($i, (string) $i, $i === $p['page']); }
  return $h.$link($p['page'] + 1, '&rsaquo;', false, $p['page'] >= $p['pages']).'</div></nav>'; }

/** Empty state with illustration. */
function empty_state(string $title, string $text = '', string $illus = 'empty-box', string $actionHtml = ''): string {
  return '<div class="empty">'.illus($illus, 'empty-illus').'<h3>'.e($title).'</h3>'.($text ? '<p class="muted">'.e($text).'</p>' : '').$actionHtml.'</div>'; }

/** WhatsApp number of a person row: their own WhatsApp number, else their phone. */
function wa_number(array $u): ?string { return ($u['whatsapp'] ?? null) ?: ($u['phone'] ?? null); }
function wa_link(?string $phone, string $text = ''): string { $d = preg_replace('/\D+/', '', (string) $phone); return 'https://wa.me/'.$d.($text ? '?text='.rawurlencode($text) : ''); }
function str_limit(?string $s, int $n = 80): string { $s = (string) $s; return mb_strlen($s) > $n ? mb_substr($s, 0, $n - 1).'…' : $s; }
function query_merge(array $add): string { return '?'.http_build_query(array_merge($_GET, $add)); }

/** Product name is fixed; the customer's company identity is configurable. */
function app_name(): string { return 'Niyanta'; }
function company_name(): string {
  $n = trim((string) setting('company.name', ''));
  if ($n === '') { $legacy = trim((string) setting('branding.app_name', '')); $n = $legacy !== '' && $legacy !== 'Niyanta' ? $legacy : ''; }
  return $n !== '' ? $n : 'Your Company'; }
/** Uploaded company logo URL or null. */
function company_logo_url(): ?string { $l = setting('branding.logo'); return $l ? url('assets/uploads/branding/'.basename((string) $l)) : null; }
/** True when a product feature (core/features.php) is switched on. Use to hide cards, links and options of disabled features. */
function feature_on(string $feature): bool { return Core\Features::enabled($feature); }
/** False when the page at $path belongs to a switched-off module or feature (works in web, cron and tests). */
function route_on(string $path): bool {
  static $cli = null;
  $r = Core\App::router();
  if (!$r->hasRoutes()) { if (!$cli) { $cli = new Core\Router(); Core\Modules::registerRoutes($cli); } $r = $cli; }
  return $r->reachable($path); }

/** The signed-in person's profile: the HR profile (with an Account tab) when the employee directory is on, otherwise the account page. */
function profile_url(): string { return feature_on('employees') && can('hr.directory.view') ? '/hr/employees/'.(int) (user()['id'] ?? 0) : '/account'; }
