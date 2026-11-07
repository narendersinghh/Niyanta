'use strict';
/*
 * Keyboard and mouse activity, sampled once per second by the tracker, the same way on Windows, macOS and Linux:
 * powerMonitor.getSystemIdleTime() gives the seconds since the last keyboard or mouse input (Windows GetLastInputInfo, macOS
 * IOHIDSystem idle time, Linux X11 / logind idle time). Only whether there was input is used: nothing is counted (no keystrokes,
 * no mouse events) and nothing about what is typed or where. No global input hook, no native module, and on macOS no
 * Accessibility permission (Screen Recording is only needed for screenshots). meter.js turns the samples into active seconds.
 * powerMonitor.getSystemIdleState() also tells when the screen is locked, which counts as away (inactive at once).
 */
const { powerMonitor } = require('electron');

class Activity {
  constructor(log) {
    this.log = log || (() => {});
    this.source = null; }

  /** Kept for the tracker's start/stop calls: there is nothing to start (no hook), only the source is logged once. */
  start() {
    if (this.source) return;
    this.source = 'idle-time';
    this.log(`activity source: keyboard and mouse input from the system idle time${process.platform === 'win32' ? ' (GetLastInputInfo)' : ''}, read every second; nothing is counted`); }

  stop() {}

  /**
   * One sample per tick: {input (input during the last second), last (ms of the last input, middle of its whole second),
   * idle (seconds since the last input), locked (the screen is locked)}.
   */
  sample(now = Date.now()) {
    let sys = 0, locked = false;
    try { sys = powerMonitor.getSystemIdleTime(); } catch (e) { sys = 0; }
    try { locked = powerMonitor.getSystemIdleState(1) === 'locked'; } catch (e) { locked = false; }
    return { input: sys === 0 && !locked, last: now - sys * 1000 - 500, idle: sys, locked }; } }

module.exports = { Activity };
