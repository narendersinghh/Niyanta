'use strict';
/*
 * Niyanta Tracker state machine. The server is the source of truth (modules/Attendance/Services/Tracker.php); the app keeps
 * an optimistic local projection so the UI reacts instantly and keeps working offline:
 *   not_started --start--> tracking --pause--> paused --resume--> tracking   (switch: tracking -> tracking, no gap)
 * There is no "end day": the day ends at midnight (workspace time zone). A running entry is closed at 00:00 and tracking
 * continues in the new day on the same project/task; a paused day simply ends and the next Start begins the new day.
 * Activity (activity.js + meter.js): the system idle time is read every second (whether there was keyboard or mouse input,
 * nothing is counted); tracked seconds are grouped in 12-second blocks, a block is active when it had input (rules and
 * constants in meter.js: grace, bridging a short pause, locked screen inactive, the block after waking ignored). Seconds are summed per slot (the screenshot interval, aligned to the
 * workspace's midnight): activity % = active / tracked seconds of the slot (paused time excluded); each slot is reported to
 * /api/agent/activity and the screenshot taken at its end carries that %.
 * Idle: after idle_minutes without input the person is idle from the last input; the timer keeps running and the span is saved
 * as idle time automatically (heartbeat idle_seconds + idle_spans). Idle up to idle_auto_pause_minutes (from the last input)
 * pauses tracking at last input + that maximum; on return a short message offers Resume. Nothing is ever asked.
 * Meeting mode (setMeeting): time is tracked as usual (screenshots as configured) but idle detection is off and the slots it
 * mostly covers are sent with meeting: 1, so the server keeps their activity without counting it in any average. It turns
 * off by itself after an hour (MEETING_MS), on Pause, or when switched off.
 * Pomodoro (pomodoro.js, optional, local only): after N minutes tracked without a break a reminder offers a break (pause +
 * countdown, "Break over" offers Resume) or a 10-minute snooze.
 * Heartbeats (at most every 60 s) keep the running entry alive on the server; the local session (tracking or not, last alive
 * time, the open slot) is saved every 15 s so a crash or power cut is recovered on the next start (see recover()).
 */
const os = require('os');
const path = require('path');
const { EventEmitter } = require('events');
const { app } = require('electron');
const { Api, ApiError } = require('./api');
const { Queue } = require('./queue');
const capture = require('./capture');
const toast = require('./toast');
const store = require('./store');
const { Updater } = require('./updater');
const { cmpVersion, macAppPath } = require('./selfupdate');
const { Activity } = require('./activity');
const meter = require('./meter');
const { Pomodoro } = require('./pomodoro');
const errors = require('./errors');
const { MEETING_MS } = require('../shared/status');

const ACTIONS = { start: 'day/start', pause: 'pause', resume: 'resume', switch: 'switch' };
const SAVE_MS = 15000;
const UPDATE_EVERY_MS = 30 * 60000;
const STATE_EVERY_MS = 60000;
const STATE_FULL_MS = 5 * 60000;
const STATE_OFFLINE_MAX_MS = 5 * 60000;
const BOOT_EVERY_MS = 15 * 60000;
const QUIT_SYNC_MS = 60000;
const iso = ms => new Date(ms).toISOString();
/* Intl formatters are costly to build; one per time zone is kept (the tracker asks every second). */
const fmtCache = new Map();
function fmt(kind, tz) {
  const k = kind + '|' + (tz || '');
  let f = fmtCache.get(k);
  if (!f) { f = kind === 'day' ? new Intl.DateTimeFormat('en-CA', { timeZone: tz || undefined, year: 'numeric', month: '2-digit', day: '2-digit' }) : new Intl.DateTimeFormat('en-GB', { timeZone: tz || undefined, hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23' }); fmtCache.set(k, f); }
  return f; }
/* 12-hour clock unless the workspace chose 24-hour (boot.workspace.clock, from Settings › General › Time format) */
let clock24 = false;
const clock = ms => new Date(ms).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit', hour12: !clock24 });

class Tracker extends EventEmitter {
  constructor(report) {
    super();
    this.report = report;
    this.api = new Api();
    this.queue = new Queue(this.api);
    this.boot = null;          /* bootstrap payload: user, workspace, settings, projects, consent, policy */
    this.snap = null;          /* last server state */
    this.snapAt = 0;           /* Date.now() the snap is valid for */
    this.offline = false;
    this.busy = false;
    this.error = null;
    this.notice = null;
    this.idleFrom = 0;         /* ms of the last input while an idle span is open, else 0 */
    this.idleArmed = true;     /* false after a wake-up until input is seen again */
    this.spans = [];           /* closed idle spans [{from, to}] (ISO) for the next heartbeat */
    this.idlePause = null;     /* {at, minutes, back}: tracking was paused automatically after the maximum idle time */
    this.activity = new Activity(errors.log);
    this.pomo = new Pomodoro(store.get('pomodoro'));
    this.pomo.on('due', m => { errors.log(`pomodoro: break reminder (${m} min)`); this.emit('pomodoro', 'due', m); this.changed(); });
    this.pomo.on('over', m => { if (this.state === 'tracking') return; this.emit('pomodoro', 'over', m); this.changed(); });
    this.slot = null;          /* meter.slot(): {start, len, tracked, active, meet, ...block state}: the open activity slot */
    this.away = false;         /* screen locked or the system suspended (power events): activity blocks are inactive */
    this.lastSlot = null;
    this.activityApi = true;   /* false when the server has no /api/agent/activity (older workspace) */
    this.trackingSince = 0;
    this.hb = { a: 0, i: 0 };
    this.lastTick = Date.now();
    this.nextHeartbeat = 0;
    this.savedAt = 0;
    this.day = null;
    this.update = null;
    this.updater = new Updater(report);
    this.updater.on('change', () => this.changed());
    this.updater.on('available', v => this.emit('update-available', v));
    this.updater.on('restart', () => this.emit('update-restart'));
    this.autoPaused = false;
    this.permission = capture.screenPermission();
    this.loops = [];
    this.lastCaptureError = 0;
    this.selection = { project_id: null, task_id: null };
    this.meeting = null;       /* {since, until} (ms) while meeting mode is on */
    this.idleFloor = 0;        /* ms: idle never starts before this (the end of the last meeting) */
    this.quitSync = null;      /* {total, left, startedAt, until, shown}: sending the offline queue before quitting */
    this.queue.on('change', () => this.changed());
    this.queue.on('online', on => { this.offline = !on; this.changed(); if (on) this.refreshState(); });
    this.queue.on('unauthorized', () => this.signedOutRemotely());
    this.queue.on('done', (job, res) => { if ((job.type === 'action' || job.type === 'heartbeat' || job.type === 'idle') && res && res.state && !this.queue.jobs.some(j => j.type === 'action')) { this.setSnap(res.state); this.reconcile(); } });
    this.queue.on('rejected', (job, err) => this.onRejected(job, err));
    this.queue.on('stuck', (job, err) => this.report('queue', err, { job: job.type, path: job.path, tries: job.tries }));
    this.queue.on('dropped', (job, why) => this.report('queue', new Error(`Queued ${job.type} dropped (${why})`), { path: job.path }, 'warning')); }

  /* ---------- session ---------- */

  get signedIn() { return !!(this.boot && this.api.token); }
  get settings() { return (this.boot && this.boot.settings) || {}; }
  get staleMs() { return Math.max(5, this.settings.stale_minutes || 10) * 60000; }
  get beatMs() { return Math.min(60, Math.max(15, this.settings.heartbeat_seconds || 60)) * 1000; }
  idleThreshold() { return Math.max(1, +this.settings.idle_minutes || 5) * 60; }
  /** Seconds from the last input until tracking pauses by itself (always more than the idle threshold). */
  maxIdle() { return Math.max(this.idleThreshold() + 60, Math.min(240, +this.settings.idle_auto_pause_minutes || 15) * 60); }
  slotLen() { return Math.max(10, Math.min(3600, +this.settings.screenshot_interval_seconds || 180)); }
  /** Start (ms) of the activity slot containing `ms`: slots of slotLen() seconds counted from the workspace's midnight. */
  slotStart(ms) { const sec = ms - ms % 1000; return sec - (this.secondsIntoDay(sec) % this.slotLen()) * 1000; }

  async restore() {
    const url = store.get('workspaceUrl'); const token = store.getToken();
    if (!url || !token) { this.changed(); return false; }
    if (this._session === undefined) this._session = store.get('session') || null; /* read before anything overwrites it */
    this.api.configure(url, token);
    try { this.applyBoot(await this.api.get('bootstrap')); }
    catch (e) {
      if (e.status === 401 || e.status === 403) { this.signOutLocal(e.message); return false; }
      const cached = store.get('bootCache');
      if (!cached) { this.error = e.message; this.offline = !!e.offline; this.changed(); setTimeout(() => this.restore(), 15000); return false; }
      this.offline = !!e.offline;
      this.applyBoot(cached, true); }
    this.start();
    this.recover(this._session);
    this._session = null;
    this.afterUpdateRestart();
    return true; }

  async signIn({ url, email, password }) {
    this.busy = true; this.error = null; this.changed();
    try {
      this.api.configure(url, null);
      const res = await this.api.post('login', { email, password, device_name: (os.hostname() || 'Computer').slice(0, 90), os: `${osName()} ${os.release()}`.slice(0, 40), app_version: app.getVersion() }, { auth: false });
      store.set('workspaceUrl', this.api.base); store.set('email', email); store.setToken(res.token); store.set('session', undefined);
      this.api.configure(this.api.base, res.token);
      this.queue.clear(); this.queue.resume();
      this.applyBoot(res);
      this.start();
      this.emit('signed-in');
      return { ok: true }; }
    catch (e) {
      this.error = e.message;
      if (!(e.status && e.status < 500) && e.body && e.body.code !== 'input') this.report('signin', e, { status: e.status });
      return { ok: false, error: e.message }; }
    finally { this.busy = false; this.changed(); } }

  async signOut() {
    if (this.state === 'tracking') await this.action('pause', {}, { quiet: true });
    try { await this.api.post('logout', {}, { timeout: 5000 }); } catch (e) { /* token is dropped locally anyway */ }
    this.signOutLocal(null); }

  signOutLocal(message) {
    this.stop();
    store.setToken(null); store.set('bootCache', undefined); store.set('session', undefined); store.set('updateRestart', undefined);
    this.queue.clear();
    store.resetQueueKey();
    this.activity.stop();
    this.boot = null; this.snap = null; this.idlePause = null; this.spans = []; this.idleFrom = 0; this.slot = null; this.lastSlot = null; this.meeting = null;
    this.api.configure(store.get('workspaceUrl'), null);
    this.updater.configure(null, null);
    this.error = message; this.notice = null;
    this.changed();
    this.emit('signed-out'); }

  signedOutRemotely() { this.signOutLocal('You were signed out (this device was disconnected or your access changed). Please sign in again.'); }

  applyBoot(b, cached = false) {
    this.boot = { user: b.user, workspace: b.workspace, permissions: b.permissions, settings: b.settings, consent: b.consent, policy: b.policy, projects: b.projects || [] };
    clock24 = !!(b.workspace && b.workspace.clock === 24);
    if (!cached) store.set('bootCache', Object.assign({}, this.boot, { state: b.state }));
    if (b.state) this.setSnap(b.state, cached);
    const cur = this.snap && (this.snap.running || this.snap.last);
    if (cur && this.selection.project_id === null) this.selection = { project_id: cur.project_id || null, task_id: cur.task_id || null };
    this.changed(); }

  /* ---------- the workspace's day ---------- */

  /** Calendar date (YYYY-MM-DD) in the workspace time zone (the server's day), or the computer's when that is unknown. */
  dayKey(ms = Date.now()) {
    const tz = this.boot && this.boot.workspace && this.boot.workspace.timezone;
    try { return fmt('day', tz).format(ms); }
    catch (e) { const d = new Date(ms); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`; } }

  /** Seconds since midnight in the workspace time zone. */
  secondsIntoDay(ms = Date.now()) {
    const tz = this.boot && this.boot.workspace && this.boot.workspace.timezone;
    try {
      const p = {}; fmt('time', tz).formatToParts(ms).forEach(x => { p[x.type] = +x.value; });
      return (p.hour % 24) * 3600 + p.minute * 60 + p.second; }
    catch (e) { const d = new Date(ms); return d.getHours() * 3600 + d.getMinutes() * 60 + d.getSeconds(); } }

  /**
   * The date changed (midnight passed, or the computer woke up on a later day). Tracking: the server closes the running entry
   * and the old day at 00:00 and continues on the same project/task (Tracker::settle, run by the heartbeat sent now). If the
   * computer slept across midnight the server stops the entry at its last heartbeat instead and reconcile() resumes tracking
   * in the new day. Paused or not tracking: the day is over; Start begins the new one.
   */
  newDay(day) {
    const s = this.snap;
    if (!s) return;
    const into = this.secondsIntoDay();
    const zero = { tracked: 0, active: 0, idle: 0, paused: 0, entries: 0 };
    if (s.state === 'tracking') {
      const start = Date.now() - into * 1000;
      const run = Object.assign({}, s.running || {}, { id: null, started_at: iso(start), elapsed: 0, idle_seconds: 0 });
      this.setSnap(Object.assign({}, s, { day: { date: day, clock_in: iso(start), clock_out: null, paused_since: null }, running: run, totals: Object.assign(zero, { entries: 1 }), server_time: iso(start) }));
      this.snapAt = start;
      this.flash('A new day has started: yesterday was closed at midnight and tracking continues.', 'info');
      this.flushHeartbeat(); }
    else {
      const last = s.running ? { project_id: s.running.project_id, project: s.running.project, task_id: s.running.task_id, task: s.running.task, description: s.running.description } : s.last;
      this.setSnap(Object.assign({}, s, { state: 'not_started', label: 'Not started', day: { date: day, clock_in: null, clock_out: null, paused_since: null }, running: null, last, totals: zero, server_time: iso(Date.now()) }));
      this.refreshState(); } }

  /* ---------- server state & local projection ---------- */

  setSnap(state, cached = false) {
    const was = this.state;
    this.snap = JSON.parse(JSON.stringify(state));
    this.snapAt = cached ? Date.parse(state.server_time || '') || Date.now() : Date.now();
    if (this.state !== 'tracking' && this.idleFrom) this.closeIdle(Date.now());
    if (was !== 'tracking' && this.state === 'tracking') { this.trackingSince = Date.now(); this.pomo.resumed(); }
    else if (was === 'tracking' && this.state !== 'tracking') this.pomo.rest();
    if (this.state !== 'paused') this.idlePause = null;
    if (was !== this.state) this.saveSession(true);
    this.changed(); }

  get state() { return this.snap ? this.snap.state : 'not_started'; }

  /** The snapshot rolled forward to now (tracking time keeps counting locally, also while idle). */
  view() {
    const s = this.snap ? JSON.parse(JSON.stringify(this.snap)) : null;
    const dt = Math.max(0, Math.round((Date.now() - this.snapAt) / 1000));
    if (s && s.state === 'tracking') {
      const idle = this.idleFrom ? Math.min(dt, Math.max(0, Math.round((Date.now() - Math.max(this.snapAt, this.idleFrom)) / 1000))) : 0;
      s.totals.tracked += dt; s.totals.active += dt - idle; s.totals.idle += idle;
      if (s.running) s.running.elapsed += dt; }
    else if (s && s.state === 'paused' && s.day && s.day.paused_since) s.totals.paused += dt;
    return s; }

  /** Optimistic local transition, so the UI is instant and offline actions still make sense. */
  project(name, body) {
    const s = this.view() || { state: 'not_started', day: {}, totals: { tracked: 0, active: 0, idle: 0, paused: 0, entries: 0 }, running: null, last: null };
    const now = body.at || new Date().toISOString();
    const pj = this.projectById(body.project_id);
    const task = pj && body.task_id ? (pj.tasks || []).find(t => t.id === body.task_id) : null;
    const entry = src => ({ id: null, started_at: now, elapsed: 0, idle_seconds: 0, project_id: src.project_id || null, project: src.project_id ? (src.project || (this.projectById(src.project_id) || {}).name || null) : null, task_id: src.task_id || null, task: src.task || (task && task.title) || null, description: src.description || null, device: os.hostname() });
    s.day = s.day || {};
    if (s.running) s.last = { project_id: s.running.project_id, project: s.running.project, task_id: s.running.task_id, task: s.running.task, description: s.running.description };
    if (name === 'start') { s.state = 'tracking'; s.day = Object.assign({}, s.day, { clock_in: s.day.clock_in || now, clock_out: null, paused_since: null }); s.running = entry(body); }
    if (name === 'pause') { s.state = 'paused'; s.running = null; s.day.paused_since = now; }
    if (name === 'resume') { s.state = 'tracking'; s.day = Object.assign({}, s.day, { clock_in: s.day.clock_in || now, clock_out: null, paused_since: null }); s.running = entry(body.project_id !== undefined ? body : (s.last || {})); }
    if (name === 'switch') { s.state = 'tracking'; s.running = entry(body); }
    s.label = { tracking: 'Tracking', paused: 'Paused' }[s.state] || s.label;
    s.server_time = now;
    this.setSnap(s); }

  projectById(id) { return id && this.boot ? this.boot.projects.find(p => p.id === id) || null : null; }

  /** Run a tracking action: optimistic update, then send now (or queue with its client time when offline). */
  async action(name, body = {}, { quiet = false, at = null } = {}) {
    if (!ACTIONS[name] || !this.signedIn) return { ok: false };
    if (name === 'pause' && this.idleFrom) this.closeIdle(Date.now());
    if (name === 'pause') this.endMeeting('pause');
    body = Object.assign({}, body, { at: at || new Date().toISOString() });
    if (name === 'pause') this.flushHeartbeat();
    const before = this.snap;
    this.project(name, body);
    if (name !== 'pause') { this.autoPaused = false; this.idlePause = null; }
    if ((name === 'start' || name === 'resume') && this.consentNeeded()) this.emit('consent-needed');
    const job = { type: 'action', path: ACTIONS[name], json: body };
    if (this.queue.size) { this.queue.add(job); return { ok: true, queued: true }; }
    try {
      const res = await this.api.post(job.path, body);
      this.offline = false;
      if (res.state) this.setSnap(res.state);
      return { ok: true }; }
    catch (e) {
      if (e.retryable) { this.offline = !!e.offline; this.queue.add(job); this.changed(); return { ok: true, queued: true }; }
      if (e.status === 401) { this.signedOutRemotely(); return { ok: false }; }
      this.snap = before; this.changed();
      if (!quiet) this.flash(e.message, 'error');
      this.refreshState();
      return { ok: false, error: e.message }; } }

  /**
   * The periodic GET /state (every 60 s) is skipped while heartbeats (which answer with the same state) are flowing, except
   * for a full refresh (settings, consent) every 5 minutes; while offline the interval doubles up to 5 minutes so a computer
   * without a network does not try every minute.
   */
  stateDue(now) {
    if (now < this.nextState) return false;
    if (this.offline) { this.stateWait = Math.min(STATE_OFFLINE_MAX_MS, (this.stateWait || STATE_EVERY_MS) * 2); this.nextState = now + this.stateWait; return true; }
    this.stateWait = STATE_EVERY_MS; this.nextState = now + STATE_EVERY_MS;
    return !(this.state === 'tracking' && now - this.lastBeatAt < STATE_EVERY_MS && now - this.lastFullState < STATE_FULL_MS); }

  async refreshState() {
    if (!this.signedIn || this.refreshing) return;
    this.refreshing = true;
    try {
      const r = await this.api.get('state', { timeout: 15000 });
      this.offline = false; this.lastFullState = Date.now(); this.stateWait = STATE_EVERY_MS;
      if (!this.queue.jobs.some(j => j.type === 'action')) this.setSnap(r.state);
      const had = !!(this.boot && this.boot.consent);
      this.boot.settings = r.settings;
      if (had && !r.consent) { this.boot.consent = null; this.queue.dropScreenshots(); this.flash('Screen monitoring consent was revoked. No more screenshots are taken.', 'info'); }
      if (!had && r.consent) this.refreshBoot();
      this.reconcile();
      this.changed(); }
    catch (e) {
      if (e.status === 401) return this.signedOutRemotely();
      if (e.offline) { this.offline = true; this.changed(); } }
    finally { this.refreshing = false; } }

  async refreshBoot() {
    if (!this.signedIn) return;
    try { const b = await this.api.get('bootstrap'); this.applyBoot(b); this.reconcile(); }
    catch (e) { if (e.status === 401) this.signedOutRemotely(); } }

  /**
   * The server stopped our entry while we were tracking (no heartbeat for 10 minutes: offline, asleep; or a new day after
   * sleeping across midnight): tell the person and continue tracking (resume also starts the new day when needed).
   */
  reconcile() {
    if (!this.snap || this.queue.jobs.some(j => j.type === 'action')) return;
    if (this.localTracking && ['stopped', 'not_started'].includes(this.state)) {
      this.flash(this.state === 'stopped' ? 'Tracking was interrupted while this computer was offline or asleep. That time is not counted; tracking continues now.' : 'Tracking stopped while this computer was asleep and the day changed. Tracking continues in the new day.', 'info');
      this.localTracking = false;
      this.action('resume', {}, { quiet: true }); }
    else if (this.localTracking && this.state === 'ended') this.flash('Your day was closed on another device. Press Start to track again.', 'info');
    this.localTracking = this.state === 'tracking'; }

  /* ---------- crash / power-off resilience ---------- */

  /** Saved every 15 s and on every state change: whether we are tracking and the last moment the app was alive. */
  saveSession(force = false) {
    const now = Date.now();
    if (!this.signedIn || (!force && now - this.savedAt < SAVE_MS)) return;
    this.savedAt = now;
    store.set('session', { user: this.boot.user.id, tracking: this.state === 'tracking', aliveAt: now, day: this.day || this.dayKey(now), device: os.hostname().slice(0, 90), slot: this.slot, meeting: this.meeting }); }

  /**
   * Start-up after the app was killed or the computer was switched off while tracking (a clean quit pauses first and saves
   * tracking=false). The running entry is never stretched over the time the app was not running:
   * - back within the stale window (10 min, e.g. a crash or a quick restart): the entry is closed at the last moment the app
   *   was alive (saved every 15 s) and tracking resumes at once on the same project/task (in the new day if it changed);
   * - later: a heartbeat tells the server the app was gone, so it closes the entry at its last heartbeat (at most 60 s old);
   *   the app then shows the normal not-tracking state with a note, and the person presses Resume (or Start in a new day).
   */
  recover(s) {
    if (!s || !this.boot) return;
    /* the activity slot that was open: continue it when it is still the current slot, else report it */
    if (s.slot && s.user === this.boot.user.id && s.slot.tracked >= 1) {
      const sl = Object.assign(meter.slot(s.slot.start, s.slot.len), { tracked: +s.slot.tracked || 0, active: +s.slot.active || 0, meet: +s.slot.meet || 0 });
      if (s.slot.start === this.slotStart(Date.now() - 500) && s.slot.len === this.slotLen()) { this.slot = sl; meter.wake(sl, Date.now()); }
      else { this.slot = sl; this.closeSlot(false); } }
    if (!s.tracking || s.user !== this.boot.user.id) { this.saveSession(true); return; }
    const run = this.snap && this.snap.running;
    if (this.state === 'tracking' && run && run.device && s.device && run.device !== s.device) { this.saveSession(true); return; }
    const gap = Date.now() - (+s.aliveAt || 0);
    if (gap <= this.staleMs) {
      if (this.state === 'tracking') this.action('pause', {}, { quiet: true, at: iso(s.aliveAt) }).then(() => this.action('resume', {}, { quiet: true })).then(() => this.restoreMeeting(s.meeting));
      else if (['stopped', 'not_started'].includes(this.state)) { this.action('resume', {}, { quiet: true }); this.restoreMeeting(s.meeting); }
      else return;
      this.flash(`Niyanta Tracker was closed unexpectedly at ${clock(s.aliveAt)}. The time it was not running is not counted; tracking continues.`, 'info');
      return; }
    if (this.state === 'tracking') {
      this.localTracking = false;
      this.setSnap(Object.assign({}, this.view(), { state: 'stopped', label: 'Not tracking', running: null }));
      this.sendHeartbeat({ active_seconds: 0, idle_seconds: 0, at: iso(Date.now()) }); }
    this.flash(`Niyanta Tracker was not running since ${clock(s.aliveAt)} (the computer was switched off or the app was closed). Tracking stopped at that time. Press ${this.dayKey() === s.day ? 'Resume' : 'Start'} to continue.`, 'info');
    this.saveSession(true); }

  /** The system is shutting down or logging out: save the session (recover() closes the entry at this moment next time). */
  onShutdown() { this.flushHeartbeat(); this.saveSession(true); this.queue.persist(); }

  /* ---------- loops: activity, heartbeat, screenshots, polling ---------- */

  start() {
    this.stop();
    this.lastTick = Date.now();
    this.day = this.dayKey();
    this.nextHeartbeat = Date.now() + this.beatMs;
    this.localTracking = this.state === 'tracking';
    this.activity.start();
    /* one timer drives everything: the activity sample every second; state, bootstrap and update refreshes when due */
    this.nextState = Date.now() + STATE_EVERY_MS; this.nextBoot = Date.now() + BOOT_EVERY_MS; this.nextUpdate = Date.now() + 8000;
    this.lastBeatAt = 0; this.lastFullState = Date.now(); this.stateWait = STATE_EVERY_MS;
    this.loops.push(setInterval(() => this.tick(), 1000));
    this.queue.resume();
    this.reconcile(); }

  stop() { this.loops.forEach(clearInterval); this.loops = []; }

  tick() {
    const now = Date.now();
    const gap = (now - this.lastTick) / 1000;
    const dt = Math.min(5, Math.max(0, gap));
    const prev = this.lastTick;
    this.lastTick = now;
    if (!this.signedIn) return;
    if (gap > 30) this.onWake(prev);
    if (this.stateDue(now)) this.refreshState();
    if (now >= this.nextBoot) { this.nextBoot = now + BOOT_EVERY_MS; if (!this.offline) this.refreshBoot(); }
    if (now >= this.nextUpdate) { this.nextUpdate = now + UPDATE_EVERY_MS; if (!this.offline) this.checkUpdates(); }
    const day = this.dayKey(now);
    if (this.day && day !== this.day) { this.day = day; this.newDay(day); }
    this.day = day;
    this.localTracking = this.state === 'tracking';
    if (this.localTracking) this.saveSession();
    const thr = this.idleThreshold();
    const input = this.activity.sample(now);
    this.locked = !!input.locked;
    if (this.meeting && (this.state !== 'tracking' || now >= this.meeting.until)) this.endMeeting(now >= this.meeting.until ? 'timeout' : 'stopped');
    /* meeting mode: nobody types in a meeting, so idle detection (and the automatic pause) is off; afterwards idle counts from its end */
    const idleSec = this.meeting ? 0 : Math.min(input.idle, Math.max(0, Math.floor((now - this.idleFloor) / 1000)));
    if (idleSec < thr) this.idleArmed = true;
    /* back at the computer after the automatic pause: the message (and a notification) offers Resume, nothing is asked */
    if (this.idlePause && !this.idlePause.back && idleSec < 3) { this.idlePause.back = true; this.changed(); this.emit('idle-return', this.idlePause); }
    const tracking = this.state === 'tracking';
    this.slotTick(now, tracking && gap <= 30 ? dt : 0, input);
    if (!tracking) { this.pomo.tick(now, 0); return; }
    if (this.pomo.brk) this.pomo.resumed();
    if (!this.idleFrom && this.idleArmed && idleSec >= thr) {
      /* no input for the threshold: idle since the last input (not before tracking started); those seconds move to idle */
      this.idleFrom = Math.max(now - idleSec * 1000, this.trackingSince || 0, this.idleFloor);
      const back = Math.round((now - this.idleFrom) / 1000);
      this.hb.i += back; this.hb.a = Math.max(0, this.hb.a - back);
      this.changed(); }
    else if (this.idleFrom && idleSec < thr) this.closeIdle(now - idleSec * 1000);
    if (this.idleFrom && now - this.idleFrom >= this.maxIdle() * 1000) { this.autoPauseIdle(); return; }
    this.hb[this.idleFrom ? 'i' : 'a'] += dt;
    /* pomodoro: only time worked counts; idle at least as long as the break is a break */
    if (this.idleFrom && now - this.idleFrom >= this.pomo.prefs.brk * 60000) this.pomo.rest();
    this.pomo.tick(now, this.idleFrom || gap > 30 ? 0 : dt);
    if (now >= this.nextHeartbeat) this.flushHeartbeat(); }

  /** The event loop did not run (sleep, hibernate): an open idle span ends where the sleep began; wait for input again. */
  onWake(sleptAt) {
    this.wokeAt = Date.now(); meter.wake(this.slot, this.wokeAt);
    if (this.idleFrom) this.closeIdle(sleptAt);
    if (Date.now() - sleptAt >= this.pomo.prefs.brk * 60000) this.pomo.rest();
    this.idleArmed = false;
    this.nextHeartbeat = 0; }

  flushHeartbeat() {
    this.nextHeartbeat = Date.now() + this.beatMs;
    if (!this.signedIn || (this.state !== 'tracking' && !this.hb.a && !this.hb.i && !this.spans.length)) return;
    const json = { active_seconds: Math.round(this.hb.a), idle_seconds: Math.round(this.hb.i), at: iso(Date.now()) };
    if (this.spans.length) json.idle_spans = this.spans;
    this.hb = { a: 0, i: 0 }; this.spans = [];
    this.saveSession(true);
    this.sendHeartbeat(json); }

  sendHeartbeat(json) {
    if (this.queue.size) { this.queue.add({ type: 'heartbeat', path: 'heartbeat', json }); return; }
    this.api.post('heartbeat', json, { timeout: 15000 }).then(r => {
      this.offline = false; this.lastBeatAt = Date.now();
      if (r.state && !this.queue.jobs.some(j => j.type === 'action')) { this.setSnap(r.state); this.reconcile(); } }).catch(e => {
      if (e.status === 401) return this.signedOutRemotely();
      if (e.retryable) { this.offline = !!e.offline; this.queue.add({ type: 'heartbeat', path: 'heartbeat', json }); }
      this.changed(); }); }

  /* ---------- idle: saved automatically, automatic pause after the maximum ---------- */

  /** Close the open idle span at `to` (ms); it goes to the server with the next heartbeat (sent now) as idle time. */
  closeIdle(to) {
    const from = this.idleFrom;
    this.idleFrom = 0;
    if (!from || to - from < 1000) { this.changed(); return; }
    const last = this.spans[this.spans.length - 1];
    if (last && from <= Date.parse(last.to) + 1000) last.to = iso(Math.max(Date.parse(last.to), to)); else this.spans.push({ from: iso(from), to: iso(to) });
    this.flushHeartbeat();
    this.changed(); }

  /** Idle up to the maximum: keep that idle time and pause at last input + maximum (nothing after it is tracked). */
  autoPauseIdle() {
    const at = this.idleFrom + this.maxIdle() * 1000;
    this.closeIdle(at);
    this.idlePause = { at: iso(at), minutes: Math.round(this.maxIdle() / 60), back: false };
    errors.log(`paused automatically after ${this.idlePause.minutes} min idle (at ${this.idlePause.at})`);
    this.action('pause', {}, { quiet: true, at: iso(at) }); }

  /* ---------- activity slots ---------- */

  /**
   * Add this tick (the dt tracked seconds before `now`, 0 while not tracking) to the slot it falls in; a slot that is over is
   * closed (its last second included) and reported.
   */
  slotTick(now, dt, input) {
    const start = this.slotStart(now - 500);
    if (this.slot && (this.slot.start !== start || this.slot.len !== this.slotLen())) this.closeSlot(this.slot.len === this.slotLen() && now - (this.slot.start + this.slot.len * 1000) < 5000);
    if (dt) {
      if (!this.slot) { this.slot = meter.slot(start, this.slotLen()); if (this.wokeAt && now - this.wokeAt < meter.BLOCK_S * 1000) meter.wake(this.slot, this.wokeAt); }
      const s = this.slot;
      const added = meter.tick(s, now, dt, input.last, { input: input.input, away: this.away || input.locked });
      if (this.meeting) s.meet = Math.min(s.tracked, (s.meet || 0) + added); }
    if (this.slot && now >= this.slot.start + this.slot.len * 1000) this.closeSlot(true); }

  /** Report the open slot (and, at the natural end of a slot while tracking, take the screenshot that carries its activity; not after a sleep). */
  closeSlot(natural) {
    const s = this.slot;
    this.slot = null;
    if (!s || s.tracked < 1) return null;
    meter.finish(s);
    const secs = Math.min(s.len, Math.round(s.tracked));
    const rec = { slot_start: iso(s.start), slot_seconds: secs, active_seconds: Math.min(secs, Math.round(s.active)), meeting: meter.meetingSlot(s) ? 1 : 0 };
    const pct = Math.round(100 * rec.active_seconds / Math.max(1, secs));
    this.lastSlot = { start: rec.slot_start, seconds: secs, pct, meeting: !!rec.meeting };
    if (!rec.meeting && this.dayKey(s.start) === this.dayKey()) { const d = this.dayAct && this.dayAct.day === this.dayKey() ? this.dayAct : { day: this.dayKey(), tracked: 0, active: 0 }; d.tracked += secs; d.active += rec.active_seconds; this.dayAct = d; }
    if (this.activityApi) this.queue.add({ type: 'activity', path: 'activity', json: { slots: [rec] } });
    if (natural && this.state === 'tracking' && secs >= Math.min(10, s.len) && this.captureAllowed()) this.screenshot(pct, rec);
    this.changed();
    return rec; }

  /** Activity % of the open slot so far (null before the first tracked second). */
  slotPct() { return this.slot && this.slot.tracked >= 1 ? Math.round(100 * Math.min(this.slot.active, this.slot.tracked) / this.slot.tracked) : null; }

  /* ---------- screenshots & consent ---------- */

  consentNeeded() { return !!(this.settings.screenshots_enabled && this.boot && !this.boot.consent); }

  /** Screenshots only with monitoring on, the person's consent, while tracking and never while the screen is locked or the computer asleep. */
  captureAllowed() {
    if (!this.settings.screenshots_enabled || !this.boot || !this.boot.consent || this.state !== 'tracking' || this.away || this.locked) return false;
    this.permission = capture.screenPermission();
    return this.permission === 'granted' || this.permission === 'unknown' || this.permission === 'not-determined'; }

  async acceptConsent() {
    try {
      const r = await this.api.post('consent', { agree: true, policy_version: this.boot.policy && this.boot.policy.version });
      this.boot.consent = r.consent;
      this.changed();
      return { ok: true }; }
    catch (e) { this.flash(e.message, 'error'); return { ok: false, error: e.message }; } }

  notifyOn() {
    const pref = store.get('notifyScreenshots');
    if (!this.settings.screenshot_notify_user_can_disable) return !!this.settings.screenshot_notify;
    return pref === undefined || pref === null ? !!this.settings.screenshot_notify : !!pref; }

  /**
   * Capture and queue the upload with the activity of the slot that just ended. Screenshots are not shown in the app; only
   * the small capture notice appears (if enabled).
   */
  async screenshot(activity, slot) {
    let shots;
    const notify = this.notifyOn();
    try { shots = await capture.captureAll({ thumb: notify }); }
    catch (e) {
      this.permission = capture.screenPermission();
      if (Date.now() - this.lastCaptureError > 10 * 60000) { this.lastCaptureError = Date.now(); this.report('capture', e, { permission: this.permission, displays: require('electron').screen.getAllDisplays().length }); }
      this.changed();
      return; }
    const at = new Date().toISOString();
    for (const s of shots) this.queue.addScreenshot(s.jpeg, { activity_pct: activity, meeting: slot.meeting ? 1 : 0, display: s.index, captured_at: at });
    if (notify) toast.show({ title: 'Screenshot taken', body: `${shots.length > 1 ? shots.length + ' screens · ' : ''}${clock(Date.now())} · ${slot.meeting ? 'meeting' : 'activity ' + activity + '%'}`, thumb: shots[0].thumb });
    this.changed(); }

  onRejected(job, err) {
    if (job.type === 'activity' && (err.status === 404 || err.status === 405)) { this.activityApi = false; return; }
    if (job.type === 'screenshot' && /consent|turned off/i.test(err.message)) { this.queue.dropScreenshots(); this.refreshState(); return; }
    if (job.type === 'action') { this.flash(err.message, 'error'); this.refreshState(); return; }
    if (job.type !== 'error' && !/too quickly|only accepted while/i.test(err.message)) this.report('sync', err, { path: job.path, status: err.status }, 'warning'); }

  /* ---------- meeting mode ---------- */

  /**
   * Meeting mode on: tracking continues as usual (starts or resumes it when needed), idle detection is off and the slots it
   * mostly covers are flagged as meeting (their activity is not averaged). Off after MEETING_MS, on Pause or when switched off.
   */
  async setMeeting(on) {
    if (!this.signedIn) return { ok: false };
    if (!on) { this.endMeeting('off'); return { ok: true }; }
    if (this.state !== 'tracking') {
      const r = await this.action(['not_started', 'ended'].includes(this.state) ? 'start' : 'resume', this.selection);
      if (!r.ok || this.state !== 'tracking') return { ok: false }; }
    const now = Date.now();
    if (this.idleFrom) this.closeIdle(now);
    this.meeting = { since: now, until: now + MEETING_MS };
    errors.log('meeting mode on');
    this.saveSession(true); this.changed();
    return { ok: true }; }

  endMeeting(why) {
    if (!this.meeting) return;
    this.meeting = null;
    this.idleFloor = Date.now();
    errors.log(`meeting mode off (${why})`);
    if (why === 'timeout') this.flash('Meeting mode turned off after 1 hour. Activity counts again.', 'info');
    this.saveSession(true); this.changed(); }

  /** After a crash or an update restart: meeting mode continues until its original end. */
  restoreMeeting(m) { if (m && +m.until > Date.now() && this.state === 'tracking') { this.meeting = { since: +m.since, until: +m.until }; this.changed(); } }

  /* ---------- pomodoro break reminder ---------- */

  /** Save the reminder settings (this device only). */
  setPomodoro(p) { const prefs = this.pomo.configure(p); store.set('pomodoro', prefs); this.changed(); return { ok: true, prefs }; }

  /** "Take a break": pause now and count the break down; "Break over" then offers Resume (never resumes by itself). */
  async takeBreak() {
    if (this.state !== 'tracking') return { ok: false };
    this.pomo.takeBreak(Date.now());
    const r = await this.action('pause');
    this.changed();
    return r; }

  snoozeBreak() { this.pomo.snooze(Date.now()); this.changed(); return { ok: true }; }

  dismissBreakOver() { this.pomo.dismissOver(); this.changed(); return { ok: true }; }

  /* ---------- power events ---------- */

  onPower(kind) {
    if (!this.signedIn) return;
    /* locked or asleep: activity blocks are inactive at once; after waking the first block is left out (meter.js) */
    if (kind === 'suspend' || kind === 'lock-screen') this.away = true;
    if (kind === 'unlock-screen') this.away = false;
    if (kind === 'resume') { this.away = false; this.wokeAt = Date.now(); meter.wake(this.slot, this.wokeAt); }
    if ((kind === 'suspend' || kind === 'lock-screen') && this.idleFrom) this.closeIdle(Math.min(Date.now(), this.idleFrom + this.maxIdle() * 1000));
    if ((kind === 'suspend' || kind === 'lock-screen') && this.settings.auto_pause_on_lock && this.state === 'tracking') {
      this.autoPaused = true;
      this.action('pause', {}, { quiet: true }).then(() => { this.autoPaused = true; });
      this.flash(kind === 'suspend' ? 'Paused while the computer was asleep.' : 'Paused while the screen was locked.', 'info'); }
    else if ((kind === 'resume' || kind === 'unlock-screen') && this.autoPaused) {
      this.autoPaused = false;
      setTimeout(() => this.action('resume', {}, { quiet: true }), kind === 'resume' ? 4000 : 500); }
    if (kind === 'suspend') this.saveSession(true);
    if (kind === 'resume') { this.idleArmed = false; this.tick(); setTimeout(() => this.refreshState(), 6000); }
    /* back from sleep or the lock screen: look for an update (the loop timer may have been asleep for hours) */
    if (kind === 'resume' || kind === 'unlock-screen') { clearTimeout(this._wakeCheck); this._wakeCheck = setTimeout(() => this.checkUpdates(), 15000); } }

  /**
   * Quitting with data still in the offline queue (actions, heartbeats, activity, screenshots): send it first. The window
   * shows "Syncing your data to the server..." with progress (main.js shows it after 1.2 s, so a quick sync is invisible) and
   * the app quits when the queue is empty, after QUIT_SYNC_MS, or when the person clicks Quit anyway (the rest stays queued
   * on disk and is sent on the next start). Resolves {done, left}.
   */
  async syncBeforeQuit(ms = QUIT_SYNC_MS) {
    if (!this.signedIn || !this.queue.size) return { done: true, left: 0 };
    this.stop();
    const now = Date.now();
    this.quitSync = { total: this.queue.size, left: this.queue.size, startedAt: now, until: now + ms, shown: false };
    const show = setTimeout(() => { if (this.quitSync) { this.quitSync.shown = true; this.emit('quit-sync-show'); this.changed(); } }, 1200);
    this.changed();
    const r = await this.queue.flush(ms, left => { if (this.quitSync) { this.quitSync.total = Math.max(this.quitSync.total, left); this.quitSync.left = left; } });
    clearTimeout(show);
    errors.log(`quit: ${r.done ? 'all data synced' : r.left + ' item(s) left queued for the next start'}`);
    this.quitSync = null;
    return r; }

  /** "Quit anyway": stop waiting; what is left stays in the queue for the next start. */
  quitAnyway() { if (this.queue.abortFlush) this.queue.abortFlush(); return { ok: true }; }

  /** Best-effort pause before the app quits, so the server does not count the minutes until the entry goes stale. */
  async beforeQuit(wait = 4000) {
    if (this.signedIn) this.closeSlot(false);
    if (this.signedIn) this.flushHeartbeat();
    if (this.signedIn && this.state === 'tracking') await Promise.race([this.action('pause', {}, { quiet: true }), new Promise(r => setTimeout(r, wait))]);
    this.activity.stop();
    this.saveSession(true);
    this.queue.persist(); }

  /* ---------- updates ---------- */

  /**
   * On start, every 30 minutes and after waking up or unlocking: GET /api/agent/version (what the workspace offers); when it is newer, the updater finds out
   * what one click installs (updater.js). The window shows the update card and main.js one notification per version.
   */
  async checkUpdates(manual = false) {
    if (!this.signedIn) return;
    try {
      const r = await this.api.get('version', { timeout: 15000 });
      this.update = { available: !!r.update_available, latest: r.latest, url: r.download_page, checkedAt: new Date().toISOString() };
      if (r.update_available) { this.updater.configure(this.api.base, this.api.token); await this.updater.check({ latest: r.latest, files: r.files || {} }); this.emit('update-available', this.updater.state.version || r.latest); }
      if (manual) this.flash(!r.update_available ? (r.latest ? 'You have the latest version.' : 'No version is available from your workspace yet.') : `Niyanta Tracker ${r.latest} is available. Click Update now to install it.`, 'info'); }
    catch (e) { if (manual) this.flash('Could not check for updates: ' + e.message, 'error'); }
    this.changed(); }

  /** "Update now": download and verify; the updater then asks main.js to restart (pause first, resume after). */
  updateNow() { if (!this.update || !this.update.available) return { ok: false }; this.updater.configure(this.api.base, this.api.token); return this.updater.updateNow(); }

  /** Fallback: save the installer for this computer to Downloads and open it. */
  async downloadInstaller() {
    this.updater.configure(this.api.base, this.api.token);
    try { return await this.updater.manualDownload(); }
    catch (e) { this.updater.fail(e.message, e); return { ok: false, error: e.message }; } }

  /**
   * Just before the update restart: remember whether we were tracking (main.js then pauses, as on every quit). The pause is
   * sent with the click's time; after the restart afterUpdateRestart() resumes, so the restart itself is never counted.
   */
  markUpdateRestart() {
    if (!this.signedIn) return;
    store.set('updateRestart', { user: this.boot.user.id, tracking: this.state === 'tracking', meeting: this.meeting, at: Date.now(), from: app.getVersion(), to: this.updater.state.version || (this.update && this.update.latest) || null, exe: process.execPath }); }

  /** First start after an update restart: resume tracking if it was on (within 30 minutes) and say what happened. */
  afterUpdateRestart() {
    const r = store.get('updateRestart');
    if (!r) return;
    store.set('updateRestart', undefined);
    if (!this.boot || r.user !== this.boot.user.id) return;
    const resume = r.tracking && Date.now() - (+r.at || 0) < 30 * 60000;
    if (resume) {
      /* the pause did not reach the server before the restart: close the entry at the click, then resume */
      if (this.state === 'tracking') this.action('pause', {}, { quiet: true, at: iso(r.at) }).then(() => this.action('resume', {}, { quiet: true })).then(() => this.restoreMeeting(r.meeting));
      else if (['paused', 'stopped', 'not_started'].includes(this.state)) { this.action('resume', {}, { quiet: true }); this.restoreMeeting(r.meeting); } }
    const updated = cmpVersion(app.getVersion(), r.from) > 0;
    if (!updated) this.updater.notInstalled(r.to);
    /* macOS: the old folder was not writable, so the new version went to ~/Applications (login item follows the new path) */
    const moved = updated && process.platform === 'darwin' && r.exe && r.exe !== process.execPath ? (macAppPath(process.execPath) || process.execPath) : null;
    if (moved) this.emit('app-moved');
    this.flash(updated ? `Updated to version ${app.getVersion()}.${resume ? ' Tracking continues where you left off.' : ''}${moved ? ` It is now in ${path.dirname(moved)}; you can delete the old copy in ${path.dirname(macAppPath(r.exe) || r.exe)}.` : ''}` : `The update to ${r.to || 'the new version'} could not be installed automatically. Use Download the new version below.${resume ? ' Tracking continues.' : ''}`, updated ? 'info' : 'error'); }

  /* ---------- UI ---------- */

  flash(text, kind = 'info') { this.notice = { text, kind, id: Date.now() }; this.emit('alert', kind); this.changed(); }

  changed() {
    if (this._t) return;
    this._t = setTimeout(() => { this._t = null; this.emit('change', this.ui()); }, 30); }

  /**
   * available: a newer version is on the workspace; auto: one click installs it (else the manual download); status: idle |
   * available | downloading | verifying | ready | installing | manual (installer saved to Downloads) | error; want: the
   * person clicked Update now (progress is shown and the app restarts when ready).
   */
  updateUi() {
    const u = this.updater.state;
    if (!this.update && !u.manual) return null;
    const cur = app.getVersion();
    const latest = u.version && cmpVersion(u.version, cur) > 0 ? u.version : (this.update && this.update.latest) || null;
    const available = !!(this.update && this.update.available) || !!(u.version && cmpVersion(u.version, cur) > 0);
    return { available, latest, current: cur, url: this.update && this.update.url, notes: u.notes || [], mode: u.mode, auto: this.updater.auto, status: u.status,
      percent: u.percent || 0, error: u.error, want: !!u.want, manual: u.manual, platform: process.platform, checkedAt: this.update && this.update.checkedAt,
      prompt: available && !!latest && !u.want && !['manual', 'error'].includes(u.status) && store.get('updatePrompted') !== latest }; }

  /** The update prompt was answered (Update now or Later): it is shown once per version; the card stays. */
  updatePrompted(v) { if (v) store.set('updatePrompted', String(v).slice(0, 40)); this.changed(); return { ok: true }; }

  setSelection(sel) { this.selection = { project_id: sel.project_id || null, task_id: sel.task_id || null }; }

  ui() {
    const v = this.view();
    const st = this.state;
    const idle = st === 'tracking' && !!this.idleFrom;
    const chip = this.offline && this.signedIn ? 'offline' : (idle ? 'idle' : st);
    return {
      screen: this.signedIn ? 'tracker' : 'signin',
      version: app.getVersion(), platform: process.platform,
      busy: this.busy, error: this.error, notice: this.notice,
      login: { url: store.get('workspaceUrl') || '', email: store.get('email') || '' },
      account: this.boot ? { name: this.boot.user.name, email: this.boot.user.email, initials: this.boot.user.initials, workspace: this.boot.workspace } : null,
      state: v, chip, idle, offline: this.offline, pending: this.queue.size, pendingShots: this.queue.pendingShots,
      skew: this.snap && this.snap.server_time ? Date.parse(this.snap.server_time) - this.snapAt : 0,
      meeting: this.meeting && this.state === 'tracking' ? this.meeting : null, quitSync: this.quitSync && this.quitSync.shown ? Object.assign({ offline: this.offline }, this.quitSync) : null,
      dayActivity: this.dayAct && this.dayAct.day === this.dayKey() && this.dayAct.tracked ? Math.round(100 * this.dayAct.active / this.dayAct.tracked) : null,
      projectsEnabled: !!this.settings.projects_enabled, projects: this.boot ? this.boot.projects : [], selection: this.selection,
      settings: this.settings, consent: this.boot ? { given: !!this.boot.consent, needed: this.consentNeeded(), policy: this.boot.policy } : null,
      monitoring: { on: !!this.settings.screenshots_enabled && !!(this.boot && this.boot.consent), interval: this.settings.screenshot_interval_seconds, permission: this.permission },
      idlePause: this.idlePause, activity: { source: this.activity.source, slot: this.slotPct(), last: this.lastSlot }, update: this.updateUi(),
      tracking: st === 'tracking' && !idle, idleSince: idle ? this.idleFrom : null,
      prefs: { launchAtLogin: store.get('launchAtLogin', true), notify: this.notifyOn(), notifyAllowed: !!this.settings.screenshot_notify_user_can_disable, tone: store.get('alertTone', true), tokenProtected: store.tokenProtected() },
      pomodoro: this.pomo.view(),
      at: Date.now() }; } }

function osName() { return { win32: 'Windows', darwin: 'macOS', linux: 'Linux' }[process.platform] || process.platform; }

module.exports = { Tracker, ApiError, osName };
