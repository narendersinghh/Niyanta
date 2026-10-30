'use strict';
/*
 * In-app updates without configuration. electron-updater (generic provider) reads the update files from the workspace the
 * person signed in to: <workspace>/api/agent/updates/latest*.yml, authenticated with the device token. The workspace mirrors
 * the private GitHub release, so the installer and its .blockmap also come from there. Updates download in the background
 * and install when the app quits ("Restart to install" does it right away).
 * Supported: Windows (NSIS), macOS (signed builds only; Squirrel.Mac refuses unsigned apps), Linux AppImage. Elsewhere
 * (unsigned macOS, .deb, development) the app falls back to "Download" which opens Time Tracking › Get the desktop app.
 */
const { app } = require('electron');
const { EventEmitter } = require('events');

class Updater extends EventEmitter {
  constructor(report) {
    super();
    this.report = report;
    this.au = null;
    this.state = { supported: false, status: 'idle', version: null, percent: 0, error: null };
    this.feed = '';
    this.token = '';
    this.busy = false;
    this.disabledReason = Updater.unsupportedReason();
    this.state.supported = !this.disabledReason;
  }

  static unsupportedReason() {
    if (!app.isPackaged) return 'development build';
    if (process.platform === 'linux' && !process.env.APPIMAGE) return 'installed from a .deb package';
    if (!['win32', 'darwin', 'linux'].includes(process.platform)) return 'unsupported platform';
    return null;
  }

  load() {
    if (this.au || !this.state.supported) return this.au;
    try {
      const { autoUpdater } = require('electron-updater');
      autoUpdater.autoDownload = true;
      autoUpdater.autoInstallOnAppQuit = true;
      autoUpdater.allowPrerelease = false;
      autoUpdater.allowDowngrade = false;
      autoUpdater.logger = { info: () => {}, warn: () => {}, debug: () => {}, error: () => {} };
      autoUpdater.on('checking-for-update', () => this.set({ status: 'checking', error: null }));
      autoUpdater.on('update-not-available', () => this.set({ status: 'current', version: null, percent: 0 }));
      autoUpdater.on('update-available', info => this.set({ status: 'downloading', version: info && info.version, percent: 0 }));
      autoUpdater.on('download-progress', p => { const pc = Math.round(p && p.percent || 0); if (pc !== this.state.percent) this.set({ percent: pc }); });
      autoUpdater.on('update-downloaded', info => this.set({ status: 'ready', version: info && info.version, percent: 100 }));
      autoUpdater.on('error', err => this.onError(err));
      this.au = autoUpdater;
    } catch (e) {
      this.state.supported = false; this.disabledReason = 'updater unavailable';
      this.report('updater', e, {}, 'warning');
    }
    return this.au;
  }

  set(patch) { Object.assign(this.state, patch); this.emit('change', this.state); }

  onError(err) {
    const msg = String(err && err.message || err);
    /* 401/404/503 from the workspace: signed out, nothing mirrored yet, or GitHub not connected. Not an app error. */
    const benign = /\b(401|403|404|503)\b|Cannot find channel|net::ERR_(INTERNET_DISCONNECTED|NETWORK_CHANGED|NAME_NOT_RESOLVED|CONNECTION_REFUSED|TIMED_OUT)/i.test(msg);
    /* Squirrel.Mac rejects unsigned builds: switch to the manual download for good. */
    const unsigned = process.platform === 'darwin' && /code signature|signature|not signed|SecCodeCheckValidity|ShipIt/i.test(msg);
    if (unsigned) { this.state.supported = false; this.disabledReason = 'unsigned macOS build'; }
    this.set({ status: this.state.status === 'ready' ? 'ready' : 'error', error: msg.slice(0, 300), supported: this.state.supported });
    if (!benign || unsigned) this.report('updater', err instanceof Error ? err : new Error(msg), { feed: this.feed, unsigned }, unsigned ? 'info' : 'warning');
  }

  /** Point the updater at the signed-in workspace; call after sign-in and whenever the token changes. */
  configure(base, token) {
    this.feed = base ? base.replace(/\/+$/, '') + '/api/agent/updates/' : '';
    this.token = token || '';
    const au = this.load();
    if (!au || !this.feed) return;
    const headers = { Authorization: 'Bearer ' + this.token, 'X-Agent-Token': this.token, 'X-App-Version': app.getVersion() };
    au.setFeedURL({ provider: 'generic', url: this.feed, channel: 'latest', requestHeaders: headers });
    au.requestHeaders = headers;
  }

  async check() {
    const au = this.load();
    if (!au || !this.feed || !this.token || this.busy || ['downloading', 'ready'].includes(this.state.status)) return this.state;
    this.busy = true;
    try { await au.checkForUpdates(); } catch (e) { this.onError(e); } finally { this.busy = false; }
    return this.state;
  }

  /** Quit and install the downloaded update now (the caller pauses tracking first). */
  install() {
    if (!this.au || this.state.status !== 'ready') return false;
    setImmediate(() => this.au.quitAndInstall(false, true));
    return true;
  }
}

module.exports = { Updater };
