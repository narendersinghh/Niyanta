/* Niyanta Tracker renderer. Talks to the main process only through window.tracker (preload bridge). */
(() => {
  'use strict';
  const T = window.tracker;
  const $ = id => document.getElementById(id);
  const pad = n => String(n).padStart(2, '0');
  const hms = s => { s = Math.max(0, Math.floor(s || 0)); return `${pad(Math.floor(s / 3600))}:${pad(Math.floor(s % 3600 / 60))}:${pad(s % 60)}`; };
  const hm = s => { s = Math.max(0, Math.floor(s || 0)); return `${Math.floor(s / 3600)}h ${pad(Math.floor(s % 3600 / 60))}m`; };
  const esc = s => String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const every = s => !s ? '' : (s % 60 ? (s > 60 ? `${Math.floor(s / 60)} min ${s % 60} s` : `${s} s`) : (s === 60 ? 'minute' : `${s / 60} min`));
  const icon = (id, cls = '') => `<svg class="ic ${cls}"><use href="#i-${id}"/></svg>`;
  const CHIPS = { tracking: 'Tracking', idle: 'Idle', paused: 'Paused', offline: 'Offline', stopped: 'Not tracking', ended: 'Day ended', not_started: 'Not started' };
  let ui = null;
  let noticeShown = 0;
  let pickerKey = '';
  let modalKind = null;
  let consentLater = false;

  window.addEventListener('error', e => T.reportError({ message: e.message, stack: e.error && e.error.stack }));
  window.addEventListener('unhandledrejection', e => T.reportError({ message: 'Unhandled rejection: ' + (e.reason && e.reason.message || e.reason), stack: e.reason && e.reason.stack }));

  /* ---------- rendering ---------- */

  function render(next) {
    ui = next;
    $('signin').classList.toggle('hide', ui.screen !== 'signin');
    $('tracker').classList.toggle('hide', ui.screen !== 'tracker');
    if (ui.screen === 'signin') renderSignin(); else renderTracker();
    renderSheet();
    renderModal();
    tick();
  }

  function renderSignin() {
    if (!$('si-url').value && ui.login.url) $('si-url').value = ui.login.url;
    if (!$('si-email').value && ui.login.email) $('si-email').value = ui.login.email;
    $('si-submit').disabled = ui.busy;
    $('si-submit').textContent = ui.busy ? 'Signing in…' : 'Sign in';
    $('si-error').textContent = ui.error || '';
    $('si-error').classList.toggle('hide', !ui.error);
  }

  function renderTracker() {
    const st = ui.state || { state: 'not_started', totals: {}, day: {} };
    $('ws-name').textContent = ui.account ? ui.account.workspace.name : '';
    $('ws-user').textContent = ui.account ? ui.account.name + ' · ' + ui.account.email : '';
    $('today').textContent = new Date().toLocaleDateString([], { weekday: 'short', day: 'numeric', month: 'short' });
    const chip = ui.chip === 'not_started' && st.state !== 'not_started' ? st.state : ui.chip;
    $('hero').dataset.state = chip;
    $('chip-label').textContent = CHIPS[chip] || st.label || '';
    renderPickers(st);
    const s = st.state;
    const show = (id, on) => $(id).classList.toggle('hide', !on);
    show('btn-start', ['not_started', 'ended', 'stopped'].includes(s));
    $('btn-start').querySelector('span').textContent = s === 'stopped' ? 'Resume tracking' : (s === 'ended' ? 'Start again' : 'Start day');
    show('btn-pause', s === 'tracking');
    show('btn-resume', s === 'paused');
    show('btn-end', ['tracking', 'paused', 'stopped'].includes(s));
    renderBanners(st);
    renderShots();
    const sync = ui.offline ? `Offline · ${ui.pending} waiting to sync` : (ui.pending ? `Syncing ${ui.pending}…` : 'All changes synced');
    $('sync').textContent = `v${ui.version} · ${sync}`;
  }

  function renderPickers(st) {
    const on = ui.projectsEnabled;
    $('pickers').classList.toggle('hide', !on);
    if (!on) return;
    const sel = ui.selection || {};
    const key = JSON.stringify([ui.projects.map(p => [p.id, p.tasks.length]), sel]);
    if (key !== pickerKey) {
      pickerKey = key;
      $('project').innerHTML = '<option value="">No project</option>' + ui.projects.map(p => `<option value="${p.id}">${esc(p.name)}</option>`).join('');
      $('project').value = sel.project_id ? String(sel.project_id) : '';
      fillTasks(sel.task_id);
    }
    const run = st.running;
    const differs = run && ((run.project_id || null) !== (sel.project_id || null) || (run.task_id || null) !== (sel.task_id || null));
    $('switch').classList.toggle('hide', !(st.state === 'tracking' && differs));
  }

  function fillTasks(taskId) {
    const p = ui.projects.find(x => String(x.id) === $('project').value);
    const tasks = p ? p.tasks : [];
    $('task').innerHTML = `<option value="">${p ? (tasks.length ? 'No specific task' : 'No open tasks') : 'Select a project first'}</option>` + tasks.map(t => `<option value="${t.id}">${esc(t.title)}${t.mine ? ' ★' : ''}</option>`).join('');
    $('task').disabled = !p || !tasks.length;
    $('task').value = taskId ? String(taskId) : '';
  }

  function currentSel() { return { project_id: +$('project').value || null, task_id: +$('task').value || null }; }

  function banner(kind, html, actions = '') { return `<div class="alert ${kind}"><div class="grow">${html}</div>${actions}</div>`; }

  function renderBanners(st) {
    const b = [];
    if (ui.notice && ui.notice.id !== noticeShown) {
      b.push(banner(ui.notice.kind === 'error' ? 'error' : 'info', esc(ui.notice.text), `<button class="btn" data-dismiss="${ui.notice.id}">OK</button>`));
    }
    if (ui.offline) b.push(banner('warn', `${icon('cloud-off', 'sm')} <b>Offline.</b> Your actions are saved and sync automatically. Without a connection for 10 minutes, time is not counted.`));
    if (ui.update && ui.update.available) b.push(banner('ok', `Version <b>${esc(ui.update.latest)}</b> is available.`, '<button class="btn" data-open="download">Download</button>'));
    if (ui.monitoring && ui.settings.screenshots_enabled && ui.consent && ui.consent.given && ui.monitoring.permission === 'denied') b.push(banner('error', 'Screenshots need the <b>Screen Recording</b> permission.', '<button class="btn" data-open="permissions">Open settings</button>'));
    if (ui.consent && ui.consent.needed && st.state === 'tracking' && modalKind !== 'consent') b.push(banner('warn', 'Screen monitoring is on in your workspace. Please review the notice.', '<button class="btn" data-consent>Review</button>'));
    $('banners').innerHTML = b.join('');
  }

  function renderShots() {
    const m = ui.monitoring || {};
    $('mon-info').innerHTML = ui.settings.screenshots_enabled ? (m.on ? `${icon('camera', 'sm')} every ${every(m.interval)}` : 'consent needed') : 'off in your workspace';
    if (!ui.settings.screenshots_enabled) { $('thumbs').innerHTML = '<div class="empty">Screenshots are not used in your workspace.</div>'; return; }
    if (!ui.shots.length) { $('thumbs').innerHTML = `<div class="empty">${m.on ? 'Screenshots appear here while you track.' : 'Accept the monitoring notice to start screenshots.'}</div>`; return; }
    $('thumbs').innerHTML = ui.shots.map(s => `<div class="thumb"><img src="${esc(s.thumb)}" alt="Screenshot ${esc(new Date(s.at).toLocaleTimeString())}"><small>${esc(new Date(s.at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }))}${s.screens > 1 ? ' · ' + s.screens : ''}</small></div>`).join('');
  }

  function renderSheet() {
    if (!ui || ui.screen !== 'tracker') { $('sheet').classList.add('hide'); return; }
    $('p-launch').checked = !!ui.prefs.launchAtLogin;
    $('p-notify').checked = !!ui.prefs.notify;
    $('p-notify').disabled = !ui.prefs.notifyAllowed;
    $('p-notify-hint').textContent = ui.prefs.notifyAllowed ? 'Show a small notice each time a screenshot is taken.' : 'Your administrator keeps this notice on for everyone.';
    const m = ui.monitoring || {};
    $('p-monitor').textContent = !ui.settings.screenshots_enabled ? 'Screenshots are off in your workspace. Only your tracked time and activity level (not what you type) are recorded.'
      : (ui.consent.given ? `Screenshots of all screens every ${every(m.interval)} while tracking. You can see them in the web app and revoke consent there.` : 'Screenshots are on in your workspace, but you have not accepted the notice yet.');
    $('p-perm').classList.toggle('hide', ui.platform !== 'darwin');
    $('p-version').textContent = ui.version;
    $('p-update-state').textContent = ui.update ? (ui.update.available ? `version ${ui.update.latest} available` : 'up to date') : 'not checked yet';
    $('p-download').classList.toggle('hide', !(ui.update && ui.update.available));
    $('p-account').textContent = ui.account ? `${ui.account.name} · ${ui.account.workspace.url}${ui.prefs.tokenProtected ? ' · sign-in stored in the system keychain' : ''}` : '';
  }

  function renderModal() {
    let kind = null;
    if (ui.screen === 'tracker' && ui.idlePrompt) kind = 'idle';
    else if (modalKind === 'end') kind = 'end';
    else if (ui.screen === 'tracker' && ui.consent && ui.consent.needed && (modalKind === 'consent' || (ui.state && ui.state.state === 'tracking' && !consentLater))) kind = 'consent';
    modalKind = kind;
    $('modal').classList.toggle('hide', !kind);
    if (!kind) return;
    if (kind === 'idle') {
      const mins = Math.max(1, Math.round(ui.idlePrompt.seconds / 60));
      $('m-art').innerHTML = `<div class="bubble">${icon('coffee')}</div>`;
      $('m-title').textContent = 'Welcome back!';
      $('m-body').className = 'm-body center';
      $('m-body').innerHTML = `<p>You were idle for <b>${mins < 60 ? mins + ' min' : hm(ui.idlePrompt.seconds)}</b> while tracking. Keep that time, or remove it from your day?</p>`;
      $('m-actions').innerHTML = '<button class="btn ghost" data-idle="keep">Keep time</button><button class="btn primary" data-idle="discard">Discard idle time</button>';
    } else if (kind === 'end') {
      $('m-art').innerHTML = `<div class="bubble lav">${icon('stop')}</div>`;
      $('m-title').textContent = 'End your day?';
      $('m-body').className = 'm-body center';
      $('m-body').innerHTML = `<p>You tracked <b>${hm(ui.state.totals.tracked)}</b> today. Ending the day clocks you out. You can start again later if you need to.</p>`;
      $('m-actions').innerHTML = '<button class="btn ghost" data-close-modal>Keep working</button><button class="btn primary" data-end-confirm>End day</button>';
    } else {
      const p = ui.consent.policy || { points: [] };
      $('m-art').innerHTML = `<div class="bubble lav">${icon('shield')}</div>`;
      $('m-title').textContent = p.title || 'Screen monitoring notice';
      $('m-body').className = 'm-body';
      $('m-body').innerHTML = `<p>Your workspace uses screenshots while you track time (policy ${esc(p.version)}):</p><ul>${p.points.map(x => `<li>${esc(x)}</li>`).join('')}</ul>`;
      $('m-actions').innerHTML = '<button class="btn ghost" data-consent-later>Not now</button><button class="btn primary" data-consent-accept>I accept</button>';
    }
  }

  /* ---------- live clock ---------- */

  function tick() {
    if (!ui || ui.screen !== 'tracker' || !ui.state) return;
    const st = ui.state;
    const live = st.state === 'tracking' ? (Date.now() - ui.at) / 1000 : 0;
    $('clock').textContent = hms(st.totals.tracked + live);
    const r = st.running;
    let sub;
    if (r) sub = `Current entry <b>${hms(r.elapsed + live)}</b>${ui.projectsEnabled ? '<br>' + esc(r.project || 'No project') + (r.task ? ' · ' + esc(r.task) : '') : ''}`;
    else sub = { paused: st.day && st.day.paused_since ? `Paused since <b>${esc(new Date(st.day.paused_since).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }))}</b>. Resume when you are back.` : 'Paused. Resume when you are back.', stopped: 'Your day is open but nothing is tracked.', ended: 'Day ended. Nice work!', not_started: 'Start your day when you begin working.' }[st.state] || '';
    $('sub').innerHTML = sub;
    const pausedLive = st.state === 'paused' ? (Date.now() - ui.at) / 1000 : 0;
    $('t-active').textContent = hm(st.totals.active + live);
    $('t-idle').textContent = hm(st.totals.idle);
    $('t-paused').textContent = hm(st.totals.paused + pausedLive);
    $('t-total').textContent = hm(st.totals.tracked + live);
  }
  setInterval(tick, 1000);

  /* ---------- events ---------- */

  $('si-form').addEventListener('submit', async e => {
    e.preventDefault();
    const url = $('si-url').value.trim(), email = $('si-email').value.trim(), password = $('si-pass').value;
    if (!url || !email || !password) { $('si-error').textContent = 'Enter your workspace address, email and password.'; $('si-error').classList.remove('hide'); return; }
    const r = await T.signIn({ url, email, password });
    if (r && r.ok) $('si-pass').value = '';
  });

  $('project').addEventListener('change', () => { fillTasks(null); T.select(currentSel()); });
  $('task').addEventListener('change', () => T.select(currentSel()));
  $('switch').addEventListener('click', () => T.switchProject(currentSel()));
  $('btn-start').addEventListener('click', () => (ui.state && ui.state.state === 'stopped' ? T.resume(currentSel()) : T.startDay(currentSel())));
  $('btn-pause').addEventListener('click', () => T.pause());
  $('btn-resume').addEventListener('click', () => T.resume(currentSel()));
  $('btn-end').addEventListener('click', () => { modalKind = 'end'; renderModal(); });
  $('open-settings').addEventListener('click', () => $('sheet').classList.remove('hide'));
  $('close-settings').addEventListener('click', () => $('sheet').classList.add('hide'));
  $('sheet').addEventListener('click', e => { if (e.target.id === 'sheet') $('sheet').classList.add('hide'); });
  $('p-launch').addEventListener('change', e => T.setPref('launchAtLogin', e.target.checked));
  $('p-notify').addEventListener('change', e => T.setPref('notify', e.target.checked));
  $('p-check').addEventListener('click', () => T.checkUpdates());
  $('p-download').addEventListener('click', () => T.open('download'));
  $('p-perm').addEventListener('click', () => T.open('permissions'));
  $('p-shots').addEventListener('click', () => T.open('screenshots'));
  $('p-logs').addEventListener('click', () => T.open('logs'));
  $('p-signout').addEventListener('click', () => { $('sheet').classList.add('hide'); T.signOut(); });
  $('open-web').addEventListener('click', () => T.open('time'));
  document.addEventListener('keydown', e => { if (e.key === 'Escape') { $('sheet').classList.add('hide'); if (modalKind === 'end') { modalKind = null; renderModal(); } } });

  document.addEventListener('click', e => {
    const t = e.target.closest('button');
    if (!t) return;
    if (t.dataset.dismiss) { noticeShown = +t.dataset.dismiss; renderBanners(ui.state || {}); }
    if (t.dataset.open) T.open(t.dataset.open);
    if (t.dataset.idle) T.answerIdle(t.dataset.idle);
    if ('closeModal' in t.dataset) { modalKind = null; renderModal(); }
    if ('endConfirm' in t.dataset) { modalKind = null; renderModal(); T.endDay(); }
    if ('consent' in t.dataset) { consentLater = false; modalKind = 'consent'; renderModal(); }
    if ('consentLater' in t.dataset) { consentLater = true; modalKind = null; renderModal(); renderBanners(ui.state || {}); }
    if ('consentAccept' in t.dataset) { t.disabled = true; T.acceptConsent().then(() => { modalKind = null; }); }
  });

  T.onState(render);
  T.getState().then(render);
})();
