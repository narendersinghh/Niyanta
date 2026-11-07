'use strict';
/* Minimal, typed bridge between the sandboxed renderer and the main process (contextIsolation on, no Node in the page).
   Every call goes to one fixed 'tracker:<name>' channel with already-typed arguments; ipcRenderer itself is never exposed.
   Events from the main process reach the page only through the two subscriptions at the end. */
const { contextBridge, ipcRenderer } = require('electron');

const call = (name, arg) => ipcRenderer.invoke('tracker:' + name, arg);
const text = (v, max = 500) => (typeof v === 'string' ? v : String(v == null ? '' : v)).slice(0, max);
const num = v => { const n = Number(v); return Number.isFinite(n) ? n : null; };
/** The project / task selection: ids as numbers (or null) and an optional short description, nothing else. */
const sel = s => { s = s && typeof s === 'object' ? s : {}; const out = { project_id: num(s.project_id), task_id: num(s.task_id) }; if (typeof s.description === 'string') out.description = s.description.slice(0, 500); return out; };

contextBridge.exposeInMainWorld('tracker', {
  getState: () => call('state'),
  signIn: (data) => call('signIn', { url: text(data && data.url, 300), email: text(data && data.email, 200), password: text(data && data.password, 1000) }),
  signOut: () => call('signOut'),
  connect: (op, url) => call('connect', { op: text(op, 20), url: text(url, 300) }),
  startDay: (s) => call('action', { name: 'start', body: sel(s) }),
  pause: () => call('action', { name: 'pause', body: {} }),
  resume: (s) => call('action', { name: 'resume', body: sel(s) }),
  switchProject: (s) => call('action', { name: 'switch', body: sel(s) }),
  select: (s) => call('select', sel(s)),
  acceptConsent: () => call('consent'),
  setPref: (key, value) => call('pref', { key: text(key, 40), value: !!value }),
  setPomodoro: (p) => call('pomodoro', { op: 'save', prefs: { on: !!(p && p.on), work: num(p && p.work), brk: num(p && p.brk) } }),
  takeBreak: () => call('pomodoro', { op: 'break' }),
  snoozeBreak: () => call('pomodoro', { op: 'snooze' }),
  dismissBreakOver: () => call('pomodoro', { op: 'dismiss' }),
  open: (what) => call('open', text(what, 40)),
  checkUpdates: () => call('updates'),
  installUpdate: () => call('installUpdate'),
  updatePrompted: (version) => call('updatePrompted', text(version, 40)),
  setMeeting: (on) => call('meeting', !!on),
  quitAnyway: () => call('quitAnyway'),
  downloadInstaller: () => call('downloadInstaller'),
  openInstaller: () => call('openInstaller'),
  restartApp: () => call('restartApp'),
  copy: (t) => call('copy', text(t, 2000)),
  refresh: () => call('refresh'),
  reportError: (info) => call('error', { message: text(info && info.message, 900), stack: text(info && info.stack, 6000), where: 'renderer' }),
  onTone: (cb) => { const h = () => cb(); ipcRenderer.on('tracker:tone', h); return () => ipcRenderer.removeListener('tracker:tone', h); },
  onState: (cb) => { const h = (_e, s) => cb(s); ipcRenderer.on('tracker:state', h); return () => ipcRenderer.removeListener('tracker:state', h); } });
