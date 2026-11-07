'use strict';
/* HTTP client for the Niyanta Tracker API (modules/Attendance/AGENT_API.md). Uses Electron's network stack (net.request),
   so system proxies and certificate stores apply; certificate errors are never ignored (main.js). Every request carries the
   device token and the app version. Redirects are followed only within the workspace's own origin, so the token never
   travels to another site; responses are capped in size and time. */
const { net, app } = require('electron');
const crypto = require('crypto');
const { sameOrigin } = require('./guard');

const MAX_JSON = 4 * 1048576;
const MAX_REDIRECTS = 5;

class ApiError extends Error {
  constructor(message, status, body) {
    super(message);
    this.status = status || 0;
    this.body = body || {};
    this.code = (body && body.code) || null;
    this.offline = !status && !['input', 'redirect'].includes(this.code); /* no HTTP answer at all: network down, DNS, TLS, timeout */ }
  get retryable() { return this.offline || this.status === 408 || this.status === 429 || this.status >= 500; } }

function normaliseUrl(input) {
  let u = String(input || '').trim();
  if (!u) throw new ApiError('Enter your workspace address, for example https://team.example.com', 0, { code: 'input' });
  if (/^[a-z][a-z0-9+.-]*:/i.test(u) && !/^https?:\/\//i.test(u)) throw new ApiError('Use an https:// address for your workspace.', 0, { code: 'input' });
  if (!/^https?:\/\//i.test(u)) u = 'https://' + u;
  let parsed;
  try { parsed = new URL(u); } catch (e) { throw new ApiError('That workspace address does not look right.', 0, { code: 'input' }); }
  if (parsed.protocol !== 'https:' && !['localhost', '127.0.0.1', '::1', '[::1]'].includes(parsed.hostname)) {
    throw new ApiError('Use an https:// address so your password and screenshots are encrypted in transit.', 0, { code: 'input' }); }
  if (parsed.username || parsed.password) throw new ApiError('The workspace address must not contain a user name or password.', 0, { code: 'input' });
  parsed.hash = ''; parsed.search = '';
  return parsed.toString().replace(/\/+$/, '') + '/'; }

function multipart(fields, file) {
  const boundary = '----niyanta' + crypto.randomBytes(12).toString('hex');
  const parts = [];
  const clean = s => String(s).replace(/[\r\n"]/g, '');
  for (const [k, v] of Object.entries(fields || {})) {
    if (v === undefined || v === null) continue;
    parts.push(Buffer.from(`--${boundary}\r\nContent-Disposition: form-data; name="${clean(k)}"\r\n\r\n${v}\r\n`)); }
  if (file) {
    parts.push(Buffer.from(`--${boundary}\r\nContent-Disposition: form-data; name="${clean(file.field)}"; filename="${clean(file.name)}"\r\nContent-Type: ${clean(file.type)}\r\n\r\n`));
    parts.push(file.data);
    parts.push(Buffer.from('\r\n')); }
  parts.push(Buffer.from(`--${boundary}--\r\n`));
  return { body: Buffer.concat(parts), type: `multipart/form-data; boundary=${boundary}` }; }

function userAgent() { return `NiyantaTracker/${app.getVersion()} (${process.platform}; ${process.arch})`; }

/**
 * One HTTP request through Electron's network stack. Resolves {status, headers, res, req} when the response headers are in
 * (the body is still streaming from `res`); rejects with an ApiError (offline) on network errors, on the connect timeout
 * and on a redirect that leaves the origin of `url`. Same-origin redirects are followed (at most MAX_REDIRECTS).
 */
function rawRequest(url, { method = 'GET', headers = {}, body, timeout = 20000 } = {}) {
  return new Promise((resolve, reject) => {
    let done = false; let hops = 0;
    const req = net.request({ url, method, redirect: 'manual', cache: 'no-store', useSessionCookies: false });
    const timer = setTimeout(() => { fail(new ApiError('The server did not answer in time.', 0, { code: 'timeout' })); req.abort(); }, timeout);
    const fail = err => { if (done) return; done = true; clearTimeout(timer); reject(err); };
    for (const [k, v] of Object.entries(headers)) if (v !== undefined && v !== null) req.setHeader(k, String(v));
    req.on('redirect', (status, _m, to) => {
      if (sameOrigin(url, to) && ++hops <= MAX_REDIRECTS) { req.followRedirect(); return; }
      req.abort();
      fail(new ApiError(hops > MAX_REDIRECTS ? 'The workspace redirects too many times.' : 'The workspace redirects to another site. Enter the final address of your workspace.', 0, { code: 'redirect', detail: String(to).slice(0, 200) })); });
    req.on('error', e => fail(new ApiError('Cannot reach your workspace. Check your connection.', 0, { detail: String(e && e.message || e).slice(0, 200) })));
    req.on('response', res => {
      if (done) { res.resume(); return; }
      done = true; clearTimeout(timer);
      const h = {};
      for (const [k, v] of Object.entries(res.headers || {})) h[k.toLowerCase()] = Array.isArray(v) ? v.join(', ') : String(v);
      resolve({ status: res.statusCode, headers: h, res, req }); });
    if (body !== undefined && body !== null) req.write(body);
    req.end(); }); }

/** The whole body as a Buffer, at most `max` bytes, within `timeout` ms; otherwise the request is aborted and it rejects. */
function readBody(r, max, timeout = 20000) {
  return new Promise((resolve, reject) => {
    const chunks = []; let size = 0; let done = false;
    const finish = (err, out) => { if (done) return; done = true; clearTimeout(timer); if (err) { try { r.req.abort(); } catch (e) { /* already closed */ } reject(err); } else resolve(out); };
    const timer = setTimeout(() => finish(new ApiError('The server did not answer in time.', 0, { code: 'timeout' })), timeout);
    r.res.on('data', c => { size += c.length; if (size > max) return finish(new ApiError('The server sent an answer that is too large.', r.status, { code: 'too_large' })); chunks.push(c); });
    r.res.on('end', () => finish(null, Buffer.concat(chunks)));
    r.res.on('error', e => finish(new ApiError('The connection was interrupted.', 0, { detail: String(e && e.message || e).slice(0, 200) })));
    r.res.on('aborted', () => finish(new ApiError('The connection was interrupted.', 0, { code: 'aborted' }))); }); }

class Api {
  constructor() { this.base = null; this.token = null; }
  configure(base, token) { this.base = base ? normaliseUrl(base) : null; this.token = token || null; }

  headers(auth = true) {
    const h = { Accept: 'application/json', 'X-App-Version': app.getVersion(), 'User-Agent': userAgent() };
    if (auth && this.token) { h.Authorization = 'Bearer ' + this.token; h['X-Agent-Token'] = this.token; }
    return h; }

  async request(method, path, { json, form, file, timeout = 20000, auth = true } = {}) {
    if (!this.base) throw new ApiError('Not connected to a workspace.', 0, { code: 'input' });
    const headers = this.headers(auth);
    let body;
    if (file || form) { const m = multipart(form, file); body = m.body; headers['Content-Type'] = m.type; }
    else if (json !== undefined) { body = JSON.stringify(json); headers['Content-Type'] = 'application/json'; }
    const url = this.base + 'api/agent/' + String(path).replace(/^\/+/, '');
    const r = await rawRequest(url, { method, headers, body, timeout });
    const text = (await readBody(r, MAX_JSON, timeout)).toString('utf8');
    let data = {};
    try { data = text ? JSON.parse(text) : {}; } catch (e) {
      throw new ApiError(r.status === 404 ? 'This address is not a Niyanta workspace (or time tracking is not installed).' : `Unexpected answer from the server (HTTP ${r.status}).`, r.status, { code: 'not_json', detail: text.slice(0, 200) }); }
    if (!data || typeof data !== 'object' || Array.isArray(data)) data = {};
    if (r.status < 200 || r.status >= 300 || data.ok === false) throw new ApiError(typeof data.error === 'string' ? data.error.slice(0, 300) : `Request failed (HTTP ${r.status}).`, r.status, data);
    return data; }

  get(path, opts) { return this.request('GET', path, opts); }
  post(path, json, opts) { return this.request('POST', path, Object.assign({ json }, opts)); } }

module.exports = { Api, ApiError, normaliseUrl, rawRequest, readBody, userAgent, MAX_REDIRECTS };
