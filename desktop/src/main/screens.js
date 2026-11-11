'use strict';
/* Window geometry on the displays (pure, no Electron; checked by scripts/prefs-check.js). Rectangles are {x, y, width,
   height} in device-independent pixels, as Electron's screen module reports work areas. */

/** Overlap of two rectangles: {w, h} (0 when apart). */
const overlap = (a, b) => ({ w: Math.max(0, Math.min(a.x + a.width, b.x + b.width) - Math.max(a.x, b.x)), h: Math.max(0, Math.min(a.y + a.height, b.y + b.height) - Math.max(a.y, b.y)) });

/**
 * The main window after a display change: unchanged (null) while at least a grab-able corner of it (80 x 60) is on some work
 * area; otherwise centred on `near` (the work area it overlaps most, or the primary one), no larger than that area.
 */
function fitWindow(b, areas, near) {
  if (!b || !Array.isArray(areas) || !areas.length) return null;
  if (areas.some(a => { const o = overlap(b, a); return o.w >= 80 && o.h >= 60; })) return null;
  const a = near || areas[0];
  const width = Math.min(b.width, a.width), height = Math.min(b.height, a.height);
  return { x: Math.round(a.x + (a.width - width) / 2), y: Math.round(a.y + (a.height - height) / 2), width, height }; }

/** The capture notice: bottom-right of the work area, `margin` from its edges, whole pixels, never outside it. */
function noticeBounds(a, w, h, margin = 16) {
  const width = Math.min(w, a.width), height = Math.min(h, a.height);
  return { x: Math.round(Math.max(a.x, a.x + a.width - width - margin)), y: Math.round(Math.max(a.y, a.y + a.height - height - margin)), width: Math.round(width), height: Math.round(height) }; }

module.exports = { fitWindow, noticeBounds, overlap };
