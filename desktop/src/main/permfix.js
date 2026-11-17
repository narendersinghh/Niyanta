'use strict';
/*
 * macOS Screen Recording after an update (pure: no Electron, checked by scripts/prefs-check.js). macOS ties Screen
 * Recording to the app's designated requirement (DR, `codesign -d -r-`):
 *   - signed with the project's own self-signed certificate (desktop/SIGNING.md, free): the DR names the bundle id and the
 *     certificate (identifier "app.niyanta.tracker" and certificate ... = H"<sha1>"), the same for every version, so the
 *     grant survives updates and nothing is reset;
 *   - signed ad hoc (no certificate in CI): the DR is the cdhash of this one build, so every update has a new identity and
 *     the switch in System Settings can stay on while it no longer applies (captures fail although
 *     getMediaAccessStatus('screen') says 'granted').
 * When the identity changed (ad hoc, or a different DR than the version whose captures worked), the app resets its own
 * entry (capture.resetPermission(): tccutil, own bundle id, no admin rights, no restart) so the next capture makes macOS ask
 * again ("Allow", then Quit & Reopen):
 *   - proactively once after an update, when the previous version had working screenshots (store 'screenOk' = the last
 *     version whose capture worked, 'screenId' = its DR), so the prompt comes with the first capture instead of after a
 *     failed one;
 *   - when a capture fails while macOS says 'granted' (fallback, whatever the identity).
 * At most once per app version (store 'screenReset'), only on packaged macOS builds (never Windows, Linux or dev runs).
 * `waiting` is true from a reset until a capture works again (the window explains what to do).
 */

/** A DR that stays the same across builds: present and not tied to one build's code hash. */
function stableIdentity(dr) { return typeof dr === 'string' && /\bidentifier\b/.test(dr) && /\bcertificate\b/.test(dr) && !/\bcdhash\b/.test(dr); }

function create({ platform, packaged, version, store, reset, log = () => {} }) {
  const on = platform === 'darwin' && !!packaged && !!version;
  const fix = {
    waiting: false,
    identity: null,
    /** Reset once for this version; returns whether it ran. */
    reset(why) {
      if (!on || store.get('screenReset') === version) return false;
      store.set('screenReset', version);
      fix.waiting = true;
      log(`screen permission reset (${why})`);
      Promise.resolve().then(reset).then(ok => log(`screen permission reset: ${ok ? 'ok' : 'failed'}`), e => log(`screen permission reset failed: ${e && e.message}`));
      return true; },
    /**
     * App start: updated from a version whose screenshots worked. `identity` is this build's DR (null when unknown). A stable
     * DR equal to the one that worked keeps the grant: no reset.
     */
    atStart(identity = null) {
      fix.identity = typeof identity === 'string' ? identity : null;
      const okAt = store.get('screenOk');
      if (!okAt || okAt === version) return false;
      if (stableIdentity(fix.identity) && store.get('screenId') === fix.identity) { if (on) log('screen permission kept: same code identity as the previous version'); return false; }
      return fix.reset('updated'); },
    /** A capture worked: remember the version and its identity, nothing to wait for. */
    worked() {
      fix.waiting = false;
      if (on && store.get('screenOk') !== version) store.set('screenOk', version);
      if (on && fix.identity && store.get('screenId') !== fix.identity) store.set('screenId', fix.identity); },
    /** A capture failed with this permission status. */
    failed(permission) { return permission === 'granted' && fix.reset('capture failed while granted'); } };
  return fix; }

module.exports = { create, stableIdentity };
