'use strict';
/*
 * Checks without Electron (run by npm run check):
 * - the big counter (src/shared/status.js): the session since the last start, resume or switch while tracking, meeting mode
 *   with its countdown; while the tracker is off (paused, break, stopped, ended, not started) today's time, frozen
 * - meeting-mode slot flagging (src/main/meter.js meetingSlot, fed like tracker.slotTick)
 * - the offline queue flushes before quitting (src/main/queue.js flush: retries quickly, resolves when empty, Quit anyway)
 * - the tracking dot bitmaps (src/main/badge.js)
 * - activity from the system idle time on every platform (src/main/activity.js + meter.js), no input hook anywhere
 */
const fs = require('fs');
const os = require('os');
const path = require('path');
const Module = require('module');
const root = path.join(__dirname, '..');
const S = require(path.join(root, 'src', 'shared', 'status.js'));
const meter = require(path.join(root, 'src', 'main', 'meter.js'));
const badge = require(path.join(root, 'src', 'main', 'badge.js'));

let bad = 0, n = 0;
const ok = (c, m) => { n++; if (!c) { bad++; console.error('FAIL ' + m); } };
const eq = (a, b, m) => ok(JSON.stringify(a) === JSON.stringify(b), `${m}: expected ${JSON.stringify(b)}, got ${JSON.stringify(a)}`);

/* ---------- big counter: time of the current status ---------- */
const NOW = Date.UTC(2026, 10, 7, 10, 0, 0);
const iso = ms => new Date(ms).toISOString();
const base = (state, extra = {}) => Object.assign({ state: { state, totals: { tracked: 3 * 3600, active: 3 * 3600 - 300, idle: 300, paused: 1200 }, day: {}, running: null, server_time: iso(NOW - 5000) }, at: NOW - 5000, skew: 0, idle: false, meeting: null }, extra);

let ui = base('tracking'); ui.state.running = { started_at: iso(NOW - 125000 - 5000), elapsed: 125 };
let c = S.current(ui, NOW);
eq([c.kind, c.seconds, c.total, c.since], ['tracking', 130, 3 * 3600 + 5, NOW - 130000], 'tracking: the current session (since the last start/resume/switch), day total below');
eq(S.hms(c.seconds), '0:02:10', 'counter format');
ui = base('tracking'); ui.state.running = { started_at: iso(NOW - 5000), elapsed: 0 }; ui.state.totals.tracked = 3 * 3600 + 130;
c = S.current(ui, NOW);
eq([c.seconds, c.total], [5, 3 * 3600 + 135], 'project switch: the counter starts again, the day total continues');
ui = base('tracking', { skew: 90000 }); ui.state.running = { started_at: iso(NOW + 90000 - 60000), elapsed: 55 };
eq(S.current(ui, NOW).since, NOW - 60000, 'session start on the local clock (server ahead by 90 s)');
ui = base('tracking', { idle: true }); ui.state.running = { started_at: iso(NOW - 900000), elapsed: 895 };
c = S.current(ui, NOW);
eq([c.kind, c.label, c.seconds], ['idle', 'Idle', 900], 'idle: still the session time (the timer keeps running)');
ui = base('tracking', { meeting: { since: NOW - 600000, until: NOW + 3000000 } }); ui.state.running = { started_at: iso(NOW - 600000), elapsed: 595 };
c = S.current(ui, NOW);
eq([c.kind, c.label, c.seconds, c.meetingLeft], ['meeting', 'In a meeting', 600, 3000000], 'meeting: session time and the countdown to the automatic end');
ui.meeting = { since: NOW - 3700000, until: NOW - 100000 };
eq(S.current(ui, NOW).kind, 'tracking', 'an expired meeting shows plain tracking');
/* tracker off: nothing ticks, whatever the clock does (regression: 1.4.1 counted the paused time up in the big counter) */
ui = base('paused', { skew: -30000 }); ui.state.day = { paused_since: iso(NOW - 30000 - 720000) };
c = S.current(ui, NOW);
eq([c.kind, c.seconds, c.total, c.ticking, c.since], ['paused', 3 * 3600, 3 * 3600, false, NOW - 720000], 'paused: today\'s tracked time, frozen; paused since on the local clock (server 30 s behind)');
for (const later of [1000, 60000, 3 * 3600000]) { const x = S.current(ui, NOW + later); eq([x.seconds, x.total], [3 * 3600, 3 * 3600], `paused, ${later / 1000} s later: counter and day total do not move`); }
ui = base('paused'); ui.state.day = {};
eq(S.current(ui, NOW + 600000).seconds, 3 * 3600, 'paused without paused_since: frozen too');
ui = base('paused', { meeting: { since: NOW - 60000, until: NOW + 3000000 }, idle: true }); ui.state.day = { paused_since: iso(NOW - 60000) };
eq([S.current(ui, NOW + 60000).kind, S.current(ui, NOW + 60000).seconds, S.current(ui, NOW + 60000).meetingLeft], ['paused', 3 * 3600, null], 'paused with a stale meeting or idle flag (pomodoro break, meeting ended by Pause): frozen, no meeting countdown');
for (const st of ['not_started', 'stopped', 'ended']) {
  const x = S.current(base(st), NOW + 3600000);
  eq([x.seconds, x.total, x.ticking], [3 * 3600, 3 * 3600, false], st + ': today\'s time, frozen an hour later'); }
eq(S.current(Object.assign(base('not_started'), { state: { state: 'not_started', totals: { tracked: 0 }, day: {} } }), NOW + 5000).seconds, 0, 'a new day (closed at midnight): 0 and frozen');
ok(S.current(ui, NOW).ticking === false && S.current(Object.assign(base('tracking'), { state: Object.assign(base('tracking').state, { running: { elapsed: 1 } }) }), NOW).ticking === true, 'ticking only while tracking');
eq(S.current(Object.assign(base('tracking', { offline: true }), { state: Object.assign(base('tracking').state, { running: { elapsed: 1 } }) }), NOW).label, 'Offline · Tracking', 'offline label');
eq([S.hms(3725), S.hm(3725), S.mmss(61000)], ['1:02:05', '1h 02m', '1:01'], 'formats');

/* ---------- meeting-mode slot flagging, fed like tracker.slotTick ---------- */
function slot(len, meetingFrom, meetingTo, trackedFrom = 0, trackedTo = len) {
  const s = meter.slot(NOW, len);
  for (let t = 1; t <= len; t++) {
    if (t <= trackedFrom || t > trackedTo) continue;
    const added = meter.tick(s, NOW + t * 1000, 1, NOW + t * 1000 - 200, { input: true });
    if (t > meetingFrom && t <= meetingTo) s.meet = Math.min(s.tracked, s.meet + added); }
  return meter.finish(s); }
eq(meter.meetingSlot(slot(180, 0, 180)), true, 'a slot fully in a meeting is a meeting slot');
eq(meter.meetingSlot(slot(180, 60, 180)), true, 'meeting for 120 of 180 s: meeting slot');
eq(meter.meetingSlot(slot(180, 90, 180)), true, 'meeting for exactly half: meeting slot');
eq(meter.meetingSlot(slot(180, 120, 180)), false, 'meeting for 60 of 180 s: a normal slot');
eq(meter.meetingSlot(slot(180, 200, 300)), false, 'no meeting: a normal slot');
eq(meter.meetingSlot(slot(180, 0, 100, 0, 150)), true, 'paused part of the slot is not counted (100 of 150 tracked s)');
const ms = slot(180, 0, 180);
eq([ms.tracked, ms.active], [180, 180], 'a meeting slot keeps its activity (kept for managers, not averaged)');
eq(meter.meetingSlot(null), false, 'no slot');

/* ---------- offline queue flush before quitting (electron stubbed) ---------- */
const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'ny-queue-'));
const load = Module._load;
Module._load = function (req, ...rest) { return req === 'electron' ? { app: { getPath: () => tmp } } : load.call(this, req, ...rest); };
const { Queue } = require(path.join(root, 'src', 'main', 'queue.js'));
Module._load = load;

async function queueChecks() {
  let calls = 0; const sent = [];
  const api = { request: async (m, p, o) => { calls++; if (calls === 2) { const e = new Error('offline'); e.retryable = true; e.offline = true; throw e; } sent.push(o.json.n); return { ok: true }; } };
  const q = new Queue(api);
  q.paused = true;
  for (let i = 1; i <= 3; i++) q.add({ type: 'action', path: 'pause', json: { n: i } });
  const progress = [];
  const t0 = Date.now();
  const r = await q.flush(10000, left => progress.push(left));
  eq([r.done, r.left, sent], [true, 0, [1, 2, 3]], 'flush sends everything, in order, after a failed try');
  ok(Date.now() - t0 < 4500, `retries quickly while quitting (${Date.now() - t0} ms)`);
  ok(progress.includes(0) && progress[0] >= 1, 'progress reported: ' + JSON.stringify(progress));
  eq(JSON.parse(fs.readFileSync(path.join(tmp, 'queue.json'), 'utf8')), [], 'empty queue persisted');
  const never = { request: async () => { const e = new Error('offline'); e.retryable = true; e.offline = true; throw e; } };
  const q2 = new Queue(never);
  q2.paused = true;
  q2.add({ type: 'heartbeat', path: 'heartbeat', json: { active_seconds: 5, idle_seconds: 0 } });
  setTimeout(() => q2.abortFlush(), 300);
  const r2 = await q2.flush(10000);
  eq([r2.done, r2.left], [false, 1], 'Quit anyway: stops waiting, the data stays queued');
  eq(JSON.parse(fs.readFileSync(path.join(tmp, 'queue.json'), 'utf8')).length, 1, 'kept on disk for the next start');
  const r3 = await q2.flush(500);
  eq(r3.done, false, 'gives up after the timeout');
  q2.paused = true; clearTimeout(q2.timer);
  eq(new Queue(never).size, 1, 'a new start finds the queued item');
  fs.rmSync(tmp, { recursive: true, force: true }); }

/* ---------- tracking dot ---------- */
const d = badge.dot(32, 1);
const px = (b, w, x, y) => Array.from(b.slice((y * w + x) * 4, (y * w + x) * 4 + 4));
eq(px(d, 32, 16, 16), [0xA0, 0xB5, 0x12, 255], 'dot centre is brand mint (BGRA)');
eq(px(d, 32, 0, 0), [0, 0, 0, 0], 'corner transparent');
ok(px(d, 32, 16, 0)[3] > 0 && px(d, 32, 16, 0)[3] < 255 && px(d, 32, 16, 1)[2] > 200, 'anti-aliased white ring');
eq(px(badge.dot(32, 1, badge.COLORS.off), 32, 16, 16), [0x44, 0x44, 0xEF, 255], 'not tracking: red dot (BGRA)');
ok(badge.COLORS.on === badge.MINT && badge.FRAMES === undefined && badge.PULSE_MS === undefined, 'steady dot: green on, red off, no blinking frames');
const icon = Buffer.alloc(100 * 100 * 4, 0x40);
const st = badge.onIcon(icon, 100, 100);
eq(px(st, 100, 10, 10), [0x40, 0x40, 0x40, 0x40], 'icon untouched away from the dot');
eq(px(st, 100, 79, 79), [0xA0, 0xB5, 0x12, 255], 'dot in the lower right corner');

{ const appJs = require('fs').readFileSync(path.join(root, 'src', 'renderer', 'app.js'), 'utf8');
  ok(/function revealNotices\(\)[\s\S]{0,600}closeSheet\(\)/.test(appJs) && /setHtml\('update-slot', updateCard\(\)\);\s*revealNotices\(\);/.test(appJs), 'a new notice closes the Settings drawer so it is seen'); }

/* ---------- activity from the system idle time, the same on every platform ---------- */
/*
 * The real Activity class (src/main/activity.js) with powerMonitor stubbed: getSystemIdleTime() (whole seconds) driven by a
 * fake input timeline, sampled every ~1 s like tracker.tick and fed to meter.tick exactly like tracker.slotTick. Windows,
 * macOS and Linux give the same result; nothing is counted and no input hook is ever required.
 */
function idleActivityChecks() {
  let clock = 0, lastInput = -Infinity, locked = false, hookLoaded = false;
  const stub = { powerMonitor: { getSystemIdleTime: () => Math.floor(Math.max(0, clock - lastInput) / 1000), getSystemIdleState: () => (locked ? 'locked' : 'active') } };
  const plat = Object.getOwnPropertyDescriptor(process, 'platform');
  const load2 = Module._load;
  Module._load = function (req, ...rest) { if (req === 'electron') return stub; if (/uiohook/.test(req)) { hookLoaded = true; throw new Error('must not load'); } return load2.call(this, req, ...rest); };
  const actPath = path.join(root, 'src', 'main', 'activity.js');
  const T0 = Date.UTC(2026, 10, 7, 9, 0, 0);
  let seed = 11; const rnd = () => { seed = (seed * 1103515245 + 12345) % 2147483648; return seed / 2147483648; };
  const sparse = sec => { const ev = []; for (let t = T0 + rnd() * sec * 1000; t < T0 + 3600e3; t += (sec + (rnd() - 0.5) * 10) * 1000) ev.push(Math.round(t)); return ev; };
  const bursts = () => { const ev = []; let t = T0 + 300; while (t < T0 + 3600e3) { const end = t + (8 + rnd() * 32) * 1000; for (; t < end; t += 150 + rnd() * 450) ev.push(Math.round(t)); const x = rnd(); t += (x < 0.4 ? 1 + rnd() * 4 : x < 0.8 ? 5 + rnd() * 10 : 15 + rnd() * 25) * 1000; } return ev; };
  const patterns = [['focused work', bursts(), [88, 96]], ['reading (a scroll every 15-25 s)', sparse(20), [58, 72]], ['one input a minute', sparse(60), [14, 26]], ['away', [], [0, 0]]];
  const results = {};
  let counted = false;
  try {
    for (const os of ['win32', 'darwin', 'linux']) {
      Object.defineProperty(process, 'platform', { value: os });
      delete require.cache[actPath];
      const { Activity } = require(actPath);
      for (const [name, ev, range] of patterns) {
        const logs = [];
        const a = new Activity(m => logs.push(m)); a.start();
        let i = 0, prev = T0, sl = null, tracked = 0, active = 0; lastInput = -Infinity;
        const close = () => { if (sl) { meter.finish(sl); tracked += Math.round(sl.tracked); active += Math.min(Math.round(sl.tracked), Math.round(sl.active)); } sl = null; };
        for (let now = T0 + 1000; now <= T0 + 3600e3; now += 1000 + Math.round((rnd() - 0.5) * 80)) {
          while (i < ev.length && ev[i] <= now) lastInput = ev[i++];
          clock = now;
          const dt = (now - prev) / 1000; prev = now;
          const start = now - 500 - ((now - 500 - T0) % 180000);
          if (sl && sl.start !== start) close();
          if (!sl) sl = meter.slot(start, 180);
          const x = a.sample(now);
          if ('keys' in x || 'mouse' in x) counted = true;
          meter.tick(sl, now, dt, x.last, { input: x.input, away: x.locked }); }
        close();
        const pct = tracked ? Math.round(1000 * active / tracked) / 10 : 0;
        ok(pct >= range[0] && pct <= range[1], `${os} ${name}: ${pct}% not in ${range.join('-')}%`);
        if (results[name] !== undefined) ok(Math.abs(results[name] - pct) <= 2, `${os} ${name}: ${pct}% differs from ${results[name]}%`); else results[name] = pct;
        eq(a.source, 'idle-time', 'source');
        ok(/system idle time/.test(logs[0] || '') && (os !== 'win32' || /GetLastInputInfo/.test(logs[0])), 'source logged: ' + logs[0]); } }
    for (const [name] of patterns) console.log(`  activity from the system idle time (Windows, macOS, Linux), ${name}: ${results[name]}%`);
    /* a locked screen is away: input then (e.g. a bumped mouse) is not activity */
    const { Activity } = require(actPath);
    const a = new Activity(); a.start();
    locked = true; clock = T0 + 5000; lastInput = T0 + 4800;
    const x = a.sample(clock);
    ok(x.locked && !x.input, 'locked screen: no input');
    locked = false;
    ok(!counted, 'nothing is counted (no keystroke or mouse numbers)');
    ok(!hookLoaded, 'no input hook is ever loaded'); }
  finally { Module._load = load2; Object.defineProperty(process, 'platform', plat); delete require.cache[actPath]; } }
idleActivityChecks();

/* ---------- no input hook in any build ---------- */
const pkg = JSON.parse(fs.readFileSync(path.join(root, 'package.json'), 'utf8'));
const lock = fs.readFileSync(path.join(root, 'package-lock.json'), 'utf8');
ok(!(pkg.dependencies || {})['uiohook-napi'] && !/uiohook/.test(lock) && !/uiohook/.test(JSON.stringify(pkg.build)), 'uiohook-napi is gone from package.json, the lockfile and the build config');
ok(!/uiohook|isTrustedAccessibilityClient/.test(fs.readFileSync(path.join(root, 'src', 'main', 'activity.js'), 'utf8')), 'activity.js has no hook and asks for no Accessibility permission');
eq(pkg.build.win.requestedExecutionLevel, 'asInvoker', 'no elevation requested');
eq(pkg.build.nsis.perMachine, false, 'per-user install');

queueChecks().catch(e => { bad++; console.error('FAIL queue: ' + (e && e.stack || e)); }).then(() => {
  console.log(`status, meeting, quit sync, indicator, activity source and build checks: ${n - bad}/${n} OK`);
  process.exit(bad ? 1 : 0); });
