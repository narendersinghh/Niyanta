<?php
namespace Modules\Admin\Services;
use Core\{App, Audit, Cache, DB, Migrator, Modules, Settings};

/**
 * Self-updater for shared hosting. Checks GitHub (latest release or latest commit on a branch), downloads the package,
 * verifies integrity and compatibility, takes a full backup plus a code backup, installs the files in maintenance mode,
 * runs migrations and rolls the code back automatically if anything fails after files started changing.
 * Offline/manual updates use the same pipeline with an uploaded .zip (channel "upload").
 */
final class UpdateService {
  private const ALWAYS_PRESERVE = ['config/config.php', 'storage', 'assets/uploads', '.htaccess.local', '.git'];
  private const MAX_PACKAGE = 209715200;
  private const MAX_UNPACKED = 524288000;

  public static function dir(): string {
    $d = NY_ROOT.'/storage/updates';
    if (!is_dir($d)) { @mkdir($d, 0775, true); }
    if (!is_file($d.'/.htaccess')) { @file_put_contents($d.'/.htaccess', "Require all denied\nDeny from all\n"); }
    return $d; }

  public static function lastCheck(): array { return Settings::json('updates.last_check'); }

  /** Repository to update from: the updates.repo override (forks), else the official one from niyanta.json. */
  public static function repo(): ?array {
    $r = trim((string) Settings::get('updates.repo', '')) ?: App::repository();
    return preg_match('#^([A-Za-z0-9_.\-]+)/([A-Za-z0-9_.\-]+)$#', $r, $m) ? [$m[1], $m[2]] : null; }

  /**
   * The newest web app release from a GitHub releases list: not a draft or prerelease, and not a desktop tracker release
   * (tags starting with the tracker prefix, e.g. tracker-v1.2.0, share the repository). App tags look like v1.2.3.
   */
  public static function pickAppRelease(array $list): ?array {
    $best = null; $bestV = null; $prefix = App::trackerTagPrefix();
    foreach ($list as $r) {
      $tag = is_array($r) ? (string) ($r['tag_name'] ?? '') : '';
      if ($tag === '' || !empty($r['draft']) || !empty($r['prerelease']) || str_starts_with($tag, $prefix)) { continue; }
      $v = ltrim($tag, 'vV');
      if (!preg_match('/^\d+\.\d+(\.\d+)?([-+][0-9A-Za-z.]+)?$/', $v) || ($bestV !== null && version_compare($v, $bestV, '<='))) { continue; }
      [$best, $bestV] = [$r, $v]; }
    return $best; }

  /** Query GitHub for the latest release/commit and store the result in updates.last_check. */
  public static function check(): array {
    $repo = self::repo() ?? throw new \RuntimeException('The GitHub repository (owner/name) in Settings › Updates is not valid.');
    [$o, $n] = array_map('rawurlencode', $repo);
    $channel = Settings::get('updates.channel', 'release') === 'commit' ? 'commit' : 'release';
    $res = ['checked_at' => date('Y-m-d H:i:s'), 'channel' => $channel, 'repo' => implode('/', $repo), 'available' => false];
    try {
      if ($channel === 'release') {
        $j = self::pickAppRelease(self::api("https://api.github.com/repos/$o/$n/releases?per_page=50")) ?? throw new \RuntimeException('No web app release found yet (tags like v1.2.3; desktop tracker releases are ignored).');
        $version = ltrim((string) $j['tag_name'], 'vV');
        $pkg = null; $sum = null;
        foreach ($j['assets'] ?? [] as $a) {
          $name = (string) ($a['name'] ?? '');
          if ($name === 'checksums.txt' || str_ends_with($name, '.sha256')) { $sum ??= (string) $a['url']; }
          elseif (str_ends_with(strtolower($name), '.zip') && !$pkg) { $pkg = $a; } }
        $res += ['version' => $version, 'ref' => (string) $j['tag_name'], 'name' => (string) ($j['name'] ?? $j['tag_name']), 'notes' => mb_substr((string) ($j['body'] ?? ''), 0, 20000), 'published_at' => (string) ($j['published_at'] ?? ''), 'html_url' => (string) ($j['html_url'] ?? ''),
          'url' => $pkg ? (string) $pkg['url'] : (string) $j['zipball_url'], 'package' => $pkg ? (string) $pkg['name'] : 'source zipball', 'size' => $pkg ? (int) $pkg['size'] : null,
          'digest' => $pkg && str_starts_with((string) ($pkg['digest'] ?? ''), 'sha256:') ? substr((string) $pkg['digest'], 7) : null, 'checksum_url' => $sum];
        $res['available'] = version_compare($version, NY_VERSION, '>'); }
      else {
        $branch = (string) Settings::get('updates.branch', 'main') ?: 'main';
        $j = self::api("https://api.github.com/repos/$o/$n/commits/".rawurlencode($branch));
        $sha = (string) ($j['sha'] ?? '');
        if (!preg_match('/^[0-9a-f]{40}$/', $sha)) { throw new \RuntimeException('Unexpected response for branch '.$branch.'.'); }
        $msg = (string) ($j['commit']['message'] ?? '');
        $res += ['version' => substr($sha, 0, 7), 'ref' => $sha, 'branch' => $branch, 'name' => strtok($msg, "\n") ?: $sha, 'notes' => mb_substr($msg, 0, 20000), 'published_at' => (string) ($j['commit']['committer']['date'] ?? $j['commit']['author']['date'] ?? ''), 'html_url' => (string) ($j['html_url'] ?? ''),
          'url' => "https://api.github.com/repos/$o/$n/zipball/$sha", 'package' => 'source zipball', 'size' => null, 'digest' => null, 'checksum_url' => null];
        $res['available'] = $sha !== (string) Settings::get('updates.current_commit', ''); } }
    catch (\Throwable $e) { $res['error'] = $e->getMessage(); Settings::set('updates.last_check', $res); throw $e; }
    Settings::set('updates.last_check', $res);
    return $res; }

  private static function api(string $url): array {
    [$code, $body] = self::http($url, 'application/vnd.github+json');
    $j = json_decode($body, true);
    if ($code === 404) { throw new \RuntimeException('Not found on GitHub (check the repository name, that a release exists, or add a token for a private repository).'); }
    if ($code === 401 || $code === 403) { throw new \RuntimeException('GitHub refused the request ('.$code.'): '.rtrim(is_array($j) ? (string) ($j['message'] ?? '') : 'access denied or rate limited', '.').'.'); }
    if ($code !== 200 || !is_array($j)) { throw new \RuntimeException('GitHub request failed (HTTP '.$code.').'); }
    return $j; }

  /** HTTPS-only GET with GitHub headers. With $saveTo the body streams to that file. @return array{0:int,1:string} */
  private static function http(string $url, string $accept, ?string $saveTo = null): array {
    if (!function_exists('curl_init')) { throw new \RuntimeException('The PHP cURL extension is required for online updates. Use "Upload update package" instead.'); }
    if (!str_starts_with($url, 'https://')) { throw new \RuntimeException('Only HTTPS update sources are allowed.'); }
    $h = ['User-Agent: Niyanta-Updater/'.NY_VERSION, 'Accept: '.$accept, 'X-GitHub-Api-Version: 2022-11-28'];
    if ($t = (string) Settings::get('updates.github_token', '')) { $h[] = 'Authorization: Bearer '.$t; }
    $c = curl_init($url);
    $fp = null;
    $opts = [CURLOPT_HTTPHEADER => $h, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => $saveTo ? 600 : 30, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
      CURLOPT_NOPROGRESS => false, CURLOPT_XFERINFOFUNCTION => static fn ($ch, $dlTotal, $dl) => $dl > self::MAX_PACKAGE ? 1 : 0];
    if (defined('CURLOPT_PROTOCOLS_STR')) { $opts[CURLOPT_PROTOCOLS_STR] = 'https'; $opts[CURLOPT_REDIR_PROTOCOLS_STR] = 'https'; }
    if ($saveTo) { $fp = fopen($saveTo, 'wb'); if (!$fp) { throw new \RuntimeException('Cannot write to storage/updates.'); } $opts[CURLOPT_FILE] = $fp; }
    else { $opts[CURLOPT_RETURNTRANSFER] = true; }
    curl_setopt_array($c, $opts);
    $body = curl_exec($c);
    $code = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE);
    $err = curl_error($c);
    curl_close($c);
    if ($fp) { fclose($fp); }
    if ($body === false) { throw new \RuntimeException('Network error: '.($err ?: 'request failed')); }
    return [$code, $saveTo ? '' : (string) $body]; }

  /** Download and install the update found by the last check. */
  public static function apply(?int $by = null, bool $force = false): array {
    $c = self::lastCheck();
    if (empty($c['url']) || !empty($c['error'])) { throw new \RuntimeException('Check for updates first.'); }
    if (empty($c['available']) && !$force) { throw new \RuntimeException('You are already on the latest version.'); }
    $id = self::start($c['channel'], (string) $c['version'], (string) $c['ref'], $by);
    $zip = self::dir().'/'.$id.'.zip';
    $log = self::logger($id);
    try {
      $log('Downloading '.$c['package'].' from '.$c['repo'].' ('.$c['ref'].')');
      [$code] = self::http((string) $c['url'], $c['package'] === 'source zipball' ? 'application/vnd.github+json' : 'application/octet-stream', $zip);
      if ($code !== 200) { throw new \RuntimeException('Download failed (HTTP '.$code.').'); }
      clearstatcache();
      $log('Downloaded '.BackupService::size((int) filesize($zip)).', sha256 '.hash_file('sha256', $zip));
      $expected = $c['digest'] ?? null;
      if (!$expected && !empty($c['checksum_url'])) { $expected = self::checksumFor((string) $c['checksum_url'], (string) $c['package']); }
      if ($expected) {
        if (!hash_equals(strtolower($expected), hash_file('sha256', $zip))) { throw new \RuntimeException('Checksum mismatch: the package does not match the published sha256.'); }
        $log('Checksum verified against the published sha256.'); }
      elseif (!empty($c['size'])) {
        if ((int) $c['size'] !== (int) filesize($zip)) { throw new \RuntimeException('Size mismatch: expected '.$c['size'].' bytes.'); }
        $log('Size verified against GitHub ('.$c['size'].' bytes). No checksum file published.'); }
      else { $log('No checksum published; verifying package structure instead.'); } }
    catch (\Throwable $e) {
      @unlink($zip);
      $log('FAILED: '.$e->getMessage());
      self::finish($id, 'failed');
      return DB::find('update_logs', $id); }
    $row = self::run($id, $zip, ['channel' => $c['channel'], 'ref' => $c['ref']], $by, $force);
    @unlink($zip);
    return $row; }

  private static function checksumFor(string $url, string $package): ?string {
    [$code, $body] = self::http($url, 'application/octet-stream');
    if ($code !== 200) { throw new \RuntimeException('Could not download the checksum file (HTTP '.$code.').'); }
    $lines = array_values(array_filter(array_map('trim', explode("\n", $body))));
    foreach ($lines as $l) { if (preg_match('/^([0-9a-f]{64})\s+\*?(.+)$/i', $l, $m) && basename(trim($m[2])) === $package) { return strtolower($m[1]); } }
    if (count($lines) === 1 && preg_match('/^([0-9a-f]{64})\b/i', $lines[0], $m)) { return strtolower($m[1]); }
    throw new \RuntimeException('The checksum file does not list '.$package.'.'); }

  /** Offline/manual update from a local .zip (also used by tests). */
  public static function applyLocal(string $zipPath, ?int $by = null, bool $force = false, string $label = 'upload'): array {
    $id = self::start('upload', null, mb_substr(basename($label), 0, 120), $by);
    return self::run($id, $zipPath, ['channel' => 'upload', 'ref' => $label], $by, $force); }

  private static function start(string $channel, ?string $to, ?string $ref, ?int $by): int {
    return DB::insert('update_logs', ['from_version' => NY_VERSION, 'to_version' => $to, 'channel' => $channel, 'ref' => $ref, 'status' => 'running', 'log' => '', 'started_by' => $by, 'started_at' => date('Y-m-d H:i:s')]); }

  private static function logger(int $id): \Closure {
    return static function (string $msg) use ($id): void { DB::query('UPDATE update_logs SET log=CONCAT(COALESCE(log,\'\'), ?) WHERE id=?', ['['.date('H:i:s').'] '.$msg."\n", $id]); }; }

  private static function finish(int $id, string $status): void { DB::update('update_logs', ['status' => $status, 'finished_at' => date('Y-m-d H:i:s')], 'id=?', [$id]); }

  /** The install pipeline. Never throws; returns the update_logs row. */
  private static function run(int $id, string $zip, array $meta, ?int $by, bool $force): array {
    @set_time_limit(0);
    ignore_user_abort(true);
    $log = self::logger($id);
    $lock = fopen(self::dir().'/.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) { $log('FAILED: another update is already running.'); self::finish($id, 'failed'); return DB::find('update_logs', $id); }
    $staging = self::dir().'/staging-'.$id;
    $codeBackup = null; $copying = false; $created = []; $status = 'failed';
    try {
      $log('Preflight checks');
      if (!class_exists(\ZipArchive::class)) { throw new \RuntimeException('The PHP zip extension is required.'); }
      foreach (['', 'core', 'modules', 'views', 'assets', 'storage', 'storage/updates', 'storage/backups'] as $d) { if (!is_writable(NY_ROOT.'/'.$d)) { throw new \RuntimeException('Not writable: /'.$d); } }
      $pkg = self::inspect($zip);
      $log("Package OK: {$pkg['files']} files, ".BackupService::size($pkg['bytes']).' unpacked, version '.$pkg['version'].', requires PHP '.$pkg['min_php']);
      if (version_compare(PHP_VERSION, $pkg['min_php'], '<')) { throw new \RuntimeException('This version needs PHP '.$pkg['min_php'].'; the server runs '.PHP_VERSION.'.'); }
      if (version_compare($pkg['version'], NY_VERSION, '<') && !$force) { throw new \RuntimeException('Package version '.$pkg['version'].' is older than the installed '.NY_VERSION.'. Tick "allow downgrade" to force it.'); }
      DB::update('update_logs', ['to_version' => $pkg['version']], 'id=?', [$id]);
      $free = @disk_free_space(NY_ROOT.'/storage');
      $need = $pkg['bytes'] * 2 + 52428800;
      if ($free !== false && $free < $need) { throw new \RuntimeException('Not enough disk space: '.BackupService::size((int) $free).' free, about '.BackupService::size($need).' needed.'); }
      $log('Disk space OK ('.($free === false ? 'unknown' : BackupService::size((int) $free)).' free)');
      $log('Creating full backup (database, uploads, config)');
      $b = BackupService::create('full', 'Before update to '.$pkg['version'], $by);
      if ($b['status'] !== 'success') { throw new \RuntimeException('Backup failed: '.$b['note']); }
      DB::update('update_logs', ['backup_id' => $b['id']], 'id=?', [$id]);
      $log('Backup #'.$b['id'].' created: '.$b['filename']);
      $preserve = array_values(array_unique(array_merge(self::ALWAYS_PRESERVE, self::preserveList(self::manifest()), $pkg['preserve'])));
      $codeBackup = self::dir().'/code-backup-'.$id.'.zip';
      $n = self::codeBackup($codeBackup, $preserve);
      $log("Code backup created ($n files): storage/updates/".basename($codeBackup));
      BackupService::maintenance(true);
      $log('Maintenance mode enabled');
      self::extract($zip, $pkg['prefix'], $staging);
      $log('Extracted to staging');
      $copying = true;
      $written = self::install($staging, $preserve, $created);
      $log("Installed $written files (".count($created).' new); preserved: '.implode(', ', $preserve));
      $ran = Migrator::migrate();
      $log($ran ? 'Migrations run: '.implode(', ', $ran) : 'No pending migrations');
      $p = Modules::syncPermissions();
      $log("Permissions synced ($p new)");
      Cache::flush();
      if (function_exists('opcache_reset')) { @opcache_reset(); }
      $log('Caches cleared');
      if ($meta['channel'] === 'commit') { Settings::set('updates.current_commit', $meta['ref']); }
      Settings::set('updates.installed_at', date('Y-m-d H:i:s'));
      $status = 'success';
      $log('Update to '.$pkg['version'].' completed successfully'); }
    catch (\Throwable $e) {
      $log('FAILED: '.$e->getMessage());
      error_log('Update failed: '.$e);
      if ($copying && $codeBackup) {
        try { $r = self::rollback($codeBackup, $created); $status = 'rolled_back'; $log("Code restored from backup ($r files, removed ".count($created).' new files). Database migrations that already ran are kept; restore the pre-update backup if needed.'); }
        catch (\Throwable $r) { $log('ROLLBACK FAILED: '.$r->getMessage().', restore manually from storage/updates/'.basename($codeBackup).' (see docs/UPDATES.md)'); } }
      Cache::flush(); }
    finally {
      BackupService::maintenance(false);
      self::rrmdir($staging);
      flock($lock, LOCK_UN); fclose($lock); }
    $log('Maintenance mode disabled');
    self::finish($id, $status);
    if ($status === 'success' && $codeBackup) { self::pruneCodeBackups(3); }
    Audit::log('update.'.$status, 'update', $id, ['channel' => $meta['channel'], 'ref' => mb_substr((string) $meta['ref'], 0, 120), 'from' => NY_VERSION], $by);
    return DB::find('update_logs', $id); }

  public static function manifest(): array { $j = json_decode((string) @file_get_contents(NY_ROOT.'/niyanta.json'), true); return is_array($j) ? $j : []; }
  private static function preserveList(array $m): array { return array_values(array_filter(array_map(static fn ($p) => trim(str_replace('\\', '/', (string) $p), '/'), (array) ($m['preserve'] ?? [])), static fn ($p) => $p !== '' && !str_contains($p, '..'))); }

  /** Validate a package zip: safe entry names, size limits, niyanta.json + core/version.php present and consistent. */
  public static function inspect(string $zip): array {
    if (!is_file($zip) || filesize($zip) > self::MAX_PACKAGE) { throw new \RuntimeException('Package missing or too large.'); }
    $z = new \ZipArchive();
    if ($z->open($zip, \ZipArchive::RDONLY) !== true) { throw new \RuntimeException('The package is not a valid zip archive.'); }
    try {
      $prefix = null; $bytes = 0;
      for ($i = 0; $i < $z->numFiles; $i++) {
        $s = $z->statIndex($i);
        $name = (string) $s['name'];
        if (!self::safeName($name)) { throw new \RuntimeException('Unsafe path in package: '.mb_substr($name, 0, 120)); }
        if ($z->getExternalAttributesIndex($i, $os, $attr) && $os === \ZipArchive::OPSYS_UNIX && (($attr >> 16) & 0170000) === 0120000) { throw new \RuntimeException('Symbolic links are not allowed in packages: '.$name); }
        $bytes += (int) $s['size'];
        if (basename($name) === 'niyanta.json' && substr_count($name, '/') <= 1) { $p = substr($name, 0, -strlen('niyanta.json')); if ($prefix === null || strlen($p) < strlen($prefix)) { $prefix = $p; } } }
      if ($bytes > self::MAX_UNPACKED) { throw new \RuntimeException('Package unpacks to more than '.BackupService::size(self::MAX_UNPACKED).'.'); }
      if ($prefix === null) { throw new \RuntimeException('niyanta.json not found, this is not a Niyanta package.'); }
      $m = json_decode((string) $z->getFromName($prefix.'niyanta.json'), true);
      $vp = (string) $z->getFromName($prefix.'core/version.php');
      if (!is_array($m) || ($m['name'] ?? '') !== 'niyanta' || empty($m['version'])) { throw new \RuntimeException('niyanta.json in the package is invalid.'); }
      if ($vp === '' || $z->locateName($prefix.'index.php') === false) { throw new \RuntimeException('core/version.php or index.php missing from the package.'); }
      if (!preg_match("/define\('NY_VERSION',\s*'([^']+)'\)/", $vp, $vm) || $vm[1] !== (string) $m['version']) { throw new \RuntimeException('core/version.php and niyanta.json disagree on the version.'); }
      $files = 0;
      for ($i = 0; $i < $z->numFiles; $i++) { $n = (string) $z->getNameIndex($i); if (str_starts_with($n, $prefix) && !str_ends_with($n, '/')) { $files++; } }
      return ['prefix' => $prefix, 'version' => (string) $m['version'], 'min_php' => (string) ($m['min_php'] ?? '8.2.0'), 'preserve' => self::preserveList($m), 'files' => $files, 'bytes' => $bytes]; }
    finally { $z->close(); } }

  private static function safeName(string $n): bool {
    if ($n === '' || str_contains($n, "\0") || str_contains($n, '\\') || $n[0] === '/' || preg_match('#^[A-Za-z]:#', $n)) { return false; }
    foreach (explode('/', $n) as $seg) { if ($seg === '..' || $seg === '.') { return false; } }
    return true; }

  private static function isPreserved(string $rel, array $preserve): bool {
    foreach ($preserve as $p) { if ($rel === $p || str_starts_with($rel, $p.'/')) { return true; } }
    return false; }

  /** Extract entries under $prefix into $dest by streaming (never uses extractTo, so no path can escape $dest). */
  private static function extract(string $zip, string $prefix, string $dest): int {
    self::rrmdir($dest);
    mkdir($dest, 0775, true);
    $z = new \ZipArchive();
    $z->open($zip, \ZipArchive::RDONLY);
    $n = 0;
    try {
      for ($i = 0; $i < $z->numFiles; $i++) {
        $name = (string) $z->getNameIndex($i);
        if (!str_starts_with($name, $prefix) || str_ends_with($name, '/')) { continue; }
        $rel = substr($name, strlen($prefix));
        if (!self::safeName($rel)) { throw new \RuntimeException('Unsafe path: '.$rel); }
        $target = $dest.'/'.$rel;
        if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0775, true) && !is_dir(dirname($target))) { throw new \RuntimeException('Cannot create staging folder.'); }
        $in = $z->getStream($name);
        $out = fopen($target, 'wb');
        if (!$in || !$out || stream_copy_to_stream($in, $out) === false) { throw new \RuntimeException('Cannot extract '.$rel); }
        fclose($in); fclose($out); $n++; } }
    finally { $z->close(); }
    return $n; }

  /** Copy staged files over the app root (temp file + rename per file), skipping preserved paths. */
  private static function install(string $staging, array $preserve, array &$created): int {
    $n = 0;
    $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($staging, \FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
      if (!$f->isFile()) { continue; }
      $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($staging) + 1));
      if (self::isPreserved($rel, $preserve)) { continue; }
      $target = NY_ROOT.'/'.$rel;
      $dir = dirname($target);
      if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) { throw new \RuntimeException('Cannot create folder '.dirname($rel)); }
      if (!file_exists($target)) { $created[] = $rel; }
      $tmp = $target.'.ny-'.bin2hex(random_bytes(3));
      if (!copy($f->getPathname(), $tmp) || !rename($tmp, $target)) { @unlink($tmp); throw new \RuntimeException('Cannot write '.$rel); }
      if (str_ends_with($rel, '.php') && function_exists('opcache_invalidate')) { @opcache_invalidate($target, true); }
      $n++; }
    return $n; }

  /** Zip the current application code (everything except preserved paths) for rollback. */
  public static function codeBackup(string $zipPath, array $preserve): int {
    $z = new \ZipArchive();
    if ($z->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) { throw new \RuntimeException('Cannot create code backup.'); }
    $root = NY_ROOT;
    $filter = new \RecursiveCallbackFilterIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), static function (\SplFileInfo $f) use ($root, $preserve): bool {
      $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1));
      return !$f->isLink() && !self::isPreserved($rel, $preserve); });
    $n = 0;
    foreach (new \RecursiveIteratorIterator($filter) as $f) {
      if (!$f->isFile()) { continue; }
      $z->addFile($f->getPathname(), str_replace('\\', '/', substr($f->getPathname(), strlen($root) + 1)));
      $n++; }
    if (!$z->close()) { throw new \RuntimeException('Could not finalise code backup.'); }
    return $n; }

  /** Restore code from a code backup zip and remove files the failed update added. */
  public static function rollback(string $codeBackup, array $created): int {
    $tmp = self::dir().'/rollback-'.bin2hex(random_bytes(3));
    $n = self::extract($codeBackup, '', $tmp);
    $none = [];
    self::install($tmp, self::ALWAYS_PRESERVE, $none);
    self::rrmdir($tmp);
    foreach ($created as $rel) { if (self::safeName($rel)) { @unlink(NY_ROOT.'/'.$rel); } }
    if (function_exists('opcache_reset')) { @opcache_reset(); }
    return $n; }

  private static function pruneCodeBackups(int $keep): void {
    $f = glob(self::dir().'/code-backup-*.zip') ?: [];
    usort($f, static fn ($a, $b) => filemtime($b) <=> filemtime($a));
    foreach (array_slice($f, $keep) as $old) { @unlink($old); } }

  public static function rrmdir(string $d): void {
    if (!is_dir($d) || !str_starts_with(realpath($d) ?: '', realpath(NY_ROOT.'/storage') ?: "\0")) { return; }
    $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($d, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) { $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
    @rmdir($d); } }
