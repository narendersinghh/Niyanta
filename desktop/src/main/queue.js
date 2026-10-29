'use strict';
/* Offline queue: everything that must reach the server (tracking actions, heartbeats, idle answers, screenshots, error
   reports) goes through here. Jobs are persisted to userData/queue.json (screenshots as files in userData/pending), sent in
   order and retried with exponential backoff while the network or the server is unavailable. */
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');
const { EventEmitter } = require('events');
const { app } = require('electron');

const MAX_JOBS = 2000;
const MAX_AGE_MS = 24 * 3600 * 1000;
const MAX_SHOTS = 400;

class Queue extends EventEmitter {
  constructor(api) {
    super();
    this.api = api;
    this.dir = path.join(app.getPath('userData'), 'pending');
    this.file = path.join(app.getPath('userData'), 'queue.json');
    this.jobs = [];
    this.running = false;
    this.timer = null;
    this.failures = 0;
    this.paused = false;
    this.online = true;
    try { this.jobs = JSON.parse(fs.readFileSync(this.file, 'utf8')) || []; } catch (e) { this.jobs = []; }
  }

  persist() {
    try {
      fs.mkdirSync(path.dirname(this.file), { recursive: true });
      fs.writeFileSync(this.file + '.tmp', JSON.stringify(this.jobs));
      fs.renameSync(this.file + '.tmp', this.file);
    } catch (e) { console.error('queue persist failed', e); }
  }

  get size() { return this.jobs.length; }
  get pendingShots() { return this.jobs.filter(j => j.type === 'screenshot').length; }

  /** Add a job. Consecutive heartbeats are merged so a long offline period stays one small request. */
  add(job) {
    job = Object.assign({ id: crypto.randomBytes(6).toString('hex'), createdAt: Date.now(), tries: 0 }, job);
    const last = this.jobs[this.jobs.length - 1];
    if (job.type === 'heartbeat' && last && last.type === 'heartbeat' && !this.running) {
      last.json.active_seconds += job.json.active_seconds;
      last.json.idle_seconds += job.json.idle_seconds;
    } else {
      this.jobs.push(job);
    }
    this.trim();
    this.persist();
    this.emit('change');
    this.kick();
    return job;
  }

  /** Store screenshot bytes on disk and queue the upload. */
  addScreenshot(buffer, form) {
    fs.mkdirSync(this.dir, { recursive: true });
    const filePath = path.join(this.dir, Date.now() + '-' + crypto.randomBytes(4).toString('hex') + '.jpg');
    fs.writeFileSync(filePath, buffer);
    return this.add({ type: 'screenshot', path: 'screenshot', form, filePath });
  }

  trim() {
    const now = Date.now();
    const drop = j => { if (j.filePath) fs.rm(j.filePath, { force: true }, () => {}); };
    this.jobs = this.jobs.filter(j => { const keep = now - j.createdAt < MAX_AGE_MS; if (!keep) { drop(j); this.emit('dropped', j, 'expired'); } return keep; });
    let shots = this.jobs.filter(j => j.type === 'screenshot');
    while (shots.length > MAX_SHOTS) { const j = shots.shift(); drop(j); this.jobs.splice(this.jobs.indexOf(j), 1); }
    while (this.jobs.length > MAX_JOBS) { const j = this.jobs.shift(); drop(j); }
  }

  clear() {
    for (const j of this.jobs) if (j.filePath) fs.rm(j.filePath, { force: true }, () => {});
    this.jobs = [];
    this.persist();
    this.emit('change');
  }

  /** Remove queued screenshots (consent revoked or monitoring switched off). */
  dropScreenshots() {
    const before = this.jobs.length;
    this.jobs = this.jobs.filter(j => { if (j.type === 'screenshot') { if (j.filePath) fs.rm(j.filePath, { force: true }, () => {}); return false; } return true; });
    if (before !== this.jobs.length) { this.persist(); this.emit('change'); }
  }

  pause() { this.paused = true; }
  resume() { this.paused = false; this.kick(); }

  kick(delay = 0) {
    if (this.timer) { clearTimeout(this.timer); this.timer = null; }
    this.timer = setTimeout(() => { this.timer = null; this.run(); }, delay);
  }

  setOnline(on) { if (this.online !== on) { this.online = on; this.emit('online', on); } }

  async run() {
    if (this.running || this.paused || !this.jobs.length) return;
    this.running = true;
    try {
      while (this.jobs.length && !this.paused) {
        const job = this.jobs[0];
        try {
          const res = await this.send(job);
          this.jobs.shift();
          if (job.filePath) fs.rm(job.filePath, { force: true }, () => {});
          this.failures = 0;
          this.setOnline(true);
          this.persist();
          this.emit('done', job, res);
          this.emit('change');
        } catch (err) {
          if (err.status === 401) { this.paused = true; this.emit('unauthorized', err); break; }
          if (err.retryable) {
            job.tries++;
            this.failures++;
            this.setOnline(!err.offline);
            this.persist();
            const wait = Math.min(300000, 3000 * Math.pow(2, Math.min(this.failures, 7))) * (0.8 + Math.random() * 0.4);
            if (job.tries === 5) this.emit('stuck', job, err);
            this.kick(wait);
            break;
          }
          /* 4xx rule violation: the server refused this job for good (e.g. consent revoked); drop it and tell the app */
          this.jobs.shift();
          if (job.filePath) fs.rm(job.filePath, { force: true }, () => {});
          this.persist();
          this.emit('rejected', job, err);
          this.emit('change');
        }
      }
    } finally { this.running = false; }
  }

  async send(job) {
    if (job.type === 'screenshot') {
      let data;
      try { data = fs.readFileSync(job.filePath); } catch (e) { return { skipped: true }; }
      return this.api.request('POST', job.path, { form: job.form, file: { field: 'screenshot', name: path.basename(job.filePath), type: 'image/jpeg', data }, timeout: 60000 });
    }
    return this.api.request(job.method || 'POST', job.path, { json: job.json, auth: job.auth !== false });
  }
}

module.exports = { Queue };
