'use strict';
/* Niyanta Tracker: main process. Single instance, tray icon + menu, compact window, tracking dot on the taskbar / dock icon,
   IPC bridge, auto-launch, power events, sync of the offline queue before quitting, niyanta-tracker:// sign-in links. */
const path = require('path');
const fs = require('fs');
const os = require('os');
const START = Date.now();
const { app, BrowserWindow, Tray, Menu, Notification, ipcMain, shell, nativeTheme, nativeImage, powerMonitor, dialog, clipboard, session, screen } = require('electron');
const errors = require('./errors');
const store = require('./store');
const guard = require('./guard');
const { Tracker } = require('./tracker');
const { Connect } = require('./connect');
const { normaliseUrl } = require('./api');
/* needed later or only in some cases (Screen Recording pane, the capture notice, the taskbar dot, uninstall): loaded on use */
const lazy = name => { let m = null; return () => (m = m || require(name)); };
const capture = lazy('./capture'), toast = lazy('./toast'), badge = lazy('./badge'), uninstall = lazy('./uninstall');

/* ---------- process hardening (before anything else runs) ---------- */
/* Every renderer runs in the Chromium sandbox (also set per window; the fuses of the packaged app turn off runAsNode,
   NODE_OPTIONS and --inspect). The variables below never reach a relaunch or the helper processes we spawn. */
app.enableSandbox();
for (const k of ['ELECTRON_RUN_AS_NODE', 'ELECTRON_ENABLE_LOGGING', 'NODE_OPTIONS', 'NODE_EXTRA_CA_CERTS']) delete process.env[k];

app.setName('Niyanta Tracker');
if (process.platform === 'win32') app.setAppUserModelId('app.niyanta.tracker');
if (process.env.NIYANTA_TRACKER_PROFILE) app.setPath('userData', path.resolve(process.env.NIYANTA_TRACKER_PROFILE));

if (!app.requestSingleInstanceLock()) { app.quit(); return; } // eslint-disable-line no-useless-return

/* ---------- niyanta-tracker:// links ("Sign in with your browser", src/main/connect.js) ---------- */
/* The scheme is registered by the installers too (package.json build.protocols: macOS CFBundleURLTypes, the Linux .desktop
   MimeType; build/installer.nsh: the Windows registry); here for development runs and AppImages. A link arrives with
   open-url (macOS, also before ready), the argv of a second instance (Windows, Linux) or the argv of the first launch.
   Everything is parsed strictly by guard.parseDeepLink before anything happens. */
function registerProtocol() {
  try {
    if (process.defaultApp && process.argv.length >= 2) app.setAsDefaultProtocolClient(guard.SCHEME, process.execPath, [path.resolve(process.argv[1])]);
    else app.setAsDefaultProtocolClient(guard.SCHEME);
    if (process.platform === 'linux' && process.env.APPIMAGE) linuxHandler(process.env.APPIMAGE); }
  catch (e) { errors.report('protocol', e, {}, 'warning'); } }
/** AppImage: a hidden .desktop entry that hands niyanta-tracker:// links to this AppImage, made the default with xdg-mime. */
function linuxHandler(exe) {
  const dir = path.join(os.homedir(), '.local', 'share', 'applications');
  const name = 'niyanta-tracker-url.desktop';
  fs.mkdirSync(dir, { recursive: true });
  fs.writeFileSync(path.join(dir, name), `[Desktop Entry]\nType=Application\nName=Niyanta Tracker\nExec=${guard.desktopExecArg(exe)} %u\nNoDisplay=true\nTerminal=false\nMimeType=x-scheme-handler/${guard.SCHEME};\n`, { mode: 0o644 });
  require('child_process').execFile('xdg-mime', ['default', name, 'x-scheme-handler/' + guard.SCHEME], { timeout: 10000 }, () => { /* best effort */ }); }
registerProtocol();
let connect = null;
let earlyLink = null;
const linkIn = argv => (Array.isArray(argv) ? argv : []).find(a => typeof a === 'string' && a.slice(0, guard.SCHEME.length + 1).toLowerCase() === guard.SCHEME + ':') || null;
function deepLink(url) {
  if (!connect || !connect.ready) { earlyLink = url; return; }
  connect.link(url).catch(e => errors.report('connect', e)); }
app.on('open-url', (e, url) => { e.preventDefault(); deepLink(url); });

/* TLS: a certificate the OS does not trust is never accepted (the default; stated here so nothing can loosen it later). */
app.on('certificate-error', (_e, _wc, _url, _err, _cert, callback) => callback(false));
/* Every web contents (the window, the capture notice): no navigation, no new windows, no <webview>, no remote content. */
app.on('web-contents-created', (_e, wc) => {
  wc.setWindowOpenHandler(() => ({ action: 'deny' }));
  wc.on('will-navigate', (e, url) => { if (!isOurPage(url)) e.preventDefault(); });
  wc.on('will-redirect', e => e.preventDefault());
  wc.on('will-attach-webview', e => e.preventDefault());
  wc.on('devtools-opened', () => { if (app.isPackaged) wc.closeDevTools(); }); });

const rendererDir = path.join(__dirname, '..', 'renderer');
/** Only the app's own pages (file:// inside src/renderer) may be shown in a window. */
function isOurPage(url) {
  try { const u = new URL(url); if (u.protocol !== 'file:') return false; const p = decodeURIComponent(u.pathname); return path.resolve(process.platform === 'win32' ? p.replace(/^\//, '') : p).startsWith(rendererDir + path.sep); }
  catch (e) { return false; } }

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

let windows = 0;
function createWindow() {
  /* started hidden (at login, into the tray): the page stays inactive until it is shown, so its clock does not tick (a
     window created hidden would otherwise count as visible to the page until its first show / hide) */
  const hidden = process.argv.includes('--hidden') && !windows++;
  win = new BrowserWindow(Object.assign({}, SIZE, {
    show: false, paintWhenInitiallyHidden: !hidden, resizable: true, maximizable: false, fullscreenable: false,
    title: 'Niyanta Tracker', icon: asset('renderer', 'icon.png'), autoHideMenuBar: true,
    backgroundColor: nativeTheme.shouldUseDarkColors ? '#0E0D1C' : '#F4F4FA',
    webPreferences: Object.assign({ preload: asset('preload', 'preload.js') }, WEB_PREFS) }));
  /* some Linux window managers ignore maximizable:false (double-click on the title bar) */
  win.on('maximize', () => win.unmaximize());
  win.on('enter-full-screen', () => win.setFullScreen(false));
  /* no menu bar at all on Windows/Linux (autoHideMenuBar would still show it on Alt and push the content down) */
  if (process.platform !== 'darwin') win.removeMenu(); else win.setMenuBarVisibility(false);
  /* a (re)loaded page has no project list yet: the next push carries it */
  win.webContents.on('did-start-loading', () => { sentRev = -1; });
  win.loadFile(asset('renderer', 'index.html'));
  if (hidden) win.webContents.once('did-finish-load', () => errors.log(`startup: window loaded (hidden) ${Date.now() - START} ms after launch`));
  else win.once('ready-to-show', () => { onScreen(); win.show(); errors.log(`startup: window shown ${Date.now() - START} ms after launch`); });
  win.on('closed', () => { win = null; });
  win.on('session-end', () => tracker && tracker.onShutdown());
  win.on('focus', () => win.flashFrame(false));
  /* a hidden window gets no state pushes (nothing to paint); the latest state is sent when it shows again */
  for (const ev of ['show', 'restore', 'focus']) win.on(ev, flushUi);
  win.on('close', e => { if (!quitting) { e.preventDefault(); win.hide(); if (process.platform === 'darwin') app.dock && app.dock.hide(); } });
  /* the page crashed (or was killed): reload it, at most 3 times in 5 minutes */
  win.webContents.on('render-process-gone', (_e, d) => {
    if (d.reason === 'clean-exit' || quitting) return;
    const now = Date.now(); crashes = crashes.filter(t => now - t < 300000); crashes.push(now);
    if (crashes.length <= 3 && win && !win.isDestroyed()) setTimeout(() => { if (win && !win.isDestroyed()) win.webContents.reload(); }, 500); }); }

/** Shared by the main window and the capture notice: sandboxed, isolated, no Node, no webview, no remote content. */
const WEB_PREFS = { contextIsolation: true, sandbox: true, nodeIntegration: false, nodeIntegrationInWorker: false, nodeIntegrationInSubFrames: false, webviewTag: false, webSecurity: true,
  allowRunningInsecureContent: false, experimentalFeatures: false, plugins: false, navigateOnDragDrop: false, spellcheck: false, safeDialogs: true, disableBlinkFeatures: 'Auxclick', devTools: !app.isPackaged };
let crashes = [];
let uiDirty = false;

/** Deny every permission a page could ask for (nothing in the app needs one; screenshots are taken by the main process). */
function lockSession() {
  const ses = session.defaultSession;
  /* no spell checking anywhere, and no language list: otherwise Electron downloads a Hunspell dictionary from Google's CDN
     at every start on Windows and Linux (nothing in the app needs it) */
  try { ses.setSpellCheckerEnabled(false); if (process.platform !== 'darwin') ses.setSpellCheckerLanguages([]); } catch (e) { /* not available */ }
  ses.setPermissionRequestHandler((_wc, _permission, callback) => callback(false));
  ses.setPermissionCheckHandler(() => false);
  ses.setDevicePermissionHandler(() => false);
  ses.on('will-download', e => e.preventDefault()); }

function showWindow() {
  if (!win || win.isDestroyed()) createWindow();
  if (process.platform === 'darwin' && app.dock) app.dock.show();
  if (win.isMinimized()) win.restore();
  onScreen();
  win.show(); win.focus(); }

/**
 * Keep the window on a display that exists: a monitor was unplugged, a laptop left its dock or the scaling changed while the
 * window was hidden in the tray. When less than a corner of it is visible on any display it is centred on the nearest one.
 */
function onScreen() {
  if (!win || win.isDestroyed()) return;
  try {
    const b = win.getBounds();
    const fit = require('./screens').fitWindow(b, screen.getAllDisplays().map(d => d.workArea), screen.getDisplayMatching(b).workArea);
    if (fit) win.setBounds(fit); }
  catch (e) { errors.report('window', e, {}, 'warning'); } }

function flushUi() { if (uiDirty && lastUi && win && !win.isDestroyed()) { uiDirty = false; send(lastUi); } }

/* The project list (up to a few thousand tasks) is sent again only when it changed (a new bootstrap); otherwise the page
   keeps the one it has. Sent state is otherwise small. */
let sentRev = -1;
function send(ui) {
  const rev = ui.projectsRev || 0;
  const out = rev === sentRev ? Object.assign({}, ui, { projects: undefined }) : ui;
  sentRev = rev;
  win.webContents.send('tracker:state', out); }

function push(ui) {
  lastUi = ui;
  if (win && !win.isDestroyed()) { if (win.isVisible() && !win.isMinimized()) send(ui); else uiDirty = true; }
  updateTray(ui);
  indicator(ui && ui.screen === 'tracker' ? (ui.tracking ? 'on' : 'off') : null); }

/* ---------- tracking dot on the taskbar / dock icon ---------- */

/**
 * Status dot on the app icon, steady (no blinking): green while tracking, red while paused / idle-paused / not tracking,
 * none when signed out. Windows taskbar overlay icon, macOS dock icon, Linux window icon (shown by the taskbars of KDE,
 * Xfce, Cinnamon and others; the tray icon shows the state everywhere). Images are made once per colour.
 */
const ind = { state: undefined, imgs: {}, base: null };
function indicatorImage(state) {
  if (ind.imgs[state] !== undefined) return ind.imgs[state];
  const color = badge().COLORS[state];
  if (process.platform === 'win32') return (ind.imgs[state] = nativeImage.createFromBitmap(badge().dot(32, 1, color), { width: 32, height: 32, scaleFactor: 2 }));
  if (ind.base === null) { const src = nativeImage.createFromPath(asset('renderer', 'icon.png')); ind.base = src.isEmpty() ? false : src.resize({ width: 256, height: 256, quality: 'best' }); }
  if (!ind.base) return (ind.imgs[state] = null);
  const { width, height } = ind.base.getSize();
  return (ind.imgs[state] = nativeImage.createFromBitmap(badge().onIcon(ind.base.toBitmap(), width, height, color), { width, height })); }

function indicator(state) {
  if (state === ind.state) return;
  ind.state = state;
  try {
    const img = state ? indicatorImage(state) : null;
    if (process.platform === 'win32') { if (win && !win.isDestroyed()) win.setOverlayIcon(img, state === 'on' ? 'Tracking' : state ? 'Not tracking' : ''); }
    else if (process.platform === 'darwin') { if (app.dock && (img || ind.base)) app.dock.setIcon(img || ind.base); }
    else if (win && !win.isDestroyed()) win.setIcon(img || asset('renderer', 'icon.png')); }
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
function tone() { if (toneOn() && win && !win.isDestroyed()) win.webContents.send('tracker:tone'); }
const toneOn = () => store.get('alertTone', true) !== false;

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
      fs.writeFileSync(file, `[Desktop Entry]\nType=Application\nName=Niyanta Tracker\nExec=${guard.desktopExecArg(exec)} --hidden\nX-GNOME-Autostart-enabled=true\nTerminal=false\n`, { mode: 0o644 });
    } else {
      app.setLoginItemSettings({ openAtLogin: !!on, openAsHidden: true, args: ['--hidden'] }); }
  } catch (e) { errors.report('autostart', e, {}, 'warning'); } }

/* ---------- links ---------- */

/**
 * The only way a link leaves the app: a fixed set of targets, and only http(s) URLs on the signed-in workspace (or
 * niyanta.app) are handed to the browser (guard.allowedLink); the Screen Recording pane and the log folder are constants.
 */
function openTarget(what) {
  if (!guard.OPEN_TARGETS.includes(what)) return false;
  const ws = tracker.boot && tracker.boot.workspace;
  const base = (ws && ws.url) || store.get('workspaceUrl');
  const urls = { web: base, time: ws && ws.time_page, screenshots: ws && ws.screenshots_page, download: (tracker.update && tracker.update.url) || (ws && ws.time_page ? ws.time_page + '#desktop' : null) };
  if (what === 'permissions') return capture().openPermissionSettings();
  /* macOS kept an old Screen Recording entry (unsigned builds get a new signature with each update): clear it and restart,
     so macOS asks again for this version on the next capture. Packaged macOS builds only. */
  if (what === 'fix-permission') {
    if (process.platform !== 'darwin' || !app.isPackaged) return false;
    capture().resetPermission().then(ok => { errors.log(`screen permission reset: ${ok ? 'ok' : 'failed'}`); app.relaunch(); app.quit(); });
    return true; }
  if (what === 'logs') return shell.showItemInFolder(errors.logFile());
  const url = urls[what];
  if (!guard.allowedLink(url, tracker.api.base || base)) { if (url) errors.log(`open: refused link outside the workspace (${what})`); return false; }
  shell.openExternal(url, { activate: true });
  return true; }

/** The workspace's "connect the desktop app" page (flow B): only an http(s) URL on that workspace goes to the browser. */
function openConnectPage(url, ws) {
  if (!guard.allowedLink(url, ws)) { errors.log('connect: refused a sign-in page outside the workspace'); return false; }
  shell.openExternal(url, { activate: true });
  return true; }

/* ---------- IPC ---------- */

/**
 * Every handler checks that the call comes from the main frame of our own window (not the notice window, not a frame),
 * validates its arguments (types, ranges, lengths: guard.js) and is rate limited per channel. Errors never leak stacks to
 * the page, only the message.
 */
const limiter = new guard.RateLimiter({ state: 240, select: 120, action: 60, error: 10, signIn: 10, connect: 20, dismiss: 60, copy: 30, updates: 10, refresh: 10, uninstall: 3 }, 60000, 60);
function validSender(e) {
  if (!win || win.isDestroyed() || e.sender !== win.webContents) return false;
  const f = e.senderFrame;
  return !!f && f === win.webContents.mainFrame && isOurPage(f.url); }
const obj = v => (v && typeof v === 'object' && !Array.isArray(v) ? v : {});

function registerIpc() {
  const h = (name, fn) => ipcMain.handle('tracker:' + name, async (e, arg) => {
    if (!validSender(e)) return null;
    if (!limiter.allow(name)) return { ok: false, error: 'Too many requests. Please wait a moment.' };
    try { return await fn(arg); } catch (err) { errors.report('ipc:' + name, err); return { ok: false, error: String(err && err.message || 'Something went wrong.').slice(0, 300) }; } });
  h('state', () => tracker.ui());
  h('signIn', d => { d = obj(d); return tracker.signIn({ url: guard.str(d.url, 300), email: guard.str(d.email, 200), password: guard.str(d.password, 1000) }); });
  h('signOut', () => tracker.signOut());
  h('connect', c => { c = obj(c); const op = guard.str(c.op, 20); return guard.CONNECT_OPS.includes(op) ? connect.op(op, guard.str(c.url, 300)) : { ok: false }; });
  h('action', a => { a = obj(a); const name = guard.str(a.name, 10); return ['start', 'pause', 'resume', 'switch'].includes(name) ? tracker.action(name, guard.selection(a.body)) : { ok: false }; });
  h('select', sel => { tracker.setSelection(guard.selection(sel)); return { ok: true }; });
  h('consent', () => tracker.acceptConsent());
  h('pref', p => {
    const key = guard.str(obj(p).key, 40), value = !!obj(p).value;
    if (!guard.PREF_KEYS.includes(key)) return { ok: false };
    if (key === 'launchAtLogin') setLaunchAtLogin(value);
    if (key === 'notify' && tracker.settings.screenshot_notify_user_can_disable) store.set('notifyScreenshots', value);
    if (key === 'tone') store.set('alertTone', value);
    tracker.changed();
    return { ok: true }; });
  h('pomodoro', p => {
    const op = guard.str(obj(p).op, 10);
    if (!guard.POMODORO_OPS.includes(op)) return { ok: false };
    if (op === 'save') return tracker.setPomodoro(obj(obj(p).prefs));
    if (op === 'break') return tracker.takeBreak();
    if (op === 'snooze') return tracker.snoozeBreak();
    return tracker.dismissBreakOver(); });
  h('open', what => ({ ok: openTarget(guard.str(what, 40)) !== false }));
  h('updates', () => tracker.checkUpdates(true));
  h('installUpdate', () => tracker.updateNow());
  h('updatePrompted', v => tracker.updatePrompted(guard.str(v, 40)));
  h('meeting', on => tracker.setMeeting(!!on));
  h('dismiss', id => { id = guard.str(id, 20); return guard.DISMISS_IDS.includes(id) ? tracker.dismiss(id) : { ok: false }; });
  h('quitAnyway', () => tracker.quitAnyway());
  h('downloadInstaller', () => tracker.downloadInstaller());
  h('openInstaller', () => ({ ok: tracker.updater.open() }));
  h('restartApp', () => { restartToUpdate(false); return { ok: true }; });
  h('uninstall', () => uninstallApp());
  h('uninstallDismiss', () => { if (!removing) { tracker.uninstall = null; tracker.changed(); } return { ok: true }; });
  h('copy', text => { clipboard.writeText(guard.str(text, 2000)); return { ok: true }; });
  h('refresh', async () => { await tracker.refreshBoot(); await tracker.refreshState(); return { ok: true }; });
  h('error', info => { info = obj(info); const e = new Error(guard.str(info.message, 900) || 'Unknown renderer error'); e.stack = guard.str(info.stack, 6000) || e.stack; errors.report('renderer', e); return { ok: true }; }); }

/* ---------- lifecycle ---------- */

app.on('second-instance', (_e, argv) => { if (!app.isReady()) return; showWindow(); const url = linkIn(argv); if (url) deepLink(url); });
app.on('activate', () => showWindow());
app.on('window-all-closed', e => { /* keep running in the tray */ });
/*
 * Quitting pauses tracking first, then (sync = a normal quit) sends what is still in the offline queue: after 1.2 s the
 * window shows "Syncing your data to the server. The app will close by itself when done." with progress and Quit anyway
 * (up to 60 s; whatever is left stays queued for the next start). Then it quits for real (app.quit, so a downloaded update
 * installs on the way out). Update restarts and system shutdowns do not wait for the sync (the queue is saved on disk).
 */
let closing = null;
const QUIT_CEILING_MS = 75000;
async function prepareQuit(wait, sync = false) {
  closing = closing || (async () => {
    const work = (async () => {
      try { await tracker.beforeQuit(wait); } catch (err) { /* quitting anyway */ }
      if (sync) { try { await tracker.syncBeforeQuit(); } catch (err) { /* quitting anyway */ } } })();
    /* whatever happens (a hung request, a stuck sync) the app quits after QUIT_CEILING_MS: the queue is on disk */
    await Promise.race([work, new Promise(r => setTimeout(r, QUIT_CEILING_MS))]);
    try { tracker.quitAnyway(); } catch (err) { /* nothing to abort */ }
    toast().destroy();
    quitting = true;
  })();
  return closing; }

app.on('before-quit', async e => {
  if (quitting) return;
  e.preventDefault();
  await prepareQuit(undefined, true);
  app.quit(); });

/* ---------- uninstall (Settings › Uninstall Niyanta Tracker, src/main/uninstall.js) ---------- */

let removing = false;
/**
 * Stop and sync tracking (bounded by QUIT_CEILING_MS), sign this device out (token revoked on the server, token, keys and
 * queue removed here), remove the login item and the niyanta-tracker:// handler, then start the helper (detached) that
 * removes the program and the data once this process has ended, and quit. Progress and anything that could not be done
 * are shown in the window (tracker.uninstall); nothing is left half-removed silently.
 */
async function uninstallApp() {
  if (removing || !tracker) return { ok: false };
  const ctx = { platform: process.platform, packaged: app.isPackaged, execPath: process.execPath, appImage: process.env.APPIMAGE || null, home: os.homedir(), userData: app.getPath('userData'), tmp: os.tmpdir(), exists: p => fs.existsSync(p) };
  const p = uninstall().plan(ctx);
  if (!p.ok) { tracker.uninstall = { step: null, error: p.error }; tracker.changed(); return { ok: false, error: p.error }; }
  removing = true;
  const win32 = process.platform === 'win32';
  let dir = null, log = null;
  const note = m => { errors.log('uninstall: ' + m); if (log) { try { fs.appendFileSync(log, `${new Date().toISOString()} ${m}\n`, { mode: 0o600 }); } catch (e) { /* the log is best effort */ } } };
  const step = m => { tracker.uninstall = { step: m, error: null }; tracker.changed(); note(m); };
  try {
    /* macOS / Linux: a small /bin/sh helper in its own temporary folder; Windows: no helper at all (see below) */
    if (!win32) {
      dir = fs.mkdtempSync(path.join(os.tmpdir(), 'ny-uninstall-'));
      if (!guard.uninstallPath(dir, 'helperDir', ctx)) throw new Error('The temporary folder for the uninstall helper is not usable.');
      log = path.join(dir, 'uninstall.log'); }
    step('Stopping tracking and syncing your data...');
    await Promise.race([(async () => { await tracker.beforeQuit(4000); await tracker.syncBeforeQuit(); })(), new Promise(r => setTimeout(r, QUIT_CEILING_MS))]);
    try { tracker.quitAnyway(); } catch (e) { /* nothing to abort */ }
    step('Signing out of this device...');
    await Promise.race([tracker.signedIn ? tracker.signOut() : Promise.resolve(), new Promise(r => setTimeout(r, 15000))]);
    if (tracker.signedIn) tracker.signOutLocal(null);
    step('Removing the start-up entry and the sign-in link handler...');
    setLaunchAtLogin(false);
    try { if (process.defaultApp && process.argv.length >= 2) app.removeAsDefaultProtocolClient(guard.SCHEME, process.execPath, [path.resolve(process.argv[1])]); else app.removeAsDefaultProtocolClient(guard.SCHEME); } catch (e) { note('protocol: ' + e.message); }
    step('Removing Niyanta Tracker...');
    note('plan: ' + p.kind + ' ' + p.remove.map(r => r.path).join(' | '));
    const { spawn } = require('child_process');
    let child;
    if (win32) {
      /* Windows: the app's own NSIS uninstaller, exactly as Settings › Apps runs it, silently and with electron-builder's
         documented --delete-app-data (removes %APPDATA%\Niyanta Tracker; build/installer.nsh removes the update cache). It
         waits for this process to end by itself. No script, nothing written to %TEMP%, no cmd.exe. */
      child = spawn(p.uninstaller, uninstall().NSIS_ARGS, { detached: true, stdio: 'ignore', cwd: path.dirname(p.uninstaller) }); }
    else {
      const file = path.join(dir, 'uninstall.sh');
      fs.writeFileSync(file, uninstall().shScript({ pid: process.pid, remove: p.remove, dir, log, platform: process.platform }), { mode: 0o700 });
      child = spawn('/bin/sh', [file], { detached: true, stdio: 'ignore', cwd: dir }); }
    await new Promise((resolve, reject) => { child.once('error', reject); child.once('spawn', resolve); });
    child.unref();
    if (p.manual) await dialog.showMessageBox({ type: 'info', message: 'Niyanta Tracker is almost removed', detail: p.manual + '\n\nThe app closes now.', buttons: ['Copy the command and close', 'Close'] }).then(r => { if (r.response === 0) clipboard.writeText(uninstall().DEB_COMMAND); });
    toast().destroy();
    quitting = true;
    app.exit(0);
    return { ok: true }; }
  catch (e) {
    removing = false;
    const left = p.remove.map(r => r.path);
    const how = process.platform === 'win32' ? 'Remove Niyanta Tracker in Windows Settings › Apps › Installed apps.' : process.platform === 'darwin' ? 'Quit the app, move Niyanta Tracker from Applications to the Trash and delete the folders listed.' : (p.kind === 'deb' ? `Run: ${uninstall().DEB_COMMAND}` : 'Delete the AppImage and the folders listed.');
    tracker.uninstall = { step: null, error: `Niyanta Tracker could not finish removing itself (${String(e && e.message || e).slice(0, 200)}). ${how}`, left };
    note('failed: ' + (e && e.message));
    errors.report('uninstall', e);
    tracker.changed();
    return { ok: false, error: tracker.uninstall.error }; } }

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
  lockSession();
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
  connect = new Connect({ tracker, store, open: openConnectPage, focus: showWindow, device: os.hostname(), platform: process.platform, normalise: normaliseUrl, log: errors.log });
  tracker.connectUi = () => connect.view();
  registerIpc();
  createWindow();
  createTray();
  for (const ev of ['suspend', 'resume', 'lock-screen', 'unlock-screen']) powerMonitor.on(ev, () => tracker.onPower(ev));
  /* a monitor unplugged or rescaled: the window comes back onto a display that exists */
  for (const ev of ['display-removed', 'display-metrics-changed']) screen.on(ev, () => onScreen());
  /* Linux/macOS shutdown or log-out: delay it until tracking is paused and everything is saved (Windows: 'session-end') */
  powerMonitor.on('shutdown', e => { if (quitting) return; e.preventDefault(); tracker.onShutdown(); prepareQuit().then(() => app.quit()); });
  /* first start of the installed app: launch at login on (a choice made later is never changed again; development runs
     never register themselves) */
  if (store.get('launchAtLogin') === undefined && app.isPackaged) setLaunchAtLogin(true);
  await tracker.restore();
  /* a link that started the app (or came before the session was restored) is handled now */
  connect.ready = true;
  const first = earlyLink || linkIn(process.argv);
  earlyLink = null;
  if (first) deepLink(first);
  if (process.platform === 'darwin' && tracker.settings.screenshots_enabled && capture().screenPermission() === 'denied') {
    dialog.showMessageBox({ type: 'info', message: 'Allow screen recording', detail: 'Your organisation uses screenshots while you track time. Open System Settings › Privacy & Security › Screen Recording and turn on Niyanta Tracker, then restart the app.', buttons: ['Open Settings', 'Later'] })
      .then(r => { if (r.response === 0) capture().openPermissionSettings(); }); } });
