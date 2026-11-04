/* electron-builder afterPack hook: without an Apple Developer ID certificate, sign the macOS app ad hoc ("-") so Apple
   silicon Macs show "unverified developer, Open Anyway" instead of "damaged and can't be opened". With a real certificate
   (CSC_LINK) electron-builder signs (and notarizes) it afterwards and this step does nothing. */
const { execFileSync } = require('child_process');
const path = require('path');
exports.default = async ctx => {
  if (ctx.electronPlatformName !== 'darwin' || process.env.CSC_LINK) return;
  const app = path.join(ctx.appOutDir, ctx.packager.appInfo.productFilename + '.app');
  execFileSync('codesign', ['--force', '--deep', '--sign', '-', app], { stdio: 'inherit' });
  execFileSync('codesign', ['--verify', '--deep', '--strict', app], { stdio: 'inherit' });
  console.log('  • ad-hoc signed ' + app); };
