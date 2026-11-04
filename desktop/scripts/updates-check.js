'use strict';
/*
 * Checks of the in-app update helpers (src/main/selfupdate.js) without Electron: version compare, latest-mac.yml parsing,
 * release notes, picking the zip / installer for a computer, where the macOS app is installed, sha512, and the generated
 * macOS helper script: bash -n, then a real run on fake .app folders with stub ditto/xattr/open commands (success, a quoted
 * path with spaces and a quote, the restore after a failed copy). Run: node scripts/updates-check.js (npm run check runs it).
 */
const fs = require('fs');
const os = require('os');
const path = require('path');
const { execFileSync, spawn } = require('child_process');
const su = require(path.join(__dirname, '..', 'src', 'main', 'selfupdate.js'));

let fails = 0; let n = 0;
const ok = (cond, msg) => { n++; if (!cond) { fails++; console.error('FAIL', msg); } };
const eq = (a, b, msg) => ok(JSON.stringify(a) === JSON.stringify(b), `${msg}: expected ${JSON.stringify(b)}, got ${JSON.stringify(a)}`);

(async () => {
  /* versions */
  eq([su.cmpVersion('1.2.0', '1.1.9'), su.cmpVersion('1.10.0', '1.9.0'), su.cmpVersion('1.2.0', '1.2.0'), su.cmpVersion('1.2.0-beta.1', '1.2.0'), su.cmpVersion('v2.0.0', '1.99.99')], [1, 1, 0, -1, 1], 'cmpVersion');

  /* electron-builder latest-mac.yml */
  const yml = ['version: 1.2.0', 'files:', '  - url: niyanta-tracker-1.2.0-mac-x64.zip', '    sha512: AAA=', '    size: 100', '  - url: niyanta-tracker-1.2.0-mac-arm64.zip', '    sha512: BBB=', '    size: 90',
    '  - url: niyanta-tracker-1.2.0-mac-x64.dmg', '    sha512: CCC=', '    size: 120', 'path: niyanta-tracker-1.2.0-mac-x64.zip', 'sha512: AAA=', "releaseDate: '2026-11-01T10:00:00.000Z'",
    'releaseNotes: |-', '  ## What\'s new', '  - Update the app with **one click**', '  - Faster <b>sync</b> &amp; fewer prompts', '  * Fix: idle detection', '  - Four', '  - Five', ''].join('\n');
  const feed = su.parseFeed(yml);
  eq([feed.version, feed.files.length, feed.files[1].name, feed.files[1].sha512, feed.files[1].size], ['1.2.0', 3, 'niyanta-tracker-1.2.0-mac-arm64.zip', 'BBB=', 90], 'parseFeed');
  eq(su.notesLines(feed.releaseNotes), ['Update the app with one click', 'Faster sync & fewer prompts', 'Fix: idle detection', 'Four'], 'notesLines from Markdown/HTML');
  eq(su.notesLines([{ version: '1.2.0', note: '<p>Hello</p><p>World</p>' }]), ['Hello', 'World'], 'notesLines from electron-updater array');
  eq(su.notesLines(null), [], 'no notes');
  ok(/^x+\.\.\.$/.test(su.notesLines('x'.repeat(300))[0]) && su.notesLines('x'.repeat(300))[0].length === 140, 'long note shortened');
  let threw = false; try { su.parseFeed('files: []'); } catch (e) { threw = true; } ok(threw, 'feed without version rejected');
  eq(su.parseFeed("version: 1.3.0\npath: niyanta-tracker-1.3.0-linux-x86_64.AppImage\nsha512: ZZZ=\n").files, [{ url: 'niyanta-tracker-1.3.0-linux-x86_64.AppImage', name: 'niyanta-tracker-1.3.0-linux-x86_64.AppImage', sha512: 'ZZZ=', size: 0 }], 'old style feed (path only)');
  eq(su.baseName('https://ws.example.com/api/agent/updates/niyanta-tracker-1.2.0-mac-arm64.zip?x=1'), 'niyanta-tracker-1.2.0-mac-arm64.zip', 'absolute feed urls');

  /* which file for which computer */
  eq(su.pickMacZip(feed.files, 'arm64').name, 'niyanta-tracker-1.2.0-mac-arm64.zip', 'Apple silicon zip');
  eq(su.pickMacZip(feed.files, 'x64').name, 'niyanta-tracker-1.2.0-mac-x64.zip', 'Intel zip');
  eq(su.pickMacZip([feed.files[0]], 'arm64'), null, 'never the Intel zip for Apple silicon');
  const files = { windows: [{ name: 'a.exe', arch: 'x64', ext: 'exe' }], mac: [{ name: 'm-x64.zip', arch: 'x64', ext: 'zip' }, { name: 'm-x64.dmg', arch: 'x64', ext: 'dmg' }, { name: 'm-arm64.dmg', arch: 'arm64', ext: 'dmg' }], linux: [{ name: 'l.AppImage', arch: 'x64', ext: 'appimage' }], deb: [{ name: 'l.deb', arch: 'x64', ext: 'deb' }] };
  eq(['windows', 'linux', 'deb'].map(k => su.pickInstaller(files, k, 'x64').name), ['a.exe', 'l.AppImage', 'l.deb'], 'pickInstaller');
  eq([su.pickInstaller(files, 'mac', 'x64').name, su.pickInstaller(files, 'mac', 'arm64').name], ['m-x64.dmg', 'm-arm64.dmg'], 'mac installer prefers the dmg of the right arch');
  eq(su.pickInstaller({}, 'windows', 'x64'), null, 'nothing offered');

  /* where the macOS app lives and where the new one goes */
  eq(su.macAppPath('/Applications/Niyanta Tracker.app/Contents/MacOS/Niyanta Tracker'), '/Applications/Niyanta Tracker.app', 'macAppPath');
  eq(su.macAppPath('/usr/bin/node'), null, 'not a bundle');
  const all = () => true; const none = () => false; const onlyHome = p => p.startsWith('/Users/');
  eq(su.macTarget('/Applications/Niyanta Tracker.app', '/Users/ana', all), { target: '/Applications/Niyanta Tracker.app', moved: false, reason: null }, 'in place');
  eq(su.macTarget('/Volumes/Niyanta Tracker 1.1.0/Niyanta Tracker.app', '/Users/ana', all).target, '/Applications/Niyanta Tracker.app', 'running from the dmg: install to Applications');
  eq(su.macTarget('/private/var/folders/x/T/AppTranslocation/ABC/d/Niyanta Tracker.app', '/Users/ana', all).target, '/Applications/Niyanta Tracker.app', 'translocated');
  eq(su.macTarget('/Applications/Niyanta Tracker.app', '/Users/ana', onlyHome), { target: '/Users/ana/Applications/Niyanta Tracker.app', moved: true, reason: 'user-applications' }, 'Applications not writable: ~/Applications');
  eq(su.macTarget('/Users/ana/Desktop/Niyanta Tracker.app', '/Users/ana', onlyHome).target, '/Users/ana/Desktop/Niyanta Tracker.app', 'user folder kept');
  eq(su.macTarget(null, '/Users/ana', none).target, '/Users/ana/Applications/Niyanta Tracker.app', 'unknown location');

  /* sha512 in the latest*.yml format */
  const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'ny-upd-check-'));
  try {
    const f = path.join(tmp, 'x.zip'); fs.writeFileSync(f, 'hello update');
    eq(await su.sha512File(f), require('crypto').createHash('sha512').update('hello update').digest('base64'), 'sha512File');

    /* helper script: rejects odd input, passes bash -n, and really swaps a bundle */
    threw = false; try { su.macHelperScript({ pid: 0, source: '/a.app', target: '/b.app', work: '/w', log: '/l' }); } catch (e) { threw = true; } ok(threw, 'bad pid rejected');
    threw = false; try { su.macHelperScript({ pid: 5, source: '/a', target: '/b.app', work: '/w', log: '/l' }); } catch (e) { threw = true; } ok(threw, 'non-bundle rejected');
    eq(su.sq("it's"), "'it'\\''s'", 'single quoting');
    const bin = path.join(tmp, 'bin'); fs.mkdirSync(bin);
    const stub = (name, body) => fs.writeFileSync(path.join(bin, name), '#!/bin/bash\n' + body + '\n', { mode: 0o755 });
    stub('ditto', '[ -n "${FAIL_DITTO:-}" ] && exit 1; cp -R "$1" "$2"');
    stub('xattr', 'echo "xattr $*" >> "$OUT"');
    stub('open', 'echo "open $*" >> "$OUT"');
    const scenario = async (label, dirName, failCopy) => {
      const root = path.join(tmp, label); const apps = path.join(root, dirName); const work = path.join(root, 'work');
      const src = path.join(work, 'app', 'Niyanta Tracker.app'); const dest = path.join(apps, 'Niyanta Tracker.app');
      fs.mkdirSync(path.join(src, 'Contents'), { recursive: true }); fs.writeFileSync(path.join(src, 'Contents', 'version'), '1.2.0');
      fs.mkdirSync(path.join(dest, 'Contents'), { recursive: true }); fs.writeFileSync(path.join(dest, 'Contents', 'version'), '1.1.0');
      const out = path.join(root, 'out.txt'); const log = path.join(root, 'update.log');
      const app = spawn('sleep', ['1']);
      const script = path.join(root, 'update.sh');
      fs.writeFileSync(script, su.macHelperScript({ pid: app.pid, source: src, target: dest, work, log }), { mode: 0o700 });
      execFileSync('bash', ['-n', script]);
      const t0 = Date.now();
      /* async, so Node reaps the fake app process when it ends (a blocked event loop would leave a zombie that kill -0 still sees) */
      const code = await new Promise(res => spawn('bash', [script], { env: Object.assign({}, process.env, { PATH: bin + ':' + process.env.PATH, OUT: out, FAIL_DITTO: failCopy ? '1' : '' }), stdio: 'ignore' }).on('exit', res));
      ok(code === 0, label + ': helper exit code ' + code);
      return { waited: Date.now() - t0, version: fs.readFileSync(path.join(dest, 'Contents', 'version'), 'utf8'), out: fs.existsSync(out) ? fs.readFileSync(out, 'utf8') : '', work: fs.existsSync(work), old: fs.existsSync(dest + '.previous'), log: fs.readFileSync(log, 'utf8') }; };
    let r = await scenario('plain', 'Applications', false);
    ok(r.waited >= 700, 'helper waits for the app to quit (' + r.waited + ' ms)');
    eq([r.version, r.work, r.old], ['1.2.0', false, false], 'bundle replaced, download folder and old copy removed');
    ok(/xattr -cr .*Niyanta Tracker\.app/.test(r.out) && /open .*Niyanta Tracker\.app/.test(r.out), 'quarantine cleared and app opened: ' + r.out);
    r = await scenario('quote', "Ana's Apps (2)", false);
    eq(r.version, '1.2.0', 'paths with spaces and quotes');
    r = await scenario('fail', 'Applications', true);
    eq([r.version, r.old], ['1.1.0', false], 'failed copy restores the previous version');
    ok(/copy failed, restoring/.test(r.log) && /open /.test(r.out), 'failure logged and the old version reopened'); }
  finally { fs.rmSync(tmp, { recursive: true, force: true }); }

  console.log(`updates: ${n - fails}/${n} checks passed`);
  process.exit(fails ? 1 : 0); })().catch(e => { console.error(e); process.exit(1); });
