'use strict';
/*
 * "Tracker off = no time accrues" checks for the real state machine (src/main/tracker.js), run by npm run check without
 * Electron: electron and the modules that need it are stubbed, the clock is fake (Date), the server is a recorder that can go
 * offline or fail. Each scenario checks what reaches the server (path + the `at` the pause carries) and what the window shows
 * (src/shared/status.js on the tracker's ui()): the counter never moves while the tracker is off.
 *   pause online / offline (queued with its real time, replayed later), a Pause queued behind a failing screenshot upload,
 *   quit (durable pause written to disk before the app exits), system shutdown, sleep (closed where the sleep began, on
 *   every platform, also without the suspend event), wake-up behind the lock screen, sign-out with a busy queue, idle
 *   auto-pause, locked screen where the system idle time stays 0, meeting mode and the pomodoro break.
 */
const fs = require('fs');
const os = require('os');
const path = require('path');
const Module = require('module');
const { EventEmitter } = require('events');
const root = path.join(__dirname, '..');
const S = require(path.join(root, 'src', 'shared', 'status.js'));

let bad = 0, n = 0;
const ok = (c, m) => { n++; if (!c) { bad++; console.error('FAIL ' + m); } };
const eq = (a, b, m) => ok(JSON.stringify(a) === JSON.stringify(b), `${m}: expected ${JSON.stringify(b)}, got ${JSON.stringify(a)}`);

/* ---------- fake clock ---------- */
const RealDate = Date;
let clock = RealDate.UTC(2026, 10, 9, 10, 0, 0);
class FakeDate extends RealDate { constructor(...a) { if (a.length) super(...a); else super(clock); } static now() { return clock; } }
global.Date = FakeDate;
const iso = ms => new RealDate(ms).toISOString();
const wait = (ms = 0) => new Promise(r => setTimeout(r, ms));
const flush = async () => { for (let i = 0; i < 8; i++) await wait(0); await wait(40); };

/* ---------- stubs ---------- */
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'ny-tracker-'));
const sys = { idle: 0, locked: false };
const memStore = {};
const store = { get: (k, d) => (memStore[k] === undefined ? d : memStore[k]), set: (k, v) => { if (v === undefined) delete memStore[k]; else memStore[k] = JSON.parse(JSON.stringify(v)); }, getToken: () => memStore.token || null, setToken: t => { memStore.token = t; }, tokenProtected: () => true, getQueueKey: () => Buffer.alloc(32, 7), resetQueueKey: () => {} };
class Updater extends EventEmitter { constructor() { super(); this.state = {}; this.auto = false; } configure() {} check() {} }
const stubs = {
  electron: { app: { getPath: () => tmp, getVersion: () => '1.4.1' }, powerMonitor: { getSystemIdleTime: () => sys.idle, getSystemIdleState: () => (sys.locked ? 'locked' : 'active') }, screen: { getAllDisplays: () => [] } },
  './store': store, './capture': { screenPermission: () => 'granted', captureAll: async () => [] }, './toast': { show() {} },
  './updater': { Updater }, './selfupdate': { cmpVersion: () => 0, macAppPath: () => null }, './errors': { log() {}, report() {} } };
class ApiError extends Error { constructor(m, status, body) { super(m); this.status = status || 0; this.body = body || {}; this.offline = !status; } get retryable() { return this.offline || this.status >= 500; } }
/* the server: records every request; offline / failing paths answer like the network or a 500 would */
const server = { online: true, fail: new Set(), log: [], state: null };
class Api {
  constructor() { this.base = 'https://ws.test'; this.token = null; }
  configure(b, t) { this.base = b; this.token = t; }
  async request(method, p, o = {}) {
    if (!server.online) throw new ApiError('offline', 0);
    if (server.fail.has(p)) throw new ApiError('server error', 500);
    server.log.push({ path: p, json: o.json ? JSON.parse(JSON.stringify(o.json)) : null, sentAt: clock });
    return { ok: true, state: server.state ? JSON.parse(JSON.stringify(server.state)) : undefined }; }
  get(p, o) { return this.request('GET', p, o); }
  post(p, json, o = {}) { return this.request('POST', p, Object.assign({}, o, { json })); } }
stubs['./api'] = { Api, ApiError };
const load = Module._load;
Module._load = function (req, parent, ...rest) {
  if (req === 'electron') return stubs.electron;
  if (parent && parent.filename && parent.filename.startsWith(path.join(root, 'src', 'main')) && stubs[req] && req !== 'electron') return stubs[req];
  return load.call(this, req, parent, ...rest); };
const { Tracker, UPDATE_EVERY_MS } = require(path.join(root, 'src', 'main', 'tracker.js'));
Module._load = load;

/* ---------- helpers ---------- */
function tracking(t, since = clock - 1800000) {
  const st = { state: 'tracking', label: 'Tracking', server_time: iso(clock), day: { date: '2026-11-09', clock_in: iso(since), clock_out: null, paused_since: null },
    running: { id: 1, started_at: iso(since), elapsed: Math.round((clock - since) / 1000), idle_seconds: 0, project_id: null, device: os.hostname() }, last: null,
    totals: { tracked: Math.round((clock - since) / 1000), active: Math.round((clock - since) / 1000), idle: 0, paused: 0, entries: 1 } };
  t.setSnap(st); return st; }
let prev = null;
function make(settings = {}) {
  /* the previous scenario's tracker is silenced (its queue would send into this one) */
  if (prev) { prev.stop(); prev.queue.paused = true; clearTimeout(prev.queue.timer); clearTimeout(prev._autoResume); }
  server.online = true; server.fail = new Set(); server.log = []; server.state = null; sys.idle = 0; sys.locked = false;
  for (const k of Object.keys(memStore)) delete memStore[k];
  try { fs.rmSync(path.join(tmp, 'queue.json'), { force: true }); } catch (e) { /* none */ }
  const t = new Tracker(() => {});
  t.api.configure('https://ws.test', 'nyt_test');
  t.boot = { user: { id: 7, name: 'Emp', email: 'e@x' }, workspace: { name: 'W', timezone: 'UTC', clock: 12 }, settings: Object.assign({ heartbeat_seconds: 60, idle_minutes: 5, idle_auto_pause_minutes: 15, auto_pause_on_lock: false, stale_minutes: 10, screenshot_interval_seconds: 180 }, settings), consent: null, policy: null, projects: [] };
  t.day = t.dayKey(); t.lastTick = clock; t.nextState = Infinity; t.nextBoot = Infinity; t.nextUpdate = Infinity; t.nextHeartbeat = clock + 60000;
  tracking(t);
  return prev = t; }
/* advance the clock second by second, like the 1-second loop (input: whether there was keyboard/mouse input each second) */
function run(t, secs, input = true) { for (let i = 0; i < secs; i++) { clock += 1000; sys.idle = input ? 0 : sys.idle + 1; t.tick(); } }
const counter = (t, at = clock) => { const ui = t.ui(); return S.current(ui, at); };
const pauses = () => server.log.filter(r => r.path === 'pause');
const shot = () => { const f = path.join(tmp, 'pending-' + Math.random().toString(36).slice(2) + '.jpg'); fs.writeFileSync(f, 'jpeg'); return f; };
const onDisk = () => { try { return JSON.parse(fs.readFileSync(path.join(tmp, 'queue.json'), 'utf8')); } catch (e) { return []; } };

async function main() {
  /* 1. pause online: sent at once with the time it was pressed; nothing ticks afterwards */
  let t = make();
  run(t, 10);
  const pressed = clock;
  await t.action('pause'); await flush();
  eq(pauses().map(r => r.json.at), [iso(pressed)], 'pause online: sent with its time');
  let c0 = counter(t); clock += 3600000; let c1 = counter(t);
  eq([c0.kind, c1.seconds === c0.seconds, c1.total === c0.total, c1.ticking], ['paused', true, true, false], 'paused: the counter and the day total stay frozen an hour later');
  ok(server.log.every(r => r.path !== 'heartbeat' || r.json.at <= iso(pressed)), 'no heartbeat after the pause');
  t.stop();

  /* 2. pause offline: queued on disk with its real time and replayed with that time when the network is back */
  t = make(); run(t, 5);
  server.online = false;
  const offAt = clock;
  await t.action('pause'); await flush();
  eq([t.state, onDisk().filter(j => j.path === 'pause').map(j => j.json.at)], ['paused', [iso(offAt)]], 'pause offline: paused locally, queued on disk with its time');
  clock += 2 * 3600000; server.online = true; t.queue.kick(0); await flush();
  eq(pauses().map(r => r.json.at), [iso(offAt)], 'replayed two hours later with the real pause time');
  t.stop();

  /* 3. a Pause is never stuck behind an upload the server keeps refusing (500) */
  t = make(); run(t, 5);
  server.fail.add('screenshot');
  t.queue.paused = true; t.queue.add({ type: 'screenshot', path: 'screenshot', form: {}, filePath: shot(), bytes: 1 }); t.queue.paused = false;
  await t.action('pause'); await flush();
  eq(pauses().length, 1, 'pause sent before the failing screenshot');
  eq(t.queue.jobs.map(j => j.type), ['screenshot'], 'the screenshot waits for its retry');
  t.queue.clear(); t.stop();

  /* 4. quit while tracking: the pause is on disk before the app goes away; online it is sent before quitting */
  t = make(); run(t, 5);
  server.online = false;
  let quitAt = clock;
  await t.beforeQuit(150);
  eq([t.state, onDisk().filter(j => j.path === 'pause').map(j => j.json.at), memStore.session && memStore.session.tracking], ['paused', [iso(quitAt)], false], 'quit offline: pause queued on disk with the quit time, session saved as not tracking');
  t.stop();
  t = make(); run(t, 5); quitAt = clock;
  await t.beforeQuit(2000); await flush();
  eq([pauses().map(r => r.json.at), onDisk().length], [[iso(quitAt)], 0], 'quit online: pause sent before quitting');
  t.stop();

  /* 5. system shutdown (Windows session-end may end the process at once): written to disk synchronously */
  t = make(); run(t, 5);
  server.online = false;
  const downAt = clock;
  t.onShutdown();
  eq(onDisk().filter(j => j.path === 'pause').map(j => j.json.at), [iso(downAt)], 'shutdown: pause on disk before anything is awaited');
  ok(onDisk().findIndex(j => j.path === 'heartbeat') < onDisk().findIndex(j => j.path === 'pause'), 'the last heartbeat goes first');
  t.queue.clear(); t.stop();

  /* 6. sleep for 40 minutes without any power event (lid closed, Linux): closed where the sleep began, tracking continues */
  t = make(); run(t, 30);
  const before = counter(t).total, sleptAt = clock;
  clock += 40 * 60000; t.tick(); await flush(); await flush();
  eq(pauses().map(r => r.json.at), [iso(sleptAt)], 'sleep: pause with the time the sleep began');
  eq(server.log.filter(r => r.path === 'resume').length, 1, 'and tracking resumes now');
  const hb = server.log.filter(r => r.path === 'heartbeat').pop();
  ok(hb && hb.json.at === iso(sleptAt), 'the heartbeat before the pause carries the time the sleep began: ' + (hb && hb.json.at));
  ok(counter(t).total - before <= 2, `the sleep is not added to the day total (+${counter(t).total - before} s)`);
  t.stop();

  /* 7. woke up behind the lock screen (auto pause on lock): stays paused until the unlock */
  t = make({ auto_pause_on_lock: true }); run(t, 5);
  sys.locked = true; t.onPower('lock-screen'); t.onPower('suspend'); await flush();
  eq(t.state, 'paused', 'locked + asleep: paused');
  clock += 3600000; t.onPower('resume'); await wait(4200); await flush();
  eq([t.state, server.log.filter(r => r.path === 'resume').length], ['paused', 0], 'woke up behind the lock screen: not resumed');
  ok(counter(t).ticking === false, 'nothing ticks behind the lock screen');
  sys.locked = false; t.onPower('unlock-screen'); await wait(700); await flush();
  eq(t.state, 'tracking', 'unlocked: tracking continues');
  t.stop();

  /* 8. sign-out with a busy queue: the pause reaches the server before the queue is cleared, the logout carries the time */
  t = make(); run(t, 5);
  server.fail.add('screenshot');
  t.queue.paused = true; t.queue.add({ type: 'screenshot', path: 'screenshot', form: {}, filePath: shot(), bytes: 1 }); t.queue.paused = false;
  const outAt = clock;
  await t.signOut();
  eq(server.log.filter(r => r.path === 'pause' || r.path === 'logout').map(r => [r.path, r.json.at]), [['pause', iso(outAt)], ['logout', iso(outAt)]], 'sign-out: pause first, then logout, both with the time');
  ok(!t.signedIn, 'signed out');
  t.stop();

  /* 9. idle: after the maximum idle time the pause carries last input + maximum; nothing after it is counted */
  t = make({ idle_minutes: 5, idle_auto_pause_minutes: 15 }); run(t, 10);
  const lastInput = clock;
  run(t, 16 * 60, false); await flush();
  const p = pauses()[0];
  ok(p && Math.abs(RealDate.parse(p.json.at) - (lastInput + 15 * 60000)) <= 2000, 'idle auto-pause at last input + 15 min: ' + (p && p.json.at));
  c0 = counter(t); clock += 1800000;
  eq([t.state, counter(t).total === c0.total], ['paused', true], 'idle auto-paused: the day total stays frozen');
  t.stop();

  /* 10. locked screen where the system idle time stays 0 (some Linux desktops): still auto-paused after the maximum */
  t = make({ idle_minutes: 5, idle_auto_pause_minutes: 15 }); run(t, 10);
  const lockAt = clock; sys.locked = true;
  for (let i = 0; i < 16 * 60; i++) { clock += 1000; sys.idle = 0; t.tick(); }
  await flush();
  const lp = pauses()[0];
  ok(t.state === 'paused' && lp && Math.abs(RealDate.parse(lp.json.at) - (lockAt + 15 * 60000)) <= 2000, 'locked with idle time 0: paused at lock + 15 min: ' + (lp && lp.json.at));
  sys.locked = false; t.stop();

  /* 11. meeting mode: Pause ends it and nothing keeps counting; a pomodoro break is a pause */
  t = make(); run(t, 5);
  await t.setMeeting(true);
  run(t, 60);
  ok(counter(t).kind === 'meeting' && counter(t).ticking, 'meeting: counting while tracking');
  await t.action('pause'); await flush();
  c0 = counter(t); run(t, 600, false);
  eq([t.meeting, c0.kind, counter(t).total === c0.total, counter(t).meetingLeft], [null, 'paused', true, null], 'meeting ended by Pause: frozen, no countdown');
  eq(server.log.filter(r => r.path === 'resume' || r.path === 'day/start').length, 0, 'meeting mode does not start tracking again by itself');
  t.stop();
  /* meeting mode turns off by itself after 30 minutes; the notice offers Turn back on */
  t = make(); run(t, 5);
  await t.setMeeting(true);
  eq(S.MEETING_MS, 30 * 60000, 'meeting mode: 30 minutes');
  run(t, 30 * 60 - 2); ok(!!t.meeting, 'still on just before 30 minutes');
  run(t, 3);
  eq([t.meeting, t.state, t.notice && t.notice.text, t.notice && t.notice.action], [null, 'tracking', 'Meeting mode turned off after 30 minutes. Activity counts again.', 'meeting-on'], 'off after 30 minutes, tracking continues, notice with Turn back on');
  await t.setMeeting(true);
  ok(t.meeting && t.meeting.until - clock === 30 * 60000, 'Turn back on: another 30 minutes');
  t.stop();
  t = make(); t.setPomodoro({ on: true, work: 1, brk: 1 }); run(t, 65);
  await t.takeBreak(); await flush();
  c0 = counter(t); run(t, 120);
  eq([t.state, counter(t).total === c0.total, server.log.filter(r => r.path === 'resume').length], ['paused', true, 0], 'pomodoro break: paused, frozen, never resumes by itself');
  t.stop()

  /* 12. update checks: shortly after start, then every 5 hours; a reconnect (start() again) keeps the schedule; after waking or unlocking at most once per 15 minutes */
  const restart = () => { t.start(); t.stop(); t.nextBoot = Infinity; t.nextState = Infinity; };
  t = make(); t.nextUpdate = undefined; restart();
  const versions = () => server.log.filter(r => r.path === 'version').length;
  eq(UPDATE_EVERY_MS, 5 * 3600000, 'periodic update check every 5 hours');
  for (let i = 0; i < 10; i++) { clock += 1000; t.tick(); }
  await flush();
  eq(versions(), 1, 'one check shortly after start');
  restart(); run(t, 3600); await flush();
  eq(versions(), 1, 'no check on a reconnect or within the next hour');
  clock += 5 * 3600000; t.lastTick = clock - 1000; t.tick(); await flush();
  eq(versions(), 2, 'the next one 5 hours later');
  ok(!t.updateCheckDue(clock + 60000) && t.updateCheckDue(clock + 15 * 60000), 'after unlock or wake: skipped when the last check is less than 15 minutes old');
  await t.checkUpdates(true); await flush();
  eq(versions(), 3, 'Check for updates in Settings is immediate');
  t.stop(); }

main().catch(e => { bad++; console.error('FAIL ' + (e && e.stack || e)); }).then(() => {
  global.Date = RealDate;
  try { fs.rmSync(tmp, { recursive: true, force: true }); } catch (e) { /* temp */ }
  console.log(`tracker off checks (pause, offline, quit, shutdown, sleep, lock, sign-out, idle, meeting, pomodoro, update schedule): ${n - bad}/${n} OK`);
  process.exit(bad ? 1 : 0); });
