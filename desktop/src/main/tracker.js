'use strict';
/*
 * Niyanta Tracker state machine. The server is the source of truth (modules/Attendance/Services/Tracker.php); the app keeps
 * an optimistic local projection so the UI reacts instantly and keeps working offline:
 *   not_started --start--> tracking --pause--> paused --resume--> tracking   (switch: tracking -> tracking, no gap)
 * There is no "end day": the day ends at midnight (workspace time zone). A running entry is closed at 00:00 and tracking
 * continues in the new day on the same project/task; a paused day simply ends and the next Start begins the new day.
 * Activity (activity.js): every second is active when there was keyboard or mouse input in it. Seconds are summed per slot
 * (the screenshot interval, aligned to the workspace's midnight): activity % = active / tracked seconds of the slot (paused
 * time excluded); each slot is reported to /api/agent/activity and the screenshot taken at its end carries that %.
 * Idle: after idle_minutes without input the person is idle from the last input; the timer keeps running and the span is saved
 * as idle time automatically (heartbeat idle_seconds + idle_spans). Idle up to idle_auto_pause_minutes (from the last input)
 * pauses tracking at last input + that maximum; on return a short message offers Resume. Nothing is ever asked.
 * Heartbeats (at most every 60 s) keep the running entry alive on the server; the local session (tracking or not, last alive
 * time, the open slot) is saved every 15 s so a crash or power cut is recovered on the next start (see recover()).
 */
const os = require('os');
const { EventEmitter } = require('events');
const { app } = require('electron');
const { Api, ApiError } = require('./api');
const { Queue } = require('./queue');
const capture = require('./capture');
const toast = require('./toast');
const store = require('./store');
const { Updater } = require('./updater');
const { Activity } = require('./activity');
const errors = require('./errors');

const ACTIONS = { start: 'day/start', pause: 'pause', resume: 'resume', switch: 'switch' };
const SAVE_MS = 15000;
const iso = ms => new Date(ms).toISOString();
const clock = ms => new Date(ms).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });

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
    this.slot = null;          /* {start, len, tracked, active, keys, mouse, counted}: the open activity slot */
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
    this.updater.on('change', st => { if (st.status === 'ready' && !this._readyShown) { this._readyShown = true; this.emit('update-ready', st.version); } this.changed(); });
    this.autoPaused = false;
    this.permission = capture.screenPermission();
    this.loops = [];
    this.lastCaptureError = 0;
    this.selection = { project_id: null, task_id: null };
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
    store.setToken(null); store.set('bootCache', undefined); store.set('session', undefined);
    this.queue.clear();
    this.activity.stop();
    this.boot = null; this.snap = null; this.idlePause = null; this.spans = []; this.idleFrom = 0; this.slot = null; this.lastSlot = null;
    this.api.configure(store.get('workspaceUrl'), null);
    this.updater.configure(null, null);
    this.error = message; this.notice = null;
    this.changed();
    this.emit('signed-out'); }

  signedOutRemotely() { this.signOutLocal('You were signed out (this device was disconnected or your access changed). Please sign in again.'); }

  applyBoot(b, cached = false) {
    this.boot = { user: b.user, workspace: b.workspace, permissions: b.permissions, settings: b.settings, consent: b.consent, policy: b.policy, projects: b.projects || [] };
    if (!cached) store.set('bootCache', Object.assign({}, this.boot, { state: b.state }));
    if (b.state) this.setSnap(b.state, cached);
    const cur = this.snap && (this.snap.running || this.snap.last);
    if (cur && this.selection.project_id === null) this.selection = { project_id: cur.project_id || null, task_id: cur.task_id || null };
    this.changed(); }

  /* ---------- the workspace's day ---------- */

  /** Calendar date (YYYY-MM-DD) in the workspace time zone (the server's day), or the computer's when that is unknown. */
  dayKey(ms = Date.now()) {
    const tz = this.boot && this.boot.workspace && this.boot.workspace.timezone;
    try { return new Intl.DateTimeFormat('en-CA', { timeZone: tz || undefined, year: 'numeric', month: '2-digit', day: '2-digit' }).format(ms); }
    catch (e) { const d = new Date(ms); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`; } }

  /** Seconds since midnight in the workspace time zone. */
  secondsIntoDay(ms = Date.now()) {
    const tz = this.boot && this.boot.workspace && this.boot.workspace.timezone;
    try {
      const p = {}; new Intl.DateTimeFormat('en-GB', { timeZone: tz || undefined, hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23' }).formatToParts(ms).forEach(x => { p[x.type] = +x.value; });
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
    if (was !== 'tracking' && this.state === 'tracking') this.trackingSince = Date.now();
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

  async refreshState() {
    if (!this.signedIn) return;
    try {
      const r = await this.api.get('state', { timeout: 15000 });
      this.offline = false;
      if (!this.queue.jobs.some(j => j.type === 'action')) this.setSnap(r.state);
      const had = !!(this.boot && this.boot.consent);
      this.boot.settings = r.settings;
      if (had && !r.consent) { this.boot.consent = null; this.queue.dropScreenshots(); this.flash('Screen monitoring consent was revoked. No more screenshots are taken.', 'info'); }
      if (!had && r.consent) this.refreshBoot();
      this.reconcile();
      this.changed(); }
    catch (e) {
      if (e.status === 401) return this.signedOutRemotely();
      if (e.offline) { this.offline = true; this.changed(); } } }

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
    store.set('session', { user: this.boot.user.id, tracking: this.state === 'tracking', aliveAt: now, day: this.day || this.dayKey(now), device: os.hostname().slice(0, 90), slot: this.slot }); }

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
      if (s.slot.start === this.slotStart(Date.now() - 500) && s.slot.len === this.slotLen()) this.slot = s.slot;
      else { this.slot = s.slot; this.closeSlot(false); } }
    if (!s.tracking || s.user !== this.boot.user.id) { this.saveSession(true); return; }
    const run = this.snap && this.snap.running;
    if (this.state === 'tracking' && run && run.device && s.device && run.device !== s.device) { this.saveSession(true); return; }
    const gap = Date.now() - (+s.aliveAt || 0);
    if (gap <= this.staleMs) {
      if (this.state === 'tracking') this.action('pause', {}, { quiet: true, at: iso(s.aliveAt) }).then(() => this.action('resume', {}, { quiet: true }));
      else if (['stopped', 'not_started'].includes(this.state)) this.action('resume', {}, { quiet: true });
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
    this.loops.push(setInterval(() => this.tick(), 1000));
    this.loops.push(setInterval(() => this.refreshState(), 60000));
    this.loops.push(setInterval(() => this.refreshBoot(), 15 * 60000));
    this.loops.push(setInterval(() => this.checkUpdates(), 6 * 3600000));
    setTimeout(() => this.checkUpdates(), 8000);
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
    const day = this.dayKey(now);
    if (this.day && day !== this.day) { this.day = day; this.newDay(day); }
    this.day = day;
    this.localTracking = this.state === 'tracking';
    if (this.localTracking) this.saveSession();
    const thr = this.idleThreshold();
    const input = this.activity.sample(now);
    const idleSec = input.idle;
    if (idleSec < thr) this.idleArmed = true;
    /* back at the computer after the automatic pause: the message (and a notification) offers Resume, nothing is asked */
    if (this.idlePause && !this.idlePause.back && idleSec < 3) { this.idlePause.back = true; this.changed(); this.emit('idle-return', this.idlePause); }
    const tracking = this.state === 'tracking';
    this.slotTick(now, tracking && gap <= 30 ? dt : 0, input);
    if (!tracking) return;
    if (!this.idleFrom && this.idleArmed && idleSec >= thr) {
      /* no input for the threshold: idle since the last input (not before tracking started); those seconds move to idle */
      this.idleFrom = Math.max(now - idleSec * 1000, this.trackingSince || 0);
      const back = Math.round((now - this.idleFrom) / 1000);
      this.hb.i += back; this.hb.a = Math.max(0, this.hb.a - back);
      this.changed(); }
    else if (this.idleFrom && idleSec < thr) this.closeIdle(now - idleSec * 1000);
    if (this.idleFrom && now - this.idleFrom >= this.maxIdle() * 1000) { this.autoPauseIdle(); return; }
    this.hb[this.idleFrom ? 'i' : 'a'] += dt;
    if (now >= this.nextHeartbeat) this.flushHeartbeat(); }

  /** The event loop did not run (sleep, hibernate): an open idle span ends where the sleep began; wait for input again. */
  onWake(sleptAt) {
    if (this.idleFrom) this.closeIdle(sleptAt);
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
      this.offline = false;
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
      if (!this.slot) this.slot = { start, len: this.slotLen(), tracked: 0, active: 0, keys: 0, mouse: 0, counted: true };
      const s = this.slot;
      s.tracked = Math.min(s.len, s.tracked + dt);
      if (input.active) s.active = Math.min(s.tracked, s.active + dt);
      if (input.keys === null) s.counted = false; else { s.keys += input.keys; s.mouse += input.mouse; } }
    if (this.slot && now >= this.slot.start + this.slot.len * 1000) this.closeSlot(true); }

  /** Report the open slot (and, at the natural end of a slot while tracking, take the screenshot that carries its activity; not after a sleep). */
  closeSlot(natural) {
    const s = this.slot;
    this.slot = null;
    if (!s || s.tracked < 1) return null;
    const secs = Math.min(s.len, Math.round(s.tracked));
    const rec = { slot_start: iso(s.start), slot_seconds: secs, active_seconds: Math.min(secs, Math.round(s.active)), keystrokes: s.counted ? s.keys : null, mouse_events: s.counted ? s.mouse : null };
    const pct = Math.round(100 * rec.active_seconds / Math.max(1, secs));
    this.lastSlot = { start: rec.slot_start, seconds: secs, pct, keystrokes: rec.keystrokes, mouse_events: rec.mouse_events };
    if (this.activityApi) this.queue.add({ type: 'activity', path: 'activity', json: { slots: [rec] } });
    if (natural && this.state === 'tracking' && secs >= Math.min(10, s.len) && this.captureAllowed()) this.screenshot(pct, rec);
    this.changed();
    return rec; }

  /** Activity % of the open slot so far (null before the first tracked second). */
  slotPct() { return this.slot && this.slot.tracked >= 1 ? Math.round(100 * Math.min(this.slot.active, this.slot.tracked) / this.slot.tracked) : null; }

  /* ---------- screenshots & consent ---------- */

  consentNeeded() { return !!(this.settings.screenshots_enabled && this.boot && !this.boot.consent); }

  captureAllowed() {
    if (!this.settings.screenshots_enabled || !this.boot || !this.boot.consent || this.state !== 'tracking') return false;
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
    try { shots = await capture.captureAll(); }
    catch (e) {
      this.permission = capture.screenPermission();
      if (Date.now() - this.lastCaptureError > 10 * 60000) { this.lastCaptureError = Date.now(); this.report('capture', e, { permission: this.permission, displays: require('electron').screen.getAllDisplays().length }); }
      this.changed();
      return; }
    const at = new Date().toISOString();
    for (const s of shots) this.queue.addScreenshot(s.jpeg, { activity_pct: activity, keystrokes: slot.keystrokes, mouse_events: slot.mouse_events, display: s.index, captured_at: at });
    if (this.notifyOn()) toast.show({ title: 'Screenshot taken', body: `${shots.length > 1 ? shots.length + ' screens · ' : ''}${new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })} · activity ${activity}%`, thumb: shots[0].thumb });
    this.changed(); }

  onRejected(job, err) {
    if (job.type === 'activity' && (err.status === 404 || err.status === 405)) { this.activityApi = false; return; }
    if (job.type === 'screenshot' && /consent|turned off/i.test(err.message)) { this.queue.dropScreenshots(); this.refreshState(); return; }
    if (job.type === 'action') { this.flash(err.message, 'error'); this.refreshState(); return; }
    if (job.type !== 'error' && !/too quickly|only accepted while/i.test(err.message)) this.report('sync', err, { path: job.path, status: err.status }, 'warning'); }

  /* ---------- power events ---------- */

  onPower(kind) {
    if (!this.signedIn) return;
    if ((kind === 'suspend' || kind === 'lock-screen') && this.idleFrom) this.closeIdle(Math.min(Date.now(), this.idleFrom + this.maxIdle() * 1000));
    if ((kind === 'suspend' || kind === 'lock-screen') && this.settings.auto_pause_on_lock && this.state === 'tracking') {
      this.autoPaused = true;
      this.action('pause', {}, { quiet: true }).then(() => { this.autoPaused = true; });
      this.flash(kind === 'suspend' ? 'Paused while the computer was asleep.' : 'Paused while the screen was locked.', 'info'); }
    else if ((kind === 'resume' || kind === 'unlock-screen') && this.autoPaused) {
      this.autoPaused = false;
      setTimeout(() => this.action('resume', {}, { quiet: true }), kind === 'resume' ? 4000 : 500); }
    if (kind === 'suspend') this.saveSession(true);
    if (kind === 'resume') { this.idleArmed = false; this.tick(); setTimeout(() => this.refreshState(), 6000); } }

  /** Best-effort pause before the app quits, so the server does not count the minutes until the entry goes stale. */
  async beforeQuit() {
    if (this.signedIn) this.closeSlot(false);
    if (this.signedIn && this.state === 'tracking') await Promise.race([this.action('pause', {}, { quiet: true }), new Promise(r => setTimeout(r, 4000))]);
    this.activity.stop();
    this.saveSession(true);
    this.queue.persist(); }

  /* ---------- updates ---------- */

  /**
   * On start and every 6 hours: GET /api/agent/version (what the workspace offers) and, where supported, electron-updater
   * against <workspace>/api/agent/updates/ (downloads in the background, installs on quit). Unsupported builds (unsigned
   * macOS, .deb, development) show "Download" instead, which opens the workspace's download page.
   */
  async checkUpdates(manual = false) {
    if (!this.signedIn) return;
    try {
      const r = await this.api.get('version', { timeout: 15000 });
      this.update = { available: !!r.update_available, latest: r.latest, url: r.download_page, checkedAt: new Date().toISOString() };
      if (r.update_available && this.updater.state.supported) { this.updater.configure(this.api.base, this.api.token); await this.updater.check(); }
      if (manual) {
        const u = this.updater.state;
        this.flash(!r.update_available ? (r.latest ? 'You have the latest version.' : 'No version is available from your workspace yet.')
          : (u.status === 'ready' ? `Version ${u.version || r.latest} is ready. Restart to install.` : (u.status === 'downloading' ? `Downloading version ${r.latest}...` : `Version ${r.latest} is available.`)), 'info'); } }
    catch (e) { if (manual) this.flash('Could not check for updates: ' + e.message, 'error'); }
    this.changed(); }

  installUpdate() { return this.updater.install(); }

  /* ---------- UI ---------- */

  flash(text, kind = 'info') { this.notice = { text, kind, id: Date.now() }; this.changed(); }

  changed() {
    if (this._t) return;
    this._t = setTimeout(() => { this._t = null; this.emit('change', this.ui()); }, 30); }

  /** available: newer version on the workspace; ready: downloaded, restart to install; auto: installs itself. */
  updateUi() {
    if (!this.update) return null;
    const u = this.updater.state;
    const auto = !!u.supported && this.update.available;
    return Object.assign({}, this.update, { auto, ready: auto && u.status === 'ready', downloading: auto && u.status === 'downloading', percent: u.percent || 0, readyVersion: u.version || this.update.latest }); }

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
      projectsEnabled: !!this.settings.projects_enabled, projects: this.boot ? this.boot.projects : [], selection: this.selection,
      settings: this.settings, consent: this.boot ? { given: !!this.boot.consent, needed: this.consentNeeded(), policy: this.boot.policy } : null,
      monitoring: { on: !!this.settings.screenshots_enabled && !!(this.boot && this.boot.consent), interval: this.settings.screenshot_interval_seconds, permission: this.permission },
      idlePause: this.idlePause, activity: { source: this.activity.source, counting: this.activity.counting, slot: this.slotPct(), last: this.lastSlot }, update: this.updateUi(),
      prefs: { launchAtLogin: store.get('launchAtLogin', true), notify: this.notifyOn(), notifyAllowed: !!this.settings.screenshot_notify_user_can_disable, tokenProtected: store.tokenProtected() },
      at: Date.now() }; } }

function osName() { return { win32: 'Windows', darwin: 'macOS', linux: 'Linux' }[process.platform] || process.platform; }

module.exports = { Tracker, ApiError, osName };
