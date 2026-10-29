<?php
namespace Core;

/**
 * Module registry. Each modules/<Name>/module.php returns a manifest:
 * key, name, description, icon, version, requires[], core(bool), permissions[key => [label, roles[]]],
 * nav[] (label, icon, url, perm, group, order), routes(Router), widgets[] (callable(user) => html),
 * search(callable(string q, array user) => results[]), cron[] (name => [every minutes | daily "HH:MM", callable]),
 * file_access[entity_type => callable(array user, int entityId, array file) => bool], settings_view (view name)
 */
final class Modules {
  private static array $manifests = [];
  private static array $state = [];

  public static function discover(): array {
    $out = [];
    foreach (glob(NY_ROOT.'/modules/*/module.php') ?: [] as $f) {
      $m = require $f;
      $m['dir'] = dirname($f);
      $m['namespace'] = 'Modules\\'.basename(dirname($f));
      $out[$m['key']] = $m + ['requires' => [], 'core' => false, 'permissions' => [], 'nav' => [], 'widgets' => [], 'cron' => [], 'file_access' => [], 'order' => 50]; }
    uasort($out, static fn ($a, $b) => $a['order'] <=> $b['order']);
    return $out; }

  public static function load(): void {
    self::$manifests = self::discover();
    try { self::$state = Cache::remember('modules_state', 0, static fn () => DB::pairs('SELECT `key`, enabled FROM modules')); }
    catch (\Throwable) { self::$state = []; } }

  public static function all(): array { return self::$manifests; }
  public static function get(string $k): ?array { return self::$manifests[$k] ?? null; }
  public static function exists(string $k): bool { return isset(self::$manifests[$k]); }
  public static function enabled(string $k): bool {
    if (!isset(self::$manifests[$k])) { return false; }
    if (!empty(self::$manifests[$k]['core'])) { return true; }
    if ($fs = Features::moduleFeatures($k)) { foreach ($fs as $f) { if (Features::enabled($f)) { return true; } } return false; }
    return !empty(self::$state[$k]); }
  public static function path(string $k): string {
    if (!isset(self::$manifests[$k])) { throw new \RuntimeException("Unknown module $k"); }
    return self::$manifests[$k]['dir']; }

  public static function setEnabled(string $k, bool $on): void {
    foreach (Features::moduleFeatures($k) as $f) { Features::set('feature:'.$f, $on); }
    DB::query('INSERT INTO modules (`key`, enabled, updated_at) VALUES (?,?,NOW()) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled), updated_at=NOW()', [$k, (int) $on]);
    Cache::forget('modules_state');
    self::$state[$k] = (int) $on; }

  public static function registerRoutes(Router $r): void {
    foreach (self::$manifests as $k => $m) { if (isset($m['routes'])) { $r->group(['module' => empty($m['core']) ? $k : null], $m['routes']); } } }

  /** Sidebar sections (label null = no header; collapsible sections start closed unless they hold the active page). */
  public const SECTIONS = ['main' => [null, false], 'work' => ['Work', false], 'clients' => ['Clients', false], 'me' => ['My HR', true], 'hr' => ['HR Admin', true], 'system' => ['System', true]];

  /** Navigation entries visible to the current user grouped by section. */
  public static function nav(): array {
    $legacy = ['Main' => 'main', 'Work' => 'work', 'Clients' => 'clients', 'Finance' => 'clients', 'People' => 'me', 'System' => 'system'];
    $out = array_fill_keys(array_keys(self::SECTIONS), []);
    foreach (self::$manifests as $k => $m) {
      if (!self::enabled($k)) { continue; }
      foreach ($m['nav'] as $n) {
        if (!empty($n['perm']) && !Gate::any((array) $n['perm'])) { continue; }
        if (!empty($n['hide_if']) && Gate::any((array) $n['hide_if']) && !Auth::isSuperAdmin()) { continue; }
        if (!empty($n['hide_if']) && Auth::isSuperAdmin()) { continue; }
        $sec = $n['section'] ?? ($legacy[$n['group'] ?? 'Main'] ?? 'work');
        $out[$sec][] = $n + ['order' => 50, 'module' => $k, 'color' => '#8B85C9']; } }
    foreach ($out as &$g) { usort($g, static fn ($a, $b) => $a['order'] <=> $b['order']); }
    return array_filter($out); }

  /** Collect a hook (widgets, search ...) from enabled modules. */
  public static function hooks(string $name): array {
    $out = [];
    foreach (self::$manifests as $k => $m) { if (self::enabled($k) && !empty($m[$name])) { $out[$k] = $m[$name]; } }
    return $out; }

  /** Insert newly declared permissions and grant their default roles (never revokes customised grants). */
  public static function syncPermissions(): int {
    $known = array_fill_keys(DB::column('SELECT `key` FROM permissions'), true);
    $roles = DB::pairs('SELECT slug, id FROM roles');
    $n = 0;
    foreach (self::discover() as $k => $m) {
      foreach ($m['permissions'] as $perm => $def) {
        if (isset($known[$perm])) { DB::update('permissions', ['label' => $def['label'], 'module' => $k], '`key`=?', [$perm]); continue; }
        DB::insert('permissions', ['key' => $perm, 'module' => $k, 'label' => $def['label']]);
        foreach ($def['roles'] ?? [] as $slug) { if (isset($roles[$slug])) { DB::query('INSERT IGNORE INTO role_permissions (role_id, permission) VALUES (?,?)', [$roles[$slug], $perm]); } }
        $n++; } }
    Gate::flush();
    return $n; } }
