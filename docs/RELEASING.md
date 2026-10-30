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
