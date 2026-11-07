<?php
namespace Core;

/** Bundles & features (see core/features.php). State lives in the `modules` table as "bundle:<key>" / "feature:<key>". The pseudo feature "ai" is the AI assistant switch (ai.enabled). */
final class Features {
  private static ?array $defs = null;
  private static ?array $state = null;
  private static ?array $prefixes = null;

  public static function bundles(): array { return self::$defs ??= require __DIR__.'/features.php'; }

  private static function state(): array {
    if (self::$state !== null) { return self::$state; }
    try { return self::$state = Cache::remember('features_state', 0, static fn () => DB::pairs("SELECT `key`, enabled FROM modules WHERE `key` LIKE 'bundle:%' OR `key` LIKE 'feature:%'")); }
    catch (\Throwable) { return self::$state = []; } }

  public static function bundleEnabled(string $b): bool { return (int) (self::state()['bundle:'.$b] ?? 1) === 1; }

  public static function find(string $feature): ?array {
    foreach (self::bundles() as $bk => $b) { if (isset($b['features'][$feature])) { return $b['features'][$feature] + ['bundle' => $bk, 'key' => $feature]; } }
    return null; }

  public static function enabled(string $feature): bool {
    if ($feature === 'ai') { return Modules::enabled('ai') && Settings::bool('ai.enabled'); }
    $f = self::find($feature);
    if (!$f) { return true; }
    foreach ($f['requires'] ?? [] as $r) { if (!self::enabled($r)) { return false; } }
    return self::bundleEnabled($f['bundle']) && (int) (self::state()['feature:'.$feature] ?? 1) === 1; }

  /** Feature owning a permission (longest matching prefix), or null when not feature-governed. */
  public static function forPermission(string $perm): ?string {
    if (self::$prefixes === null) {
      self::$prefixes = [];
      foreach (self::bundles() as $b) { foreach ($b['features'] as $fk => $f) { foreach ($f['perms'] as $p) { self::$prefixes[$p] = $fk; } } }
      uksort(self::$prefixes, static fn ($a, $b) => strlen($b) <=> strlen($a)); }
    foreach (self::$prefixes as $p => $fk) { if ($perm === $p || str_starts_with($perm, $p.'.')) { return $fk; } }
    return null; }

  public static function permEnabled(string $perm): bool { $f = self::forPermission($perm); return $f === null || self::enabled($f); }

  /** A code module is active when at least one of its features is enabled (modules without features use their own flag). */
  public static function moduleFeatures(string $module): array {
    $out = [];
    foreach (self::bundles() as $b) { foreach ($b['features'] as $fk => $f) { if ($f['module'] === $module) { $out[] = $fk; } } }
    return $out; }

  public static function set(string $key, bool $on): void {
    DB::query('INSERT INTO modules (`key`, enabled, updated_at) VALUES (?,?,NOW()) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled), updated_at=NOW()', [$key, (int) $on]);
    Cache::forget('features_state');
    Cache::forget('modules_state');
    self::$state = null; } }
