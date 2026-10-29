# Niyanta Desktop Agent API

The desktop agent is an optional, separately installed and **separately authorised** program that tracks time and (with consent) full-desktop screenshots for one person. The agent itself is not part of Niyanta; this document is the contract an agent must implement.

## Authentication

1. The person signs in to Niyanta, opens **Time Tracker → Desktop agent**, names the device and clicks **Create**.
2. The token (`nyt_` + 48 hex characters) is shown **once**. Niyanta stores only its SHA-256 hash (`agent_tokens.token_hash`).
3. The agent sends it on every request:
   ```
   Authorization: Bearer nyt_xxxxxxxx…
   Accept: application/json
   ```
   If your host strips the `Authorization` header (some CGI/FastCGI setups), send `X-Agent-Token: nyt_…` instead.
4. Tokens can be revoked at any time from the same card. A revoked token, a deactivated user, a user without `attendance.track`, or a disabled Attendance module all return an error. A person can have at most 5 active tokens.

Requests are rate limited per IP (600/min; screenshots 200/min) and screenshots additionally to one per 20 seconds per user. No session cookie or CSRF token is used.

## Responses

Every response is JSON. Success: `{"ok": true, …}`. Errors: `{"error": "message"}` with HTTP `401` (bad/revoked token), `403` (no permission or module disabled), `422` (rule violation, `ok: false`), `429` (rate limit).

## Endpoints

### `GET /api/agent/status`
Returns the user, the running entry (or `null`), server time, settings and consent:
```json
{"ok":true,"user":{"id":21,"name":"Esha Patel"},
 "running":{"id":37,"started_at":"2026-10-29T09:00:00+05:30","elapsed":1840,"idle_seconds":120,"project":"Website Redesign","task":null,"description":"…","source":"agent"},
 "server_time":"2026-10-29T09:30:40+05:30",
 "settings":{"heartbeat_seconds":60,"idle_minutes":5,"screenshots_enabled":true,"screenshot_interval_minutes":3,"retention_days":30},
 "consent":{"policy_version":"2026-1","consented_at":"…"},
 "projects":[{"id":6,"name":"Website Redesign"}]}
```
`projects` lists only projects the person may log time on. Call this at start-up and every few minutes to pick up setting changes and consent revocation.

### `POST /api/agent/start`
Form or JSON body: `project_id` (optional), `task_id` (optional, must belong to the project), `description` (optional, ≤500 chars), `billable` (default `true`). Fails with 422 if a timer is already running (from the browser or another agent); one running entry per user is enforced on the server. Entries created here have `source = agent`. When the timer stops the entry is approved automatically (status `approved`), unless the week was rejected by a manager (status `rejected` until the employee resubmits the week).

### `POST /api/agent/stop`
Stops the running entry (whatever its source) at server time. Returns `{"stopped":{"id":37,"duration":1840}}`.

### `POST /api/agent/heartbeat`
Body: `active_seconds`, `idle_seconds`: activity measured **since the previous heartbeat** (send every 60 s). The server adds the idle part to the entry's `idle_seconds` (capped to the time since the last heartbeat plus the idle threshold, and to the elapsed time) and updates `last_heartbeat_at`. Returns `{"running": true|false, "entry": {…, "activity": 83}}`; when `running` is false the agent should stop tracking locally.

**Stale timers:** if no heartbeat arrives for more than 10 minutes, the `stale_timers` cron job (every 5 minutes) stops the entry at its last heartbeat. A daily `auto_close` job at 23:55 also closes open attendance and stops running timers.

### `POST /api/agent/screenshot`
`multipart/form-data` with field `screenshot` (JPEG, PNG or WebP, ≤3 MB, ≤4096 px per side; recommended JPEG quality ~0.6, max width 1280 px) and optional `activity_pct` (0–100). Accepted only when **all** of these are true:
- the administrator enabled monitoring (`attendance.screenshots_enabled = 1`);
- the person has an active, unrevoked consent for the current policy version (`monitoring_consents`);
- the person has a running time entry (the screenshot is linked to it).

Returns `{"id": 123}`. Take screenshots every `screenshot_interval_minutes` and only while the timer runs.

## Consent rules (mandatory for agents)

- Before capturing, the person must accept the monitoring notice in Niyanta (Time Tracker → Screen monitoring). The agent must check `consent` in `/status` and never capture when it is `null`.
- Consent can be revoked at any moment in the web app; uploads are then rejected immediately. Agents must stop capturing as soon as an upload returns 422 or `/status` shows no consent.
- The agent must show a persistent, visible indicator (tray icon or similar) whenever it is capturing, and must offer a way to pause/stop.
- When the policy version changes, previous consents no longer count and the person must accept again.

## Visibility & retention

- People can always see their own screenshots (`/time/screenshots`).
- Others need the `attendance.screenshots.view` permission **and** team scope: HR / Super Admin see everyone; managers see their direct reports and members of projects they manage. Images are served only through the authorised controller (`/time/screenshots/{id}/image`), never publicly; every view of someone else's screenshots is audit-logged.
- Screenshots older than `attendance.screenshot_retention_days` (default 30) are deleted (files and rows) by the daily `screenshot_cleanup` cron job.
