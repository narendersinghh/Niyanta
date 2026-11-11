'use strict';
/* Syntax-checks every JavaScript file of the app (node --check), validates package.json and runs the activity / pomodoro
   simulation (scripts/simulate.js) the update helper checks (scripts/updates-check.js), the status / meeting / quit sync / indicator / activity source / build checks (scripts/status-check.js), the tracker-off checks (scripts/tracker-check.js: no time accrues once paused, offline, quit, shut down, asleep, locked, signed out, idle), the preference / screenshot notice / Windows build / resource checks (scripts/prefs-check.js)
   and the security regression checks (scripts/security-check.js: window flags, CSP, IPC validation, HTTPS, token and screenshot storage, fuses). Exit code 1 on failure. */
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');
const root = path.join(__dirname, '..');
const files = [];
(function walk(d) { for (const f of fs.readdirSync(d, { withFileTypes: true })) { const p = path.join(d, f.name); if (f.isDirectory()) { if (!['node_modules', 'dist'].includes(f.name)) walk(p); } else if (p.endsWith('.js')) files.push(p); } })(root);
let bad = 0;
for (const f of files) { try { execFileSync(process.execPath, ['--check', f], { stdio: 'pipe' }); console.log('ok  ', path.relative(root, f)); } catch (e) { bad++; console.error('FAIL', path.relative(root, f), '\n' + e.stderr); } }
JSON.parse(fs.readFileSync(path.join(root, 'package.json'), 'utf8'));
console.log(`\n${files.length - bad}/${files.length} files OK`);
for (const f of ['simulate.js', 'updates-check.js', 'status-check.js', 'tracker-check.js', 'prefs-check.js', 'security-check.js']) { try { console.log('\n' + execFileSync(process.execPath, ['--expose-gc', path.join(__dirname, f)], { encoding: 'utf8' }).trim()); } catch (e) { bad++; console.error('FAIL ' + f + '\n' + (e.stdout || '') + (e.stderr || '')); } }
process.exit(bad ? 1 : 0);
