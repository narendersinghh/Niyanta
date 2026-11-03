'use strict';
/*
 * Pomodoro break reminder (local to this device, nothing is sent to the server). Pure (no Electron), used by tracker.js and
 * scripts/simulate.js. Settings {on, work (minutes), brk (minutes)} live in the app store under "pomodoro"; off by default.
 * - Work counts the seconds tracked while not idle since the last break, pause or resume. Pausing (by hand, by the idle
 *   auto-pause or by locking the screen), resuming, an idle stretch or a sleep at least as long as the break all reset it.
 * - After `work` minutes: "due" (OS notification + banner "Time for a N-minute break": Take a break / Snooze 10 min).
 * - Take a break: the tracker pauses and a countdown of `brk` minutes runs; at its end "over" ("Break over", Resume). Tracking
 *   never resumes by itself. Snooze: due again after SNOOZE_MIN minutes of tracking.
 */
const { EventEmitter } = require('events');

const SNOOZE_MIN = 10;
const DEFAULTS = { on: false, work: 120, brk: 5 };
const LIMITS = { work: [1, 600], brk: [1, 120] };

const int = (v, [lo, hi], def) => { v = Math.round(Number(v)); return Number.isFinite(v) ? Math.min(hi, Math.max(lo, v)) : def; };
function normalize(p) { p = p && typeof p === 'object' ? p : {}; return { on: !!p.on, work: int(p.work, LIMITS.work, DEFAULTS.work), brk: int(p.brk, LIMITS.brk, DEFAULTS.brk) }; }

class Pomodoro extends EventEmitter {
  constructor(prefs) {
    super();
    this.prefs = normalize(prefs);
    this.work = 0; this.due = false; this.snoozeUntil = 0; this.brk = null; this.over = false; }

  /** New settings (Save): the reminder is evaluated again against them on the next tick. */
  configure(prefs) {
    this.prefs = normalize(prefs);
    this.due = false; this.snoozeUntil = 0;
    if (!this.prefs.on) { this.brk = null; this.over = false; }
    return this.prefs; }

  /** One tick: `worked` = seconds tracked and not idle since the previous tick (0 while paused or idle). */
  tick(now, worked) {
    if (this.brk && now >= this.brk.until) { const minutes = this.brk.minutes; this.brk = null; this.over = true; this.emit('over', minutes); }
    if (!this.prefs.on || !(worked > 0)) return;
    this.work += worked;
    if (this.due || (this.snoozeUntil && now < this.snoozeUntil)) return;
    if (this.snoozeUntil || this.work >= this.prefs.work * 60) { this.snoozeUntil = 0; this.due = true; this.emit('due', this.prefs.brk); } }

  /** Work counter back to zero (pause, long idle, sleep). A running break countdown continues. */
  rest() { this.work = 0; this.due = false; this.snoozeUntil = 0; }

  /** Tracking (re)started: new work period, any break is over. */
  resumed() { this.rest(); this.brk = null; this.over = false; }

  takeBreak(now) { this.rest(); this.brk = { until: now + this.prefs.brk * 60000, minutes: this.prefs.brk }; this.over = false; }

  snooze(now) { this.due = false; this.snoozeUntil = now + SNOOZE_MIN * 60000; }

  dismissOver() { this.over = false; }

  view() { return Object.assign({}, this.prefs, { due: this.due, worked: Math.floor(this.work), breakUntil: this.brk ? this.brk.until : null, breakMinutes: this.brk ? this.brk.minutes : null, over: this.over, snooze: SNOOZE_MIN }); } }

module.exports = { Pomodoro, normalize, DEFAULTS, LIMITS, SNOOZE_MIN };
