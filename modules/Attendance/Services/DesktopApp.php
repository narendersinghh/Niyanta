<?php
namespace Modules\Attendance\Services;
use Core\{App, Audit, Cache, ErrorLog, Settings};

/**
 * Niyanta Tracker downloads, built in and private. The desktop app is released in the (private) official repository
 * (niyanta.json "repository", updates.repo overrides it for forks) as GitHub releases tagged tracker-v<version>
 * (.github/workflows/desktop.yml). The server reads them with the existing encrypted token updates.github_token and mirrors
 * the newest one into storage/desktop/<version>/ from the scheduler (never during a normal page view). People download the
 * installers from the server (signed in, attendance.track); the app's auto-updater reads latest*.yml and the binaries from
 * /api/agent/updates/{file} with its device token. Release metadata is cached 30 minutes; the newest 2 versions are kept.
 */
final class DesktopApp {
  /** platform => [label, hint, extensions, icon] */
  public const PLATFORMS = [
    'windows' => ['Windows', 'Windows 10 and 11 (64-bit) installer', ['exe'], 'monitor'],
    'mac' => ['macOS', 'macOS 11 or later, Apple silicon and Intel', ['dmg', 'zip'], 'monitor'],
    'linux' => ['Linux AppImage', 'Any modern distribution, no install needed', ['appimage'], 'monitor'],
    'deb' => ['Linux .deb', 'Debian, Ubuntu, Mint and derivatives', ['deb'], 'monitor']];
  public const ARCHES = ['x64' => 'Intel / AMD 64-bit', 'arm64' => 'ARM 64-bit (Apple silicon)', 'universal' => 'Universal'];
  /** Update-info files electron-builder writes and electron-updater asks for (Windows, macOS, Linux x64 / ARM). */
  public const UPDATE_FILES = ['latest.yml', 'latest-mac.yml', 'latest-linux.yml', 'latest-linux-arm64.yml', 'latest-linux-armv7l.yml'];
  private const TTL = 1800;
  private const FAIL_TTL = 300;
  private const KEEP = 2;
  private const MAX_ASSET = 629145600;

  /** Test hooks: another storage folder, and a fetcher fn(array $asset): string used instead of the GitHub API. */
  public static ?string $root = null;
  public static ?\Closure $fetcher = null;
  private static ?array $local = null;

  public static function repo(): string {
    $r = trim((string) Settings::get('updates.repo', ''));
    return preg_match('#^[A-Za-z0-9_.\-]+/[A-Za-z0-9_.\-]+$#', $r) ? $r : App::repository(); }
  public static function connected(): bool { return (string) Settings::get('updates.github_token', '') !== '' || self::$fetcher !== null; }
  public static function tagPrefix(): string { return App::trackerTagPrefix(); }
  public static function cacheKey(): string { return 'tracker_release_'.md5(self::repo()); }
  public static function validVersion(string $v): bool { return (bool) preg_match('/^\d{1,4}\.\d{1,4}\.\d{1,5}(-[0-9A-Za-z.]{1,20})?$/', $v); }

  public static function dir(): string {
    $d = self::$root ?? NY_ROOT.'/storage/desktop';
    if (!is_dir($d)) { @mkdir($d, 0775, true); }
    if (!is_file($d.'/.htaccess')) { @file_put_contents($d.'/.htaccess', "Require all denied\nDeny from all\n"); }
    return $d; }

  /** Platform, architecture and version from an official asset name: niyanta-tracker-<version>-<os>-<arch>.<ext>. */
  public static function classify(string $name): ?array {
    if (!preg_match('/^niyanta-tracker-(\d+\.\d+\.\d+(?:-[0-9A-Za-z.]+)?)-(win|mac|linux)-([a-z0-9_]+)\.(exe|dmg|zip|appimage|deb)$/i', $name, $m)) { return null; }
    [$os, $ext] = [strtolower($m[2]), strtolower($m[4])];
    $platform = match (true) { $os === 'win' && $ext === 'exe' => 'windows', $os === 'mac' && ($ext === 'dmg' || $ext === 'zip') => 'mac', $os === 'linux' && $ext === 'appimage' => 'linux', $os === 'linux' && $ext === 'deb' => 'deb', default => null };
    if (!$platform) { return null; }
    $a = strtolower($m[3]);
    $arch = match ($a) { 'x64', 'x86_64', 'amd64' => 'x64', 'arm64', 'aarch64' => 'arm64', default => $a };
    return ['platform' => $platform, 'arch' => $arch, 'ext' => $ext, 'version' => $m[1]]; }

  /* ---------- GitHub (metadata cached 30 min, assets mirrored by the scheduler) ---------- */

  /**
   * Newest tracker release on GitHub: ['ok' => bool, 'release' => ?array, 'checked_at', 'error'?]. ok=false: no token or
   * GitHub could not be asked (cached 5 min); ok=true with release=null: nothing published yet.
   */
  public static function release(bool $refresh = false): array {
    if (!self::connected()) { return ['ok' => false, 'release' => null, 'checked_at' => date('c'), 'error' => 'No GitHub access token in Settings › Updates.']; }
    $key = self::cacheKey();
    if ($refresh) { Cache::forget($key); }
    $hit = Cache::get($key);
    if (is_array($hit) && array_key_exists('ok', $hit)) { return $hit; }
    if (defined('NY_TESTING') && self::$fetcher === null) { return ['ok' => false, 'release' => null, 'checked_at' => date('c'), 'error' => 'GitHub is not contacted in tests']; }
    try {
      [$code, $body] = self::http('https://api.github.com/repos/'.self::repo().'/releases?per_page=50', 'application/vnd.github+json');
      if ($code !== 200) { throw new \RuntimeException('GitHub answered HTTP '.$code.($code === 401 || $code === 404 ? ' (check the token in Settings › Updates: it needs read access to Contents of '.self::repo().')' : '')); }
      $list = json_decode($body, true);
      if (!is_array($list) || !array_is_list($list)) { throw new \RuntimeException('unexpected GitHub response'); }
      $res = ['ok' => true, 'release' => self::pickRelease($list), 'checked_at' => date('c')]; }
    catch (\Throwable $e) {
      $res = ['ok' => false, 'release' => null, 'checked_at' => date('c'), 'error' => $e->getMessage()];
      ErrorLog::record('server', 'warning', 'Desktop app release lookup failed for '.self::repo().': '.$e->getMessage(), __FILE__, __LINE__); }
    Cache::put($key, $res, $res['ok'] ? self::TTL : self::FAIL_TTL);
    return $res; }

  /**
   * HTTPS GET with the token (api.github.com only; curl drops the header on the redirect to the storage host).
   * With $saveTo the body streams to that file. @return array{0:int,1:string}
   */
  private static function http(string $url, string $accept, ?string $saveTo = null): array {
    if (!function_exists('curl_init')) { throw new \RuntimeException('PHP cURL extension missing'); }
    $h = ['User-Agent: Niyanta/'.NY_VERSION, 'Accept: '.$accept, 'X-GitHub-Api-Version: 2022-11-28'];
    if ($t = (string) Settings::get('updates.github_token', '')) { $h[] = 'Authorization: Bearer '.$t; }
    $c = curl_init($url); $fp = null;
    $opts = [CURLOPT_HTTPHEADER => $h, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5, CURLOPT_UNRESTRICTED_AUTH => false, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => $saveTo ? 1800 : 20, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
      CURLOPT_NOPROGRESS => false, CURLOPT_XFERINFOFUNCTION => static fn ($ch, $total, $now) => $now > self::MAX_ASSET ? 1 : 0];
    if (defined('CURLOPT_PROTOCOLS_STR')) { $opts[CURLOPT_PROTOCOLS_STR] = 'https'; $opts[CURLOPT_REDIR_PROTOCOLS_STR] = 'https'; }
    if ($saveTo) { $fp = fopen($saveTo, 'wb'); if (!$fp) { throw new \RuntimeException('Cannot write '.basename($saveTo)); } $opts[CURLOPT_FILE] = $fp; }
    else { $opts[CURLOPT_RETURNTRANSFER] = true; }
    curl_setopt_array($c, $opts);
    $body = curl_exec($c); $code = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE); $err = curl_error($c); curl_close($c);
    if ($fp) { fclose($fp); }
    if ($body === false) { throw new \RuntimeException('network error: '.$err); }
    return [$code, $saveTo ? '' : (string) $body]; }

  /**
   * The newest published tracker release (tag tracker-v<semver>, not a draft, not a prerelease) from a GitHub releases
   * list: version, tag, dates, and its assets (name => id, size, API url) that belong to the app.
   */
  public static function pickRelease(array $list): ?array {
    $best = null; $prefix = self::tagPrefix();
    foreach ($list as $r) {
      $tag = is_array($r) ? (string) ($r['tag_name'] ?? '') : '';
      if ($tag === '' || !empty($r['draft']) || !empty($r['prerelease']) || !str_starts_with($tag, $prefix)) { continue; }
      $v = substr($tag, strlen($prefix));
      if (!self::validVersion($v) || ($best && version_compare($v, $best['version'], '<='))) { continue; }
      $assets = [];
      foreach ($r['assets'] ?? [] as $a) {
        $name = (string) ($a['name'] ?? '');
        $c = self::classify(preg_replace('/\.blockmap$/', '', $name));
        if (!in_array($name, self::UPDATE_FILES, true) && !($c && $c['version'] === $v)) { continue; }
        $assets[$name] = ['id' => (int) ($a['id'] ?? 0), 'size' => (int) ($a['size'] ?? 0), 'url' => (string) ($a['url'] ?? '')]; }
      $best = ['version' => $v, 'tag' => $tag, 'name' => (string) ($r['name'] ?? $tag), 'published_at' => (string) ($r['published_at'] ?? ''), 'html_url' => (string) ($r['html_url'] ?? ''), 'assets' => $assets]; }
    return $best; }

  /** Scheduler job (every 30 min): uses the cached metadata and downloads only when a newer release is not mirrored yet. */
  public static function cron(): string {
    if (!self::connected()) { return 'skipped: no GitHub token'; }
    $s = self::sync();
    return $s['ok'] ? ($s['downloaded'] ? 'mirrored '.$s['version'].' ('.$s['downloaded'].' files)' : 'up to date '.($s['version'] ?? '-')) : 'failed: '.$s['error']; }

  /**
   * Mirror the newest release into storage/desktop/<version>/: each asset once via the API asset endpoint, streamed to a
   * .part file, size checked against GitHub and sha512 against latest*.yml, then renamed. Keeps the newest 2 versions.
   * Returns and stores (tracker.sync) ['ok', 'version', 'downloaded', 'error', 'at'].
   */
  public static function sync(bool $refresh = false): array {
    $res = ['ok' => false, 'version' => null, 'downloaded' => 0, 'error' => null, 'at' => date('Y-m-d H:i:s')];
    try {
      $r = self::release($refresh);
      if (!$r['ok']) { throw new \RuntimeException((string) ($r['error'] ?? 'GitHub could not be reached')); }
      $rel = $r['release'];
      if (!$rel) { $res['ok'] = true; $res['error'] = 'No tracker release ('.self::tagPrefix().'X.Y.Z) has been published yet.'; return self::remember($res); }
      $res['version'] = $rel['version'];
      $dir = self::dir().'/'.$rel['version'];
      $have = self::manifest($dir);
      $missing = array_filter(array_keys($rel['assets']), static fn ($n) => !isset($have['files'][$n]) || !is_file($dir.'/'.$n));
      if ($have && !$missing) { $res['ok'] = true; return self::remember($res); }
      if (!$rel['assets']) { throw new \RuntimeException('Release '.$rel['tag'].' has no installers attached yet.'); }
      if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) { throw new \RuntimeException('Cannot create storage/desktop/'.$rel['version']); }
      @set_time_limit(0);
      $names = array_keys($rel['assets']);
      usort($names, static fn ($a, $b) => [!in_array($a, self::UPDATE_FILES, true), $a] <=> [!in_array($b, self::UPDATE_FILES, true), $b]);
      $sums = []; $files = $have['files'] ?? [];
      foreach ($names as $n) {
        $a = $rel['assets'][$n];
        if (!isset($files[$n]) || !is_file($dir.'/'.$n)) { self::download($a, $n, $dir); $res['downloaded']++; }
        if (in_array($n, self::UPDATE_FILES, true)) { $sums += self::ymlFiles((string) file_get_contents($dir.'/'.$n)); }
        else {
          $h = base64_encode(hash_file('sha512', $dir.'/'.$n, true));
          if (isset($sums[$n]) && !hash_equals($sums[$n], $h)) { @unlink($dir.'/'.$n); throw new \RuntimeException($n.' does not match the sha512 in the update file.'); } }
        $files[$n] = ['size' => (int) filesize($dir.'/'.$n)] + (isset($h) && !in_array($n, self::UPDATE_FILES, true) ? ['sha512' => $h] : []) + (self::classify($n) ?? []);
        unset($h); }
      $m = ['version' => $rel['version'], 'tag' => $rel['tag'], 'name' => $rel['name'], 'published_at' => $rel['published_at'], 'synced_at' => date('Y-m-d H:i:s'), 'repo' => self::repo(), 'files' => $files];
      file_put_contents($dir.'/manifest.json', json_encode($m, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX);
      self::$local = null;
      self::prune();
      if ($res['downloaded']) { Audit::log('tracker.release_mirrored', 'settings', null, ['version' => $rel['version'], 'files' => $res['downloaded']]); }
      $res['ok'] = true; }
    catch (\Throwable $e) {
      $res['error'] = $e->getMessage();
      ErrorLog::record('server', 'warning', 'Desktop app mirror failed: '.$e->getMessage(), __FILE__, __LINE__); }
    return self::remember($res); }

  private static function remember(array $res): array { Settings::set('tracker.sync', $res); return $res; }
  public static function syncStatus(): array { return Settings::json('tracker.sync'); }

  private static function download(array $a, string $name, string $dir): void {
    $part = $dir.'/'.$name.'.part';
    try {
      if (self::$fetcher) { file_put_contents($part, (self::$fetcher)($a + ['name' => $name])); }
      else {
        if (!$a['id']) { throw new \RuntimeException('asset id missing'); }
        [$code] = self::http('https://api.github.com/repos/'.self::repo().'/releases/assets/'.$a['id'], 'application/octet-stream', $part);
        if ($code !== 200) { throw new \RuntimeException('HTTP '.$code); } }
      clearstatcache(true, $part);
      if ($a['size'] && (int) filesize($part) !== $a['size']) { throw new \RuntimeException('size '.filesize($part).' instead of '.$a['size']); }
      if (!rename($part, $dir.'/'.$name)) { throw new \RuntimeException('cannot move into place'); } }
    catch (\Throwable $e) { @unlink($part); throw new \RuntimeException('Download of '.$name.' failed: '.$e->getMessage(), 0, $e); } }

  /** name => sha512 (base64) from an electron-builder update file (files: - url / sha512 entries). */
  public static function ymlFiles(string $yml): array {
    $out = []; $cur = null;
    foreach (preg_split('/\r?\n/', $yml) as $line) {
      if (preg_match('/^\s*-\s+url:\s*[\'"]?([^\'"\s]+)/', $line, $m)) { $cur = basename($m[1]); continue; }
      if ($cur && preg_match('/^\s+sha512:\s*[\'"]?([A-Za-z0-9+\/=]+)/', $line, $m)) { $out[$cur] = $m[1]; $cur = null; }
      elseif (!preg_match('/^\s/', $line)) { $cur = null; } }
    return $out; }

  private static function prune(): void {
    $vers = array_values(array_filter(array_map('basename', glob(self::dir().'/*', GLOB_ONLYDIR) ?: []), [self::class, 'validVersion']));
    usort($vers, 'version_compare');
    foreach (array_slice(array_reverse($vers), self::KEEP) as $v) { self::rrmdir(self::dir().'/'.$v); } }

  private static function rrmdir(string $d): void {
    foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($d, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) { $f->isDir() && !$f->isLink() ? @rmdir($f->getPathname()) : @unlink($f->getPathname()); }
    @rmdir($d); }

  /* ---------- local mirror (what pages and the app use) ---------- */

  private static function manifest(string $dir): ?array {
    $m = is_file($dir.'/manifest.json') ? json_decode((string) file_get_contents($dir.'/manifest.json'), true) : null;
    return is_array($m) && isset($m['version'], $m['files']) && is_array($m['files']) ? $m : null; }

  /** Manifest of the newest fully mirrored version, or null. */
  public static function local(): ?array {
    if (self::$local !== null) { return self::$local ?: null; }
    $best = null;
    foreach (glob(self::dir().'/*/manifest.json') ?: [] as $f) {
      $m = self::manifest(dirname($f));
      if ($m && basename(dirname($f)) === $m['version'] && (!$best || version_compare($m['version'], $best['version'], '>'))) { $best = $m; } }
    self::$local = $best ?? [];
    return $best; }

  public static function forget(): void { self::$local = null; self::$sums = null; }

  private static ?array $sums = null;

  /**
   * sha512 (base64 of the raw digest, electron-builder format) of a mirrored file of the newest version, or null. Taken from
   * the manifest (recorded when mirrored), else from the mirrored latest*.yml, else hashed once and cached next to the mirror
   * (sha512.json), so large installers are never hashed per request.
   */
  public static function sha512(string $name): ?string {
    $m = self::local();
    $p = self::path($name);
    if (!$m || !$p) { return null; }
    if (!empty($m['files'][$name]['sha512'])) { return (string) $m['files'][$name]['sha512']; }
    $dir = dirname($p);
    if (self::$sums === null) {
      $c = is_file($dir.'/sha512.json') ? json_decode((string) file_get_contents($dir.'/sha512.json'), true) : null;
      self::$sums = is_array($c) ? $c : [];
      foreach (self::UPDATE_FILES as $y) { if ($yp = self::path($y)) { self::$sums = self::ymlFiles((string) file_get_contents($yp)) + self::$sums; } } }
    if (!isset(self::$sums[$name])) {
      self::$sums[$name] = base64_encode(hash_file('sha512', $p, true));
      $c = is_file($dir.'/sha512.json') ? json_decode((string) file_get_contents($dir.'/sha512.json'), true) : null;
      @file_put_contents($dir.'/sha512.json', json_encode([$name => self::$sums[$name]] + (is_array($c) ? $c : []), JSON_UNESCAPED_SLASHES), LOCK_EX); }
    return self::$sums[$name]; }

  /** Absolute path of a mirrored file of the newest version, or null (names are checked against the manifest). */
  public static function path(string $name): ?string {
    $m = self::local();
    if (!$m || !isset($m['files'][$name]) || $name !== basename($name)) { return null; }
    $p = self::dir().'/'.$m['version'].'/'.$name;
    return is_file($p) ? $p : null; }

  /**
   * Downloads per platform from the local mirror: ['ok', 'connected', 'version', 'date', 'synced_at', 'platforms' =>
   * [platform => [files...]]] where each file has name, arch, ext, size, version, date and url (server download route).
   */
  public static function latest(): array {
    $m = self::local();
    $out = ['ok' => $m !== null, 'connected' => self::connected(), 'version' => $m['version'] ?? null, 'date' => $m['published_at'] ?? null, 'synced_at' => $m['synced_at'] ?? null, 'repo' => self::repo(), 'platforms' => array_fill_keys(array_keys(self::PLATFORMS), [])];
    if (!$m) { return $out; }
    $all = [];
    foreach ($m['files'] as $n => $f) { if (isset($f['platform'])) { $all[] = $f + ['name' => $n, 'version' => $m['version'], 'date' => $m['published_at'], 'url' => url('/time/desktop/download/'.rawurlencode($n))]; } }
    foreach (array_keys(self::PLATFORMS) as $p) {
      $mine = array_values(array_filter($all, static fn ($f) => $f['platform'] === $p));
      usort($mine, static fn ($a, $b) => [$a['ext'] === 'zip', $a['arch'] !== 'x64' && $p !== 'mac', $a['arch']] <=> [$b['ext'] === 'zip', $b['arch'] !== 'x64' && $p !== 'mac', $b['arch']]);
      $out['platforms'][$p] = $mine; }
    return $out; }

  /**
   * electron-updater feed file (latest*.yml of the mirrored version) with url/path entries pointing at
   * /api/agent/updates/<file> on this server. @return array{0:int,1:string} HTTP status and body.
   */
  public static function updateInfo(string $file): array {
    if (!in_array($file, self::UPDATE_FILES, true)) { return [404, 'Unknown update file.']; }
    $p = self::path($file);
    if (!$p) { return [404, 'No mirrored Niyanta Tracker release contains '.$file.'.']; }
    return [200, self::rewriteYml((string) file_get_contents($p))]; }

  /** Rewrite `url:` / `path:` entries of an electron-builder update file to this server's /api/agent/updates/<name>. */
  public static function rewriteYml(string $yml): string {
    return (string) preg_replace_callback('/^([ \t]*(?:-[ \t]+)?(?:url|path):[ \t]*)([\'"]?)([^\'"\r\n]+?)\2[ \t]*$/m', static function (array $m): string {
      $name = basename(str_replace('\\', '/', trim($m[3])));
      return $m[1]."'".str_replace("'", "''", absolute_url('/api/agent/updates/'.rawurlencode($name)))."'"; }, $yml); } }
