<?php
namespace Modules\Admin\Services;
use Core\{Audit, Cache, DB, Settings};

/**
 * Pure-PHP backups (no mysqldump/exec needed). Database dumps are streamed table by table, chunked by primary key and
 * gzip-compressed; "full" backups zip the dump with storage/uploads, assets/uploads and config/config.php.
 * The backup folder lives in storage/ which is never web-accessible. Downloads go through an authorised controller.
 */
final class BackupService {
  private const CHUNK = 1000;
  private const STMT_BYTES = 1048576;

  public static function dir(): string {
    $d = NY_ROOT.'/storage/backups';
    if (!is_dir($d)) { @mkdir($d, 0775, true); }
    if (!is_file($d.'/.htaccess')) { @file_put_contents($d.'/.htaccess', "Require all denied\nDeny from all\n"); }
    return $d; }

  public static function path(array $row): string { return self::dir().'/'.basename((string) $row['filename']); }

  public static function maintenance(bool $on): void {
    $f = NY_ROOT.'/storage/maintenance.flag';
    if ($on) { file_put_contents($f, date('c')); }
    elseif (is_file($f)) { @unlink($f); } }

  /** Cron entry point: respects backups.enabled and backups.include_uploads. */
  public static function scheduled(): string {
    if (!Settings::bool('backups.enabled', true)) { return 'disabled'; }
    $type = Settings::bool('backups.include_uploads') && class_exists(\ZipArchive::class) ? 'full' : 'database';
    $b = self::create($type, 'Scheduled backup');
    return $b['status'] === 'success' ? $b['filename'].' ('.self::size((int) $b['size']).')' : 'failed: '.$b['note']; }

  /** Create a backup. Never throws; returns the backups row (status success|failed). */
  public static function create(string $type = 'database', ?string $note = null, ?int $by = null, bool $prune = true): array {
    $type = $type === 'full' ? 'full' : 'database';
    @set_time_limit(0);
    $lock = fopen(self::dir().'/.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { return ['id' => 0, 'status' => 'failed', 'filename' => '', 'size' => 0, 'note' => 'Another backup is already running.']; }
    $stamp = date('Ymd-His').'-'.bin2hex(random_bytes(3));
    $sql = self::dir().'/niyanta-db-'.$stamp.'.sql.gz';
    $file = $type === 'full' ? self::dir().'/niyanta-full-'.$stamp.'.zip' : $sql;
    $id = DB::insert('backups', ['filename' => basename($file), 'type' => $type, 'status' => 'running', 'note' => $note === null ? null : mb_substr($note, 0, 255), 'created_by' => $by, 'created_at' => date('Y-m-d H:i:s')]);
    try {
      $stats = self::dumpDatabase($sql);
      if ($type === 'full') { self::zipFull($file, $sql, $stats); @unlink($sql); }
      clearstatcache();
      DB::update('backups', ['status' => 'success', 'size' => filesize($file), 'sha256' => hash_file('sha256', $file)], 'id=?', [$id]);
      if ($prune) { self::prune(); } }
    catch (\Throwable $e) {
      @unlink($sql); @unlink($file);
      error_log('Backup failed: '.$e);
      DB::update('backups', ['status' => 'failed', 'note' => mb_substr(($note ? $note.', ' : '').'Error: '.$e->getMessage(), 0, 255)], 'id=?', [$id]); }
    finally { flock($lock, LOCK_UN); fclose($lock); }
    $row = DB::find('backups', $id);
    Audit::log('backup.create', 'backup', $id, ['type' => $type, 'status' => $row['status'], 'file' => $row['filename']], $by);
    return $row; }

  /** Delete older successful backups beyond backups.keep, and failed rows older than a week. */
  public static function prune(): int {
    $keep = max(1, (int) Settings::get('backups.keep', 7));
    $n = 0;
    foreach (DB::all("SELECT * FROM backups WHERE status='success' ORDER BY id DESC LIMIT 1000 OFFSET ".$keep) as $old) { @unlink(self::path($old)); DB::delete('backups', 'id=?', [$old['id']]); $n++; }
    $n += DB::delete('backups', "status='failed' AND created_at < ?", [date('Y-m-d H:i:s', strtotime('-7 days'))]);
    return $n; }

  public static function delete(array $row): void { @unlink(self::path($row)); DB::delete('backups', 'id=?', [$row['id']]); }

  /** Stream a consistent, gzip-compressed SQL dump of every table and view. */
  public static function dumpDatabase(string $gzPath): array {
    $pdo = DB::pdo();
    $gz = gzopen($gzPath, 'wb6');
    if (!$gz) { throw new \RuntimeException('Cannot write '.basename($gzPath)); }
    $w = static function (string $s) use ($gz): void { if (gzwrite($gz, $s) === false) { throw new \RuntimeException('Write failed (disk full?)'); } };
    $db = (string) DB::value('SELECT DATABASE()');
    $w("-- Niyanta database backup\n-- Version: ".NY_VERSION."\n-- Database: $db\n-- Server: ".DB::value('SELECT VERSION()')."\n-- Created: ".date('c')."\n\n");
    $w("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET UNIQUE_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\nSET time_zone='+00:00';\n\n");
    $pdo->exec("SET time_zone='+00:00'");
    $tables = []; $views = [];
    foreach ($pdo->query('SHOW FULL TABLES')->fetchAll(\PDO::FETCH_NUM) as $t) { if ($t[1] === 'VIEW') { $views[] = $t[0]; } else { $tables[] = $t[0]; } }
    $rows = 0;
    try {
      foreach ($tables as $t) {
        $q = '`'.str_replace('`', '``', $t).'`';
        $create = $pdo->query("SHOW CREATE TABLE $q")->fetch(\PDO::FETCH_NUM)[1];
        $w("--\n-- Table $q\n--\nDROP TABLE IF EXISTS $q;\n$create;\n\n");
        $rows += self::dumpRows($pdo, $t, $q, $w);
        $w("\n"); }
      foreach ($views as $v) {
        $q = '`'.str_replace('`', '``', $v).'`';
        $create = preg_replace('/\sDEFINER=`[^`]*`@`[^`]*`/', '', (string) $pdo->query("SHOW CREATE VIEW $q")->fetch(\PDO::FETCH_NUM)[1]);
        $w("DROP VIEW IF EXISTS $q;\n$create;\n\n"); } }
    finally { DB::syncTimezone(); }
    $w("SET FOREIGN_KEY_CHECKS=1;\nSET UNIQUE_CHECKS=1;\n-- Completed ".date('c')."\n");
    if (!gzclose($gz)) { throw new \RuntimeException('Could not finalise the dump file.'); }
    return ['tables' => count($tables), 'views' => count($views), 'rows' => $rows]; }

  private static function dumpRows(\PDO $pdo, string $t, string $q, callable $w): int {
    $cols = array_values(array_filter($pdo->query("SHOW COLUMNS FROM $q")->fetchAll(), static fn ($c) => !preg_match('/GENERATED/i', (string) $c['Extra'])));
    $bin = []; $names = [];
    foreach ($cols as $c) { $names[] = '`'.str_replace('`', '``', $c['Field']).'`'; $bin[] = (bool) preg_match('/blob|binary|bit|geometry|point|polygon/i', $c['Type']); }
    $pk = array_column($pdo->query("SHOW KEYS FROM $q WHERE Key_name='PRIMARY'")->fetchAll(), 'Column_name');
    $sel = implode(',', $names);
    $single = count($pk) === 1;
    $order = $pk ? ' ORDER BY '.implode(',', array_map(static fn ($c) => '`'.str_replace('`', '``', $c).'`', $pk)) : '';
    $head = "INSERT INTO $q (".implode(',', $names).') VALUES ';
    $last = null; $offset = 0; $total = 0;
    while (true) {
      if ($single && $last !== null) { $st = $pdo->prepare("SELECT $sel FROM $q WHERE `".str_replace('`', '``', $pk[0]).'` > ?'.$order.' LIMIT '.self::CHUNK); $st->execute([$last]); }
      elseif ($single) { $st = $pdo->query("SELECT $sel FROM $q$order LIMIT ".self::CHUNK); }
      else { $st = $pdo->query("SELECT $sel FROM $q$order LIMIT ".self::CHUNK.' OFFSET '.$offset); }
      $batch = $st->fetchAll(\PDO::FETCH_NUM);
      if (!$batch) { break; }
      $buf = ''; $n = 0;
      foreach ($batch as $r) {
        $vals = [];
        foreach ($r as $i => $v) {
          if ($v === null) { $vals[] = 'NULL'; }
          elseif ($bin[$i]) { $vals[] = $v === '' ? "''" : '0x'.bin2hex((string) $v); }
          elseif (is_int($v)) { $vals[] = (string) $v; }
          elseif (is_float($v)) { $vals[] = var_export($v, true); }
          else { $vals[] = $pdo->quote((string) $v); } }
        $buf .= ($n++ ? ",\n" : '').'('.implode(',', $vals).')';
        if (strlen($buf) > self::STMT_BYTES) { $w($head."\n".$buf.";\n"); $buf = ''; $n = 0; } }
      if ($buf !== '') { $w($head."\n".$buf.";\n"); }
      $total += count($batch);
      if ($single) { $last = $batch[count($batch) - 1][array_search($pk[0], array_column($cols, 'Field'), true)]; }
      $offset += self::CHUNK;
      if (count($batch) < self::CHUNK) { break; } }
    return $total; }

  private static function zipFull(string $zipPath, string $sqlGz, array $stats): void {
    if (!class_exists(\ZipArchive::class)) { throw new \RuntimeException('The PHP zip extension is required for full backups.'); }
    $z = new \ZipArchive();
    if ($z->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) { throw new \RuntimeException('Cannot create zip archive.'); }
    $z->addFile($sqlGz, 'database.sql.gz');
    $z->setCompressionName('database.sql.gz', \ZipArchive::CM_STORE);
    if (is_file(NY_ROOT.'/config/config.php')) { $z->addFile(NY_ROOT.'/config/config.php', 'config/config.php'); }
    $files = 0;
    foreach (['storage/uploads', 'assets/uploads'] as $dir) { $files += self::zipDir($z, NY_ROOT.'/'.$dir, $dir); }
    $z->addFromString('README.txt', "Niyanta full backup\nVersion: ".NY_VERSION."\nCreated: ".date('c')."\nTables: {$stats['tables']}, rows: {$stats['rows']}, files: $files\n\nContents:\n  database.sql.gz   gzip-compressed SQL dump (import with phpMyAdmin or: gunzip < database.sql.gz | mysql -u USER -p DBNAME)\n  config/config.php application config incl. APP KEY and DB password, keep this archive private\n  storage/uploads/  private uploaded files\n  assets/uploads/   branding files\n");
    if (!$z->close()) { throw new \RuntimeException('Could not finalise zip archive.'); } }

  public static function zipDir(\ZipArchive $z, string $abs, string $prefix, array $exclude = []): int {
    if (!is_dir($abs)) { return 0; }
    $n = 0;
    $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($abs, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::LEAVES_ONLY);
    foreach ($it as $f) {
      if (!$f->isFile() || $f->isLink()) { continue; }
      $rel = $prefix.'/'.str_replace('\\', '/', substr($f->getPathname(), strlen($abs) + 1));
      foreach ($exclude as $x) { if ($rel === $x || str_starts_with($rel, rtrim($x, '/').'/')) { continue 2; } }
      $z->addFile($f->getPathname(), $rel);
      $n++; }
    return $n; }

  /**
   * Restore the database from a backup (database or full). Takes a safety backup first, enables maintenance mode,
   * streams and executes each statement, and keeps the backups table rows created since the dump.
   * @return array{ok: bool, statements: int, safety_id: int, error: ?string}
   */
  public static function restore(array $row, ?int $by = null): array {
    @set_time_limit(0);
    ignore_user_abort(true);
    $src = self::path($row);
    if ($row['status'] !== 'success' || !is_file($src)) { return ['ok' => false, 'statements' => 0, 'safety_id' => 0, 'error' => 'Backup file is missing.']; }
    if ($row['sha256'] && !hash_equals((string) $row['sha256'], hash_file('sha256', $src))) { return ['ok' => false, 'statements' => 0, 'safety_id' => 0, 'error' => 'Checksum mismatch, the backup file is corrupted or was modified.']; }
    $safety = self::create('database', 'Safety backup before restoring #'.$row['id'], $by, false);
    if ($safety['status'] !== 'success') { return ['ok' => false, 'statements' => 0, 'safety_id' => 0, 'error' => 'Safety backup failed: '.$safety['note']]; }
    $keepRows = DB::all('SELECT * FROM backups');
    $tmp = null; $n = 0; $error = null;
    self::maintenance(true);
    try {
      $gzFile = $src;
      if ($row['type'] === 'full') {
        $z = new \ZipArchive();
        if ($z->open($src) !== true || ($in = $z->getStream('database.sql.gz')) === false) { throw new \RuntimeException('database.sql.gz not found in archive.'); }
        $tmp = self::dir().'/restore-'.bin2hex(random_bytes(4)).'.sql.gz';
        $out = fopen($tmp, 'wb');
        stream_copy_to_stream($in, $out);
        fclose($out); fclose($in); $z->close();
        $gzFile = $tmp; }
      $pdo = DB::pdo();
      $pdo->exec("SET time_zone='+00:00'");
      foreach (self::statements($gzFile) as $sql) { $pdo->exec($sql); $n++; }
      $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
      DB::syncTimezone();
      DB::transaction(static function () use ($keepRows) { DB::delete('backups', '1=1'); DB::insertMany('backups', $keepRows); });
      Cache::flush(); }
    catch (\Throwable $e) { $error = $e->getMessage(); error_log('Restore failed: '.$e); try { DB::pdo()->exec('SET FOREIGN_KEY_CHECKS=1'); DB::syncTimezone(); } catch (\Throwable) {} }
    finally { if ($tmp) { @unlink($tmp); } self::maintenance(false); }
    Audit::log($error ? 'backup.restore_failed' : 'backup.restore', 'backup', (int) $row['id'], ['file' => $row['filename'], 'statements' => $n, 'safety_backup' => $safety['filename']] + ($error ? ['error' => mb_substr($error, 0, 300)] : []), $by);
    return ['ok' => $error === null, 'statements' => $n, 'safety_id' => (int) $safety['id'], 'safety_file' => $safety['filename'], 'error' => $error]; }

  /** Stream SQL statements from a .sql or .sql.gz file, honouring quotes, backslash escapes and comments. */
  public static function statements(string $file): \Generator {
    $h = gzopen($file, 'rb');
    if (!$h) { throw new \RuntimeException('Cannot read '.basename($file)); }
    $buf = ''; $q = null; $block = false;
    try {
      while (($line = gzgets($h, 1 << 20)) !== false) {
        $len = strlen($line); $i = 0;
        if ($q === null && !$block && $buf === '') {
          $t = ltrim($line);
          if ($t === '' || str_starts_with($t, '-- ') || rtrim($t) === '--' || str_starts_with($t, '#')) { continue; } }
        while ($i < $len) {
          if ($block) { $p = strpos($line, '*/', $i); if ($p === false) { $i = $len; break; } $block = false; $i = $p + 2; continue; }
          if ($q !== null) {
            $p = strcspn($line, $q.'\\', $i) + $i;
            $buf .= substr($line, $i, $p - $i);
            if ($p >= $len) { $i = $len; break; }
            if ($line[$p] === '\\') { $buf .= substr($line, $p, 2); $i = $p + 2; continue; }
            if ($p + 1 < $len && $line[$p + 1] === $q) { $buf .= $q.$q; $i = $p + 2; continue; }
            $buf .= $q; $q = null; $i = $p + 1; continue; }
          $p = strcspn($line, "'\"`;-#/", $i) + $i;
          $buf .= substr($line, $i, $p - $i);
          if ($p >= $len) { $i = $len; break; }
          $c = $line[$p];
          if ($c === ';') { $s = trim($buf); $buf = ''; $i = $p + 1; if ($s !== '') { yield $s; } continue; }
          if ($c === '-' && substr($line, $p, 3) === '-- ' || $c === '-' && substr($line, $p) === "--\n" || $c === '#') { $i = $len; $buf .= "\n"; break; }
          if ($c === '/' && ($line[$p + 1] ?? '') === '*' && ($line[$p + 2] ?? '') !== '!') { $block = true; $i = $p + 2; continue; }
          if ($c === "'" || $c === '"' || $c === '`') { $q = $c; }
          $buf .= $c; $i = $p + 1; } }
      if (($s = trim($buf)) !== '') { yield $s; } }
    finally { gzclose($h); } }

  public static function size(int $b): string { foreach (['GB' => 1073741824, 'MB' => 1048576, 'KB' => 1024] as $u => $d) { if ($b >= $d) { return round($b / $d, 1).' '.$u; } } return $b.' B'; } }
