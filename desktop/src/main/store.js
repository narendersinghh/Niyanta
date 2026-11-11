'use strict';
/*
 * Preferences and the device token, kept in two small JSON files in userData (file mode 600):
 *   config.json  what the person chose and the sign-in: preferences (pomodoro, screenshot alert, launch at startup, tone,
 *                last project / task), workspace, email, the token and the screenshot key. Written only when one of them
 *                changes, so it is rarely open for writing.
 *   state.json   what the app rewrites all the time (the crash-recovery session every 15 s, the cached bootstrap, update
 *                bookkeeping). A damaged or lost state.json never costs a preference or the sign-in.
 * Every write goes to a .tmp file first and is then renamed over the old one. On Windows the rename fails now and then
 * (EPERM / EBUSY / EACCES while an antivirus scanner or the search indexer has the file open); it is retried and, if it
 * still fails, the file is written in place, so a preference is never kept only in memory. Each good config.json
 * is also kept as config.json.bak and read when config.json is empty or damaged (power cut during a write).
 * The token and the key that encrypts queued screenshots are encrypted with the OS keychain (safeStorage: DPAPI on Windows,
 * Keychain on macOS, libsecret/kwallet on Linux) whenever that is available. Nothing in the app ever resets a preference:
 * not a new day, a server refresh, an update or signing out (scripts/prefs-check.js).
 */
const fs = require('fs');
const path = require('path');
const { app, safeStorage } = require('electron');
const guard = require('./guard');

/** Keys that change often and live in state.json; everything else is a preference or the sign-in (config.json). */
const VOLATILE = ['session', 'bootCache', 'updateRestart', 'updateNotified', 'updatePrompted', 'updateDismissed', 'connectPending'];
const SCHEMA = 2;
const dir = () => app.getPath('userData');
const files = { config: () => path.join(dir(), 'config.json'), state: () => path.join(dir(), 'state.json') };
let data = null, state = null;
const written = { config: null, state: null };
const isObj = v => !!v && typeof v === 'object' && !Array.isArray(v);

function readJson(f) {
  try { const t = fs.readFileSync(f, 'utf8'); if (!t.trim()) return null; const v = JSON.parse(t); return isObj(v) ? v : null; } catch (e) { return null; } }

/** Sleep without the event loop (only between rename retries, a few ms). */
function pause(ms) { try { Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, ms); } catch (e) { /* no wait */ } }

/** Write `text` to `f` atomically when possible; returns true when it is on disk. */
function writeFile(f, text) {
  fs.mkdirSync(path.dirname(f), { recursive: true, mode: 0o700 });
  const tmp = f + '.tmp';
  try {
    fs.writeFileSync(tmp, text, { mode: 0o600 });
    for (let i = 0; i < 6; i++) {
      try { fs.renameSync(tmp, f); return true; }
      catch (e) { if (!['EPERM', 'EBUSY', 'EACCES', 'ENOTEMPTY', 'EEXIST'].includes(e.code) || i === 5) throw e; pause(15 * (i + 1)); } } }
  catch (e) {
    /* the rename keeps failing (the file is held open): write in place, a plain write is allowed while another reader has it */
    try { fs.writeFileSync(f, text, { mode: 0o600 }); try { fs.rmSync(tmp, { force: true }); } catch (x) { /* left over */ } return true; }
    catch (x) { console.error('store: write failed', f, x.code || x.message); return false; } }
  return false; }

function load() {
  if (data) return data;
  const f = files.config();
  data = readJson(f);
  if (data) written.config = JSON.stringify(data, null, 2);
  /* config.json empty or damaged (cut off by a power loss): the last good copy, else the write that was in flight */
  else data = readJson(f + '.bak') || readJson(f + '.tmp') || {};
  state = readJson(files.state()) || {};
  written.state = JSON.stringify(state);
  /* 1.4.3 and older kept everything in config.json: move the busy keys out once (preferences stay where they are) */
  let moved = false;
  for (const k of VOLATILE) if (k in data) { if (!(k in state)) state[k] = data[k]; delete data[k]; moved = true; }
  if (data.schema !== SCHEMA) { data.schema = SCHEMA; moved = true; }
  if (moved) { save('state'); save('config'); }
  return data; }

/** Write one of the files; skipped when its content did not change. */
function save(which = 'config') {
  const obj = which === 'state' ? state : data;
  if (!obj) return;
  const text = JSON.stringify(obj, null, which === 'state' ? 0 : 2);
  if (written[which] === text) return;
  const f = files[which]();
  if (!writeFile(f, text)) return;
  written[which] = text;
  if (process.platform !== 'win32') { try { fs.chmodSync(f, 0o600); } catch (e) { /* best effort */ } }
  /* config.json: a second copy of what was just written, read when config.json is found damaged (power cut mid-write) */
  if (which === 'config') writeFile(f + '.bak', text); }

const where = key => (VOLATILE.includes(key) ? 'state' : 'config');
function get(key, def) { load(); const v = (where(key) === 'state' ? state : data)[key]; return v === undefined ? def : v; }
function set(key, value) {
  load();
  const w = where(key), obj = w === 'state' ? state : data;
  if (value === undefined) { if (!(key in obj)) return; delete obj[key]; } else obj[key] = value;
  save(w); }

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
function setSealed(key, value) { set(key, value !== null && value !== undefined ? seal(JSON.stringify(value)) : undefined); }

function getSealed(key) {
  const v = get(key);
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

module.exports = { get, set, setToken, getToken, tokenProtected, setSealed, getSealed, canEncrypt, getQueueKey, resetQueueKey, VOLATILE, writeFile };
