# Niyanta Tracker (desktop app)

The time tracker for Niyanta workspaces, for **Windows, macOS and Linux**. Since the web app no longer starts or stops time,
this app is how people track their day: Start (clock in), Pause, Resume and switch project without stopping. There is no
"end day": the day ends by itself at midnight (workspace time zone) and, if the person was tracking, continues in the new day on
the same project/task. Activity (active / idle) comes from keyboard and mouse input, and if the workspace turns it on, screenshots of
all screens are taken at the configured interval, with a small notice each time. Everything syncs with the server; the API
contract is in [`modules/Attendance/AGENT_API.md`](../modules/Attendance/AGENT_API.md).

Electron, plain JavaScript, **no native modules** (it builds on any CI runner without a compiler).

```
src/main/      main.js (lifecycle, tray, window, IPC, auto-launch), tracker.js (state machine, activity, heartbeats,
               screenshots, power events), api.js (HTTP client), queue.js (offline queue), capture.js (screenshots),
               toast.js (capture notice), store.js (prefs + encrypted token), errors.js (error reporting),
               updater.js (electron-updater against the workspace)
src/preload/   contextIsolation bridge (window.tracker) and the notice window bridge
src/renderer/  index.html / app.js / styles.css (380 x 640 window), toast.html (capture notice)
build/         icon.png (1024, converted by electron-builder), icons/ (Linux), tray/ (tray states), mac entitlements
scripts/       icons.js (regenerate icons from the logo), check.js (syntax check)
```

## Develop

```bash
cd desktop
npm install
npm start                      # opens the app; sign in with your workspace URL, email and password
npm run check                  # node --check on every JS file
NIYANTA_TRACKER_PROFILE=/tmp/tracker-2 npm start   # a second, separate profile (another user)
```

`http://localhost` / `127.0.0.1` workspaces are allowed for development; any other address must be `https://`.

## Build installers

| Command | Output (in `dist/`) |
|---|---|
| `npm run dist:win` (on Windows) | `niyanta-tracker-<v>-win-x64.exe` (NSIS installer) + `.blockmap`, `latest.yml` |
| `npm run dist:mac` (on macOS) | `niyanta-tracker-<v>-mac-x64.dmg` / `-arm64.dmg` and `.zip` + `.blockmap`, `latest-mac.yml` |
| `npm run dist:linux` (on Linux) | `niyanta-tracker-<v>-linux-x86_64.AppImage`, `niyanta-tracker-<v>-linux-amd64.deb`, `latest-linux.yml` |

The `latest*.yml` files are the auto-update metadata (version, file names, sha512, sizes). electron-builder writes them because
`build.publish` names a `generic` provider; its URL (`https://updates.invalid/...`) is a placeholder that the app replaces at
runtime with the workspace it is signed in to. Builds always run with `--publish never`; electron-builder uploads nothing.
Icons: `build/icon.png` is converted to `.ico` / `.icns` by electron-builder; run `npm run icons` (under `xvfb-run` on a
headless Linux box) after changing `src/renderer/logo.svg`.

### Releasing a new version

Releases live in the private repository `narendersinghh/Niyanta` (the same repository as the web app; web app releases use
tags `vX.Y.Z`, the tracker uses `tracker-vX.Y.Z`). Nothing is published publicly. A Niyanta server only needs the GitHub
access token in **Settings › Updates** (the same one app updates use, see docs/UPDATES.md).

1. Bump `version` in `desktop/package.json` (e.g. `1.2.0`) and commit: `git commit -am "Tracker 1.2.0"`.
2. Tag and push: `git tag tracker-v1.2.0 && git push origin main tracker-v1.2.0`.
3. `.github/workflows/desktop.yml` checks that the tag equals the `package.json` version (it fails otherwise), builds Windows,
   macOS and Linux, and creates the GitHub Release "Niyanta Tracker v1.2.0" (not a draft, not a prerelease) with all
   installers, `latest*.yml` and `.blockmap` files, using the workflow's own `GITHUB_TOKEN`.
4. Every Niyanta server with a GitHub token in **Settings › Updates** copies the release to `storage/desktop/1.2.0/` within
   30 minutes (Super Admins can press **Refresh now** there). From then on Time Tracking offers it and installed apps update.

Running the workflow by hand (Actions › Desktop tracker › Run workflow) makes test builds: workflow artifacts only, no release.

### Auto-update

The app checks on start and every 6 hours: `GET /api/agent/version`, then electron-updater (generic provider) with the feed
`<workspace>/api/agent/updates/` and the device token as `Authorization: Bearer`. The workspace serves the `latest*.yml`
rewritten to point at itself, plus the installers and blockmaps it mirrored. The update downloads in the background; the
tray menu and the window then show **Update ready, restart to install**, and it installs when the app quits anyway
(`autoInstallOnAppQuit`). Errors go to the workspace error log through the normal error reporting.

| Platform | Auto-update |
|---|---|
| Windows (NSIS) | yes |
| macOS | only for **signed** builds (Squirrel.Mac rejects unsigned apps). Unsigned builds show a **Download** button that opens Time Tracking › Get the desktop app. |
| Linux AppImage | yes (the AppImage file is replaced in place) |
| Linux .deb | no, the app shows the **Download** button (install the new .deb with apt) |

### Code signing (optional)

Unsigned builds work but show warnings (Windows SmartScreen "unknown publisher", macOS Gatekeeper: right-click › Open the
first time). To sign in CI add these repository secrets; the workflow passes them to electron-builder when present:

| Platform | Secrets |
|---|---|
| Windows | `WIN_CSC_LINK` (base64 .pfx or https URL), `WIN_CSC_KEY_PASSWORD` |
| macOS | `MAC_CSC_LINK` (base64 Developer ID Application .p12), `MAC_CSC_KEY_PASSWORD`, and for notarisation `APPLE_ID`, `APPLE_APP_SPECIFIC_PASSWORD`, `APPLE_TEAM_ID` (then set `"notarize": true` under `build.mac`) |

## Permissions per OS

- **macOS**: *Screen Recording* (System Settings › Privacy & Security › Screen Recording) is needed for screenshots; the app
  detects a missing permission and links to the right pane. Idle detection needs no permission. Launch at login uses Login Items.
- **Windows**: no special permission. Launch at login uses the Run registry key of the current user.
- **Linux (X11)**: works out of the box. **Wayland**: screen capture goes through the desktop portal and may ask every time
  (or be unavailable on some compositors); idle time may read as 0 on some Wayland sessions. Launch at login writes
  `~/.config/autostart/niyanta-tracker.desktop`. Without a keychain service (gnome-keyring / kwallet) the device token is
  stored unencrypted in the user's profile (file mode 600). The capture notice uses a plain window on Linux (no transparency).

## How it works

- **Sign-in**: `POST /api/agent/login` returns a personal device token, kept encrypted with the OS keychain (`safeStorage`).
  People see and disconnect devices on Time Tracking › Connected devices. Same lockout and rate limits as the web sign-in.
- **State machine**: the server is the source of truth; the app applies actions optimistically and sends them with their time
  (`at`). Retries never double-book (the server's actions are idempotent).
- **Offline**: actions, heartbeats, idle answers, screenshots and error reports go into a persisted queue
  (`userData/queue.json`, screenshots in `userData/pending/`) and are retried with exponential backoff. If the computer is
  offline for more than 10 minutes the server stops the entry at the last sync; the app tells the person and resumes when back.
  Queued heartbeats keep their time and are merged only while contiguous, so a gap is never counted.
- **Midnight**: the app checks the date (workspace time zone) every second and after waking up. Tracking at midnight: the
  server closes the running entry and the old day at 00:00 and continues on the same project/task in the new day (the app sends
  a heartbeat at once; no click needed). Asleep across midnight: the old entry ends at its last heartbeat and tracking resumes
  in the new day on waking. Paused at midnight: the old day ends; Start begins the new one.
- **Activity and idle**: `powerMonitor.getSystemIdleTime()` every second. After the workspace's idle threshold (`idle_minutes`)
  without input an idle span opens at the last input; the timer keeps running and the chip shows *Idle*. When the person is
  back, the window comes to the front (plus a notification that opens it) with "You were idle for 14 min (2:10 pm to 2:24 pm).
  Keep or discard this time?". Tracking continues while the question is open; spans that pile up before an answer are merged
  into one prompt. Discard removes exactly those spans on the server (`/api/agent/idle` with the spans' times); Keep counts them
  as worked (still reported as idle). Nothing is detected while paused; an idle span still open when tracking pauses (e.g. screen
  locked) ends at the pause.
- **Power**: locking the screen or sleeping pauses tracking (if the workspace setting is on) and unlocking / waking resumes it.
  Quitting the app while tracking pauses first; a system shutdown or log-out is delayed (Linux/macOS) until that is done.
- **Crash or power cut**: heartbeats (at most every 60 s) keep the running entry's end on the server, and the app saves locally
  every 15 s whether it is tracking and when it was last alive (`config.json` > `session`). On the next start after an unclean
  exit while tracking: back within 10 minutes (crash, quick restart) the entry is closed at the last alive moment and tracking
  resumes automatically with a note; after longer, the server closes the entry at its last heartbeat (at most a minute is lost,
  the time the computer was off is never counted) and the app shows the normal not-tracking state with a note, so nobody is
  tracked by surprise hours later (Resume, or Start in a new day). The server's stale job does the same if the app never returns.
- **Screenshots**: only with monitoring enabled, the person's consent (the app shows the notice and records consent via the
  API before the first capture) and while tracking. All displays, max 1920 px wide, JPEG quality 60, uploaded with the activity
  percentage of the interval. They are not shown in the app (only the small capture notice, if enabled); people see them on
  the web (My screenshots).
- **Errors**: uncaught exceptions, unhandled rejections, failed syncs and capture failures are written to
  `userData/logs/tracker.log` and sent to the workspace error log (Audit log › Error logs, source *desktop*, with the version).
- **Updates**: see *Auto-update* above.

## Privacy

The app records: start/pause times, the chosen project/task, counts of active and idle seconds (never keystrokes, never
which keys or what is typed, no URLs or window titles), and, only when the workspace enables monitoring and the person accepted
the notice, screenshots while tracking. People can see all of it in the web app (Time Tracking, Timesheets, My screenshots),
revoke screenshot consent at any time, and screenshots are deleted after the workspace's retention period.
