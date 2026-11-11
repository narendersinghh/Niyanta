'use strict';
/*
 * The status dot on the taskbar / dock icon, drawn in memory (no image files): BGRA, premultiplied alpha, the pixel format of
 * nativeImage.toBitmap() / createFromBitmap(). Pure (no Electron), checked by scripts/status-check.js. Steady, no blinking:
 * green (brand mint #12B5A0) while tracking, red (#EF4444) while paused or not tracking; a thin white ring keeps it readable
 * on light and dark taskbars.
 */
const MINT = { r: 0x12, g: 0xB5, b: 0xA0 };
const RED = { r: 0xEF, g: 0x44, b: 0x44 };
const COLORS = { on: MINT, off: RED };

/** A size x size dot: coverage-based anti-aliasing (4x4 samples per pixel), ring of `ring` px in white. */
function dot(size, alpha = 1, color = MINT, ring = Math.max(1, size / 12)) {
  const buf = Buffer.alloc(size * size * 4);
  const c = size / 2, R = size / 2 - 0.5, inner = R - ring;
  for (let y = 0; y < size; y++) for (let x = 0; x < size; x++) {
    let cov = 0, core = 0;
    for (let sy = 0; sy < 4; sy++) for (let sx = 0; sx < 4; sx++) {
      const d = Math.hypot(x + (sx + 0.5) / 4 - c, y + (sy + 0.5) / 4 - c);
      if (d <= R) { cov++; if (d <= inner) core++; } }
    if (!cov) continue;
    const a = alpha * cov / 16, k = core / cov;
    const r = color.r * k + 255 * (1 - k), g = color.g * k + 255 * (1 - k), b = color.b * k + 255 * (1 - k);
    const i = (y * size + x) * 4;
    buf[i] = Math.round(b * a); buf[i + 1] = Math.round(g * a); buf[i + 2] = Math.round(r * a); buf[i + 3] = Math.round(255 * a); }
  return buf; }

/** Copy of the w x h bitmap `base` with `src` (s x s) drawn over it at (x, y), source-over in premultiplied alpha. */
function stamp(base, w, h, src, s, x, y) {
  const out = Buffer.from(base);
  for (let j = 0; j < s; j++) for (let i = 0; i < s; i++) {
    const px = x + i, py = y + j;
    if (px < 0 || py < 0 || px >= w || py >= h) continue;
    const si = (j * s + i) * 4, di = (py * w + px) * 4, sa = src[si + 3] / 255;
    if (!src[si + 3]) continue;
    for (let k = 0; k < 4; k++) out[di + k] = Math.min(255, Math.round(src[si + k] + out[di + k] * (1 - sa))); }
  return out; }

/** Icon with the dot in its lower right corner (dot = 38% of the icon, inset 2%). */
function onIcon(base, w, h, color = MINT) {
  const s = Math.round(Math.min(w, h) * 0.38), m = Math.round(Math.min(w, h) * 0.02);
  return stamp(base, w, h, dot(s, 1, color), s, w - s - m, h - s - m); }

module.exports = { MINT, RED, COLORS, dot, stamp, onIcon };
