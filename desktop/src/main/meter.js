'use strict';
/*
 * Activity calibration: how input turns into active seconds. Pure (no Electron), shared by the tracker and scripts/simulate.js.
 * Counting only the exact seconds that had input reads 75-80% for focused work (reading, thinking, the pauses between typing),
 * where other trackers show ~90%. So time is measured in short windows instead:
 *   - the tracked seconds of a slot are grouped in WINDOW_S-second windows (aligned to the slot start, so to the workspace's
 *     midnight; the last window of a slot can be shorter);
 *   - a window is fully active when there was keyboard or mouse input in it, or when the last input before it was less than
 *     GRACE_S seconds before it began (input "carries" activity for a short grace period);
 *   - otherwise all its seconds are inactive.
 * Both activity sources feed the same rule: the input hook gives the time of the last event (ms), the fallback gives
 * now - powerMonitor.getSystemIdleTime(). Paused time is never added (the tracker only adds tracked seconds), and idle
 * detection, auto-pause and the keystroke / mouse counts do not depend on this.
 * scripts/simulate.js (old per-second rule -> this rule): steady typing 93 -> 100%, focused work with short pauses 74 -> 94%,
 * mixed typing / reading / thinking 54 -> 88%, a scroll every 15 to 25 s 5 -> 68%, one input every 30 s 3 -> 39%, one input
 * per minute 2 -> 20%, away 0%. A larger GRACE_S raises the sparse patterns most (5 s: reading 79%, once a minute 25%).
 */

/** Length of an activity window in seconds. */
const WINDOW_S = 10;
/** Seconds after the last input that still count the next window as active. */
const GRACE_S = 3;

/**
 * Add one tick to slot `s` ({start (ms), tracked, active, w, pend, on}): `dt` tracked seconds ending at `now` (ms), `last` =
 * time (ms) of the latest input seen so far. Seconds of a window that has no input yet wait in `pend` and become active as
 * soon as input arrives in the same window; they are dropped (inactive) when the window ends without input.
 * Call it after s.tracked was increased by dt.
 */
function credit(s, now, dt, last) {
  const w = Math.floor(Math.max(0, now - 500 - s.start) / (WINDOW_S * 1000));
  if (s.w !== w) { s.w = w; s.pend = 0; s.on = false; }
  if (!s.on && Number.isFinite(last) && last > s.start + w * WINDOW_S * 1000 - GRACE_S * 1000) { s.on = true; s.active += s.pend; s.pend = 0; }
  if (s.on) s.active += dt; else s.pend += dt;
  s.active = Math.min(s.active, s.tracked); }

module.exports = { WINDOW_S, GRACE_S, credit };
