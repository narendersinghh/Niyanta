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
 */
const fs = require('fs');
const os = require('os');
const path = require('path');
const Module = require('module');
const root = path.join(__dirname, '..');
const read = f => fs.readFileSync(path.join(root, f), 'utf8');
const src = {};
for (const f of ['src/main/main.js', 'src/main/toast.js', 'src/main/api.js', 'src/main/store.js', 'src/main/queue.js', 'src/main/updater.js', 'src/main/tracker.js', 'src/main/capture.js', 'src/main/errors.js',
  'src/preload/preload.js', 'src/preload/toast-preload.js', 'src/renderer/index.html', 'src/renderer/toast.html', 'src/renderer/app.js', 'src/renderer/toast.js', 'src/shared/status.js', 'package.json']) src[f] = read(f);
const code = Object.entries(src).filter(([f]) => f.endsWith('.js')).map(([, s]) => s).join('\n');
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
not('src/main/main.js', /setAsDefaultProtocolClient|registerSchemesAsPrivileged|protocol\.(handle|register)/, 'no custom protocol handlers');
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
eq((code.match(/shell\.openExternal\(/g) || []).length, 2, 'shell.openExternal appears exactly twice (openTarget and the macOS settings pane)');
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

queueChecks().then(distChecks).catch(e => { bad++; console.error('FAIL ' + (e && e.stack || e)); }).then(() => {
  fs.rmSync(tmp, { recursive: true, force: true });
  console.log(`security: ${n - bad}/${n} checks passed`);
  process.exit(bad ? 1 : 0); });
