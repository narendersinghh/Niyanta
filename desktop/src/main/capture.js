'use strict';
/* Full-desktop screenshots: every display is captured separately (desktopCapturer 'screen' sources, at most MAX_DISPLAYS),
   scaled to at most 1920 px wide and encoded as JPEG (quality 60). The small thumbnail for the capture notice is only made
   when the notice is on (a 160 px JPEG). macOS needs the Screen Recording permission. The Screen Recording settings pane is the only
   non-http link the app ever opens, and it is a fixed constant. */
const { desktopCapturer, screen, systemPreferences, shell } = require('electron');

const MAX_W = 1920;
const QUALITY = 60;
const MAX_DISPLAYS = 6;
/* the notice shows the thumbnail at 76 x 48: a small JPEG (about 5 KB) instead of a 240 px PNG data URL */
const THUMB_W = 160;
const SETTINGS_PANE = 'x-apple.systempreferences:com.apple.preference.security?Privacy_ScreenCapture';

/** 'granted' | 'denied' | 'restricted' | 'not-determined' | 'unknown' (only macOS asks for permission). */
function screenPermission() {
  if (process.platform !== 'darwin') return 'granted';
  try { return systemPreferences.getMediaAccessStatus('screen'); } catch (e) { return 'unknown'; } }

/**
 * macOS ties the Screen Recording permission to the app's code signature. Builds without an Apple Developer ID are signed
 * ad hoc, so every update has a new signature: the switch in System Settings can stay on while macOS no longer applies it
 * to this version. Resetting the app's own entry (tccutil, no admin rights) makes macOS ask again for the current version.
 */
const BUNDLE_ID = 'app.niyanta.tracker';
function resetPermission() {
  if (process.platform !== 'darwin') return Promise.resolve(false);
  return new Promise(res => require('child_process').execFile('/usr/bin/tccutil', ['reset', 'ScreenCapture', BUNDLE_ID], { timeout: 10000 }, err => res(!err))); }

function openPermissionSettings() {
  if (process.platform === 'darwin') return shell.openExternal(SETTINGS_PANE);
  return Promise.resolve(); }

/** Capture all displays. Returns [{display, index, jpeg: Buffer, thumb: dataURL | null, width, height}]. */
async function captureAll({ thumb = true } = {}) {
  const displays = screen.getAllDisplays().slice(0, MAX_DISPLAYS);
  const sizeOf = d => { const w = Math.round(d.size.width * (d.scaleFactor || 1)); const h = Math.round(d.size.height * (d.scaleFactor || 1)); const k = Math.min(1, MAX_W / w); return { width: Math.max(1, Math.round(w * k)), height: Math.max(1, Math.round(h * k)) }; };
  const biggest = displays.map(sizeOf).reduce((a, b) => ({ width: Math.max(a.width, b.width), height: Math.max(a.height, b.height) }), { width: 1, height: 1 });
  const sources = (await desktopCapturer.getSources({ types: ['screen'], thumbnailSize: biggest, fetchWindowIcons: false })).slice(0, MAX_DISPLAYS);
  if (!sources.length) throw new Error('No screen sources available (is screen capture allowed?)');
  const out = [];
  sources.forEach((src, i) => {
    const display = displays.find(d => String(d.id) === String(src.display_id)) || displays[i] || displays[0];
    let img = src.thumbnail;
    if (!img || img.isEmpty()) return;
    const want = sizeOf(display);
    const got = img.getSize();
    if (got.width > want.width + 2 || got.height > want.height + 2) img = img.resize({ width: want.width, height: want.height, quality: 'good' });
    const size = img.getSize();
    const idx = Math.max(0, displays.indexOf(display));
    out.push({ display: display.id, index: idx >= 0 ? idx : i, jpeg: img.toJPEG(QUALITY), thumb: thumb && i === 0 ? 'data:image/jpeg;base64,' + img.resize({ width: THUMB_W, quality: 'good' }).toJPEG(70).toString('base64') : null, width: size.width, height: size.height }); });
  if (!out.length) throw new Error('Screen capture returned empty images (permission missing?)');
  return out; }

module.exports = { resetPermission, BUNDLE_ID, captureAll, screenPermission, openPermissionSettings, MAX_DISPLAYS };
