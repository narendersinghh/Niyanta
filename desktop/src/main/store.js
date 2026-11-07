'use strict';
/* Preferences and the device token, kept in userData/config.json (file mode 600, written atomically). The token and the key
   that encrypts queued screenshots are encrypted with the OS keychain (safeStorage: DPAPI on Windows, Keychain on macOS,
   libsecret/kwallet on Linux) whenever that is available. */
const fs = require('fs');
const path = require('path');
const { app, safeStorage } = require('electron');
const guard = require('./guard');

const file = () => path.join(app.getPath('userData'), 'config.json');
let data = null;

function load() {
  if (data) return data;
  try { data = JSON.parse(fs.readFileSync(file(), 'utf8')); } catch (e) { data = {}; }
  if (!data || typeof data !== 'object' || Array.isArray(data)) data = {};
  return data; }

function save() {
  try {
    const dir = path.dirname(file());
    fs.mkdirSync(dir, { recursive: true, mode: 0o700 });
    const tmp = file() + '.tmp';
    fs.writeFileSync(tmp, JSON.stringify(data, null, 2), { mode: 0o600 });
    fs.renameSync(tmp, file());
    if (process.platform !== 'win32') { try { fs.chmodSync(file(), 0o600); } catch (e) { /* best effort */ } }
  } catch (e) { console.error('config save failed', e); } }

function get(key, def) { const v = load()[key]; return v === undefined ? def : v; }
function set(key, value) { load(); if (value === undefined) delete data[key]; else data[key] = value; save(); }

function canEncrypt() { try { return safeStorage.isEncryptionAvailable(); } catch (e) { return false; } }

/** Encrypt a secret with the OS keychain; {enc} when that works, {plain} otherwise (file is still 0600 in the profile). */
function seal(secret) {
  if (canEncrypt()) { try { return { enc: safeStorage.encryptString(secret).toString('base64') }; } catch (e) { /* fall through */ } }
  return { plain: secret }; }

function unseal(enc, plain) {
  if (enc) { try { return safeStorage.decryptString(Buffer.from(enc, 'base64')); } catch (e) { return null; } }
  return plain || null; }

function setToken(token) {
  load();
  delete data.token; delete data.tokenEnc;
  if (token) { const s = seal(token); if (s.enc) data.tokenEnc = s.enc; else data.token = token; }
  save(); }

function getToken() { load(); return unseal(data.tokenEnc, data.token); }

function tokenProtected() { return !!load().tokenEnc; }

/** A small object kept like the token (sealed with the OS keychain when available); null / undefined removes it. */
function setSealed(key, value) {
  load(); delete data[key];
  if (value !== null && value !== undefined) data[key] = seal(JSON.stringify(value));
  save(); }

function getSealed(key) {
  const v = load()[key];
  if (!v || typeof v !== 'object') return null;
  try { return JSON.parse(unseal(v.enc, v.plain) || 'null'); } catch (e) { return null; } }

/**
 * The 32-byte key that encrypts screenshots waiting in the offline queue: created on first use, kept like the token. When
 * the keychain becomes available later the key is re-sealed. Returns null only when a stored key cannot be read (then the
 * queued files it protects are useless and are dropped).
 */
let queueKey = null;
function getQueueKey() {
  if (queueKey) return queueKey;
  load();
  if (data.queueKeyEnc || data.queueKey) {
    const b64 = unseal(data.queueKeyEnc, data.queueKey);
    if (!b64) return null;
    queueKey = Buffer.from(b64, 'base64');
    if (data.queueKey && canEncrypt()) { const s = seal(b64); if (s.enc) { data.queueKeyEnc = s.enc; delete data.queueKey; save(); } }
    return queueKey; }
  queueKey = guard.newKey();
  const s = seal(queueKey.toString('base64'));
  if (s.enc) data.queueKeyEnc = s.enc; else data.queueKey = s.plain;
  save();
  return queueKey; }

/** New key (sign-out: whatever was queued is deleted with it). */
function resetQueueKey() { load(); queueKey = null; delete data.queueKeyEnc; delete data.queueKey; save(); }

module.exports = { get, set, setToken, getToken, tokenProtected, setSealed, getSealed, canEncrypt, getQueueKey, resetQueueKey };
