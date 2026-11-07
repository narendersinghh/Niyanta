'use strict';
/* Niyanta Tracker: main process. Single instance, tray icon + menu, compact window, tracking dot on the taskbar / dock icon,
   IPC bridge, auto-launch, power events, sync of the offline queue before quitting. */
const path = require('path');
const fs = require('fs');
const os = require('os');
const { app, BrowserWindow, Tray, Menu, Notification, ipcMain, shell, nativeTheme, nativeImage, powerMonitor, dialog, clipboard } = require('electron');
const errors = require('./errors');
const store = require('./store');
const capture = require('./capture');
const toast = require('./toast');
const badge = require('./badge');
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

/* A compact tool window: resizable a little (taller for long project lists), never maximized or full screen. */
const SIZE = { width: 384, height: 700, minWidth: 360, minHeight: 600, maxWidth: 460, maxHeight: 900 };

function createWindow() {
  win = new BrowserWindow(Object.assign({}, SIZE, {
    show: false, resizable: true, maximizable: false, fullscreenable: false,
    title: 'Niyanta Tracker', icon: asset('renderer', 'icon.png'), autoHideMenuBar: true,
    backgroundColor: nativeTheme.shouldUseDarkColors ? '#0E0D1C' : '#F4F4FA',
    webPreferences: { preload: asset('preload', 'preload.js'), contextIsolation: true, sandbox: true, nodeIntegration: false, spellcheck: false, devTools: !app.isPackaged } }));
  /* some Linux window managers ignore maximizable:false (double-click on the title bar) */
  win.on('maximize', () => win.unmaximize());
  win.on('enter-full-screen', () => win.setFullScreen(false));
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
  updateTray(ui);
  indicator(!!(ui && ui.screen === 'tracker' && ui.tracking)); }

/* ---------- tracking dot on the taskbar / dock icon ---------- */

/**
 * While tracking (not paused, idle or stopped) a small mint dot sits on the app icon and pulses very slowly (two frames, full
 * and 70% opacity, swapped every 4 s): Windows taskbar overlay icon, macOS dock icon, Linux window icon (shown by the
 * taskbars of KDE, Xfce, Cinnamon and others; the tray icon shows the state everywhere).
 */
const ind = { on: false, frame: 0, timer: null, frames: null, base: null };
function indicatorFrames() {
  if (ind.frames) return ind.frames;
  if (process.platform === 'win32') {
    ind.frames = badge.FRAMES.map(a => nativeImage.createFromBitmap(badge.dot(32, a), { width: 32, height: 32, scaleFactor: 2 }));
  } else {
    const src = nativeImage.createFromPath(asset('renderer', 'icon.png'));
    ind.base = src.isEmpty() ? null : src.resize({ width: 256, height: 256, quality: 'best' });
    if (!ind.base) return (ind.frames = []);
    const { width, height } = ind.base.getSize();
    const bmp = ind.base.toBitmap();
    ind.frames = badge.FRAMES.map(a => nativeImage.createFromBitmap(badge.onIcon(bmp, width, height, a), { width, height })); }
  return ind.frames; }

function showFrame(i) {
  try {
    const f = indicatorFrames()[i];
    if (!f) return;
    if (process.platform === 'win32') { if (win && !win.isDestroyed()) win.setOverlayIcon(f, 'Tracking'); }
    else if (process.platform === 'darwin') { if (app.dock) app.dock.setIcon(f); }
    else if (win && !win.isDestroyed()) win.setIcon(f); }
  catch (e) { errors.report('indicator', e, {}, 'warning'); stopIndicator(); } }

function stopIndicator() { clearInterval(ind.timer); ind.timer = null; }

function indicator(on) {
  if (on === ind.on) return;
  ind.on = on;
  stopIndicator();
  if (on) { ind.frame = 0; showFrame(0); ind.timer = setInterval(() => { ind.frame = 1 - ind.frame; showFrame(ind.frame); }, badge.PULSE_MS); return; }
  try {
    if (process.platform === 'win32') { if (win && !win.isDestroyed()) win.setOverlayIcon(null, ''); }
    else if (process.platform === 'darwin') { if (app.dock && ind.base) app.dock.setIcon(ind.base); }
    else if (win && !win.isDestroyed()) win.setIcon(asset('renderer', 'icon.png')); }
  catch (e) { errors.report('indicator', e, {}, 'warning'); } }

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
    tone();
    idleNote = new Notification({ title: 'Niyanta Tracker', body: `Tracking paused after ${p.minutes} min idle. Resume when you are back.`, silent: true });
    idleNote.on('click', () => showWindow());
    idleNote.show(); }
  catch (e) { errors.report('notification', e, {}, 'warning'); } }

/** "Message alert tone" (on by default): a short chime played by the window with the app's own banners and notifications
    (not with the screenshot notice). System notifications are always silent so the tone plays once. */
function tone() { if (store.get('alertTone', true) !== false && win && !win.isDestroyed()) win.webContents.send('tracker:tone'); }

/**
 * Pomodoro reminder / break over: banner in the window plus a system notification (click opens the window; on macOS the
 * notification also has the buttons, elsewhere they are in the window).
 */
let pomoNote = null;
function pomodoroNote(kind, minutes) {
  tone();
  if (!Notification.isSupported()) return;
  try {
    if (pomoNote) pomoNote.close();
    const due = kind === 'due';
    const mac = process.platform === 'darwin';
    const actions = !mac ? [] : (due ? ['Take a break', 'Snooze 10 min'] : ['Resume']).map(text => ({ type: 'button', text }));
    pomoNote = new Notification({ title: due ? `Time for a ${minutes}-minute break` : 'Break over', body: due ? `You have tracked ${tracker.pomo.prefs.work} minutes without a break.` : 'Resume tracking when you are ready.', silent: true, actions });
    pomoNote.on('click', () => showWindow());
    pomoNote.on('action', (_e, i) => { if (!due) tracker.action('resume', {}); else if (i === 0) tracker.takeBreak(); else tracker.snoozeBreak(); });
    pomoNote.show(); }
  catch (e) { errors.report('notification', e, {}, 'warning'); } }

/**
 * A newer version (checked on start, every 30 minutes and after sleep / unlock): once per version, an OS notification and the
 * window pops up (without taking the focus) with the update prompt; the update card stays at the top until it is installed.
 */
let updateNote = null;
function updateAvailable(v) {
  if (!v || store.get('updateNotified') === v) return;
  store.set('updateNotified', v);
  tone();
  if (Notification.isSupported()) {
    try {
      if (updateNote) updateNote.close();
      updateNote = new Notification({ title: `Niyanta Tracker ${v} is available`, body: 'Click Update now in the app. It restarts by itself and your tracking continues.', silent: true });
      updateNote.on('click', () => showWindow());
      updateNote.show(); }
    catch (e) { errors.report('notification', e, {}, 'warning'); } }
  if (!win) createWindow();
  if (process.platform === 'darwin' && app.dock) app.dock.show();
  if (win.isMinimized()) win.restore();
  if (!win.isVisible()) win.showInactive(); }

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
  tray.setToolTip(`Niyanta Tracker · ${label}${ui.update && ui.update.available ? ' · update available' : ''}${st ? ' · ' + hm(st.totals.tracked) + ' today' : ''}${proj ? ' · ' + proj : ''}${ui.monitoring && ui.monitoring.on && st && st.state === 'tracking' ? ' · screenshots on' : ''}`);
  if (process.platform === 'darwin') tray.setTitle(st && st.state === 'tracking' ? ' ' + hm(st.totals.tracked) : '');
  const up = ui.update || {};
  const updating = up.want && ['downloading', 'verifying', 'ready', 'installing'].includes(up.status);
  const key = [kind, st && st.state, proj, up.available, up.latest, up.auto, updating, ui.screen].join('|');
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
    if (up.available && updating) items.push({ label: `Updating to ${up.latest}...`, enabled: false });
    else if (up.available && up.auto) items.push({ label: `Update now to ${up.latest}`, click: () => { showWindow(); tracker.updateNow(); } });
    else if (up.available) items.push({ label: `Download version ${up.latest}`, click: () => { showWindow(); tracker.downloadInstaller(); } }); }
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
    if (key === 'tone') store.set('alertTone', !!value);
    tracker.changed();
    return { ok: true }; });
  h('pomodoro', ({ op, prefs }) => {
    if (op === 'save') return tracker.setPomodoro(prefs);
    if (op === 'break') return tracker.takeBreak();
    if (op === 'snooze') return tracker.snoozeBreak();
    if (op === 'dismiss') return tracker.dismissBreakOver();
    return { ok: false }; });
  h('open', what => { openTarget(what); return { ok: true }; });
  h('updates', () => tracker.checkUpdates(true));
  h('installUpdate', () => tracker.updateNow());
  h('updatePrompted', v => tracker.updatePrompted(v));
  h('meeting', on => tracker.setMeeting(!!on));
  h('quitAnyway', () => tracker.quitAnyway());
  h('downloadInstaller', () => tracker.downloadInstaller());
  h('openInstaller', () => ({ ok: tracker.updater.open() }));
  h('restartApp', () => { restartToUpdate(false); return { ok: true }; });
  h('copy', text => { clipboard.writeText(String(text || '').slice(0, 2000)); return { ok: true }; });
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
/*
 * Quitting pauses tracking first, then (sync = a normal quit) sends what is still in the offline queue: after 1.2 s the
 * window shows "Syncing your data to the server. The app will close by itself when done." with progress and Quit anyway
 * (up to 60 s; whatever is left stays queued for the next start). Then it quits for real (app.quit, so a downloaded update
 * installs on the way out). Update restarts and system shutdowns do not wait for the sync (the queue is saved on disk).
 */
let closing = null;
async function prepareQuit(wait, sync = false) {
  closing = closing || (async () => {
    try { await tracker.beforeQuit(wait); } catch (err) { /* quitting anyway */ }
    if (sync) { try { await tracker.syncBeforeQuit(); } catch (err) { /* quitting anyway */ } }
    toast.destroy();
    quitting = true;
  })();
  return closing; }

app.on('before-quit', async e => {
  if (quitting) return;
  e.preventDefault();
  await prepareQuit(undefined, true);
  app.quit(); });

/**
 * The update is downloaded and verified (or the person restarts after installing a .deb): remember whether tracking was on,
 * pause it (as on every quit, waiting up to 15 s for the server), then install and restart. electron-updater quits by itself
 * ('self'); the macOS helper needs us to quit ('quit'); when the install could not start, the current version restarts and
 * the tracker shows the manual download. Tracking resumes after the restart (tracker.afterUpdateRestart).
 */
let restarting = false;
async function restartToUpdate(install = true) {
  if (restarting || !tracker) return;
  restarting = true;
  tracker.markUpdateRestart();
  await prepareQuit(15000);
  const how = install ? tracker.updater.apply() : false;
  if (how === 'self') { setTimeout(() => { app.relaunch(); app.exit(0); }, 20000); return; }
  if (how !== 'quit') app.relaunch();
  app.quit(); }

app.whenReady().then(async () => {
  tracker = new Tracker(errors.report);
  errors.attach(tracker);
  tracker.on('change', push);
  tracker.on('idle-return', p => idleReturn(p));
  tracker.on('pomodoro', (kind, m) => pomodoroNote(kind, m));
  tracker.on('alert', () => tone());
  tracker.on('consent-needed', () => showWindow());
  tracker.on('update-available', v => updateAvailable(v));
  tracker.on('update-restart', () => restartToUpdate(true));
  tracker.on('quit-sync-show', () => showWindow());
  tracker.on('app-moved', () => { if (store.get('launchAtLogin', true)) setLaunchAtLogin(true); });
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
