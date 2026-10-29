'use strict';
/* Preferences and the device token, kept in userData/config.json. The token is encrypted with the OS keychain
   (safeStorage: DPAPI on Windows, Keychain on macOS, libsecret/kwallet on Linux) whenever that is available. */
const fs = require('fs');
const path = require('path');
const { app, safeStorage } = require('electron');

const file = () => path.join(app.getPath('userData'), 'config.json');
let data = null;

function load() {
  if (data) return data;
  try { data = JSON.parse(fs.readFileSync(file(), 'utf8')); } catch (e) { data = {}; }
  return data;
}

function save() {
  try {
    fs.mkdirSync(path.dirname(file()), { recursive: true });
    const tmp = file() + '.tmp';
    fs.writeFileSync(tmp, JSON.stringify(data, null, 2), { mode: 0o600 });
    fs.renameSync(tmp, file());
  } catch (e) { console.error('config save failed', e); }
}

function get(key, def) { const v = load()[key]; return v === undefined ? def : v; }
function set(key, value) { load(); if (value === undefined) delete data[key]; else data[key] = value; save(); }

function canEncrypt() { try { return safeStorage.isEncryptionAvailable(); } catch (e) { return false; } }

function setToken(token) {
  load();
  delete data.token; delete data.tokenEnc;
  if (token) {
    if (canEncrypt()) data.tokenEnc = safeStorage.encryptString(token).toString('base64');
    else data.token = token; /* no keychain (e.g. Linux without a secret service): file is still 0600 in the user profile */
  }
  save();
}

function getToken() {
  load();
  if (data.tokenEnc) { try { return safeStorage.decryptString(Buffer.from(data.tokenEnc, 'base64')); } catch (e) { return null; } }
  return data.token || null;
}

function tokenProtected() { return !!load().tokenEnc; }

module.exports = { get, set, setToken, getToken, tokenProtected, canEncrypt };
