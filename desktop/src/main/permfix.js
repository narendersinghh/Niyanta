'use strict';
/*
 * macOS Screen Recording after an update (pure: no Electron, checked by scripts/prefs-check.js). Builds without an Apple
 * Developer ID are signed ad hoc, so every update has a new code signature; macOS ties Screen Recording to the signature, so
 * the switch in System Settings can stay on while it no longer applies to the new version (captures fail although
 * getMediaAccessStatus('screen') says 'granted'). The app then resets its own entry (capture.resetPermission(): tccutil,
 * own bundle id, no admin rights, no restart) so the next capture makes macOS ask again ("Allow", then Quit & Reopen):
 *   - proactively once after an update, when the previous version had working screenshots (store 'screenOk' = the last
 *     version whose capture worked), so the prompt comes with the first capture instead of after a failed one;
 *   - when a capture fails while macOS says 'granted'.
 * At most once per app version (store 'screenReset'), only on packaged macOS builds (never Windows, Linux or dev runs).
 * `waiting` is true from a reset until a capture works again (the window explains what to do).
 */
function create({ platform, packaged, version, store, reset, log = () => {} }) {
  const on = platform === 'darwin' && !!packaged && !!version;
  const fix = {
    waiting: false,
    /** Reset once for this version; returns whether it ran. */
    reset(why) {
      if (!on || store.get('screenReset') === version) return false;
      store.set('screenReset', version);
      fix.waiting = true;
      log(`screen permission reset (${why})`);
      Promise.resolve().then(reset).then(ok => log(`screen permission reset: ${ok ? 'ok' : 'failed'}`), e => log(`screen permission reset failed: ${e && e.message}`));
      return true; },
    /** App start: updated from a version whose screenshots worked. */
    atStart() { const okAt = store.get('screenOk'); return !!okAt && okAt !== version && fix.reset('updated'); },
    /** A capture worked: remember the version, nothing to wait for. */
    worked() { fix.waiting = false; if (on && store.get('screenOk') !== version) store.set('screenOk', version); },
    /** A capture failed with this permission status. */
    failed(permission) { return permission === 'granted' && fix.reset('capture failed while granted'); } };
  return fix; }

module.exports = { create };
