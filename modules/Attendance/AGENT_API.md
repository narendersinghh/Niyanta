# Niyanta Tracker API (desktop app)

Niyanta Tracker (`desktop/` in this repository, Electron, Windows/macOS/Linux) is the only way to track time. The web app shows
live status, entries, timesheets and screenshots read-only (manual corrections excepted). This is the contract the app uses.

**One model:** attendance and time tracking are the same thing.

| App action | Server effect |
|---|---|
| Start day | clock in (or resume an ended day; the gap counts as a pause) + start a time entry |
| Pause | stop the running entry + start a break |
| Resume | end the break + start a new entry on the last (or given) project/task |
| Switch project | close the running entry and start the next one **at the same second** (no gap, no pause) |
| End day | stop the entry + clock out |

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
**Time Tracking › Connected devices**. A revoked token, a deactivated user, a role without `attendance.track` or time tracking
switched off answer `401`/`403`; the app then signs out.

## Responses and errors

JSON everywhere. Success: `{"ok": true, …}`. Errors: `{"ok": false, "error": "message", "code"?: "…"}` with `401`
(signed out), `403` (no permission / feature off), `422` (rule violation), `429` (rate limit), `500` (server failure, recorded in
**Audit log › Error logs** with source `desktop`, the app version and a `ref`). Rate limits per IP: 600/min (login 20 per 5 min,
screenshots 240/min, error reports 60/min, version 30/min, update files 60/min per file).

## Endpoints

| Method & path | Body | Returns |
|---|---|---|
| `POST /api/agent/login` | `email, password, device_name, os, app_version` | `token` + bootstrap payload |
| `POST /api/agent/logout` | | revokes the token |
| `GET /api/agent/bootstrap` | | `user, workspace, permissions, settings, consent, policy, projects[{id,name,tasks[]}], state` |
| `GET /api/agent/state` | | `state, settings, consent` (cheap refresh) |
| `GET /api/agent/tasks?project_id=` | | open tasks of a visible project |
| `POST /api/agent/day/start` | `project_id?, task_id?, description?` | `state` |
| `POST /api/agent/pause` | | `state` |
| `POST /api/agent/resume` | `project_id?, task_id?, description?` | `state` |
| `POST /api/agent/switch` | `project_id (null = no project), task_id?, description?` | `state` |
| `POST /api/agent/day/end` | | `state` |
| `POST /api/agent/heartbeat` | `active_seconds, idle_seconds` since the previous beat | `running, entry, state` |
| `POST /api/agent/idle` | `action: keep|discard, seconds` | `discarded, state` |
| `POST /api/agent/consent` | `agree: true, policy_version` | `consent` |
| `POST /api/agent/screenshot` | multipart `screenshot`, `activity_pct`, `display`, `captured_at` | `id` |
| `POST /api/agent/error` | `level, message, where, stack, file, line, os, arch, context{}` | token optional |
| `GET /api/agent/version` | | `latest, current, update_available, released_at, download_page, updates_feed, files` |
| `GET /api/agent/updates/{file}` | | electron-updater feed (not JSON): `latest.yml`, `latest-mac.yml`, `latest-linux.yml`, `latest-linux-arm64.yml`, `latest-linux-armv7l.yml` as `text/yaml`, or a mirrored installer / `.blockmap` (streamed). `404` when the file is not in the mirrored release |
| `GET /api/agent/status` | | legacy alias of bootstrap |

Tracking actions accept an optional `at` (ISO 8601): the moment the person clicked, for actions the app queued while offline.
The server uses it when it is not in the future, not before the person's last recorded event and at most 6 hours old.
All tracking actions are idempotent and serialised per user: retrying `day/start`, `pause`, `resume` or `day/end` after a timeout
never double-books. `projects` lists only projects the person may see (`ProjectAccess`); `project_id`/`task_id` are validated
again on every call.

`state` object: `state` (`not_started | tracking | paused | stopped | ended`; `stopped` = day open but no running entry, e.g. after
the server stopped a stale entry), `label`, `day{date, clock_in, clock_out, paused_since}`, `running{id, started_at, elapsed,
project_id, project, task_id, task, description, device, …}` or `null`, `last{project_id, task_id, description}`,
`totals{tracked, active, idle, paused, entries}` in seconds for today.

`settings`: `heartbeat_seconds` (15 to 300, default 60), `idle_minutes`, `auto_pause_on_lock`, `screenshots_enabled`,
`screenshot_interval_seconds` (10 to 3600, default 180), `screenshot_notify` (default on), `screenshot_notify_user_can_disable`,
`retention_days`, `stale_minutes` (10), `projects_enabled`.

## Activity, idle and stale entries

The app samples `powerMonitor.getSystemIdleTime()` every second: a second is *idle* when there was no mouse or keyboard input for
at least `idle_minutes`, otherwise *active*. Every `heartbeat_seconds` it sends the counts since the previous beat. The server adds
the idle part to the running entry (capped to the time since the last beat plus the idle threshold, and to the elapsed time).
When the person returns from an idle stretch longer than the threshold, the app sends a heartbeat and asks "keep or discard";
`discard` removes the idle seconds from the entry (`discarded_seconds`).

If no heartbeat arrives for 10 minutes the `stale_timers` cron (every 5 min) stops the entry at its last heartbeat; the day stays
open (`stopped`) and the app resumes with `resume` when it comes back. The daily `auto_close` job (23:55) stops running entries at
their last heartbeat and closes open days at the end of their last entry. Heartbeat `running: false` tells the app to refresh `state`.

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
- `GET /api/agent/updates/{file}` (device token, same auth as the other endpoints) is the feed of electron-updater's generic
  provider: the app sets the feed URL to `<workspace>/api/agent/updates/` at runtime and sends `Authorization: Bearer <token>`.
  The `latest*.yml` files are served with every `url:` / `path:` rewritten to `<workspace>/api/agent/updates/<file name>`, so
  the installer and its `.blockmap` also come from the workspace (GitHub is never contacted by the app).
- People download installers on **Time Tracking › Get the desktop app** from `GET /time/desktop/download/{file}` (signed in,
  `attendance.track`, `Content-Disposition: attachment`).
