'use strict';
/* Small, non-intrusive "Screenshot taken" notice: a frameless, transparent, always-on-top window that never takes focus,
   bottom-right of the primary display for ~2.5 s. Falls back to a system notification when such windows are unavailable. */
const path = require('path');
const { BrowserWindow, Notification, screen } = require('electron');

let win = null;
let hideTimer = null;
const W = 300, H = 76;

function ensure() {
  if (win && !win.isDestroyed()) return win;
  const linux = process.platform === 'linux';
  win = new BrowserWindow({
    width: W, height: H, show: false, frame: false, resizable: false, movable: false, minimizable: false, maximizable: false, fullscreenable: false,
    focusable: false, skipTaskbar: true, alwaysOnTop: true, hasShadow: false, transparent: !linux, backgroundColor: linux ? '#17162B' : '#00000000',
    webPreferences: { contextIsolation: true, sandbox: true, nodeIntegration: false, nodeIntegrationInWorker: false, nodeIntegrationInSubFrames: false, webviewTag: false, webSecurity: true, allowRunningInsecureContent: false,
      experimentalFeatures: false, plugins: false, navigateOnDragDrop: false, spellcheck: false, devTools: false, preload: path.join(__dirname, '..', 'preload', 'toast-preload.js') }
  });
  win.setAlwaysOnTop(true, 'screen-saver');
  win.setVisibleOnAllWorkspaces(true, { visibleOnFullScreen: true });
  win.setIgnoreMouseEvents(true);
  win.loadFile(path.join(__dirname, '..', 'renderer', 'toast.html'));
  win.on('closed', () => { win = null; });
  return win;
}

/** Show the notice. Returns false when it could not be shown in any way. */
function show({ title = 'Screenshot taken', body = '', thumb = null } = {}) {
  try {
    const w = ensure();
    const wa = screen.getPrimaryDisplay().workArea;
    w.setBounds({ x: wa.x + wa.width - W - 16, y: wa.y + wa.height - H - 16, width: W, height: H });
    const send = () => { if (!w.isDestroyed()) { w.webContents.send('toast', { title, body, thumb }); w.showInactive(); } };
    if (w.webContents.isLoading()) w.webContents.once('did-finish-load', send); else send();
    clearTimeout(hideTimer);
    hideTimer = setTimeout(() => { if (win && !win.isDestroyed()) win.hide(); }, 2600);
    return true;
  } catch (e) {
    if (Notification.isSupported()) { new Notification({ title, body, silent: true }).show(); return true; }
    return false;
  }
}

function destroy() { if (win && !win.isDestroyed()) win.destroy(); win = null; }

module.exports = { show, destroy };
