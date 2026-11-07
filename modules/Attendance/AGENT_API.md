# Niyanta Tracker API (desktop app)

Niyanta Tracker (`desktop/` in this repository, Electron, Windows/macOS/Linux) is the only way to track time. The web app shows
live status, entries, timesheets and screenshots read-only (manual corrections excepted). This is the contract the app uses.

**One model:** attendance and time tracking are the same thing.

| App action | Server effect |
|---|---|
| Start (first start of the day) | clock in (or reopen an ended day; the gap counts as a pause) + start a time entry |
| Pause | stop the running entry + start a break |
| Resume | end the break + start a new entry on the last (or given) project/task; clocks in first when the day has not started (e.g. after midnight) |
| Switch project | close the running entry and start the next one **at the same second** (no gap, no pause) |
| Midnight (automatic) | the running entry and the day end at 00:00 (workspace time zone); the new day starts at 00:00 with a new entry on the same project/task |
| End day (legacy) | stop the entry + clock out. Older apps only: the app has no End day, the day ends by itself at midnight |

**Day boundary.** `Tracker::settle()` runs before every tracking action, heartbeat, `state` and `bootstrap` (and in the
`stale_timers` job): a running entry that started before today is split at every midnight when the app was alive across it
(a heartbeat or the action itself at or after 00:00, at most 10 minutes after the previous heartbeat); otherwise it is stopped
at its last heartbeat once stale. Open days before today with nothing running are closed at the end of their last entry.
A paused day simply ends; the next Start (or Resume) begins the new day.

Only tracked time counts as worked time: when a day has entries, `attendance.worked_seconds` = the sum of its entries.
Entries are approved automatically when they stop, unless a manager rejects the week (`Timesheets`).

## Authentication

`POST /api/agent/login` with the person's Niyanta email and password returns a personal device token (`nyt_` + 48 hex).
Niyanta stores only its SHA-256 hash (`agent_tokens`, `kind = desktop`). Send it on every request:

```
Authorization: Bearer nyt_xxxxxxxx…        (or X-Agent-Token: nyt_… when the host strips Authorization)
X-App-Version: 1.0.0
Accept: application/json
```

Sign-in uses the same rate limits and lockout as the web sign-in (`Core\Auth::verify`: per email+IP, per IP, 10 failures lock the
account for 30 minutes) and is audit-logged (`agent.login`). Signing in again with the same device name replaces the old token;
at most 5 devices stay connected (the least recently used desktop token is revoked). People see and disconnect devices on
**Time Tracking › Your devices**. A token that has not been used for `attendance.token_idle_days` (default 90, 0 = never)
is revoked at its next use. A revoked or expired token, a deactivated user, a role without `attendance.track` or time tracking
switched off answer `401`/`403`; the app then signs out and asks for a fresh sign-in.

Device tokens are independent of web sessions: signing out of the website (`POST /logout`, idle or absolute session
timeout) never signs the app out. Only Disconnect under **Your devices**, `POST /api/agent/logout`, the idle expiry, a
deactivated user, a role change or time tracking switched off end a device token.

## Sign in with your browser (no typing)

The app never asks for the workspace address or a password in this flow. The browser hands it a one-time **connect code** with
the custom URL scheme (registered by the app on install):

```
niyanta-tracker://connect?ws=<urlencoded workspace base URL>&code=<code>[&state=<state>]
```

`ws` is the workspace base URL the app stores (the same value as `workspace.url` in the bootstrap payload, with a trailing
`/`); `code` is 43 characters `[A-Za-z0-9_-]`; `state` is present only in flow B and equals the `state` the app sent. The app
should accept the link only when `ws` is `https://` (or a local development address) and, in flow B, when `state` matches.

**Flow A, started from the web.** Time Tracking › Get the desktop app › **Connect the desktop app** (`POST
/time/desktop/connect-link`, signed in, `attendance.track`, CSRF, 10 per minute) mints a code (`purpose = web`, 2 minutes) and
answers a small page that opens the deep link (a normal link plus an automatic `location` change in `js/tt-connect.js`). The
personal install scripts (`install.sh`, `install.ps1`) mint a code too (`purpose = install`, 30 minutes) when they are generated
and open the deep link after installing: macOS `open "<link>"` after the first launch; Linux starts the AppImage with the link as
its first argument (the `.desktop` entry also declares `MimeType=x-scheme-handler/niyanta-tracker;` with `%U`); Windows waits up
to 25 minutes for `HKEY_CLASSES_ROOT\niyanta-tracker` (the app or its setup must register the scheme) and then
`Start-Process "<link>"`. Flow A codes have no challenge, so no `verifier` is sent.

**Flow B, started from the app.** The app creates a PKCE verifier (43 to 128 characters `[A-Za-z0-9._~-]`), its challenge
`base64url(sha256(verifier))` without padding (43 characters) and a random `state` (16 to 64 characters `[A-Za-z0-9._~-]`), then
opens in the system browser:

```
GET <workspace>/time/desktop/connect?challenge=<43 chars>&state=<state>&device=<name, max 100>&os=<win|mac|linux>
```

The person signs in to the website if needed (then comes back to this URL), sees the workspace, who is signed in, the device
name and system, and chooses **Allow** or **Cancel** ("Not you? Sign out" signs out and returns here after the next sign-in).
Allow (`POST /time/desktop/connect`, CSRF) mints a code (`purpose = browser`, 2 minutes) bound to the user, challenge, state,
device and os, and opens `niyanta-tracker://connect?ws=…&code=…&state=…` the same way; the page says "You can close this tab".
Invalid parameters answer `400` with an explanation; Cancel mints nothing and sends nothing to the app.

**Exchange (both flows).**

```
POST /api/agent/connect
{"code": "…", "verifier": "…" (flow B only), "device_name": "…", "device_os": "win|mac|linux", "app_version": "1.5.0"}
```

Success: exactly the answer of a successful `POST /api/agent/login` (`ok`, `token`, then the bootstrap payload: `user`,
`workspace` (incl. `url`), `permissions`, `settings`, `consent`, `policy`, `projects`, `state`, `server_time`), so the app reuses
its login path. The token is created like a password sign-in (same device-name replacement and 5-device limit, audit
`agent.login`). `device_name` / `device_os` fall back to the values from the flow B request. Errors (`{ok: false, error, code}`):

| Status | `code` | When |
|---|---|---|
| 401 | `code_invalid` | unknown, malformed or already used code, or the user is no longer active |
| 401 | `code_expired` | older than 2 minutes (web button, flow B) or 30 minutes (install script) |
| 401 | `verifier_mismatch` | the code was created with a challenge and `verifier` is missing or `base64url(sha256(verifier))` differs (compared in constant time); the code is burned |
| 403 | `forbidden` | the role may not track time |
| 403 | `disabled` | time tracking is turned off (the code is not used up) |
| 422 | | rule violation, e.g. 5 devices already connected |
| 429 | `locked` (or none) | 20 failed codes per IP in 15 minutes, or more than 30 requests per IP in 5 minutes |

Codes are stored as SHA-256 only (`desktop_connect_codes`: user, hash, challenge, state, device, os, purpose, expiry, used
time, IP), are used up atomically (`UPDATE … WHERE used_at IS NULL AND expires_at > now`), and expired rows are deleted by the
`attendance.connect_codes_cleanup` job (hourly, bounded). Audit log: `desktop.connect.issued`, `desktop.connect.used`,
`desktop.connect.failed` (with the reason). `POST /api/agent/login` with email and password stays as the fallback.

## Responses and errors

JSON everywhere. Success: `{"ok": true, …}`. Errors: `{"ok": false, "error": "message", "code"?: "…"}` with `401`
(signed out), `403` (no permission / feature off), `422` (rule violation), `429` (rate limit), `500` (server failure, recorded in
**Audit log › Error logs** with source `desktop`, the app version and a `ref`). Rate limits per IP: 600/min (login 20 per 5 min, connect 30 per 5 min,
screenshots 240/min, error reports 60/min, version 30/min, update files 60/min per file).

## Endpoints

| Method & path | Body | Returns |
|---|---|---|
| `POST /api/agent/login` | `email, password, device_name, os, app_version` | `token` + bootstrap payload |
| `POST /api/agent/connect` | `code, verifier?, device_name, device_os, app_version` | `token` + bootstrap payload (see Sign in with your browser) |
| `POST /api/agent/logout` | `at`? | stops the entry this device is tracking (at `at`, clamped like actions), then revokes the token |
| `GET /api/agent/bootstrap` | | `user, workspace, permissions, settings, consent, policy, projects[{id,name,tasks[]}], state` |
| `GET /api/agent/state` | | `state, settings, consent` (cheap refresh) |
| `GET /api/agent/tasks?project_id=` | | open tasks of a visible project |
| `POST /api/agent/day/start` | `project_id?, task_id?, description?` | `state` |
| `POST /api/agent/pause` | | `state` |
| `POST /api/agent/resume` | `project_id?, task_id?, description?` | `state` |
| `POST /api/agent/switch` | `project_id (null = no project), task_id?, description?` | `state` |
| `POST /api/agent/day/end` | | `state` (legacy, not used by the app) |
| `POST /api/agent/heartbeat` | `active_seconds, idle_seconds` since the previous beat, `at?` (time of the beat), `idle_spans?: [{from, to}]` | `running, entry, state` |
| `POST /api/agent/activity` | `slots: [{slot_start, slot_seconds, active_seconds, meeting}]` (1 to 500) | `saved, skipped` |
| `POST /api/agent/idle` | legacy (apps up to 1.0.2): `action: keep|discard, spans: [{from, to}]` (or `seconds`); always kept | `discarded: 0, kept, state` |
| `POST /api/agent/consent` | `agree: true, policy_version` | `consent` |
| `POST /api/agent/screenshot` | multipart `screenshot`, `activity_pct`, `meeting`, `display`, `captured_at` | `id` |
| `POST /api/agent/error` | `level, message, where, stack, file, line, os, arch, context{}` | token optional |
| `GET /api/agent/version` | | `latest, current, update_available, released_at, download_page, updates_feed, files{windows,mac,linux,deb}[{name, arch, ext, size, version, sha512}]` |
| `GET /api/agent/updates/{file}` | | electron-updater feed (not JSON): `latest.yml`, `latest-mac.yml`, `latest-linux.yml`, `latest-linux-arm64.yml`, `latest-linux-armv7l.yml` as `text/yaml`, or a mirrored installer / `.blockmap` (streamed). `404` when the file is not in the mirrored release |
| `GET /api/agent/status` | | legacy alias of bootstrap |

Tracking actions accept an optional `at` (ISO 8601): the moment the person clicked, for actions the app queued while offline.
The server uses it when it is not in the future, not before the person's last recorded event and at most 6 hours old.
All tracking actions are idempotent and serialised per user: retrying `day/start`, `pause`, `resume` or `day/end` after a timeout
never double-books. `pause` with nothing running is a no-op (an entry still running from an earlier day is stopped and its day
closed). `projects` lists only projects the person may see (`ProjectAccess`); `project_id`/`task_id` are validated
again on every call.

`state` object: `state` (`not_started | tracking | paused | stopped | ended`; `stopped` = day open but no running entry, e.g. after
the server stopped a stale entry; `ended` only after a legacy `day/end`), `label`, `day{date, clock_in, clock_out, paused_since}`, `running{id, started_at, elapsed,
project_id, project, task_id, task, description, device, …}` or `null`, `last{project_id, task_id, description}`,
`totals{tracked, active, idle, paused, entries}` in seconds for today, plus `activity` (today's activity %, meeting slots
excluded, `null` when there is nothing to average) and `meeting` (seconds tracked in meeting mode today).

`settings`: `heartbeat_seconds` (15 to 60, default 60), `idle_minutes`, `idle_auto_pause_minutes` (2 to 240, default 15,
always at least `idle_minutes` + 1), `auto_pause_on_lock`, `screenshots_enabled`,
`screenshot_interval_seconds` (10 to 3600, default 180), `screenshot_notify` (default on), `screenshot_notify_user_can_disable`,
`retention_days`, `stale_minutes` (10), `projects_enabled`.

## Activity, idle and stale entries

**Activity.** The app reads the system idle time (`powerMonitor.getSystemIdleTime()`: `GetLastInputInfo` on Windows, the
IOHIDSystem idle time on macOS, the X11 / logind idle time on Linux) once per second, the same way on every platform. Only
whether there was keyboard or mouse input is used: no keystrokes or mouse events are counted, nothing about what is typed or
where, no input hook, and on macOS no Accessibility permission. Tracked seconds are grouped in 12-second blocks (from the slot
start); a block is active when there was input in it or less than 1 s before it began; a single inactive block between two busy
blocks (input in at least 4 different seconds) counts as active (a short thinking pause); while the screen is locked or the
computer sleeps blocks are inactive at once, and the first block after waking is left out of the slot. Typical results: focused
work about 90-95%, reading with a scroll every 15-25 s about 65%, one input a minute about 20%, away 0%. The constants are in
`desktop/src/main/meter.js`; `desktop/scripts/simulate.js` prints the results for typical input patterns. Seconds are summed
per slot of `screenshot_interval_seconds`, counted from the workspace's midnight: `POST /api/agent/activity` with
`slots: [{slot_start (ISO 8601), slot_seconds (tracked seconds in the slot, paused time excluded, 1 to 3600), active_seconds,
meeting}]`. Stored in `activity_slots` (one row per user and `slot_start`, tied to the time entry that overlaps the slot most,
with the device token): `active_seconds` is clamped to `slot_seconds`, a repeated `slot_start` replaces the row only when it
covers at least as many seconds (retries never double count), slots older than 7 days or in the future are skipped. Activity %
of a slot = `active_seconds / slot_seconds`. Slots are sent whether or not screenshots are on. The screenshot taken at the end
of a slot carries that slot's `activity_pct` and `meeting` (stored on `screenshots`). The `keystrokes` / `mouse_events`
columns of `activity_slots` and `screenshots` stay (nullable) for older data only: the server ignores counts that apps up to
1.2.x still send and the web app no longer shows counts.

**Meeting mode.** A switch in the app: time is tracked as usual (screenshots as configured), idle detection and the automatic
pause are off, and it turns off by itself after 30 minutes (or on Pause, or when switched off; apps before 1.4.2: 1 hour). A slot counts as a meeting slot
when meeting mode covered at least half of its tracked seconds; it is sent with `meeting: 1` (booleans and `"1"` accepted)
and stored in `activity_slots.meeting` (screenshots: `screenshots.meeting`). Meeting slots keep their activity and are visible
to managers (the flag is on the slot and the screenshot), but every activity average leaves them out:
`TimeService::dayActivity()` (used by the day summary in `BaseController::day()` and by `state.totals.activity`) averages
only slots with `meeting = 0`, and its screenshot fallback only screenshots with `meeting = 0`. A day with nothing but
meetings has no activity average (`null`).

**Idle.** Once there was no input for `idle_minutes`, the person is idle from the last input (those seconds move from active
to idle) and the timer keeps running. Every `heartbeat_seconds` (at most 60) the app sends the counts since the previous beat
with the beat's time `at`. The server adds the idle part to the running entry (capped to the time since the last beat plus the
idle threshold, and to the elapsed time) and moves `last_heartbeat_at` to `at`. When the idle span ends (input again, a pause,
sleep) the app sends it in the next heartbeat as `idle_spans: [{from, to}]`; each span is recorded per overlapping entry in
`time_idle_spans` with `action = 'auto'` (retries are skipped). Idle time is never removed from tracked time and nothing is
asked. If the person stays idle until `idle_auto_pause_minutes` after the last input, the app sends `pause` with
`at` = last input + that maximum: the idle minutes up to then stay tracked as idle time and nothing after is tracked. When the
person is back the app shows "Tracking paused after 15 min idle. Resume when you are back." with a Resume button.
`POST /api/agent/idle` remains for apps up to 1.0.2: both answers only record the spans as kept idle time (`action = 'keep'`),
`discard` no longer removes anything.

**Stale entries.** A beat whose seconds start more than 10 minutes after the last heartbeat
(`at - active - idle - last_heartbeat_at`) means the app was not running in between (switched off, killed, asleep): the entry is
stopped at its last heartbeat and `running: false` is returned. Offline beats are merged in the queue only while contiguous
(their idle spans are kept), so a continuous offline stretch is kept and a gap is not bridged; activity slots queued offline
are sent in one batch.

If no heartbeat arrives for 10 minutes the `stale_timers` job (every 5 min) stops the entry at its last heartbeat (never at the
time the job runs) and closes days that are over; the day stays open (`stopped`) and the app resumes with `resume` when it comes
back. The daily `auto_close` job (23:55) only does the same (it no longer closes today or stops a healthy entry: the day ends at
midnight). Heartbeat `running: false` tells the app to refresh `state`.

**Tracker off means no time accrues (server side, also protects apps 1.3.x / 1.4.x).** A running entry is *stale* when it has
had no heartbeat for 10 minutes (`TimeService::stale`). Then:
- every read settles it: `GET /api/agent/state` and `bootstrap`, the web `time/status` (time page, dashboard card, top bar
  chip poll) and `TimeService::running()` (top bar chip) stop it at its last heartbeat, so it is never shown as running;
- totals that are not settled per person (team pages, timesheets, payroll `secondsForUser`) count a stale running entry only
  up to its last heartbeat (`TimeService::secondsSql()`), never up to now;
- an action (`pause`, `resume`, `switch`, `day/start`) is judged at its own time (`at`): a Pause sent right after waking from a
  long sleep, or replayed from the offline queue hours later (clamped to 6 h back), stops the entry at its last heartbeat, not
  at the pause time; a heartbeat is judged at the start of the seconds it covers, so a merged offline beat (also across
  midnight) continues the entry;
- exception: an entry the server created in the last 10 minutes from a queued, older start that has had no heartbeat yet is
  not stale (the app is replaying its queue; its heartbeats follow);
- signing out (`POST /api/agent/logout`) or Disconnect on the web stops the entry of that device at once.

**Crash or power cut.** The app saves locally every 15 s whether it is tracking and when it was last alive. On the next start,
if it was tracking: back within 10 minutes it sends `pause` at that time (`at`) and `resume` (tracking continues, the minutes
the app was not running are not counted); later, it sends an empty heartbeat so the server stops the entry at its last heartbeat
(at most 60 s lost), and shows the normal not-tracking state with a note (Resume, or Start in a new day).

## Screenshots and consent (mandatory)

Accepted only when monitoring is enabled (`attendance.screenshots_enabled`), the person has an active consent for the current
policy version, and the capture moment falls inside one of their time entries (running, or stopped for captures queued while
offline). The first time screenshots are enabled for a person, the app shows `policy` and records consent with
`POST /api/agent/consent` before capturing. Consent can be revoked on the web at any moment; uploads then answer `422` and the
app stops capturing. One upload per display per capture (`display` = 0-based index), JPEG quality ~0.6, max 1920 px wide,
≤ 3 MB, ≤ 4096 px per side; at most one per display every `min(20, interval/2)` seconds. The app shows a tray indicator while
tracking and, if enabled, a small notice for every capture. People always see their own screenshots (`/time/screenshots`);
others need `attendance.screenshots.view` and team scope; every view of someone else's screenshots is audit-logged; images older
than `attendance.screenshot_retention_days` are deleted nightly.

## Errors and updates

The app reports uncaught exceptions, unhandled rejections, API calls that failed after retries, capture failures and update
failures to `POST /api/agent/error` (source `desktop`, app version, OS).

Releases are built in, nothing is configured per workspace. The desktop app is published as GitHub releases tagged
`tracker-vX.Y.Z` in the official (private) repository from `niyanta.json` (`repository`; `updates.repo` overrides it for
forks). The server reads them with the token from **Settings › Updates** (`updates.github_token`, read-only Contents) and the
scheduler job `attendance.tracker_mirror` (every 30 minutes; release metadata cached 30 minutes) copies the newest
non-draft, non-prerelease release once into `storage/desktop/<version>/` (private; size checked against GitHub and sha512
against `latest*.yml`; newest 2 versions kept). Super Admins can force it with **Settings › Updates › Refresh now**.

- `GET /api/agent/version` compares `X-App-Version` with the mirrored version and links to the download page (`/time#desktop`).
  Every file carries `sha512`: base64 of the raw SHA-512 digest (electron-builder format), recorded in the manifest when
  mirrored, else read from the mirrored `latest*.yml`, else hashed once and cached in `sha512.json` next to the mirror (never
  per request). `download_page` and `updates_feed` are built from the configured workspace URL (`app_url`; a spoofed Host header
  does not change them), and no `/api/agent/*` endpoint answers with a redirect.
- `GET /api/agent/updates/{file}` (device token, same auth as the other endpoints) is the feed of electron-updater's generic
  provider: the app sets the feed URL to `<workspace>/api/agent/updates/` at runtime and sends `Authorization: Bearer <token>`.
  The `latest*.yml` files are served with every `url:` / `path:` rewritten to `<workspace>/api/agent/updates/<file name>`, so
  the installer and its `.blockmap` also come from the workspace (GitHub is never contacted by the app).
- People download installers on **Time Tracking › Get the desktop app** from `GET /time/desktop/download/{file}` (signed in,
  `attendance.track`, `Content-Disposition: attachment`).
