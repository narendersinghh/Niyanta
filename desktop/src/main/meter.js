'use strict';
/*
 * Activity calibration: how keyboard and mouse input turns into active seconds. Pure (no Electron), shared by the tracker and
 * scripts/simulate.js / scripts/status-check.js. The only input is the time of the last keyboard or mouse input, read once per
 * second from the system (powerMonitor.getSystemIdleTime(): GetLastInputInfo on Windows, IOHIDSystem idle time on macOS, the
 * X11 / logind idle time on Linux), on every platform. Nothing is counted: no keystrokes, no mouse events, never what is typed.
 *
 * Rules (constants below):
 *   - the tracked seconds of a slot are grouped in BLOCK_S-second blocks, aligned to the slot start (so to the workspace's
 *     midnight; the last block of a slot can be shorter);
 *   - a block is active when there was input in it, or when the last input before it was less than GRACE_S seconds before
 *     it began (input "carries" a little into the next block); otherwise its seconds are inactive;
 *   - bridge: a single inactive block between two "busy" active blocks (input in at least BUSY_S different seconds each)
 *     counts as active: a short thinking pause in the middle of steady work. Two inactive blocks in a row are never bridged,
 *     and sparse input (a scroll now and then) is not busy, so reading stays lower;
 *   - away: while the screen is locked, the display sleeps or the system is suspended (the tracker passes away=true) the
 *     block is inactive at once, nothing is bridged across it and input from before it carries no grace;
 *   - wake-up: the first block after resuming from sleep is left out of the slot entirely (IGNORE_AFTER_WAKE_BLOCKS), so the
 *     touch that wakes the computer neither counts as work nor drags the activity down;
 *   - paused time is never added (the tracker only feeds tracked seconds); idle detection and auto-pause do not depend on this.
 * scripts/simulate.js prints the result for typical input patterns (focused work ~90%, reading with a scroll every 15-25 s
 * ~65%, one input a minute ~20%, away 0%).
 */

/** Length of an activity block in seconds. */
const BLOCK_S = 12;
/** Seconds after the last input that still make the next block active. */
const GRACE_S = 1;
/** A block is "busy" (may bridge a pause) with input in at least this many different seconds. */
const BUSY_S = 4;
/** At most this many inactive blocks in a row are bridged between two busy blocks. */
const BRIDGE_BLOCKS = 1;
/** Blocks left out of the slot after a resume from sleep. */
const IGNORE_AFTER_WAKE_BLOCKS = 1;

/** Fresh slot state: {start (ms), len (s), tracked, active, meet} plus the meter's block state. */
function slot(start, len) { return { start, len, tracked: 0, active: 0, meet: 0, w: null, pend: 0, on: false, hits: 0, seen: -Infinity, floor: -Infinity, bridge: 0, prevBusy: false, ignoreUntil: 0 }; }

/** The block that just ended: bridge a pending pause when this block is busy too, and remember what this block was. */
function endBlock(s) {
  if (s.w === null) return;
  const busy = s.on && s.hits >= BUSY_S;
  if (s.on) {
    if (s.bridge && busy) s.active += s.bridge;
    s.bridge = 0; s.gap = 0; s.prevBusy = busy; }
  else {
    s.gap = (s.gap || 0) + 1;
    if (s.prevBusy && s.gap <= BRIDGE_BLOCKS && !s.away) s.bridge += s.pend; else { s.bridge = 0; s.prevBusy = false; } }
  s.pend = 0; s.away = false; }

/**
 * One tick: `dt` tracked seconds ending at `now` (ms); `last` = ms of the latest input seen so far; flags.input = there was
 * input during the last second (system idle time 0; counts the busy seconds of a block); flags.away = screen locked, display
 * asleep or suspended. Updates s.tracked and s.active and returns the seconds added to the slot (0 when the tick falls
 * in the ignored block after a wake-up), so the caller can add the same seconds to s.meet.
 */
function tick(s, now, dt, last, flags = {}) {
  if (!dt) return 0;
  if (now <= s.ignoreUntil) return 0;
  const w = Math.floor(Math.max(0, now - 500 - s.start) / (BLOCK_S * 1000));
  if (s.w !== w) { endBlock(s); s.w = w; s.on = false; s.hits = 0; }
  const from = s.start + w * BLOCK_S * 1000;
  if (flags.away) { s.away = true; s.on = false; s.pend = 0; s.bridge = 0; s.prevBusy = false; s.floor = now; }
  else if (Number.isFinite(last) && last > s.floor) {
    const fresh = flags.input !== undefined ? !!flags.input : last > s.seen + 1500;
    if (fresh && last >= from - 1000) s.hits++;
    if (!s.on && last > from - GRACE_S * 1000) { s.on = true; s.active += s.pend; s.pend = 0; } }
  if (Number.isFinite(last)) s.seen = Math.max(s.seen, last);
  s.tracked = Math.min(s.len, s.tracked + dt);
  if (s.on) s.active += dt; else s.pend += dt;
  s.active = Math.min(s.active, s.tracked);
  return dt; }

/** Close the slot: settle the last block (a pending bridge is dropped, an unfinished inactive block stays inactive). */
function finish(s) { endBlock(s); s.bridge = 0; s.active = Math.min(s.active, s.tracked); return s; }

/** After a resume from sleep: leave the next IGNORE_AFTER_WAKE_BLOCKS block(s) of this slot out; no grace or bridge across the sleep. */
function wake(s, now) {
  if (!s) return;
  const w = Math.floor(Math.max(0, now - s.start) / (BLOCK_S * 1000));
  s.ignoreUntil = s.start + (w + IGNORE_AFTER_WAKE_BLOCKS) * BLOCK_S * 1000 + 500;
  s.floor = now; s.bridge = 0; s.prevBusy = false; s.pend = 0; s.on = false; }

/**
 * Meeting mode: while it is on, the tracker adds the tracked seconds of the slot to s.meet as well. A slot counts as a meeting
 * slot (sent with meeting: 1, kept for managers but left out of every activity average on the server) when meeting mode
 * covered at least half of its tracked seconds, so a meeting that starts or ends inside a slot flags the slot it mostly fills.
 */
function meetingSlot(s) { return !!s && (+s.meet || 0) > 0 && 2 * (+s.meet || 0) >= (+s.tracked || 0); }

module.exports = { BLOCK_S, GRACE_S, BUSY_S, BRIDGE_BLOCKS, IGNORE_AFTER_WAKE_BLOCKS, slot, tick, finish, wake, meetingSlot };
