'use strict';
/*
 * "Uninstall Niyanta Tracker" (Settings): pure helpers, no Electron, tested by scripts/security-check.js. main.js stops and
 * syncs tracking, signs this device out, removes the login item and the niyanta-tracker:// handler, then runs the helper
 * this module writes, detached, and quits. The helper waits for the app's process to end, removes what plan() lists (every
 * path checked by guard.uninstallPath: absolute, expected name, expected place, never the home folder or above), logs each
 * step to a small file in its own temporary folder and deletes that folder at the end. When something could not be
 * removed it keeps the log and tells the person (a dialog on macOS, a notification on Linux, the log in Notepad on Windows).
 *   Windows (NSIS, per user): the installed uninstaller next to the exe, silently (/S), then the data folder
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

/** cmd.exe helper for Windows: wait for `pid`, run the uninstaller silently, wait for it, remove the rest, log, clean up. */
function cmdScript({ pid, uninstaller, remove, dir, log, exe }) {
  if (!Number.isInteger(pid) || pid <= 0) throw new Error('uninstall: bad pid');
  const safe = s => typeof s === 'string' && path.win32.isAbsolute(s) && !/["%!^&|<>\r\n]/.test(s);
  if (![uninstaller, dir, log, exe].every(safe) || !/\\ny-uninstall-[A-Za-z0-9]+$/.test(dir) || !log.startsWith(dir + '\\') || remove.some(r => !safe(r.path))) throw new Error('uninstall: unsafe path');
  const L = [
    '@echo off',
    'rem Niyanta Tracker uninstall helper (generated by the app). Removes the app and its data after it has quit.',
    'setlocal',
    `set "LOG=${log}"`, 'set LEFT=0',
    `echo uninstall: waiting for the app (pid ${pid}) to quit>>"%LOG%"`,
    'set /a N=0',
    ':wait',
    `tasklist /FI "PID eq ${pid}" /NH 2>nul | find "${pid}" >nul`,
    'if not errorlevel 1 if %N% lss 60 (set /a N+=1 & timeout /t 1 /nobreak >nul & goto wait)',
    `echo running "${uninstaller}" /S>>"%LOG%"`,
    `"${uninstaller}" /S`,
    'set /a N=0',
    ':un',
    `if exist "${exe}" if %N% lss 90 (set /a N+=1 & timeout /t 1 /nobreak >nul & goto un)`,
    `if exist "${exe}" (echo could not remove the program "${exe}">>"%LOG%" & set LEFT=1) else (echo removed the program>>"%LOG%")`];
  for (const r of remove) {
    L.push(`if exist "${r.path}\\*" (rmdir /s /q "${r.path}") else if exist "${r.path}" (del /f /q "${r.path}")`);
    L.push(`if exist "${r.path}" (echo could not remove "${r.path}">>"%LOG%" & set LEFT=1) else (echo removed "${r.path}">>"%LOG%")`); }
  L.push('if %LEFT%==1 (start "" notepad "%LOG%" & exit /b 1)', `cd /d "%TEMP%"`, `(goto) 2>nul & rmdir /s /q "${dir}"`, '');
  return L.join('\r\n'); }

module.exports = { plan, shScript, cmdScript, sq, DEB_COMMAND };
