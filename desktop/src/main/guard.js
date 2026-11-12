'use strict';
/*
 * Pure security helpers (no Electron, so scripts/security-check.js can test them with plain Node): argument validation for
 * the IPC handlers, the allowlist of links the app may open in the browser, a per-channel rate limiter, safe file names for
 * downloads, same-origin checks for redirects, the AES-256-GCM encryption of queued screenshots and the pieces of "Sign in
 * with your browser" (niyanta-tracker:// deep links, PKCE, state).
 */
const crypto = require('crypto');
const path = require('path');

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
/** In-app notices the window may dismiss (tracker:dismiss, the x on each banner and card). */
const DISMISS_IDS = ['notice', 'pomodoro', 'idle', 'offline', 'permission', 'consent', 'update'];
/** "Sign in with your browser" operations (tracker:connect). */
const CONNECT_OPS = ['browser', 'cancel', 'confirm', 'decline', 'retry', 'dismiss'];

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

/**
 * Uninstall: may the path `p` be removed as `kind`? Only absolute, normalised paths with the expected name in the expected
 * place, never the root, the home folder or one of its parents, never a path with a control character (and on Windows none
 * of the characters cmd.exe treats specially, so a path is always safe on a command line). ctx: {platform, home, execPath}.
 *   userData      the app's data folder (…/Niyanta Tracker or …/niyanta-tracker) inside the home folder
 *   cache         an app cache or log folder inside the home folder (updater cache, ~/Library/Logs|Caches/Niyanta Tracker)
 *   macState      ~/Library/Saved Application State/app.niyanta.tracker.savedState, ~/Library/Preferences/app.niyanta.tracker.plist
 *   macApp        /Applications/Niyanta Tracker.app or ~/Applications/Niyanta Tracker.app
 *   appImage      a niyanta-tracker*.AppImage file inside the home folder
 *   desktopEntry  ~/.local/share/applications or ~/.config/autostart niyanta-tracker*.desktop
 *   uninstaller   "Uninstall Niyanta Tracker.exe" next to the running exe (Windows)
 *   helperDir     the uninstall helper's own temporary folder (ny-uninstall-*)
 * Returns the path, or null when it may not be removed.
 */
const UNINSTALL_KINDS = ['userData', 'cache', 'macState', 'macApp', 'appImage', 'desktopEntry', 'uninstaller', 'helperDir'];
function uninstallPath(p, kind, ctx = {}) {
  const win = ctx.platform === 'win32';
  const P = win ? path.win32 : path.posix;
  const s = typeof p === 'string' ? p : '';
  const home = typeof ctx.home === 'string' ? ctx.home : '';
  if (!s || !home || !UNINSTALL_KINDS.includes(kind) || !P.isAbsolute(s) || !P.isAbsolute(home) || s.length > 400 || /[\x00-\x1f\x7f]/.test(s)) return null;
  if (win && /["%!^&|<>]/.test(s)) return null;
  if (P.normalize(s) !== s || s.endsWith(P.sep) || s.split(/[\\/]/).includes('..')) return null;
  const low = x => (win ? x.toLowerCase() : x);
  const S = low(s), H = low(P.normalize(home).replace(/[\\/]+$/, ''));
  const root = P.parse(s).root;
  if (S === low(root) || S === H || H.startsWith(S + P.sep)) return null;
  const inside = (dir, x = S) => x.startsWith(low(dir) + P.sep);
  const base = P.basename(s), dir = P.dirname(s);
  const ok = {
    userData: () => inside(H) && ['Niyanta Tracker', 'niyanta-tracker'].includes(base),
    cache: () => inside(H) && ['niyanta-tracker-updater', 'Niyanta Tracker', 'niyanta-tracker'].includes(base),
    macState: () => !win && ((dir === P.join(home, 'Library', 'Saved Application State') && base === 'app.niyanta.tracker.savedState') || (dir === P.join(home, 'Library', 'Preferences') && base === 'app.niyanta.tracker.plist')),
    macApp: () => !win && base === 'Niyanta Tracker.app' && (dir === '/Applications' || dir === P.join(home, 'Applications')),
    appImage: () => !win && inside(H) && /^niyanta[-_ ]?tracker[^/]*\.appimage$/i.test(base),
    desktopEntry: () => !win && (dir === P.join(home, '.local', 'share', 'applications') || dir === P.join(home, '.config', 'autostart')) && /^niyanta-tracker[A-Za-z0-9._-]*\.desktop$/.test(base),
    uninstaller: () => win && base === 'Uninstall Niyanta Tracker.exe' && typeof ctx.execPath === 'string' && low(dir) === low(P.dirname(ctx.execPath)),
    helperDir: () => /^ny-uninstall-[A-Za-z0-9]{4,}$/.test(base) && (inside(H) || (typeof ctx.tmp === 'string' && low(dir) === low(P.normalize(ctx.tmp).replace(/[\\/]+$/, '')))) }[kind];
  return ok() ? s : null; }

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

/* ---------- sign in with the browser: niyanta-tracker://connect?ws=<workspace>&code=<code>[&state=<state>] ---------- */

const SCHEME = 'niyanta-tracker';
/** How long a sign-in started from the app (PKCE verifier + state) waits for the browser. */
const CONNECT_TTL_MS = 10 * 60000;
const CODE_RE = /^[A-Za-z0-9_-]{20,128}$/;
const STATE_RE = /^[A-Za-z0-9._~-]{16,64}$/;
const b64url = buf => Buffer.from(buf).toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');

/**
 * A workspace base URL from a deep link: https (http only for localhost), no user name or password, a host, at most 300
 * characters; returned as origin + path with one trailing slash (query and hash dropped), or null.
 */
function workspaceBase(v) {
  if (typeof v !== 'string' || !v || v.length > 300) return null;
  let u;
  try { u = new URL(v); } catch (e) { return null; }
  if (u.protocol !== 'https:' && !(u.protocol === 'http:' && LOCAL_HOSTS.includes(u.hostname))) return null;
  if (u.username || u.password || !u.hostname) return null;
  return u.origin + u.pathname.replace(/\/+$/, '') + '/'; }

/** Same workspace (origin and path, after workspaceBase)? */
function sameWorkspace(a, b) { const x = workspaceBase(String(a || '')), y = workspaceBase(String(b || '')); return !!x && x === y; }

/**
 * Parse a niyanta-tracker:// link strictly: the scheme, host "connect" (an empty path or "/"), no credentials or port, each
 * of ws / code / state at most once, ws a valid workspace (workspaceBase), code 20 to 128 of [A-Za-z0-9_-], state (optional)
 * 16 to 64 of [A-Za-z0-9._~-], at most 2048 characters. Returns {ws, code, state} (state null when absent) or null.
 */
function parseDeepLink(raw) {
  if (typeof raw !== 'string' || raw.length > 2048 || /[\s\x00-\x1f\x7f]/.test(raw)) return null;
  let u;
  try { u = new URL(raw); } catch (e) { return null; }
  if (u.protocol !== SCHEME + ':' || u.hostname !== 'connect' || u.username || u.password || u.port || !['', '/'].includes(u.pathname) || u.hash) return null;
  const q = u.searchParams;
  for (const k of ['ws', 'code', 'state']) if (q.getAll(k).length > 1) return null;
  const ws = workspaceBase(q.get('ws')), code = q.get('code'), state = q.get('state');
  if (!ws || !CODE_RE.test(code || '')) return null;
  if (state !== null && !STATE_RE.test(state)) return null;
  return { ws, code, state }; }

/** PKCE (RFC 7636, S256): a verifier of 32 random bytes (43 characters) and its challenge base64url(sha256(verifier)). */
function pkceVerifier() { return b64url(crypto.randomBytes(32)); }
function pkceChallenge(verifier) { return b64url(crypto.createHash('sha256').update(String(verifier), 'ascii').digest()); }
/** The state of a sign-in started from the app: 24 random bytes (32 characters). */
function newState() { return b64url(crypto.randomBytes(24)); }

/**
 * Does the state of a deep link belong to the sign-in this app started (`pending` = {state, verifier, ws, at})? 'ok',
 * 'expired' (older than CONNECT_TTL_MS or from the future), 'mismatch' or 'none' (nothing pending). Constant-time compare.
 */
function checkState(pending, state, now = Date.now()) {
  if (!pending || typeof pending.state !== 'string' || typeof pending.verifier !== 'string' || !Number.isFinite(pending.at)) return 'none';
  const a = Buffer.from(pending.state), b = Buffer.from(String(state || ''));
  if (a.length !== b.length || !crypto.timingSafeEqual(a, b)) return 'mismatch';
  if (now - pending.at > CONNECT_TTL_MS || pending.at - now > 60000) return 'expired';
  return 'ok'; }

/** The computer's name for the device list: control characters and anything unusual removed, at most 100 characters. */
function deviceName(host) { const s = String(host || '').replace(/[^\p{L}\p{N} ._'()-]+/gu, ' ').replace(/\s+/g, ' ').trim().slice(0, 100).trim(); return s || 'Computer'; }

/** win | mac | linux for the connect page. */
function osCode(platform) { return { win32: 'win', darwin: 'mac' }[platform] || 'linux'; }

/** The workspace page that signs the app in: <ws>time/desktop/connect?challenge=&state=&device=&os= (ws from workspaceBase). */
function connectPageUrl(ws, { challenge, state, device, os }) {
  const base = workspaceBase(ws);
  if (!base) return null;
  return base + 'time/desktop/connect?' + new URLSearchParams({ challenge, state, device: deviceName(device), os: ['win', 'mac', 'linux'].includes(os) ? os : 'linux' }).toString(); }

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

module.exports = { uninstallPath, UNINSTALL_KINDS, str, id, selection, allowedLink, sameOrigin, safeFileName, desktopExecArg, RateLimiter, newKey, encrypt, decrypt, isEncrypted, OPEN_TARGETS, PREF_KEYS, POMODORO_OPS, CONNECT_OPS, DISMISS_IDS, LINK_HOSTS,
  SCHEME, CONNECT_TTL_MS, workspaceBase, sameWorkspace, parseDeepLink, pkceVerifier, pkceChallenge, newState, checkState, deviceName, osCode, connectPageUrl };
