<?php
use Core\{Auth, Crypto, DB, Settings};
use Modules\Attendance\Services\{DesktopApp, DesktopInstall};

/** A mirrored tracker release in a temp folder (manifest + update files with real sha512), like DesktopApp::sync() leaves it. */
function inst_mirror(): array {
  $root = sys_get_temp_dir().'/ny-install-'.bin2hex(random_bytes(4));
  $v = '1.4.0'; $dir = "$root/$v"; mkdir($dir, 0775, true);
  $bin = ['niyanta-tracker-1.4.0-win-x64.exe' => 'MZ'.str_repeat('w', 200), 'niyanta-tracker-1.4.0-mac-arm64.zip' => 'PKarm', 'niyanta-tracker-1.4.0-mac-x64.zip' => 'PKx64', 'niyanta-tracker-1.4.0-mac-arm64.dmg' => 'dmg',
    'niyanta-tracker-1.4.0-linux-x86_64.AppImage' => "\x7fELF".str_repeat('a', 100)];
  $sha = static fn (string $n): string => base64_encode(hash('sha512', $bin[$n], true));
  $yml = static fn (array $names): string => "version: $v\nfiles:\n".implode('', array_map(static fn ($n) => "  - url: $n\n    sha512: ".$sha($n)."\n    size: ".strlen($bin[$n])."\n", $names))."path: {$names[0]}\n";
  $bin['latest.yml'] = $yml(['niyanta-tracker-1.4.0-win-x64.exe']);
  $bin['latest-mac.yml'] = $yml(['niyanta-tracker-1.4.0-mac-x64.zip', 'niyanta-tracker-1.4.0-mac-arm64.zip']);
  $bin['latest-linux.yml'] = $yml(['niyanta-tracker-1.4.0-linux-x86_64.AppImage']);
  $files = [];
  foreach ($bin as $n => $c) { file_put_contents("$dir/$n", $c); $files[$n] = ['size' => strlen($c)] + (DesktopApp::classify($n) ?? []); }
  file_put_contents("$dir/manifest.json", json_encode(['version' => $v, 'tag' => 'tracker-v'.$v, 'name' => 'Niyanta Tracker v'.$v, 'published_at' => '2026-11-01T08:00:00Z', 'synced_at' => date('Y-m-d H:i:s'), 'files' => $files]));
  DesktopApp::$root = $root; DesktopApp::forget();
  return ['root' => $root, 'bin' => $bin]; }

function inst_cleanup(string $root): void { DesktopApp::$root = null; DesktopApp::forget(); exec('rm -rf '.escapeshellarg($root)); }

/** Fetch a public install route as a guest (no session), like curl / PowerShell do. */
function inst_get(string $path): Core\Response { act_as(null); unset($_SERVER['HTTP_AUTHORIZATION'], $_SERVER['HTTP_ACCEPT']); return http('GET', $path, [], false); }

return [
  'install keys: signed per user, 2 hours, bound to purpose, session version, status and permission' => function () {
    $emp = make_user('employee', 'emp@install.test');
    $now = time();
    $k = DesktopInstall::key($emp, $now);
    assert_true((bool) preg_match('/^'.$emp.'-\d{10}-[A-Za-z0-9_-]{32}$/', $k), 'key format: '.$k);
    assert_eq($now + 7200, DesktopInstall::expires($k), 'expires in 2 hours');
    assert_eq($emp, (int) (DesktopInstall::user($k)['id'] ?? 0), 'valid key');
    assert_true(!isset(DesktopInstall::user($k)['password_hash']), 'no password hash');
    [$uid, $exp, $sig] = explode('-', $k, 3);
    $flip = $sig[0] === 'A' ? 'B' : 'A';
    assert_eq(null, DesktopInstall::user("$uid-$exp-".$flip.substr($sig, 1)), 'tampered signature');
    assert_eq(null, DesktopInstall::user("$uid-".($exp + 60)."-$sig"), 'longer expiry needs a new signature');
    $other = make_user('employee', 'other@install.test');
    assert_eq(null, DesktopInstall::user("$other-$exp-$sig"), 'not transferable to another user');
    assert_eq(null, DesktopInstall::user($k, $now + 7201), 'expired after 2 hours');
    assert_eq(null, DesktopInstall::user(DesktopInstall::key($emp, $now + 3 * 3600), $now), 'not valid longer than 2 hours ahead');
    foreach (['', 'abc', "$emp-$exp", "$emp-$exp-$sig-x", "0-$exp-$sig", "$emp-$exp-".str_repeat('a', 31).'!'] as $bad) { assert_eq(null, DesktopInstall::user($bad), 'malformed: '.$bad); }
    $raw = substr(Crypto::sign('other-purpose', "$emp|$exp|".DB::value('SELECT session_version FROM users WHERE id=?', [$emp])), 0, 24);
    assert_eq(null, DesktopInstall::user("$emp-$exp-".rtrim(strtr(base64_encode($raw), '+/', '-_'), '=')), 'a signature for another purpose does not verify');
    Auth::bumpSessions($emp);
    assert_eq(null, DesktopInstall::user($k), 'sign out everywhere / password change revokes it');
    $k = DesktopInstall::key($emp);
    DB::update('users', ['status' => 'inactive'], 'id=?', [$emp]);
    assert_eq(null, DesktopInstall::user($k), 'inactive user');
    DB::update('users', ['status' => 'active'], 'id=?', [$emp]);
    $role = DB::insert('roles', ['slug' => 'contractor_inst', 'name' => 'Contractor', 'created_at' => date('Y-m-d H:i:s')]);
    $con = make_user('contractor_inst', 'con@install.test');
    assert_eq(null, DesktopInstall::user(DesktopInstall::key($con)), 'role without attendance.track');
    assert_true(DesktopInstall::user($k) !== null, 'still valid for the employee'); },

  'one-line install: scripts and files work with the key only, are safe, verified, throttled and audited' => function () {
    $emp = (int) DB::value("SELECT id FROM users WHERE email='emp@install.test'");
    ['root' => $root, 'bin' => $bin] = inst_mirror();
    $oldTok = (string) Settings::get('updates.github_token', '');
    Settings::set('updates.github_token', 'github_pat_SECRETunitTOKEN123');
    try {
      act_as($emp);
      $html = (string) http('GET', '/time')->body();
      assert_true(str_contains($html, 'Install with one command (no security warnings)') && str_contains($html, 'data-ol="windows"') && str_contains($html, 'data-ol="mac"'), 'commands on the download card');
      assert_true((bool) preg_match('#curl -fsSL &quot;http://localhost/time/desktop/install\.sh\?k=('.$emp.'-\d{10}-[A-Za-z0-9_-]{32})&quot; \| bash#', $html, $m), 'curl | bash command with a personal key');
      $k = $m[1];
      assert_true(str_contains($html, 'irm &quot;http://localhost/time/desktop/install.ps1?k='.$k.'&quot; | iex'), 'PowerShell command');
      assert_true(!str_contains($html, 'SECRETunitTOKEN'), 'GitHub token never on the page');

      // bash script (macOS + Linux)
      $r = inst_get('/time/desktop/install.sh?k='.$k);
      $sh = $r->body();
      assert_true($r->status() === 200 && str_starts_with((string) ($r->headers()['Content-Type'] ?? ''), 'text/plain') && str_contains((string) ($r->headers()['Cache-Control'] ?? ''), 'no-store'), 'plain text, not cached');
      assert_true(str_starts_with($sh, "#!/usr/bin/env bash\n") && str_contains($sh, "set -euo pipefail\n") && str_ends_with($sh, "main \"\$@\"\n"), 'strict mode, everything inside main()');
      assert_true(str_contains($sh, "BASE='http://localhost/time/desktop/get/'") && str_contains($sh, "KEY='$k'") && str_contains($sh, "VERSION='1.4.0'"), 'workspace URL, key and version');
      $hex = static fn (string $n): string => hash('sha512', $bin[$n]);
      assert_true(str_contains($sh, "MAC_ARM64='niyanta-tracker-1.4.0-mac-arm64.zip'") && str_contains($sh, "MAC_ARM64_SHA='".$hex('niyanta-tracker-1.4.0-mac-arm64.zip')."'") && str_contains($sh, "MAC_X64_SHA='".$hex('niyanta-tracker-1.4.0-mac-x64.zip')."'"), 'macOS zips with sha512 (hex)');
      assert_true(str_contains($sh, "LINUX='niyanta-tracker-1.4.0-linux-x86_64.AppImage'") && str_contains($sh, "LINUX_SHA='".$hex('niyanta-tracker-1.4.0-linux-x86_64.AppImage')."'"), 'Linux AppImage with sha512');
      assert_true(str_contains($sh, 'ditto -x -k') && str_contains($sh, 'xattr -cr "$dest"') && str_contains($sh, '/Applications') && str_contains($sh, '$HOME/Applications') && str_contains($sh, '.local/bin') && str_contains($sh, 'niyanta-tracker.desktop'), 'extracts, clears quarantine, user folders');
      assert_true(!str_contains($sh, 'SECRETunitTOKEN') && !str_contains($sh, 'github.com') && !preg_match('/[^\x09\x0a\x20-\x7e]/', $sh), 'no token, no GitHub, ASCII only');
      $tmp = tempnam(sys_get_temp_dir(), 'inst'); file_put_contents($tmp, $sh);
      exec('bash -n '.escapeshellarg($tmp).' 2>&1', $o, $rc); assert_eq(0, $rc, 'bash -n: '.implode("\n", $o));
      assert_true((bool) DB::value("SELECT 1 FROM audit_logs WHERE action='tracker.install_script' AND user_id=? AND meta LIKE '%\"sh\"%'", [$emp]), 'script fetch audited for the key owner');

      // PowerShell script (Windows)
      $ps = inst_get('/time/desktop/install.ps1?k='.$k)->body();
      assert_true(str_starts_with($ps, '# Niyanta Tracker installer for Windows') && str_contains($ps, "& {\n") && str_ends_with(rtrim($ps), '}'), 'runs in a script block');
      assert_true(str_contains($ps, "\$url = 'http://localhost/time/desktop/get/niyanta-tracker-1.4.0-win-x64.exe?k=$k'") && str_contains($ps, "\$sha = '".strtoupper($hex('niyanta-tracker-1.4.0-win-x64.exe'))."'"), 'installer url and sha512');
      assert_true(str_contains($ps, 'Invoke-WebRequest -Uri $url -OutFile $out -UseBasicParsing') && str_contains($ps, "-ArgumentList '/S' -Wait") && str_contains($ps, 'Get-FileHash -Algorithm SHA512') && str_contains($ps, 'Start-Process -FilePath $exe'), 'downloads without a browser, checks, installs silently, starts the app');
      assert_true(!preg_match('/^\s*exit\b/mi', $ps) && !str_contains($ps, 'SECRETunitTOKEN') && !preg_match('/[^\x09\x0a\x20-\x7e]/', $ps), 'never exits the window, no token, ASCII only');
      assert_eq(substr_count($ps, '{'), substr_count($ps, '}'), 'balanced braces');

      // expired or forged keys: a script that only explains
      foreach (['/time/desktop/install.sh?k=', '/time/desktop/install.sh?k='.$emp.'-'.(time() - 10).'-'.substr($k, -32)] as $p) {
        $b = inst_get($p)->body();
        assert_true(str_contains($b, 'expired or is not valid') && str_contains($b, 'exit 1') && !str_contains($b, 'BASE='), 'invalid: explains only'); }
      assert_true(str_contains(inst_get('/time/desktop/install.ps1?k=nope')->body(), 'expired or is not valid'), 'ps1 invalid');

      // installer files
      $r = inst_get('/time/desktop/get/niyanta-tracker-1.4.0-mac-arm64.zip?k='.$k);
      assert_true($r->status() === 200 && ($r->headers()['Content-Length'] ?? '') === '5' && str_starts_with((string) ($r->headers()['Content-Disposition'] ?? ''), 'attachment;'), 'zip served: '.$r->status());
      assert_true((bool) DB::value("SELECT 1 FROM audit_logs WHERE action='tracker.download' AND user_id=? AND meta LIKE '%install command%'", [$emp]), 'download audited');
      assert_eq(403, inst_get('/time/desktop/get/niyanta-tracker-1.4.0-mac-arm64.zip?k=bad')->status(), 'needs the key');
      assert_eq(403, inst_get('/time/desktop/get/niyanta-tracker-1.4.0-mac-arm64.zip')->status(), 'no key');
      foreach (['latest-mac.yml', 'manifest.json', '..%2Fmanifest.json', 'niyanta-tracker-9.9.9-win-x64.exe'] as $n) { assert_eq(404, inst_get('/time/desktop/get/'.$n.'?k='.$k)->status(), 'only installers: '.$n); }

      // throttled per address
      for ($i = 0; $i < 310 && ($s = inst_get('/time/desktop/install.ps1?k='.$k)->status()) === 200; $i++) { }
      assert_eq(429, $s, 'throttled');
      DB::query("DELETE FROM rate_limits"); }
    finally { Settings::set('updates.github_token', $oldTok); inst_cleanup($root); } },

  'one-line install: nothing mirrored yet, and plain-HTTP workspaces get no commands' => function () {
    $emp = (int) DB::value("SELECT id FROM users WHERE email='emp@install.test'");
    $root = sys_get_temp_dir().'/ny-install-empty-'.bin2hex(random_bytes(4));
    DesktopApp::$root = $root; DesktopApp::forget();
    try {
      $k = DesktopInstall::key($emp);
      assert_true(str_contains(inst_get('/time/desktop/install.sh?k='.$k)->body(), 'not available on this workspace yet'), 'nothing to install');
      act_as($emp);
      assert_true(!str_contains((string) http('GET', '/time')->body(), 'Install with one command'), 'no commands without a release');
      $cfg = \Core\App::config('app_url');
      \Core\App::setConfig(['app_url' => 'http://team.example.com'] + \Core\App::config());
      assert_true(!DesktopInstall::available(), 'http workspace: no curl | bash');
      \Core\App::setConfig(['app_url' => 'https://team.example.com'] + \Core\App::config());
      assert_true(DesktopInstall::available(), 'https workspace');
      \Core\App::setConfig(['app_url' => $cfg] + \Core\App::config()); }
    finally { inst_cleanup($root); } },
];
