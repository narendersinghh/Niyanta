'use strict';
/*
 * npm run dist:win [-- extra electron-builder arguments, e.g. the signing options CI adds]. Three Windows artifacts, x64:
 *   1. niyanta-tracker-<v>-win-x64.exe, the NSIS setup, with latest.yml and its .blockmap: electron-builder packs
 *      dist/win-unpacked (afterPack: fuses; afterSign: version resource) and builds the setup. This one must succeed: it is
 *      what installed apps update to (electron-updater), so nothing below may change it.
 *   2. niyanta-tracker-<v>-win-x64.msi, the MSI installer (package.json build.msi: per user, assisted UI, fixed upgradeCode
 *      so a newer MSI upgrades in place), built from that same dist/win-unpacked (--prepackaged: the very exe step 1 built
 *      and checked, no second pack).
 *   3. niyanta-tracker-<v>-win-x64.zip, the portable app, from the same folder plus resources/niyanta-portable: the marker the
 *      app reads (selfupdate.windowsInstallKind) to know it runs from the zip (updates: the workspace's download page). The
 *      marker exists only while the zip is made: the MSI and the setup never contain it.
 * Steps 2 and 3 write no latest.yml (electron-builder writes Windows update info for NSIS targets only), so auto-update stays
 * on the setup. They are alternatives for PCs where Windows Security quarantines the setup; a failure there is reported
 * (a ::warning:: in GitHub Actions) but does not fail the build, so a WiX problem never blocks a release. Set
 * NIYANTA_WIN_EXTRAS=required to make it fatal.
 */
const fs = require('fs');
const path = require('path');
const { spawnSync } = require('child_process');
const { PORTABLE_MARKER } = require('../src/main/selfupdate');

const root = path.join(__dirname, '..');
const cli = require.resolve('electron-builder/cli.js', { paths: [root] });
const extra = process.argv.slice(2);
const unpacked = path.join(root, 'dist', 'win-unpacked');
const marker = path.join(unpacked, 'resources', PORTABLE_MARKER);
const required = process.env.NIYANTA_WIN_EXTRAS === 'required';

function build(args) {
  console.log('\n> electron-builder ' + args.join(' '));
  const r = spawnSync(process.execPath, [cli, ...args, '--publish', 'never', ...extra], { cwd: root, stdio: 'inherit' });
  return r.status === 0; }

function warn(msg) {
  console.log((process.env.GITHUB_ACTIONS ? '::warning::' : 'WARNING: ') + msg);
  if (required) process.exit(1); }

if (!build(['--win', 'nsis', '--x64'])) process.exit(1);
if (!fs.existsSync(path.join(unpacked, 'Niyanta Tracker.exe'))) { console.error('dist/win-unpacked/Niyanta Tracker.exe is missing after the setup build'); process.exit(1); }

if (!build(['--win', 'msi', '--x64', '--prepackaged', unpacked])) warn('The MSI installer could not be built; the release has the setup and (if it builds) the portable zip only.');

fs.rmSync(marker, { force: true });
try {
  fs.writeFileSync(marker, 'Niyanta Tracker portable (zip) copy. It does not install or update itself: get new versions from your workspace, Time Tracking > Get the desktop app.\r\n');
  if (!build(['--win', 'zip', '--x64', '--prepackaged', unpacked])) warn('The portable zip could not be built; the release has the setup and (if it built) the MSI installer only.'); }
finally { fs.rmSync(marker, { force: true }); }
