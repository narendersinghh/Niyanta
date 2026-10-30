# Updating Niyanta

Niyanta updates itself from **Settings → Updates** (`/admin/updates`, Super Admin only). No shell access, Composer or Git is required.

## One-time setup: connect GitHub

Niyanta and the Niyanta Tracker desktop app are released in the private repository `narendersinghh/Niyanta` (built in via
`repository` in `niyanta.json`). Each server needs read access once:

1. On GitHub: **Settings › Developer settings › Personal access tokens › Fine-grained tokens › Generate new token**.
   Resource owner: the owner of the repository; *Repository access*: only `narendersinghh/Niyanta`; *Permissions*:
   **Contents: Read-only** (Metadata read-only is added automatically). Pick an expiry and note the date.
2. In Niyanta: **Settings › Updates › GitHub access token**, paste it and save. It is stored encrypted and never shown again.
3. Press **Check for updates** (web app) and **Refresh now** under *Desktop app (Niyanta Tracker)* to confirm both work.

Renew the token before it expires; until then app update checks fail and the desktop app mirror keeps the version it has.

| Setting | Meaning |
|---|---|
| GitHub repository (optional override) | Empty uses the official `narendersinghh/Niyanta`. Set `owner/name` only to follow a fork. |
| Update channel | **Stable releases** (newest GitHub release tagged `vX.Y.Z`, compared with `NY_VERSION`) or **Latest commit** on a branch (compared with the last installed commit SHA). Desktop tracker releases (`tracker-v*`) are always ignored here. |
| Branch | Branch followed by the commit channel (default `main`). |
| GitHub access token | Required for the private repository (app updates and the desktop app). Fine-grained, read-only *Contents*. |

## Desktop app (Niyanta Tracker)

The scheduler job `attendance.tracker_mirror` runs every 30 minutes. It looks at the newest `tracker-vX.Y.Z` release (metadata
cached 30 minutes) and, when that version is not on the server yet, downloads its installers, `latest*.yml` and `.blockmap`
files once through the GitHub API into `storage/desktop/<version>/` (private, denied by `.htaccess`). Sizes are checked
against GitHub and installers against the sha512 in `latest*.yml`; the newest two versions are kept. **Settings › Updates ›
Refresh now** does the same immediately and shows the status. People download the app from **Time Tracking › Get the desktop
app** (signed in, with the *Track own time* permission) and installed apps update themselves from the server
(`/api/agent/updates/`, device token). Without a token the Time Tracking page says the desktop app is not available yet.

Offline or manual updates: **Upload package** accepts a release `.zip` (it must contain `niyanta.json` and `core/version.php`, either at the root or in a single top folder like GitHub zipballs).

## What happens when you click Install

1. **Download** the package to `storage/updates/<id>.zip`, following redirects, over HTTPS only.
2. **Integrity**: if the release publishes a SHA-256 digest, a `checksums.txt` or a `*.sha256` asset, the download must match it. Otherwise the size reported by GitHub is compared. Every package must also pass the structure checks: valid `niyanta.json`, `core/version.php` with the same version, no absolute paths, no `..` segments, no symlinks, and an unpacked size within the limit.
3. **Compatibility**: `min_php` from the package must be ≤ the server's PHP version. The version must not be lower than the installed one, unless you tick *Allow reinstall / downgrade*.
4. **Preflight**: the zip extension is available, the app folders are writable and there is enough free disk space.
5. **Backups**: a full backup (database, `storage/uploads`, `assets/uploads`, `config/config.php`) is listed under Backups. A code backup is saved to `storage/updates/code-backup-<id>.zip`; the three newest are kept.
6. **Maintenance mode** is turned on with `storage/maintenance.flag`. Super Admins can still use the app while it is on.
7. Files are extracted to `storage/updates/staging-<id>/`, then copied over the app one file at a time (temp file + rename). Preserved paths are never touched: `config/config.php`, `storage/`, `assets/uploads/`, `.htaccess.local` and `.git`, plus anything listed in `preserve` in `niyanta.json`.
8. **Migrations** run (`Migrator::migrate()`), permissions are synced, caches and OPcache are cleared.
9. Maintenance mode is turned off. The step log is stored in `update_logs` and shown at `/admin/updates/<id>`.

If anything fails **after files started copying**, the previous code is restored automatically from the code backup and files added by the update are removed. The update is then marked **Rolled back** and maintenance mode is switched off. Migrations that already ran are **not** reverted. Restore the pre-update backup if needed (see below).

## Recovery procedures

### The site shows "We'll be right back" to everyone
Maintenance mode was left on, for example because PHP was killed mid-update. Delete `storage/maintenance.flag` with FTP or your host's file manager.

### Roll back the code manually
1. Find the newest `storage/updates/code-backup-<id>.zip`. The id matches the update in the history.
2. Download it and unzip it over the application folder, replacing existing files. It never contains `config/config.php`, `storage/` or `assets/uploads/`, so your configuration and files are safe.
3. Delete any new files the failed update added, if you know them. They are listed as "new" in the update log.
4. Delete `storage/cache/*.php`. Restart PHP if OPcache is configured with `validate_timestamps=0`.

### Roll back the database
1. Open **Backups** and find the backup named "Before update to …".
2. Use **Restore** and type `RESTORE` to confirm. A safety backup of the current state is taken first.
3. Without web access: unzip the full backup and import `database.sql.gz` with phpMyAdmin (Import tab) or run `gunzip < database.sql.gz | mysql -u USER -p DBNAME`.

### Pending migrations after a manual upload
Open **Updates → Run pending migrations**, or run `php cron.php --migrate` over SSH.

### Restore everything onto a new server
1. Upload the application code for the same version, or newer.
2. Unzip the full backup, then copy `config/config.php`, `storage/uploads/` and `assets/uploads/` into place.
3. Create the database and import `database.sql.gz`.
4. Create `storage/installed.lock` if it is missing. Keep the original `config/config.php`: its `app_key` decrypts the stored secrets, such as SMTP, AI and GitHub tokens.

## Publishing a release (maintainers)

Web app:

1. Bump `version` in `niyanta.json` and `NY_VERSION` in `core/version.php`. They must match.
2. Add new schema changes as **new** migration files. Never edit a shipped migration.
3. Commit, then `git tag vX.Y.Z && git push origin main vX.Y.Z`.
4. `.github/workflows/app-release.yml` checks the versions against the tag, builds `niyanta-X.Y.Z.zip` (tracked files without
   `desktop/`, `.github/`, `tests/`, `config/config.php` and storage contents) plus `checksums.txt`, and attaches them to the
   release "Niyanta vX.Y.Z". Edit the release on GitHub to add the changelog (it is shown in Settings › Updates).

Desktop tracker: bump `desktop/package.json`, commit, `git tag tracker-vX.Y.Z && git push origin tracker-vX.Y.Z`
(see desktop/README.md). Tracker tags never trigger the web app workflow and are never installed as app updates.
