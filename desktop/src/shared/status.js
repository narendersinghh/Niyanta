/*
 * What the big counter shows: the time of the current status, not the day total. Pure (no Electron, no DOM), loaded by the
 * renderer as a plain script (window.NyStatus) and by scripts/status-check.js through require().
 *   tracking / idle / meeting: the current session = the running entry, which starts at the last Start, Resume or project
 *     switch (and at midnight, when the day rolls over); its elapsed seconds rolled forward to now
 *   paused: how long it has been paused (since day.paused_since, on the server's clock)
 *   not started / stopped / ended: 0
 * The day total (tracked seconds today) is returned separately for the medium line below the counter.
 */
(function (root, factory) { if (typeof module === 'object' && module.exports) module.exports = factory(); else root.NyStatus = factory(); }(typeof self !== 'undefined' ? self : this, function () {
  'use strict';
  const MEETING_MS = 3600000;
  const LABELS = { tracking: 'Tracking', idle: 'Idle', meeting: 'In a meeting', paused: 'Paused', offline: 'Offline', stopped: 'Not tracking', ended: 'Not tracking', not_started: 'Not started' };

  /**
   * ui: {state (the tracker's view(): state, running, day, totals, server_time), at (ms the view is valid for), idle (bool),
   * skew (ms: server clock - local clock), meeting ({since, until} ms or null), offline}. now: ms (default Date.now()).
   * Returns {kind, label, seconds (current status), since (ms or null), total (day total seconds), meetingLeft (ms or null)}.
   */
  function current(ui, now) {
    now = now === undefined ? Date.now() : now;
    const st = (ui && ui.state) || { state: 'not_started', totals: {}, day: {} };
    const live = Math.max(0, (now - ((ui && ui.at) || now)) / 1000);
    const tracking = st.state === 'tracking';
    const totals = st.totals || {};
    const total = Math.max(0, Math.floor((+totals.tracked || 0) + (tracking ? live : 0)));
    const meeting = tracking && ui.meeting && +ui.meeting.until > now ? ui.meeting : null;
    const out = { kind: st.state || 'not_started', seconds: 0, since: null, total, meetingLeft: meeting ? +meeting.until - now : null };
    if (tracking) {
      const r = st.running || {};
      out.kind = meeting ? 'meeting' : (ui.idle ? 'idle' : 'tracking');
      out.seconds = Math.max(0, Math.floor((+r.elapsed || 0) + live));
      out.since = r.started_at ? Date.parse(r.started_at) - ((ui && +ui.skew) || 0) : null; }
    else if (st.state === 'paused') {
      const p = st.day && st.day.paused_since ? Date.parse(st.day.paused_since) : NaN;
      if (Number.isFinite(p)) { out.since = p - ((ui && +ui.skew) || 0); out.seconds = Math.max(0, Math.floor((now - out.since) / 1000)); }
      else out.seconds = Math.max(0, Math.floor(+totals.paused || 0)); }
    out.label = (ui && ui.offline && tracking ? 'Offline · ' : '') + (LABELS[out.kind] || st.label || '');
    return out; }

  const pad = n => String(n).padStart(2, '0');
  /** 3725 -> "1:02:05"; under an hour "02:05" is never used: the counter always shows h:mm:ss so it does not jump in width. */
  function hms(s) { s = Math.max(0, Math.floor(s || 0)); return `${Math.floor(s / 3600)}:${pad(Math.floor(s % 3600 / 60))}:${pad(s % 60)}`; }
  function hm(s) { s = Math.max(0, Math.floor(s || 0)); return `${Math.floor(s / 3600)}h ${pad(Math.floor(s % 3600 / 60))}m`; }
  function mmss(ms) { const s = Math.max(0, Math.ceil(ms / 1000)); return `${Math.floor(s / 60)}:${pad(s % 60)}`; }

  return { current, hms, hm, mmss, MEETING_MS, LABELS }; }));
