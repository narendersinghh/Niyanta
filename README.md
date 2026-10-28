# Niyanta

**Niyanta** is a lightweight, secure, modular business-management web app for agencies and service businesses: clients, projects, people, time, payroll and AI in one place. It is built to run on **ordinary PHP shared hosting**: no Composer, Node.js, Redis, Docker or background workers.

- **Stack:** PHP 8.2+, MySQL 5.7+/MariaDB 10.3+, server-rendered HTML, one CSS file and a small vanilla JS file
- **Architecture:** modular monolith (`core/` framework plus self-contained `modules/`)
- **Design:** dark sidebar, pastel multi-colour cards, light/dark/system themes, cartoon illustrations

## Modules

| Module | Highlights |
|---|---|
| **Client Management (CRM)** | Client profiles, contacts, tags, assigned managers, WhatsApp/call/email quick actions, chronological client journey (meetings, calls, requirements, follow-ups, internal notes), private documents, quotations, invoices with partial payments, branded PDF invoices/quotations, signed share links, overdue tracking and reminders, financial reports (collections, aging, outstanding, per-project) |
| **Projects & Work** | Projects with scope, deliverables, budgets and members; milestones; tasks, subtasks and dependencies; drag-and-drop Kanban and list views; comments and attachments; estimated vs actual and billable hours; change requests; daily/weekly work reports with manager approval; deadline reminders |
| **HR** | Team directory, employee records (bank and tax IDs encrypted and masked), secure ID-proof and agreement storage, onboarding/offboarding checklists, leave types, balances, requests and approvals (HR: all; managers: direct reports), team leave calendar, holidays |
| **Attendance & Time** | Clock in/out and breaks; timer with idle detection and keep/discard; manual entries; weekly timesheets with scoped approvals; consent-based browser screenshot capture (configurable, default every 3 min) with retention clean-up; token-authenticated desktop-agent API |
| **Payroll** | Monthly or hourly compensation history, allowances and deductions (fixed or %), reimbursements with receipts, monthly payroll runs (unpaid leave and approved hours included), finalize/paid lifecycle, PDF payslips, audited bank-transfer CSV |
| **AI Assistant** | OpenAI, Anthropic Claude and OpenRouter: client and meeting summaries, email and payment-reminder drafts, follow-up suggestions, project reports, task suggestions that need explicit approval, natural-language search. Keys are encrypted; token budget, per-user limits, PII redaction and a usage log |
| **System Administration** | Users, roles and permission matrix, module on/off switches, settings tabs (general, users, appearance and branding with live preview, email, AI, HR, payroll, storage, cron, updates, security, backups), audit log, backups and restore, GitHub/upload updates with rollback, cron dashboard, system health |

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
- **AI** needs your own API key for OpenAI, Anthropic or OpenRouter, and outbound HTTPS (cURL). Provider calls were exercised only against a mock and with error handling, not with live keys.
- **GitHub updates** need outbound HTTPS to `api.github.com`; private repos need a token. The update pipeline was tested with uploaded packages, including automatic rollback. The live GitHub download path could not be tested in the development sandbox. Database migrations are not rolled back automatically; restore the pre-update backup (see UPDATES.md).
- **Screenshots:** browser capture works only while the time-tracker page is open, the user has consented, and the browser screen-share permission has been granted. **Full-desktop screenshots require a separately authorised desktop agent, which is not included.** Only its API and protocol are provided.
- **Email** uses PHP `mail()` or SMTP, and is sent by cron from a queue.
- **Fonts:** the default is Google Fonts "Plus Jakarta Sans"; choose "System" in Appearance to avoid external requests.
