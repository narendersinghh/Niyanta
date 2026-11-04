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
- **Desktop app**: your Niyanta server downloads the new installers automatically within 30 minutes (or immediately with *Settings › Updates › Desktop app › Refresh now*). Employees see the new version on **Time Tracking** and installed apps update themselves.
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
