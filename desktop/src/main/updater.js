'use strict';
/*
 * In-app updates from the workspace the person signed in to (<workspace>/api/agent/updates/, device token). The workspace
 * mirrors the private GitHub release, so nothing here talks to GitHub. On start and every 6 hours the tracker asks
 * /api/agent/version; when a newer version exists the window shows "Niyanta Tracker X is available" with one button,
 * Update now, plus one OS notification per version. Nothing restarts without that click (or a normal quit, as before).
 * How the click updates, per install:
 * - Windows (NSIS), Linux AppImage, macOS with a Developer ID signature: electron-updater (generic provider). The update is
 *   pre-downloaded in the background; the click waits for it (progress bar), then quitAndInstall(silent, run after). Files
 *   the app downloads itself carry no Mark-of-the-Web, so SmartScreen does not prompt.
 * - macOS ad-hoc signed (no Developer ID, Squirrel.Mac refuses it): own updater. Downloads the zip for this Mac from
 *   latest-mac.yml, checks its sha512, extracts it with ditto and starts a detached helper (selfupdate.macHelperScript) that
 *   waits for the app to quit, swaps the bundle, runs xattr -cr and opens the new version. App downloads have no quarantine
 *   flag, so Gatekeeper does not block it.
 * - Linux .deb: downloads the new .deb to Downloads and opens it with the system installer (and shows the apt command).
 * - Anything failing: "Download the new version" saves the installer to Downloads (inside the app, no browser) and opens it,
 *   with short steps. Development builds only offer that manual download.
 * The caller (main.js) pauses tracking before the restart and the tracker resumes it after (tracker.afterUpdateRestart).
 */
const fs = require('fs');
const os = require('os');
const path = require('path');
const { execFile, spawn } = require('child_process');
const { app, net, shell } = require('electron');
const { EventEmitter } = require('events');
const su = require('./selfupdate');

const FEED = { win32: 'latest.yml', darwin: 'latest-mac.yml', linux: 'latest-linux.yml' };

class Updater extends EventEmitter {
  constructor(report) {
    super();
    this.report = report;
    this.au = null;
    this.feed = '';
    this.token = '';
    this.busy = false;
    this.offer = null;         /* /api/agent/version answer: latest, files */
    this.macFeed = null;       /* parsed latest-mac.yml (own macOS updater) */
    this.prepared = null;      /* { source, work }: extracted macOS app ready to swap in */
    this.mode = Updater.initialMode();
    this.state = { mode: this.mode, status: 'idle', version: null, notes: [], percent: 0, error: null, want: false, manual: null, bg: false };
    this.signing = this.mode === 'mac' ? this.detectMacSigning() : Promise.resolve(); }

  /** electron: electron-updater; mac: own macOS updater; deb: download + system installer; manual: only "Download". */
  static initialMode() {
    if (!app.isPackaged) return 'manual';
    if (process.platform === 'win32') return 'electron';
    if (process.platform === 'linux') return process.env.APPIMAGE ? 'electron' : 'deb';
    if (process.platform === 'darwin') return process.env.NIYANTA_MAC_UPDATER === 'squirrel' ? 'electron' : 'mac';
    return 'manual'; }

  get auto() { return ['electron', 'mac', 'deb'].includes(this.mode); }

  /** A real Developer ID signature (TeamIdentifier set) lets Squirrel.Mac update the app; ad-hoc builds use the own updater. */
  detectMacSigning() {
    const appPath = su.macAppPath(process.execPath);
    if (!appPath || process.env.NIYANTA_MAC_UPDATER === 'custom') return Promise.resolve();
    return new Promise(resolve => execFile('/usr/bin/codesign', ['-dv', '--verbose=2', appPath], { timeout: 10000 }, (err, out, errOut) => {
      const txt = String(out || '') + String(errOut || '');
      const team = (txt.match(/^TeamIdentifier=(.+)$/m) || [])[1];
      if (!err && team && team.trim() !== 'not set' && !/Signature=adhoc/.test(txt)) { this.mode = 'electron'; this.state.mode = 'electron'; }
      resolve(); })); }

  load() {
    if (this.au || this.mode !== 'electron') return this.au;
    try {
      const { autoUpdater } = require('electron-updater');
      autoUpdater.autoDownload = true;
      autoUpdater.autoInstallOnAppQuit = true;
      autoUpdater.allowPrerelease = false;
      autoUpdater.allowDowngrade = false;
      autoUpdater.logger = { info: () => {}, warn: () => {}, debug: () => {}, error: () => {} };
      autoUpdater.on('update-not-available', () => { if (!this.state.want) this.set({ status: 'current', percent: 0, bg: false }); });
      autoUpdater.on('update-available', info => this.available(info && info.version, info && info.releaseNotes, { bg: true }));
      autoUpdater.on('download-progress', p => { const pc = Math.round(p && p.percent || 0); if (pc !== this.state.percent) this.set({ percent: pc }); });
      autoUpdater.on('update-downloaded', info => { this.set({ status: 'ready', bg: false, percent: 100, version: (info && info.version) || this.state.version }); if (this.state.want) this.emit('restart'); });
      autoUpdater.on('error', err => this.onError(err));
      this.au = autoUpdater; }
    catch (e) { this.mode = 'manual'; this.state.mode = 'manual'; this.report('updater', e, {}, 'warning'); }
    return this.au; }

  set(patch) { Object.assign(this.state, patch); this.emit('change', this.state); }

  /** A newer version is known: banner + (once per version) a notification. */
  available(version, notes, extra = {}) {
    if (!version || su.cmpVersion(version, app.getVersion()) <= 0) return;
    const fresh = this.state.version !== version;
    const busy = ['downloading', 'verifying', 'installing', 'ready'].includes(this.state.status);
    this.set(Object.assign({ version, notes: su.notesLines(notes) }, busy && !fresh ? {} : { status: 'available', error: null }, extra));
    if (fresh) this.emit('available', version); }

  onError(err) {
    const msg = String(err && err.message || err);
    /* 401/404/503 from the workspace: signed out, nothing mirrored yet, or GitHub not connected. Not an app error. */
    const benign = /\b(401|403|404|503)\b|Cannot find channel|net::ERR_(INTERNET_DISCONNECTED|NETWORK_CHANGED|NAME_NOT_RESOLVED|CONNECTION_REFUSED|TIMED_OUT)/i.test(msg);
    if (!benign) this.report('updater', err instanceof Error ? err : new Error(msg), { feed: this.feed, mode: this.mode }, 'warning');
    /* a background check or download failed: nothing to show, the click retries (and falls back to the manual download) */
    if (!this.state.want) { this.set({ bg: false, status: this.state.status === 'ready' ? 'ready' : (this.state.version ? 'available' : 'idle') }); return; }
    this.fail(msg); }

  /** The one-click update failed: keep tracking as it is and offer the manual download. */
  fail(msg, err) {
    this.set({ status: 'error', want: false, bg: false, error: friendly(msg) });
    if (err) this.report('updater', err instanceof Error ? err : new Error(String(err)), { feed: this.feed, mode: this.mode }, 'warning'); }

  /** Point the updater at the signed-in workspace; call after sign-in and whenever the token changes. */
  configure(base, token) {
    this.feed = base ? base.replace(/\/+$/, '') + '/api/agent/updates/' : '';
    this.token = token || '';
    const au = this.load();
    if (!au || !this.feed) return;
    const headers = this.headers();
    au.setFeedURL({ provider: 'generic', url: this.feed, channel: 'latest', requestHeaders: headers });
    au.requestHeaders = headers; }

  headers() { return { Authorization: 'Bearer ' + this.token, 'X-Agent-Token': this.token, 'X-App-Version': app.getVersion(), 'User-Agent': `NiyantaTracker/${app.getVersion()} (${process.platform}; ${process.arch})` }; }

  /** /api/agent/version said a newer version exists: find out what the click will install. */
  async check(offer) {
    if (offer) this.offer = offer;
    await this.signing;
    if (!this.feed || !this.token || this.busy) return this.state;
    if (['downloading', 'verifying', 'installing', 'ready'].includes(this.state.status)) return this.state;
    this.busy = true;
    try {
      if (this.mode === 'electron' && this.load()) await this.au.checkForUpdates();
      else {
        let feed = null;
        try { feed = su.parseFeed(await this.text(FEED[process.platform] || 'latest-linux.yml')); } catch (e) { if (this.mode === 'mac') throw e; }
        if (this.mode === 'mac') this.macFeed = feed;
        const v = feed && feed.version || (this.offer && this.offer.latest);
        if (v && su.cmpVersion(v, app.getVersion()) > 0) this.available(v, feed && feed.releaseNotes);
        else if (!this.state.want) this.set({ status: 'current' }); } }
    catch (e) {
      /* the feed could not be read: still tell the person, the click falls back to the manual download */
      if (this.offer && this.offer.latest) this.available(this.offer.latest, null);
      if (this.mode !== 'electron' && !/\b(401|403|404|503)\b/.test(String(e && e.message))) this.report('updater', e, { feed: this.feed, mode: this.mode }, 'warning'); }
    finally { this.busy = false; }
    return this.state; }

  /* ---------- the click ---------- */

  /** "Update now": download (progress), verify, then emit 'restart' (main.js pauses tracking and calls apply()). */
  async updateNow() {
    if (this.state.want && this.state.status !== 'error') return { ok: true };
    if (!this.state.version && this.offer && this.offer.latest) this.state.version = this.offer.latest;
    await this.signing;
    try {
      if (this.mode === 'electron' && this.load()) {
        this.set({ want: true, error: null });
        if (this.state.status === 'ready') { this.emit('restart'); return { ok: true }; }
        this.set({ status: 'downloading' });
        if (!this.state.bg) this.au.downloadUpdate().catch(e => this.onError(e));
        return { ok: true }; }
      if (this.mode === 'mac') { this.set({ want: true, error: null, status: 'downloading', percent: 0 }); await this.prepareMac(); this.emit('restart'); return { ok: true }; }
      if (this.mode === 'deb') { this.set({ want: true, error: null }); return await this.manualDownload('deb'); }
      return await this.manualDownload(); }
    catch (e) { this.fail(e.message, e); return { ok: false, error: this.state.error }; } }

  /** macOS: zip for this Mac -> sha512 -> ditto -x -k -> the .app inside, ready for the helper. */
  async prepareMac() {
    if (!this.macFeed) this.macFeed = su.parseFeed(await this.text('latest-mac.yml'));
    if (su.cmpVersion(this.macFeed.version, app.getVersion()) <= 0) throw new Error('You already have the newest version.');
    const arch = process.arch === 'arm64' || app.runningUnderARM64Translation ? 'arm64' : 'x64';
    const f = su.pickMacZip(this.macFeed.files, arch);
    if (!f) throw new Error('This release has no macOS update for ' + (arch === 'arm64' ? 'Apple silicon' : 'Intel') + ' Macs.');
    if (!f.sha512) throw new Error('The update file has no checksum.');
    const work = fs.mkdtempSync(path.join(os.tmpdir(), 'niyanta-update-'));
    try {
      const zip = path.join(work, f.name);
      await this.download(f.name, zip, f.size);
      this.set({ status: 'verifying' });
      if (await su.sha512File(zip) !== f.sha512) throw new Error('The downloaded update is damaged (checksum mismatch). Please try again.');
      const out = path.join(work, 'app');
      fs.mkdirSync(out);
      await run('/usr/bin/ditto', ['-x', '-k', zip, out]);
      const bundle = fs.readdirSync(out).find(n => n.endsWith('.app'));
      if (!bundle) throw new Error('The update does not contain the app.');
      fs.rmSync(zip, { force: true });
      this.prepared = { source: path.join(out, bundle), work }; }
    catch (e) { fs.rmSync(work, { recursive: true, force: true }); throw e; }
    this.set({ status: 'ready', percent: 100 }); }

  /**
   * After tracking is paused: install and restart. Returns 'self' (electron-updater quits the app), 'quit' (the caller quits;
   * the macOS helper takes over) or false (could not start; the caller relaunches the current version).
   */
  apply() {
    try {
      this.set({ status: 'installing' });
      if (this.mode === 'electron' && this.au) {
        this.au.quitAndInstall(true, true);
        return this.au.quitAndInstallCalled === false ? false : 'self'; }
      if (this.mode === 'mac' && this.prepared) {
        const appPath = su.macAppPath(process.execPath);
        const canWrite = p => { try { fs.accessSync(p, fs.constants.W_OK); return true; } catch (e) { return false; } };
        const t = su.macTarget(appPath, os.homedir(), canWrite);
        const log = path.join(app.getPath('userData'), 'logs', 'update.log');
        fs.mkdirSync(path.dirname(log), { recursive: true });
        const script = path.join(this.prepared.work, 'update.sh');
        fs.writeFileSync(script, su.macHelperScript({ pid: process.pid, source: this.prepared.source, target: t.target, work: this.prepared.work, log }), { mode: 0o700 });
        const child = spawn('/bin/bash', [script], { detached: true, stdio: 'ignore' });
        child.on('error', e => this.report('updater', e, { step: 'helper' }, 'warning'));
        child.unref();
        return 'quit'; } }
    catch (e) { this.fail(e.message, e); }
    return false; }

  /* ---------- manual download (fallback, .deb, development) ---------- */

  /** Save the installer for this computer to Downloads and open it (no browser, so no browser or SmartScreen warning). */
  async manualDownload(kindIn) {
    const kind = kindIn || ({ win32: 'windows', darwin: 'mac' }[process.platform] || (this.mode === 'deb' ? 'deb' : 'linux'));
    const arch = process.platform === 'darwin' && (process.arch === 'arm64' || app.runningUnderARM64Translation) ? 'arm64' : (process.arch === 'arm64' ? 'arm64' : 'x64');
    const f = su.pickInstaller(this.offer && this.offer.files, kind, arch);
    if (!f) throw new Error('Your workspace does not offer an installer for this computer yet.');
    let dir = app.getPath('downloads');
    try { fs.mkdirSync(dir, { recursive: true }); } catch (e) { dir = os.tmpdir(); }
    const dest = path.join(dir, f.name);
    this.set({ status: 'downloading', percent: 0, error: null, want: true, manual: null });
    await this.download(f.name, dest, f.size);
    if (kind === 'linux') fs.chmodSync(dest, 0o755);
    if (kind === 'mac') await run('/usr/bin/xattr', ['-cr', dest]).catch(() => {});
    const manual = { kind, file: f.name, path: dest, folder: dir, command: kind === 'deb' ? 'sudo apt install ' + su.sq(dest) : null };
    this.set({ status: 'manual', want: false, percent: 100, manual });
    this.open(kind);
    return { ok: true }; }

  /** Open the downloaded installer again (or show it in its folder for an AppImage). */
  open(kind) {
    const m = this.state.manual;
    if (!m || !fs.existsSync(m.path)) return false;
    if ((kind || m.kind) === 'linux') { shell.showItemInFolder(m.path); return true; }
    shell.openPath(m.path).then(err => { if (err) shell.showItemInFolder(m.path); });
    return true; }

  /** The restart did not bring a newer version (the tracker noticed after the restart). */
  notInstalled(version) { if (version) this.state.version = version; this.fail('The update could not be installed automatically.'); }

  /* ---------- HTTP (Electron's network stack: system proxy and certificates) ---------- */

  async text(name) {
    const res = await net.fetch(this.feed + encodeURIComponent(name), { headers: this.headers(), cache: 'no-store' });
    if (!res.ok) throw new Error(`Update file ${name}: HTTP ${res.status}`);
    return res.text(); }

  /** Stream a file from the workspace feed to `dest` (via dest.part) with progress. */
  async download(name, dest, size) {
    const res = await net.fetch(this.feed + encodeURIComponent(name), { headers: this.headers(), cache: 'no-store' });
    if (!res.ok || !res.body) throw new Error(res.status === 404 ? 'The new version is not on your workspace any more. Check again in a minute.' : `Download failed (HTTP ${res.status}).`);
    const total = +res.headers.get('content-length') || size || 0;
    const part = dest + '.part';
    const out = fs.createWriteStream(part);
    const reader = res.body.getReader();
    let got = 0; let last = -1;
    try {
      for (;;) {
        const { done, value } = await reader.read();
        if (done) break;
        got += value.length;
        if (!out.write(Buffer.from(value))) await new Promise(r => out.once('drain', r));
        const pc = total ? Math.min(99, Math.floor(got * 100 / total)) : 0;
        if (pc !== last) { last = pc; this.set({ percent: pc }); } }
      await new Promise((resolve, reject) => out.end(err => err ? reject(err) : resolve())); }
    catch (e) { out.destroy(); fs.rmSync(part, { force: true }); throw new Error('The download was interrupted. Check your connection and try again.'); }
    if (total && got !== total) { fs.rmSync(part, { force: true }); throw new Error('The download was incomplete. Please try again.'); }
    fs.renameSync(part, dest); } }

function run(cmd, args) { return new Promise((resolve, reject) => execFile(cmd, args, { timeout: 300000 }, (err, out, errOut) => err ? reject(new Error(`${path.basename(cmd)} failed: ${String(errOut || err.message).trim().slice(0, 200)}`)) : resolve(out))); }

function friendly(msg) {
  const m = String(msg || '');
  if (/net::ERR_|ENOTFOUND|ECONN|ETIMEDOUT|Cannot reach/i.test(m)) return 'Cannot reach your workspace. Check your connection and try again.';
  if (/\b(401|403)\b/.test(m)) return 'Your workspace did not allow the download. Sign in again and retry.';
  if (/code signature|not signed|SecCodeCheckValidity|ShipIt/i.test(m)) return 'macOS refused the automatic update.';
  return m.length > 200 ? m.slice(0, 197) + '...' : m; }

module.exports = { Updater };
