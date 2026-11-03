'use strict';
/*
 * Deterministic simulation of the activity calibration (src/main/meter.js) and the pomodoro reminder (src/main/pomodoro.js)
 * on fake input timelines (seeded random, ticks of ~1 s with jitter, like the tracker's setInterval). For each pattern it
 * prints the activity % of the old rule (a second is active only with input in it), the new rule with the input hook (last
 * event in ms) and the new rule with the fallback (getSystemIdleTime, whole seconds), and fails (exit 1) when a result is out
 * of its expected range. Run: node scripts/simulate.js (also run by npm run check).
 */
const path = require('path');
const meter = require(path.join(__dirname, '..', 'src', 'main', 'meter.js'));
const { Pomodoro } = require(path.join(__dirname, '..', 'src', 'main', 'pomodoro.js'));

function rng(seed) { return () => { seed = (seed + 0x6D2B79F5) | 0; let t = Math.imul(seed ^ (seed >>> 15), 1 | seed); t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t; return ((t ^ (t >>> 14)) >>> 0) / 4294967296; }; }
const between = (r, a, b) => a + r() * (b - a);
const HOUR = 3600000;
const T0 = Date.UTC(2026, 10, 3, 9, 0, 0);

/* ---------- input timelines (ms of every input event) ---------- */

/** Alternating bursts of input (one event every `gap` ms) and pauses picked by `pause()` (seconds). */
function bursts(r, ms, burst, gap, pause) {
  const ev = []; let t = T0 + 200;
  while (t < T0 + ms) {
    const end = t + burst() * 1000;
    for (; t < end && t < T0 + ms; t += between(r, gap[0], gap[1])) ev.push(Math.round(t));
    t += pause() * 1000; }
  return ev; }

/** One event roughly every `sec` seconds (± jitter). */
function sparse(r, ms, sec, jitter) { const ev = []; for (let t = T0 + between(r, 0, sec * 1000); t < T0 + ms; t += (sec + between(r, -jitter, jitter)) * 1000) ev.push(Math.round(t)); return ev; }

const PATTERNS = [
  { name: 'Steady typing (bursts 20-60 s, pauses 2-6 s)', expect: [95, 100], ev: r => bursts(r, HOUR, () => between(r, 20, 60), [120, 450], () => between(r, 2, 6)) },
  { name: 'Focused work: typing with short pauses (1-40 s)', expect: [85, 95], ev: r => bursts(r, HOUR, () => between(r, 8, 40), [150, 600], () => { const x = r(); return x < 0.4 ? between(r, 1, 5) : x < 0.8 ? between(r, 5, 15) : between(r, 15, 40); }) },
  { name: 'Mixed work: typing, reading, thinking (pauses 2-40 s)', expect: [80, 95], ev: r => bursts(r, HOUR, () => between(r, 3, 20), [150, 700], () => { const x = r(); return x < 0.6 ? between(r, 2, 8) : x < 0.9 ? between(r, 8, 20) : between(r, 20, 40); }) },
  { name: 'Mouse work: clicks and moves every 1-12 s', expect: [85, 100], ev: r => bursts(r, HOUR, () => between(r, 0.2, 2), [250, 400], () => between(r, 1, 12)) },
  { name: 'Reading: a scroll every 15-25 s', expect: [45, 75], ev: r => sparse(r, HOUR, 20, 5) },
  { name: 'One input every 30 s', expect: [25, 50], ev: r => sparse(r, HOUR, 30, 3) },
  { name: 'One input per minute', expect: [10, 30], ev: r => sparse(r, HOUR, 60, 5) },
  { name: 'Away (no input)', expect: [0, 0], ev: () => [] }];

/* ---------- the tracker's slot loop, reduced to activity ---------- */

/**
 * Ticks every ~1 s for `ms`; returns {old, hook, idle} activity %. `paused(t)` excludes seconds (not tracked). Slots of
 * `slotS` seconds are closed like tracker.closeSlot (pending seconds of an unfinished window are dropped).
 */
function run(events, ms, { slotS = 600, paused = () => false, seed = 7 } = {}) {
  const r = rng(seed);
  const res = {};
  for (const mode of ['old', 'hook', 'idle']) {
    let i = 0, last = -Infinity, prev = T0, slot = null, tracked = 0, active = 0;
    const close = () => { if (slot) { tracked += Math.min(slot.len, Math.round(slot.tracked)); active += Math.min(Math.round(slot.tracked), Math.round(slot.active)); } slot = null; };
    for (let now = T0 + 1000; now <= T0 + ms; now += Math.round(1000 + between(r, -40, 40))) {
      let seen = false;
      while (i < events.length && events[i] <= now) { last = events[i++]; seen = true; }
      const dt = (now - prev) / 1000; prev = now;
      const start = now - 500 - ((now - 500 - T0) % (slotS * 1000));
      if (slot && slot.start !== start) close();
      if (paused(now)) continue;
      if (!slot) slot = { start, len: slotS, tracked: 0, active: 0 };
      slot.tracked = Math.min(slot.len, slot.tracked + dt);
      if (mode === 'old') { if (seen) slot.active = Math.min(slot.tracked, slot.active + dt); }
      else if (mode === 'hook') meter.credit(slot, now, dt, last);
      else { const sys = Math.floor(Math.max(0, now - last) / 1000); meter.credit(slot, now, dt, now - sys * 1000 - 500); } }
    close();
    res[mode] = tracked ? Math.round(1000 * active / tracked) / 10 : 0;
    res.tracked = tracked; }
  return res; }

let failed = 0;
const check = (ok, msg) => { if (!ok) { failed++; console.error('FAIL ' + msg); } };

console.log(`Activity windows: ${meter.WINDOW_S} s, grace ${meter.GRACE_S} s (src/main/meter.js)\n`);
console.log('pattern'.padEnd(58) + 'old/sec  hook  fallback  expected');
for (const [k, p] of PATTERNS.entries()) {
  const ev = p.ev(rng(100 + k));
  const x = run(ev, HOUR);
  console.log(p.name.padEnd(58) + `${String(x.old).padStart(6)}% ${String(x.hook).padStart(5)}% ${String(x.idle).padStart(7)}%   ${p.expect[0]}-${p.expect[1]}%`);
  for (const m of ['hook', 'idle']) check(x[m] >= p.expect[0] && x[m] <= p.expect[1], `${p.name}: ${m} ${x[m]}% not in ${p.expect.join('-')}%`);
  check(x.hook >= x.old, `${p.name}: new rule below the old one`); }

/* paused time is excluded: 20 min paused in the middle of an hour of focused work, no input while paused */
{
  const focus = PATTERNS[1];
  const pausedAt = t => t > T0 + 20 * 60000 && t <= T0 + 40 * 60000;
  const ev = focus.ev(rng(101)).filter(t => !pausedAt(t));
  const x = run(ev, HOUR, { paused: pausedAt });
  console.log(`\nFocused work with 20 min paused: tracked ${x.tracked} s, hook ${x.hook}%, fallback ${x.idle}%`);
  check(Math.abs(x.tracked - 2400) <= 5, `paused time counted: tracked ${x.tracked} s`);
  check(x.hook >= 85 && x.idle >= 85, 'paused time lowered the activity %'); }

/* slot lengths that are not a multiple of the window (short last window) */
for (const slotS of [45, 180, 300]) {
  const x = run(PATTERNS[1].ev(rng(101)), HOUR, { slotS });
  console.log(`Focused work, ${slotS} s slots: hook ${x.hook}%, fallback ${x.idle}%`);
  check(x.hook >= 85 && x.hook <= 95, `${slotS} s slots: ${x.hook}%`); }

/* a window gets activity from input inside it or within the grace before it, never from older input */
{
  const s = { start: T0, tracked: 0, active: 0 };
  const step = (sec, last) => { s.tracked += 1; meter.credit(s, T0 + sec * 1000, 1, last); };
  for (let sec = 1; sec <= 10; sec++) step(sec, T0 - 60000);
  check(s.active === 0, 'window without input counted');
  for (let sec = 11; sec <= 20; sec++) step(sec, sec >= 18 ? T0 + 17500 : T0 - 60000);
  check(s.active === 10, `input late in a window: ${s.active} of 10 s`);
  for (let sec = 21; sec <= 30; sec++) step(sec, T0 + 17500);
  check(s.active === 20, `grace into the next window: ${s.active} of 20 s`);
  for (let sec = 31; sec <= 40; sec++) step(sec, T0 + 17500);
  check(s.active === 20, 'grace carried more than one window'); }

/* ---------- pomodoro ---------- */
{
  const p = new Pomodoro({ on: true, work: 120, brk: 5 });
  const events = [];
  p.on('due', m => events.push(['due', m])); p.on('over', m => events.push(['over', m]));
  let t = T0;
  const work = sec => { for (let i = 0; i < sec; i++) { t += 1000; p.tick(t, 1); } };
  const wait = sec => { for (let i = 0; i < sec; i++) { t += 1000; p.tick(t, 0); } };
  work(119 * 60);
  check(!p.due && !events.length, 'pomodoro fired early');
  work(60);
  check(p.due && events.length === 1 && events[0][1] === 5, 'pomodoro did not fire after 120 min');
  p.snooze(t); work(9 * 60);
  check(!p.due, 'snooze shorter than 10 min');
  work(61);
  check(p.due && events.length === 2, 'snooze did not fire again after 10 min');
  p.takeBreak(t); p.rest(); wait(4 * 60);
  check(!p.over, 'break over too early');
  wait(61);
  check(p.over && events[2] && events[2][0] === 'over', 'break over not signalled');
  p.resumed(); work(60 * 60); p.rest(); work(70 * 60);
  check(!p.due, 'manual pause did not reset the work counter');
  const off = new Pomodoro({}); for (let i = 0; i < 200 * 60; i++) off.tick(T0 + i * 1000, 1);
  check(!off.due && off.prefs.on === false && off.prefs.work === 120 && off.prefs.brk === 5, 'pomodoro defaults (off, 120, 5)');
  console.log('\nPomodoro: due after 120 min, snooze 10 min, break countdown, pause resets: ' + (failed ? 'see failures' : 'ok')); }

console.log(failed ? `\n${failed} check(s) failed` : '\nAll simulation checks passed');
process.exit(failed ? 1 : 0);
