'use strict';
/*
 * "Uninstall Niyanta Tracker" (Settings): pure helpers, no Electron, tested by scripts/security-check.js. main.js stops and
 * syncs tracking, signs this device out, removes the login item and the niyanta-tracker:// handler, then starts the removal,
 * detached, and quits.
 *   Windows (NSIS, per user): the uninstaller electron-builder installed next to the exe, run directly with its documented
 *     arguments (NSIS_ARGS: silent, delete the app data). Nothing is written to a temporary folder and no script or shell
 *     runs: the same uninstaller Settings › Apps uses. build/installer.nsh removes the update cache and the
 *     niyanta-tracker:// key.
 * macOS / Linux: a /bin/sh helper (shScript) in its own temporary folder waits for the app's process to end, removes what
 * plan() lists (every path checked by guard.uninstallPath: absolute, expected name, expected place, never the home folder
 * or above), logs each step and deletes its folder at the end. When something could not be removed it keeps the log and
 * tells the person (a dialog on macOS, a notification on Linux).
 *   macOS: the .app (only in /Applications or ~/Applications), data, logs, caches, saved state, preferences
 *   Linux AppImage: the AppImage file, the .desktop entries the app created, data, caches
 *   Linux .deb (no APPIMAGE): data and entries only; removing the program needs root (sudo apt remove niyanta-tracker)
 */
const path = require('path');
const guard = require('./guard');

const APP = 'Niyanta Tracker';
const DEB_COMMAND = 'sudo apt remove niyanta-tracker';

/** Single-quote for /bin/sh. */
function sq(s) { return "'" + String(s).replace(/'/g, "'\\''") + "'"; }

/**
 * What to remove on this computer. ctx: {platform, packaged, execPath, appImage (process.env.APPIMAGE), home, userData,
 * tmp (os.tmpdir())}. Returns {ok, kind, remove: [{kind, path}], uninstaller, manual, error}. A path that does not pass
 * guard.uninstallPath is never listed; when the program itself cannot be located safely the plan is refused (ok false).
 */
function plan(ctx) {
  const { platform, home, userData } = ctx;
  if (!ctx.packaged) return { ok: false, error: 'Uninstall is only available in the installed app.' };
  const P = platform === 'win32' ? path.win32 : path.posix;
  const remove = [];
  const add = (kind, p) => { const v = guard.uninstallPath(p, kind, ctx); if (v && !remove.some(r => r.path === v)) remove.push({ kind, path: v }); return v; };
  const data = add('userData', userData);
  if (!data) return { ok: false, error: `The data folder (${userData}) is not where Niyanta Tracker keeps it, so nothing was removed.` };
  const out = { ok: true, kind: null, remove, uninstaller: null, manual: null };
  if (platform === 'win32') {
    const local = process.env.LOCALAPPDATA || P.join(home, 'AppData', 'Local');
    add('cache', P.join(local, 'niyanta-tracker-updater'));
    const un = guard.uninstallPath(P.join(P.dirname(ctx.execPath || ''), 'Uninstall Niyanta Tracker.exe'), 'uninstaller', ctx);
    if (!un || (ctx.exists && !ctx.exists(un))) return { ok: false, error: 'The Niyanta Tracker uninstaller was not found next to the app. Remove it in Windows Settings › Apps › Installed apps.' };
    out.kind = 'nsis'; out.uninstaller = un;
    return out; }
  if (platform === 'darwin') {
    const m = String(ctx.execPath || '').match(/^(.*?\.app)\/Contents\/MacOS\/[^/]+$/);
    const app = m && add('macApp', m[1]);
    if (!app) return { ok: false, error: `Niyanta Tracker is not in Applications (${m ? m[1] : ctx.execPath}), so it cannot remove itself. Quit it and move it to the Trash.` };
    add('cache', P.join(home, 'Library', 'Logs', APP));
    add('cache', P.join(home, 'Library', 'Caches', APP));
    add('cache', P.join(home, 'Library', 'Caches', 'niyanta-tracker-updater'));
    add('macState', P.join(home, 'Library', 'Saved Application State', 'app.niyanta.tracker.savedState'));
    add('macState', P.join(home, 'Library', 'Preferences', 'app.niyanta.tracker.plist'));
    out.kind = 'mac';
    return out; }
  add('cache', P.join(home, '.cache', 'niyanta-tracker-updater'));
  add('desktopEntry', P.join(home, '.local', 'share', 'applications', 'niyanta-tracker-url.desktop'));
  add('desktopEntry', P.join(home, '.config', 'autostart', 'niyanta-tracker.desktop'));
  if (ctx.appImage) {
    if (!add('appImage', ctx.appImage)) return { ok: false, error: `The AppImage (${ctx.appImage}) is not a Niyanta Tracker AppImage in your home folder, so nothing was removed. Delete it yourself.` };
    out.kind = 'appimage';
    return out; }
  out.kind = 'deb';
  out.manual = `Your data was removed. To remove the program, run: ${DEB_COMMAND}`;
  return out; }

/**
 * /bin/sh helper for macOS and Linux: wait for `pid` to end (at most 60 s), remove each path, log every step to `log`
 * (inside `dir`), then delete `dir` (the script and its log). Anything left: the log stays and the person is told.
 */
function shScript({ pid, remove, dir, log, platform }) {
  if (!Number.isInteger(pid) || pid <= 0) throw new Error('uninstall: bad pid');
  if (!/\/ny-uninstall-[A-Za-z0-9]+$/.test(dir) || !log.startsWith(dir + '/')) throw new Error('uninstall: bad helper folder');
  const lines = [
    '#!/bin/sh',
    '# Niyanta Tracker uninstall helper (generated by the app). Removes the app and its data after it has quit.',
    `PID=${pid}`, `DIR=${sq(dir)}`, `LOG=${sq(log)}`, 'LEFT=""',
    'exec >>"$LOG" 2>&1',
    'echo "$(date) uninstall: waiting for the app (pid $PID) to quit"',
    'i=0; while kill -0 "$PID" 2>/dev/null && [ $i -lt 120 ]; do sleep 0.5; i=$((i+1)); done',
    'gone() { if [ -e "$1" ] || [ -L "$1" ]; then rm -rf -- "$1"; fi; if [ -e "$1" ] || [ -L "$1" ]; then echo "could not remove $1"; LEFT="$LEFT$1\n"; else echo "removed $1"; fi; }'];
  for (const r of remove) lines.push(`gone ${sq(r.path)}`);
  lines.push('if [ -z "$LEFT" ]; then echo "done"; rm -rf -- "$DIR"; exit 0; fi');
  if (platform === 'darwin') lines.push(`/usr/bin/osascript -e 'display dialog "Niyanta Tracker could not remove everything. What is left is listed in ' "$LOG" '" buttons {"OK"} with title "Niyanta Tracker"' >/dev/null 2>&1 || true`);
  else lines.push(`command -v notify-send >/dev/null 2>&1 && notify-send "Niyanta Tracker" "Could not remove everything. What is left is listed in $LOG" || true`);
  lines.push('exit 1', '');
  return lines.join('\n'); }

/**
 * Windows: the arguments for the NSIS uninstaller electron-builder installs next to the app ("Uninstall Niyanta
 * Tracker.exe"): silent, and --delete-app-data (electron-builder's own option) removes %APPDATA%\Niyanta Tracker too.
 * The uninstaller waits for the app to quit (it closes it if needed) and build/installer.nsh removes the update cache.
 */
const NSIS_ARGS = ['/S', '--delete-app-data'];

module.exports = { plan, shScript, sq, DEB_COMMAND, NSIS_ARGS };
