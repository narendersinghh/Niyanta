'use strict';
/*
 * Security regression checks, run by npm run check (plain Node, no Electron):
 * - static: BrowserWindow flags, sandbox, permission handlers, navigation lock, certificate errors, CSP and no inline scripts
 *   in the pages, the preload exposes only typed calls, every IPC handler is validated / sender-checked / rate limited, links
 *   leave the app through one allowlisted path, HTTPS enforcement and same-origin redirects in the HTTP client, the token
 *   and the screenshot key go through safeStorage, screenshots at rest are encrypted and bounded, update downloads are
 *   checksummed and file names sanitised, fuses config, package whitelist, no secrets in log lines
 * - functional (Electron stubbed): guard.js validators, allowlist, rate limiter, encryption round trip, the queue's
 *   encrypted files / orphan sweep / caps, normaliseUrl, log scrubbing, the fuse wire of a packed build when one exists
 * - sign in with the browser: niyanta-tracker:// is registered (package.json, installer.nsh, main.js) and every way a link
 *   arrives is handled; deep link parsing, PKCE (RFC 7636 vector), state matching and expiry, and the connect flow
 *   (src/main/connect.js with a fake tracker): confirmation, remembered hosts, already connected, mismatched / expired state
 */
const fs = require('fs');
const os = require('os');
const path = require('path');
const Module = require('module');
const root = path.join(__dirname, '..');
const read = f => fs.readFileSync(path.join(root, f), 'utf8').replace(/\r\n/g, '\n'); /* Windows checkouts may use CRLF */
const src = {};
for (const f of ['src/main/main.js', 'src/main/toast.js', 'src/main/api.js', 'src/main/connect.js', 'src/main/guard.js', 'build/installer.nsh', 'src/main/store.js', 'src/main/queue.js', 'src/main/updater.js', 'src/main/tracker.js', 'src/main/capture.js', 'src/main/errors.js', 'src/main/uninstall.js',
  'src/preload/preload.js', 'src/preload/toast-preload.js', 'src/renderer/index.html', 'src/renderer/toast.html', 'src/renderer/app.js', 'src/renderer/toast.js', 'src/shared/status.js', 'package.json']) src[f] = read(f);
const code = Object.entries(src).filter(([f]) => f.endsWith('.js') && f !== 'src/main/guard.js').map(([, s]) => s).join('\n');
/* source without comments and strings, for "this must not appear" checks */
const strip = s => s.replace(/\/\*[^]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');

let bad = 0, n = 0;
const ok = (c, m) => { n++; if (!c) { bad++; console.error('FAIL ' + m); } };
const eq = (a, b, m) => ok(JSON.stringify(a) === JSON.stringify(b), `${m}: expected ${JSON.stringify(b)}, got ${JSON.stringify(a)}`);
const has = (f, re, m) => ok(re.test(src[f]), `${f}: ${m}`);
const not = (f, re, m) => ok(!re.test(strip(src[f])), `${f}: ${m}`);

/* ---------- windows and the session ---------- */
const main = src['src/main/main.js'];
has('src/main/main.js', /^app\.enableSandbox\(\);/m, 'app.enableSandbox() before ready');
for (const f of ['src/main/main.js', 'src/main/toast.js']) {
  for (const flag of ['contextIsolation: true', 'sandbox: true', 'nodeIntegration: false', 'nodeIntegrationInWorker: false', 'nodeIntegrationInSubFrames: false', 'webviewTag: false', 'webSecurity: true', 'allowRunningInsecureContent: false', 'experimentalFeatures: false', 'plugins: false', 'navigateOnDragDrop: false']) has(f, new RegExp(flag.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')), 'webPreferences ' + flag);
  not(f, /enableRemoteModule|nodeIntegration: true|contextIsolation: false|sandbox: false|webSecurity: false|allowRunningInsecureContent: true/, 'no unsafe webPreferences'); }
has('src/main/main.js', /devTools: !app\.isPackaged/, 'devtools only in development');
has('src/main/toast.js', /devTools: false/, 'notice window has no devtools');
has('src/main/main.js', /setPermissionRequestHandler\(\([^)]*callback\) => callback\(false\)\)/, 'every permission request is denied');
has('src/main/main.js', /setPermissionCheckHandler\(\(\) => false\)/, 'permission checks say no');
has('src/main/main.js', /setDevicePermissionHandler\(\(\) => false\)/, 'device permissions denied');
has('src/main/main.js', /ses\.on\('will-download', e => e\.preventDefault\(\)\)/, 'page downloads blocked');
has('src/main/main.js', /app\.on\('certificate-error', \([^)]*callback\) => callback\(false\)\)/, 'certificate errors are never accepted');
not('src/main/main.js', /certificate-error[^]*?callback\(true\)/, 'no certificate override');
has('src/main/main.js', /app\.on\('web-contents-created'/, 'global web-contents hardening');
has('src/main/main.js', /setWindowOpenHandler\(\(\) => \(\{ action: 'deny' \}\)\)/, 'window.open denied');
has('src/main/main.js', /wc\.on\('will-navigate', \(e, url\) => \{ if \(!isOurPage\(url\)\) e\.preventDefault\(\); \}\)/, 'navigation only to our own pages');
has('src/main/main.js', /wc\.on\('will-attach-webview', e => e\.preventDefault\(\)\)/, 'webview attach denied');
has('src/main/main.js', /wc\.on\('will-redirect', e => e\.preventDefault\(\)\)/, 'redirects denied');
has('src/main/main.js', /delete process\.env\[k\]/, 'ELECTRON_RUN_AS_NODE / NODE_OPTIONS removed from the environment');
has('src/main/main.js', /render-process-gone/, 'renderer crash recovery');
has('src/main/main.js', /QUIT_CEILING_MS/, 'quit never waits forever');
not('src/main/main.js', /registerSchemesAsPrivileged|protocol\.(handle|register)/, 'no privileged schemes or protocol handlers inside the app');
eq((main.match(/setAsDefaultProtocolClient\(/g) || []).length, 2, 'the OS hands niyanta-tracker:// to the app (one call for development, one packaged)');
has('src/main/main.js', /process\.defaultApp && process\.argv\.length >= 2\) app\.setAsDefaultProtocolClient\(guard\.SCHEME, process\.execPath, \[path\.resolve\(process\.argv\[1\]\)\]\)/, 'development runs register with the app path');
ok(!/\.loadURL\(/.test(strip(code)), 'renderer loads local files only (no loadURL)');
ok((code.match(/\.loadFile\(/g) || []).length === 2 && /loadFile\(asset\('renderer', 'index\.html'\)\)/.test(main) && /loadFile\(path\.join\(__dirname, '\.\.', 'renderer', 'toast\.html'\)\)/.test(src['src/main/toast.js']), 'only the two bundled pages are loaded');

/* ---------- IPC ---------- */
eq((main.match(/ipcMain\.handle\(/g) || []).length, 1, 'one ipcMain.handle wrapper (every channel goes through h())');
has('src/main/main.js', /if \(!validSender\(e\)\) return null;/, 'sender validation on every channel');
has('src/main/main.js', /f === win\.webContents\.mainFrame && isOurPage\(f\.url\)/, 'sender must be the main frame of our page');
has('src/main/main.js', /if \(!limiter\.allow\(name\)\)/, 'rate limit on every channel');
has('src/main/main.js', /new guard\.RateLimiter\(\{[^}]*error: 10/, 'renderer error reports limited');
has('src/main/main.js', /guard\.selection\(a\.body\)/, 'selection validated');
has('src/main/main.js', /\['start', 'pause', 'resume', 'switch'\]\.includes\(name\)/, 'action names allowlisted');
has('src/main/main.js', /guard\.PREF_KEYS\.includes\(key\)/, 'pref keys allowlisted');
has('src/main/main.js', /guard\.POMODORO_OPS\.includes\(op\)/, 'pomodoro ops allowlisted');
has('src/main/main.js', /guard\.OPEN_TARGETS\.includes\(what\)/, 'open targets allowlisted');
has('src/main/main.js', /guard\.str\(d\.url, 300\), email: guard\.str\(d\.email, 200\), password: guard\.str\(d\.password, 1000\)/, 'sign-in fields typed and bounded');
has('src/main/main.js', /clipboard\.writeText\(guard\.str\(text, 2000\)\)/, 'clipboard text bounded');
eq((code.match(/shell\.openExternal\(/g) || []).length, 3, 'shell.openExternal appears exactly three times (openTarget, the connect page and the macOS settings pane)');
has('src/main/main.js', /function openConnectPage\(url, ws\) \{\n  if \(!guard\.allowedLink\(url, ws\)\) \{[^\n]*return false; \}\n  shell\.openExternal\(url/, 'the connect page opens only on the workspace host');
has('src/main/main.js', /if \(!guard\.allowedLink\(url, [^)]*\)\) \{[^]*?return false; \}\n  shell\.openExternal\(url/, 'links go to the browser only after the allowlist');
has('src/main/capture.js', /shell\.openExternal\(SETTINGS_PANE\)/, 'settings pane is a constant');
not('src/main/main.js', /shell\.openPath\(/, 'main.js never opens arbitrary paths');
has('src/main/main.js', /guard\.desktopExecArg\(exec\)/, 'autostart .desktop Exec is quoted');

/* ---------- preload ---------- */
for (const f of ['src/preload/preload.js', 'src/preload/toast-preload.js']) {
  ok((src[f].match(/require\(/g) || []).length === 1 && /require\('electron'\)/.test(src[f]), f + ': requires only electron');
  not(f, /exposeInMainWorld\([^]*?\bipcRenderer\s*[,}:]/, 'ipcRenderer itself is not exposed');
  not(f, /ipcRenderer\.(send|sendSync|postMessage)\b/, 'no fire-and-forget channels'); }
ok((src['src/preload/preload.js'].match(/ipcRenderer\.invoke\(/g) || []).length === 1 && /invoke\('tracker:' \+ name/.test(src['src/preload/preload.js']), 'preload: one invoke helper with a fixed channel prefix');
has('src/preload/preload.js', /password: text\(data && data\.password, 1000\)/, 'preload types the sign-in payload');

/* ---------- pages ---------- */
for (const f of ['src/renderer/index.html', 'src/renderer/toast.html']) {
  const h = src[f];
  const csp = (h.match(/http-equiv="Content-Security-Policy" content="([^"]+)"/) || [])[1] || '';
  ok(/default-src 'none'/.test(csp) && /script-src 'self'/.test(csp) && /base-uri 'none'/.test(csp) && /object-src 'none'/.test(csp) && /frame-src 'none'/.test(csp), f + ': strict CSP');
  ok(!/unsafe-inline|unsafe-eval|https?:/.test(csp), f + ': CSP has no unsafe-* and no remote sources');
  ok(!/<script(?![^>]*\ssrc=)[^>]*>/i.test(h), f + ': no inline <script>');
  ok([...h.matchAll(/<script[^>]*>([^]*?)<\/script>/gi)].every(m => !m[1].trim()), f + ': script tags have no body');
  ok(!/\son[a-z]+\s*=/i.test(h), f + ': no inline event handlers');
  ok(!/javascript:/i.test(h) && !/(src|href)="\s*https?:/i.test(h), f + ': no javascript: or remote src/href');
  ok(!/<(iframe|object|embed|webview|base)\b/i.test(h), f + ': no frames, plugins, webview or <base>'); }
for (const f of ['src/renderer/app.js', 'src/renderer/toast.js', 'src/shared/status.js']) not(f, /\beval\(|new Function\(|document\.write\(|window\.require|\bimport\(|\.outerHTML\s*=/, 'no eval / Function / document.write / require / dynamic import');
has('src/renderer/app.js', /const esc = s => String\(s == null \? '' : s\)\.replace\(\/\[&<>"'\]\/g/, 'HTML escaper present');
has('src/renderer/app.js', /visibilitychange/, 'clock stops while the window is hidden');

/* ---------- network ---------- */
has('src/main/api.js', /parsed\.protocol !== 'https:' && !\['localhost', '127\.0\.0\.1', '::1', '\[::1\]'\]\.includes\(parsed\.hostname\)/, 'HTTPS enforced except localhost');
has('src/main/api.js', /redirect: 'manual'/, 'redirects handled by hand');
has('src/main/api.js', /if \(sameOrigin\(url, to\) && \+\+hops <= MAX_REDIRECTS\) \{ req\.followRedirect\(\); return; \}/, 'only same-origin redirects are followed (the token never leaves the workspace)');
ok(!/redirect: 'follow'|net\.fetch\(|\bfetch\(/.test(strip(code)), 'no net.fetch / follow-redirect requests anywhere');
has('src/main/api.js', /const MAX_JSON = /, 'response size cap');
has('src/main/api.js', /useSessionCookies: false/, 'no session cookies');
has('src/main/api.js', /'User-Agent': userAgent\(\)/, 'user agent with the app version');
has('src/main/queue.js', /backoff\(\) \{ return this\.flushing \? 2000 : Math\.min\(300000, 3000 \* Math\.pow\(2, Math\.min\(this\.failures, 7\)\)\) \* \(0\.8 \+ Math\.random\(\) \* 0\.4\); \}/, 'exponential backoff with jitter, capped');
has('src/main/updater.js', /safeFileName\(f\.name, \['zip'\]\)/, 'macOS zip name sanitised');
has('src/main/updater.js', /safeFileName\(f\.name, EXTS\)/, 'installer name sanitised');
ok((src['src/main/updater.js'].match(/su\.sha512File\(/g) || []).length >= 2, 'sha512 checked for the macOS zip and the manual installer');
has('src/main/updater.js', /MAX_DOWNLOAD_BYTES/, 'download size bounded');
has('src/main/updater.js', /STALL_MS/, 'download stall timeout');
has('src/main/updater.js', /createWriteStream\(part, \{ mode: 0o600 \}\)/, 'downloads written with mode 600');
has('src/main/updater.js', /allowDowngrade = false/, 'electron-updater: no downgrades');
has('src/main/updater.js', /allowPrerelease = false/, 'electron-updater: no prereleases');

/* ---------- data at rest ---------- */
has('src/main/store.js', /safeStorage\.encryptString\(secret\)/, 'secrets sealed with safeStorage');
has('src/main/store.js', /mode: 0o600/, 'config.json mode 600');
has('src/main/store.js', /function getQueueKey\(\)/, 'screenshot key management');
has('src/main/queue.js', /fs\.writeFileSync\(filePath, guard\.encrypt\(key, buffer\), \{ mode: 0o600 \}\)/, 'screenshots encrypted on disk, mode 600');
has('src/main/queue.js', /\.enc'/, 'screenshot files are .enc');
has('src/main/queue.js', /MAX_SHOT_BYTES/, 'screenshot queue bounded in bytes');
has('src/main/queue.js', /sweep\(\)/, 'orphan files swept');
has('src/main/queue.js', /writeFileSync\(this\.file \+ '\.tmp', JSON\.stringify\(this\.jobs\), \{ mode: 0o600 \}\)/, 'queue.json mode 600');
has('src/main/tracker.js', /store\.resetQueueKey\(\)/, 'sign-out drops the screenshot key');
has('src/main/tracker.js', /this\.queue\.clear\(\);\n    store\.resetQueueKey\(\);/, 'sign-out clears the queue');
has('src/main/tracker.js', /\|\| this\.away \|\| this\.locked\) return false;/, 'no screenshots while locked or asleep');
has('src/main/tracker.js', /if \(!this\.settings\.screenshots_enabled \|\| !this\.boot \|\| !this\.boot\.consent \|\| this\.state !== 'tracking'/, 'screenshots need the setting, consent and tracking');
has('src/main/errors.js', /mode: 0o600/, 'log files mode 600');
has('src/main/errors.js', /function scrub\(/, 'log scrubber');
eq((src['src/main/tracker.js'].match(/setInterval\(/g) || []).length, 1, 'one interval in the tracker (everything else is driven by the 1 s tick)');
const logLines = code.split('\n').filter(l => /(errors\.log\(|\.report\(|writeLocal\(|console\.(log|error)\()/.test(l));
ok(logLines.length > 10 && !logLines.some(l => /\b(this\.token|api\.token|password|headers\(\)|Authorization)\b/.test(l)), 'no token / password / headers in any log call');

/* ---------- package and build ---------- */
const pkg = JSON.parse(src['package.json']);
const ver = s => String(s || '').replace(/^[^\d]*/, '').split('.').map(Number);
const gte = (a, b) => { for (let i = 0; i < 3; i++) { if ((a[i] || 0) !== (b[i] || 0)) return (a[i] || 0) > (b[i] || 0); } return true; };
ok(gte(ver(pkg.dependencies['electron-updater']), [6, 8, 11]), 'electron-updater >= 6.8.11 (cross-origin redirect token leak fix)');
ok(pkg.devDependencies['@electron/fuses'], '@electron/fuses present for the afterPack hook');
eq(pkg.build.asar, true, 'asar on');
eq(pkg.build.afterPack, 'scripts/after-pack.js', 'fuses hook wired');
ok(!pkg.build.asarUnpack, 'nothing unpacked from the asar');
ok(pkg.build.files.includes('src/**/*') && !pkg.build.files.some(f => /^scripts|^dist|^build|^README/.test(f)), 'package whitelist: src and package.json only');
ok(pkg.build.files.some(f => /\.map/.test(f)) && pkg.build.files.some(f => /\.md/.test(f)), 'package excludes maps and docs');
eq(pkg.build.win.requestedExecutionLevel, 'asInvoker', 'no elevation');
ok(Object.keys(pkg.dependencies).length === 1, 'one runtime dependency');
ok(fs.readFileSync(path.join(root, '.htaccess'), 'utf8').includes('Require all denied'), 'desktop/ is not served by Apache');
ok(!fs.existsSync(path.join(root, '.npmrc')) || !/_authToken|password/.test(fs.readFileSync(path.join(root, '.npmrc'), 'utf8')), 'no registry credentials in .npmrc');
const { fuseConfig } = require(path.join(root, 'scripts', 'after-pack.js'));
const { FuseV1Options, getCurrentFuseWire } = require('@electron/fuses');
for (const plat of ['darwin', 'win32', 'linux']) {
  const c = fuseConfig(plat, false);
  eq([c[FuseV1Options.RunAsNode], c[FuseV1Options.EnableCookieEncryption], c[FuseV1Options.EnableNodeOptionsEnvironmentVariable], c[FuseV1Options.EnableNodeCliInspectArguments], c[FuseV1Options.OnlyLoadAppFromAsar], c[FuseV1Options.EnableEmbeddedAsarIntegrityValidation]],
    [false, true, false, false, true, plat !== 'linux'], 'fuses for ' + plat); }
ok(fuseConfig('darwin', true).resetAdHocDarwinSignature === true && fuseConfig('win32', false).resetAdHocDarwinSignature === false, 'ad-hoc signature reset only on macOS');

/* ---------- sign in with the browser: registration and handlers ---------- */
eq(pkg.build.protocols, [{ name: 'Niyanta Tracker', schemes: ['niyanta-tracker'], role: 'Viewer' }], 'package.json build.protocols (macOS CFBundleURLTypes, Linux MimeType x-scheme-handler)');
eq(pkg.build.nsis.include, 'build/installer.nsh', 'NSIS include wired');
has('build/installer.nsh', /!macro customInstall[^]*WriteRegStr SHCTX "Software\\Classes\\niyanta-tracker" "URL Protocol" ""[^]*shell\\open\\command" "" '"\$appExe" "%1"'[^]*!macroend/, 'Windows registry: URL Protocol and open command');
has('build/installer.nsh', /!macro customUnInstall[^]*\$\{ifNot\} \$\{isUpdated\}[^]*DeleteRegKey SHCTX "Software\\Classes\\niyanta-tracker"/, 'Windows uninstall removes the scheme (not on updates)');
has('src/main/main.js', /app\.on\('open-url', \(e, url\) => \{ e\.preventDefault\(\); deepLink\(url\); \}\);/, 'macOS open-url handled (registered before ready)');
ok(main.indexOf("app.on('open-url'") < main.indexOf('app.whenReady()'), 'open-url listener exists before ready');
has('src/main/main.js', /app\.on\('second-instance', \(_e, argv\) => \{[^\n]*const url = linkIn\(argv\); if \(url\) deepLink\(url\);/, 'second-instance argv handled (Windows, Linux)');
has('src/main/main.js', /const first = earlyLink \|\| linkIn\(process\.argv\);/, 'first launch argv and early links handled after the session is restored');
has('src/main/main.js', /MimeType=x-scheme-handler\/\$\{guard\.SCHEME\};/, 'AppImage gets a scheme handler entry');
has('src/main/main.js', /h\('connect', c => \{ c = obj\(c\); const op = guard\.str\(c\.op, 20\); return guard\.CONNECT_OPS\.includes\(op\)/, 'connect IPC ops allowlisted');
has('src/main/main.js', /connect: 20/, 'connect IPC rate limited');
has('src/preload/preload.js', /connect: \(op, url\) => call\('connect', \{ op: text\(op, 20\), url: text\(url, 300\) \}\)/, 'preload types the connect call');
has('src/main/connect.js', /const l = guard\.parseDeepLink\(raw\);/, 'every link goes through guard.parseDeepLink');
ok(!/require\('electron'\)/.test(src['src/main/connect.js']), 'connect.js has no Electron dependency (tested here)');
has('src/main/tracker.js', /const api = new Api\(\); api\.configure\(ws, null\);\n      const res = await api\.post\('connect'[^\n]*\{ auth: false/, 'the code is traded on a separate client without the current token');
has('src/main/tracker.js', /if \(e\.status === 401 \|\| \(e\.status === 403 && \['disabled', 'forbidden'\]\.includes\(e\.code\)\)\) \{ this\.signOutLocal/, 'signed out only on 401 or access removed (never because of the website session)');
not('src/main/connect.js', /signOut|signedOutRemotely/, 'a failed connect never signs anyone out');
has('src/renderer/index.html', /id="si-browser" class="btn primary block big"[^]*Sign in with your browser/, 'big Sign in with your browser button');
has('src/renderer/index.html', /Use email and password instead/, 'email and password fallback');
has('src/renderer/app.js', /as the person signed in on that website\?/, 'confirmation text');
ok(!/\u2014/.test(src['src/renderer/index.html'] + src['src/renderer/app.js'] + src['src/main/connect.js']), 'no em dashes in UI text');

/* ---------- functional: guard.js ---------- */
const guard = require(path.join(root, 'src', 'main', 'guard.js'));
const base = 'https://ws.example.com/';
eq(['https://ws.example.com/time', 'https://ws.example.com:443/x', 'https://niyanta.app/help', 'https://www.niyanta.app/', 'http://ws.example.com/time', 'https://evil.com/', 'javascript:alert(1)', 'file:///etc/passwd', 'https://user:pw@ws.example.com/', 'https://ws.example.com:444/', 'https://ws.example.com.evil.com/', 'not a url', ''].map(u => guard.allowedLink(u, base)),
  [true, true, true, true, false, false, false, false, false, false, false, false, false], 'allowedLink');
eq([guard.allowedLink('http://localhost:8080/x', 'http://localhost:8080/'), guard.allowedLink('http://localhost:8080/x', base), guard.allowedLink('https://ws.example.com/', null)], [true, false, false], 'allowedLink localhost and no base');
eq(guard.selection({ project_id: '5', task_id: 'x', description: 'a'.repeat(600), extra: 1 }), { project_id: 5, task_id: null, description: 'a'.repeat(500) }, 'selection typed and bounded');
eq([guard.selection(null), guard.selection('x'), guard.selection([1]), guard.selection({ project_id: -1 }), guard.selection({ project_id: 1e13 }), guard.selection({ project_id: 2.5, task_id: 3 })], [{}, {}, {}, { project_id: null, task_id: null }, { project_id: null, task_id: null }, { project_id: null, task_id: 3 }], 'selection rejects odd input');
eq([guard.str('abc', 2), guard.str(5), guard.str(null), guard.str({ toString: () => 'x' })], ['ab', '', '', ''], 'str');
eq(['niyanta-tracker-1.2.0-win-x64.exe', 'Niyanta Tracker.dmg', '../x.exe', 'a/b.exe', 'x.exe;rm -rf', 'name.txt', '.hidden.exe', 'x'.repeat(130) + '.exe', 'a..b.exe', 'ok_1+2.AppImage'].map(nm => guard.safeFileName(nm, ['exe', 'dmg', 'appimage'])),
  ['niyanta-tracker-1.2.0-win-x64.exe', null, null, null, null, null, null, null, null, 'ok_1+2.AppImage'], 'safeFileName');
eq(guard.safeFileName('latest.yml'), 'latest.yml', 'safeFileName without extension filter');
eq(guard.desktopExecArg('/opt/it"s $x `y` \\z'), '"/opt/it\\"s \\$x \\`y\\` \\\\z"', 'desktop Exec quoting');
eq([guard.sameOrigin('https://a.com/x', 'https://a.com/y'), guard.sameOrigin('https://a.com/x', 'https://b.com/x'), guard.sameOrigin('https://a.com/x', 'http://a.com/x'), guard.sameOrigin('https://a.com/x', 'https://a.com:444/x'), guard.sameOrigin('x', 'y')], [true, false, false, false, false], 'sameOrigin');
const lim = new guard.RateLimiter({ a: 3 }, 1000, 2);
eq([lim.allow('a', 0), lim.allow('a', 10), lim.allow('a', 20), lim.allow('a', 30), lim.allow('b', 0), lim.allow('b', 0), lim.allow('b', 0), lim.allow('a', 1001), lim.allow('a', 1002)], [true, true, true, false, true, true, false, true, false], 'rate limiter: per channel, sliding window');
const key = guard.newKey(), key2 = guard.newKey();
const plain = Buffer.from('jpeg bytes ' + 'x'.repeat(1000));
const blob = guard.encrypt(key, plain);
ok(blob.length === plain.length + 32 && guard.isEncrypted(blob) && !guard.isEncrypted(plain) && !blob.includes(plain.subarray(0, 20)), 'encrypt: header + iv + tag, no plaintext inside');
ok(guard.decrypt(key, blob).equals(plain), 'decrypt round trip');
ok(!guard.encrypt(key, plain).equals(blob), 'fresh iv every time');
let threw = 0;
try { guard.decrypt(key2, blob); } catch (e) { threw++; }
const tampered = Buffer.from(blob); tampered[40] ^= 1;
try { guard.decrypt(key, tampered); } catch (e) { threw++; }
try { guard.decrypt(key, plain); } catch (e) { threw++; }
eq(threw, 3, 'wrong key, tampered data and plain input are rejected');

/* ---------- functional: queue with electron stubbed ---------- */
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'ny-sec-'));
const load = Module._load;
const stub = { app: { getPath: () => tmp, getVersion: () => '0.0.0', on: () => {}, isPackaged: true }, net: {}, safeStorage: { isEncryptionAvailable: () => false } };
Module._load = function (req, ...rest) { return req === 'electron' ? stub : load.call(this, req, ...rest); };
const { Queue, MAX_SHOTS } = require(path.join(root, 'src', 'main', 'queue.js'));
const { normaliseUrl } = require(path.join(root, 'src', 'main', 'api.js'));
const errors = require(path.join(root, 'src', 'main', 'errors.js'));
const store = require(path.join(root, 'src', 'main', 'store.js'));
Module._load = load;

async function queueChecks() {
  const pending = path.join(tmp, 'pending');
  fs.mkdirSync(pending, { recursive: true });
  fs.writeFileSync(path.join(pending, 'orphan-1.jpg'), 'old plain screenshot');
  const got = [];
  const api = { request: async (m, p, o) => { got.push(o); return { ok: true }; } };
  const q = new Queue(api, { key: () => key });
  ok(!fs.existsSync(path.join(pending, 'orphan-1.jpg')), 'orphan files in pending/ are deleted on start');
  q.paused = true;
  const job = q.addScreenshot(plain, { activity_pct: 50, display: 0 });
  ok(job && job.filePath.endsWith('.enc') && job.bytes === plain.length, 'screenshot queued as .enc with its size');
  const onDisk = fs.readFileSync(job.filePath);
  ok(guard.isEncrypted(onDisk) && !onDisk.includes(plain.subarray(0, 20)), 'file on disk is encrypted');
  if (process.platform !== 'win32') eq(fs.statSync(job.filePath).mode & 0o777, 0o600, 'screenshot file mode 600');
  if (process.platform !== 'win32') eq(fs.statSync(path.join(tmp, 'queue.json')).mode & 0o777, 0o600, 'queue.json mode 600');
  ok(!JSON.stringify(JSON.parse(fs.readFileSync(path.join(tmp, 'queue.json'), 'utf8'))).includes('jpeg bytes'), 'queue.json holds no image data');
  const r = await q.flush(5000);
  ok(r.done && got.length === 1 && got[0].file.data.equals(plain) && got[0].file.type === 'image/jpeg' && /\.jpg$/.test(got[0].file.name), 'upload sends the decrypted JPEG');
  ok(!fs.existsSync(job.filePath), 'file deleted after the upload');
  const q2 = new Queue(api, { key: () => null });
  ok(q2.addScreenshot(plain, {}) === null && fs.readdirSync(pending).length === 0, 'without a key nothing is written');
  for (let i = 0; i < MAX_SHOTS + 5; i++) { const f = path.join(pending, `s${i}.enc`); fs.writeFileSync(f, 'x'); q2.jobs.push({ id: String(i), type: 'screenshot', path: 'screenshot', createdAt: Date.now(), tries: 0, filePath: f, bytes: 1 }); }
  q2.trim();
  await new Promise(res => setTimeout(res, 100));
  ok(q2.jobs.length === MAX_SHOTS && !fs.existsSync(path.join(pending, 's0.enc')) && fs.existsSync(path.join(pending, `s${MAX_SHOTS + 4}.enc`)), 'oldest screenshots evicted over the cap, files removed');
  q2.clear();
  ok(q2.jobs.length === 0 && fs.readdirSync(pending).length === 0, 'clear() removes every file');
  /* keys: created once, read back, dropped on sign-out */
  const k1 = store.getQueueKey();
  ok(Buffer.isBuffer(k1) && k1.length === 32 && store.getQueueKey().equals(k1), 'queue key created and stable');
  store.resetQueueKey();
  ok(!store.getQueueKey().equals(k1), 'sign-out makes a new key'); }

/* ---------- functional: URL normalisation and log scrubbing ---------- */
eq(normaliseUrl('team.example.com'), 'https://team.example.com/', 'https added');
eq(normaliseUrl('https://team.example.com/app/?x=1#y'), 'https://team.example.com/app/', 'query and hash dropped');
eq(normaliseUrl('http://localhost:8080'), 'http://localhost:8080/', 'http allowed for localhost');
for (const u of ['http://team.example.com', 'https://user:pw@team.example.com', 'ftp://x', '']) { let t = false; try { normaliseUrl(u); } catch (e) { t = e.code === 'input'; } ok(t, 'rejected: ' + JSON.stringify(u)); }
eq(errors.scrub('Authorization: Bearer abcdefgh12345678 x'), 'Authorization: Bearer [redacted] x', 'bearer tokens scrubbed');
eq(errors.scrub('{"password":"hunter22","token":"abcd1234"}'), '{"password":"[redacted]","token":"[redacted]"}', 'password / token fields scrubbed');
eq(errors.scrub('sync: Request failed (HTTP 500).'), 'sync: Request failed (HTTP 500).', 'ordinary messages untouched');

/* ---------- functional: sign in with the browser ---------- */
const WS = 'https://team.example.com/', CODE = 'A'.repeat(20) + '_-b9' + 'z'.repeat(19), STATE = 'S'.repeat(32);
const dl = (q, scheme = 'niyanta-tracker://connect') => scheme + '?' + q;
const wsq = u => 'ws=' + encodeURIComponent(u);
eq(guard.parseDeepLink(dl(`${wsq(WS)}&code=${CODE}`)), { ws: WS, code: CODE, state: null }, 'deep link: web / install link');
eq(guard.parseDeepLink(dl(`${wsq('https://team.example.com/app')}&code=${CODE}&state=${STATE}`, 'niyanta-tracker://connect/')), { ws: 'https://team.example.com/app/', code: CODE, state: STATE }, 'deep link: with state, path normalised to a trailing slash');
eq(guard.parseDeepLink(dl(`${wsq('http://localhost:8080/')}&code=${CODE}`)).ws, 'http://localhost:8080/', 'deep link: http for localhost');
eq(guard.parseDeepLink(dl(`${wsq('http://127.0.0.1/')}&code=${CODE}`)).ws, 'http://127.0.0.1/', 'deep link: http for 127.0.0.1');
const badLinks = {
  'wrong scheme': dl(`${wsq(WS)}&code=${CODE}`, 'niyanta://connect'), 'https scheme': dl(`${wsq(WS)}&code=${CODE}`, 'https://connect'),
  'wrong host': dl(`${wsq(WS)}&code=${CODE}`, 'niyanta-tracker://login'), 'path': dl(`${wsq(WS)}&code=${CODE}`, 'niyanta-tracker://connect/x'),
  'credentials on the link': dl(`${wsq(WS)}&code=${CODE}`, 'niyanta-tracker://u:p@connect'), 'port on the link': dl(`${wsq(WS)}&code=${CODE}`, 'niyanta-tracker://connect:1'),
  'http non-local ws': dl(`${wsq('http://team.example.com/')}&code=${CODE}`), 'credentials in ws': dl(`${wsq('https://user:pw@team.example.com/')}&code=${CODE}`),
  'user only in ws': dl(`${wsq('https://user@team.example.com/')}&code=${CODE}`), 'javascript ws': dl(`${wsq('javascript:alert(1)')}&code=${CODE}`), 'file ws': dl(`${wsq('file:///etc/')}&code=${CODE}`),
  'no ws': dl(`code=${CODE}`), 'no code': dl(wsq(WS)), 'bad code chars': dl(`${wsq(WS)}&code=${'a'.repeat(30)}%3Cx%3E`), 'code with dot': dl(`${wsq(WS)}&code=${'a'.repeat(30)}.b`),
  'short code': dl(`${wsq(WS)}&code=abc`), 'overlong code': dl(`${wsq(WS)}&code=${'a'.repeat(129)}`), 'bad state chars': dl(`${wsq(WS)}&code=${CODE}&state=${'s'.repeat(20)}%2F`),
  'short state': dl(`${wsq(WS)}&code=${CODE}&state=abc`), 'overlong state': dl(`${wsq(WS)}&code=${CODE}&state=${'s'.repeat(65)}`), 'empty state': dl(`${wsq(WS)}&code=${CODE}&state=`),
  'duplicate code': dl(`${wsq(WS)}&code=${CODE}&code=${CODE}`), 'duplicate ws': dl(`${wsq(WS)}&${wsq('https://evil.com/')}&code=${CODE}`),
  'overlong link': dl(`${wsq(WS)}&code=${CODE}&x=${'a'.repeat(2100)}`), 'overlong ws': dl(`${wsq('https://team.example.com/' + 'a'.repeat(300))}&code=${CODE}`),
  'space / argv injection': dl(`${wsq(WS)}&code=${CODE}`) + ' --inspect', 'hash': dl(`${wsq(WS)}&code=${CODE}`) + '#x', 'not a string': 42, 'empty': '' };
for (const [why, l] of Object.entries(badLinks)) ok(guard.parseDeepLink(l) === null, 'deep link rejected: ' + why);
eq([guard.sameWorkspace('https://team.example.com', WS), guard.sameWorkspace('https://TEAM.example.com/?x', WS), guard.sameWorkspace('https://team.example.com:444/', WS), guard.sameWorkspace('https://team.example.com/app/', WS), guard.sameWorkspace('', WS)], [true, true, false, false, false], 'sameWorkspace');
eq(guard.pkceChallenge('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk'), 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', 'PKCE S256 challenge (RFC 7636 appendix B vector)');
const vs = [guard.pkceVerifier(), guard.pkceVerifier()];
ok(vs.every(v => /^[A-Za-z0-9_-]{43}$/.test(v)) && vs[0] !== vs[1], 'PKCE verifier: 32 random bytes, 43 base64url characters');
ok(/^[A-Za-z0-9_-]{43}$/.test(guard.pkceChallenge(vs[0])), 'PKCE challenge is 43 base64url characters');
const st0 = guard.newState();
ok(/^[A-Za-z0-9_-]{32}$/.test(st0) && st0 !== guard.newState() && guard.parseDeepLink(dl(`${wsq(WS)}&code=${CODE}&state=${st0}`)) !== null, 'state: 24 random bytes, accepted by the parser');
const pend = { ws: WS, state: st0, verifier: vs[0], at: 1000000 };
eq([guard.checkState(pend, st0, 1000000 + 60000), guard.checkState(pend, st0, 1000000 + guard.CONNECT_TTL_MS + 1), guard.checkState(pend, st0.slice(0, -1) + (st0.slice(-1) === 'a' ? 'b' : 'a'), 1000001), guard.checkState(pend, 'short', 1000001), guard.checkState(null, st0, 0), guard.checkState(pend, st0, 1000000 - 120000)],
  ['ok', 'expired', 'mismatch', 'mismatch', 'none', 'expired'], 'state matching and expiry (10 minutes)');
eq(guard.CONNECT_TTL_MS, 600000, 'pending sign-in lives at most 10 minutes');
eq([guard.deviceName('Ana\u0007s-MacBook\n<script>'), guard.deviceName(''), guard.deviceName('x'.repeat(150)).length], ['Ana s-MacBook script', 'Computer', 100], 'device name sanitised, at most 100');
eq([guard.osCode('win32'), guard.osCode('darwin'), guard.osCode('linux'), guard.osCode('freebsd')], ['win', 'mac', 'linux', 'linux'], 'os codes');
const page = new URL(guard.connectPageUrl('https://team.example.com/app', { challenge: 'c'.repeat(43), state: st0, device: 'My PC', os: 'win' }));
ok(page.origin + page.pathname === 'https://team.example.com/app/time/desktop/connect' && page.searchParams.get('challenge') === 'c'.repeat(43) && page.searchParams.get('state') === st0 && page.searchParams.get('device') === 'My PC' && page.searchParams.get('os') === 'win', 'connect page URL');
ok(guard.allowedLink(page.toString(), 'https://team.example.com/app/') && guard.connectPageUrl('http://team.example.com/', {}) === null, 'connect page is on the workspace host only');
eq(guard.CONNECT_OPS, ['browser', 'cancel', 'confirm', 'decline', 'retry', 'dismiss'], 'connect ops');

async function connectChecks() {
  const { Connect, friendly } = require(path.join(root, 'src', 'main', 'connect.js'));
  const mem = {};
  const fakeStore = { get: (k, d) => (k in mem ? JSON.parse(JSON.stringify(mem[k])) : d), set: (k, v) => { if (v === undefined) delete mem[k]; else mem[k] = v; }, getSealed: k => mem['sealed:' + k] || null, setSealed: (k, v) => { if (v == null) delete mem['sealed:' + k]; else mem['sealed:' + k] = v; } };
  let clock = 5000000;
  const calls = [], flashes = [], opened = [];
  const tracker = { signedIn: false, api: { base: null }, boot: null, changed() {}, flash(t, k) { flashes.push([k, t]); },
    async connect(ws, body) { calls.push({ ws, body }); if (body.code.startsWith('bad')) return { ok: false, code: 'code_expired', error: 'x' }; this.signedIn = true; this.api.base = ws; this.boot = { user: { name: 'Ana' } }; return { ok: true, user: { name: 'Ana' } }; } };
  const mk = () => new Connect({ tracker, store: fakeStore, open: (u, ws) => { opened.push(u); return guard.allowedLink(u, ws); }, device: 'ana-pc', platform: 'win32', now: () => clock });
  let c = mk();
  /* flow A, first time this host is seen: asks */
  let r = await c.link(dl(`${wsq(WS)}&code=${CODE}`));
  ok(r.asked && c.phase === 'confirm' && c.view().host === 'team.example.com' && !c.view().switching && calls.length === 0, 'flow A: first time a host is seen, the app asks');
  c.decline();
  ok(c.phase === null && calls.length === 0, 'flow A: Cancel trades nothing');
  await c.link(dl(`${wsq(WS)}&code=${CODE}`));
  r = await c.confirm();
  ok(r.ok && calls.length === 1 && calls[0].ws === WS && calls[0].body.code === CODE && !('verifier' in calls[0].body) && calls[0].body.device_name === 'ana-pc', 'flow A: confirmed, the code is traded (no verifier)');
  ok((mem.connectHosts || []).includes('https://team.example.com'), 'confirmed host remembered');
  /* signed in to the same workspace: nothing is traded */
  r = await c.link(dl(`${wsq('https://team.example.com')}&code=${CODE}`));
  ok(r.already && calls.length === 1 && /^Already connected to team\.example\.com as Ana/.test(flashes[flashes.length - 1][1]), 'same workspace: Already connected, code untouched');
  /* signed in, another workspace: always asks, as a switch */
  r = await c.link(dl(`${wsq('https://other.example.org/')}&code=${CODE}`));
  ok(r.asked && c.view().switching && c.view().current === 'team.example.com' && c.view().host === 'other.example.org', 'another workspace: asks to switch');
  c.decline();
  mem.connectHosts.unshift('https://other.example.org');
  r = await c.link(dl(`${wsq('https://other.example.org/')}&code=${CODE}`));
  ok(r.asked && c.view().switching, 'switching workspace asks even for a known host');
  c.decline();
  /* signed out, known host: straight through */
  tracker.signedIn = false; tracker.api.base = null;
  r = await c.link(dl(`${wsq(WS)}&code=${CODE}`));
  ok(r.ok && !r.asked && calls.length === 2, 'signed out, confirmed host: no question');
  /* flow B */
  tracker.signedIn = false;
  r = c.browser('https://team.example.com');
  const u = new URL(opened[opened.length - 1]);
  const p = c.pending;
  ok(r.ok && c.phase === 'waiting' && u.pathname === '/time/desktop/connect' && u.searchParams.get('state') === p.state && u.searchParams.get('challenge') === guard.pkceChallenge(p.verifier) && u.searchParams.get('os') === 'win' && u.searchParams.get('device') === 'ana-pc', 'flow B: browser opened with challenge, state, device, os');
  ok(mem['sealed:connectPending'] && mem['sealed:connectPending'].state === p.state, 'flow B: pending sign-in kept (sealed) for a relaunch');
  const relaunched = mk();
  ok(relaunched.pending && relaunched.pending.verifier === p.verifier && relaunched.phase === 'waiting', 'relaunch restores the pending sign-in');
  r = await c.link(dl(`${wsq(WS)}&code=${CODE}&state=${guard.newState()}`));
  ok(!r.ok && c.phase === 'waiting' && calls.length === 2 && /not started from this app/.test(c.message.text), 'flow B: mismatched state ignored with a message, still waiting');
  r = await c.link(dl(`${wsq('https://evil.example.com/')}&code=${CODE}&state=${p.state}`));
  ok(!r.ok && calls.length === 2, 'flow B: right state for another workspace ignored');
  r = await c.link(dl(`${wsq(WS)}&code=${CODE}&state=${p.state}`));
  ok(r.ok && calls.length === 3 && calls[2].body.verifier === p.verifier && !c.pending && !mem['sealed:connectPending'], 'flow B: matching state traded with the verifier, pending cleared');
  r = await c.link(dl(`${wsq(WS)}&code=${CODE}&state=${p.state}`));
  ok(!r.ok && calls.length === 3, 'flow B: a state is used once');
  tracker.signedIn = false;
  c.browser(WS);
  const p2 = c.pending;
  clock += guard.CONNECT_TTL_MS + 1000;
  r = await c.link(dl(`${wsq(WS)}&code=${CODE}&state=${p2.state}`));
  ok(!r.ok && calls.length === 3 && c.phase === 'error' && c.retry && /expired/.test(c.message.text) && !mem['sealed:connectPending'], 'flow B: expired state refused, Try again offered');
  const stale = mk();
  ok(!stale.pending && stale.phase === null, 'an expired pending sign-in is not restored');
  /* server errors */
  c.browser(WS);
  r = await c.link(dl(`${wsq(WS)}&code=bad${'x'.repeat(20)}&state=${c.pending.state}`));
  ok(!r.ok && c.phase === 'error' && c.retry && c.message.text === 'This sign-in link has expired. Please try again.' && !tracker.signedIn, 'server error code_expired: friendly message, Try again, nobody signed out');
  r = c.op('retry');
  ok(r.ok && c.phase === 'waiting' && c.pending, 'Try again starts a new browser sign-in');
  c.cancel();
  ok(c.phase === null && !c.pending && !mem['sealed:connectPending'], 'Cancel drops the pending sign-in');
  eq([friendly('code_invalid'), friendly('verifier_mismatch'), friendly('disabled'), friendly(null, 'Server says no')], [
    'This sign-in link was already used or is not valid any more. Please try again.', 'This sign-in was started on another computer or in another app. Please try again from here.',
    'Time tracking is turned off for this workspace. Ask your administrator.', 'Server says no'], 'friendly error messages');
  r = c.browser('http://team.example.com');
  ok(!r.ok && c.phase === 'error' && opened.every(x => x.startsWith('https://')), 'flow B refuses a non-https workspace');
  r = await c.link('niyanta-tracker://connect?code=x');
  ok(!r.ok && /not valid/.test(c.message.text), 'invalid link: message, nothing traded');
  clearTimeout(c.timer); clearTimeout(relaunched.timer); }

/* ---------- a packed build, when one is around (dist/*-unpacked): read its fuse wire ---------- */
async function distChecks() {
  const dist = path.join(root, 'dist');
  if (!fs.existsSync(dist)) return;
  for (const d of fs.readdirSync(dist).filter(x => /-unpacked$/.test(x))) {
    const plat = /^win/.test(d) ? 'win32' : /^mac/.test(d) ? 'darwin' : 'linux';
    const bin = { linux: path.join(dist, d, pkg.build.linux.executableName), win32: path.join(dist, d, pkg.productName + '.exe'), darwin: path.join(dist, d, pkg.productName + '.app') }[plat];
    if (!fs.existsSync(bin)) continue;
    const want = fuseConfig(plat, false);
    let wire;
    try { wire = await getCurrentFuseWire(bin); } catch (e) { console.log(`  dist/${d}: fuse wire not readable (${e.message})`); continue; }
    const on = v => v === '1' || v === 49; /* the wire holds the characters '0' / '1' (as char codes in some versions) */
    const diff = [FuseV1Options.RunAsNode, FuseV1Options.EnableCookieEncryption, FuseV1Options.EnableNodeOptionsEnvironmentVariable, FuseV1Options.EnableNodeCliInspectArguments, FuseV1Options.OnlyLoadAppFromAsar, FuseV1Options.EnableEmbeddedAsarIntegrityValidation].filter(o => on(wire[o]) !== want[o]);
    if (process.env.NIYANTA_CHECK_DIST) ok(!diff.length, `dist/${d}: fuses differ from scripts/after-pack.js (${diff.join(',')})`);
    else console.log(`  dist/${d}: fuses ${diff.length ? 'NOT yet flipped (build again with npm run dist)' : 'flipped as configured'}`); } }

/* ---------- uninstall: path validation, the removal plan per OS, the helper scripts ---------- */
function uninstallChecks() {
  const guard = require(path.join(root, 'src', 'main', 'guard.js'));
  const un = require(path.join(root, 'src', 'main', 'uninstall.js'));
  const lx = { platform: 'linux', home: '/home/ana' }, mc = { platform: 'darwin', home: '/Users/ana' }, wn = { platform: 'win32', home: 'C:\\Users\\ana', execPath: 'C:\\Users\\ana\\AppData\\Local\\Programs\\niyanta-tracker\\Niyanta Tracker.exe' };
  const yes = [['/home/ana/.config/Niyanta Tracker', 'userData', lx], ['/Users/ana/Library/Application Support/Niyanta Tracker', 'userData', mc], ['C:\\Users\\ana\\AppData\\Roaming\\Niyanta Tracker', 'userData', wn],
    ['/Applications/Niyanta Tracker.app', 'macApp', mc], ['/Users/ana/Applications/Niyanta Tracker.app', 'macApp', mc], ['/home/ana/Apps/niyanta-tracker-1.4.1-linux-x86_64.AppImage', 'appImage', lx],
    ['/home/ana/.config/autostart/niyanta-tracker.desktop', 'desktopEntry', lx], ['/home/ana/.local/share/applications/niyanta-tracker-url.desktop', 'desktopEntry', lx],
    ['/Users/ana/Library/Saved Application State/app.niyanta.tracker.savedState', 'macState', mc], ['/home/ana/.cache/niyanta-tracker-updater', 'cache', lx],
    ['C:\\Users\\ana\\AppData\\Local\\Programs\\niyanta-tracker\\Uninstall Niyanta Tracker.exe', 'uninstaller', wn], ['/tmp/ny-uninstall-AbC123', 'helperDir', Object.assign({ tmp: '/tmp' }, lx)]];
  for (const [p, k, c] of yes) eq(guard.uninstallPath(p, k, c), p, `uninstall path allowed: ${k} ${p}`);
  const no = [['/', 'userData', lx], ['/home/ana', 'userData', lx], ['/home', 'userData', lx], ['/home/ana/.config', 'userData', lx], ['/home/ana/../ana/.config/Niyanta Tracker', 'userData', lx], ['.config/Niyanta Tracker', 'userData', lx],
    ['/home/ana/.config/Niyanta Tracker/', 'userData', lx], ['/etc/Niyanta Tracker', 'userData', lx], ['/home/bob/.config/Niyanta Tracker', 'userData', lx], ['/home/ana/.config/Other', 'userData', lx],
    ['/Volumes/Niyanta/Niyanta Tracker.app', 'macApp', mc], ['/Applications/Safari.app', 'macApp', mc], ['/Users/ana/Downloads/Niyanta Tracker.app', 'macApp', mc], ['/Applications', 'macApp', mc],
    ['/usr/bin/niyanta-tracker.AppImage', 'appImage', lx], ['/home/ana/Apps/other.AppImage', 'appImage', lx], ['/home/ana/.config/autostart/other.desktop', 'desktopEntry', lx], ['/home/ana/.bashrc', 'desktopEntry', lx],
    ['/home/ana/.config/Niyanta Tracker\nrm -rf ~', 'userData', lx], ["C:\\Users\\ana\\AppData\\Roaming\\Niyanta Tracker\" & del C:\\x", 'userData', wn], ['C:\\Users\\ana\\%APPDATA%\\Niyanta Tracker', 'userData', wn],
    ['C:\\Users\\ana', 'userData', wn], ['C:\\', 'userData', wn], ['C:\\Windows\\Uninstall Niyanta Tracker.exe', 'uninstaller', wn], ['/home/ana/.config/Niyanta Tracker', 'nonsense', lx], [null, 'userData', lx], ['/tmp/other', 'helperDir', Object.assign({ tmp: '/tmp' }, lx)]];
  for (const [p, k, c] of no) eq(guard.uninstallPath(p, k, c), null, `uninstall path refused: ${k} ${JSON.stringify(p)}`);
  /* plans */
  const pl = c => un.plan(Object.assign({ packaged: true, tmp: '/tmp' }, c));
  eq(pl({ platform: 'linux', home: '/home/ana', packaged: false, userData: '/home/ana/.config/Niyanta Tracker', execPath: '/x' }).ok, false, 'no uninstall in a development run');
  let r = pl({ platform: 'linux', home: '/home/ana', userData: '/home/ana/.config/Niyanta Tracker', execPath: '/tmp/.mount_x/niyanta-tracker', appImage: '/home/ana/Apps/niyanta-tracker-1.4.1.AppImage' });
  eq([r.ok, r.kind, r.remove.map(x => x.kind)], [true, 'appimage', ['userData', 'cache', 'desktopEntry', 'desktopEntry', 'appImage']], 'AppImage plan: data, cache, entries, the AppImage');
  r = pl({ platform: 'linux', home: '/home/ana', userData: '/home/ana/.config/Niyanta Tracker', execPath: '/opt/Niyanta Tracker/niyanta-tracker' });
  ok(r.ok && r.kind === 'deb' && !r.remove.some(x => x.path.startsWith('/opt')) && /sudo apt remove niyanta-tracker/.test(r.manual), '.deb plan: data only, the program needs sudo apt remove');
  eq(pl({ platform: 'linux', home: '/home/ana', userData: '/home/ana/.config/Niyanta Tracker', appImage: '/usr/local/bin/evil.AppImage' }).ok, false, 'an AppImage outside the home folder is refused');
  eq(pl({ platform: 'linux', home: '/home/ana', userData: '/home/ana' }).ok, false, 'a data folder that is the home folder is refused');
  r = pl({ platform: 'darwin', home: '/Users/ana', userData: '/Users/ana/Library/Application Support/Niyanta Tracker', execPath: '/Applications/Niyanta Tracker.app/Contents/MacOS/Niyanta Tracker' });
  ok(r.ok && r.kind === 'mac' && r.remove.some(x => x.path === '/Applications/Niyanta Tracker.app') && r.remove.some(x => x.path === '/Users/ana/Library/Logs/Niyanta Tracker'), 'macOS plan: the bundle in Applications, data, logs, caches, state');
  eq(pl({ platform: 'darwin', home: '/Users/ana', userData: '/Users/ana/Library/Application Support/Niyanta Tracker', execPath: '/Volumes/Niyanta Tracker/Niyanta Tracker.app/Contents/MacOS/Niyanta Tracker' }).ok, false, 'macOS: running from the disk image is refused');
  const wctx = { platform: 'win32', home: 'C:\\Users\\ana', userData: 'C:\\Users\\ana\\AppData\\Roaming\\Niyanta Tracker', execPath: wn.execPath };
  r = pl(Object.assign({ exists: () => true }, wctx));
  ok(r.ok && r.kind === 'nsis' && r.uninstaller === 'C:\\Users\\ana\\AppData\\Local\\Programs\\niyanta-tracker\\Uninstall Niyanta Tracker.exe', 'Windows plan: the NSIS uninstaller next to the exe');
  eq(pl(Object.assign({ exists: () => false }, wctx)).ok, false, 'Windows: no uninstaller found, refused');
  /* scripts */
  const sh = un.shScript({ pid: 4242, remove: [{ path: "/home/ana/it's/Niyanta Tracker" }], dir: '/tmp/ny-uninstall-AbC123', log: '/tmp/ny-uninstall-AbC123/uninstall.log', platform: 'linux' });
  ok(sh.includes("gone '/home/ana/it'\\''s/Niyanta Tracker'") && /kill -0 "\$PID"/.test(sh) && /rm -rf -- "\$DIR"/.test(sh) && !/\beval\b|`/.test(sh), 'sh helper: single-quoted paths, waits for the app, removes its folder, no eval');
  let threw = 0;
  for (const bad of [{ pid: 0 }, { dir: '/tmp' }, { log: '/etc/x' }]) { try { un.shScript(Object.assign({ pid: 1, remove: [], dir: '/tmp/ny-uninstall-AbC1', log: '/tmp/ny-uninstall-AbC1/l', platform: 'linux' }, bad)); } catch (e) { threw++; } }
  eq(threw, 3, 'sh helper refuses a bad pid or helper folder');
  const cmd = un.cmdScript({ pid: 4242, uninstaller: r.uninstaller, remove: [{ path: wctx.userData }], dir: 'C:\\Users\\ana\\AppData\\Local\\Temp\\ny-uninstall-AbC123', log: 'C:\\Users\\ana\\AppData\\Local\\Temp\\ny-uninstall-AbC123\\uninstall.log', exe: wn.execPath });
  ok(cmd.includes(`"${r.uninstaller}" /S`) && cmd.includes(`rmdir /s /q "${wctx.userData}"`) && /tasklist \/FI "PID eq 4242"/.test(cmd), 'cmd helper: waits for the app, runs the uninstaller silently, removes the data');
  threw = 0;
  try { un.cmdScript({ pid: 1, uninstaller: 'C:\\a\\Uninstall Niyanta Tracker.exe', remove: [{ path: 'C:\\x" & del C:\\y' }], dir: 'C:\\t\\ny-uninstall-AbC1', log: 'C:\\t\\ny-uninstall-AbC1\\l', exe: 'C:\\a\\n.exe' }); } catch (e) { threw++; }
  eq(threw, 1, 'cmd helper refuses a path with cmd.exe metacharacters');
  /* the sh helper for real (Linux/macOS): removes what it lists, and its own folder */
  if (process.platform !== 'win32') {
    const home = fs.mkdtempSync(path.join(os.tmpdir(), 'ny-home-'));
    const data = path.join(home, '.config', 'Niyanta Tracker'); fs.mkdirSync(path.join(data, 'logs'), { recursive: true }); fs.writeFileSync(path.join(data, 'config.json'), '{}');
    const img = path.join(home, 'niyanta-tracker-1.0.0.AppImage'); fs.writeFileSync(img, 'x');
    const keep = path.join(home, 'keep.txt'); fs.writeFileSync(keep, 'x');
    const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'ny-uninstall-')); const log = path.join(dir, 'uninstall.log');
    const p2 = un.plan({ platform: process.platform === 'darwin' ? 'linux' : process.platform, packaged: true, home, userData: data, appImage: img, tmp: os.tmpdir() });
    fs.writeFileSync(path.join(dir, 'u.sh'), un.shScript({ pid: 999999, remove: p2.remove, dir, log, platform: 'linux' }));
    require('child_process').execFileSync('/bin/sh', [path.join(dir, 'u.sh')], { stdio: 'ignore' });
    eq([fs.existsSync(data), fs.existsSync(img), fs.existsSync(keep), fs.existsSync(dir)], [false, false, true, false], 'sh helper run: data and AppImage gone, other files kept, helper folder and log deleted');
    fs.rmSync(home, { recursive: true, force: true }); }
  /* wiring */
  has('src/main/main.js', /guard\.uninstallPath\(dir, 'helperDir'/, 'the helper folder is validated');
  has('src/main/main.js', /spawn\('\/bin\/sh', \[file\], \{ detached: true/, 'sh helper spawned detached, no shell string');
  not('src/main/main.js', /shell:\s*true/, 'no spawn with shell: true');
  not('src/main/uninstall.js', /require\('electron'\)/, 'uninstall.js is pure (no Electron)');
  has('src/main/uninstall.js', /guard\.uninstallPath\(p, kind, ctx\)/, 'every planned path goes through guard.uninstallPath');
  has('src/main/main.js', /beforeQuit\(4000\); await tracker\.syncBeforeQuit\(\)/, 'tracking is paused and synced before anything is removed');
  has('src/main/main.js', /tracker\.signOut\(\)/, 'the device is signed out (token revoked) first');
  has('src/main/main.js', /setLaunchAtLogin\(false\)/, 'the login item / autostart entry is removed');
  has('src/main/main.js', /removeAsDefaultProtocolClient/, 'the niyanta-tracker:// handler is removed');
  has('src/renderer/index.html', /id="p-uninstall" class="btn danger/, 'Uninstall button in Settings (danger)');
  has('src/renderer/app.js', /Tracking stops and your data is synced[^]*signed out on this device[^]*all its data on this computer are removed/, 'the confirmation says what happens'); }

queueChecks().then(uninstallChecks).then(connectChecks).then(distChecks).catch(e => { bad++; console.error('FAIL ' + (e && e.stack || e)); }).then(() => {
  fs.rmSync(tmp, { recursive: true, force: true });
  console.log(`security: ${n - bad}/${n} checks passed`);
  process.exit(bad ? 1 : 0); });
