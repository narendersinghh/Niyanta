# Releasing Niyanta (no command line needed)

Everything happens in GitHub with one button.

1. Open your repository on GitHub and click the **Actions** tab.
2. In the left list click **Release**, then the **Run workflow** button on the right.
3. Choose:
   - **What to release**: `both`, `web app` (the Niyanta website) or `desktop app` (Niyanta Tracker).
   - **Size of the change**: `patch` for fixes (1.0.0 → 1.0.1), `minor` for new features (1.0.0 → 1.1.0), `major` for big changes (1.0.0 → 2.0.0). `current` releases the version already in the code (used for the very first release).
4. Click **Run workflow**. In about 15–25 minutes the release is ready (green tick).

What it does for you: updates the version numbers, saves that change to `main`, creates the release tags, builds the website update package and the Windows, macOS and Linux installers, and attaches them to a private GitHub release.

## After a release
- **Desktop app**: your Niyanta server downloads the new installers automatically within 30 minutes (or immediately with *Settings › Updates › Desktop app › Refresh now*). Employees see the new version on **Time Tracking**, and installed apps show *Niyanta Tracker X is available* with an **Update now** button (see below).
- **Release notes in the app (optional)**: the app shows up to 4 lines of *what's new* when the update files contain `releaseNotes` (electron-builder adds them from `build.releaseInfo.releaseNotes` or a `release-notes.md` in `desktop/build/`). Without them the card just shows the version.
- **Website**: go to *Settings › Updates*, click **Check for updates**, then **Install**. A backup is taken first and the update rolls back automatically if anything fails.

## One-time setup on each Niyanta server
The repository is private, so the server needs a read-only key:
1. GitHub → your profile picture → **Settings** → **Developer settings** → **Personal access tokens** → **Fine-grained tokens** → **Generate new token**.
2. Name it "Niyanta server", choose an expiry, **Repository access: Only select repositories → Niyanta**, **Permissions → Repository permissions → Contents: Read-only**. Generate and copy it.
3. In Niyanta: *Settings › Updates*, paste it into **GitHub token**, save.

## Code signing (removes the "damaged" and "unknown publisher" warnings)

Without certificates the desktop app is built unsigned on Windows and ad-hoc signed on macOS. People can still install it (see the steps on Time & Attendance › Tracker), but browsers and the operating system warn first. To remove the warnings, add these repository secrets (GitHub › Settings › Secrets and variables › Actions) and release again; nothing else needs to change:

| Platform | What you need | Secrets |
|---|---|---|
| macOS | Apple Developer Program membership (99 USD/year), a "Developer ID Application" certificate exported as .p12, and an app-specific password for your Apple ID | `MAC_CSC_LINK` (the .p12 as base64), `MAC_CSC_KEY_PASSWORD`, `APPLE_ID`, `APPLE_APP_SPECIFIC_PASSWORD`, `APPLE_TEAM_ID` |
| Windows | A code signing certificate (OV or EV) exported as .pfx | `WIN_CSC_LINK` (the .pfx as base64), `WIN_CSC_KEY_PASSWORD` |

With the macOS secrets the build is signed with your Developer ID and notarized by Apple, so it opens without any prompt and updates itself. A new Windows certificate removes "unknown publisher" at once; SmartScreen stops warning after the certificate builds up reputation (immediately with EV).

## Windows Defender says the app "contains a virus or potentially unwanted software"

This is a false positive of Microsoft's machine-learning detection on unsigned apps. Niyanta Tracker 1.1.0 to 1.2.1 included a global keyboard and mouse hook, which such detections treat like a keylogger. Since then no build contains an input hook or counts keystrokes (activity is measured on every platform from the time of the last keyboard or mouse input that the system reports), the Windows build asks for no administrator rights, installs per user and the PowerShell command opens the setup normally. If a release is still reported:

1. Download the reported file from the GitHub release (or from *Settings › Updates › Desktop app*).
2. Open https://www.microsoft.com/wdsi/filesubmission, choose **Software developer**, sign in with a Microsoft account, choose **Microsoft Defender Antivirus**, upload the `.exe` (or a zip of it) and select **Incorrectly detected as malware/malicious**. Mention the detection name Windows Security showed (Protection history) and that it is your company's time tracker.
3. Microsoft usually answers within a few days; once cleared, new definitions stop flagging that file. People who need it sooner can restore it from Windows Security › Protection history (Allow on device), with their IT team's approval.

Every new release is a new file, so submissions only fix one version at a time. **Code signing is the lasting fix**: a Windows code signing certificate (see the table above; EV gets SmartScreen reputation at once) gives the installer a known publisher, which ML detections and SmartScreen trust far more than unsigned files.

## How desktop updates and installs work (no browser or OS warnings)

**Updates inside the app.** The app checks your Niyanta server on start, every 30 minutes and after the computer wakes up or is unlocked. When a newer version is there the window pops up once with the update (and one notification), and a card stays at the top of the app; the person clicks **Update now**. The app downloads the update from your server (with its own sign-in, never from GitHub), checks it, pauses tracking, installs, restarts and resumes tracking by itself. Because the app downloads the file itself, Windows SmartScreen and macOS Gatekeeper do not warn:
- Windows and Linux AppImage: the standard updater installs silently and restarts.
- macOS (built without a Developer ID): the app downloads the zip for that Mac, checks its sha512, replaces the app in Applications (or in the person's own Applications folder if the main one is not writable), clears the quarantine flag and reopens it. With a Developer ID (secrets below) the standard macOS updater is used instead, automatically.
- Linux .deb: the new package is downloaded and opened in the software installer (the app also shows the one-line `apt` command).
- If anything goes wrong the card offers **Download the new version**: the installer is saved to Downloads by the app and opened, with 2-3 short steps.

Note: apps installed before this version (1.1.0 and older) still show the old *Download* button once; after updating to a version with this feature, every later update is one click.

**First install with one command.** On *Time & Attendance › Tracker › Get the desktop app* each person sees a personal command for their system (theirs first) besides the normal download buttons:
- macOS and Linux, in Terminal: `curl -fsSL "https://<your server>/time/desktop/install.sh?k=<key>" | bash`
- Windows, in PowerShell: `irm "https://<your server>/time/desktop/install.ps1?k=<key>" | iex`

The command downloads the app straight from your server, checks it and installs it (macOS: into Applications, quarantine removed, app opened; Windows: the setup is saved to Downloads and opened, the person clicks Install and the app starts; Linux: AppImage in `~/.local/bin` with a menu entry). Chrome, SmartScreen and Gatekeeper do not get involved because no browser downloads the file. The `k` key is created for the signed-in person, is signed by your server, works for 2 hours, only for these install downloads, and stops working when the person signs out everywhere or changes their password. The GitHub token never leaves the server. Requests are rate limited and every install is recorded in the audit log (`tracker.install_script`, `tracker.download`). The commands are only shown when the server runs on HTTPS.

**Why signing still helps.** The commands and in-app updates avoid the warnings, but the normal download buttons on the page still get them, and IT departments may block unsigned apps. Signing (secrets above) makes the normal downloads open without prompts, shows your company as the publisher, and on macOS lets the built-in updater be used.

