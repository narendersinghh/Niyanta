'use strict';
/*
 * Deterministic simulation of the activity calibration (src/main/meter.js) and the pomodoro reminder (src/main/pomodoro.js)
 * on fake input timelines (seeded random, ticks of ~1 s with jitter, like the tracker's setInterval). For each pattern it
 * prints the activity % of the old rule (a second is active only with input in it) and of meter.js fed with the system idle
 * time (whole seconds, as powerMonitor.getSystemIdleTime() gives it), and fails (exit 1) when a result is out of its range.
 * Run: node scripts/simulate.js (also run by npm run check).
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
  { name: 'Focused work: typing with short pauses (1-40 s)', expect: [88, 95], ev: r => bursts(r, HOUR, () => between(r, 8, 40), [150, 600], () => { const x = r(); return x < 0.4 ? between(r, 1, 5) : x < 0.8 ? between(r, 5, 15) : between(r, 15, 40); }) },
  { name: 'Mixed work: typing, reading, thinking (pauses 2-40 s)', expect: [80, 95], ev: r => bursts(r, HOUR, () => between(r, 3, 20), [150, 700], () => { const x = r(); return x < 0.6 ? between(r, 2, 8) : x < 0.9 ? between(r, 8, 20) : between(r, 20, 40); }) },
  { name: 'Mouse work: clicks and moves every 1-12 s', expect: [85, 100], ev: r => bursts(r, HOUR, () => between(r, 0.2, 2), [250, 400], () => between(r, 1, 12)) },
  { name: 'Reading: a scroll every 15-25 s', expect: [60, 70], ev: r => sparse(r, HOUR, 20, 5) },
  { name: 'One input every 30 s', expect: [25, 50], ev: r => sparse(r, HOUR, 30, 3) },
  { name: 'One input per minute', expect: [15, 25], ev: r => sparse(r, HOUR, 60, 5) },
  { name: 'Away (no input)', expect: [0, 0], ev: () => [] }];

/* ---------- the tracker's slot loop, reduced to activity ---------- */

/**
 * Ticks every ~1 s for `ms`; returns {old, now: activity %, tracked}. "old" counts only the seconds that had input; "now" is
 * meter.js fed like tracker.slotTick with the system idle time (whole seconds, as powerMonitor.getSystemIdleTime() gives it).
 * `paused(t)` excludes seconds (not tracked), `away(t)` = screen locked / asleep, `wakeAt` = a resume from sleep. Slots of
 * `slotS` seconds are closed like tracker.closeSlot (meter.finish).
 */
function run(events, ms, { slotS = 600, paused = () => false, away = () => false, wakeAt = [], seed = 7 } = {}) {
  const r = rng(seed);
  const res = {};
  for (const mode of ['old', 'now']) {
    let i = 0, last = -Infinity, prev = T0, slot = null, tracked = 0, active = 0, wi = 0;
    const close = () => { if (slot) { meter.finish(slot); tracked += Math.min(slot.len, Math.round(slot.tracked)); active += Math.min(Math.round(slot.tracked), Math.round(slot.active)); } slot = null; };
    for (let now = T0 + 1000; now <= T0 + ms; now += Math.round(1000 + between(r, -40, 40))) {
      let seen = false;
      while (i < events.length && events[i] <= now) { last = events[i++]; seen = true; }
      const dt = (now - prev) / 1000; prev = now;
      const start = now - 500 - ((now - 500 - T0) % (slotS * 1000));
      if (slot && slot.start !== start) close();
      if (paused(now)) continue;
      if (!slot) slot = meter.slot(start, slotS);
      if (wi < wakeAt.length && now >= wakeAt[wi]) { meter.wake(slot, now); wi++; }
      if (mode === 'old') { slot.tracked = Math.min(slot.len, slot.tracked + dt); if (seen && !away(now)) slot.active = Math.min(slot.tracked, slot.active + dt); }
      else { const sys = Math.floor(Math.max(0, now - last) / 1000); meter.tick(slot, now, dt, now - sys * 1000 - 500, { away: away(now), input: sys === 0 }); } }
    close();
    res[mode] = tracked ? Math.round(1000 * active / tracked) / 10 : 0;
    res.tracked = tracked; }
  return res; }

let failed = 0;
const check = (ok, msg) => { if (!ok) { failed++; console.error('FAIL ' + msg); } };

console.log(`Activity blocks: ${meter.BLOCK_S} s, grace ${meter.GRACE_S} s, bridge ${meter.BRIDGE_BLOCKS} block between busy blocks (input in ${meter.BUSY_S}+ seconds), first ${meter.IGNORE_AFTER_WAKE_BLOCKS} block after waking ignored (src/main/meter.js)\n`);
console.log('pattern'.padEnd(58) + 'old/sec   now   expected');
for (const [k, p] of PATTERNS.entries()) {
  const ev = p.ev(rng(100 + k));
  const x = run(ev, HOUR);
  console.log(p.name.padEnd(58) + `${String(x.old).padStart(6)}% ${String(x.now).padStart(5)}%   ${p.expect[0]}-${p.expect[1]}%`);
  check(x.now >= p.expect[0] && x.now <= p.expect[1], `${p.name}: ${x.now}% not in ${p.expect.join('-')}%`);
  check(x.now >= x.old, `${p.name}: new rule below the old one`); }

/* paused time is excluded: 20 min paused in the middle of an hour of focused work, no input while paused */
{
  const focus = PATTERNS[1];
  const pausedAt = t => t > T0 + 20 * 60000 && t <= T0 + 40 * 60000;
  const ev = focus.ev(rng(101)).filter(t => !pausedAt(t));
  const x = run(ev, HOUR, { paused: pausedAt });
  console.log(`\nFocused work with 20 min paused: tracked ${x.tracked} s, activity ${x.now}%`);
  check(Math.abs(x.tracked - 2400) <= 5, `paused time counted: tracked ${x.tracked} s`);
  check(x.now >= 85, 'paused time lowered the activity %'); }

/* screen locked for 10 minutes while input keeps arriving (e.g. a mouse bumped): inactive at once */
{
  const lock = t => t > T0 + 20 * 60000 && t <= T0 + 30 * 60000;
  const ev = PATTERNS[0].ev(rng(100));
  const x = run(ev, HOUR, { away: lock });
  console.log(`Steady typing with 10 min locked screen: ${x.now}% (expected about 5/6 of 100%)`);
  check(x.now >= 80 && x.now <= 86, `locked screen counted as active: ${x.now}%`); }

/* slot lengths that are not a multiple of the block (short last block) */
for (const slotS of [45, 180, 300]) {
  const x = run(PATTERNS[1].ev(rng(101)), HOUR, { slotS });
  console.log(`Focused work, ${slotS} s slots: ${x.now}%`);
  check(x.now >= 85 && x.now <= 96, `${slotS} s slots: ${x.now}%`); }

/* block rules, step by step (1 tick per second, slot from T0) */
{
  const B = meter.BLOCK_S;
  let s = meter.slot(T0, 600);
  const step = (sec, last, f) => meter.tick(s, T0 + sec * 1000, 1, last, f);
  for (let sec = 1; sec <= B; sec++) step(sec, T0 - 60000);
  check(s.active === 0, 'block without input counted');
  for (let sec = B + 1; sec <= 2 * B; sec++) step(sec, sec >= 2 * B - 2 ? T0 + (2 * B - 3) * 1000 + 500 : T0 - 60000);
  check(s.active === B, `input late in a block: ${s.active} of ${B} s`);
  for (let sec = 2 * B + 1; sec <= 3 * B; sec++) step(sec, T0 + (2 * B - 1) * 1000 + 500);
  check(s.active === 2 * B, `grace into the next block: ${s.active} of ${2 * B} s`);
  for (let sec = 3 * B + 1; sec <= 4 * B; sec++) step(sec, T0 + (2 * B - 1) * 1000 + 500);
  meter.finish(s);
  check(s.active === 2 * B, 'grace carried more than one block');
  /* bridge: busy, pause, busy -> all active; busy, pause, pause, busy -> pauses inactive; sparse, pause, sparse -> no bridge */
  const pattern = (blocks, busy) => { const t = meter.slot(T0, 3600); let last = -Infinity; blocks.forEach((on, k) => { for (let j = 1; j <= B; j++) { const sec = k * B + j; const inp = !!on && (busy ? j <= 6 : j === 2); if (inp) last = T0 + sec * 1000 - 300; meter.tick(t, T0 + sec * 1000, 1, last, { input: inp }); } }); return meter.finish(t).active; };
  check(pattern([1, 0, 1], true) === 3 * B, 'a short pause between busy blocks is bridged');
  check(pattern([1, 0, 0, 1], true) === 2 * B, 'two inactive blocks are not bridged');
  check(pattern([1, 0, 1], false) === 2 * B, 'sparse input does not bridge a pause');
  /* wake-up: the first block after resuming is left out of the slot */
  s = meter.slot(T0, 600);
  meter.wake(s, T0 + 1000);
  for (let sec = 1; sec <= 3 * B; sec++) step(sec, T0 + sec * 1000 - 300);
  check(s.tracked === 2 * B && s.active === 2 * B, `first block after waking ignored: tracked ${s.tracked}, active ${s.active}`);
  /* away: input during a locked screen does not count, and gives no grace afterwards */
  s = meter.slot(T0, 600);
  for (let sec = 1; sec <= B; sec++) step(sec, T0 + sec * 1000 - 300, { away: true });
  for (let sec = B + 1; sec <= 2 * B; sec++) step(sec, T0 + B * 1000 - 300);
  meter.finish(s);
  check(s.active === 0, `locked screen or its grace counted: ${s.active}`); }

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
