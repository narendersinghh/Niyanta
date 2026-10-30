'use strict';
/*
 * Keyboard and mouse activity, sampled once per second by the tracker. Only counts are kept, never which keys or where.
 * Source "hook": a global input hook (uiohook-napi, prebuilt N-API binaries for Windows, macOS and Linux X11). A second is
 * active when a key was pressed or the mouse moved, clicked or scrolled in it; keystrokes (key presses, auto-repeat ignored)
 * and mouse events (clicks, wheel and moves, at most one move per 250 ms) are counted.
 * Source "idle-time": fallback when the hook cannot run (module missing or failed to load, Wayland, no X display, macOS
 * Accessibility not granted, or the hook stopped reporting): powerMonitor.getSystemIdleTime() each second, active when the
 * last input was at most 1 s ago; counts are unknown (null).
 * On macOS the Accessibility prompt is shown once (ever); the fallback is used meanwhile and the hook starts as soon as the
 * permission is granted (checked every minute).
 */
const { powerMonitor, systemPreferences } = require('electron');
const store = require('./store');

const MOVE_MS = 250;
const WATCHDOG_S = 30;
const RECHECK_MS = 60000;
const T = { keydown: 4, keyup: 5, mousedown: 7, move: 9, wheel: 11 };

class Activity {
  constructor(log) {
    this.log = log || (() => {});
    this.source = null; this.reason = null; this.hook = null;
    this.last = Date.now(); this.lastMove = 0; this.seen = false; this.keys = 0; this.mouse = 0; this.down = new Set(); this.silent = 0; this.recheckAt = 0; }

  get counting() { return this.source === 'hook'; }

  use(source, reason = null) {
    if (this.source === source && this.reason === reason) return;
    this.source = source; this.reason = reason;
    this.log(`activity source: ${source === 'hook' ? 'input hook (keyboard and mouse counts)' : 'system idle time (no counts)'}${reason ? ' (' + reason + ')' : ''}`); }

  /** Why the hook cannot be used on this system right now (null: try it). */
  blocked() {
    if (process.env.NIYANTA_NO_INPUT_HOOK) return 'turned off by NIYANTA_NO_INPUT_HOOK';
    if (process.platform === 'linux') {
      if ((process.env.XDG_SESSION_TYPE || '').toLowerCase() === 'wayland' || (process.env.WAYLAND_DISPLAY && !process.env.DISPLAY)) return 'Wayland session';
      if (!process.env.DISPLAY) return 'no X display'; }
    if (process.platform === 'darwin') {
      let ok = false;
      try { ok = systemPreferences.isTrustedAccessibilityClient(false); } catch (e) { ok = false; }
      if (!ok) {
        if (!store.get('accessibilityAsked')) { store.set('accessibilityAsked', true); try { systemPreferences.isTrustedAccessibilityClient(true); } catch (e) { /* no prompt */ } }
        return 'macOS Accessibility permission not granted'; } }
    return null; }

  start() {
    if (this.hook) return;
    this.recheckAt = Date.now() + RECHECK_MS;
    const why = this.blocked();
    if (why) { this.use('idle-time', why); return; }
    let h;
    try { h = require('uiohook-napi').uIOhook; } catch (e) { this.use('idle-time', 'input hook not available: ' + e.message); return; }
    const onInput = e => {
      const now = Date.now();
      if (e.type === T.keydown) { if (!this.down.has(e.keycode)) { this.down.add(e.keycode); this.keys++; } }
      else if (e.type === T.keyup) { this.down.delete(e.keycode); return; }
      else if (e.type === T.mousedown || e.type === T.wheel) this.mouse++;
      else if (e.type === T.move) {
        /* events can arrive in batches: throttle on the event's own time stamp (ms) when it has one */
        const t = Number.isFinite(e.time) && e.time > 0 ? e.time : now;
        if (Math.abs(t - this.lastMove) >= MOVE_MS) { this.lastMove = t; this.mouse++; } }
      else return;
      this.last = now; this.seen = true; };
    try { h.on('input', onInput); h.start(); }
    catch (e) { try { h.removeListener('input', onInput); } catch (x) { /* ignore */ } this.use('idle-time', 'input hook failed to start: ' + e.message); return; }
    this.hook = h; this.onInput = onInput; this.silent = 0; this.seen = false; this.last = Date.now();
    this.use('hook'); }

  stop() {
    if (!this.hook) return;
    try { this.hook.removeListener('input', this.onInput); this.hook.stop(); } catch (e) { /* already stopped */ }
    this.hook = null; this.down.clear(); }

  /**
   * One sample per tick: {active, idle (seconds since the last input), keys, mouse} for the time since the previous sample
   * (keys/mouse are null without the hook). The hook is dropped for the fallback when the system reports input for 30 s
   * while the hook saw none (e.g. permission revoked while running).
   */
  sample(now = Date.now()) {
    let sys = 0;
    try { sys = powerMonitor.getSystemIdleTime(); } catch (e) { sys = 0; }
    if (!this.hook && this.source !== 'hook' && now >= this.recheckAt && /permission/.test(this.reason || '')) this.start();
    if (!this.hook) { if (sys <= 1) this.last = Math.max(this.last, now - sys * 1000); return { active: sys <= 1, idle: sys, keys: null, mouse: null }; }
    const active = this.seen; const keys = this.keys; const mouse = this.mouse;
    this.seen = false; this.keys = 0; this.mouse = 0;
    if (active) this.silent = 0;
    else if (sys <= 1 && ++this.silent >= WATCHDOG_S) { this.stop(); this.use('idle-time', 'the input hook stopped reporting input'); return { active: true, idle: sys, keys: null, mouse: null }; }
    return { active, idle: Math.min(sys, Math.max(0, Math.floor((now - this.last) / 1000))), keys, mouse }; } }

module.exports = { Activity };
