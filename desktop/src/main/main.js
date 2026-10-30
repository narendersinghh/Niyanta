'use strict';
/* Niyanta Tracker: main process. Single instance, tray icon + menu, compact window, IPC bridge, auto-launch, power events. */
const path = require('path');
const fs = require('fs');
const os = require('os');
const { app, BrowserWindow, Tray, Menu, Notification, ipcMain, shell, nativeTheme, nativeImage, powerMonitor, dialog } = require('electron');
const errors = require('./errors');
const store = require('./store');
const capture = require('./capture');
const toast = require('./toast');
const { Tracker } = require('./tracker');

app.setName('Niyanta Tracker');
if (process.platform === 'win32') app.setAppUserModelId('app.niyanta.tracker');
if (process.env.NIYANTA_TRACKER_PROFILE) app.setPath('userData', path.resolve(process.env.NIYANTA_TRACKER_PROFILE));

if (!app.requestSingleInstanceLock()) { app.quit(); return; } // eslint-disable-line no-useless-return

errors.installGlobalHandlers();
let win = null;
let tray = null;
let tracker = null;
let quitting = false;
let lastUi = null;

const asset = (...p) => path.join(__dirname, '..', ...p);
const trayDir = () => app.isPackaged ? path.join(process.resourcesPath, 'tray') : path.join(__dirname, '..', '..', 'build', 'tray');

function createWindow() {
  win = new BrowserWindow({
    width: 380, height: 640, minWidth: 360, minHeight: 540, maxWidth: 520, show: false, resizable: true, maximizable: false, fullscreenable: false,
    title: 'Niyanta Tracker', icon: asset('renderer', 'icon.png'), autoHideMenuBar: true,
    backgroundColor: nativeTheme.shouldUseDarkColors ? '#0E0D1C' : '#F4F4FA',
    webPreferences: { preload: asset('preload', 'preload.js'), contextIsolation: true, sandbox: true, nodeIntegration: false, spellcheck: false, devTools: !app.isPackaged } });
  /* no menu bar at all on Windows/Linux (autoHideMenuBar would still show it on Alt and push the content down) */
  if (process.platform !== 'darwin') win.removeMenu(); else win.setMenuBarVisibility(false);
  win.loadFile(asset('renderer', 'index.html'));
  win.once('ready-to-show', () => { if (!process.argv.includes('--hidden')) win.show(); });
  win.on('session-end', () => tracker && tracker.onShutdown());
  win.on('focus', () => win.flashFrame(false));
  win.on('close', e => { if (!quitting) { e.preventDefault(); win.hide(); if (process.platform === 'darwin') app.dock && app.dock.hide(); } });
  win.webContents.setWindowOpenHandler(({ url }) => { if (/^https?:/.test(url)) shell.openExternal(url); return { action: 'deny' }; });
  win.webContents.on('will-navigate', (e, url) => { if (!url.startsWith('file:')) { e.preventDefault(); shell.openExternal(url); } }); }

function showWindow() {
  if (!win) createWindow();
  if (process.platform === 'darwin' && app.dock) app.dock.show();
  if (win.isMinimized()) win.restore();
  win.show(); win.focus(); }

function push(ui) {
  lastUi = ui;
  if (win && !win.isDestroyed()) win.webContents.send('tracker:state', ui);
  updateTray(ui); }

/**
 * Back after tracking paused itself (idle up to the maximum): the window shows a short message with Resume (non-blocking);
 * when the window is not focused, an OS notification says the same and opens the window on click. Nothing is asked.
 */
let idleNote = null;
function idleReturn(p) {
  const focused = win && !win.isDestroyed() && win.isVisible() && win.isFocused();
  if (focused || !Notification.isSupported()) return;
  try {
    if (idleNote) idleNote.close();
    idleNote = new Notification({ title: 'Niyanta Tracker', body: `Tracking paused after ${p.minutes} min idle. Resume when you are back.`, silent: true });
    idleNote.on('click', () => showWindow());
    idleNote.show(); }
  catch (e) { errors.report('notification', e, {}, 'warning'); } }

/* ---------- tray ---------- */

function trayImage(kind) {
  if (process.platform === 'darwin') {
    const img = nativeImage.createFromPath(path.join(trayDir(), 'trayTemplate.png'));
    img.setTemplateImage(true);
    return img; }
  const name = { tracking: 'tray-active.png', idle: 'tray-paused.png', paused: 'tray-paused.png', offline: 'tray-offline.png' }[kind] || 'tray.png';
  const img = nativeImage.createFromPath(path.join(trayDir(), name));
  return img.isEmpty() ? nativeImage.createFromPath(asset('renderer', 'icon.png')).resize({ width: 22 }) : img; }

const hm = s => { s = Math.max(0, Math.floor(s || 0)); return `${Math.floor(s / 3600)}h ${String(Math.floor(s % 3600 / 60)).padStart(2, '0')}m`; };
let trayKey = '';

function updateTray(ui) {
  if (!tray || !ui) return;
  const st = ui.state;
  const kind = ui.screen === 'signin' ? 'signed-out' : ui.chip;
  const label = { tracking: 'Tracking', idle: 'Idle', paused: 'Paused', offline: 'Offline', ended: 'Not started', stopped: 'Not tracking', not_started: 'Not started', 'signed-out': 'Signed out' }[kind] || 'Niyanta Tracker';
  const proj = st && st.running ? (st.running.project || (ui.projectsEnabled ? 'No project' : '')) : '';
  tray.setToolTip(`Niyanta Tracker · ${label}${ui.update && ui.update.ready ? ' · update ready' : ''}${st ? ' · ' + hm(st.totals.tracked) + ' today' : ''}${proj ? ' · ' + proj : ''}${ui.monitoring && ui.monitoring.on && st && st.state === 'tracking' ? ' · screenshots on' : ''}`);
  if (process.platform === 'darwin') tray.setTitle(st && st.state === 'tracking' ? ' ' + hm(st.totals.tracked) : '');
  const up = ui.update || {};
  const key = [kind, st && st.state, proj, up.available, up.ready, up.downloading, ui.screen].join('|');
  if (key === trayKey) return;
  trayKey = key;
  tray.setImage(trayImage(kind));
  const s = st ? st.state : 'signed-out';
  const items = [{ label: `${label}${proj ? ' · ' + proj : ''}`, enabled: false }, { type: 'separator' }, { label: 'Show Niyanta Tracker', click: showWindow }];
  if (ui.screen === 'tracker') {
    if (['not_started', 'ended'].includes(s)) items.push({ label: 'Start', click: () => tracker.action('start', tracker.selection) });
    if (s === 'tracking') items.push({ label: 'Pause', click: () => tracker.action('pause') });
    if (s === 'paused' || s === 'stopped') items.push({ label: 'Resume', click: () => tracker.action('resume', {}) });
    items.push({ type: 'separator' }, { label: 'Open Niyanta in browser', click: () => openTarget('web') });
    if (up.ready) items.push({ label: `Update ready, restart to install ${up.readyVersion}`, click: () => restartToUpdate() });
    else if (up.downloading) items.push({ label: `Downloading update ${up.latest}...`, enabled: false });
    else if (up.available) items.push({ label: `Download update ${up.latest}`, click: () => openTarget('download') }); }
  items.push({ type: 'separator' }, { label: 'Quit Niyanta Tracker', click: () => { app.quit(); } });
  tray.setContextMenu(Menu.buildFromTemplate(items)); }

function createTray() {
  tray = new Tray(trayImage('signed-out'));
  tray.setToolTip('Niyanta Tracker');
  tray.on('click', () => { if (win && win.isVisible() && win.isFocused()) win.hide(); else showWindow(); });
  updateTray(lastUi || tracker.ui()); }

/* ---------- auto-launch ---------- */

function setLaunchAtLogin(on) {
  store.set('launchAtLogin', !!on);
  try {
    if (process.platform === 'linux') {
      const dir = path.join(os.homedir(), '.config', 'autostart');
      const file = path.join(dir, 'niyanta-tracker.desktop');
      if (!on) { fs.rmSync(file, { force: true }); return; }
      const exec = process.env.APPIMAGE || process.execPath;
      fs.mkdirSync(dir, { recursive: true });
      fs.writeFileSync(file, `[Desktop Entry]\nType=Application\nName=Niyanta Tracker\nExec="${exec}" --hidden\nX-GNOME-Autostart-enabled=true\nTerminal=false\n`);
    } else {
      app.setLoginItemSettings({ openAtLogin: !!on, openAsHidden: true, args: ['--hidden'] }); }
  } catch (e) { errors.report('autostart', e, {}, 'warning'); } }

/* ---------- links ---------- */

function openTarget(what) {
  const ws = tracker.boot && tracker.boot.workspace;
  const base = (ws && ws.url) || store.get('workspaceUrl');
  const urls = { web: base, time: ws && ws.time_page, screenshots: ws && ws.screenshots_page, download: (tracker.update && tracker.update.url) || (ws && ws.time_page ? ws.time_page + '#desktop' : null) };
  if (what === 'permissions') return capture.openPermissionSettings();
  if (what === 'logs') return shell.showItemInFolder(errors.logFile());
  if (urls[what] && /^https?:\/\//.test(urls[what])) return shell.openExternal(urls[what]); }

/* ---------- IPC ---------- */

function registerIpc() {
  const h = (name, fn) => ipcMain.handle('tracker:' + name, async (e, arg) => {
    if (!win || e.sender !== win.webContents) return null;
    try { return await fn(arg); } catch (err) { errors.report('ipc:' + name, err); return { ok: false, error: err.message }; } });
  h('state', () => tracker.ui());
  h('signIn', d => tracker.signIn(d));
  h('signOut', () => tracker.signOut());
  h('action', ({ name, body }) => tracker.action(name, sanitizeSel(body)));
  h('select', sel => { tracker.setSelection(sanitizeSel(sel)); return { ok: true }; });
  h('consent', () => tracker.acceptConsent());
  h('pref', ({ key, value }) => {
    if (key === 'launchAtLogin') setLaunchAtLogin(value);
    if (key === 'notify' && tracker.settings.screenshot_notify_user_can_disable) store.set('notifyScreenshots', !!value);
    tracker.changed();
    return { ok: true }; });
  h('open', what => { openTarget(what); return { ok: true }; });
  h('updates', () => tracker.checkUpdates(true));
  h('installUpdate', () => ({ ok: restartToUpdate() }));
  h('refresh', async () => { await tracker.refreshBoot(); await tracker.refreshState(); return { ok: true }; });
  h('error', info => { const e = new Error(info.message); e.stack = info.stack || e.stack; errors.report('renderer', e); return { ok: true }; }); }

function sanitizeSel(b) {
  const out = {};
  if (!b || typeof b !== 'object') return out;
  if ('project_id' in b) { out.project_id = Number.isInteger(+b.project_id) && +b.project_id > 0 ? +b.project_id : null; out.task_id = Number.isInteger(+b.task_id) && +b.task_id > 0 ? +b.task_id : null; }
  if (typeof b.description === 'string') out.description = b.description.slice(0, 500);
  return out; }

/* ---------- lifecycle ---------- */

app.on('second-instance', () => showWindow());
app.on('activate', () => showWindow());
app.on('window-all-closed', e => { /* keep running in the tray */ });
/* Quitting pauses tracking first, then quits for real (app.quit, so a downloaded update installs on the way out). */
let closing = null;
async function prepareQuit() {
  closing = closing || (async () => {
    try { await tracker.beforeQuit(); } catch (err) { /* quitting anyway */ }
    toast.destroy();
    quitting = true;
  })();
  return closing; }

app.on('before-quit', async e => {
  if (quitting) return;
  e.preventDefault();
  await prepareQuit();
  app.quit(); });

/** "Restart to install": pause tracking, then let electron-updater quit, install and relaunch. */
function restartToUpdate() {
  const up = tracker && tracker.updateUi();
  if (!up || !up.ready) return false;
  prepareQuit().then(() => { if (!tracker.installUpdate()) app.quit(); });
  return true; }

app.whenReady().then(async () => {
  tracker = new Tracker(errors.report);
  errors.attach(tracker);
  tracker.on('change', push);
  tracker.on('idle-return', p => idleReturn(p));
  tracker.on('consent-needed', () => showWindow());
  tracker.on('update-ready', v => { if (tray && process.platform === 'win32') tray.displayBalloon({ title: 'Niyanta Tracker', content: `Version ${v} is ready. Restart the app to install it, or it installs when you quit.` }); });
  registerIpc();
  createWindow();
  createTray();
  for (const ev of ['suspend', 'resume', 'lock-screen', 'unlock-screen']) powerMonitor.on(ev, () => tracker.onPower(ev));
  /* Linux/macOS shutdown or log-out: delay it until tracking is paused and everything is saved (Windows: 'session-end') */
  powerMonitor.on('shutdown', e => { if (quitting) return; e.preventDefault(); tracker.onShutdown(); prepareQuit().then(() => app.quit()); });
  if (store.get('launchAtLogin') === undefined) setLaunchAtLogin(true);
  await tracker.restore();
  if (process.platform === 'darwin' && tracker.settings.screenshots_enabled && capture.screenPermission() === 'denied') {
    dialog.showMessageBox({ type: 'info', message: 'Allow screen recording', detail: 'Your organisation uses screenshots while you track time. Open System Settings › Privacy & Security › Screen Recording and turn on Niyanta Tracker, then restart the app.', buttons: ['Open Settings', 'Later'] })
      .then(r => { if (r.response === 0) capture.openPermissionSettings(); }); } });
