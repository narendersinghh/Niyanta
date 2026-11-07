'use strict';
/* Syntax-checks every JavaScript file of the app (node --check), validates package.json and runs the activity / pomodoro
   simulation (scripts/simulate.js) the update helper checks (scripts/updates-check.js) and the status / meeting / quit sync / indicator / activity source / build checks (scripts/status-check.js). Exit code 1 on failure. */
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
for (const f of ['simulate.js', 'updates-check.js', 'status-check.js']) { try { console.log('\n' + execFileSync(process.execPath, [path.join(__dirname, f)], { encoding: 'utf8' }).trim()); } catch (e) { bad++; console.error('FAIL ' + f + '\n' + (e.stdout || '') + (e.stderr || '')); } }
process.exit(bad ? 1 : 0);
