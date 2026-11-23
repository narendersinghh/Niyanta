'use strict';
/*
 * In-app updates from the workspace the person signed in to (<workspace>/api/agent/updates/, device token). The workspace
 * mirrors the private GitHub release, so nothing here talks to GitHub. On start and every 6 hours the tracker asks
 * /api/agent/version; when a newer version exists the window shows "Niyanta Tracker X is available" with one button,
 * Update now, plus one OS notification per version. Nothing is downloaded, installed or restarted without that click.
 * How the click updates, per install:
 * - Windows (NSIS), Linux AppImage, macOS with a Developer ID signature: electron-updater (generic provider). The click
 *   downloads the update (progress bar), electron-updater checks its sha512, then quitAndInstall(silent, run after). No
 *   installer is fetched in the background and none runs on a normal quit (autoDownload and autoInstallOnAppQuit off): an
 *   unsigned app that drops and silently starts an .exe on its own looks like a dropper to antivirus behaviour rules.
 * - macOS ad-hoc signed (no Developer ID, Squirrel.Mac refuses it): own updater. Downloads the zip for this Mac from
 *   latest-mac.yml, checks its sha512, extracts it with ditto and starts a detached helper (selfupdate.macHelperScript) that
 *   waits for the app to quit, swaps the bundle, runs xattr -cr and opens the new version. App downloads have no quarantine
 *   flag, so Gatekeeper does not block it.
 * - Microsoft Store (process.windowsStore, the optional appx build): the Store updates the app; the click opens the Store's
 *   downloads and updates page (Store policy: an app from the Store never updates itself).
 * - Windows MSI install (no NSIS uninstaller next to the exe, no portable marker: selfupdate.windowsInstallKind):
 *   electron-updater only knows the NSIS setup, so the click downloads the new .msi from the workspace to Downloads, checks
 *   it against the sha512 the workspace recorded when it mirrored the release (required: no checksum, no install), opens
 *   it with Windows Installer (shell.openPath: its normal window, no msiexec command line, nothing silent) and the app
 *   quits, so the installer can replace it. The person clicks through it; its last page starts the new version.
 * - Windows portable zip (resources/niyanta-portable): nothing to install. The click opens the workspace's download page
 *   (Time Tracking › Get the desktop app) to get the new zip ('download-page', main.js opens it).
 * - Linux .deb: downloads the new .deb to Downloads and opens it with the system installer (and shows the apt command).
 * - Anything failing: "Download the new version" saves the installer to Downloads (inside the app, no browser) and opens it,
 *   with short steps. Development builds only offer that manual download.
 * The caller (main.js) pauses tracking before the restart and the tracker resumes it after (tracker.afterUpdateRestart).
 */
const fs = require('fs');
const os = require('os');
const path = require('path');
const { execFile, spawn } = require('child_process');
const { app, shell } = require('electron');
const { EventEmitter } = require('events');
const su = require('./selfupdate');
const { rawRequest, readBody, userAgent } = require('./api');
const { safeFileName } = require('./guard');

const FEED = { win32: 'latest.yml', darwin: 'latest-mac.yml', linux: 'latest-linux.yml' };
const EXTS = ['exe', 'msi', 'dmg', 'zip', 'appimage', 'deb'];
const MAX_FEED_BYTES = 1048576;
const MAX_DOWNLOAD_BYTES = 600 * 1048576;
const STALL_MS = 60000;

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

  /** electron: electron-updater; mac: own macOS updater; deb: download + system installer; store: the Microsoft Store
      updates it; msi: download the new .msi and open it; portable: the workspace's download page; manual: only "Download". */
  static initialMode() {
    if (!app.isPackaged) return 'manual';
    if (process.windowsStore) return 'store';
    if (process.platform === 'win32') {
      const kind = su.windowsInstallKind({ windowsStore: !!process.windowsStore, execPath: process.execPath, resourcesPath: process.resourcesPath, exists: p => fs.existsSync(p) });
      return { nsis: 'electron', msi: 'msi', portable: 'portable' }[kind] || 'manual'; }
    if (process.platform === 'linux') return process.env.APPIMAGE ? 'electron' : 'deb';
    if (process.platform === 'darwin') return process.env.NIYANTA_MAC_UPDATER === 'squirrel' ? 'electron' : 'mac';
    return 'manual'; }

  get auto() { return ['electron', 'mac', 'deb', 'msi'].includes(this.mode); }

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
      autoUpdater.autoDownload = false;
      autoUpdater.autoInstallOnAppQuit = false;
      autoUpdater.allowPrerelease = false;
      autoUpdater.allowDowngrade = false;
      autoUpdater.logger = { info: () => {}, warn: () => {}, debug: () => {}, error: () => {} };
      autoUpdater.on('update-not-available', () => { if (!this.state.want) this.set({ status: 'current', percent: 0, bg: false }); });
      autoUpdater.on('update-available', info => this.available(info && info.version, info && info.releaseNotes, { bg: false }));
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
    const benign = /\b(401|403|404|503)\b|Cannot find channel|net::ERR_(INTERNET_DISCONNECTED|NETWORK_CHANGED|NAME_NOT_RESOLVED|CONNECTION_REFUSED|CONNECTION_RESET|CONNECTION_CLOSED|CONNECTION_TIMED_OUT|TIMED_OUT|QUIC_PROTOCOL_ERROR|HTTP2_PROTOCOL_ERROR|ADDRESS_UNREACHABLE|PROXY_CONNECTION_FAILED)/i.test(msg);
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

  headers() { return { Authorization: 'Bearer ' + this.token, 'X-Agent-Token': this.token, 'X-App-Version': app.getVersion(), 'User-Agent': userAgent() }; }

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
    if (this.mode === 'store') return this.openStore();
    if (this.mode === 'portable') return this.downloadPage();
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
      if (this.mode === 'msi') { this.set({ want: true, error: null, status: 'downloading', percent: 0 }); await this.prepareMsi(); this.emit('restart'); return { ok: true }; }
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
    const name = safeFileName(f.name, ['zip']);
    if (!name) throw new Error('The update file has an unexpected name.');
    const work = fs.mkdtempSync(path.join(os.tmpdir(), 'niyanta-update-'));
    try {
      const zip = path.join(work, name);
      await this.download(name, zip, f.size);
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
   * Windows MSI install: the .msi of the offered version (listed under `windows` by /api/agent/version, with the sha512 the
   * workspace recorded) -> Downloads -> sha512 (required) -> ready for apply(), which opens it.
   */
  async prepareMsi() {
    const arch = process.arch === 'arm64' ? 'arm64' : 'x64';
    const f = su.pickInstaller(this.offer && this.offer.files, 'msi', arch);
    if (!f) throw new Error('Your workspace does not offer the MSI installer for this version yet.');
    if (f.version && su.cmpVersion(f.version, app.getVersion()) <= 0) throw new Error('You already have the newest version.');
    if (typeof f.sha512 !== 'string' || f.sha512.length < 64) throw new Error('Your workspace did not send a checksum for the MSI installer, so it was not installed.');
    const name = safeFileName(f.name, ['msi']);
    if (!name) throw new Error('The installer has an unexpected file name.');
    let dir = app.getPath('downloads');
    try { fs.mkdirSync(dir, { recursive: true }); } catch (e) { dir = os.tmpdir(); }
    const dest = path.join(dir, name);
    await this.download(name, dest, f.size);
    this.set({ status: 'verifying' });
    if (await su.sha512File(dest) !== f.sha512) { fs.rmSync(dest, { force: true }); throw new Error('The downloaded installer is damaged (checksum mismatch). Please try again.'); }
    this.prepared = { msi: dest };
    this.set({ status: 'ready', percent: 100 }); }

  /**
   * After tracking is paused: install and restart. Returns 'self' (electron-updater quits the app), 'quit' (the caller quits;
   * the macOS helper or the MSI installer takes over) or false (could not start; the caller relaunches the current version).
   * The MSI case answers with a promise: Windows must have opened the installer before the app quits.
   */
  apply() {
    try {
      this.set({ status: 'installing' });
      if (this.mode === 'electron' && this.au) {
        this.au.quitAndInstall(true, true);
        return this.au.quitAndInstallCalled === false ? false : 'self'; }
      if (this.mode === 'msi' && this.prepared && this.prepared.msi) {
        const msi = this.prepared.msi;
        /* Windows Installer's own window (the .msi file's default action), never a command line or a silent switch */
        return shell.openPath(msi).then(err => {
          if (!err) return 'quit';
          this.fail('Windows could not open the installer (' + err + '). Open it from your Downloads folder.');
          return false; }, e => { this.fail(e.message, e); return false; }); }
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
    if (this.mode === 'store') return this.openStore();
    if (this.mode === 'portable') return this.downloadPage();
    const kind = kindIn || ({ win32: this.mode === 'msi' ? 'msi' : 'windows', darwin: 'mac' }[process.platform] || (this.mode === 'deb' ? 'deb' : 'linux'));
    const arch = process.platform === 'darwin' && (process.arch === 'arm64' || app.runningUnderARM64Translation) ? 'arm64' : (process.arch === 'arm64' ? 'arm64' : 'x64');
    const f = su.pickInstaller(this.offer && this.offer.files, kind, arch);
    if (!f) throw new Error('Your workspace does not offer an installer for this computer yet.');
    const name = safeFileName(f.name, EXTS);
    if (!name) throw new Error('The installer has an unexpected file name.');
    let dir = app.getPath('downloads');
    try { fs.mkdirSync(dir, { recursive: true }); } catch (e) { dir = os.tmpdir(); }
    const dest = path.join(dir, name);
    this.set({ status: 'downloading', percent: 0, error: null, want: true, manual: null });
    await this.download(name, dest, f.size);
    /* the installer is checked against the sha512 the server offers (version answer or the latest*.yml of this platform) */
    const sha = await this.expectedSha(name, f);
    if (sha) { this.set({ status: 'verifying' }); if (await su.sha512File(dest) !== sha) { fs.rmSync(dest, { force: true }); throw new Error('The downloaded installer is damaged (checksum mismatch). Please try again.'); } }
    else this.report('updater', new Error('No checksum offered for ' + name + '; the download was accepted over HTTPS from the workspace only.'), { kind }, 'warning');
    if (kind === 'linux') fs.chmodSync(dest, 0o755);
    if (kind === 'mac') await run('/usr/bin/xattr', ['-cr', dest]).catch(() => {});
    const manual = { kind, file: name, path: dest, folder: dir, command: kind === 'deb' ? 'sudo apt install ' + su.sq(dest) : null };
    this.set({ status: 'manual', want: false, percent: 100, manual });
    this.open(kind);
    return { ok: true }; }

  /** Portable zip: nothing to install in place; main.js opens the workspace's download page for the new zip. */
  downloadPage() {
    this.set({ status: 'available', want: false, error: null });
    this.emit('download-page');
    return { ok: true, page: true }; }

  /** Microsoft Store install: updates come from the Store (its downloads and updates page). */
  openStore() {
    shell.openExternal('ms-windows-store://downloadsandupdates').catch(() => {});
    this.set({ status: 'available', want: false, error: null });
    return { ok: true, store: true }; }

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

  /** A small text file (latest*.yml) from the feed; redirects off the workspace origin are refused (api.rawRequest). */
  async text(name) {
    const r = await rawRequest(this.feed + encodeURIComponent(name), { headers: this.headers(), timeout: 20000 });
    if (r.status < 200 || r.status >= 300) { r.req.abort(); throw new Error(`Update file ${name}: HTTP ${r.status}`); }
    return (await readBody(r, MAX_FEED_BYTES, 20000)).toString('utf8'); }

  /** The sha512 (base64) the server offers for `name`: in the version answer, else in this platform's latest*.yml. */
  async expectedSha(name, f) {
    if (f && typeof f.sha512 === 'string' && f.sha512.length >= 64) return f.sha512;
    try {
      const feed = this.macFeed && process.platform === 'darwin' ? this.macFeed : su.parseFeed(await this.text(FEED[process.platform] || 'latest-linux.yml'));
      const hit = (feed.files || []).find(x => x.name === name && x.sha512);
      return hit ? hit.sha512 : (feed.path && su.baseName(feed.path) === name && feed.sha512 ? feed.sha512 : null); }
    catch (e) { return null; } }

  /** Stream a file from the workspace feed to `dest` (via dest.part, mode 600) with progress; bounded in size and stall time. */
  async download(name, dest, size) {
    const r = await rawRequest(this.feed + encodeURIComponent(name), { headers: this.headers(), timeout: 30000 });
    if (r.status < 200 || r.status >= 300) { r.req.abort(); throw new Error(r.status === 404 ? 'The new version is not on your workspace any more. Check again in a minute.' : `Download failed (HTTP ${r.status}).`); }
    const total = +r.headers['content-length'] || size || 0;
    if (total > MAX_DOWNLOAD_BYTES) { r.req.abort(); throw new Error('The update file is unexpectedly large.'); }
    const part = dest + '.part';
    fs.rmSync(part, { force: true });
    const out = fs.createWriteStream(part, { mode: 0o600 });
    let got = 0; let last = -1;
    try {
      await new Promise((resolve, reject) => {
        let stall = null;
        const arm = () => { clearTimeout(stall); stall = setTimeout(() => { r.req.abort(); reject(new Error('stalled')); }, STALL_MS); };
        arm();
        r.res.on('data', chunk => {
          got += chunk.length;
          if (got > MAX_DOWNLOAD_BYTES) { r.req.abort(); reject(new Error('too large')); return; }
          arm();
          if (!out.write(chunk)) { r.res.pause(); out.once('drain', () => r.res.resume()); }
          const pc = total ? Math.min(99, Math.floor(got * 100 / total)) : 0;
          if (pc !== last) { last = pc; this.set({ percent: pc }); } });
        r.res.on('end', () => { clearTimeout(stall); out.end(err => err ? reject(err) : resolve()); });
        r.res.on('error', e => { clearTimeout(stall); reject(e); });
        r.res.on('aborted', () => { clearTimeout(stall); reject(new Error('aborted')); }); }); }
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
