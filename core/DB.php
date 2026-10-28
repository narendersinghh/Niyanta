<?php
namespace Core;
use PDO;

/** Thin PDO wrapper. All values are bound; identifiers are validated. */
final class DB {
  private static ?PDO $pdo = null;
  private static array $cfg = [];
  public static int $queries = 0;

  public static function init(array $cfg): void { self::$cfg = $cfg; self::$pdo = null; }

  public static function connect(array $c): PDO {
    $dsn = 'mysql:host='.$c['host'].';port='.(int) ($c['port'] ?? 3306).';dbname='.$c['name'].';charset=utf8mb4';
    $pdo = new PDO($dsn, $c['user'], $c['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_STRINGIFY_FETCHES => false]);
    $pdo->exec("SET NAMES utf8mb4, time_zone='".(new \DateTime())->format('P')."', sql_mode='STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION'");
    return $pdo; }

  public static function pdo(): PDO {
    if (!self::$pdo && !self::$cfg) { throw new \RuntimeException('Database is not configured.'); }
    return self::$pdo ??= self::connect(self::$cfg); }
  public static function syncTimezone(): void { if (self::$pdo) { self::$pdo->exec("SET time_zone='".(new \DateTime())->format('P')."'"); } }

  public static function query(string $sql, array $p = []): \PDOStatement {
    self::$queries++;
    $st = self::pdo()->prepare($sql);
    foreach (array_values($p) as $i => $v) { $st->bindValue($i + 1, is_bool($v) ? (int) $v : $v, is_int($v) || is_bool($v) ? PDO::PARAM_INT : ($v === null ? PDO::PARAM_NULL : PDO::PARAM_STR)); }
    $st->execute();
    return $st; }

  public static function all(string $sql, array $p = []): array { return self::query($sql, $p)->fetchAll(); }
  public static function first(string $sql, array $p = []): ?array { $r = self::query($sql, $p)->fetch(); return $r === false ? null : $r; }
  public static function value(string $sql, array $p = []): mixed { $r = self::query($sql, $p)->fetchColumn(); return $r === false ? null : $r; }
  public static function column(string $sql, array $p = []): array { return self::query($sql, $p)->fetchAll(PDO::FETCH_COLUMN); }
  /** Key => value pairs from first two columns. */
  public static function pairs(string $sql, array $p = []): array { return self::query($sql, $p)->fetchAll(PDO::FETCH_KEY_PAIR); }
  public static function find(string $table, int $id): ?array { return self::first('SELECT * FROM '.self::id($table).' WHERE id=? LIMIT 1', [$id]); }

  public static function insert(string $table, array $data): int {
    $cols = array_map([self::class, 'id'], array_keys($data));
    self::query('INSERT INTO '.self::id($table).' ('.implode(',', $cols).') VALUES ('.implode(',', array_fill(0, count($data), '?')).')', array_values($data));
    return (int) self::pdo()->lastInsertId(); }

  /** Insert many rows sharing the same columns in one statement. */
  public static function insertMany(string $table, array $rows): void {
    if (!$rows) { return; }
    $cols = array_map([self::class, 'id'], array_keys(reset($rows)));
    $ph = '('.implode(',', array_fill(0, count($cols), '?')).')';
    foreach (array_chunk($rows, 200) as $chunk) {
      $vals = [];
      foreach ($chunk as $r) { array_push($vals, ...array_values($r)); }
      self::query('INSERT INTO '.self::id($table).' ('.implode(',', $cols).') VALUES '.implode(',', array_fill(0, count($chunk), $ph)), $vals); } }

  /** $where uses ? placeholders, e.g. update('users', [...], 'id=?', [5]) */
  public static function update(string $table, array $data, string $where, array $p = []): int {
    $set = implode(',', array_map(static fn ($c) => self::id($c).'=?', array_keys($data)));
    return self::query('UPDATE '.self::id($table).' SET '.$set.' WHERE '.$where, [...array_values($data), ...$p])->rowCount(); }

  public static function delete(string $table, string $where, array $p = []): int { return self::query('DELETE FROM '.self::id($table).' WHERE '.$where, $p)->rowCount(); }

  public static function transaction(callable $fn): mixed {
    $pdo = self::pdo();
    if ($pdo->inTransaction()) { return $fn(); }
    $pdo->beginTransaction();
    try { $r = $fn(); $pdo->commit(); return $r; }
    catch (\Throwable $e) { $pdo->rollBack(); throw $e; } }

  /** Server-side pagination. Returns items, total, page, pages, per. */
  public static function paginate(string $sql, array $p = [], int $per = 20, ?int $page = null): array {
    $page = max(1, $page ?? (int) ($_GET['page'] ?? 1));
    $total = (int) self::value('SELECT COUNT(*) FROM ('.$sql.') t', $p);
    $pages = max(1, (int) ceil($total / $per));
    $page = min($page, $pages);
    $items = self::all($sql.' LIMIT '.$per.' OFFSET '.(($page - 1) * $per), $p);
    return compact('items', 'total', 'page', 'pages', 'per'); }

  /** Build "IN (?,?,?)" placeholder list. */
  public static function in(array $vals): string { return '('.($vals ? implode(',', array_fill(0, count($vals), '?')) : 'NULL').')'; }

  public static function id(string $name): string {
    if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) { throw new \InvalidArgumentException('Invalid identifier'); }
    return '`'.$name.'`'; }

  public static function like(string $s): string { return '%'.addcslashes($s, '%_\\').'%'; } }
