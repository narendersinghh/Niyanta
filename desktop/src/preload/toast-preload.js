'use strict';
const { contextBridge, ipcRenderer } = require('electron');
contextBridge.exposeInMainWorld('toastBridge', { onToast: (cb) => ipcRenderer.on('toast', (_e, d) => cb(d)) });
