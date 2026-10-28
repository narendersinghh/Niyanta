<?php
namespace Core;

/**
 * Runs core/migrations then modules/<Name>/migrations in order. A migration file returns
 * ['up' => [sql, ...] | callable, 'down' => [sql, ...] | callable]. Disabled modules are still migrated
 * so their data and dependants stay intact.
 */
final class Migrator {
  public static function sources(): array {
    $src = ['core' => NY_ROOT.'/core/migrations'];
    foreach (Modules::discover() as $k => $m) { if (is_dir($m['dir'].'/migrations')) { $src[$k] = $m['dir'].'/migrations'; } }
    return $src; }

  public static function pending(): array {
    self::ensureTable();
    $done = array_fill_keys(DB::column('SELECT CONCAT(module, \'/\', migration) FROM migrations'), true);
    $out = [];
    foreach (self::sources() as $mod => $dir) {
      foreach (glob($dir.'/*.php') ?: [] as $f) {
        $n = basename($f, '.php');
        if (!isset($done["$mod/$n"])) { $out[] = ['module' => $mod, 'name' => $n, 'file' => $f]; } } }
    usort($out, static fn ($a, $b) => [$a['module'] !== 'core', $a['name']] <=> [$b['module'] !== 'core', $b['name']]);
    return $out; }

  /** @return string[] names of migrations run */
  public static function migrate(?callable $log = null): array {
    $batch = (int) DB::value('SELECT COALESCE(MAX(batch),0)+1 FROM migrations');
    $ran = [];
    foreach (self::pending() as $m) {
      $def = require $m['file'];
      $up = $def['up'];
      if (is_callable($up)) { $up(DB::pdo()); }
      else { foreach ((array) $up as $sql) { DB::pdo()->exec($sql); } }
      DB::insert('migrations', ['module' => $m['module'], 'migration' => $m['name'], 'batch' => $batch, 'ran_at' => date('Y-m-d H:i:s')]);
      $ran[] = $m['module'].'/'.$m['name'];
      if ($log) { $log($m['module'].'/'.$m['name']); } }
    return $ran; }

  public static function ensureTable(): void {
    DB::pdo()->exec('CREATE TABLE IF NOT EXISTS migrations (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, module VARCHAR(50) NOT NULL, migration VARCHAR(190) NOT NULL, batch INT NOT NULL, ran_at DATETIME NOT NULL, UNIQUE KEY uq_mig (module, migration)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'); } }
