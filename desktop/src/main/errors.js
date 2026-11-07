'use strict';
/* Error reporting: every desktop error is written to a local log and sent to the workspace error log
   (POST /api/agent/error → Audit log › Error logs, source "desktop"), through the offline queue. */
const fs = require('fs');
const path = require('path');
const os = require('os');
const { app } = require('electron');

const recent = new Map();
let tracker = null;

function logFile() { return path.join(app.getPath('userData'), 'logs', 'tracker.log'); }

const MAX_LOG_BYTES = 2 * 1048576;
const KEEP_LOGS = 2;

/** Append one line (file mode 600); at MAX_LOG_BYTES the file rotates to .1 and .2, older ones are deleted. */
function writeLocal(line) {
  try {
    fs.mkdirSync(path.dirname(logFile()), { recursive: true, mode: 0o700 });
    const f = logFile();
    try {
      if (fs.statSync(f).size > MAX_LOG_BYTES) {
        fs.rmSync(`${f}.${KEEP_LOGS}`, { force: true });
        for (let i = KEEP_LOGS - 1; i >= 1; i--) { try { fs.renameSync(`${f}.${i}`, `${f}.${i + 1}`); } catch (e) { /* no such backup */ } }
        fs.renameSync(f, f + '.1'); } }
    catch (e) { /* new file */ }
    fs.appendFileSync(f, `[${new Date().toISOString()}] ${scrub(line)}\n`, { mode: 0o600 });
  } catch (e) { /* never throw from the reporter */ }
}

/** Nothing that looks like a bearer token or a password ever reaches the log or the server. */
function scrub(s) { return String(s).replace(/(Bearer\s+)[A-Za-z0-9._~+/=-]{8,}/gi, '$1[redacted]').replace(/("?(?:password|token|authorization|x-agent-token)"?\s*[:=]\s*)("?)(?!Bearer\b|\[redacted\])[^\s",}]{4,}/gi, '$1$2[redacted]'); }

function attach(t) { tracker = t; }

/** Local log line only (not sent to the workspace). */
function log(line) { writeLocal('INFO ' + line); }

/** report(where, error, context?, level?) */
function report(where, err, context = {}, level = 'error') {
  try {
    const e = err instanceof Error ? err : new Error(String(err && err.message || err));
    const message = scrub(`${where}: ${e.message}`).slice(0, 900);
    writeLocal(`${level.toUpperCase()} ${message}${e.stack ? '\n' + e.stack : ''}`);
    const key = message.replace(/\d{3,}/g, 'N');
    const last = recent.get(key) || 0;
    if (Date.now() - last < 5 * 60000) return;
    recent.set(key, Date.now());
    const frame = /\((.*):(\d+):\d+\)/.exec(e.stack || '') || /at (.*):(\d+):\d+/.exec(e.stack || '');
    const json = { level, message, where, stack: scrub(e.stack || '').slice(0, 6000), file: frame ? path.basename(frame[1]) : null, line: frame ? +frame[2] : null,
      os: `${process.platform} ${os.release()}`, arch: process.arch, app_version: app.getVersion(), context: JSON.parse(scrub(JSON.stringify(Object.assign({ electron: process.versions.electron }, context)))) };
    if (tracker && tracker.api.base) tracker.queue.add({ type: 'error', path: 'error', json });
  } catch (x) { console.error('report failed', x); }
}

function installGlobalHandlers() {
  process.on('uncaughtException', e => report('main', e, {}, 'exception'));
  process.on('unhandledRejection', e => report('main', e instanceof Error ? e : new Error('Unhandled rejection: ' + String(e)), {}, 'rejection'));
  app.on('render-process-gone', (_ev, _wc, d) => report('renderer', new Error('Renderer process gone: ' + d.reason), { exitCode: d.exitCode }, 'fatal'));
  app.on('child-process-gone', (_ev, d) => { if (d.reason !== 'clean-exit') report('process', new Error(`${d.type} process gone: ${d.reason}`), { exitCode: d.exitCode }, 'warning'); });
}

module.exports = { report, log, attach, installGlobalHandlers, logFile, scrub };
