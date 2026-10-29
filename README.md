# Niyanta

**Niyanta** is a lightweight, secure, modular business-management web app for agencies and service businesses: clients, projects, people, time, payroll and AI in one place. It is built to run on **ordinary PHP shared hosting**: no Composer, Node.js, Redis, Docker or background workers.

- **Stack:** PHP 8.2+, MySQL 5.7+/MariaDB 10.3+, server-rendered HTML, one CSS file and a small vanilla JS file
- **Architecture:** modular monolith (`core/` framework plus self-contained `modules/`)
- **Design:** dark sidebar, pastel multi-colour cards, light/dark/system themes, cartoon illustrations

## Product structure
Niyanta is organised into **three bundles**. A Super Admin can switch a whole bundle, or any feature inside it, on or off in **System → Features**. Turning something off hides and blocks it, but its data is always kept.

| Bundle | Features |
|---|---|
| **HR & Employee Management** | **Employee directory & records:** profiles, encrypted bank and tax IDs, secure ID proofs, onboarding/offboarding, and a per-employee work schedule (hours/day, weekly off days, leave quotas). **Attendance & time tracking**. **Leave & holidays:** fixed company holidays plus a pool of optional days for festivals, personal or medical needs. **Salary & payroll:** prorated on each employee's own working days, with PDF payslips. **Reimbursements**. **Company documents:** policies and handbooks with read acknowledgements. |
| **Team & Work Management** | **Projects & tasks:** Kanban with WIP limits and quick-add, subtasks, dependencies, milestones, change requests, budgets and hours. **Work reports** with manager approval. |
| **Client Management** | **Clients & journey:** profiles, contacts, timeline, follow-ups and documents. **Quotations**. **Invoices & payments:** partial payments, PDFs, signed share links and overdue reminders. **Financial reports**. |
| **System** (always on) | Users, roles and permission matrix, features, settings grouped by bundle, branding, email provider presets, data retention, demo data import/delete, audit log, backups and restore, updates with rollback, and the cron dashboard. |

The AI Assistant code is included but parked (switched off) for a later release.

### Roles
Four built-in roles with granular permissions that the Super Admin can edit (custom roles can be added too):
- **Super Admin:** full control.
- **Manager:** assigned clients, projects, tasks, team workload, approvals and reports.
- **HR:** employees, documents, attendance, leave, payroll.
- **Employee:** own projects, tasks, time, reports, leave and payslips.

Permissions are enforced server-side on every route. Every controller also applies **record-level scoping**: for example, a manager only sees their assigned clients and an employee only sees projects they belong to. Disabling a module blocks its routes, navigation, widgets and permissions, but never deletes its data.

## Quick start
1. Upload the files, create an empty MySQL database, and open the site. The installer guides you through the rest.
2. Add one cron entry: `* * * * * php /path/to/niyanta/cron.php` (or use the secured web-cron URL).

Full guide: [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md) · Updates: [docs/UPDATES.md](docs/UPDATES.md) · Module development: [docs/DEVELOPING.md](docs/DEVELOPING.md) · Desktop agent protocol: [modules/Attendance/AGENT_API.md](modules/Attendance/AGENT_API.md)

## Security
- Passwords are hashed with `password_hash`, with configurable policy.
- Login is rate limited and accounts lock after repeated failures.
- Sessions use secure, HttpOnly, SameSite cookies, regenerate on login, time out when idle, and sessions can be revoked.
- CSRF protection on every POST.
- All queries are prepared, and identifiers are validated.
- Strict Content-Security-Policy (no inline scripts) plus security headers.
- Private file storage served only through permission checks, with upload type and content validation, and no script execution in public uploads.
- Secrets (SMTP, AI keys, GitHub token, bank and tax IDs) are encrypted with libsodium.
- Audit log of sensitive actions.
- Database transactions for multi-table writes.
- Backups with checksums.

## Tests
```
cp tests/config.example.php tests/config.php   # points at a DISPOSABLE database
php tests/run.php                              # core, HR and Admin suites (recreates the test DB)
php modules/Ai/tests/ai_test.php               # AI parsing, redaction, filter and scoping tests
```

## External requirements and honest limitations
- **AI** is parked. When re-enabled it needs your own OpenAI, Anthropic or OpenRouter key.
- **GitHub updates** need outbound HTTPS to `api.github.com`; private repos need a token. The update pipeline was tested with uploaded packages, including automatic rollback. The live GitHub download path could not be tested in the development sandbox. Database migrations are not rolled back automatically; restore the pre-update backup (see UPDATES.md).
- **Screenshots:** browser capture works only while the time-tracker page is open, the user has consented, and the browser screen-share permission has been granted. **Full-desktop screenshots require a separately authorised desktop agent, which is not included.** Only its API and protocol are provided.
- **Email** uses PHP `mail()` or SMTP, with presets for common providers (see [docs/EMAIL.md](docs/EMAIL.md)). Mail is queued and sent by cron.
- **Fonts:** the default is Google Fonts "Plus Jakarta Sans"; choose "System" in Appearance to avoid external requests.
