'use strict';
/* HTTP client for the Niyanta Tracker API (modules/Attendance/AGENT_API.md). Uses Electron's network stack (net.fetch),
   so system proxies and certificate stores apply. Every request carries the device token and the app version. */
const { net, app } = require('electron');
const crypto = require('crypto');

class ApiError extends Error {
  constructor(message, status, body) {
    super(message);
    this.status = status || 0;
    this.body = body || {};
    this.code = (body && body.code) || null;
    this.offline = !status; /* no HTTP answer at all: network down, DNS, TLS, timeout */
  }
  get retryable() { return this.offline || this.status === 408 || this.status === 429 || this.status >= 500; }
}

function normaliseUrl(input) {
  let u = String(input || '').trim();
  if (!u) throw new ApiError('Enter your workspace address, for example https://team.example.com', 0, { code: 'input' });
  if (!/^https?:\/\//i.test(u)) u = 'https://' + u;
  let parsed;
  try { parsed = new URL(u); } catch (e) { throw new ApiError('That workspace address does not look right.', 0, { code: 'input' }); }
  if (parsed.protocol !== 'https:' && !['localhost', '127.0.0.1', '::1'].includes(parsed.hostname)) {
    throw new ApiError('Use an https:// address so your password and screenshots are encrypted in transit.', 0, { code: 'input' });
  }
  parsed.hash = ''; parsed.search = '';
  return parsed.toString().replace(/\/+$/, '') + '/';
}

function multipart(fields, file) {
  const boundary = '----niyanta' + crypto.randomBytes(12).toString('hex');
  const parts = [];
  for (const [k, v] of Object.entries(fields || {})) {
    if (v === undefined || v === null) continue;
    parts.push(Buffer.from(`--${boundary}\r\nContent-Disposition: form-data; name="${k}"\r\n\r\n${v}\r\n`));
  }
  if (file) {
    parts.push(Buffer.from(`--${boundary}\r\nContent-Disposition: form-data; name="${file.field}"; filename="${file.name}"\r\nContent-Type: ${file.type}\r\n\r\n`));
    parts.push(file.data);
    parts.push(Buffer.from('\r\n'));
  }
  parts.push(Buffer.from(`--${boundary}--\r\n`));
  return { body: Buffer.concat(parts), type: `multipart/form-data; boundary=${boundary}` };
}

class Api {
  constructor() { this.base = null; this.token = null; }
  configure(base, token) { this.base = base ? normaliseUrl(base) : null; this.token = token || null; }

  async request(method, path, { json, form, file, timeout = 20000, auth = true } = {}) {
    if (!this.base) throw new ApiError('Not connected to a workspace.', 0, { code: 'input' });
    const headers = { Accept: 'application/json', 'X-App-Version': app.getVersion(), 'User-Agent': `NiyantaTracker/${app.getVersion()} (${process.platform}; ${process.arch})` };
    if (auth && this.token) { headers.Authorization = 'Bearer ' + this.token; headers['X-Agent-Token'] = this.token; }
    let body;
    if (file || form) { const m = multipart(form, file); body = m.body; headers['Content-Type'] = m.type; }
    else if (json !== undefined) { body = JSON.stringify(json); headers['Content-Type'] = 'application/json'; }
    const ctrl = new AbortController();
    const timer = setTimeout(() => ctrl.abort(), timeout);
    let res;
    try {
      res = await net.fetch(this.base + 'api/agent/' + path.replace(/^\//, ''), { method, headers, body, signal: ctrl.signal, redirect: 'follow', cache: 'no-store' });
    } catch (e) {
      throw new ApiError(e.name === 'AbortError' ? 'The server did not answer in time.' : 'Cannot reach your workspace. Check your connection.', 0, { detail: String(e && e.message || e) });
    } finally { clearTimeout(timer); }
    const text = await res.text();
    let data = {};
    try { data = text ? JSON.parse(text) : {}; } catch (e) {
      throw new ApiError(res.status === 404 ? 'This address is not a Niyanta workspace (or time tracking is not installed).' : `Unexpected answer from the server (HTTP ${res.status}).`, res.status, { code: 'not_json', detail: text.slice(0, 200) });
    }
    if (!res.ok || data.ok === false) throw new ApiError(data.error || `Request failed (HTTP ${res.status}).`, res.status, data);
    return data;
  }

  get(path, opts) { return this.request('GET', path, opts); }
  post(path, json, opts) { return this.request('POST', path, Object.assign({ json }, opts)); }
}

module.exports = { Api, ApiError, normaliseUrl };
