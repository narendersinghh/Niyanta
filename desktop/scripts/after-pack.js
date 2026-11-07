/* electron-builder afterPack hook: flips the Electron fuses of the packed binary (hardening that cannot be undone at run
   time), then signs the macOS app ad hoc when there is no Developer ID certificate (scripts/adhoc-sign.js; the fuses must be
   flipped before signing, so this hook owns the order).
   Fuses: runAsNode off (ELECTRON_RUN_AS_NODE cannot turn the app into a Node runtime), cookie encryption on, NODE_OPTIONS and
   --inspect ignored, the app is loaded only from app.asar, and on macOS / Windows the asar is checked against the hash
   electron-builder embeds (a modified app.asar does not start). Linux has no integrity check in Electron 33, so that fuse is
   left alone there. */
const fs = require('fs');
const path = require('path');
const { flipFuses, FuseVersion, FuseV1Options } = require('@electron/fuses');

/** The fuse wire every packaged build must carry (scripts/security-check.js reads it from here). */
function fuseConfig(platform, resetAdHoc) {
  const integrity = platform === 'darwin' || platform === 'win32';
  return {
    version: FuseVersion.V1,
    resetAdHocDarwinSignature: !!resetAdHoc,
    [FuseV1Options.RunAsNode]: false,
    [FuseV1Options.EnableCookieEncryption]: true,
    [FuseV1Options.EnableNodeOptionsEnvironmentVariable]: false,
    [FuseV1Options.EnableNodeCliInspectArguments]: false,
    [FuseV1Options.EnableEmbeddedAsarIntegrityValidation]: integrity,
    [FuseV1Options.OnlyLoadAppFromAsar]: true }; }

function binaryFor(ctx) {
  const name = ctx.packager.appInfo.productFilename;
  const candidates = { darwin: [name + '.app'], win32: [name + '.exe'], linux: [ctx.packager.executableName, name].filter(Boolean) }[ctx.electronPlatformName] || [];
  for (const c of candidates) { const p = path.join(ctx.appOutDir, c); if (fs.existsSync(p)) return p; }
  throw new Error('after-pack: cannot find the packed binary in ' + ctx.appOutDir); }

exports.fuseConfig = fuseConfig;
exports.default = async ctx => {
  const bin = binaryFor(ctx);
  const mac = ctx.electronPlatformName === 'darwin';
  await flipFuses(bin, fuseConfig(ctx.electronPlatformName, mac && !process.env.CSC_LINK));
  console.log('  • fuses flipped on ' + bin);
  await require('./adhoc-sign').default(ctx); };
