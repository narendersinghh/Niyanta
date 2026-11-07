'use strict';
/*
 * "Sign in with your browser" (no Electron here: main.js passes what it needs, so scripts/security-check.js runs it with
 * fakes). The browser hands the app a one-time code with the deep link niyanta-tracker://connect?ws=&code=[&state=]
 * (guard.parseDeepLink); the app trades it at POST <ws>/api/agent/connect (tracker.connect) for a device token, exactly like
 * a password sign-in. Two ways in:
 *   A. a link from the website or the install script (no state): signed out, or signed in to another workspace, the window
 *      asks "Connect to <host> as the person signed in on that website?" (the first time a host is seen, and always when it
 *      would switch workspace; confirmed hosts are remembered). Signed in to the same workspace: "Already connected".
 *   B. the button in the app: a PKCE verifier + state (kept in memory and, sealed, in the config for at most 10 minutes so
 *      a relaunch by the link still works), the browser opens <ws>time/desktop/connect?challenge=&state=&device=&os=, and
 *      only a link with that state is accepted (with the verifier). Other states are ignored with a message.
 * The desktop session never depends on the website: signing out there does not sign the app out.
 */
const guard = require('./guard');

const PENDING = 'connectPending';
const HOSTS = 'connectHosts';
const ASK_TTL_MS = 5 * 60000;
const FRIENDLY = {
  code_invalid: 'This sign-in link was already used or is not valid any more. Please try again.',
  code_expired: 'This sign-in link has expired. Please try again.',
  verifier_mismatch: 'This sign-in was started on another computer or in another app. Please try again from here.',
  disabled: 'Time tracking is turned off for this workspace. Ask your administrator.',
  forbidden: 'Your role is not allowed to track time. Ask your administrator.',
  locked: 'Too many sign-in attempts. Please wait 15 minutes and try again.' };
const hostOf = ws => { try { return new URL(ws).host; } catch (e) { return ''; } };
const originOf = ws => { try { return new URL(ws).origin; } catch (e) { return ''; } };

/** Message for a failed exchange: the server's codes get a friendly text, other errors keep theirs. */
function friendly(code, fallback) { return FRIENDLY[code] || String(fallback || 'Could not sign in. Please try again.').slice(0, 300); }

class Connect {
  /** deps: tracker, store, open(url, ws) -> bool, focus(), device (host name), platform, normalise(url) (throws), now(), log(msg) */
  constructor(deps) {
    this.d = Object.assign({ now: Date.now, log: () => {}, focus: () => {}, normalise: u => { const w = guard.workspaceBase(u); if (!w) throw new Error('Enter your https:// workspace address.'); return w; } }, deps);
    this.phase = null;      /* null | waiting (browser opened) | confirm (flow A asks) | working | error */
    this.pending = null;    /* flow B: {ws, state, verifier, at} */
    this.ask = null;        /* flow A waiting for the confirmation: {ws, code, at, switching} */
    this.ws = null;         /* the workspace of the current / last attempt (Try again) */
    this.message = null;    /* {text, kind: error | info} */
    this.retry = false;
    this.limiter = new guard.RateLimiter({ link: 20 }, 60000, 20);
    this.timer = null;
    const p = this.d.store.getSealed(PENDING);
    if (p && guard.checkState(p, p.state, this.d.now()) === 'ok' && guard.workspaceBase(p.ws)) { this.pending = p; this.ws = p.ws; this.phase = 'waiting'; this.arm(); }
    else if (p) this.d.store.setSealed(PENDING, null); }

  changed() { this.d.tracker.changed(); }

  view() {
    const t = this.d.tracker;
    return { phase: this.phase, host: hostOf(this.ask ? this.ask.ws : this.ws), switching: !!(this.ask && this.ask.switching), current: t.signedIn ? hostOf(t.api.base) : '',
      message: this.message, retry: this.retry, until: this.pending ? this.pending.at + guard.CONNECT_TTL_MS : null }; }

  known(ws) { return (this.d.store.get(HOSTS) || []).includes(originOf(ws)); }
  remember(ws) { const o = originOf(ws); if (!o) return; const list = (this.d.store.get(HOSTS) || []).filter(x => typeof x === 'string' && x !== o); list.unshift(o); this.d.store.set(HOSTS, list.slice(0, 20)); }

  setPending(p) { this.pending = p; this.d.store.setSealed(PENDING, p); this.arm(); }
  arm() {
    clearTimeout(this.timer); this.timer = null;
    if (!this.pending) return;
    this.timer = setTimeout(() => { if (this.pending && guard.checkState(this.pending, this.pending.state, this.d.now()) !== 'ok') this.fail(this.pending.ws, 'Nothing came back from your browser in 10 minutes. Please try again.', true); },
      Math.max(1000, this.pending.at + guard.CONNECT_TTL_MS - this.d.now() + 500));
    if (this.timer.unref) this.timer.unref(); }

  /** Flow B: open the workspace's connect page in the browser. `input` is the workspace address (else the stored one). */
  browser(input) {
    let ws;
    try { ws = this.d.normalise(input || this.d.store.get('workspaceUrl') || ''); }
    catch (e) { this.ws = null; return this.fail(null, e.message, false); }
    const verifier = guard.pkceVerifier(), state = guard.newState();
    const url = guard.connectPageUrl(ws, { challenge: guard.pkceChallenge(verifier), state, device: this.d.device, os: guard.osCode(this.d.platform) });
    this.ws = ws; this.ask = null; this.message = null; this.retry = false;
    this.setPending({ ws, state, verifier, at: this.d.now() });
    if (!url || !this.d.open(url, ws)) { this.clearPending(); return this.fail(ws, 'Could not open your browser. Please try again.', true); }
    this.phase = 'waiting'; this.changed();
    return { ok: true }; }

  clearPending() { this.pending = null; clearTimeout(this.timer); this.timer = null; this.d.store.setSealed(PENDING, null); }

  /** A niyanta-tracker:// link arrived (open-url, second-instance argv, first launch argv). */
  async link(raw) {
    const t = this.d.tracker;
    this.d.focus();
    if (!this.limiter.allow('link', this.d.now())) return { ok: false };
    const l = guard.parseDeepLink(raw);
    if (!l) { this.d.log('connect: refused an invalid sign-in link'); return this.say('This sign-in link is not valid. Open it again from your workspace.', 'error'); }
    if (this.phase === 'working') return { ok: false };
    if (l.state) {
      const st = guard.checkState(this.pending, l.state, this.d.now());
      if (st === 'ok' && guard.sameWorkspace(l.ws, this.pending.ws)) { const p = this.pending; this.clearPending(); return this.exchange(p.ws, l.code, p.verifier); }
      if (st === 'expired') { const ws = this.pending.ws; this.clearPending(); return this.fail(ws, 'This sign-in request has expired. Please try again.', true); }
      this.d.log(`connect: ignored a sign-in link with an unknown state (${st})`);
      return this.say('Ignored a sign-in link that was not started from this app. If you just signed in, please try again.', 'error'); }
    if (t.signedIn && guard.sameWorkspace(l.ws, t.api.base)) {
      const u = t.boot && t.boot.user;
      t.flash(`Already connected to ${hostOf(l.ws)}${u && u.name ? ' as ' + u.name : ''}. To use another account, sign out first.`, 'info');
      return { ok: true, already: true }; }
    const switching = !!t.signedIn;
    if (switching || !this.known(l.ws)) { this.ask = { ws: l.ws, code: l.code, at: this.d.now(), switching }; this.ws = l.ws; this.phase = 'confirm'; this.message = null; this.changed(); return { ok: true, asked: true }; }
    return this.exchange(l.ws, l.code, null); }

  /** Flow A, the person said yes. */
  confirm() {
    const a = this.ask;
    this.ask = null;
    if (!a) { this.phase = null; this.changed(); return { ok: false }; }
    if (this.d.now() - a.at > ASK_TTL_MS) return this.fail(a.ws, FRIENDLY.code_expired, true);
    this.remember(a.ws);
    return this.exchange(a.ws, a.code, null); }

  decline() { this.ask = null; this.phase = null; this.message = null; this.changed(); return { ok: true }; }
  cancel() { this.clearPending(); this.phase = null; this.message = null; this.retry = false; this.changed(); return { ok: true }; }
  dismiss() { this.message = null; this.retry = false; if (this.phase === 'error') this.phase = null; this.changed(); return { ok: true }; }

  async exchange(ws, code, verifier) {
    this.phase = 'working'; this.ws = ws; this.message = null; this.retry = false; this.changed();
    const body = { code, device_name: guard.deviceName(this.d.device) };
    if (verifier) body.verifier = verifier;
    const r = await this.d.tracker.connect(ws, body);
    if (r && r.ok) {
      this.remember(ws); this.phase = null; this.ws = ws; this.changed();
      this.d.tracker.flash(`Connected to ${hostOf(ws)}${r.user && r.user.name ? ' as ' + r.user.name : ''}.`, 'info');
      return { ok: true }; }
    return this.fail(ws, friendly(r && r.code, r && r.error), true); }

  /** A failed attempt: on the sign-in screen an error with Try again; while signed in, a banner in the window. */
  fail(ws, text, retry) {
    this.ws = ws || this.ws; this.ask = null;
    if (this.d.tracker.signedIn) { this.phase = null; this.message = null; this.retry = false; this.d.tracker.flash(text, 'error'); return { ok: false, error: text }; }
    this.phase = 'error'; this.message = { text, kind: 'error' }; this.retry = !!retry && !!this.ws; this.changed();
    return { ok: false, error: text }; }

  /** A note that does not change the flow (an invalid or foreign link while waiting). */
  say(text, kind) {
    if (this.d.tracker.signedIn) { this.d.tracker.flash(text, kind); return { ok: false }; }
    this.message = { text, kind }; this.changed();
    return { ok: false }; }

  /** tracker:connect from the window (op allowlisted by guard.CONNECT_OPS). */
  op(name, url) {
    if (name === 'browser') return this.browser(url);
    if (name === 'retry') return this.browser(this.ws || url);
    if (name === 'cancel') return this.cancel();
    if (name === 'confirm') return this.confirm();
    if (name === 'decline') return this.decline();
    return this.dismiss(); } }

module.exports = { Connect, friendly, FRIENDLY, ASK_TTL_MS };
