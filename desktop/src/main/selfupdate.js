'use strict';
/*
 * Pure helpers for in-app updates (no Electron here, so scripts/updates-check.js can test them with plain Node):
 * version compare, the electron-builder feed (latest*.yml), release notes as short text, picking the right file for this
 * computer, how a Windows copy was installed (setup, MSI, portable zip, Store), sha512 checks, and the macOS helper script
 * that swaps the app bundle after the app has quit.
 * macOS without a Developer ID: Squirrel.Mac refuses ad-hoc signed apps, so the app downloads the zip itself (no quarantine
 * flag on files an app downloads), checks its sha512, extracts it with ditto and lets this script replace the bundle,
 * clear extended attributes (xattr -cr) and open the new version.
 */
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const APP_NAME = 'Niyanta Tracker';

/** Compare x.y.z[-pre] versions: -1, 0 or 1 (a prerelease sorts before its release). */
function cmpVersion(a, b) {
  const p = v => { const m = String(v || '').trim().replace(/^v/, '').match(/^(\d+)\.(\d+)\.(\d+)(?:-([0-9A-Za-z.]+))?/); return m ? [+m[1], +m[2], +m[3], m[4] || ''] : [0, 0, 0, '']; };
  const x = p(a), y = p(b);
  for (let i = 0; i < 3; i++) if (x[i] !== y[i]) return x[i] > y[i] ? 1 : -1;
  if (x[3] === y[3]) return 0;
  if (!x[3]) return 1;
  if (!y[3]) return -1;
  return x[3] > y[3] ? 1 : -1; }

/** electron-builder update file -> { version, files: [{url, name, sha512, size}], path, sha512, releaseNotes, releaseDate }. */
function parseFeed(text) {
  let doc;
  try { doc = require('js-yaml').load(String(text || '')); } catch (e) { throw new Error('The update file could not be read (' + e.message + ').'); }
  if (!doc || typeof doc !== 'object' || !doc.version) throw new Error('The update file has no version.');
  const files = (Array.isArray(doc.files) ? doc.files : []).filter(f => f && f.url).map(f => ({ url: String(f.url), name: baseName(f.url), sha512: f.sha512 ? String(f.sha512) : '', size: +f.size || 0 }));
  if (!files.length && doc.path) files.push({ url: String(doc.path), name: baseName(doc.path), sha512: String(doc.sha512 || ''), size: 0 });
  return { version: String(doc.version), files, path: doc.path ? String(doc.path) : '', sha512: doc.sha512 ? String(doc.sha512) : '', releaseNotes: doc.releaseNotes || null, releaseDate: doc.releaseDate ? String(doc.releaseDate) : '' }; }

function baseName(u) { const s = String(u || '').split(/[?#]/)[0]; return decodeURIComponent(s.slice(s.lastIndexOf('/') + 1)); }

/**
 * Release notes (string with Markdown/HTML, or electron-updater's [{version, note}]) as at most `max` short plain lines,
 * or [] when there is nothing useful. Never HTML: the renderer escapes them again.
 */
function notesLines(notes, max = 4) {
  if (!notes) return [];
  const raw = Array.isArray(notes) ? notes.map(n => (n && (n.note || n)) || '').join('\n') : String(notes);
  const text = raw.replace(/<br\s*\/?>|<\/(p|li|h\d|div)>/gi, '\n').replace(/<[^>]*>/g, '')
    .replace(/&nbsp;/g, ' ').replace(/&amp;/g, '&').replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&quot;/g, '"').replace(/&#39;/g, "'");
  const lines = text.split(/\r?\n/).map(l => l.replace(/^\s*(?:[-*+]|\d+[.)]|#{1,6})\s*/, '').replace(/\*\*|__|`/g, '').replace(/\s+/g, ' ').trim())
    .filter(l => l && !/^(what'?s new|changes|changelog|full changelog)\b:?$/i.test(l) && !/^\*{0,2}full changelog/i.test(l));
  return lines.slice(0, max).map(l => l.length > 140 ? l.slice(0, 137).trimEnd() + '...' : l); }

/** The macOS zip for this Mac (arm64 build on Apple silicon, also when the Intel build runs under Rosetta). */
function pickMacZip(files, arch) {
  const zips = (files || []).filter(f => /\.zip$/i.test(f.name));
  const want = arch === 'arm64' ? /-(arm64|aarch64)\.zip$/i : /-(x64|x86_64)\.zip$/i;
  return zips.find(f => want.test(f.name)) || zips.find(f => /universal/i.test(f.name)) || (arch === 'arm64' ? null : zips.find(f => !/arm64|aarch64/i.test(f.name))) || null; }

/**
 * The installer to download for a manual update, from /api/agent/version `files` ({windows|mac|linux|deb: [{name, arch, ext}]}).
 * kind: 'windows' (NSIS setup) | 'msi' (Windows MSI installer) | 'portable' (Windows zip) | 'mac' | 'linux' (AppImage) | 'deb'.
 * The MSI and the zip are listed under `windows` next to the setup.
 */
function pickInstaller(files, kind, arch) {
  const list = (files && files[kind === 'msi' || kind === 'portable' ? 'windows' : kind]) || [];
  const ext = { windows: ['exe'], msi: ['msi'], portable: ['zip'], mac: ['dmg', 'zip'], linux: ['appimage'], deb: ['deb'] }[kind] || [];
  const ok = list.filter(f => ext.includes(String(f.ext).toLowerCase()));
  ok.sort((a, b) => ext.indexOf(String(a.ext).toLowerCase()) - ext.indexOf(String(b.ext).toLowerCase()));
  return ok.find(f => f.arch === arch) || ok.find(f => f.arch === 'universal') || (kind === 'mac' ? null : ok[0]) || null; }

/** The uninstaller the NSIS setup puts next to the exe, and the marker file only the portable zip has (resources/). */
const NSIS_UNINSTALLER = 'Uninstall ' + APP_NAME + '.exe';
const PORTABLE_MARKER = 'niyanta-portable';

/**
 * How this Windows copy was installed, which decides how it updates (updater.js):
 *   'store'    Microsoft Store (process.windowsStore): the Store updates it
 *   'nsis'     the setup (.exe): its uninstaller is next to the exe; electron-updater updates it
 *   'portable' the zip: resources/niyanta-portable exists (scripts/dist-win.js adds it to the zip build only)
 *   'msi'      anything else: the MSI installer (neither the NSIS uninstaller nor the zip marker)
 * ctx: { windowsStore, execPath, resourcesPath, exists(path) }. The NSIS uninstaller wins, so a setup install never loses
 * electron-updater, even if an MSI or a zip was put into the same folder later.
 */
function windowsInstallKind(ctx) {
  const P = path.win32;
  if (ctx.windowsStore) return 'store';
  const exe = String(ctx.execPath || '');
  if (exe && ctx.exists(P.join(P.dirname(exe), NSIS_UNINSTALLER))) return 'nsis';
  const res = String(ctx.resourcesPath || '') || (exe && P.join(P.dirname(exe), 'resources'));
  if (res && ctx.exists(P.join(res, PORTABLE_MARKER))) return 'portable';
  return 'msi'; }

/** sha512 of a file, base64 (the format of latest*.yml). */
function sha512File(file) {
  return new Promise((resolve, reject) => {
    const h = crypto.createHash('sha512');
    fs.createReadStream(file).on('error', reject).on('data', d => h.update(d)).on('end', () => resolve(h.digest('base64'))); }); }

/** The .app bundle that contains the running executable (…/Niyanta Tracker.app/Contents/MacOS/Niyanta Tracker), or null. */
function macAppPath(execPath) { const m = String(execPath || '').match(/^(.*?\.app)\/Contents\/MacOS\/[^/]+$/); return m ? m[1] : null; }

/**
 * Where the new version goes: the running app's own place, unless it runs from the disk image (/Volumes), from App
 * Translocation (a quarantined app opened in place) or from a folder this user cannot write; then /Applications, and when
 * that is not writable either, ~/Applications. Returns { target, moved, reason }.
 * `canWrite(dir)` is injected (fs.accessSync W_OK in the app, a stub in tests).
 */
function macTarget(appPath, home, canWrite) {
  const name = (appPath && path.posix.basename(appPath)) || APP_NAME + '.app';
  const sys = path.posix.join('/Applications', name);
  const user = path.posix.join(home, 'Applications', name);
  const temporary = !appPath || /^\/Volumes\//.test(appPath) || /\/AppTranslocation\//.test(appPath) || /^\/private\/var\/folders\//.test(appPath);
  const writable = p => canWrite(path.posix.dirname(p)) && (!fs.existsSync(p) || canWrite(p));
  if (!temporary && writable(appPath)) return { target: appPath, moved: false, reason: null };
  if (writable(sys)) return { target: sys, moved: sys !== appPath, reason: temporary ? 'not-installed' : 'not-writable' };
  return { target: user, moved: true, reason: 'user-applications' }; }

/** Single-quote a string for bash. */
function sq(s) { return "'" + String(s).replace(/'/g, "'\\''") + "'"; }

/**
 * Bash script run detached after the app quits: waits for the app's process to end (at most 60 s), moves the old bundle
 * aside, copies the new one into place with ditto (restores the old one if that fails), clears extended attributes
 * (quarantine) with xattr -cr, removes the download folder and opens the app. Everything is logged to `log`.
 */
function macHelperScript({ pid, source, target, work, log }) {
  if (!/\.app$/.test(source) || !/\.app$/.test(target)) throw new Error('macHelperScript: source and target must be .app bundles');
  if (!Number.isInteger(pid) || pid <= 0) throw new Error('macHelperScript: bad pid');
  return [
    '#!/bin/bash',
    '# Niyanta Tracker update helper (generated by the app). Replaces the app after it has quit and opens the new version.',
    'set -uo pipefail',
    `PID=${pid}`,
    `SRC=${sq(source)}`,
    `DEST=${sq(target)}`,
    `WORK=${sq(work)}`,
    `LOG=${sq(log)}`,
    'exec >>"$LOG" 2>&1',
    'echo "$(date) update: waiting for the app (pid $PID) to quit"',
    'for _ in $(seq 1 120); do kill -0 "$PID" 2>/dev/null || break; sleep 0.5; done',
    'if kill -0 "$PID" 2>/dev/null; then echo "the app is still running, update skipped"; exit 1; fi',
    'mkdir -p "$(dirname "$DEST")"',
    'BAK="$DEST.previous"',
    'rm -rf "$BAK"',
    'if [ -e "$DEST" ] && ! mv "$DEST" "$BAK"; then echo "cannot move the old app aside"; open "$DEST"; exit 1; fi',
    'if ditto "$SRC" "$DEST"; then rm -rf "$BAK"; echo "installed $DEST"',
    'else echo "copy failed, restoring the previous version"; rm -rf "$DEST"; if [ -e "$BAK" ]; then mv "$BAK" "$DEST"; fi; fi',
    'xattr -cr "$DEST" 2>/dev/null || true',
    'rm -rf "$WORK"',
    'open "$DEST" || open -a "$(basename "$DEST" .app)"',
    ''].join('\n'); }

module.exports = { APP_NAME, NSIS_UNINSTALLER, PORTABLE_MARKER, cmpVersion, parseFeed, notesLines, pickMacZip, pickInstaller, windowsInstallKind, sha512File, macAppPath, macTarget, macHelperScript, sq, baseName };
