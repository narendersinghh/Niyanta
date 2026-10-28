# Deploying Niyanta on shared hosting

Niyanta needs only PHP + MySQL. No Composer, Node.js, Redis, Docker or background workers.

## Requirements
| Item | Minimum |
|---|---|
| PHP | 8.2+ with `pdo_mysql`, `mbstring`, `openssl`, `sodium`, `fileinfo`, `json`, `zlib`, `iconv` |
| Recommended PHP extensions | `curl` (AI providers, GitHub updates), `zip` (backups & updates), `gd` |
| Database | MySQL 5.7+ / 8.x or MariaDB 10.3+ (InnoDB, utf8mb4) |
| Web server | Apache with `mod_rewrite` (LiteSpeed works the same). Nginx: see below |
| Cron | One cron entry (every 1–5 minutes) or the built-in web-cron URL |

## 1. Upload
1. Download the release `.zip` (or clone the repository).
2. Upload the **contents** to your domain/sub-domain folder, e.g. `public_html/app/`. Keep the folder structure intact — `config/`, `core/`, `modules/`, `storage/`, `views/` are protected by `.htaccess` files.
3. Make these folders writable by PHP (usually `755`, or `775` on some hosts): `config/`, `storage/` (and all sub-folders), `assets/uploads/`.

> Best practice: if your host lets you choose the document root, the app can live outside `public_html`; with the default layout every private folder is already denied by `.htaccess`.

## 2. Create the database
In cPanel → **MySQL® Databases**: create a database, a user with a strong password, and add the user to the database with **ALL PRIVILEGES**.

## 3. Run the web installer
Open `https://your-domain/app/` — you are redirected to `/install`:
1. **Requirements** are checked (PHP version, extensions, writable folders).
2. **Database** credentials are tested. The database must be empty.
3. **Workspace & Super Admin**: app name, URL, timezone, currency, and your Super Admin account.

The installer creates all tables, seeds roles/permissions/settings, generates a unique encryption key in `config/config.php`, and writes `storage/installed.lock`. After that the installer is permanently disabled. **Back up `config/config.php`** — the `app_key` inside it decrypts stored secrets (SMTP password, AI keys, GitHub token, bank details).

## 4. Configure cron
Settings → Cron shows the exact command. Add **one** entry in cPanel → **Cron Jobs**:
```
* * * * * /usr/local/bin/php /home/USER/public_html/app/cron.php >/dev/null 2>&1
```
(Every 5 minutes is fine too.) The scheduler runs due jobs only: mail queue, reminders, overdue invoices, leave roll-over, stale timers, screenshot clean-up, backups, log clean-up. A file lock prevents overlapping runs.

**No cron on your plan?** Enable *web cron* in Settings → Cron and ping the secret URL `https://your-domain/app/cron/run/<token>` every few minutes from any external uptime/cron service. The token is compared in constant time and can be regenerated.

## 5. Email
Settings → Email: choose PHP `mail()` or SMTP (host, port, TLS/SSL, username, password). Use **Send test email**. Emails are queued and sent by cron.

## 6. Hardening checklist
- Serve over **HTTPS** (session cookies become `Secure` automatically; HSTS is sent).
- Keep `debug => false` in `config/config.php`.
- Verify `https://your-domain/app/config/config.php` and `/storage/` return **403**.
- Set file permissions: `config/config.php` → `640`.
- Configure backups (Settings → Backups) and download a copy off-site regularly.

## Nginx
```
location / { try_files $uri $uri/ /index.php?$query_string; }
location ~ ^/(config|core|modules|storage|views|tests|docs|tools)/ { deny all; }
location ~ /\. { deny all; }
location ~ ^/assets/uploads/.*\.(php|phtml|svg|html?)$ { deny all; }
location = /cron.php { deny all; }
location ~ \.php$ { include fastcgi_params; fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name; fastcgi_pass unix:/run/php/php8.2-fpm.sock; }
```

## Updating
See [UPDATES.md](UPDATES.md). In short: Settings → Updates checks GitHub (release or latest commit), takes a backup, stages and verifies the package, copies files while preserving `config/`, `storage/` and `assets/uploads/`, runs migrations, and rolls back code automatically on failure. Manual alternative: upload new files over the old ones (never overwrite `config/` or `storage/`), then run `php cron.php --migrate` or click **Run pending migrations**.

## Local development
```
php -S 127.0.0.1:8080 tests/dev-router.php
cp tests/config.example.php tests/config.php   # disposable test database
php tests/run.php
```
