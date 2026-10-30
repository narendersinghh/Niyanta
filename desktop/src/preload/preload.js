'use strict';
/* Minimal, typed bridge between the sandboxed renderer and the main process (contextIsolation on, no Node in the page). */
const { contextBridge, ipcRenderer } = require('electron');

const call = (name, arg) => ipcRenderer.invoke('tracker:' + name, arg);

contextBridge.exposeInMainWorld('tracker', {
  getState: () => call('state'),
  signIn: (data) => call('signIn', { url: String(data.url || ''), email: String(data.email || ''), password: String(data.password || '') }),
  signOut: () => call('signOut'),
  startDay: (sel) => call('action', { name: 'start', body: sel || {} }),
  pause: () => call('action', { name: 'pause', body: {} }),
  resume: (sel) => call('action', { name: 'resume', body: sel || {} }),
  switchProject: (sel) => call('action', { name: 'switch', body: sel || {} }),
  endDay: () => call('action', { name: 'end', body: {} }),
  select: (sel) => call('select', sel || {}),
  answerIdle: (action) => call('idle', action === 'discard' ? 'discard' : 'keep'),
  acceptConsent: () => call('consent'),
  setPref: (key, value) => call('pref', { key: String(key), value: !!value }),
  open: (what) => call('open', String(what)),
  checkUpdates: () => call('updates'),
  installUpdate: () => call('installUpdate'),
  refresh: () => call('refresh'),
  reportError: (info) => call('error', { message: String(info && info.message || ''), stack: String(info && info.stack || ''), where: 'renderer' }),
  onState: (cb) => { const h = (_e, s) => cb(s); ipcRenderer.on('tracker:state', h); return () => ipcRenderer.removeListener('tracker:state', h); }
});
