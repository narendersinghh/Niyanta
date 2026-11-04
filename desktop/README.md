# Niyanta Tracker (desktop app)

The time tracker for Niyanta workspaces, for **Windows, macOS and Linux**. Since the web app no longer starts or stops time,
this app is how people track their day: Start (clock in), Pause, Resume and switch project without stopping. There is no
"end day": the day ends by itself at midnight (workspace time zone) and, if the person was tracking, continues in the new day on
the same project/task. Activity (active / idle) comes from keyboard and mouse input, and if the workspace turns it on, screenshots of
all screens are taken at the configured interval, with a small notice each time. Everything syncs with the server; the API
contract is in [`modules/Attendance/AGENT_API.md`](../modules/Attendance/AGENT_API.md).

Electron, plain JavaScript. One native module, [`uiohook-napi`](https://github.com/SnosMe/uiohook-napi) (global keyboard and
mouse hook for real activity levels): it is N-API and ships prebuilt binaries for win32-x64, darwin-x64/arm64 and linux-x64,
so nothing is compiled (`build.npmRebuild` is `false`; electron-builder unpacks the `.node` file from the asar automatically).
If it cannot load or run on a computer, the app falls back to the system idle time (see *Activity and idle*).

```
src/main/      main.js (lifecycle, tray, window, IPC, auto-launch, notifications), tracker.js (state machine, activity slots,
               idle, heartbeats, screenshots, power events), activity.js (keyboard/mouse input hook or idle-time fallback),
               meter.js (activity windows and grace period), pomodoro.js (break reminder), api.js (HTTP client),
               queue.js (offline queue), capture.js (screenshots),
               toast.js (capture notice), store.js (prefs + encrypted token), errors.js (error reporting),
               updater.js (update check, Update now, manual download), selfupdate.js (pure helpers: feed parsing,
               macOS helper script)
src/preload/   contextIsolation bridge (window.tracker) and the notice window bridge
src/renderer/  index.html / app.js / styles.css (380 x 640 window), toast.html (capture notice)
build/         icon.png (1024, converted by electron-builder), icons/ (Linux), tray/ (tray states), mac entitlements
scripts/       icons.js (regenerate icons from the logo), check.js (syntax check + simulation + update checks), simulate.js
               (activity and pomodoro simulation on fake input timelines), updates-check.js (selfupdate.js checks, runs the
               generated macOS helper on fake bundles), adhoc-sign.js (afterPack: ad-hoc signature without a certificate)
```

## Develop

```bash
cd desktop
npm install
npm start                      # opens the app; sign in with your workspace URL, email and password
npm run check                  # node --check on every JS file, the simulation below and the update helper checks
npm run simulate               # activity % for typical input patterns (old vs new rule) and the pomodoro checks
NIYANTA_TRACKER_PROFILE=/tmp/tracker-2 npm start   # a second, separate profile (another user)
NIYANTA_NO_INPUT_HOOK=1 npm start                  # force the idle-time fallback (no input hook)
```

The activity source in use (and why the fallback is used) is written to `userData/logs/tracker.log`
(`activity source: input hook ...` or `activity source: system idle time ... (reason)`).

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

### Updates (in the app)

The app checks on start and every 6 hours: `GET /api/agent/version` (device token). When the workspace has a newer
version, the window shows a card **Niyanta Tracker 1.2.0 is available** (with up to 4 lines of *what's new* when the
`latest*.yml` has `releaseNotes`) and one button, **Update now**; the tray menu has the same item, and one OS notification
is shown per version. Nothing restarts without that click (or a normal quit, which installs a pre-downloaded update on
Windows / AppImage, as before). Clicking: progress bar, then tracking is paused (the click's time is sent; up to 15 s for the
server), the app installs and restarts, and on the next start tracking resumes by itself (`config.json` > `updateRestart`,
honoured within 30 minutes). The restart minutes are never counted and nothing is counted twice: if the pause did not reach
the server, the entry is closed at the click time first. If the version did not change after the restart, the card shows
the manual download instead.

All downloads come from `<workspace>/api/agent/updates/<file>` with the device token (the workspace mirrors the private
GitHub release; the app never talks to GitHub). Files an app downloads itself get no Mark-of-the-Web (Windows) and no
quarantine flag (macOS), so SmartScreen and Gatekeeper do not interfere.

| Install | What **Update now** does |
|---|---|
| Windows (NSIS) | electron-updater (generic provider). The update is pre-downloaded in the background; the click waits for it, then `quitAndInstall(true, true)`: silent NSIS update, app starts again. |
| Linux AppImage | electron-updater: the AppImage file is replaced in place and started again. |
| macOS, ad-hoc signed (no Developer ID) | Own updater (Squirrel.Mac refuses such apps): reads `latest-mac.yml`, downloads the zip for this Mac (arm64 on Apple silicon, also under Rosetta), checks its sha512, extracts it with `ditto -x -k` and starts a detached helper script (`selfupdate.macHelperScript`) that waits for the app to quit, swaps the bundle at its current path (from `process.execPath`; `/Applications`, or `~/Applications` when that is not writable, or when the app ran from the disk image / App Translocation), keeps the old copy until the new one is in place (restores it on failure), runs `xattr -cr` and opens the new version. Log: `userData/logs/update.log`. |
| macOS with a Developer ID | Detected at start (`codesign -dv` shows a TeamIdentifier): electron-updater / Squirrel.Mac as on Windows. `NIYANTA_MAC_UPDATER=custom` or `=squirrel` forces one or the other. |
| Linux .deb | Downloads the new .deb to Downloads and opens it with the system installer; the card shows `sudo apt install '<file>'` with Copy and a **Restart app** button (tracking resumes). |
| Anything failed, development build | **Download the new version**: saves the installer for this computer to Downloads (inside the app, no browser) and opens it, with 2-3 short steps (macOS: the dmg opens; quarantine is removed first; drag to Applications and Replace). |

Errors go to the workspace error log through the normal error reporting.

### First install without warnings (one-line commands)

Time Tracking › *Get the desktop app* shows, next to the download buttons, a personal command per OS (the visitor's OS
first). They download straight from the workspace (no browser, so no Chrome or SmartScreen prompt, no quarantine flag) and
check the sha512 from the `latest*.yml`:

```bash
# macOS (zip for arm64/x64 -> /Applications or ~/Applications, xattr -cr, opens it) and Linux (AppImage -> ~/.local/bin + menu entry)
curl -fsSL "https://team.example.com/time/desktop/install.sh?k=<key>" | bash
# Windows PowerShell (Invoke-WebRequest to %TEMP%, silent per-user install /S, starts the app)
irm "https://team.example.com/time/desktop/install.ps1?k=<key>" | iex
```

The key is signed by the server for the signed-in user (HMAC with the app key, bound to this purpose and to the user's
session version), works for 2 hours, only for these routes and the installer files they fetch
(`/time/desktop/get/<file>?k=<key>`), is throttled and every script/download is audit-logged. Commands are only offered
on HTTPS workspaces (or localhost). Server side: `modules/Attendance/Services/DesktopInstall.php`.

### Code signing (optional)

Unsigned builds work but a browser download shows warnings (Chrome "uncommon download", Windows SmartScreen "unknown
publisher", macOS "damaged" / "unverified developer"). The one-line commands and in-app updates avoid them; signing removes
them for the normal download buttons too. To sign in CI add these repository secrets; the workflow passes them to electron-builder when present:

| Platform | Secrets |
|---|---|
| Windows | `WIN_CSC_LINK` (base64 .pfx or https URL), `WIN_CSC_KEY_PASSWORD` |
| macOS | `MAC_CSC_LINK` (base64 Developer ID Application .p12), `MAC_CSC_KEY_PASSWORD`, and for notarisation `APPLE_ID`, `APPLE_APP_SPECIFIC_PASSWORD`, `APPLE_TEAM_ID` (then set `"notarize": true` under `build.mac`) |

## Permissions per OS

- **macOS**: *Screen Recording* (System Settings › Privacy & Security › Screen Recording) is needed for screenshots; the app
  detects a missing permission and links to the right pane. *Accessibility* (Privacy & Security › Accessibility) is needed for
  keyboard and mouse counts: the app asks once; until it is granted the activity comes from the system idle time (no counts)
  and the hook starts by itself within a minute of granting it. Launch at login uses Login Items.
- **Windows**: no special permission. Launch at login uses the Run registry key of the current user.
- **Linux (X11)**: works out of the box (the hook needs libX11, libXtst, libXt and libXrandr, listed as .deb dependencies).
  **Wayland**: the input hook is not used (it would only see X11 apps), activity comes from the system idle time, which may
  read as 0 on some Wayland sessions; screen capture goes through the desktop portal and may ask every time (or be
  unavailable on some compositors). Launch at login writes
  `~/.config/autostart/niyanta-tracker.desktop`. Without a keychain service (gnome-keyring / kwallet) the device token is
  stored unencrypted in the user's profile (file mode 600). The capture notice uses a plain window on Linux (no transparency).

## How it works

- **Sign-in**: `POST /api/agent/login` returns a personal device token, kept encrypted with the OS keychain (`safeStorage`).
  People see and disconnect devices on Time Tracking › Connected devices. Same lockout and rate limits as the web sign-in.
- **State machine**: the server is the source of truth; the app applies actions optimistically and sends them with their time
  (`at`). Retries never double-book (the server's actions are idempotent).
- **Offline**: actions, heartbeats (with idle spans), activity slots, screenshots and error reports go into a persisted queue
  (`userData/queue.json`, screenshots in `userData/pending/`) and are retried with exponential backoff. If the computer is
  offline for more than 10 minutes the server stops the entry at the last sync; the app tells the person and resumes when back.
  Queued heartbeats keep their time and are merged only while contiguous, so a gap is never counted.
- **Midnight**: the app checks the date (workspace time zone) every second and after waking up. Tracking at midnight: the
  server closes the running entry and the old day at 00:00 and continues on the same project/task in the new day (the app sends
  a heartbeat at once; no click needed). Asleep across midnight: the old entry ends at its last heartbeat and tracking resumes
  in the new day on waking. Paused at midnight: the old day ends; Start begins the new one.
- **Activity**: tracked time is measured in 10-second windows: a window is fully active when there was keyboard or mouse
  input in it, or less than 3 s before it began (a short grace period), otherwise it is inactive. Counting only the exact
  seconds with input read 75-80% for focused work (reading, thinking, pauses between typing); with windows the same work reads
  about 90%, like other trackers, while occasional input stays low (one input a minute about 20%, away 0%). `WINDOW_S` and
  `GRACE_S` are in `src/main/meter.js`; `npm run simulate` prints the result for typical input patterns. With the input hook
  (Windows, macOS with Accessibility, Linux X11) the app also counts keystrokes (key presses, auto-repeat ignored) and mouse
  events (clicks, wheel, moves at most 4 per second), never which keys or where; otherwise (fallback)
  `powerMonitor.getSystemIdleTime()` gives the last input every second (same windows) and the counts are unknown. If the hook stops reporting
  input while the system still sees input for 30 s, the app switches to the fallback. Seconds are summed per **slot** = the
  screenshot interval (`screenshot_interval_seconds`, e.g. 180 s), counted from the workspace's midnight: activity % =
  active seconds / tracked seconds of the slot (paused time excluded). Every slot is sent to `/api/agent/activity` (also
  without screenshots or consent), and the screenshot taken at the end of the slot carries its activity % and counts (the
  capture notice shows that %). The open slot is saved with the session and continued after a quick restart.
- **Idle**: after `idle_minutes` without input the person is idle from the last input (the chip shows *Idle*); the timer keeps
  running and the idle time is saved automatically (heartbeat `idle_seconds` plus the span in `idle_spans`). Back before the
  maximum: the chip goes back to *Tracking*, nothing is asked. Idle until `idle_auto_pause_minutes` (default 15, from the last
  input): tracking pauses at last input + 15 min, so those 15 minutes are kept as idle time and nothing after is tracked. When
  the person is back, the window shows "Tracking paused after 15 min idle. Resume when you are back." with **Resume** (and a
  system notification when the window is not focused). Nothing is detected while paused; an idle span still open when tracking
  pauses (e.g. screen locked) ends at the pause.
- **Power**: locking the screen or sleeping pauses tracking (if the workspace setting is on) and unlocking / waking resumes it.
  Quitting the app while tracking pauses first; a system shutdown or log-out is delayed (Linux/macOS) until that is done.
- **Crash or power cut**: heartbeats (at most every 60 s) keep the running entry's end on the server, and the app saves locally
  every 15 s whether it is tracking and when it was last alive (`config.json` > `session`). On the next start after an unclean
  exit while tracking: back within 10 minutes (crash, quick restart) the entry is closed at the last alive moment and tracking
  resumes automatically with a note; after longer, the server closes the entry at its last heartbeat (at most a minute is lost,
  the time the computer was off is never counted) and the app shows the normal not-tracking state with a note, so nobody is
  tracked by surprise hours later (Resume, or Start in a new day). The server's stale job does the same if the app never returns.
- **Settings (gear icon)**: *Pomodoro break* (off by default; *Notify after working* 120 minutes, *Break interval* 5
  minutes, Save): after that much tracked time without a break, pause or resume (idle time does not count; idle or sleep at
  least as long as the break resets it), a system notification and a banner say "Time for a 5-minute break" with **Take a
  break** (pauses and counts the break down, then "Break over" with **Resume**; tracking never resumes by itself) and
  **Snooze 10 min**. Pausing resets the counter. Stored only on this device (`config.json` > `pomodoro`), nothing is sent to
  the server; notification buttons are on macOS, elsewhere the notification opens the window with the buttons.
  *Preferences*: **Screenshot alert** (the capture notice; locked when the workspace does not let people turn it off),
  **Launch at startup** (on by default; Login Items / Run key / `~/.config/autostart`) and **Message alert tone** (on by
  default: a short chime with the app's own messages, reminders and notifications, never with the screenshot notice).
- **Screenshots**: only with monitoring enabled, the person's consent (the app shows the notice and records consent via the
  API before the first capture) and while tracking. One capture at the end of each activity slot, all displays, max 1920 px
  wide, JPEG quality 60, uploaded with that slot's activity percentage, keystrokes and mouse events. They are not shown in the app (only the small capture notice, if enabled); people see them on
  the web (My screenshots).
- **Errors**: uncaught exceptions, unhandled rejections, failed syncs and capture failures are written to
  `userData/logs/tracker.log` and sent to the workspace error log (Audit log › Error logs, source *desktop*, with the version).
- **Updates**: see *Updates (in the app)* above.

## Privacy

The app records: start/pause times, the chosen project/task, active and idle seconds, and per slot the number of keystrokes
and mouse events (counts only: never which keys or what is typed, no mouse positions, no URLs or window titles), and, only when the workspace enables monitoring and the person accepted
the notice, screenshots while tracking. People can see all of it in the web app (Time Tracking, Timesheets, My screenshots),
revoke screenshot consent at any time, and screenshots are deleted after the workspace's retention period.
