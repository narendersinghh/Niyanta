/* Niyanta Tracker renderer. Talks to the main process only through window.tracker (preload bridge); the status / counter logic
   is shared with the checks (../shared/status.js, window.NyStatus). */
(() => {
  'use strict';
  const T = window.tracker;
  const S = window.NyStatus;
  const $ = id => document.getElementById(id);
  const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const every = s => !s ? '' : (s % 60 ? (s > 60 ? `${Math.floor(s / 60)} min ${s % 60} s` : `${s} s`) : (s === 60 ? 'minute' : `${s / 60} min`));
  const icon = (id, cls = '') => `<svg class="ic ${cls}"><use href="#i-${id}"/></svg>`;
  /* 12-hour clock unless the workspace chose 24-hour (Settings › General › Time format in the web app) */
  const time = v => new Date(v).toLocaleTimeString([], { hour: 'numeric', minute: '2-digit', hour12: !(ui && ui.account && ui.account.workspace && ui.account.workspace.clock === 24) }).replace(/\s?([AP]M)$/i, (m, x) => ' ' + x.toLowerCase());
  const { hms, hm, mmss } = S;
  let ui = null;
  let noticeShown = 0;
  let pickerKey = '';
  let modalKind = null;
  let modalKey = '';
  let modalReturn = null;
  let consentLater = false;
  let promptLater = '';
  let pomoDirty = false;
  let pomoBusy = false;
  let pomoTimer = null;
  let pomoMsg = '';
  let pomoMsgTimer = null;
  let sheetReturn = null;
  let meetBusy = false;
  let wsOpen = false;
  let pwOpen = false;
  let uninstallAsk = false;
  const hostOf = u => { try { return u ? new URL(/^https?:\/\//i.test(u) ? u : 'https://' + u).host : ''; } catch (e) { return ''; } };

  window.addEventListener('error', e => T.reportError({ message: e.message, stack: e.error && e.error.stack }));
  window.addEventListener('unhandledrejection', e => T.reportError({ message: 'Unhandled rejection: ' + (e.reason && e.reason.message || e.reason), stack: e.reason && e.reason.stack }));

  /* ---------- rendering ---------- */

  function render(next) {
    /* the project list comes only when it changed (main.js send()); otherwise the one we have stays */
    if (next && next.projects === undefined) {
      if (ui && ui.projects && ui.projectsRev === next.projectsRev) next.projects = ui.projects;
      else { next.projects = []; T.getState().then(full => { if (full && full.projects) render(full); }); } }
    ui = next;
    $('signin').classList.toggle('hide', ui.screen !== 'signin');
    $('tracker').classList.toggle('hide', ui.screen !== 'tracker');
    if (ui.screen === 'signin') renderSignin(); else renderTracker();
    renderSheet();
    renderModal();
    tick(); }

  /* Sign in: the big "Sign in with your browser" button; the workspace field only when no workspace is known yet or after
     Change; email and password stay available behind "Use email and password instead". */
  function renderSignin() {
    if (!$('si-url').value && ui.login.url) $('si-url').value = ui.login.url;
    if (!$('si-email').value && ui.login.email) $('si-email').value = ui.login.email;
    const cn = ui.connect || {};
    const host = hostOf(ui.login.url);
    const field = wsOpen || !host;
    const show = (id, on) => $(id).classList.toggle('hide', !on);
    show('si-intro', !host && !cn.phase);
    show('si-ws-field', field && cn.phase !== 'waiting');
    show('si-ws-line', !field || cn.phase === 'waiting');
    $('si-host').textContent = (cn.phase && cn.host) || host;
    show('si-change-wrap', cn.phase !== 'waiting');
    $('si-change').setAttribute('aria-expanded', String(field));
    show('si-browser', cn.phase !== 'waiting');
    show('si-wait', cn.phase === 'waiting');
    const working = cn.phase === 'working';
    $('si-browser').disabled = ui.busy || working;
    $('si-browser-label').textContent = working ? 'Connecting...' : 'Sign in with your browser';
    const msg = cn.message || (!pwOpen && ui.error ? { text: ui.error, kind: 'error' } : null);
    show('si-cn-msg', !!msg);
    $('si-cn-msg').className = 'alert ' + (msg && msg.kind === 'info' ? 'info' : 'error') + (msg ? '' : ' hide');
    $('si-cn-text').textContent = msg ? msg.text : '';
    show('si-retry', !!(cn.message && cn.retry));
    show('si-form', pwOpen);
    $('si-pw-toggle').setAttribute('aria-expanded', String(pwOpen));
    $('si-pw-toggle').textContent = pwOpen ? 'Hide email and password' : 'Use email and password instead';
    $('si-submit').disabled = ui.busy || working;
    $('si-submit').textContent = ui.busy && !working ? 'Signing in...' : 'Sign in';
    $('si-error').textContent = pwOpen && ui.error ? ui.error : '';
    $('si-error').classList.toggle('hide', !(pwOpen && ui.error)); }

  function renderTracker() {
    const st = ui.state || { state: 'not_started', totals: {}, day: {} };
    const acc = ui.account;
    $('ws-name').textContent = acc ? acc.workspace.name : '';
    $('ws-user').textContent = acc ? acc.name : '';
    $('ws-user').title = acc ? acc.email : '';
    $('avatar').textContent = acc ? (acc.initials || (acc.name || '?').slice(0, 1)).toUpperCase() : '';
    $('today').textContent = new Date().toLocaleDateString([], { weekday: 'short', day: 'numeric', month: 'short' });
    renderPickers(st);
    const s = st.state;
    const show = (id, on) => $(id).classList.toggle('hide', !on);
    show('btn-start', ['not_started', 'ended'].includes(s));
    show('btn-pause', s === 'tracking');
    show('btn-resume', s === 'paused' || s === 'stopped');
    renderMeeting(st);
    renderBanners(st);
    setHtml('update-slot', updateCard());
    revealNotices();
    document.querySelectorAll('[data-pc]').forEach(el => { el.style.width = el.dataset.pc + '%'; });
    renderMonitoring();
    const sync = $('sync');
    sync.classList.toggle('off', !!ui.offline); sync.classList.toggle('busy', !ui.offline && !!ui.pending);
    $('sync-text').textContent = `v${ui.version} · ` + (ui.offline ? `Offline, ${ui.pending} waiting to sync` : (ui.pending ? `Syncing ${ui.pending}...` : 'All changes synced')); }

  function renderPickers(st) {
    const on = ui.projectsEnabled;
    $('pickers').classList.toggle('hide', !on);
    if (!on) return;
    const sel = ui.selection || {};
    const key = JSON.stringify([ui.projectsRev, (ui.projects || []).length, sel]);
    if (key !== pickerKey) {
      pickerKey = key;
      $('project').innerHTML = '<option value="">No project</option>' + ui.projects.map(p => `<option value="${p.id}">${esc(p.name)}</option>`).join('');
      $('project').value = sel.project_id ? String(sel.project_id) : '';
      fillTasks(sel.task_id); }
    const run = st.running;
    const differs = run && ((run.project_id || null) !== (sel.project_id || null) || (run.task_id || null) !== (sel.task_id || null));
    $('switch').classList.toggle('hide', !(st.state === 'tracking' && differs)); }

  function fillTasks(taskId) {
    const p = ui.projects.find(x => String(x.id) === $('project').value);
    const tasks = p ? p.tasks : [];
    $('task').innerHTML = `<option value="">${p ? (tasks.length ? 'No specific task' : 'No open tasks') : 'Select a project first'}</option>` + tasks.map(t => `<option value="${t.id}">${esc(t.title)}${t.mine ? ' (mine)' : ''}</option>`).join('');
    $('task').disabled = !p || !tasks.length;
    $('task').value = taskId ? String(taskId) : ''; }

  function currentSel() { return { project_id: +$('project').value || null, task_id: +$('task').value || null }; }

  /** Meeting mode switch: on while tracking; switching it on when not tracking starts (or resumes) tracking first. */
  function renderMeeting(st) {
    const on = !!ui.meeting;
    $('meet-on').checked = on;
    $('meet-on').disabled = meetBusy;
    $('meet').classList.toggle('on', on);
    $('meet-left').classList.toggle('hide', !on);
    const mins = Math.round(S.MEETING_MS / 60000);
    /* "Auto off in 30 min." never breaks inside (no lone "min." on a line of its own) */
    const off = `Auto\u00a0off\u00a0in\u00a0${mins}\u00a0min.`;
    setText('meet-hint', on ? `Turn it off when the meeting ends. ${off}` : (st.state === 'tracking' ? `Activity not counted. ${off}` : `Starts tracking, activity not counted. ${off}`)); }

  /** The x that closes a notice (main.js tracker:dismiss, ids in guard.DISMISS_IDS). */
  const closeX = id => `<button class="x-btn" type="button" data-close="${id}" aria-label="Dismiss" title="Dismiss">${icon('x', 'sm')}</button>`;
  function banner(kind, html, actions = '', id = null) { return `<div class="alert ${kind}" role="status"><div class="grow">${html}</div>${actions}${id ? closeX(id) : ''}</div>`; }
  /** Banner with its buttons on a row below the text (pomodoro). */
  function stackBanner(kind, html, actions, id = null) { return `<div class="alert stack ${kind}" role="status"><div>${html}${id ? closeX(id) : ''}</div><div class="acts">${actions}</div></div>`; }

  function pomodoroBanners(st) {
    const p = ui.pomodoro;
    if (!p || !p.on) return [];
    if (p.due && st.state === 'tracking') return [stackBanner('ok', `${icon('coffee', 'sm')} <span><b>Time for a ${+p.brk}-minute break</b><br>You have tracked ${+p.work} minutes without a break.</span>`, '<button class="btn" data-pomo="break">Take a break</button><button class="btn" data-pomo="snooze">Snooze 10 min</button>', 'pomodoro')];
    if (p.breakUntil && st.state === 'paused' && !p.breakHidden) return [stackBanner('info', `${icon('coffee', 'sm')} <span><b>On a ${+p.breakMinutes}-minute break</b><br>Tracking is paused. <span data-countdown="${+p.breakUntil}">${mmss(p.breakUntil - Date.now())}</span> left.</span>`, '<button class="btn" data-resume>Resume now</button>', 'pomodoro')];
    if (p.over && st.state === 'paused') return [stackBanner('ok', `${icon('coffee', 'sm')} <span><b>Break over</b><br>Resume tracking when you are ready.</span>`, '<button class="btn" data-resume>Resume</button><button class="btn" data-pomo="dismiss">Not yet</button>', 'pomodoro')];
    return []; }

  function renderBanners(st) {
    const b = [];
    const dis = ui.dismissed || {};
    if (ui.notice && ui.notice.id !== noticeShown) b.push(banner(ui.notice.kind === 'error' ? 'error' : 'info', esc(ui.notice.text), ui.notice.action === 'meeting-on' ? `<button class="btn" data-meeting-on="${ui.notice.id}">Turn back on</button>` : '', 'notice'));
    b.push(...pomodoroBanners(st));
    if (ui.idlePause && ui.idlePause.back && !ui.idlePause.dismissed && st.state === 'paused') b.push(banner('info', `${icon('coffee', 'sm')} Tracking paused after ${+ui.idlePause.minutes} min idle. Resume when you are back.`, '<button class="btn" data-resume>Resume</button>', 'idle'));
    if (ui.offline && !dis.offline) b.push(banner('warn', `${icon('cloud-off', 'sm')} <span><b>Offline.</b> Your actions are saved and sync automatically. Without a connection for 10 minutes, time is not counted.</span>`, '', 'offline'));
    const perm = ui.monitoring && ui.monitoring.permission;
    if (ui.monitoring && ui.settings.screenshots_enabled && ui.consent && ui.consent.given && (perm === 'denied' || perm === 'stale') && !dis.permission) b.push(banner('error', perm === 'stale' ? '<span><b>Screenshots are not working.</b> macOS still has Screen Recording switched on for an older version of the app. Click <b>Fix permission</b>: the app restarts and macOS asks again, then choose <b>Allow</b> (and <b>Quit &amp; Reopen</b> if asked).</span>' : '<span>Screenshots need the <b>Screen Recording</b> permission. If it is already on in System Settings, click <b>Fix permission</b>.</span>', '<button class="btn" data-open="fix-permission">Fix permission</button><button class="btn" data-open="permissions">Open settings</button>', 'permission'));
    if (ui.consent && ui.consent.needed && st.state === 'tracking' && modalKind !== 'consent' && !dis.consent) b.push(banner('warn', 'Screen monitoring is on in your workspace. Please review the notice.', '<button class="btn" data-consent>Review</button>', 'consent'));
    setHtml('banners', b.join('')); }

  /* A notice that appears while Settings is open (e.g. after Check for updates) would be hidden behind the drawer: close
     the drawer so it is seen. Keys are the notices' text without numbers, so a ticking countdown or progress is not "new". */
  let noticeKeys = null;
  function revealNotices() {
    const keys = [...document.querySelectorAll('#banners .alert, #update-slot .upd-head')].map(el => el.textContent.replace(/\d+/g, '#').replace(/\s+/g, ' ').trim().slice(0, 80));
    const fresh = noticeKeys !== null && keys.some(k => !noticeKeys.includes(k));
    noticeKeys = keys;
    if (fresh && !$('sheet').classList.contains('hide')) closeSheet(); }

  const BUSY = ['downloading', 'verifying', 'ready', 'installing'];
  /** Short steps after the installer was saved to Downloads (fallback, .deb, development builds). */
  const MANUAL = {
    windows: ['The installer opened. If you do not see it, click <b>Open installer</b>.', 'Follow it to the end: it replaces this version and keeps your sign-in.', 'Niyanta Tracker starts again by itself.'],
    mac: ['The disk image opened. Quit Niyanta Tracker (menu bar icon › <b>Quit</b>).', 'Drag <b>Niyanta Tracker</b> onto <b>Applications</b> and choose <b>Replace</b>.', 'Open it from Applications. The download has no quarantine flag, so macOS opens it without warnings.'],
    linux: ['The new AppImage is in your Downloads folder and is already executable.', 'Quit Niyanta Tracker, then double-click the new AppImage.', 'You can delete the old AppImage afterwards.'],
    deb: ['The new package opened in your software installer: click <b>Install</b>.', 'Or run the command below in a terminal.', 'Then click <b>Restart app</b>. Tracking continues.'] };

  /** The update card at the top: "Niyanta Tracker X is available" with Update now, its progress, the manual download and its steps. */
  function updateCard() {
    const up = ui.update;
    if (!up || !(up.available || up.manual)) return '';
    const busy = up.want && BUSY.includes(up.status);
    /* closed with its x: gone for this version (Settings › Version still has it); never while an update is running */
    if (up.dismissed && !busy) return '';
    const v = esc(up.latest || '');
    const head = (title, sub) => `<div class="upd-head">${icon('download')}<div class="grow"><b>${title}</b>${sub ? `<small>${sub}</small>` : ''}</div>${busy ? '' : closeX('update')}</div>`;
    const card = (cls, inner) => `<section class="upd ${cls}" aria-live="polite">${inner}</section>`;
    if (up.status === 'manual' && up.manual) {
      const m = up.manual;
      const steps = (MANUAL[m.kind] || MANUAL.windows).map(x => `<li>${x}</li>`).join('');
      const cmd = m.command ? `<div class="upd-cmd"><code>${esc(m.command)}</code><button class="btn sm" data-copy-text="${esc(m.command)}">Copy</button></div>` : '';
      const acts = (m.kind === 'linux' ? '<button class="btn sm" data-installer>Show in folder</button>' : '<button class="btn sm" data-installer>Open installer</button>') + (m.kind === 'deb' ? '<button class="btn primary sm" data-restart-app>Restart app</button>' : '');
      return card('ok', head(`Version ${v} downloaded`, `Saved to ${esc(m.folder)}`) + `<ol class="upd-steps">${steps}</ol>${cmd}<div class="acts">${acts}</div>`); }
    if (up.want && BUSY.includes(up.status)) {
      const pc = up.status === 'downloading' ? Math.max(2, +up.percent || 0) : 100;
      const label = { downloading: `Downloading${up.auto && up.mode !== 'deb' ? '' : ' the installer'}... ${+up.percent || 0}%`, verifying: 'Checking the download...', ready: 'Installing. The app restarts in a moment.', installing: 'Installing. The app restarts in a moment.' }[up.status];
      return card('info', head(`Updating to Niyanta Tracker ${v}`, up.auto ? 'Keep working. Tracking pauses for the restart and continues by itself.' : '') + `<div class="upd-bar" role="progressbar" aria-label="Update progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${pc}"><span data-pc="${pc}"></span></div><small class="upd-label">${label}</small>`); }
    if (up.status === 'error') {
      return card('error', head(`Niyanta Tracker ${v} is available`, esc(up.error || 'The automatic update did not work.')) + '<p class="upd-p">Get the new version in one step instead: it downloads inside the app (no browser) and opens the installer.</p><div class="acts"><button class="btn primary sm" data-download-installer>Download the new version</button>' + (up.auto ? '<button class="btn sm" data-install-update>Try again</button>' : '') + '</div>'); }
    const notes = up.notes && up.notes.length ? `<ul class="upd-notes">${up.notes.map(n => `<li>${esc(n)}</li>`).join('')}</ul>` : '';
    const sub = up.auto ? (up.mode === 'deb' ? 'Downloads the new package and opens your software installer.' : 'One click: it downloads, installs and restarts. Tracking continues.') : 'Downloads the installer inside the app and opens it.';
    return card('hot', head(`Niyanta Tracker ${v} is available`, `You have ${esc(up.current)}. ${sub}`) + notes + `<div class="acts">${up.auto ? '<button class="btn light" data-install-update>Update now</button>' : '<button class="btn light" data-download-installer>Download the new version</button>'}</div>`); }

  /** One quiet line about screen monitoring (screenshots themselves are never shown in the app) and the last activity level. */
  function renderMonitoring() {
    const m = ui.monitoring || {};
    const last = ui.activity && ui.activity.last;
    const a = last && ui.state && ui.state.state === 'tracking' ? (last.meeting ? `Last ${esc(every(last.seconds))}: meeting` : `Activity ${+last.pct}% (last ${esc(every(last.seconds))})`) : '';
    const mon = !ui.settings.screenshots_enabled ? '' : (m.on ? `Screenshots every ${esc(every(m.interval))}${a ? '' : ' while tracking'}` : 'Screen monitoring is on: please review the notice');
    const line = [mon, a].filter(Boolean).join(' · ');
    setHtml('mon-info', line ? `${icon(mon ? 'camera' : 'check', 'sm')} <span>${line}</span>` : ''); }

  function renderSheet() {
    if (!ui || ui.screen !== 'tracker') { $('sheet').classList.add('hide'); return; }
    /* closed: nothing to update (openSheet renders it) */
    if ($('sheet').classList.contains('hide')) return;
    $('p-launch').checked = !!ui.prefs.launchAtLogin;
    $('p-tone').checked = ui.prefs.tone !== false;
    $('p-notify').checked = !!ui.prefs.notify;
    $('p-notify').disabled = !ui.prefs.notifyAllowed;
    $('p-notify').closest('.switch-row').classList.toggle('locked', !ui.prefs.notifyAllowed);
    setHtml('p-notify-hint', ui.prefs.notifyAllowed ? 'Show a small notice each time a screenshot is taken.' : `${icon('lock', 'sm')} ${ui.prefs.notify ? 'Your workspace keeps this notice on for everyone.' : 'Your workspace has turned this notice off.'}`);
    renderPomodoroForm();
    const m = ui.monitoring || {};
    $('p-monitor').textContent = !ui.settings.screenshots_enabled ? 'Screenshots are off in your workspace. Only your tracked time and activity level (not what you type) are recorded.'
      : (ui.consent.given ? `Screenshots of all screens every ${every(m.interval)} while tracking. You can see them in the web app and revoke consent there.` : 'Screenshots are on in your workspace, but you have not accepted the notice yet.')
      + ' Activity is whether there was keyboard or mouse input in each 12-second block, read from the system every second: never what you type, and nothing is counted.'
      + ' Meeting mode keeps tracking but leaves that time out of your activity average.';
    $('p-perm').classList.toggle('hide', ui.platform !== 'darwin');
    $('p-version').textContent = ui.version;
    const up = ui.update;
    const busy = up && up.want && BUSY.includes(up.status);
    $('p-update-state').textContent = !up ? 'not checked yet' : (busy ? `updating to ${up.latest} (${+up.percent || 0}%)` : (up.available ? `version ${up.latest} available` : 'up to date'));
    $('p-download').classList.toggle('hide', !(up && up.available && !busy));
    $('p-download').lastChild.textContent = up && up.auto && up.status !== 'error' ? ' Update now' : ' Download the new version';
    $('p-account').textContent = ui.account ? `${ui.account.name} · ${ui.account.email} · ${ui.account.workspace.url}${ui.prefs.tokenProtected ? ' · sign-in stored in the system keychain' : ''}` : ''; }

  /* Pomodoro settings save themselves (like the other switches): the switch at once, the minutes as soon as they are
     valid (a short pause after typing, or leaving the field). pomoDirty only protects a field while it is being edited. */
  function renderPomodoroForm(force = false) {
    const p = ui.pomodoro;
    if (!p) return;
    if (!pomoBusy) $('pomo-on').checked = !!p.on;
    if (!pomoDirty || force) { $('pomo-work').value = p.work; $('pomo-brk').value = p.brk; }
    pomoState(); }

  /** Field validity and the status line under the pomodoro fields. */
  function pomoState(msg) {
    const work = +$('pomo-work').value, brk = +$('pomo-brk').value;
    const okW = Number.isInteger(work) && work >= 1 && work <= 600, okB = Number.isInteger(brk) && brk >= 1 && brk <= 120;
    $('pomo-work').setAttribute('aria-invalid', okW ? 'false' : 'true'); $('pomo-brk').setAttribute('aria-invalid', okB ? 'false' : 'true');
    $('pomo-form').classList.toggle('off', !$('pomo-on').checked);
    const p = ui && ui.pomodoro;
    const text = msg || pomoMsg || (!okW ? 'Working time: 1 to 600 minutes.' : !okB ? 'Break: 1 to 120 minutes.'
      : (p && p.on ? `On: a ${p.brk}-minute break after ${p.work} minutes of tracking.` : 'Off: no break reminders.'));
    setText('pomo-msg', text);
    $('pomo-msg').classList.toggle('bad', !okW || !okB);
    return okW && okB; }

  /** Save what the form shows; invalid minutes keep the saved ones (the switch alone always saves). */
  async function savePomodoro() {
    clearTimeout(pomoTimer);
    const p = ui.pomodoro || {};
    const valid = pomoState();
    const prefs = { on: $('pomo-on').checked, work: valid ? +$('pomo-work').value : p.work, brk: valid ? +$('pomo-brk').value : p.brk };
    pomoBusy = true;
    let r = null;
    try { r = await T.setPomodoro(prefs); } catch (e) { r = null; } finally { pomoBusy = false; }
    if (r && r.ok) {
      pomoDirty = false;
      ui.pomodoro = Object.assign({}, ui.pomodoro, r.prefs);
      pomoFlash(r.prefs.on ? `Saved. A ${r.prefs.brk}-minute break after ${r.prefs.work} minutes of tracking.` : 'Saved. Break reminders are off.');
      renderPomodoroForm(!$('pomo-form').contains(document.activeElement) || document.activeElement === $('pomo-on')); }
    else { $('pomo-on').checked = !!p.on; pomoFlash('Could not save. Please try again.'); } }

  function pomoFlash(text) { pomoMsg = text; pomoState(); clearTimeout(pomoMsgTimer); pomoMsgTimer = setTimeout(() => { pomoMsg = ''; if (ui) pomoState(); }, 4000); }

  /* ---------- modals: sync before quitting > consent notice > update prompt (once per version) ---------- */

  function wantedModal() {
    if (ui.uninstall && (ui.uninstall.step || ui.uninstall.error)) return 'uninstall';
    if (ui.quitSync) return 'sync';
    if (uninstallAsk) return 'uninstall-ask';
    if (ui.connect && ui.connect.phase === 'confirm') return 'connect';
    if (ui.screen !== 'tracker') return null;
    if (ui.consent && ui.consent.needed && (modalKind === 'consent' || (ui.state && ui.state.state === 'tracking' && !consentLater))) return 'consent';
    if (ui.update && ui.update.prompt && promptLater !== ui.update.latest) return 'update';
    return null; }

  function renderModal() {
    const kind = wantedModal();
    const was = modalKind;
    modalKind = kind;
    $('modal').classList.toggle('hide', !kind);
    if (!kind) { modalKey = ''; if (was && modalReturn && document.contains(modalReturn)) modalReturn.focus(); modalReturn = null; return; }
    if (!was) modalReturn = document.activeElement;
    const key = kind + '|' + (kind === 'update' ? ui.update.latest : kind === 'connect' ? ui.connect.host + '|' + ui.connect.switching : kind === 'uninstall' ? (ui.uninstall.step || '') + '|' + (ui.uninstall.error || '') : '');
    const fresh = key !== modalKey;
    modalKey = key;
    if (kind === 'sync') { renderSync(fresh); return; }
    if (!fresh) return;
    if (kind === 'uninstall-ask') {
      $('m-art').innerHTML = `<div class="bubble danger">${icon('x')}</div>`;
      $('m-title').textContent = 'Uninstall Niyanta Tracker?';
      $('m-body').className = 'm-body';
      $('m-body').innerHTML = '<p>This is what happens:</p><ul><li>Tracking stops and your data is synced to the server first.</li><li>You are signed out on this device.</li><li>The app and all its data on this computer are removed.</li></ul><p class="note">Your tracked time, timesheets and screenshots stay in your workspace.</p>';
      $('m-actions').innerHTML = '<button class="btn ghost" data-uninstall="cancel">Cancel</button><button class="btn danger" data-uninstall="confirm">Uninstall</button>';
      setTimeout(() => { const b = $('m-actions').querySelector('.ghost'); if (b) b.focus(); }, 0);
      return; }
    if (kind === 'uninstall') {
      const u = ui.uninstall;
      $('m-art').innerHTML = u.error ? `<div class="bubble danger">${icon('x')}</div>` : `<div class="bubble spin">${icon('sync')}</div>`;
      $('m-title').textContent = u.error ? 'Niyanta Tracker was not fully removed' : 'Removing Niyanta Tracker...';
      $('m-body').className = 'm-body' + (u.error ? '' : ' center');
      $('m-body').innerHTML = u.error ? `<p>${esc(u.error)}</p>${u.left && u.left.length ? `<p>Still on this computer:</p><ul>${u.left.map(x => `<li>${esc(x)}</li>`).join('')}</ul>` : ''}` : `<p role="status" aria-live="polite">${esc(u.step)}</p><p class="note">The app closes by itself when it is done.</p>`;
      $('m-actions').innerHTML = u.error ? '<button class="btn primary" data-uninstall="ok">OK</button>' : '';
      setTimeout(() => { const b = $('m-actions').querySelector('button'); if (b) b.focus(); }, 0);
      return; }
    if (kind === 'consent') {
      const p = ui.consent.policy || { points: [] };
      $('m-art').innerHTML = `<div class="bubble">${icon('shield')}</div>`;
      $('m-title').textContent = p.title || 'Screen monitoring notice';
      $('m-body').className = 'm-body';
      $('m-body').innerHTML = `<p>Your workspace uses screenshots while you track time (policy ${esc(p.version)}):</p><ul>${p.points.map(x => `<li>${esc(x)}</li>`).join('')}</ul>`;
      $('m-actions').innerHTML = '<button class="btn ghost" data-consent-later>Not now</button><button class="btn primary" data-consent-accept>I accept</button>'; }
    if (kind === 'connect') {
      const c = ui.connect;
      $('m-art').innerHTML = `<div class="bubble">${icon('shield')}</div>`;
      $('m-title').textContent = c.switching ? 'Switch workspace?' : 'Connect this computer?';
      $('m-body').className = 'm-body center';
      $('m-body').innerHTML = `<p>Connect to <b>${esc(c.host)}</b> as the person signed in on that website?</p>`
        + (c.switching ? `<p>You are signed in to <b>${esc(c.current)}</b>. Tracking there is paused and this computer is signed out of it first.</p>` : '')
        + '<p class="note">Only continue if you just clicked Connect the desktop app on that website.</p>';
      $('m-actions').innerHTML = `<button class="btn ghost" data-cn="decline">Cancel</button><button class="btn primary" data-cn="confirm">${c.switching ? 'Switch' : 'Connect'}</button>`; }
    if (kind === 'update') {
      const up = ui.update;
      $('m-art').innerHTML = `<div class="bubble">${icon('download')}</div>`;
      $('m-title').textContent = `Niyanta Tracker ${up.latest} is available`;
      $('m-body').className = 'm-body center';
      const notes = up.notes && up.notes.length ? `<ul>${up.notes.map(n => `<li>${esc(n)}</li>`).join('')}</ul>` : '';
      $('m-body').innerHTML = `${notes}<p>${up.auto ? 'Update now downloads and installs it, then the app restarts by itself. Your tracking continues where you left off.' : 'Download the new version: the app saves the installer and opens it.'}</p><p class="note">You have version ${esc(up.current)}.</p>`;
      $('m-actions').innerHTML = `<button class="btn ghost" data-prompt-later>Later</button><button class="btn primary" data-prompt-update>${up.auto ? 'Update now' : 'Download'}</button>`; }
    setTimeout(() => { const b = $('m-actions').querySelector('.primary'); if (b) b.focus(); }, 0); }

  function renderSync(fresh) {
    const q = ui.quitSync;
    if (fresh) {
      $('m-art').innerHTML = `<div class="bubble mint spin">${icon('sync')}</div>`;
      $('m-title').textContent = 'Syncing your data';
      $('m-body').className = 'm-body center';
      $('m-body').innerHTML = '<p><b>Syncing your data to the server. The app will close by itself when done.</b></p><div class="pbar" role="progressbar" aria-label="Sync progress" aria-valuemin="0" aria-valuemax="100"><span id="sync-bar"></span></div><p id="sync-line" class="note" role="status" aria-live="polite"></p><p class="note">Quit anyway keeps what is left on this computer; it is sent the next time you open Niyanta Tracker.</p>';
      $('m-actions').innerHTML = '<button class="btn ghost" data-quit-anyway>Quit anyway</button>';
      setTimeout(() => { const b = $('m-actions').querySelector('button'); if (b) b.focus(); }, 0); }
    syncProgress(); }

  function syncProgress() {
    const q = ui && ui.quitSync;
    if (!q || !$('sync-bar')) return;
    const total = Math.max(1, +q.total || 1), sent = Math.max(0, total - (+q.left || 0));
    const pc = Math.round(100 * sent / total);
    $('sync-bar').style.width = Math.max(4, pc) + '%';
    $('sync-bar').parentElement.setAttribute('aria-valuenow', String(pc));
    const left = Math.max(0, Math.ceil((+q.until - Date.now()) / 1000));
    $('sync-line').textContent = `${sent} of ${total} sent` + (q.offline ? '. No connection right now, retrying...' : '') + ` · closes in ${left} s at the latest`; }

  /* ---------- live clock ---------- */

  /* Only the texts that changed are written back to the DOM (no layout work when the second did not change a value). */
  const shown = new Map();
  const setText = (id, v) => { if (shown.get(id) !== v) { shown.set(id, v); $(id).textContent = v; } };
  const setHtml = (id, v) => { if (shown.get('#' + id) !== v) { shown.set('#' + id, v); $(id).innerHTML = v; } };

  function tick() {
    if (!ui || ui.screen !== 'tracker' || !ui.state) return;
    const st = ui.state;
    const cur = S.current(ui);
    const live = st.state === 'tracking' ? Math.max(0, (Date.now() - ui.at) / 1000) : 0;
    const kind = ui.offline && st.state === 'tracking' ? 'offline' : cur.kind;
    if ($('hero').dataset.kind !== kind) $('hero').dataset.kind = kind;
    /* tracker off: the counter shows today's tracked time, frozen and dimmed; the "Today" line would only repeat it */
    const frozen = cur.ticking ? '0' : '1';
    if ($('hero').dataset.frozen !== frozen) { $('hero').dataset.frozen = frozen; $('day-line').classList.toggle('hide', !cur.ticking); }
    setText('chip-label', cur.label);
    setText('clock', hms(cur.seconds));
    const aria = cur.ticking ? `${cur.label}: ${hm(cur.seconds)}` : `${cur.label}. Tracked today: ${hm(cur.seconds)}`;
    if (shown.get('clock-aria') !== aria) { shown.set('clock-aria', aria); $('clock').setAttribute('aria-label', aria); }
    setText('day-total', hms(cur.total));
    const r = st.running;
    const proj = r && ui.projectsEnabled ? (r.project || 'No project') + (r.task ? ' · ' + r.task : '') : '';
    setText('hero-proj', proj);
    /* nothing under the divider (tracker off, no project): no empty strip */
    const foot = cur.ticking || proj ? '1' : '0';
    if (shown.get('hero-foot') !== foot) { shown.set('hero-foot', foot); document.querySelector('.hero-foot').classList.toggle('hide', foot === '0'); }
    /* the status dot beats with the clock: dimmed every other second while time counts */
    const beat = cur.ticking && (kind === 'tracking' || kind === 'meeting') && Math.floor(Date.now() / 1000) % 2 ? '1' : '0';
    if ($('hero').dataset.beat !== beat) $('hero').dataset.beat = beat;
    const since = cur.since ? `<b>${esc(time(cur.since))}</b>` : '';
    const pomo = ui.pomodoro && ui.pomodoro.breakUntil && st.state === 'paused' ? ui.pomodoro : null;
    setHtml('caption', {
      tracking: since ? `Current session since ${since}` : 'Current session',
      idle: ui.idleSince ? `Idle since <b>${esc(time(ui.idleSince))}</b>. The timer keeps running.` : 'Idle. The timer keeps running.',
      meeting: `Meeting mode, ends in <b>${mmss(cur.meetingLeft || 0)}</b>`,
      paused: pomo ? `On a break, <b>${mmss(pomo.breakUntil - Date.now())}</b> left. Not counting.` : (since ? `Paused since ${since}. Not counting.` : 'Paused. Not counting.'),
      stopped: 'Not tracking, not counting. Press Resume.',
      ended: 'Not tracking. Press Start to track again.',
      not_started: 'Press Start when you begin working.' }[cur.kind] || '');
    if (ui.meeting) setText('meet-left', mmss(cur.meetingLeft || 0));
    document.querySelectorAll('[data-countdown]').forEach(el => { const v = mmss(+el.dataset.countdown - Date.now()); if (el.textContent !== v) el.textContent = v; });
    const t = st.totals || {};
    setText('t-total', hm(cur.total));
    setText('t-active', hm((+t.active || 0) + (ui.idle ? 0 : live)));
    setText('t-idle', hm((+t.idle || 0) + (ui.idle ? live : 0)));
    const act = Number.isFinite(t.activity) ? t.activity : (Number.isFinite(ui.dayActivity) ? ui.dayActivity : null);
    setText('t-act', act === null ? '-' : act + '%');
    const bar = (act || 0) + '%';
    if (shown.get('t-act-bar') !== bar) { shown.set('t-act-bar', bar); $('t-act-bar').style.width = bar; }
    if (ui.quitSync) syncProgress(); }
  /* the clock runs only while the window is visible (hidden in the tray nothing is painted); it catches up on show */
  let ticker = null;
  function startTicker() { if (!ticker) ticker = setInterval(tick, 1000); tick(); }
  function stopTicker() { clearInterval(ticker); ticker = null; }
  document.addEventListener('visibilitychange', () => { if (document.hidden) stopTicker(); else startTicker(); });
  if (!document.hidden) startTicker();

  /* ---------- events ---------- */

  $('si-form').addEventListener('submit', async e => {
    e.preventDefault();
    const url = $('si-url').value.trim(), email = $('si-email').value.trim(), password = $('si-pass').value;
    if (!url || !email || !password) { if (!url) { wsOpen = true; renderSignin(); } $('si-error').textContent = 'Enter your workspace address, email and password.'; $('si-error').classList.remove('hide'); return; }
    const r = await T.signIn({ url, email, password });
    if (r && r.ok) $('si-pass').value = ''; });

  $('si-browser').addEventListener('click', () => {
    const url = $('si-url').value.trim() || ui.login.url;
    if (!url) { wsOpen = true; renderSignin(); $('si-url').focus(); $('si-cn-text').textContent = 'Enter your workspace address first.'; $('si-cn-msg').className = 'alert error'; return; }
    T.connect('browser', url); });
  $('si-url').addEventListener('keydown', e => { if (e.key === 'Enter' && !pwOpen) { e.preventDefault(); $('si-browser').click(); } });
  $('si-cancel').addEventListener('click', () => T.connect('cancel'));
  $('si-retry').addEventListener('click', () => T.connect('retry', $('si-url').value.trim()));
  $('si-change').addEventListener('click', () => { wsOpen = true; renderSignin(); $('si-url').focus(); $('si-url').select(); });
  $('si-pw-toggle').addEventListener('click', () => { pwOpen = !pwOpen; renderSignin(); if (pwOpen) $(!$('si-url').value ? 'si-url' : !$('si-email').value ? 'si-email' : 'si-pass').focus(); });

  $('project').addEventListener('change', () => { fillTasks(null); T.select(currentSel()); });
  $('task').addEventListener('change', () => T.select(currentSel()));
  $('switch').addEventListener('click', () => T.switchProject(currentSel()));
  $('btn-start').addEventListener('click', () => T.startDay(currentSel()));
  $('btn-pause').addEventListener('click', () => T.pause());
  $('btn-resume').addEventListener('click', () => T.resume(currentSel()));
  $('meet-on').addEventListener('change', async e => {
    meetBusy = true; e.target.disabled = true;
    try { await T.setMeeting(e.target.checked); } finally { meetBusy = false; e.target.disabled = false; } });
  /* settings sheet: focus moves into it, Tab stays inside, Escape or the backdrop closes it and focus goes back */
  const focusables = root => [...root.querySelectorAll('button,input,select,[href]')].filter(el => !el.disabled && el.offsetParent !== null);
  function trap(root, e) {
    if (e.key !== 'Tab') return;
    const f = focusables(root); if (!f.length) return;
    const first = f[0], last = f[f.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); } }
  function openSheet() { sheetReturn = document.activeElement; pomoDirty = false; pomoMsg = ''; $('sheet').classList.remove('hide'); renderSheet(); renderPomodoroForm(true); $('sheet').querySelector('.sheet-card').focus(); }
  function closeSheet() {
    if ($('sheet').classList.contains('hide')) return;
    /* minutes typed but not saved yet (the short pause after typing had not passed): saved now, when valid */
    if (pomoDirty && pomoState()) savePomodoro();
    $('sheet').classList.add('hide'); pomoDirty = false;
    if (sheetReturn && document.contains(sheetReturn)) sheetReturn.focus(); else $('open-settings').focus(); }
  $('open-settings').addEventListener('click', openSheet);
  $('close-settings').addEventListener('click', closeSheet);
  $('sheet').addEventListener('click', e => { if (e.target.id === 'sheet') closeSheet(); });
  $('sheet').addEventListener('keydown', e => trap($('sheet'), e));
  $('modal').addEventListener('keydown', e => trap($('modal'), e));
  $('p-launch').addEventListener('change', e => T.setPref('launchAtLogin', e.target.checked));
  $('p-notify').addEventListener('change', e => T.setPref('notify', e.target.checked));
  $('p-tone').addEventListener('change', e => { T.setPref('tone', e.target.checked); if (e.target.checked) chime(); });
  $('pomo-on').addEventListener('change', () => savePomodoro());
  for (const id of ['pomo-work', 'pomo-brk']) {
    $(id).addEventListener('input', () => { pomoDirty = true; pomoMsg = ''; clearTimeout(pomoTimer); if (pomoState()) pomoTimer = setTimeout(savePomodoro, 700); });
    $(id).addEventListener('change', () => { if (pomoDirty && pomoState()) savePomodoro(); }); }
  $('pomo-form').addEventListener('submit', e => { e.preventDefault(); if (pomoState()) savePomodoro(); });
  $('p-check').addEventListener('click', () => T.checkUpdates());
  $('p-download').addEventListener('click', () => { const up = ui && ui.update; closeSheet(); if (up && up.auto && up.status !== 'error') T.installUpdate(); else T.downloadInstaller(); });
  $('p-perm').addEventListener('click', () => T.open('permissions'));
  $('p-shots').addEventListener('click', () => T.open('screenshots'));
  $('p-logs').addEventListener('click', () => T.open('logs'));
  $('p-signout').addEventListener('click', () => { closeSheet(); T.signOut(); });
  $('p-uninstall').addEventListener('click', () => { closeSheet(); uninstallAsk = true; renderModal(); });
  $('open-web').addEventListener('click', () => T.open('time'));
  document.addEventListener('keydown', e => {
    if (e.key !== 'Escape') return;
    if (modalKind === 'update') { laterPrompt(); return; }
    if (modalKind === 'connect') { T.connect('decline'); return; }
    if (modalKind === 'uninstall-ask') { uninstallAsk = false; renderModal(); return; }
    closeSheet(); });

  function laterPrompt() { const v = ui.update && ui.update.latest; promptLater = v || ''; T.updatePrompted(v); renderModal(); }

  document.addEventListener('click', e => {
    const t = e.target.closest('button');
    if (!t) return;
    if (t.dataset.close) { if (t.dataset.close === 'notice' && ui.notice) noticeShown = ui.notice.id; t.closest('.alert,.upd').classList.add('hide'); T.dismiss(t.dataset.close); }
    if (t.dataset.meetingOn) { noticeShown = +t.dataset.meetingOn; t.disabled = true; T.setMeeting(true); renderBanners(ui.state || {}); }
    if (t.dataset.open) T.open(t.dataset.open);
    if ('installUpdate' in t.dataset) { t.disabled = true; T.installUpdate(); }
    if ('downloadInstaller' in t.dataset) { t.disabled = true; T.downloadInstaller(); }
    if ('installer' in t.dataset) T.openInstaller();
    if ('restartApp' in t.dataset) { t.disabled = true; T.restartApp(); }
    if (t.dataset.copyText) { T.copy(t.dataset.copyText); t.textContent = 'Copied'; }
    if ('resume' in t.dataset) T.resume(currentSel());
    if (t.dataset.pomo === 'break') { t.disabled = true; T.takeBreak(); }
    if (t.dataset.pomo === 'snooze') T.snoozeBreak();
    if (t.dataset.pomo === 'dismiss') T.dismissBreakOver();
    if ('consent' in t.dataset) { consentLater = false; modalKind = 'consent'; modalKey = ''; renderModal(); }
    if ('consentLater' in t.dataset) { consentLater = true; modalKind = null; renderModal(); renderBanners(ui.state || {}); }
    if ('consentAccept' in t.dataset) { t.disabled = true; T.acceptConsent().then(() => { modalKind = null; }); }
    if ('promptLater' in t.dataset) laterPrompt();
    if ('promptUpdate' in t.dataset) { const up = ui.update; promptLater = up.latest; T.updatePrompted(up.latest); if (up.auto) T.installUpdate(); else T.downloadInstaller(); renderModal(); }
    if (t.dataset.cn) { t.disabled = true; T.connect(t.dataset.cn); }
    if (t.dataset.uninstall === 'cancel') { uninstallAsk = false; renderModal(); }
    if (t.dataset.uninstall === 'confirm') { uninstallAsk = false; t.disabled = true; T.uninstall().then(r => { if (r && !r.ok) render(Object.assign({}, ui, { uninstall: { step: null, error: r.error || 'Uninstall did not start.' } })); }); }
    if (t.dataset.uninstall === 'ok') { ui.uninstall = null; T.uninstallDismiss().then(() => T.getState()).then(render); renderModal(); }
    if ('quitAnyway' in t.dataset) { t.disabled = true; t.textContent = 'Closing...'; T.quitAnyway(); } });

  /* "Message alert tone": a short two-note chime (Web Audio, no sound file) */
  let audio = null;
  function chime() {
    try {
      audio = audio || new AudioContext();
      if (audio.state === 'suspended') audio.resume();
      const t0 = audio.currentTime + 0.02;
      [[880, 0], [1318.5, 0.13]].forEach(([f, at]) => {
        const o = audio.createOscillator(), g = audio.createGain();
        o.type = 'sine'; o.frequency.value = f;
        g.gain.setValueAtTime(0.0001, t0 + at); g.gain.exponentialRampToValueAtTime(0.12, t0 + at + 0.015); g.gain.exponentialRampToValueAtTime(0.0001, t0 + at + 0.24);
        o.connect(g).connect(audio.destination); o.start(t0 + at); o.stop(t0 + at + 0.26); }); }
    catch (e) { /* no audio device */ } }
  if (T.onTone) T.onTone(chime);

  T.onState(render);
  T.getState().then(render);
})();
