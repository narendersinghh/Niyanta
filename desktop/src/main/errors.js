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

function writeLocal(line) {
  try {
    fs.mkdirSync(path.dirname(logFile()), { recursive: true });
    const f = logFile();
    try { if (fs.statSync(f).size > 2 * 1048576) fs.renameSync(f, f + '.1'); } catch (e) { /* new file */ }
    fs.appendFileSync(f, `[${new Date().toISOString()}] ${line}\n`);
  } catch (e) { /* never throw from the reporter */ }
}

function attach(t) { tracker = t; }

/** Local log line only (not sent to the workspace). */
function log(line) { writeLocal('INFO ' + line); }

/** report(where, error, context?, level?) */
function report(where, err, context = {}, level = 'error') {
  try {
    const e = err instanceof Error ? err : new Error(String(err && err.message || err));
    const message = `${where}: ${e.message}`.slice(0, 900);
    writeLocal(`${level.toUpperCase()} ${message}${e.stack ? '\n' + e.stack : ''}`);
    const key = message.replace(/\d{3,}/g, 'N');
    const last = recent.get(key) || 0;
    if (Date.now() - last < 5 * 60000) return;
    recent.set(key, Date.now());
    const frame = /\((.*):(\d+):\d+\)/.exec(e.stack || '') || /at (.*):(\d+):\d+/.exec(e.stack || '');
    const json = { level, message, where, stack: (e.stack || '').slice(0, 6000), file: frame ? path.basename(frame[1]) : null, line: frame ? +frame[2] : null,
      os: `${process.platform} ${os.release()}`, arch: process.arch, app_version: app.getVersion(), context: Object.assign({ electron: process.versions.electron }, context) };
    if (tracker && tracker.api.base) tracker.queue.add({ type: 'error', path: 'error', json });
  } catch (x) { console.error('report failed', x); }
}

function installGlobalHandlers() {
  process.on('uncaughtException', e => report('main', e, {}, 'exception'));
  process.on('unhandledRejection', e => report('main', e instanceof Error ? e : new Error('Unhandled rejection: ' + String(e)), {}, 'rejection'));
  app.on('render-process-gone', (_ev, _wc, d) => report('renderer', new Error('Renderer process gone: ' + d.reason), { exitCode: d.exitCode }, 'fatal'));
  app.on('child-process-gone', (_ev, d) => { if (d.reason !== 'clean-exit') report('process', new Error(`${d.type} process gone: ${d.reason}`), { exitCode: d.exitCode }, 'warning'); });
}

module.exports = { report, log, attach, installGlobalHandlers, logFile };
