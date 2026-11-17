/* electron-builder afterSign hook. Windows only: electron-builder writes the exe's version resource (CompanyName, ProductName,
   FileDescription, LegalCopyright, InternalName, file and product version) but leaves OriginalFilename empty; an exe whose
   version block has holes looks less like a normal product to antivirus heuristics. On an unsigned build the field is filled
   here ("Niyanta Tracker.exe"), before the installer packs the exe. A signed build is never touched (any edit after signing
   would break the signature); macOS and Linux have nothing to do. Never fails the build: a problem is only logged. */
const path = require('path');

const signed = () => !!(process.env.WIN_CSC_LINK || process.env.CSC_LINK || process.env.AZURE_CLIENT_SECRET);

exports.signed = signed;
exports.default = async ctx => {
  if (ctx.electronPlatformName !== 'win32') return;
  if (signed()) { console.log('  • after-sign: signed build, version resource left as signed'); return; }
  const exe = path.join(ctx.appOutDir, ctx.packager.appInfo.productFilename + '.exe');
  const args = [exe, '--set-version-string', 'OriginalFilename', ctx.packager.appInfo.productFilename + '.exe'];
  try {
    if (process.platform !== 'win32') { console.log('  • after-sign: OriginalFilename is set on Windows build machines only'); return; }
    await require('builder-util').executeAppBuilder(['rcedit', '--args', JSON.stringify(args)], undefined, {}, 3);
    console.log('  • after-sign: OriginalFilename set on ' + exe); }
  catch (e) { console.warn('  • after-sign: could not set OriginalFilename (' + (e && e.message) + ')'); } };
