# Developing Niyanta modules

Niyanta is a dependency-free PHP 8.2 modular monolith (no Composer, no Node, no Redis). It runs on plain shared hosting.

## Layout
```
index.php            front controller        cron.php        CLI scheduler
core/                framework (App, Router, DB, Auth, Gate, View, Validator, Settings, Storage, Mailer, Notify, Pdf, Cron, Modules, Migrator, Audit, Cache, Crypto, RateLimiter)
core/Controllers/    auth, dashboard, account, files, search, notifications, web-cron
core/migrations/     core tables (users, roles, permissions, settings, files, notifications, audit_logs, ...)
modules/<Name>/      module.php (manifest), migrations/, Controllers/, Services/, views/
views/               layouts/app.php, layouts/blank.php, partials/, errors/
assets/css/app.css   design system   assets/js/app.js  core UI   assets/img/icons.svg  icon sprite
assets/img/illustrations/*.svg   cartoon illustrations (use illus('name'))
storage/             private: uploads, backups, logs, cache, sessions (denied by .htaccess)
```
Namespaces: `Core\X` → `core/X.php`; `Modules\Crm\Controllers\ClientController` → `modules/Crm/Controllers/ClientController.php`.

## Module manifest (`modules/<Name>/module.php`)
Returns an array: `key, name, description, icon, order, version, requires[], permissions, nav, routes, widgets, search, cron, file_access`.
- **permissions**: `'crm.clients.view' => ['label' => '...', 'roles' => ['manager']]` — roles get the grant by default on install/sync. Key prefix MUST be the module key (Gate denies everything of a disabled module).
- **routes**: `function (Core\Router $r) { $r->get('/clients', [ClientController::class, 'index'], 'crm.clients.view'); }` — third arg is a permission string, an array (any-of) or options `['perm' => ..., 'throttle' => [max, seconds], 'auth' => false, 'csrf' => false]`. Routes are automatically blocked when the module is disabled. `{id}` and `{*_id}` params match digits only. Every POST is CSRF-checked automatically.
- **widgets**: list of `['zone' => 'kpis'|'main'|'side', 'order' => int, 'perm' => perm|[perms], 'render' => fn(array $user): string]`. KPI widgets render one `<div class="tile bg-lav">…</div>` each.
- **search**: `fn(string $q, array $user): array` returning `['Clients' => [['title'=>..,'meta'=>..,'url'=>'/clients/5','icon'=>'briefcase'], ...]]` — MUST scope to records the user may see (max ~8 per group).
- **cron**: `'name' => ['every' => minutes | 'daily' => 'HH:MM' | 'weekly' => 'N HH:MM' | 'monthly' => 'D HH:MM', 'run' => callable returning a short status string]`.
- **file_access**: `'client' => fn(array $user, int $entityId, array $file, string $mode): bool` — authorises downloads of files attached via `Storage::attach($upload, 'crm', 'client', $id, 'category')`. Download link: `url('/files/'.$id)`; inline view `/files/{id}/view`; delete POST `/files/{id}/delete` (mode 'delete').

Validator note: rules are split on `|`; for a `regex:` rule containing `|` pass the rules as an array (`['required', 'regex:/a|b/']`).

## Controllers
Extend `Core\Controller`. Helpers: `$this->view('crm::clients/index', [...])`, `$this->validate([...rules])` (throws → redirect back with `errors()`/`old()`), `$this->redirect('/path')`, `$this->back()`, `$this->json()`, `$this->flash('Saved')`, `$this->abort(403)`, `$this->authorize('perm')`, `$this->can('perm')`, `$this->user()`, `$this->uid()`, `$this->findOr404('table', $id)`.
**Record-level scoping is mandatory**: route permission is not enough — e.g. a manager without `crm.clients.view_all` sees only clients in `client_managers`; employees only projects in `project_members`. Write one private `scope()`/`visible($id)` helper per controller and use it for index, show, edit, update, delete and related records.
Log meaningful changes with `Audit::log('crm.client.update', 'client', $id, ['meta'])`. Use `DB::transaction()` for multi-table writes (invoices + items + totals, payroll runs).

## Database
`DB::all/first/value/column/pairs/find/insert/insertMany/update/delete/paginate/transaction`, always bound params (`?`). `DB::like($q)` for LIKE, `DB::in($ids)` for IN lists. `DB::paginate($sql, $params, 20)` → `['items','total','page','pages','per']`, render with `pager($p)`. Avoid N+1: fetch related rows with one `IN (...)` query or JOIN/GROUP BY.
Migrations: `migrations/YYYY_MM_DD_NNNNNN_name.php` returning `['up' => [sql...], 'down' => [sql...]]`. Never edit a shipped migration; add a new one. All modules are migrated even when disabled.

## Views
Plain PHP. ALWAYS escape with `e()`. Helpers: `url()`, `asset()`, `csrf_field()`, `icon('name','sm')`, `illus('name','class')`, `avatar($name,$img,'sm')`, `badge($status,$label)`, `money()`, `fdate()`, `fdatetime()`, `hours($seconds)`, `ago()`, `pager($p)`, `empty_state($title,$text,$illus,$actionHtml)`, `partial('partials/page_head', ['title','subtitle','crumbs'=>[[label,url]],'actions'=>html])`, `old()`, `error_for('field')`, `selected()`, `checked()`, `can()`, `setting()`, `wa_link($phone)`, `str_limit()`, `query_merge([...])`.
Layout gets `$title`; pass `'styles' => ['css/crm.css']` for module CSS and `'scripts' => ['js/crm.js']` in view data to load extra JS files (must live in `assets/js/`).
**CSP**: no inline `<script>` and no inline event handlers (`onclick`, `onchange`). Put JS in `assets/js/<module>.js`; use `data-*` hooks. Core JS already provides: `data-modal="dialogId"` + `<dialog class="modal">` with `[data-close]`, `data-confirm="msg"` on forms/links, `data-autosubmit` on selects, `data-copy`, `data-tabs`/`data-tab`/`data-panel`+`data-group`, `form[data-ajax]` (+`data-reload`), `table[data-line-items]` invoice line calculator (see CRM), `window.Niyanta.req(path, {body})` fetch helper with CSRF, `Niyanta.toast(msg, 'error')`.

## Design system (assets/css/app.css)
Dark sidebar, light content, rounded cards (24px), multi-coloured pastel accents: lav, mint, lemon, peach, sky, pink (`bg-*` strong, `soft-*` light, `pill-*` badges). Components: `.card`, `.card-head`, `.grid .g2/.g3/.g4/.g-main/.g-side`, `.tile` (+ `.dark`, `.num`, `.lbl`, `.tile-ic`, `.corner` illustration), `.hero` (+ illustration), `.btn` (`primary, ghost, soft, mint, danger, sm, icon`), `.pill`, `.table` in `.table-wrap`, `.form` / `.form-grid` / `.field` / `.full`, `.filters`, `.tabs`, `.list` / `.list-item`, `.kanban` / `.kcol` / `.kcard`, `.timeline` / `.tl-item` / `.tl-dot` / `.tl-card`, `.progress>span`, `.ring` (`style="--v:60" data-label="60%"`), `.bars` chart, `.cal` mini calendar, `.week`, `.timer-card`, `.kv` (dl), `.quick`, `.avatars`, `.empty`, `.alert.info/warn/error/ok`, `.chips/.chip`, `.shots`, `.ai-out`, `.totals`, `dd.details` dropdown (`details.dd > summary + .dd-menu`). Use an illustration on empty states and hero cards. Must work in light and dark themes (use CSS variables, never hardcode white backgrounds).

## Code style
Compact: opening braces on the statement line; closing braces at the end of the last statement line — **no line may contain only a brace**. Two-space indent. Keep CSS/JS minified-style (one rule per line for CSS, compact JS).

## Testing
`php tests/run.php` runs the test suite against the database in `tests/config.php` (MySQL). Dev server: `php -S 127.0.0.1:8080 tests/dev-router.php`.
