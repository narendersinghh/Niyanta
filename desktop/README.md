# Niyanta Tracker (desktop app)

The time tracker for Niyanta workspaces, for **Windows, macOS and Linux**. Since the web app no longer starts or stops time,
this app is how people track their day: Start (clock in), Pause, Resume and switch project without stopping. There is no
"end day": the day ends by itself at midnight (workspace time zone) and, if the person was tracking, continues in the new day on
the same project/task. Activity (active / idle) comes from keyboard and mouse input, and if the workspace turns it on, screenshots of
all screens are taken at the configured interval, with a small notice each time. Everything syncs with the server; the API
contract is in [`modules/Attendance/AGENT_API.md`](../modules/Attendance/AGENT_API.md).

Electron, plain JavaScript, no native modules. Activity comes from the system idle time (`powerMonitor.getSystemIdleTime()`,
read every second, the same on Windows, macOS and Linux): only whether there was keyboard or mouse input, nothing is counted
(no keystrokes, no mouse events) and there is no global input hook (versions 1.1.0 to 1.2.1 had one, `uiohook-napi`; it was
removed: Windows Defender treated it like a keylogger, and macOS needed the Accessibility permission for it).

```
src/main/      main.js (lifecycle, tray, window, tracking dot on the app icon, IPC, auto-launch, notifications, sync before
               quitting), tracker.js (state machine, activity slots, meeting mode, idle, heartbeats, screenshots, power
               events), activity.js (system idle time every second), meter.js (12-second activity blocks, grace,
               bridging, locked screen, wake-up, meeting slots), badge.js (tracking dot bitmaps), pomodoro.js (break reminder), api.js (HTTP client),
               queue.js (offline queue), capture.js (screenshots),
               toast.js (capture notice), store.js (prefs + encrypted token), errors.js (error reporting),
               updater.js (update check, Update now, manual download), selfupdate.js (pure helpers: feed parsing,
               macOS helper script), guard.js (pure: IPC argument validation, link allowlist, rate limiter, safe file
               names, screenshot encryption)
src/preload/   contextIsolation bridge (window.tracker) and the notice window bridge
src/shared/    status.js (what the big counter shows; used by the window and the checks)
src/renderer/  index.html / app.js / styles.css (384 x 700 window, brand colours only), toast.html (capture notice)
build/         icon.png (1024, converted by electron-builder), icons/ (Linux), tray/ (tray states), mac entitlements
scripts/       icons.js (regenerate icons from the logo), check.js (syntax check + simulation + update checks + status-check.js:
               counter, meeting slots, sync before quitting, tracking dot, Windows activity and build), simulate.js
               (activity and pomodoro simulation on fake input timelines), updates-check.js (selfupdate.js checks, runs the
               generated macOS helper on fake bundles), security-check.js (security regression checks, see Security),
               after-pack.js (afterPack: Electron fuses, then adhoc-sign.js: ad-hoc signature without a certificate)
```

## Develop

```bash
cd desktop
npm install
npm start                      # opens the app; sign in with your workspace URL, email and password
npm run check                  # node --check on every JS file, the simulation below and the update helper checks
npm run simulate               # activity % for typical input patterns (old vs new rule) and the pomodoro checks
NIYANTA_TRACKER_PROFILE=/tmp/tracker-2 npm start   # a second, separate profile (another user)
```

The activity source in use (and why the fallback is used) is written to `userData/logs/tracker.log`
(`activity source: keyboard and mouse input from the system idle time ...`).

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

The app checks on start, every 30 minutes and 15 s after waking from sleep or unlocking: `GET /api/agent/version` (device
token). When the workspace has a newer version, the window pops up (without taking the focus) once per version with a prompt
**Niyanta Tracker 1.2.0 is available** (Later / **Update now**), and a prominent card stays at the top of the window (with up
to 4 lines of *what's new* when the `latest*.yml` has `releaseNotes`); the tray menu has the same item, and one OS
notification is shown per version. Nothing restarts without that click (or a normal quit, which installs a pre-downloaded update on
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
# Windows PowerShell (Invoke-WebRequest to Downloads, sha512 check, opens the setup normally; the person clicks Install)
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

### Windows Defender / antivirus

Versions 1.1.0 to 1.2.1 contained a global keyboard hook; unsigned, that made Defender's machine-learning detection report
the installer ("contains a virus or potentially unwanted software"). Since then no build has an input hook or any native
module; the Windows build asks for no elevation (`requestedExecutionLevel: asInvoker`, per-user install), carries version
information (company, copyright, description) and the one-line PowerShell command behaves like a person: it saves the setup
to Downloads and opens it normally (no silent `/S`, no hidden window, no TLS or execution-policy tweaks). If a build is still
reported, submit it as a false positive (docs/RELEASING.md); code signing is the lasting fix.

## Permissions per OS

- **macOS**: *Screen Recording* (System Settings › Privacy & Security › Screen Recording) is needed for screenshots; the app
  detects a missing permission and links to the right pane. Nothing else is asked (no Accessibility permission: activity
  comes from the system idle time). Launch at login uses Login Items.
- **Windows**: no special permission. Launch at login uses the Run registry key of the current user.
- **Linux**: works out of the box on X11. **Wayland**: the system idle time may read as 0 on some Wayland sessions; screen capture goes through the desktop portal and may ask every time (or be
  unavailable on some compositors). Launch at login writes
  `~/.config/autostart/niyanta-tracker.desktop`. Without a keychain service (gnome-keyring / kwallet) the device token is
  stored unencrypted in the user's profile (file mode 600). The capture notice uses a plain window on Linux (no transparency).

## The window

384 x 700, resizable between 360 x 600 and 460 x 900, never maximized or full screen. From the top: header (workspace, person,
settings), the update card when there is one, notices, the **status card** (state chip, a big counter with the time of the
current status: the session since the last Start, Resume or project switch while tracking, how long it has been paused when
paused; below it the day total in medium size and the project / task), project and task pickers, the main button (Start,
Pause or Resume), **Meeting mode**, today's summary (Total, Active, Activity %, Idle) and the sync line. Light and dark follow
the OS; only the Niyanta brand colours are used. Everything is reachable with the keyboard (dialogs keep the focus inside,
Escape closes them).

- **Meeting mode**: a switch under the main button. Time is tracked as usual (screenshots as configured; switching it on
  while not tracking starts or resumes tracking), idle detection and the automatic pause are off, and the slots it covers
  are sent with `meeting: 1`: kept and visible to managers, never counted in the activity average. It shows a countdown and
  turns off by itself after 1 hour, on Pause, or when switched off.
- **Tracking dot**: while tracking (not paused, idle or stopped) a small mint dot sits on the taskbar / dock icon and pulses
  very slowly (two opacity frames every 4 s): Windows taskbar overlay, macOS dock icon, Linux window icon (most taskbars
  other than GNOME show it; the tray icon shows the state everywhere).
- **Quitting with unsynced data**: Quit pauses tracking and then sends what is still in the offline queue. If that takes more
  than a moment the window shows "Syncing your data to the server. The app will close by itself when done." with progress
  and **Quit anyway**; after at most 60 s it quits, and anything left stays queued on disk for the next start. (Closing the
  window only hides it to the tray; tracking and syncing continue.)

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
- **Activity**: every second the app reads the system idle time (`powerMonitor.getSystemIdleTime()`: `GetLastInputInfo` on
  Windows, the IOHIDSystem idle time on macOS, X11 / logind on Linux), i.e. whether there was keyboard or mouse input; nothing
  is counted and nothing about what is typed is seen. Tracked time is measured in 12-second blocks (`src/main/meter.js`, all
  constants named there): a block is active when there was input in it or less than `GRACE_S` (1 s) before it began; a single
  inactive block between two busy blocks (input in `BUSY_S` = 4 different seconds) counts as active, so a short thinking pause
  in steady work is not lost while sparse input stays low; a locked screen or a sleeping computer is inactive at once (input
  then does not count, no grace or bridge across it) and the first block after waking is left out of the slot. Results
  (`npm run simulate`): steady typing 100%, focused work with short pauses about 95%, mixed work about 91%, reading with a
  scroll every 15-25 s about 68%, one input every 30 s about 43%, one input a minute about 21%, away 0%. Seconds are summed
  per **slot** = the screenshot interval (`screenshot_interval_seconds`, e.g. 180 s), counted from the workspace's midnight:
  activity % = active seconds / tracked seconds of the slot (paused time excluded). Every slot is sent to
  `/api/agent/activity` (also without screenshots or consent), and the screenshot taken at the end of the slot carries its
  activity % (the capture notice shows it). The open slot is saved with the session and continued after a quick restart
  (its next block is left out, like after waking).
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
  wide, JPEG quality 60, uploaded with that slot's activity percentage (and the meeting flag). They are not shown in the app (only the small capture notice, if enabled); people see them on
  the web (My screenshots).
- **Errors**: uncaught exceptions, unhandled rejections, failed syncs and capture failures are written to
  `userData/logs/tracker.log` and sent to the workspace error log (Audit log › Error logs, source *desktop*, with the version).
- **Updates**: see *Updates (in the app)* above.

## Security

What the app does to keep the device token, the screenshots and the computer safe; `npm run check` runs
`scripts/security-check.js`, which fails when any of this regresses (window flags, CSP, IPC validation, HTTPS and redirect
policy, storage, fuses, package contents).

- **Process and windows**: `app.enableSandbox()`; every window has `contextIsolation`, `sandbox`, `nodeIntegration: false`,
  `webviewTag: false`, `webSecurity`, no plugins or experimental features, devtools only in development. The pages are the
  two bundled files (`file://` inside `src/renderer`): navigation anywhere else, redirects, `window.open`, `<webview>` and page
  downloads are refused for every web contents; every permission request (camera, notifications, clipboard read, devices...)
  is denied (screenshots are taken by the main process with `desktopCapturer`). Certificate errors are never accepted. No
  custom protocol is registered. `ELECTRON_RUN_AS_NODE` and `NODE_OPTIONS` are removed from the environment at start.
- **Fuses** (`scripts/after-pack.js`, `@electron/fuses`): in every installer `runAsNode` is off (the binary cannot be used as
  a Node runtime), `NODE_OPTIONS` and `--inspect` are ignored, cookie encryption is on, the app loads only from `app.asar`,
  and on macOS and Windows the asar is validated against the hash electron-builder embeds (a modified `app.asar` does not
  start). The fuses are flipped before the ad-hoc signature, so the macOS signature stays valid.
- **Pages**: strict Content-Security-Policy (`default-src 'none'`, scripts and styles only from the app, no inline scripts,
  `connect-src 'none'`), no inline event handlers, every server string is HTML-escaped (`esc`) before it is rendered.
- **IPC**: the preload exposes a fixed set of typed functions (`window.tracker`), never `ipcRenderer`. Each handler checks that
  the call comes from the main frame of the app's own window, validates every argument (types, allowlists, id ranges, string
  lengths: `src/main/guard.js`) and is rate limited per channel (renderer error reports 10/min, sign-in 10/min). Links leave
  the app through one function: only http(s) URLs on the signed-in workspace (or niyanta.app) reach `shell.openExternal`;
  the macOS Screen Recording pane is a constant.
- **Network**: workspace addresses must be `https://` (http only for localhost development); other schemes and addresses
  with credentials are refused. Requests use Electron's network stack (`net.request`, system proxy and certificate store)
  with manual redirect handling: a redirect is followed only within the workspace's origin, so the device token never
  travels to another site; responses are capped (4 MB JSON, 1 MB update feed, 600 MB installer) and every request has a
  timeout (downloads also a 60 s stall limit). Retries use exponential backoff with jitter (3 s doubling to 5 minutes) and
  the periodic state refresh backs off the same way while offline. `electron-updater` 6.8.11 or newer (fixes the
  cross-origin credential leak of older versions), no downgrades or prereleases; everything it and the macOS updater
  download is verified against the sha512 in `latest*.yml` before it is installed; manual installers are verified when the
  server offers a checksum (`/api/agent/version` `files[].sha512`, else the feed). File names from the server are checked
  (`guard.safeFileName`) before they are joined to a folder.
- **Data at rest** (`userData`, file mode 600, folders 700): the device token and the screenshot key are encrypted with the
  OS keychain (`safeStorage`); screenshots waiting in the offline queue are AES-256-GCM encrypted files (`pending/*.enc`,
  a random key per installation), deleted right after the upload, bounded to 400 files / 120 MB / 24 hours (oldest first),
  and files no queued job refers to are deleted at start. Nothing is captured while the screen is locked or the computer
  asleep. Sign-out deletes the token, the key, the queue, the cached bootstrap and the saved session. Logs rotate at 2 MB
  (two backups) and bearer tokens / password fields are scrubbed from every line before it is written or sent.
- **Quitting**: the sync before quitting is bounded (at most 60 s, Quit anyway at any time) and a hard ceiling of 75 s
  guarantees the app exits even if a request hangs. A crashed page is reloaded (at most 3 times in 5 minutes).
- **Package**: one runtime dependency (`electron-updater`, `npm audit --omit=dev` is clean); the installer contains `src/`
  and `package.json` only (no scripts, tests or docs), nothing is unpacked from the asar, Windows asks for no elevation.
  What `npm audit` still lists is in the build toolchain only (`electron-builder` 25 › `node-gyp` › `tar`), which never runs
  on an installed app and never extracts untrusted archives here (no native modules).
- **Verify on a real machine**: `npm run dist`, then `NIYANTA_CHECK_DIST=1 npm run check` reads the fuse wire of
  `dist/*-unpacked`; on macOS `codesign -dv --verbose=2 "dist/mac-arm64/Niyanta Tracker.app"` must show a valid signature
  after the fuses; `ELECTRON_RUN_AS_NODE=1 <binary> -e "console.log(1)"` must start the app, not print 1; the `pending/`
  folder must hold only `.enc` files while offline.

## Privacy

The app records: start/pause times, the chosen project/task, active and idle seconds, and per slot how much of it had keyboard or mouse input (whether there was input,
never what is typed, no counts, no mouse positions, no URLs or window titles), and, only when the workspace enables monitoring and the person accepted
the notice, screenshots while tracking. People can see all of it in the web app (Time Tracking, Timesheets, My screenshots),
revoke screenshot consent at any time, and screenshots are deleted after the workspace's retention period.
