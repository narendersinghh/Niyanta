'use strict';
/*
 * Small, non-intrusive "Screenshot taken" notice: a frameless, always-on-top window that never takes focus, bottom-right of
 * the work area of the primary display for ~2.5 s. The tracker shows it only when the notice is on (tracker.notifyOn():
 * the workspace's setting, then the person's choice where the workspace allows one). The window
 * - is hidden after each notice and destroyed when no other notice follows within KEEP_MS (no hidden renderer kept in
 *   memory between screenshots at the usual intervals, no window re-created every few seconds at very short ones); it is
 *   placed again on every show, so a display that changed in between cannot leave it off screen;
 * - never takes the focus (focusable false, shown inactive, a notification window type on Linux) and lets clicks through;
 * - is excluded from screen capture where the OS supports it (setContentProtection: Windows 10 2004+, macOS), and the
 *   tracker hides it before the next capture anyway (hideNow);
 * - is placed in whole device-independent pixels inside the work area, so a scaled or secondary display cannot push it
 *   half off screen;
 * - has a hard deadline: whatever happens to the page (slow first load, a crash), it is hidden HIDE_MS after the call and
 *   never shown after that moment (a page that finishes loading late stays hidden).
 * Falls back to a silent system notification only when such a window cannot be created.
 */
const path = require('path');
const { BrowserWindow, Notification, screen } = require('electron');
const { noticeBounds } = require('./screens');

let win = null;
let hideTimer = null;
let dropTimer = null;
let seq = 0;
const W = 300, H = 76, MARGIN = 16, HIDE_MS = 2600, KEEP_MS = 60000;

function create(bounds) {
  const linux = process.platform === 'linux';
  const w = new BrowserWindow(Object.assign({}, bounds, {
    show: false, frame: false, resizable: false, movable: false, minimizable: false, maximizable: false, fullscreenable: false, closable: true,
    focusable: false, skipTaskbar: true, alwaysOnTop: true, hasShadow: false, transparent: !linux, backgroundColor: linux ? '#17162B' : '#00000000',
    type: linux ? 'notification' : undefined,
    webPreferences: { contextIsolation: true, sandbox: true, nodeIntegration: false, nodeIntegrationInWorker: false, nodeIntegrationInSubFrames: false, webviewTag: false, webSecurity: true, allowRunningInsecureContent: false,
      experimentalFeatures: false, plugins: false, navigateOnDragDrop: false, spellcheck: false, devTools: false, backgroundThrottling: false, preload: path.join(__dirname, '..', 'preload', 'toast-preload.js') }
  }));
  try { w.setContentProtection(true); } catch (e) { /* not supported here */ }
  w.setAlwaysOnTop(true, 'screen-saver');
  /* skipTransformProcessType: on macOS the default would turn the app into a UI element and hide its dock icon */
  w.setVisibleOnAllWorkspaces(true, { visibleOnFullScreen: true, skipTransformProcessType: true });
  w.setIgnoreMouseEvents(true);
  w.on('closed', () => { if (win === w) win = null; });
  w.webContents.on('render-process-gone', () => { if (win === w) destroy(); });
  w.loadFile(path.join(__dirname, '..', 'renderer', 'toast.html'));
  return w; }

/** Bottom-right of the primary display's work area, in whole pixels (DIP), clamped inside it (screens.noticeBounds). */
function place() { return noticeBounds(screen.getPrimaryDisplay().workArea, W, H, MARGIN); }

/** Show the notice. Returns false when it could not be shown in any way. */
function show({ title = 'Screenshot taken', body = '', thumb = null } = {}) {
  clearTimeout(hideTimer); clearTimeout(dropTimer);
  const my = ++seq, deadline = Date.now() + HIDE_MS;
  try {
    const w = win && !win.isDestroyed() ? win : (win = create(place()));
    const send = () => {
      if (my !== seq || w.isDestroyed() || Date.now() >= deadline) return;
      w.webContents.send('toast', { title, body, thumb });
      w.setBounds(place());
      w.showInactive(); };
    if (w.webContents.isLoading()) w.webContents.once('did-finish-load', send); else send();
    /* the deadline counts from now, not from the first paint: a slow load can never leave the notice up */
    hideTimer = setTimeout(() => { if (my === seq) hideNow(); }, HIDE_MS);
    return true; }
  catch (e) {
    destroy();
    if (Notification.isSupported()) { new Notification({ title, body, silent: true }).show(); return true; }
    return false; } }

/** Hide the notice now (its deadline, or before a capture); the window goes away KEEP_MS later unless another notice comes. */
function hideNow() {
  clearTimeout(hideTimer); hideTimer = null; seq++;
  if (win && !win.isDestroyed()) { try { win.hide(); } catch (e) { destroy(); return; } }
  clearTimeout(dropTimer); dropTimer = setTimeout(destroy, KEEP_MS); }

/** Remove the window (idle, a crashed page, quitting). */
function destroy() {
  clearTimeout(hideTimer); clearTimeout(dropTimer); hideTimer = dropTimer = null;
  const w = win; win = null;
  if (w && !w.isDestroyed()) { try { w.hide(); } catch (e) { /* closing anyway */ } w.destroy(); } }

function visible() { return !!(win && !win.isDestroyed() && win.isVisible()); }

module.exports = { show, destroy, hideNow, visible, HIDE_MS, KEEP_MS };
