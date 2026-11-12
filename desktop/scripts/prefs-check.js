'use strict';
/*
 * Settings that must stick, the screenshot notice, the Windows build and the resource budget (run by npm run check, no
 * Electron: electron is a stub whose userData is a temporary folder; a "restart" loads src/main/store.js and tracker.js again
 * from scratch on the same folder).
 * - every preference (pomodoro on/off and minutes, screenshot alert, launch at startup, alert tone, last project / task)
 *   survives restarts, a new day, bootstrap / state refreshes from the server, sign-out and sign-in, an update from 1.4.3
 *   (everything in one config.json) and a damaged config.json; a rename that Windows refuses (EPERM while a scanner holds
 *   the file) still lands on disk; the 15-second session saves never rewrite config.json; meeting mode does not carry over
 * - the "Screenshot taken" notice: the decision table (workspace notice, may people turn it off, this computer's choice),
 *   the tracker never shows it when off, the notice window never takes the focus, is excluded from capture and goes away
 *   by a deadline counted from the call
 * - Windows: nothing that looks like a dropper (no .cmd/.bat/PowerShell, no cmd.exe, no script in %TEMP%), conventional
 *   installer metadata, signing wired only when the secrets exist
 * - resources: what start-up does not load, no endless animation in the window, no project list re-sent, a long simulated
 *   session that does not grow
 */
const fs = require('fs');
const os = require('os');
const path = require('path');
const Module = require('module');
const { EventEmitter } = require('events');
const root = path.join(__dirname, '..');
const read = f => fs.readFileSync(path.join(root, f), 'utf8').replace(/\r\n/g, '\n');
const strip = s => s.replace(/\/\*[^]*?\*\//g, '').replace(/^\s*\/\/.*$/gm, '');

let bad = 0, n = 0;
const ok = (c, m) => { n++; if (!c) { bad++; console.error('FAIL ' + m); } };
const eq = (a, b, m) => ok(JSON.stringify(a) === JSON.stringify(b), `${m}: expected ${JSON.stringify(b)}, got ${JSON.stringify(a)}`);

/* ---------- fake clock and stubs ---------- */
const RealDate = Date;
let clock = RealDate.UTC(2026, 10, 9, 23, 50, 0);
class FakeDate extends RealDate { constructor(...a) { if (a.length) super(...a); else super(clock); } static now() { return clock; } }
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'ny-prefs-'));
const sys = { idle: 0, locked: false };
const electron = { app: { getPath: () => tmp, getVersion: () => '1.4.4', isPackaged: false }, safeStorage: { isEncryptionAvailable: () => false },
  powerMonitor: { getSystemIdleTime: () => sys.idle, getSystemIdleState: () => (sys.locked ? 'locked' : 'active') }, screen: { getAllDisplays: () => [] } };
const shown = [];
const fakeShots = [{ index: 0, jpeg: Buffer.from('x'), thumb: 'data:image/jpeg;base64,eA==' }];
const stubs = { './capture': { screenPermission: () => 'granted', captureAll: async () => fakeShots }, './toast': { show: o => shown.push(o), hideNow() {}, destroy() {} },
  './selfupdate': { cmpVersion: () => 0, macAppPath: () => null }, './errors': { log() {}, report() {} } };
class Updater extends EventEmitter { constructor() { super(); this.state = {}; this.auto = false; } configure() {} check() {} }
stubs['./updater'] = { Updater };
class ApiError extends Error { constructor(m, status, body) { super(m); this.status = status || 0; this.body = body || {}; this.offline = !status; } get retryable() { return this.offline || this.status >= 500; } }
const server = { settings: {}, log: [] };
class Api {
  constructor() { this.base = 'https://ws.test/'; this.token = null; }
  configure(b, t) { this.base = b; this.token = t; }
  async request(method, p, o = {}) { server.log.push(p); if (p === 'bootstrap') return bootstrap(); if (p === 'state') return { ok: true, state: tracking(), settings: server.settings, consent: true }; return { ok: true }; }
  get(p, o) { return this.request('GET', p, o); }
  post(p, json, o) { return this.request('POST', p, o); } }
stubs['./api'] = { Api, ApiError, normaliseUrl: u => u };
const iso = ms => new RealDate(ms).toISOString();
function tracking() { return { state: 'tracking', label: 'Tracking', server_time: iso(clock), day: { date: iso(clock).slice(0, 10), clock_in: iso(clock - 3600000), paused_since: null },
  running: { id: 1, started_at: iso(clock - 600000), elapsed: 600, project_id: 3, project: 'P', task_id: null, device: os.hostname() }, last: null, totals: { tracked: 3600, active: 3600, idle: 0, paused: 0, entries: 1 } }; }
/* the server's bootstrap carries settings only; never anything about this computer's preferences */
function bootstrap() { return { user: { id: 7, name: 'Emp', email: 'e@x' }, workspace: { name: 'W', timezone: 'UTC', clock: 12 }, settings: Object.assign({ heartbeat_seconds: 60, idle_minutes: 5, idle_auto_pause_minutes: 15, stale_minutes: 10, screenshot_interval_seconds: 180, screenshots_enabled: true, screenshot_notify: true, screenshot_notify_user_can_disable: true }, server.settings), consent: { policy_version: '1' }, policy: null, projects: [{ id: 3, name: 'P', tasks: [{ id: 9, title: 'T' }] }, { id: 4, name: 'Q', tasks: [] }], state: tracking() }; }

/** Load store.js (and tracker.js) fresh, as a new process would: nothing is kept in memory between "restarts". */
function boot() {
  for (const k of Object.keys(require.cache)) if (k.startsWith(path.join(root, 'src'))) delete require.cache[k];
  return { store: require(path.join(root, 'src', 'main', 'store.js')), T: require(path.join(root, 'src', 'main', 'tracker.js')) }; }
/* stays installed: the app loads some modules on first use (capture, toast), long after start-up */
const realLoad = Module._load;
Module._load = function (req, parent, ...rest) {
  if (req === 'electron') return electron;
  if (parent && parent.filename && parent.filename.startsWith(path.join(root, 'src', 'main')) && stubs[req]) return stubs[req];
  return realLoad.call(this, req, parent, ...rest); };
const cfg = () => JSON.parse(fs.readFileSync(path.join(tmp, 'config.json'), 'utf8'));
const wait = (ms = 0) => new Promise(r => setTimeout(r, ms));
const settle = async () => { for (let i = 0; i < 6; i++) await wait(0); await wait(40); };

async function storeChecks() {
  /* 1. the 1.4.3 layout (one config.json with everything) is migrated without losing anything */
  const legacy = { workspaceUrl: 'https://ws.test/', email: 'e@x', token: 'nyt_abcdefghijklmnop', launchAtLogin: false, alertTone: false, notifyScreenshots: false, pomodoro: { on: false, work: 50, brk: 10 },
    session: { user: 7, tracking: true, aliveAt: clock }, bootCache: { user: { id: 7 } }, updatePrompted: '1.4.3', updateNotified: '1.4.3', queueKey: Buffer.alloc(32, 1).toString('base64') };
  fs.writeFileSync(path.join(tmp, 'config.json'), JSON.stringify(legacy));
  let { store } = boot();
  eq([store.get('pomodoro'), store.get('launchAtLogin'), store.get('alertTone'), store.get('notifyScreenshots'), store.getToken()], [legacy.pomodoro, false, false, false, legacy.token], 'update from 1.4.3: every preference and the sign-in read back');
  eq([store.get('session').tracking, store.get('updatePrompted')], [true, '1.4.3'], 'update from 1.4.3: the session and update bookkeeping kept');
  const c = cfg();
  ok(!('session' in c) && !('bootCache' in c) && c.pomodoro.on === false && c.schema === 2, 'the busy keys moved to state.json, the preferences stay in config.json');
  ok(JSON.parse(fs.readFileSync(path.join(tmp, 'state.json'), 'utf8')).session.tracking === true, 'state.json holds the session');
  ({ store } = boot());
  eq(store.get('pomodoro'), legacy.pomodoro, 'restart after the migration: unchanged');

  /* 2. the 15-second session saves never rewrite config.json */
  const before = fs.readFileSync(path.join(tmp, 'config.json'), 'utf8');
  const st0 = fs.statSync(path.join(tmp, 'config.json')).mtimeMs;
  for (let i = 0; i < 50; i++) { store.set('session', { user: 7, tracking: true, aliveAt: clock + i }); store.set('bootCache', { n: i }); }
  ok(fs.readFileSync(path.join(tmp, 'config.json'), 'utf8') === before && fs.statSync(path.join(tmp, 'config.json')).mtimeMs === st0, 'session and bootstrap saves leave config.json alone');
  const wrote = []; const realWrite = fs.writeFileSync;
  fs.writeFileSync = function (f, ...a) { wrote.push(String(f)); return realWrite.call(this, f, ...a); };
  store.set('alertTone', false); store.set('pomodoro', store.get('pomodoro'));
  fs.writeFileSync = realWrite;
  eq(wrote.length, 0, 'setting a preference to the value it has writes nothing');

  /* 3. Windows refuses the rename (EPERM: a scanner holds config.json): the preference still reaches the disk */
  const realRename = fs.renameSync; let refused = 0;
  fs.renameSync = function (a, b) { if (String(b).endsWith('config.json')) { refused++; const e = new Error('EPERM: operation not permitted, rename'); e.code = 'EPERM'; throw e; } return realRename.call(this, a, b); };
  store.set('pomodoro', { on: false, work: 45, brk: 5 });
  fs.renameSync = realRename;
  ok(refused >= 2, 'the refused rename is retried');
  ({ store } = boot());
  eq(store.get('pomodoro'), { on: false, work: 45, brk: 5 }, 'EPERM on rename: the preference is on disk after a restart (written in place)');
  ok(!fs.existsSync(path.join(tmp, 'config.json.tmp')), 'no .tmp file left behind');

  /* 4. config.json cut off by a power loss: the previous good copy is used, nothing is reset */
  store.set('alertTone', true); store.set('alertTone', false);
  fs.writeFileSync(path.join(tmp, 'config.json'), '{"pomodoro":{"on":fal');
  ({ store } = boot());
  eq([store.get('pomodoro'), store.get('alertTone'), !!store.getToken()], [{ on: false, work: 45, brk: 5 }, false, true], 'damaged config.json: preferences and sign-in come from config.json.bak');
  fs.writeFileSync(path.join(tmp, 'config.json'), '');
  ({ store } = boot());
  eq(store.get('pomodoro'), { on: false, work: 45, brk: 5 }, 'empty config.json: recovered too');
  /* a damaged state.json only costs the session */
  fs.writeFileSync(path.join(tmp, 'state.json'), '{"session":');
  ({ store } = boot());
  eq([store.get('session', null), store.get('pomodoro').work], [null, 45], 'damaged state.json: the session is gone, preferences are not'); }

/* ---------- the tracker: nothing resets a preference ---------- */
async function trackerChecks() {
  global.Date = FakeDate;
  for (const f of ['config.json', 'config.json.bak', 'state.json', 'queue.json']) fs.rmSync(path.join(tmp, f), { force: true });
  let { store, T } = boot();
  store.set('workspaceUrl', 'https://ws.test/'); store.setToken('nyt_abcdefghijklmnop');
  let t = new T.Tracker(() => {});
  await t.restore(); await settle();
  eq(t.pomo.prefs.on, false, 'pomodoro is off by default');
  t.setPomodoro({ on: true, work: 50, brk: 10 });
  t.setPomodoro({ on: false, work: 50, brk: 10 });
  store.set('notifyScreenshots', false); store.set('alertTone', false); store.set('launchAtLogin', false);
  t.setSelection({ project_id: 4, task_id: null });
  const prefs = () => [store.get('pomodoro'), store.get('notifyScreenshots'), store.get('alertTone'), store.get('launchAtLogin')];
  const want = [{ on: false, work: 50, brk: 10 }, false, false, false];
  eq(prefs(), want, 'preferences saved');
  /* bootstrap and state refreshes, the 15-minute bootstrap, a new day at midnight */
  await t.refreshBoot(); await t.refreshState();
  for (let i = 0; i < 20 * 60; i++) { clock += 1000; t.tick(); }
  await settle();
  ok(t.dayKey() === '2026-11-10', 'the day rolled over (midnight passed)');
  eq([prefs(), t.pomo.prefs.on], [want, false], 'after refreshes and a new day: unchanged, the reminder stays off');
  t.stop();
  /* restart (crash or normal), then sign out and in again */
  ({ store, T } = boot());
  t = new T.Tracker(() => {});
  eq(t.pomo.prefs, { on: false, work: 50, brk: 10 }, 'restart: the pomodoro settings are read back');
  await t.restore(); await settle();
  eq(t.ui().prefs.tone, false, 'restart: the tone stays off in the window');
  eq(t.selection, { project_id: 3, task_id: null }, 'the running entry decides the selection when there is one');
  t.signOutLocal(null);
  eq(prefs(), want, 'sign-out keeps this computer\'s preferences');
  t.adopt('https://ws.test/', Object.assign(bootstrap(), { token: 'nyt_qrstuvwxyz012345' }), 'e@x');
  eq([prefs(), t.pomo.prefs.on], [want, false], 'sign-in again: unchanged');
  t.stop();
  /* last project / task: used when the server has no running or last entry */
  ({ store, T } = boot());
  const quiet = bootstrap(); quiet.state = Object.assign(tracking(), { state: 'not_started', running: null, last: null });
  t = new T.Tracker(() => {}); t.api.configure('https://ws.test/', 'x'); t.applyBoot(quiet);
  eq(t.selection, { project_id: 4, task_id: null }, 'last project picked here comes back after a restart');
  /* meeting mode never carries over to a new day: the saved meeting ended long ago */
  t.snap = tracking(); t.restoreMeeting({ since: clock - 26 * 3600000, until: clock - 25.5 * 3600000 });
  eq(t.meeting, null, 'meeting mode from yesterday is not restored');
  t.stop();
  /* the pomodoro reminder never fires when it is off, however long one works */
  ({ store, T } = boot());
  t = new T.Tracker(() => {}); await t.restore(); await settle();
  let due = 0; t.on('pomodoro', () => due++);
  for (let i = 0; i < 6 * 3600; i++) { clock += 1000; t.tick(); }
  eq(due, 0, 'off: no break reminder in 6 hours of work');
  t.setPomodoro({ on: true, work: 50, brk: 10 });
  for (let i = 0; i < 51 * 60; i++) { clock += 1000; t.tick(); }
  eq(due, 1, 'on: the reminder after 50 minutes');
  t.stop();
  global.Date = RealDate; }

/* ---------- screenshot notice ---------- */
async function noticeChecks() {
  const { T } = boot();
  const d = T.notifyDecision;
  const table = [
    [{ screenshot_notify: false, screenshot_notify_user_can_disable: true }, undefined, false, 'workspace notice off'],
    [{ screenshot_notify: false, screenshot_notify_user_can_disable: true }, true, false, 'workspace notice off beats an old "on" chosen here'],
    [{ screenshot_notify: false, screenshot_notify_user_can_disable: false }, true, false, 'workspace notice off, no choice'],
    [{ screenshot_notify: true, screenshot_notify_user_can_disable: false }, false, true, 'workspace keeps it on for everyone'],
    [{ screenshot_notify: true, screenshot_notify_user_can_disable: true }, undefined, true, 'on until turned off here'],
    [{ screenshot_notify: true, screenshot_notify_user_can_disable: true }, false, false, 'turned off here'],
    [{}, undefined, false, 'no settings yet (before the first bootstrap)'], [null, true, false, 'no settings at all']];
  for (const [s, pref, want, m] of table) eq(d(s, pref), want, 'notice: ' + m);
  /* the tracker: a screenshot with the notice off shows nothing; on shows one */
  global.Date = FakeDate;
  const { store, T: T2 } = boot();
  store.set('workspaceUrl', 'https://ws.test/'); store.setToken('nyt_abcdefghijklmnop'); store.set('notifyScreenshots', false);
  server.settings = { screenshot_notify: true, screenshot_notify_user_can_disable: true };
  const t = new T2.Tracker(() => {}); await t.restore(); await settle();
  shown.length = 0;
  await t.screenshot(80, { meeting: 0 });
  eq(shown.length, 0, 'notice turned off here: no notice after a screenshot');
  eq([t.ui().prefs.notify, t.ui().prefs.notifyAllowed], [false, true], 'the switch shows off and can be changed');
  store.set('notifyScreenshots', true); server.settings = { screenshot_notify: false, screenshot_notify_user_can_disable: true };
  await t.refreshState();
  await t.screenshot(80, { meeting: 0 });
  eq([shown.length, t.ui().prefs.notify, t.ui().prefs.notifyAllowed], [0, false, false], 'workspace turned the notice off (fresh state answer): none, and the switch is locked off');
  server.settings = { screenshot_notify: true, screenshot_notify_user_can_disable: false };
  await t.refreshState();
  await t.screenshot(80, { meeting: 0 });
  eq(shown.length, 1, 'workspace keeps it on: shown');
  t.stop(); global.Date = RealDate;
  /* the notice window */
  const toast = read('src/main/toast.js'), code = strip(toast);
  ok(/focusable: false/.test(code) && /showInactive\(\)/.test(code) && !/\.show\(\)/.test(code.replace(/Notification\([^)]*\)\.show\(\)/, '')) && !/\.focus\(\)/.test(code), 'notice window: never focusable, only shown inactive');
  ok(/setContentProtection\(true\)/.test(code), 'notice window: excluded from screen capture');
  ok(/skipTransformProcessType: true/.test(code), 'notice window: does not hide the macOS dock icon');
  ok(/setIgnoreMouseEvents\(true\)/.test(code), 'notice window: clicks go through');
  ok(/hideTimer = setTimeout\(\(\) => \{ if \(my === seq\) hideNow\(\); \}, HIDE_MS\)/.test(code) && code.indexOf('hideTimer = setTimeout') < code.indexOf('return true') && /Date\.now\(\) >= deadline\) return;/.test(code), 'notice window: hidden by a deadline counted from the call, never shown after it (a slow load cannot leave it up)');
  ok(/dropTimer = setTimeout\(destroy, KEEP_MS\)/.test(code) && /function hideNow\(\) \{[^]*seq\+\+/.test(code), 'notice window: destroyed when no notice follows within a minute; hiding cancels a pending show');
  ok(/w\.destroy\(\)/.test(code) && /function place\(\) \{ return noticeBounds\(screen\.getPrimaryDisplay\(\)\.workArea/.test(code), 'notice window: can be destroyed, placed by screens.noticeBounds on every show');
  /* geometry: the notice inside the work area on scaled, offset (secondary on the left / above) and tiny displays */
  const sc = require(path.join(root, 'src', 'main', 'screens.js'));
  const inside = (r, a) => r.x >= a.x && r.y >= a.y && r.x + r.width <= a.x + a.width && r.y + r.height <= a.y + a.height && [r.x, r.y, r.width, r.height].every(Number.isInteger);
  for (const a of [{ x: 0, y: 0, width: 1920, height: 1040 }, { x: -1280, y: 0, width: 1280, height: 984 }, { x: 0, y: -1080, width: 1536.5, height: 816.4 }, { x: 0, y: 0, width: 280, height: 60 }, { x: 2560, y: 25, width: 1440, height: 875 }])
    ok(inside(sc.noticeBounds(a, 300, 76, 16), { x: a.x, y: a.y, width: Math.ceil(a.width), height: Math.ceil(a.height) }), `notice inside the work area ${JSON.stringify(a)}`);
  eq(sc.noticeBounds({ x: 0, y: 0, width: 1920, height: 1040 }, 300, 76, 16), { x: 1604, y: 948, width: 300, height: 76 }, 'notice bottom-right with a 16 px margin');
  /* the main window after a monitor is unplugged: back on a display; left alone while a corner is visible */
  const main = { x: 2400, y: 200, width: 384, height: 700 }, primary = { x: 0, y: 0, width: 1920, height: 1040 };
  eq(sc.fitWindow(main, [primary], primary), { x: 768, y: 170, width: 384, height: 700 }, 'window on an unplugged monitor: centred on the remaining one');
  eq(sc.fitWindow({ x: 1800, y: 900, width: 384, height: 700 }, [primary], primary), null, 'window partly visible: left where it is');
  eq(sc.fitWindow({ x: 3000, y: 0, width: 460, height: 900 }, [{ x: 0, y: 0, width: 1280, height: 680 }], { x: 0, y: 0, width: 1280, height: 680 }), { x: 410, y: 0, width: 460, height: 680 }, 'window taller than a small (scaled) display: fitted inside');
  const tr = strip(read('src/main/tracker.js'));
  ok(/toast\(\)\.hideNow\(\)[^]*captureAll/.test(tr), 'a notice still visible is removed before the next capture');
  ok(/if \(notify && this\.notifyOn\(\)\) \{ toast\(\)\.show\(/.test(tr) && (tr.match(/toast\(\)\.show\(/g) || []).length === 1, 'the tracker shows the notice in one place, only when it is on'); }

/* ---------- in-app notices: every banner and card has an x, routed through the validated IPC ---------- */
async function dismissChecks() {
  const guard = require(path.join(root, 'src', 'main', 'guard.js'));
  eq(guard.DISMISS_IDS, ['notice', 'pomodoro', 'idle', 'offline', 'permission', 'consent', 'update'], 'dismissable notice ids (allowlist)');
  const main = strip(read('src/main/main.js')), pre = read('src/preload/preload.js'), app = read('src/renderer/app.js');
  ok(/h\('dismiss', id => \{ id = guard\.str\(id, 20\); return guard\.DISMISS_IDS\.includes\(id\) \? tracker\.dismiss\(id\) : \{ ok: false \}; \}\)/.test(main) && /dismiss: 60/.test(main), 'tracker:dismiss: typed, allowlisted, rate limited');
  ok(/dismiss: \(id\) => call\('dismiss', text\(id, 20\)\)/.test(pre), 'preload: dismiss(id) only');
  ok(/aria-label="Dismiss"/.test(app) && (app.match(/, '(notice|pomodoro|idle|offline|permission|consent)'\)\)?\]?;?/g) || []).length >= 8 && /closeX\('update'\)/.test(app), 'every banner (notice, break reminder, break, break over, idle pause, offline, permission, consent) and the update card have the x');
  ok(/\.x-btn:focus-visible\{[^}]*outline:2px solid var\(--primary\)/.test(read('src/renderer/styles.css')), 'the x has a visible focus ring in the brand colour');
  global.Date = FakeDate;
  for (const f of ['config.json', 'config.json.bak', 'state.json', 'queue.json']) fs.rmSync(path.join(tmp, f), { force: true });
  let { store, T } = boot();
  store.set('workspaceUrl', 'https://ws.test/'); store.setToken('nyt_abcdefghijklmnop');
  let t = new T.Tracker(() => {}); await t.restore(); await settle();
  t.flash('Tracking was interrupted.', 'info'); t.dismiss('notice');
  eq(t.ui().notice, null, 'notice: closed for good (not back on the next state push)');
  t.setPomodoro({ on: true, work: 50, brk: 10 });
  for (let i = 0; i < 51 * 60; i++) { clock += 1000; t.tick(); }
  ok(t.ui().pomodoro.due, 'break reminder due'); t.dismiss('pomodoro');
  eq([t.ui().pomodoro.due, t.ui().pomodoro.worked], [false, 0], 'break reminder closed: gone, the next one after another full period');
  t.offline = true; t.dismiss('offline');
  ok(t.ui().dismissed.offline, 'offline banner closed while offline');
  t.offline = false; t.ui(); t.offline = true;
  ok(!t.ui().dismissed.offline, 'offline banner back the next time the connection drops');
  t.dismiss('consent'); ok(t.ui().dismissed.consent, 'consent reminder closed for this run');
  t.update = { available: true, latest: '1.5.0' };
  ok(!t.ui().update.dismissed, 'update card shown'); t.dismiss('update');
  ok(t.ui().update.dismissed && store.get('updateDismissed') === '1.5.0', 'update card closed for this version (stored)');
  t.stop();
  ({ store, T } = boot());
  t = new T.Tracker(() => {}); await t.restore(); await settle();
  t.update = { available: true, latest: '1.5.0' };
  ok(t.ui().update.dismissed && !t.ui().dismissed.consent, 'after a restart: the update card stays closed for 1.5.0, the consent reminder is back');
  t.update = { available: true, latest: '1.5.1' };
  ok(!t.ui().update.dismissed, 'a newer version shows the card again');
  t.stop(); global.Date = RealDate; }

/* ---------- Windows: conventional build, nothing that looks like a dropper ---------- */
function windowsChecks() {
  const pkg = JSON.parse(read('package.json'));
  const all = [];
  (function walk(d) { for (const f of fs.readdirSync(path.join(root, d), { withFileTypes: true })) { const p = path.join(d, f.name); if (f.isDirectory()) walk(p); else if (/\.(js|html|nsh)$/.test(f.name)) all.push(p); } })('src');
  all.push('build/installer.nsh');
  for (const f of all) {
    const s = f.endsWith('.nsh') ? read(f).replace(/^\s*;.*$/gm, '') : strip(read(f));
    ok(!/\.(cmd|bat|ps1|vbs)\b['"`]|powershell|wscript|cscript|ComSpec|cmd\.exe|reg(\.exe)? add|schtasks|bitsadmin|certutil|attrib \+h/i.test(s), `${f}: no batch / PowerShell / script hosts / registry or task tools`);
    ok(!/windowsHide:\s*true/.test(s), `${f}: no hidden console windows`); }
  const nsh = read('build/installer.nsh').replace(/^\s*;.*$/gm, '');
  ok(!/\b(Exec|ExecWait|ExecShell|nsExec|ExecDos)\b/.test(nsh), 'installer.nsh runs no programs');
  ok(/\$\{ifNot\} \$\{isUpdated\}[^]*RMDir \/r "\$LOCALAPPDATA\\niyanta-tracker-updater"/.test(nsh), 'a real uninstall removes the update cache (an update keeps it)');
  const main = strip(read('src/main/main.js'));
  ok(/spawn\(p\.uninstaller, uninstall\(\)\.NSIS_ARGS/.test(main) && !/mkdtempSync[^\n]*\n[^\n]*win32/.test(main), 'Windows uninstall: the NSIS uninstaller itself, nothing written to %TEMP%');
  ok(/if \(!win32\) \{\n\s*dir = fs\.mkdtempSync/.test(main), 'the temporary helper folder exists on macOS / Linux only');
  ok(/app\.setLoginItemSettings\(/.test(main), 'launch at login: the documented Electron API (Run key / Login Items)');
  ok(/store\.get\('launchAtLogin'\) === undefined && app\.isPackaged/.test(main), 'a development run never registers itself at login');
  eq([pkg.description, pkg.productName, pkg.author && pkg.author.name, /Copyright \(C\) \d{4} Niyanta/.test(pkg.build.copyright)], ['Niyanta Tracker', 'Niyanta Tracker', 'Niyanta', true], 'version info: file description = product name, company, copyright');
  eq([pkg.build.win.requestedExecutionLevel, pkg.build.win.signAndEditExecutable, pkg.build.win.legalTrademarks], ['asInvoker', true, 'Niyanta'], 'Windows exe: no elevation, version resource written');
  eq([pkg.build.nsis.oneClick, pkg.build.nsis.perMachine, pkg.build.nsis.deleteAppDataOnUninstall, pkg.build.nsis.artifactName], [false, false, false, 'niyanta-tracker-${version}-win-${arch}.${ext}'], 'NSIS: assisted per-user installer, data kept unless asked, stable file name');
  ok(!pkg.build.win.publisherName && !pkg.build.win.azureSignOptions && !pkg.build.win.certificateFile, 'no signing settings in package.json (unsigned builds keep working; CI adds them only with secrets)');
  const wf = read('../.github/workflows/desktop.yml');
  ok(/AZURE_TENANT_ID: \$\{\{ secrets\.AZURE_TENANT_ID \}\}/.test(wf) && /AZURE_CLIENT_SECRET: \$\{\{ secrets\.AZURE_CLIENT_SECRET \}\}/.test(wf) && /vars\.AZURE_TRUSTED_SIGNING_ENDPOINT/.test(wf), 'workflow: Azure Trusted Signing secrets and variables wired');
  ok(/-c\.win\.azureSignOptions\.endpoint=/.test(wf) && /\[ -n "\$AZURE_CLIENT_SECRET" \] && \[ -n "\$AZURE_TENANT_ID" \]/.test(wf), 'workflow: Azure signing only when the secrets exist');
  ok(/WIN_CSC_LINK: \$\{\{ secrets\.WIN_CSC_LINK \}\}/.test(wf), 'workflow: a .pfx certificate still works');
  const readme = read('README.md'), rel = read('../docs/RELEASING.md');
  ok(/## Windows Defender/.test(readme) && /wdsi\/filesubmission/.test(rel) && /Azure Trusted Signing/.test(rel) && /Azure Trusted Signing/.test(readme), 'docs: Defender section, false-positive submission per release, signing'); }

/* ---------- resources ---------- */
async function resourceChecks() {
  const main = read('src/main/main.js'), tr = read('src/main/tracker.js');
  const top = s => s.split('\n').filter(l => /^const .*= require\(/.test(l)).join('\n');
  for (const m of ['capture', 'toast', 'uninstall', 'badge']) ok(!new RegExp(`require\\('\\./${m}'\\)`).test(top(main)), `main.js: ./${m} is not loaded at start`);
  for (const m of ['capture', 'toast']) ok(!new RegExp(`require\\('\\./${m}'\\)`).test(top(tr)), `tracker.js: ./${m} is not loaded at start`);
  const css = read('src/renderer/styles.css'), app = read('src/renderer/app.js');
  ok(!/animation:[^;}]*infinite/.test(css.replace(/\.si-spin\{[^}]*\}|\.m-art \.bubble\.spin \.ic\{[^}]*\}/g, '')), 'no endless animation in the tracker window (only spinners while something is in progress)');
  ok(/visibilitychange[^]*stopTicker/.test(app), 'the window\'s clock stops while it is hidden');
  ok(/projects: undefined/.test(main) && /next\.projects === undefined/.test(app), 'the project list is sent to the window only when it changed');
  { const { T } = boot(); const t = new T.Tracker(() => {}); t.api.configure('https://ws.test/', 'x');
    t.applyBoot(bootstrap()); const r1 = t.ui().projectsRev; t.applyBoot(bootstrap()); const r2 = t.ui().projectsRev;
    const b = bootstrap(); b.projects.push({ id: 5, name: 'New', tasks: [] }); t.applyBoot(b);
    ok(r1 === r2 && t.ui().projectsRev === r2 + 1, 'a bootstrap refresh with the same projects keeps the revision; a new project bumps it');
    t.stop(); }
  ok(/setSpellCheckerEnabled\(false\); if \(process\.platform !== 'darwin'\) ses\.setSpellCheckerLanguages\(\[\]\)/.test(main), 'no spell checker and no dictionary download from Google at start');
  /* a long simulated day: memory and the lists the tracker keeps stay bounded */
  global.Date = FakeDate;
  for (const f of ['config.json', 'config.json.bak', 'state.json', 'queue.json']) fs.rmSync(path.join(tmp, f), { force: true });
  const { store, T } = boot();
  store.set('workspaceUrl', 'https://ws.test/'); store.setToken('nyt_abcdefghijklmnop');
  const t = new T.Tracker(() => {}); await t.restore(); await settle();
  let ui = 0; t.on('change', () => ui++);
  const step = async hours => { for (let i = 0; i < hours * 3600; i++) { clock += 1000; sys.idle = i % 900 < 600 ? 0 : sys.idle + 1; t.tick(); if (i % 600 === 0) await settle(); } await settle(); };
  await step(1);
  if (global.gc) global.gc();
  const h0 = process.memoryUsage().heapUsed;
  await step(8);
  if (global.gc) global.gc();
  const h1 = process.memoryUsage().heapUsed;
  ok(t.spans.length < 10 && t.queue.size < 20 && JSON.stringify(t.ui()).length < 20000, `8 hours tracked: idle spans ${t.spans.length}, queue ${t.queue.size}, window state ${JSON.stringify(t.ui()).length} bytes stay small`);
  ok(!global.gc || h1 - h0 < 8 * 1048576, `8 hours tracked: heap grew by ${Math.round((h1 - h0) / 1024)} KB (under 8 MB)`);
  t.stop(); global.Date = RealDate; }

/* ---------- macOS Screen Recording after an update (src/main/permfix.js, pure; fake platform and store) ---------- */
async function permChecks() {
  const { create } = require(path.join(root, 'src', 'main', 'permfix.js'));
  const mk = (platform, packaged, version, mem = {}) => {
    const resets = [];
    const st = { get: k => mem[k], set: (k, v) => { mem[k] = v; } };
    return { f: create({ platform, packaged, version, store: st, reset: async () => { resets.push(version); return true; } }), resets, mem }; };
  const tick = () => new Promise(r => setTimeout(r, 0));
  /* screenshots worked on 1.4.5, then the update to 1.4.6: reset once at start, the prompt comes with the first capture */
  let m = mk('darwin', true, '1.4.5'); m.f.worked();
  eq(m.mem.screenOk, '1.4.5', 'macOS: the version with working screenshots is remembered');
  let a = mk('darwin', true, '1.4.6', m.mem);
  eq([a.f.atStart(), a.f.waiting], [true, true], 'macOS: updated from a version with working screenshots: reset at start, waiting for Allow');
  await tick();
  eq([a.f.failed('granted'), a.f.atStart()], [false, false], 'never more than once per version (also not after a failed capture)');
  a.f.worked(); await tick();
  eq([a.resets.length, a.f.waiting, a.mem.screenOk], [1, false, '1.4.6'], 'one reset; a working capture ends the wait');
  const again = mk('darwin', true, '1.4.6', a.mem); eq(again.f.atStart(), false, 'restart of the same version: no reset');
  /* no update, capture fails while macOS says granted: reset once for this version */
  let b = mk('darwin', true, '1.5.0', { screenOk: '1.5.0' });
  eq([b.f.atStart(), b.f.failed('denied'), b.f.failed('granted'), b.f.failed('granted')], [false, false, true, false], 'macOS: capture failing while granted resets once; denied never resets');
  await tick(); eq(b.resets.length, 1, 'one reset run');
  /* never granted before: nothing at start */
  eq(mk('darwin', true, '1.5.0', {}).f.atStart(), false, 'macOS: screenshots never worked before: no reset after the update');
  /* other platforms and dev runs: never */
  for (const [p, pk] of [['win32', true], ['linux', true], ['darwin', false]]) {
    const x = mk(p, pk, '1.5.0', { screenOk: '1.4.6' });
    eq([x.f.atStart(), x.f.failed('granted'), x.f.waiting], [false, false, false], `no reset on ${p}${pk ? '' : ' (dev run)'}`);
    await tick(); eq(x.resets.length, 0, `reset never runs on ${p}${pk ? '' : ' (dev run)'}`); } }

(async () => {
  await permChecks();
  await storeChecks();
  await trackerChecks();
  await noticeChecks();
  await dismissChecks();
  windowsChecks();
  await resourceChecks(); })().catch(e => { bad++; console.error('FAIL ' + (e && e.stack || e)); }).then(() => {
  global.Date = RealDate;
  try { fs.rmSync(tmp, { recursive: true, force: true }); } catch (e) { /* temp */ }
  console.log(`preferences, screenshot notice, Windows build and resource checks: ${n - bad}/${n} OK`);
  process.exit(bad ? 1 : 0); });
