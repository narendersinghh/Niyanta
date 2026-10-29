'use strict';
/* Regenerates the app and tray icons from the Niyanta bird logo with Electron itself (no image libraries needed):
     npx electron scripts/icons.js        (on Linux CI without a display: xvfb-run npx electron scripts/icons.js)
   Writes build/icon.png (1024, converted by electron-builder to .ico / .icns), build/icons/<n>x<n>.png (Linux),
   src/renderer/icon.png (window icon) and build/tray/*.png (tray states; trayTemplate*.png for the macOS menu bar). */
const fs = require('fs');
const path = require('path');
const { app, BrowserWindow } = require('electron');

const root = path.join(__dirname, '..');
const logo = fs.readFileSync(path.join(root, 'src', 'renderer', 'logo.svg'), 'utf8');
const dot = c => logo.replace('</svg>', `<circle cx="412" cy="412" r="96" fill="${c}" stroke="#fff" stroke-width="28"/></svg>`);
/* macOS template: black silhouette of the logo's bird-clock (rounded square outline, clock face with a hand) */
const template = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect x="5" y="5" width="54" height="54" rx="14" fill="none" stroke="#000" stroke-width="5"/><circle cx="31" cy="36" r="15" fill="#000"/><path d="M31 36h12a12 12 0 0 1-12 12z" fill="#fff"/><path d="M18 30l-9 3 9 3z" fill="#000"/><path d="M28 20c-2-5 1-9 5-8-2 2-2 5-1 8z" fill="#000"/></svg>';

const jobs = [
  ['build/icon.png', logo, 1024],
  ...[16, 24, 32, 48, 64, 128, 256, 512].map(n => [`build/icons/${n}x${n}.png`, logo, n]),
  ['src/renderer/icon.png', logo, 256],
  ['build/tray/tray.png', logo, 32], ['build/tray/tray@2x.png', logo, 64],
  ['build/tray/tray-active.png', dot('#12B5A0'), 32], ['build/tray/tray-paused.png', dot('#F5A524'), 32], ['build/tray/tray-offline.png', dot('#9896B5'), 32],
  ['build/tray/trayTemplate.png', template, 16], ['build/tray/trayTemplate@2x.png', template, 32]
];

app.disableHardwareAcceleration();
app.whenReady().then(async () => {
  const win = new BrowserWindow({ show: false, width: 1024, height: 1024, frame: false, transparent: true, webPreferences: { offscreen: true } });
  for (const [out, svg, size] of jobs) {
    const html = `<html><body style="margin:0;background:transparent"><img src="data:image/svg+xml;base64,${Buffer.from(svg).toString('base64')}" width="${size}" height="${size}" style="display:block"></body></html>`;
    win.setContentSize(size, size);
    await win.loadURL('data:text/html;base64,' + Buffer.from(html).toString('base64'));
    await new Promise(r => setTimeout(r, 120));
    const img = await win.webContents.capturePage({ x: 0, y: 0, width: size, height: size });
    const file = path.join(root, out);
    fs.mkdirSync(path.dirname(file), { recursive: true });
    fs.writeFileSync(file, img.resize({ width: size, height: size, quality: 'best' }).toPNG());
    console.log('wrote', out, size + 'px');
  }
  app.quit();
});
