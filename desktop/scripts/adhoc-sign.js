/* electron-builder afterPack hook (called by after-pack.js once the fuses are flipped): signs the macOS app when there is no
   Apple Developer ID certificate (CSC_LINK; then electron-builder signs and notarizes it afterwards and this does nothing).
   - MAC_SELF_SIGN_IDENTITY set (the SHA-1 of the project's own self-signed code-signing certificate, imported into a
     keychain by CI, see desktop/SIGNING.md and scripts/make-mac-cert.sh): signed with that certificate. The designated
     requirement is then `identifier "app.niyanta.tracker" and certificate ... = H"<sha1>"`, identical for every version, so
     macOS keeps the Screen Recording permission across updates. The DR is checked here and the build fails when it is not
     tied to that certificate.
   - otherwise ad hoc ("-") so Apple silicon Macs show "unverified developer, Open Anyway" instead of "damaged and can't be
     opened" (the DR is then the cdhash of this build, so Screen Recording has to be allowed again after an update; the app
     resets its own entry so macOS asks, src/main/permfix.js).
   No hardened runtime here: it is only needed for notarization, and with a self-signed certificate library validation
   would refuse Electron's own frameworks. */
const { execFileSync } = require('child_process');
const path = require('path');

const BUNDLE_ID = 'app.niyanta.tracker';

/** The designated requirement of a signed bundle (codesign -d -r-). */
function designated(app) {
  const out = execFileSync('codesign', ['-d', '-r-', app], { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] });
  const m = /designated => (.+)/.exec(out);
  return m ? m[1].trim() : ''; }

exports.designated = designated;
exports.default = async ctx => {
  if (ctx.electronPlatformName !== 'darwin' || process.env.CSC_LINK) return;
  const app = path.join(ctx.appOutDir, ctx.packager.appInfo.productFilename + '.app');
  const id = String(process.env.MAC_SELF_SIGN_IDENTITY || '').trim();
  if (id) {
    if (!/^[0-9A-Fa-f]{40}$/.test(id)) throw new Error('MAC_SELF_SIGN_IDENTITY must be the 40-character SHA-1 of the signing certificate.');
    const kc = process.env.MAC_SELF_SIGN_KEYCHAIN ? ['--keychain', process.env.MAC_SELF_SIGN_KEYCHAIN] : [];
    execFileSync('codesign', ['--force', '--deep', '--timestamp=none', ...kc, '--sign', id, app], { stdio: 'inherit' });
    execFileSync('codesign', ['--verify', '--deep', '--strict', app], { stdio: 'inherit' });
    const dr = designated(app);
    if (!dr.includes(`identifier "${BUNDLE_ID}"`) || !dr.toLowerCase().includes(`h"${id.toLowerCase()}"`) || /cdhash/.test(dr)) throw new Error('The designated requirement is not tied to the signing certificate: ' + dr);
    console.log('  • signed with the project certificate ' + app + '\n    designated => ' + dr);
    return; }
  execFileSync('codesign', ['--force', '--deep', '--sign', '-', app], { stdio: 'inherit' });
  execFileSync('codesign', ['--verify', '--deep', '--strict', app], { stdio: 'inherit' });
  console.log('  • ad-hoc signed ' + app); };
