# Security

What Niyanta does to protect a workspace, what the host must still provide, and how to report a problem.

## Reporting a vulnerability

Email the maintainers (see the repository page) with the steps to reproduce. Please do not open a public issue for a security problem. You will get an acknowledgement, a fix in a release, and credit if you want it.

## What is in place

### Transport and headers (core/Response.php, .htaccess)
- Every response carries a strict Content-Security-Policy (`default-src 'self'`, no inline or eval scripts except the single nonce'd theme snippet in the page head, `object-src 'none'`, `frame-src 'none'`, `frame-ancestors 'self'`, `form-action 'self'`, `base-uri 'self'`), `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy: strict-origin-when-cross-origin`, `Cross-Origin-Opener-Policy` and `Cross-Origin-Resource-Policy: same-origin`, a restrictive `Permissions-Policy`, and `Cache-Control: no-store` so no dynamic page is kept by a browser or shared cache.
- Over HTTPS the app adds `Strict-Transport-Security: max-age=31536000` and `upgrade-insecure-requests`. HSTS is sent without `includeSubDomains` so one install never affects other sites on the same domain; add `preload` or `includeSubDomains` at the host level if you want them.
- `.htaccess` disables directory listings and TRACE, denies `config/`, `core/`, `modules/`, `storage/`, `views/`, `tests/`, `docs/`, `desktop/`, every dotfile (except `.well-known`), `cron.php`, manifests, lock files, logs, backups, SQL dumps and scripts, and refuses to serve any PHP file other than `index.php`. `storage/` and `assets/uploads/` carry their own deny rules and script-execution blocks. `.user.ini` turns off `expose_php` and `display_errors` on CGI/FastCGI hosts.

### Sessions and sign-in (core/Session.php, core/Auth.php)
- Session cookie: session-only, `HttpOnly`, `SameSite=Lax`, `Secure` whenever the request came over HTTPS, strict mode (no fixation), 48-character ids, files in `storage/sessions`.
- The session id is regenerated at sign-in; the CSRF token is rotated with it. Sessions end after `security.session_idle_minutes` of inactivity (default 120) and `security.session_absolute_hours` after sign-in (default 12), and are bound to the browser's user agent.
- Passwords are hashed with Argon2id (memory 64 MB, 3 passes) where PHP has it, otherwise bcrypt at cost 12; older hashes are upgraded on the next successful sign-in. The policy requires `security.password_min_length` characters (default 10), letters and digits, and refuses a short list of very common passwords.
- Wrong passwords are rate limited per email (`security.login_max_attempts` per 15 minutes) and per network (100 per 15 minutes); after 10 wrong passwords in a row the account locks for 30 minutes. Successful sign-ins never count. The sign-in, forgot-password and reset routes are additionally throttled per IP. Administrators can unlock a person or sign everyone out (Settings, Users).
- Password changes, role changes, deactivation and admin "sign out everywhere" bump a per-user session version, which invalidates every other session at once.
- Reset links carry a random token that is stored only as a SHA-256 hash, expires after 60 minutes (invitations after 7 days) and works once.

### CSRF (core/Csrf.php, core/Router.php)
- Every POST route is protected by a per-session token (form field `_token` or header `X-CSRF-Token`) unless it is explicitly an unauthenticated API route. When the browser sends an `Origin` header it must match the site's host as well.
- Routes without CSRF are limited to token-authenticated desktop API calls, the cron tick, the web cron endpoint (secret token in the URL, compared in constant time) and the browser error beacon, all rate limited.

### Authorisation
- Every route declares a permission (or Super Admin). Record-level checks sit in each module (`Team::includes`, `ClientAccess`, `ProjectAccess`, `HrAccess`, `MeetingAccess`, `ProcessService::can*`, payslip ownership, time entry ownership, AI conversation ownership) and denied attempts are written to the audit log.
- Private files are served only through `/files/{id}` after the owning module's `file_access` callback approves; screenshots and payslips have their own owner-or-team checks. Avatars are visible to signed-in colleagues only.
- Input is whitelisted by `Validator` rules on every form; dynamic `ORDER BY` columns go through fixed `match` tables; all SQL is parameterised and identifiers pass `DB::id()`.

### Uploads (core/Storage.php)
- Files are checked by extension against a per-feature allow list, by the content's MIME type (fileinfo), by `getimagesize` for images, and against a deny list that blocks scripts, HTML, SVG and polyglots whatever the caller allowed. Text files that contain PHP or HTML are refused.
- Stored under random 32-character names outside the web root (`storage/uploads`) and streamed back with `Content-Disposition`, `nosniff` and `private` cache rules. Inline images (screenshots, avatars) use ETag revalidation so a revisit costs no bandwidth but still passes authorisation. Avatars are re-encoded with GD (when available) which strips any embedded payload and caps them at 512 px.

### Desktop tracker API (modules/Attendance)
- Personal device tokens (`nyt_…`) are random 48-hex strings stored as SHA-256 hashes, at most 5 per person, revocable from Time Tracking, expiring after `attendance.token_idle_days` without use (default 90; the app then asks for a fresh sign-in). Each call re-checks that the user is active and still holds `attendance.track`.
- One-line install links are signed with a purpose-bound HMAC of the app key, valid for 2 hours and tied to the person's session version.

### Secrets and logs
- API keys, SMTP and IMAP passwords, the GitHub token, Fireflies keys and employee bank and tax numbers are encrypted at rest with libsodium secretbox keyed by `app_key` in `config/config.php` (never in the database).
- Error and audit logs redact reset tokens, install keys, bearer tokens and secret-looking query parameters. Error pages show a reference id only unless `debug` is on in `config/config.php`; keep it off in production.

### Outbound requests
- Updates and tracker releases are fetched over HTTPS only (`CURLOPT_PROTOCOLS` https, TLS verified) and update packages are checked against the published sha256 before they are extracted by streaming (no `extractTo`, no symlinks, no path escapes).
- AI providers must be `https://`; `http://` is accepted only for loopback and private addresses so a self-hosted model works, and only a Super Admin can set that address. `Modules\Ai\Services\Http::allowedUrl($url, false)` is the strict variant (no private or loopback host) for any URL a non-admin could supply.
- SMTP and IMAP connections verify certificates; IMAP host names are validated.

### Background work
- Retention and cleanup jobs delete in batches of 2000 rows with a time budget per run, so a large backlog never holds a lock or a shared host's execution limit; the rest runs the next night.

## What the host must configure

- Serve the app over HTTPS (Let's Encrypt is fine). HSTS and the `Secure` cookie flag switch on automatically once requests arrive with `HTTPS=on` or `X-Forwarded-Proto: https`.
- Point the document root at the app folder and keep `AllowOverride All` (or at least `FileInfo Options Indexes AuthConfig`) so `.htaccess` applies. On nginx or hosts without `.htaccess`, deny the folders listed above yourself and route everything to `index.php`.
- Keep `config/config.php` and `storage/` writable by PHP only, not by other accounts on the server.
- PHP 8.2+ with `sodium`, `fileinfo`, `openssl`, `mbstring`, `zlib`; `curl` for updates and AI; `gd` for avatar re-encoding (optional); Argon2 support for the strongest password hashes (optional, bcrypt is used otherwise).
- Run `php cron.php` every minute (or use the automatic scheduler, which runs after responses) so queued mail, retention and backups keep working. Keep the web cron token secret if you enable it.
- Back up `config/config.php` together with the database; without `app_key` the encrypted settings cannot be read.

## Not implemented (by design or deferred)

- Two-factor authentication is not built in yet. Protect administrator accounts with long passwords and keep `security.login_max_attempts` low; put the site behind your SSO or VPN if you need a second factor today.
- People cannot change their own email address; an administrator does it from Users, so no self-service change confirmation flow exists.
- `X-Forwarded-Proto` is trusted from any client; this only affects whether the `Secure` flag and HSTS are emitted, never authorisation.
