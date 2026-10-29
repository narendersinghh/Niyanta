'use strict';
/* Syntax-checks every JavaScript file of the app (node --check) and validates package.json. Exit code 1 on failure. */
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
process.exit(bad ? 1 : 0);
