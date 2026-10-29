<?php
namespace Modules\Attendance\Services;
use Core\{Audit, Cache, ErrorLog, Settings};

/**
 * Niyanta Tracker downloads. Two sources, merged per platform (the newer version wins):
 * (a) installers uploaded by a Super Admin, kept privately in storage/desktop/<version>/ and served only to signed-in
 *     people who track time; (b) the latest GitHub release tagged tracker-v* of the configured repository (cached 30 min).
 */
final class DesktopApp {
  /** platform => [label, hint, extensions, icon] */
  public const PLATFORMS = [
    'windows' => ['Windows', 'Windows 10 and 11 (64-bit) installer', ['exe'], 'monitor'],
    'mac' => ['macOS', 'macOS 11 or later, Apple silicon and Intel', ['dmg', 'zip'], 'monitor'],
    'linux' => ['Linux AppImage', 'Any modern distribution, no install needed', ['appimage'], 'monitor'],
    'deb' => ['Linux .deb', 'Debian, Ubuntu, Mint and derivatives', ['deb'], 'monitor']];
  public const ARCHES = ['x64' => 'Intel / AMD 64-bit', 'arm64' => 'ARM 64-bit (Apple silicon)', 'universal' => 'Universal'];
  public const TAG_PREFIX = 'tracker-v';
  private const MAX_BYTES = 400 * 1048576;

  public static function dir(): string {
    $d = NY_ROOT.'/storage/desktop';
    if (!is_dir($d)) { @mkdir($d, 0775, true); }
    if (!is_file($d.'/.htaccess')) { @file_put_contents($d.'/.htaccess', "Require all denied\nDeny from all\n"); }
    return $d; }

  /** GitHub repository (owner/name): the tracker setting, else the update repository. */
  public static function repo(): ?string {
    $r = trim((string) Settings::get('tracker.github_repo', '')) ?: trim((string) Settings::get('updates.repo', ''));
    return preg_match('#^[A-Za-z0-9_.\-]+/[A-Za-z0-9_.\-]+$#', $r) ? $r : null; }

  public static function validVersion(string $v): bool { return (bool) preg_match('/^\d{1,4}\.\d{1,4}\.\d{1,5}(-[0-9A-Za-z.]{1,20})?$/', $v); }

  /** Platform and architecture from an installer file name (electron-builder artifact names). */
  public static function classify(string $name): ?array {
    $n = strtolower($name);
    $ext = str_ends_with($n, '.appimage') ? 'appimage' : pathinfo($n, PATHINFO_EXTENSION);
    $platform = match ($ext) { 'exe' => 'windows', 'dmg' => 'mac', 'appimage' => 'linux', 'deb' => 'deb', 'zip' => str_contains($n, 'mac') || str_contains($n, 'darwin') ? 'mac' : null, default => null };
    if (!$platform || str_ends_with($n, '.blockmap')) { return null; }
    $arch = str_contains($n, 'arm64') || str_contains($n, 'aarch64') ? 'arm64' : (str_contains($n, 'universal') ? 'universal' : 'x64');
    return ['platform' => $platform, 'arch' => $arch, 'ext' => $ext]; }

  /** Uploaded installers: list of [id, platform, arch, ext, version, path, name, size, uploaded_at, by]. */
  public static function uploads(): array { return array_values(array_filter(Settings::json('tracker.uploads'), static fn ($u) => is_array($u) && isset($u['id'], $u['path']))); }

  public static function upload(array $file, string $version, int $by): array {
    if (!self::validVersion($version)) { throw new \DomainException('Version must look like 1.4.0.'); }
    if (($file['error'] ?? 1) !== UPLOAD_ERR_OK || (!is_uploaded_file($file['tmp_name']) && PHP_SAPI !== 'cli')) { throw new \DomainException('Upload failed. Check the server upload limit (upload_max_filesize / post_max_size).'); }
    if ((int) $file['size'] > self::MAX_BYTES) { throw new \DomainException('Installer is too large (max 400 MB).'); }
    $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', basename((string) $file['name']));
    $c = self::classify($name) ?? throw new \DomainException('Unrecognised installer. Upload a .exe, .dmg, mac .zip, .AppImage or .deb file.');
    $head = (string) file_get_contents($file['tmp_name'], false, null, 0, 8);
    $magic = ['exe' => str_starts_with($head, 'MZ'), 'deb' => str_starts_with($head, '!<arch>'), 'zip' => str_starts_with($head, "PK\x03\x04"), 'appimage' => str_starts_with($head, "\x7fELF"), 'dmg' => true];
    if (!($magic[$c['ext']] ?? false)) { throw new \DomainException('The file content does not match a '.strtoupper($c['ext']).' installer.'); }
    $dir = self::dir().'/'.$version;
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) { throw new \RuntimeException('Cannot create storage/desktop/'.$version); }
    $ok = PHP_SAPI === 'cli' ? rename($file['tmp_name'], "$dir/$name") : move_uploaded_file($file['tmp_name'], "$dir/$name");
    if (!$ok) { throw new \RuntimeException('Could not save the installer. Check that storage/desktop is writable.'); }
    $row = ['id' => bin2hex(random_bytes(8)), 'version' => $version, 'path' => "$version/$name", 'name' => $name, 'size' => (int) filesize("$dir/$name"), 'uploaded_at' => date('Y-m-d H:i:s'), 'by' => $by] + $c;
    $list = array_values(array_filter(self::uploads(), static fn ($u) => $u['path'] !== $row['path']));
    $list[] = $row;
    Settings::set('tracker.uploads', $list);
    Audit::log('tracker.installer_upload', 'settings', null, ['version' => $version, 'file' => $name, 'platform' => $c['platform']], $by);
    return $row; }

  public static function remove(string $id, int $by): bool {
    $list = self::uploads(); $keep = []; $gone = null;
    foreach ($list as $u) { if ($u['id'] === $id) { $gone = $u; } else { $keep[] = $u; } }
    if (!$gone) { return false; }
    @unlink(self::dir().'/'.str_replace('..', '', $gone['path']));
    @rmdir(self::dir().'/'.$gone['version']);
    Settings::set('tracker.uploads', $keep);
    Audit::log('tracker.installer_delete', 'settings', null, ['version' => $gone['version'], 'file' => $gone['name']], $by);
    return true; }

  public static function find(string $id): ?array { foreach (self::uploads() as $u) { if ($u['id'] === $id) { return $u; } } return null; }
  public static function filePath(array $u): string { return self::dir().'/'.str_replace('..', '', $u['path']); }

  /** Latest tracker-v* release of the repository (cached 30 minutes; failures cached 5 minutes and logged). */
  public static function github(bool $refresh = false): ?array {
    $repo = self::repo();
    if (!$repo || (defined('NY_TESTING') && !Settings::bool('tracker.github_in_tests'))) { return null; }
    $key = 'tracker_release_'.md5($repo);
    if ($refresh) { Cache::forget($key); }
    $hit = Cache::get($key);
    if (is_array($hit)) { return $hit['release'] ?? null; }
    $release = null; $ttl = 1800;
    try { $release = self::fetchGithub($repo); }
    catch (\Throwable $e) { $ttl = 300; ErrorLog::record('server', 'warning', 'Desktop app release lookup failed for '.$repo.': '.$e->getMessage(), __FILE__, __LINE__); }
    Cache::put($key, ['release' => $release, 'checked_at' => date('c')], $ttl);
    return $release; }

  private static function fetchGithub(string $repo): ?array {
    if (!function_exists('curl_init')) { throw new \RuntimeException('PHP cURL extension missing'); }
    $h = ['User-Agent: Niyanta/'.NY_VERSION, 'Accept: application/vnd.github+json', 'X-GitHub-Api-Version: 2022-11-28'];
    if ($t = (string) Settings::get('updates.github_token', '')) { $h[] = 'Authorization: Bearer '.$t; }
    $c = curl_init('https://api.github.com/repos/'.$repo.'/releases?per_page=30');
    curl_setopt_array($c, [CURLOPT_HTTPHEADER => $h, CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 15, CURLOPT_SSL_VERIFYPEER => true]);
    $body = curl_exec($c); $code = (int) curl_getinfo($c, CURLINFO_RESPONSE_CODE); $err = curl_error($c); curl_close($c);
    if ($body === false) { throw new \RuntimeException('network error: '.$err); }
    if ($code !== 200) { throw new \RuntimeException('GitHub answered HTTP '.$code); }
    $list = json_decode((string) $body, true);
    if (!is_array($list)) { throw new \RuntimeException('unexpected GitHub response'); }
    return self::pickRelease($list); }

  /** The newest published (non-draft, non-prerelease) tracker-v* release from a GitHub releases list, with assets grouped by platform. */
  public static function pickRelease(array $list): ?array {
    $best = null;
    foreach ($list as $r) {
      $tag = (string) ($r['tag_name'] ?? '');
      if (!empty($r['draft']) || !empty($r['prerelease']) || !str_starts_with($tag, self::TAG_PREFIX)) { continue; }
      $v = substr($tag, strlen(self::TAG_PREFIX));
      if (!self::validVersion($v) || ($best && version_compare($v, $best['version'], '<='))) { continue; }
      $assets = [];
      foreach ($r['assets'] ?? [] as $a) {
        $c = self::classify((string) ($a['name'] ?? ''));
        if ($c) { $assets[] = $c + ['name' => (string) $a['name'], 'size' => (int) ($a['size'] ?? 0), 'url' => (string) ($a['browser_download_url'] ?? ''), 'version' => $v, 'date' => (string) ($r['published_at'] ?? ''), 'source' => 'github']; } }
      $best = ['version' => $v, 'tag' => $tag, 'published_at' => (string) ($r['published_at'] ?? ''), 'html_url' => (string) ($r['html_url'] ?? ''), 'notes' => mb_substr((string) ($r['body'] ?? ''), 0, 4000), 'assets' => $assets]; }
    return $best; }

  /**
   * Latest downloads per platform: ['version', 'date', 'platforms' => [platform => [files...]]] where each file has
   * name, arch, ext, size, version, date, source (upload|github) and url (download route or GitHub URL).
   */
  public static function latest(): array {
    $files = [];
    foreach (self::uploads() as $u) { $files[] = $u + ['date' => $u['uploaded_at'], 'source' => 'upload', 'url' => url('/time/desktop/download?f='.$u['id'])]; }
    $gh = self::github();
    foreach ($gh['assets'] ?? [] as $a) { $files[] = $a; }
    $out = ['version' => null, 'date' => null, 'platforms' => array_fill_keys(array_keys(self::PLATFORMS), []), 'release_url' => $gh['html_url'] ?? null, 'repo' => self::repo()];
    foreach (array_keys(self::PLATFORMS) as $p) {
      $mine = array_values(array_filter($files, static fn ($f) => $f['platform'] === $p));
      if (!$mine) { continue; }
      $top = array_reduce($mine, static fn ($c, $f) => $c === null || version_compare($f['version'], $c, '>') ? $f['version'] : $c);
      $mine = array_values(array_filter($mine, static fn ($f) => $f['version'] === $top));
      $seen = []; $pick = [];
      usort($mine, static fn ($a, $b) => [$a['source'] !== 'upload', $a['ext'] === 'zip', $a['arch']] <=> [$b['source'] !== 'upload', $b['ext'] === 'zip', $b['arch']]);
      foreach ($mine as $f) { $k = $f['arch'].'.'.$f['ext']; if (!isset($seen[$k])) { $seen[$k] = 1; $pick[] = $f; } }
      $out['platforms'][$p] = $pick;
      if ($out['version'] === null || version_compare($top, $out['version'], '>')) { $out['version'] = $top; $out['date'] = $pick[0]['date']; } }
    return $out; } }
