<?php
namespace Modules\Attendance\Services;
use Core\{Crypto, DB, Gate, Modules};

/**
 * One-line installs of Niyanta Tracker without browser or OS blockers. Files a browser downloads get the Mark-of-the-Web
 * (Windows SmartScreen "Windows protected your PC", Chrome "uncommon download") or the quarantine flag (macOS "damaged and
 * can't be opened" for an ad-hoc signed app); curl and PowerShell's Invoke-WebRequest add neither. The Time Tracking page
 * shows per OS:
 *   macOS / Linux: curl -fsSL "<workspace>/time/desktop/install.sh?k=<key>" | bash
 *   Windows:       irm "<workspace>/time/desktop/install.ps1?k=<key>" | iex   (saves the setup to Downloads and opens it)
 * The scripts are generated here with this workspace's URL, the mirrored file names and their sha512 (from latest*.yml), and
 * fetch the installers from /time/desktop/get/{file}?k=<key>. No browser session is needed there, so the key is the only
 * credential: "<user id>-<expiry>-<signature>", the signature being an HMAC (app key, purpose-bound subkey) of the purpose,
 * the user id, the expiry and the user's session version. It works for 2 hours, only for these install routes, only while
 * the user is active and may track time, and stops working when they sign out everywhere or change their password.
 * Each generated script also carries a one-time connect code (DesktopConnect, 30 minutes) and opens
 * niyanta-tracker://connect after installing, so a fresh install signs in by itself.
 */
final class DesktopInstall {
  public const TTL = 7200;
  private const PURPOSE = 'tracker-install.v1';

  /* ---------- signed keys ---------- */

  public static function key(int $uid, ?int $now = null): string {
    $exp = ($now ?? time()) + self::TTL;
    $sv = (int) DB::value('SELECT session_version FROM users WHERE id=?', [$uid]);
    return $uid.'-'.$exp.'-'.self::sig($uid, $exp, $sv); }

  private static function sig(int $uid, int $exp, int $sv): string {
    return rtrim(strtr(base64_encode(substr(Crypto::sign(self::PURPOSE, $uid.'|'.$exp.'|'.$sv), 0, 24)), '+/', '-_'), '='); }

  /** Expiry (unix time) of a well-formed key, for display; null when malformed. */
  public static function expires(string $key): ?int { return preg_match('/^\d{1,10}-(\d{10})-/', $key, $m) ? (int) $m[1] : null; }

  /** The active user a key belongs to (with role_slug), or null: malformed, expired, too far in the future, wrong signature, user gone, or not allowed to track. */
  public static function user(string $key, ?int $now = null): ?array {
    $now ??= time();
    if (!preg_match('/^([1-9]\d{0,9})-(\d{10})-([A-Za-z0-9_-]{32})$/', $key, $m)) { return null; }
    [$uid, $exp] = [(int) $m[1], (int) $m[2]];
    if ($exp < $now || $exp > $now + self::TTL + 300) { return null; }
    $u = DB::first("SELECT u.*, r.slug AS role_slug, r.name AS role_name FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=? AND u.status='active' LIMIT 1", [$uid]);
    if (!$u || !hash_equals(self::sig($uid, $exp, (int) $u['session_version']), $m[3])) { return null; }
    if (!Modules::enabled('attendance') || !feature_on('attendance') || !Gate::allows('attendance.track', $u)) { return null; }
    unset($u['password_hash']);
    return $u; }

  /** One-line commands are offered only over HTTPS (or on a local development address): a script piped into a shell must not travel in clear text. */
  public static function available(): bool {
    $u = parse_url(absolute_url('/'));
    return ($u['scheme'] ?? '') === 'https' || in_array($u['host'] ?? '', ['localhost', '127.0.0.1', '::1'], true); }

  /** ['mac' => ..., 'linux' => ..., 'windows' => ...] commands to paste into a terminal / PowerShell. */
  public static function commands(string $key): array {
    $sh = absolute_url('/time/desktop/install.sh').'?k='.$key;
    $cmd = 'curl -fsSL "'.$sh.'" | bash';
    return ['windows' => 'irm "'.absolute_url('/time/desktop/install.ps1').'?k='.$key.'" | iex', 'mac' => $cmd, 'linux' => $cmd]; }

  /* ---------- what the scripts download ---------- */

  /**
   * Files of the mirrored release the scripts install: version, the macOS zips (x64 / arm64), the Linux AppImage and the
   * Windows installer, each with its sha512 as hex ('' when the update file does not list it).
   */
  public static function files(): ?array {
    $m = DesktopApp::local();
    if (!$m) { return null; }
    $sums = [];
    foreach (DesktopApp::UPDATE_FILES as $y) { if ($p = DesktopApp::path($y)) { $sums += DesktopApp::ymlFiles((string) file_get_contents($p)); } }
    $pick = static function (string $platform, string $ext, string $arch) use ($m, $sums): ?array {
      foreach ($m['files'] as $n => $f) {
        if (($f['platform'] ?? '') === $platform && ($f['ext'] ?? '') === $ext && ($f['arch'] ?? '') === $arch && DesktopApp::path($n)) {
          $raw = isset($sums[$n]) ? base64_decode($sums[$n], true) : false;
          return ['name' => $n, 'sha512' => $raw !== false && strlen($raw) === 64 ? bin2hex($raw) : '']; } }
      return null; };
    return ['version' => $m['version'], 'mac_arm64' => $pick('mac', 'zip', 'arm64'), 'mac_x64' => $pick('mac', 'zip', 'x64'), 'linux' => $pick('linux', 'appimage', 'x64'), 'windows' => $pick('windows', 'exe', 'x64')]; }

  public static function fileBase(): string { return absolute_url('/time/desktop/get/'); }

  /* ---------- generated scripts ---------- */

  private static function sq(string $s): string { return "'".str_replace("'", "'\\''", $s)."'"; }
  private static function pq(string $s): string { return "'".str_replace("'", "''", $s)."'"; }

  /** A script that only explains why nothing is installed (expired link, nothing mirrored yet). */
  public static function shellError(string $msg): string {
    return "#!/usr/bin/env bash\n# Niyanta Tracker installer\nprintf '\\033[1;31mNiyanta Tracker:\\033[0m %s\\n' ".self::sq($msg)." >&2\nexit 1\n"; }

  public static function powershellError(string $msg): string {
    return "# Niyanta Tracker installer\nWrite-Host ".self::pq('Niyanta Tracker: '.$msg)." -ForegroundColor Red\n"; }

  /** macOS + Linux installer (bash). Everything runs inside main(), so a cut-off download never runs half a script. */
  public static function shell(string $key, array $f, string $connect = ''): string {
    $v = static fn (?array $x, string $k): string => self::sq((string) ($x[$k] ?? ''));
    $head = implode("\n", [
      'BASE='.self::sq(self::fileBase()),
      'KEY='.self::sq($key),
      'CONNECT='.self::sq($connect),
      'VERSION='.self::sq($f['version']),
      'MAC_ARM64='.$v($f['mac_arm64'], 'name'), 'MAC_ARM64_SHA='.$v($f['mac_arm64'], 'sha512'),
      'MAC_X64='.$v($f['mac_x64'], 'name'), 'MAC_X64_SHA='.$v($f['mac_x64'], 'sha512'),
      'LINUX='.$v($f['linux'], 'name'), 'LINUX_SHA='.$v($f['linux'], 'sha512')]);
    $body = <<<'SH'
APP='Niyanta Tracker'
say() { printf '\033[1;35m==>\033[0m %s\n' "$*"; }
die() { printf '\033[1;31mError:\033[0m %s\n' "$*" >&2; exit 1; }

fetch() {
  curl -fL --retry 3 --retry-delay 2 --progress-bar -o "$2" "${BASE}${1}?k=${KEY}" \
    || die "Download failed. Copy a new command from Time Tracking (each command works for 2 hours) and try again."; }

verify() {
  local file="$1" want="$2" got=''
  if [ -z "$want" ]; then say "No checksum published for this file, skipping the check."; return 0; fi
  if command -v shasum >/dev/null 2>&1; then got="$(shasum -a 512 "$file" | awk '{print $1}')"
  elif command -v sha512sum >/dev/null 2>&1; then got="$(sha512sum "$file" | awk '{print $1}')"
  else say "No sha512 tool found, skipping the check."; return 0; fi
  [ "$got" = "$want" ] || die "The download is damaged (checksum mismatch). Please run the command again."
  say "Checksum OK"; }

install_mac() {
  local arch file sum dir dest src
  arch="$(uname -m)"
  if [ "$arch" = arm64 ] || [ "$(sysctl -in sysctl.proc_translated 2>/dev/null || echo 0)" = 1 ]; then file="$MAC_ARM64"; sum="$MAC_ARM64_SHA"; arch=arm64
  else file="$MAC_X64"; sum="$MAC_X64_SHA"; arch=x64; fi
  [ -n "$file" ] || die "This release has no build for your Mac ($arch) yet."
  say "Downloading $APP $VERSION for macOS ($arch)"
  fetch "$file" "$TMP/app.zip"
  verify "$TMP/app.zip" "$sum"
  ditto -x -k "$TMP/app.zip" "$TMP/x"
  src="$TMP/x/$APP.app"
  [ -d "$src" ] || die "The download does not contain the app."
  if [ -w /Applications ]; then dir=/Applications; else dir="$HOME/Applications"; mkdir -p "$dir"; fi
  dest="$dir/$APP.app"
  if pgrep -x "$APP" >/dev/null 2>&1; then
    say "Closing the running $APP (tracking is paused; press Resume after it opens)"
    osascript -e "quit app \"$APP\"" >/dev/null 2>&1 || true
    for _ in $(seq 1 40); do pgrep -x "$APP" >/dev/null 2>&1 || break; sleep 0.5; done
  fi
  rm -rf "$dest"
  ditto "$src" "$dest"
  xattr -cr "$dest" 2>/dev/null || true
  say "Installed to $dest"
  open "$dest"
  if [ -n "$CONNECT" ] && { sleep 3; open "$CONNECT" >/dev/null 2>&1; }; then say "Done. Niyanta Tracker signs you in by itself. Allow Screen Recording when macOS asks (needed for screenshots only)."
  else say "Done. Allow Screen Recording when macOS asks (needed for screenshots only), then sign in with your browser."; fi; }

install_linux() {
  local bin="$HOME/.local/bin" dest apps icons
  [ "$(uname -m)" = x86_64 ] || die "Niyanta Tracker for Linux needs a 64-bit Intel/AMD computer."
  [ -n "$LINUX" ] || die "This release has no Linux AppImage yet."
  dest="$bin/niyanta-tracker.AppImage"
  say "Downloading $APP $VERSION for Linux"
  fetch "$LINUX" "$TMP/app.AppImage"
  verify "$TMP/app.AppImage" "$LINUX_SHA"
  mkdir -p "$bin"
  chmod 755 "$TMP/app.AppImage"
  mv -f "$TMP/app.AppImage" "$dest"
  apps="${XDG_DATA_HOME:-$HOME/.local/share}/applications"
  icons="${XDG_DATA_HOME:-$HOME/.local/share}/icons/hicolor/512x512/apps"
  mkdir -p "$apps"
  if (cd "$TMP" && "$dest" --appimage-extract 'niyanta-tracker.png' >/dev/null 2>&1) && [ -f "$TMP/squashfs-root/niyanta-tracker.png" ]; then
    mkdir -p "$icons" && cp -f "$TMP/squashfs-root/niyanta-tracker.png" "$icons/niyanta-tracker.png" || true
  fi
  printf '[Desktop Entry]\nType=Application\nName=%s\nComment=Niyanta time tracker\nExec="%s" %%U\nIcon=niyanta-tracker\nTerminal=false\nCategories=Office;\nStartupWMClass=Niyanta Tracker\nMimeType=x-scheme-handler/niyanta-tracker;\n' "$APP" "$dest" > "$apps/niyanta-tracker.desktop"
  command -v update-desktop-database >/dev/null 2>&1 && update-desktop-database "$apps" >/dev/null 2>&1 || true
  command -v xdg-mime >/dev/null 2>&1 && xdg-mime default niyanta-tracker.desktop x-scheme-handler/niyanta-tracker >/dev/null 2>&1 || true
  say "Installed to $dest (and added to your applications menu)"
  if command -v ldconfig >/dev/null 2>&1 && ! ldconfig -p 2>/dev/null | grep -q 'libfuse\.so\.2'; then
    say "If the app does not open, install FUSE 2 first: sudo apt install libfuse2 (Ubuntu 24.04: libfuse2t64)"
  fi
  if [ -n "$CONNECT" ]; then nohup "$dest" "$CONNECT" >/dev/null 2>&1 & say "Done. Niyanta Tracker opens and signs you in by itself."
  else nohup "$dest" >/dev/null 2>&1 & say "Done. Open Niyanta Tracker and sign in with your browser."; fi; }

main() {
  TMP="$(mktemp -d "${TMPDIR:-/tmp}/niyanta-install.XXXXXX")"
  trap 'rm -rf "$TMP"' EXIT
  case "$(uname -s)" in
    Darwin) install_mac ;;
    Linux) install_linux ;;
    *) die "This installer is for macOS and Linux. On Windows use the PowerShell command from Time Tracking." ;;
  esac; }

main "$@"
SH;
    return "#!/usr/bin/env bash\n# Niyanta Tracker installer for macOS and Linux, generated by ".preg_replace('/[^\x20-\x7e]/', '', absolute_url('/'))."\n"
      ."# Downloads only from this workspace and checks the sha512. This link works until ".gmdate('Y-m-d H:i', (int) self::expires($key))." UTC.\n"
      ."set -euo pipefail\n".$head."\n".$body."\n"; }

  /**
   * Windows installer (PowerShell 5.1+). It does what a person would do, so antivirus behaviour rules (AMSI, Defender) see a
   * normal user install and not a "download cradle": the installer is saved to the person's Downloads folder with a visible
   * progress bar, its sha512 is checked, and it is started normally (no /S silent switch, no hidden window, no -Wait, no
   * Unblock-File, no TLS or execution-policy tweaks, nothing in %TEMP%); the person clicks through the setup, which starts the
   * app at the end. Never `exit` (it would close the window).
   */
  public static function powershell(string $key, array $f, string $connect = ''): string {
    $w = $f['windows'];
    $head = implode("\n", [
      '  $version = '.self::pq($f['version']),
      '  $url = '.self::pq(self::fileBase().rawurlencode($w['name']).'?k='.$key),
      '  $sha = '.self::pq(strtoupper($w['sha512'])),
      '  $name = '.self::pq($w['name']),
      '  $connect = '.self::pq($connect)]);
    $body = <<<'PS'
  $ErrorActionPreference = 'Stop'
  $dir = Join-Path $env:USERPROFILE 'Downloads'
  if (-not (Test-Path -LiteralPath $dir)) { $dir = [Environment]::GetFolderPath('Desktop') }
  $out = Join-Path $dir $name
  Write-Host "==> Downloading Niyanta Tracker $version to $dir" -ForegroundColor Magenta
  try { Invoke-WebRequest -Uri $url -OutFile $out -UseBasicParsing }
  catch { Write-Host 'Download failed. Copy a new command from Time Tracking (each command works for 2 hours) and try again.' -ForegroundColor Red; return }
  if ($sha) {
    $got = (Get-FileHash -Algorithm SHA512 -Path $out).Hash
    if ($got -ne $sha) { Remove-Item -LiteralPath $out -Force -ErrorAction SilentlyContinue; Write-Host 'The download is damaged (checksum mismatch). Please run the command again.' -ForegroundColor Red; return }
    Write-Host '==> Checksum OK' -ForegroundColor Magenta }
  Write-Host "==> Saved $out" -ForegroundColor Magenta
  try { Start-Process -FilePath $out }
  catch { Write-Host "Could not open the setup. Open $out yourself (double-click it in your Downloads folder)." -ForegroundColor Red; return }
  Write-Host '==> The Niyanta Tracker setup is open: follow it to the end (Next, Install, Finish).' -ForegroundColor Green
  Write-Host '    If Windows Security blocks the file, tell your Niyanta administrator: it can be reported to Microsoft as a false positive.' -ForegroundColor Green
  if (-not $connect) { Write-Host '    Niyanta Tracker then starts by itself: sign in with your browser.' -ForegroundColor Green; return }
  Write-Host '    Niyanta Tracker then starts and signs you in by itself. Keep this window open until it does.' -ForegroundColor Green
  $scheme = 'Registry::HKEY_CLASSES_ROOT\niyanta-tracker'
  $until = (Get-Date).AddMinutes(25)
  while (-not (Test-Path -LiteralPath $scheme) -and ((Get-Date) -lt $until)) { Start-Sleep -Seconds 3 }
  if (-not (Test-Path -LiteralPath $scheme)) { Write-Host 'Niyanta Tracker was not set up in time. Open it and choose Sign in with your browser.' -ForegroundColor Yellow; return }
  Start-Sleep -Seconds 4
  try { Start-Process -FilePath $connect; Write-Host '==> Niyanta Tracker is signing you in. You can close this window.' -ForegroundColor Green }
  catch { Write-Host 'Could not open Niyanta Tracker. Open it and choose Sign in with your browser.' -ForegroundColor Yellow }
PS;
    return "# Niyanta Tracker installer for Windows, generated by ".preg_replace('/[^\x20-\x7e]/', '', absolute_url('/'))."\n"
      ."# Downloads only from this workspace and checks the sha512. This link works until ".gmdate('Y-m-d H:i', (int) self::expires($key))." UTC.\n"
      ."& {\n".$head."\n".$body." }\n"; } }
