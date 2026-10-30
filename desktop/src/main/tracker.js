'use strict';
/*
 * Niyanta Tracker state machine. The server is the source of truth (modules/Attendance/Services/Tracker.php); the app keeps
 * an optimistic local projection so the UI reacts instantly and keeps working offline:
 *   not_started --start--> tracking --pause--> paused --resume--> tracking --end--> ended   (switch: tracking -> tracking, no gap)
 * Activity: every second powerMonitor.getSystemIdleTime() classifies the second as active or idle (idle after idle_minutes
 * without keyboard/mouse input). Heartbeats report the counts; screenshots are taken every N seconds while tracking.
 */
const os = require('os');
const { EventEmitter } = require('events');
const { app, powerMonitor } = require('electron');
const { Api, ApiError } = require('./api');
const { Queue } = require('./queue');
const capture = require('./capture');
const toast = require('./toast');
const store = require('./store');
const { Updater } = require('./updater');

const ACTIONS = { start: 'day/start', pause: 'pause', resume: 'resume', switch: 'switch', end: 'day/end' };

class Tracker extends EventEmitter {
  constructor(report) {
    super();
    this.report = report;
    this.api = new Api();
    this.queue = new Queue(this.api);
    this.boot = null;          /* bootstrap payload: user, workspace, settings, projects, consent, policy */
    this.snap = null;          /* last server state */
    this.snapAt = 0;           /* Date.now() when snap was taken */
    this.offline = false;
    this.busy = false;
    this.error = null;
    this.notice = null;
    this.idleNow = false;
    this.idleStartMs = 0;
    this.idlePrompt = null;
    this.hb = { a: 0, i: 0 };
    this.act = { a: 0, i: 0 };
    this.lastTick = Date.now();
    this.nextHeartbeat = 0;
    this.nextShot = 0;
    this.shots = [];
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
    this.queue.on('done', (job, res) => { if (job.type === 'action' && res && res.state && !this.queue.jobs.some(j => j.type === 'action')) this.setSnap(res.state); });
    this.queue.on('rejected', (job, err) => this.onRejected(job, err));
    this.queue.on('stuck', (job, err) => this.report('queue', err, { job: job.type, path: job.path, tries: job.tries }));
    this.queue.on('dropped', (job, why) => this.report('queue', new Error(`Queued ${job.type} dropped (${why})`), { path: job.path }, 'warning'));
  }

  /* ---------- session ---------- */

  get signedIn() { return !!(this.boot && this.api.token); }
  get settings() { return (this.boot && this.boot.settings) || {}; }

  async restore() {
    const url = store.get('workspaceUrl'); const token = store.getToken();
    if (!url || !token) { this.changed(); return false; }
    this.api.configure(url, token);
    try { this.applyBoot(await this.api.get('bootstrap')); }
    catch (e) {
      if (e.status === 401 || e.status === 403) { this.signOutLocal(e.message); return false; }
      const cached = store.get('bootCache');
      if (!cached) { this.error = e.message; this.offline = !!e.offline; this.changed(); setTimeout(() => this.restore(), 15000); return false; }
      this.offline = !!e.offline;
      this.applyBoot(cached, true);
    }
    this.start();
    return true;
  }

  async signIn({ url, email, password }) {
    this.busy = true; this.error = null; this.changed();
    try {
      this.api.configure(url, null);
      const res = await this.api.post('login', { email, password, device_name: (os.hostname() || 'Computer').slice(0, 90), os: `${osName()} ${os.release()}`.slice(0, 40), app_version: app.getVersion() }, { auth: false });
      store.set('workspaceUrl', this.api.base); store.set('email', email); store.setToken(res.token);
      this.api.configure(this.api.base, res.token);
      this.queue.clear(); this.queue.resume();
      this.applyBoot(res);
      this.start();
      this.emit('signed-in');
      return { ok: true };
    } catch (e) {
      this.error = e.message;
      if (!(e.status && e.status < 500) && e.body && e.body.code !== 'input') this.report('signin', e, { status: e.status });
      return { ok: false, error: e.message };
    } finally { this.busy = false; this.changed(); }
  }

  async signOut() {
    if (this.state === 'tracking') await this.action('pause', {}, { quiet: true });
    try { await this.api.post('logout', {}, { timeout: 5000 }); } catch (e) { /* token is dropped locally anyway */ }
    this.signOutLocal(null);
  }

  signOutLocal(message) {
    this.stop();
    store.setToken(null); store.set('bootCache', undefined);
    this.queue.clear();
    this.boot = null; this.snap = null; this.idlePrompt = null; this.shots = [];
    this.api.configure(store.get('workspaceUrl'), null);
    this.updater.configure(null, null);
    this.error = message; this.notice = null;
    this.changed();
    this.emit('signed-out');
  }

  signedOutRemotely() { this.signOutLocal('You were signed out (this device was disconnected or your access changed). Please sign in again.'); }

  applyBoot(b, cached = false) {
    this.boot = { user: b.user, workspace: b.workspace, permissions: b.permissions, settings: b.settings, consent: b.consent, policy: b.policy, projects: b.projects || [] };
    if (!cached) store.set('bootCache', Object.assign({}, this.boot, { state: b.state }));
    if (b.state) this.setSnap(b.state, cached);
    const cur = this.snap && (this.snap.running || this.snap.last);
    if (cur && this.selection.project_id === null) this.selection = { project_id: cur.project_id || null, task_id: cur.task_id || null };
    this.changed();
  }

  /* ---------- server state & local projection ---------- */

  setSnap(state, cached = false) {
    const was = this.state;
    this.snap = JSON.parse(JSON.stringify(state));
    this.snapAt = cached ? Date.parse(state.server_time || '') || Date.now() : Date.now();
    if (this.state !== 'tracking') { this.idleNow = false; this.idlePrompt = null; }
    if (was !== 'tracking' && this.state === 'tracking') this.nextShot = Date.now() + Math.min(60, this.settings.screenshot_interval_seconds || 180) * 1000;
    this.changed();
  }

  get state() { return this.snap ? this.snap.state : 'not_started'; }

  /** The snapshot rolled forward to now (tracking time keeps counting locally). */
  view() {
    const s = this.snap ? JSON.parse(JSON.stringify(this.snap)) : null;
    if (s && s.state === 'tracking') {
      const dt = Math.max(0, Math.round((Date.now() - this.snapAt) / 1000));
      s.totals.tracked += dt; s.totals.active += dt;
      if (s.running) s.running.elapsed += dt;
    } else if (s && s.state === 'paused' && s.day && s.day.paused_since) {
      const dt = Math.max(0, Math.round((Date.now() - this.snapAt) / 1000));
      s.totals.paused += dt;
    }
    return s;
  }

  /** Optimistic local transition, so the UI is instant and offline actions still make sense. */
  project(name, body) {
    const s = this.view() || { state: 'not_started', day: {}, totals: { tracked: 0, active: 0, idle: 0, paused: 0, entries: 0 }, running: null, last: null };
    const now = new Date().toISOString();
    const pj = this.projectById(body.project_id);
    const task = pj && body.task_id ? (pj.tasks || []).find(t => t.id === body.task_id) : null;
    const entry = src => ({ id: null, started_at: now, elapsed: 0, idle_seconds: 0, project_id: src.project_id || null, project: src.project_id ? (src.project || (this.projectById(src.project_id) || {}).name || null) : null, task_id: src.task_id || null, task: src.task || (task && task.title) || null, description: src.description || null, device: os.hostname() });
    if (s.running) s.last = { project_id: s.running.project_id, project: s.running.project, task_id: s.running.task_id, task: s.running.task, description: s.running.description };
    if (name === 'start') { s.state = 'tracking'; s.day = Object.assign({}, s.day, { clock_in: s.day.clock_in || now, clock_out: null, paused_since: null }); s.running = entry(body); }
    if (name === 'pause') { s.state = 'paused'; s.running = null; s.day.paused_since = now; }
    if (name === 'resume') { s.state = 'tracking'; s.day.paused_since = null; s.running = entry(body.project_id !== undefined ? body : (s.last || {})); }
    if (name === 'switch') { s.state = 'tracking'; s.running = entry(body); }
    if (name === 'end') { s.state = 'ended'; s.running = null; s.day.clock_out = now; s.day.paused_since = null; }
    s.label = { tracking: 'Tracking', paused: 'Paused', ended: 'Day ended' }[s.state] || s.label;
    s.server_time = now;
    this.setSnap(s);
  }

  projectById(id) { return id && this.boot ? this.boot.projects.find(p => p.id === id) || null : null; }

  /** Run a tracking action: optimistic update, then send now (or queue with its client time when offline). */
  async action(name, body = {}, { quiet = false } = {}) {
    if (!ACTIONS[name] || !this.signedIn) return { ok: false };
    body = Object.assign({}, body, { at: new Date().toISOString() });
    if (name === 'pause' || name === 'end') this.flushHeartbeat();
    const before = this.snap;
    this.project(name, body);
    if (name !== 'pause') this.autoPaused = false;
    if (name === 'start' && this.consentNeeded()) this.emit('consent-needed');
    const job = { type: 'action', path: ACTIONS[name], json: body };
    if (this.queue.size) { this.queue.add(job); return { ok: true, queued: true }; }
    try {
      const res = await this.api.post(job.path, body);
      this.offline = false;
      if (res.state) this.setSnap(res.state);
      return { ok: true };
    } catch (e) {
      if (e.retryable) { this.offline = !!e.offline; this.queue.add(job); this.changed(); return { ok: true, queued: true }; }
      if (e.status === 401) { this.signedOutRemotely(); return { ok: false }; }
      this.snap = before; this.changed();
      if (!quiet) this.flash(e.message, 'error');
      this.refreshState();
      return { ok: false, error: e.message };
    }
  }

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
      this.changed();
    } catch (e) {
      if (e.status === 401) return this.signedOutRemotely();
      if (e.offline) { this.offline = true; this.changed(); }
    }
  }

  async refreshBoot() {
    if (!this.signedIn) return;
    try { const b = await this.api.get('bootstrap'); this.applyBoot(b); this.reconcile(); }
    catch (e) { if (e.status === 401) this.signedOutRemotely(); }
  }

  /** The server stopped our entry (no sync for 10 minutes, nightly auto close, another device): tell the person, resume if we were tracking. */
  reconcile() {
    if (!this.snap || this.queue.jobs.some(j => j.type === 'action')) return;
    if (this.localTracking && this.state === 'stopped') {
      this.flash('Tracking was interrupted while this computer was offline or asleep. Time without a connection is not counted; tracking continues now.', 'info');
      this.action('resume', {}, { quiet: true });
    } else if (this.localTracking && this.state === 'ended') {
      this.flash('Your day was closed by the server. Start a new day to keep tracking.', 'info');
    }
    this.localTracking = this.state === 'tracking';
  }

  /* ---------- loops: activity, heartbeat, screenshots, polling ---------- */

  start() {
    this.stop();
    this.lastTick = Date.now();
    this.nextHeartbeat = Date.now() + (this.settings.heartbeat_seconds || 60) * 1000;
    this.localTracking = this.state === 'tracking';
    this.loops.push(setInterval(() => this.tick(), 1000));
    this.loops.push(setInterval(() => this.refreshState(), 60000));
    this.loops.push(setInterval(() => this.refreshBoot(), 15 * 60000));
    this.loops.push(setInterval(() => this.checkUpdates(), 6 * 3600000));
    setTimeout(() => this.checkUpdates(), 8000);
    this.queue.resume();
    this.reconcile();
  }

  stop() { this.loops.forEach(clearInterval); this.loops = []; }

  tick() {
    const now = Date.now();
    const dt = Math.min(5, Math.max(0, (now - this.lastTick) / 1000));
    this.lastTick = now;
    if (!this.signedIn) return;
    this.localTracking = this.state === 'tracking';
    if (this.state !== 'tracking') { this.idleNow = false; return; }
    const thr = Math.max(1, this.settings.idle_minutes || 5) * 60;
    let idleSec = 0;
    try { idleSec = powerMonitor.getSystemIdleTime(); } catch (e) { idleSec = 0; }
    if (!this.idleNow && idleSec >= thr) {
      this.idleNow = true;
      this.idleStartMs = now - idleSec * 1000;
      /* the last `thr` seconds were idle too: move them from active to idle */
      for (const c of [this.hb, this.act]) { const m = Math.min(c.a, thr); c.a -= m; c.i += m; }
      this.pendingIdle = (this.pendingIdle || 0) + Math.min(thr, idleSec);
      this.changed();
    } else if (this.idleNow && idleSec < thr) {
      this.idleNow = false;
      const secs = Math.round((now - this.idleStartMs) / 1000 - idleSec);
      this.flushHeartbeat();
      if (secs >= thr) { this.idlePrompt = { seconds: secs, at: new Date().toISOString() }; this.emit('idle-prompt'); }
      this.changed();
    }
    const c = this.idleNow ? 'i' : 'a';
    this.hb[c] += dt; this.act[c] += dt;
    if (now >= this.nextHeartbeat) this.flushHeartbeat();
    if (now >= this.nextShot && this.captureAllowed()) { this.nextShot = now + Math.max(10, this.settings.screenshot_interval_seconds || 180) * 1000; this.screenshot(); }
  }

  flushHeartbeat() {
    this.nextHeartbeat = Date.now() + Math.max(15, this.settings.heartbeat_seconds || 60) * 1000;
    if (!this.signedIn || (this.state !== 'tracking' && !this.hb.a && !this.hb.i)) return;
    const json = { active_seconds: Math.round(this.hb.a), idle_seconds: Math.round(this.hb.i) };
    this.hb = { a: 0, i: 0 };
    if (this.queue.size) { this.queue.add({ type: 'heartbeat', path: 'heartbeat', json }); return; }
    this.api.post('heartbeat', json, { timeout: 15000 }).then(r => {
      this.offline = false;
      if (r.state && !this.queue.jobs.some(j => j.type === 'action')) { this.setSnap(r.state); this.reconcile(); }
    }).catch(e => {
      if (e.status === 401) return this.signedOutRemotely();
      if (e.retryable) { this.offline = !!e.offline; this.queue.add({ type: 'heartbeat', path: 'heartbeat', json }); }
      this.changed();
    });
  }

  /* ---------- idle prompt ---------- */

  async answerIdle(action) {
    const p = this.idlePrompt;
    this.idlePrompt = null;
    this.changed();
    if (!p) return { ok: true };
    const json = { action: action === 'discard' ? 'discard' : 'keep', seconds: p.seconds };
    if (json.action === 'discard' && this.snap && this.snap.state === 'tracking') {
      const v = this.view(); const cut = Math.min(p.seconds, v.running ? v.running.elapsed : 0);
      this.snap.totals.tracked = Math.max(0, this.snap.totals.tracked - cut);
      if (this.snap.running) this.snap.running.elapsed = Math.max(0, this.snap.running.elapsed - cut);
      this.changed();
    }
    if (this.queue.size) { this.queue.add({ type: 'idle', path: 'idle', json }); return { ok: true }; }
    try { const r = await this.api.post('idle', json); if (r.state) this.setSnap(r.state); return { ok: true }; }
    catch (e) { if (e.retryable) this.queue.add({ type: 'idle', path: 'idle', json }); else this.flash(e.message, 'error'); return { ok: false }; }
  }

  /* ---------- screenshots & consent ---------- */

  consentNeeded() { return !!(this.settings.screenshots_enabled && this.boot && !this.boot.consent); }

  captureAllowed() {
    if (!this.settings.screenshots_enabled || !this.boot || !this.boot.consent || this.state !== 'tracking') return false;
    this.permission = capture.screenPermission();
    return this.permission === 'granted' || this.permission === 'unknown' || this.permission === 'not-determined';
  }

  async acceptConsent() {
    try {
      const r = await this.api.post('consent', { agree: true, policy_version: this.boot.policy && this.boot.policy.version });
      this.boot.consent = r.consent;
      this.nextShot = Date.now() + 15000;
      this.changed();
      return { ok: true };
    } catch (e) { this.flash(e.message, 'error'); return { ok: false, error: e.message }; }
  }

  notifyOn() {
    const pref = store.get('notifyScreenshots');
    if (!this.settings.screenshot_notify_user_can_disable) return !!this.settings.screenshot_notify;
    return pref === undefined || pref === null ? !!this.settings.screenshot_notify : !!pref;
  }

  async screenshot() {
    const activity = Math.round(100 * this.act.a / Math.max(1, this.act.a + this.act.i));
    this.act = { a: 0, i: 0 };
    let shots;
    try { shots = await capture.captureAll(); }
    catch (e) {
      this.permission = capture.screenPermission();
      if (Date.now() - this.lastCaptureError > 10 * 60000) { this.lastCaptureError = Date.now(); this.report('capture', e, { permission: this.permission, displays: require('electron').screen.getAllDisplays().length }); }
      this.changed();
      return;
    }
    const at = new Date().toISOString();
    for (const s of shots) this.queue.addScreenshot(s.jpeg, { activity_pct: activity, display: s.index, captured_at: at });
    this.shots.unshift({ thumb: shots[0].thumb, at, screens: shots.length, activity });
    this.shots = this.shots.slice(0, 3);
    if (this.notifyOn()) toast.show({ title: 'Screenshot taken', body: `${shots.length > 1 ? shots.length + ' screens · ' : ''}${new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })} · activity ${activity}%`, thumb: shots[0].thumb });
    this.changed();
  }

  onRejected(job, err) {
    if (job.type === 'screenshot' && /consent|turned off/i.test(err.message)) { this.queue.dropScreenshots(); this.refreshState(); return; }
    if (job.type === 'action') { this.flash(err.message, 'error'); this.refreshState(); return; }
    if (job.type !== 'error' && !/too quickly|only accepted while/i.test(err.message)) this.report('sync', err, { path: job.path, status: err.status }, 'warning');
  }

  /* ---------- power events ---------- */

  onPower(kind) {
    if (!this.signedIn) return;
    if ((kind === 'suspend' || kind === 'lock-screen') && this.settings.auto_pause_on_lock && this.state === 'tracking') {
      this.autoPaused = true;
      this.action('pause', {}, { quiet: true }).then(() => { this.autoPaused = true; });
      this.flash(kind === 'suspend' ? 'Paused while the computer was asleep.' : 'Paused while the screen was locked.', 'info');
    } else if ((kind === 'resume' || kind === 'unlock-screen') && this.autoPaused) {
      this.autoPaused = false;
      setTimeout(() => this.action('resume', {}, { quiet: true }), kind === 'resume' ? 4000 : 500);
    }
    if (kind === 'resume') setTimeout(() => this.refreshState(), 6000);
  }

  /** Best-effort pause before the app quits, so the server does not count the 10 minutes until the entry goes stale. */
  async beforeQuit() {
    if (this.signedIn && this.state === 'tracking') {
      await Promise.race([this.action('pause', {}, { quiet: true }), new Promise(r => setTimeout(r, 4000))]);
    }
    this.queue.persist();
  }

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
      if (r.update_available && this.updater.state.supported) {
        this.updater.configure(this.api.base, this.api.token);
        await this.updater.check();
      }
      if (manual) {
        const u = this.updater.state;
        this.flash(!r.update_available ? (r.latest ? 'You have the latest version.' : 'No version is available from your workspace yet.')
          : (u.status === 'ready' ? `Version ${u.version || r.latest} is ready. Restart to install.` : (u.status === 'downloading' ? `Downloading version ${r.latest}...` : `Version ${r.latest} is available.`)), 'info');
      }
    } catch (e) { if (manual) this.flash('Could not check for updates: ' + e.message, 'error'); }
    this.changed();
  }

  installUpdate() { return this.updater.install(); }

  /* ---------- UI ---------- */

  flash(text, kind = 'info') { this.notice = { text, kind, id: Date.now() }; this.changed(); }

  changed() {
    if (this._t) return;
    this._t = setTimeout(() => { this._t = null; this.emit('change', this.ui()); }, 30);
  }

  /** available: newer version on the workspace; ready: downloaded, restart to install; auto: installs itself. */
  updateUi() {
    if (!this.update) return null;
    const u = this.updater.state;
    const auto = !!u.supported && this.update.available;
    return Object.assign({}, this.update, { auto, ready: auto && u.status === 'ready', downloading: auto && u.status === 'downloading', percent: u.percent || 0, readyVersion: u.version || this.update.latest });
  }

  setSelection(sel) { this.selection = { project_id: sel.project_id || null, task_id: sel.task_id || null }; }

  ui() {
    const v = this.view();
    const st = this.state;
    const chip = this.offline && this.signedIn ? 'offline' : (st === 'tracking' && this.idleNow ? 'idle' : st);
    return {
      screen: this.signedIn ? 'tracker' : 'signin',
      version: app.getVersion(), platform: process.platform,
      busy: this.busy, error: this.error, notice: this.notice,
      login: { url: store.get('workspaceUrl') || '', email: store.get('email') || '' },
      account: this.boot ? { name: this.boot.user.name, email: this.boot.user.email, initials: this.boot.user.initials, workspace: this.boot.workspace } : null,
      state: v, chip, offline: this.offline, pending: this.queue.size, pendingShots: this.queue.pendingShots,
      projectsEnabled: !!this.settings.projects_enabled, projects: this.boot ? this.boot.projects : [], selection: this.selection,
      settings: this.settings, consent: this.boot ? { given: !!this.boot.consent, needed: this.consentNeeded(), policy: this.boot.policy } : null,
      monitoring: { on: !!this.settings.screenshots_enabled && !!(this.boot && this.boot.consent), interval: this.settings.screenshot_interval_seconds, permission: this.permission },
      idlePrompt: this.idlePrompt, shots: this.shots, update: this.updateUi(),
      prefs: { launchAtLogin: store.get('launchAtLogin', true), notify: this.notifyOn(), notifyAllowed: !!this.settings.screenshot_notify_user_can_disable, tokenProtected: store.tokenProtected() },
      at: Date.now()
    };
  }
}

function osName() { return { win32: 'Windows', darwin: 'macOS', linux: 'Linux' }[process.platform] || process.platform; }

module.exports = { Tracker, ApiError, osName };
