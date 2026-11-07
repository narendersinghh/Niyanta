'use strict';
/*
 * Pure security helpers (no Electron, so scripts/security-check.js can test them with plain Node): argument validation for
 * the IPC handlers, the allowlist of links the app may open in the browser, a per-channel rate limiter, safe file names for
 * downloads, same-origin checks for redirects and the AES-256-GCM encryption of queued screenshots.
 */
const crypto = require('crypto');

/** A short string argument: anything else becomes '' and it is cut at `max` characters. */
function str(v, max = 200) { return typeof v === 'string' ? v.slice(0, max) : ''; }

/** A positive integer id (numbers or numeric strings) or null. */
function id(v) { const n = typeof v === 'number' || typeof v === 'string' ? +v : NaN; return Number.isInteger(n) && n > 0 && n < 1e12 ? n : null; }

/** The project / task selection sent by the window: {project_id, task_id} as ids or null, description at most 500 characters. */
function selection(b) {
  const out = {};
  if (!b || typeof b !== 'object') return out;
  if ('project_id' in b) { out.project_id = id(b.project_id); out.task_id = id(b.task_id); }
  if (typeof b.description === 'string') out.description = b.description.slice(0, 500);
  return out; }

/** What the renderer may ask the main process to open (tracker:open). */
const OPEN_TARGETS = ['web', 'time', 'screenshots', 'download', 'permissions', 'logs'];
/** Preferences the window may change (tracker:pref). */
const PREF_KEYS = ['launchAtLogin', 'notify', 'tone'];
/** Pomodoro operations (tracker:pomodoro). */
const POMODORO_OPS = ['save', 'break', 'snooze', 'dismiss'];

/** Hosts that may be opened in the browser besides the workspace itself. */
const LINK_HOSTS = ['niyanta.app', 'www.niyanta.app'];
const LOCAL_HOSTS = ['localhost', '127.0.0.1', '::1', '[::1]'];

/**
 * May `url` be opened in the browser? Only http(s) URLs on the signed-in workspace's host (https, or http for localhost
 * development) or on the allowlisted hosts; everything else (javascript:, file:, custom schemes, other sites) is refused.
 */
function allowedLink(url, base) {
  let u, b = null;
  try { u = new URL(String(url || '')); } catch (e) { return false; }
  try { b = base ? new URL(String(base)) : null; } catch (e) { b = null; }
  const local = LOCAL_HOSTS.includes(u.hostname);
  if (u.protocol !== 'https:' && !(u.protocol === 'http:' && local)) return false;
  if (u.username || u.password) return false;
  if (b && u.hostname === b.hostname && (u.port || '') === (b.port || '') && u.protocol === b.protocol) return true;
  return LINK_HOSTS.includes(u.hostname); }

/** Same origin (scheme, host, port)? Used to refuse redirects that would carry the device token to another site. */
function sameOrigin(a, b) { try { return new URL(String(a)).origin === new URL(String(b)).origin; } catch (e) { return false; } }

/**
 * A file name from the server (feed entries, installers) that is safe to join to a folder: no directory parts, only letters,
 * digits, dot, dash, underscore and plus, a plain extension, at most 120 characters. Returns null when it is not.
 */
function safeFileName(name, exts) {
  const s = String(name || '');
  if (!/^[A-Za-z0-9][A-Za-z0-9._+-]{0,118}[A-Za-z0-9]$/.test(s) || s.includes('..')) return null;
  const ext = s.slice(s.lastIndexOf('.') + 1).toLowerCase();
  if (exts && !exts.map(x => String(x).toLowerCase()).includes(ext)) return null;
  return s; }

/** Argument of the Exec= line of a .desktop file: double-quoted, with the characters the spec reserves escaped. */
function desktopExecArg(s) { return '"' + String(s).replace(/[\\"`$]/g, '\\$&') + '"'; }

/**
 * Sliding-window rate limiter for the IPC channels: `allow(name)` is true while the channel had fewer than `limit(name)`
 * calls in the last `windowMs` (default 60 s). Calls over the limit are refused, never queued.
 */
class RateLimiter {
  constructor(limits, windowMs = 60000, fallback = 60) { this.limits = limits || {}; this.windowMs = windowMs; this.fallback = fallback; this.calls = new Map(); }
  limit(name) { return Number.isFinite(this.limits[name]) ? this.limits[name] : this.fallback; }
  allow(name, now = Date.now()) {
    const max = this.limit(name);
    const list = (this.calls.get(name) || []).filter(t => now - t < this.windowMs);
    if (list.length >= max) { this.calls.set(name, list); return false; }
    list.push(now); this.calls.set(name, list);
    return true; } }

/* ---------- screenshots at rest: AES-256-GCM, a random key per installation kept in the OS keychain ---------- */

const ENC_MAGIC = Buffer.from('NYS1');

function newKey() { return crypto.randomBytes(32); }

/** NYS1 | 12-byte iv | 16-byte tag | ciphertext */
function encrypt(key, data) {
  const iv = crypto.randomBytes(12);
  const c = crypto.createCipheriv('aes-256-gcm', key, iv);
  const body = Buffer.concat([c.update(data), c.final()]);
  return Buffer.concat([ENC_MAGIC, iv, c.getAuthTag(), body]); }

/** Inverse of encrypt(); throws on a wrong key or a damaged file. */
function decrypt(key, blob) {
  if (!Buffer.isBuffer(blob) || blob.length < 32 || !blob.subarray(0, 4).equals(ENC_MAGIC)) throw new Error('not an encrypted screenshot');
  const d = crypto.createDecipheriv('aes-256-gcm', key, blob.subarray(4, 16));
  d.setAuthTag(blob.subarray(16, 32));
  return Buffer.concat([d.update(blob.subarray(32)), d.final()]); }

function isEncrypted(blob) { return Buffer.isBuffer(blob) && blob.length >= 4 && blob.subarray(0, 4).equals(ENC_MAGIC); }

module.exports = { str, id, selection, allowedLink, sameOrigin, safeFileName, desktopExecArg, RateLimiter, newKey, encrypt, decrypt, isEncrypted, OPEN_TARGETS, PREF_KEYS, POMODORO_OPS, LINK_HOSTS };
