# Updating Niyanta

Niyanta updates itself from **Settings → Updates** (`/admin/updates`, Super Admin only). No shell access, Composer or Git is required.

## Configure the source

| Setting | Meaning |
|---|---|
| GitHub repository | `owner/name` of the repository that publishes Niyanta. |
| Update channel | **Stable releases** (latest GitHub release, compared with `NY_VERSION`) or **Latest commit** on a branch (compared with the last installed commit SHA). |
| Branch | Branch followed by the commit channel (default `main`). |
| GitHub access token | Only for private repositories. A fine-grained token with read-only *Contents* access. Stored encrypted and never shown again. |

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

1. Bump `version` in `niyanta.json` and `NY_VERSION` in `core/version.php`. They must match.
2. Add new schema changes as **new** migration files. Never edit a shipped migration.
3. Create a GitHub release tagged `vX.Y.Z` and put the changelog in its body.
4. Optional, recommended: attach a built `niyanta-X.Y.Z.zip` and a `checksums.txt` containing `<sha256>  niyanta-X.Y.Z.zip`.
