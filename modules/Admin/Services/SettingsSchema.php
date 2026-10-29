<?php
namespace Modules\Admin\Services;
use Core\{Settings, Validator, ValidationException};

/**
 * Declarative settings schema, organised like the product: Workspace, the three bundles and System.
 * Each tab lists fields: key, label, type, rules (Validator rules), options, hint, section, span.
 * Types: text, email, number, textarea, select, toggle, color, range, secret (write-only, encrypted), time, days (multi-check), provider (email preset cards).
 * Rendering and validation are generic; tabs without fields render custom content in their view.
 */
final class SettingsSchema {
  public const FONTS = ['System' => 'System default', 'Plus Jakarta Sans' => 'Plus Jakarta Sans', 'Inter' => 'Inter', 'Poppins' => 'Poppins', 'DM Sans' => 'DM Sans', 'Manrope' => 'Manrope', 'Outfit' => 'Outfit', 'Nunito' => 'Nunito', 'Roboto' => 'Roboto'];
  public const DAYS = ['1' => 'Mon', '2' => 'Tue', '3' => 'Wed', '4' => 'Thu', '5' => 'Fri', '6' => 'Sat', '7' => 'Sun'];
  /** Theme defaults mirror assets/css/app.css. Values equal to these are never stored, so the stylesheet palette applies. */
  public const DEFAULTS = ['appearance.primary_color' => '#5b4cf5', 'appearance.sidebar_color' => '#13122b', 'appearance.accent_1' => '#5b4cf5', 'appearance.accent_2' => '#12b5a0', 'appearance.accent_3' => '#f5a524', 'appearance.accent_4' => '#ff6f59', 'appearance.font_size' => '14', 'appearance.radius' => '22'];
  public const THEME_KEYS = ['appearance.primary_color', 'appearance.sidebar_color', 'appearance.accent_1', 'appearance.accent_2', 'appearance.accent_3', 'appearance.accent_4', 'appearance.font_size', 'appearance.radius'];
  /** Category key => [label, description, icon, colour]. */
  public const GROUPS = [
    'workspace' => ['Workspace', 'Company details, brand, email delivery and access.', 'building', 'lav'],
    'people' => ['HR & Employee Management', 'Work schedule, leave & holidays, attendance and payroll.', 'users', 'mint'],
    'work' => ['Team & Work', 'Projects, tasks and work reports.', 'kanban', 'sky'],
    'clients' => ['Client Management', 'Quotations, invoices and payment reminders.', 'briefcase', 'peach'],
    'system' => ['System', 'Health, data retention, demo data, backups, scheduler and updates.', 'server', 'lemon']];

  /** Tab => features it configures: the tab (nav entry, home card, URL and save) exists only while at least one is on. "ai" is the AI assistant switch. */
  public const TAB_FEATURES = ['ai' => ['ai'], 'employees' => ['employees', 'attendance', 'leave', 'payroll'], 'leave' => ['leave'], 'attendance' => ['attendance'], 'payroll' => ['payroll', 'reimbursements'], 'projects' => ['projects', 'work_reports'], 'invoicing' => ['quotations', 'invoices']];

  public static function tabOn(string $tab): bool { $f = self::TAB_FEATURES[$tab] ?? null; return $f === null || (bool) array_filter($f, 'feature_on'); }

  /** "a", "a and b", "a, b and c". */
  public static function join(array $words): string { $words = array_values(array_filter($words)); $last = array_pop($words); return $words ? implode(', ', $words).' and '.$last : (string) $last; }

  /** Documents the company identity is printed on, limited to switched-on features. */
  public static function documents(): string { return self::join([feature_on('invoices') ? 'invoices' : null, feature_on('quotations') ? 'quotations' : null, feature_on('payroll') ? 'payslips' : null]); }

  /** GROUPS with descriptions limited to switched-on features. */
  public static function groups(): array {
    $g = self::GROUPS;
    $g['people'][1] = ucfirst(self::join(['work schedule', feature_on('leave') ? 'leave & holidays' : null, feature_on('attendance') ? 'attendance' : null, feature_on('payroll') ? 'payroll' : null, feature_on('reimbursements') ? 'reimbursements' : null])).'.';
    $g['work'][1] = ucfirst(self::join([feature_on('projects') ? 'projects, tasks' : null, feature_on('work_reports') ? 'work reports' : null])).'.';
    $g['clients'][1] = ucfirst(self::join([feature_on('quotations') ? 'quotations' : null, feature_on('invoices') ? 'invoices and payment reminders' : null])).'.';
    $g['workspace'][1] = 'Company details, brand, email delivery and access'.(feature_on('ai') ? ', plus the AI assistant.' : '.');
    return $g; }

  /** Tab key => [label, icon, description, group label, colour, group key, bundle]. Order defines the navigation. Tabs of switched-off features are left out. */
  public static function tabs(): array {
    $g = static fn (string $k) => self::GROUPS[$k][0];
    [$pay, $reimb, $proj, $wr, $qt, $inv] = array_map('feature_on', ['payroll', 'reimbursements', 'projects', 'work_reports', 'quotations', 'invoices']);
    $tabs = [
      'general' => ['General', 'globe', 'Region, formats, currency and your company identity.', $g('workspace'), 'sky', 'workspace', null],
      'appearance' => ['Branding & appearance', 'palette', 'Company logo, colours, typography and theme.', $g('workspace'), 'pink', 'workspace', null],
      'email' => ['Email', 'mail', 'Email provider, SMTP credentials and sender identity.', $g('workspace'), 'peach', 'workspace', null],
      'users' => ['Users & security', 'shield', 'Password policy, sessions and sign-in protection.', $g('workspace'), 'lav', 'workspace', null],
      'features' => ['Features', 'puzzle', 'Which bundles and features are switched on.', $g('workspace'), 'mint', 'workspace', null],
      'ai' => ['AI assistant', 'sparkles', 'Goraiya chat assistant, AI models, budgets and privacy.', $g('workspace'), 'pink', 'workspace', null],
      'employees' => [feature_on('employees') ? 'Employees & schedule' : 'Work schedule', 'id-card', 'Company default working hours and weekly off days.', $g('people'), 'mint', 'people', 'people'],
      'leave' => ['Leave & holidays', 'umbrella', 'Default optional-holiday allowance.', $g('people'), 'sky', 'people', 'people'],
      'attendance' => ['Attendance & time tracking', 'timer', 'Desktop tracker, idle detection, screenshots and app downloads.', $g('people'), 'lemon', 'people', 'people'],
      'payroll' => [$pay && $reimb ? 'Payroll & reimbursements' : ($pay ? 'Payroll' : 'Reimbursements'), $pay ? 'wallet' : 'receipt', $pay ? 'Payslip footer note.' : 'How expense claims are submitted and approved.', $g('people'), 'pink', 'people', 'people'],
      'projects' => [$proj ? 'Projects & tasks' : 'Work reports', $proj ? 'kanban' : 'clipboard', $proj && $wr ? 'Reminders and defaults for projects and work reports.' : ($proj ? 'Reminders and defaults for projects and tasks.' : 'How daily and weekly work reports are reviewed.'), $g('work'), 'lav', 'work', 'work'],
      'invoicing' => [$qt && $inv ? 'Quotations & invoicing' : ($inv ? 'Invoicing' : 'Quotations'), $inv ? 'receipt' : 'file-text', $inv ? 'Document numbering, tax, payment terms and reminders.' : 'Quotation numbering, tax and default terms.', $g('clients'), 'peach', 'clients', 'clients'],
      'overview' => ['System health', 'activity', 'Health of your installation at a glance.', $g('system'), 'mint', 'system', null],
      'retention' => ['Storage & data retention', 'database', 'Disk usage, upload limits and how long logs and activity data are kept.', $g('system'), 'lav', 'system', null],
      'demo' => ['Demo data', 'layers', 'Load a realistic sample agency to explore, then remove it.', $g('system'), 'sky', 'system', null],
      'backups' => ['Backups', 'cloud', 'Automatic database and file backups.', $g('system'), 'mint', 'system', null],
      'cron' => ['Cron & scheduler', 'clock', 'Background jobs, reminders and the mail queue.', $g('system'), 'lemon', 'system', null],
      'updates' => ['Updates', 'refresh', 'Update source, channel and access token.', $g('system'), 'peach', 'system', null],];
    return array_filter($tabs, static fn ($k) => self::tabOn($k), ARRAY_FILTER_USE_KEY); }

  /** Old tab keys that moved: redirect to their new home. */
  public const MOVED = ['security' => 'users', 'modules' => 'features', 'hr' => 'employees'];

  /** Section name => helper text, shown under each section title. */
  public static function sections(string $tab): array {
    return match ($tab) {
      'general' => ['Region' => 'Timezone and how dates and times are displayed everywhere.', 'Money' => 'Currency used on '.self::join([feature_on('invoices') ? 'invoices' : (feature_on('quotations') ? 'quotations' : null), feature_on('payroll') ? 'payroll' : null, 'reports']).'.', 'Company' => self::documents() ? 'Your legal identity, printed on '.self::documents().'.' : 'Your legal identity, used in emails and across the workspace.'],
      'appearance' => ['Brand' => 'How your workspace is named across the app, emails and the sign-in page.', 'Colours' => 'Leave a colour at its default to follow the built-in palette (and future design updates).', 'Typography & shape' => 'Font, base size, corner radius and the default light/dark theme.'],
      'email' => ['SMTP server' => 'Filled in by the provider you pick, adjust if your provider says otherwise.', 'Sender' => 'Use an address on a domain you verified with your provider (SPF/DKIM).', 'Delivery' => 'In-app notifications are always on; email copies are optional.'],
      'users' => ['Passwords & sessions' => 'Applies to every user the next time they sign in or change their password.'],
      'employees' => ['Work schedule' => 'Company defaults.'.(feature_on('employees') ? ' Hours per day and weekly offs can be overridden per employee on their HR profile.' : ''), 'Joining' => 'Defaults used when onboarding new employees.'],
      'leave' => ['Holidays' => 'Fixed holidays apply to everyone; optional holidays are a personal pool for festivals or personal needs.', 'Leave year' => 'Balances are allocated for this cycle.'],
      'attendance' => ['Time tracking' => 'Applies to the Niyanta Tracker desktop app. Starting tracking clocks the person in; only tracked time counts as worked time.', 'Screenshot monitoring' => 'Only for people who accepted the monitoring notice, only while tracking. Screenshots older than the retention period are deleted nightly.', 'Desktop app downloads' => 'Where the Time Tracking page and the app\'s update check find the latest installers. Uploaded installers win when they are newer.', 'Payroll' => 'How tracked time feeds monthly salaries.'],
      'payroll' => ['Payslips' => 'Shown on every payslip PDF.'],
      'invoicing' => ['Numbering' => 'New documents get the next number after the highest existing one with this prefix.', 'Tax & payment terms' => 'Defaults for new '.self::join([feature_on('invoices') ? 'invoices' : null, feature_on('quotations') ? 'quotations' : null]).'; editable per document.', 'Default terms' => 'Pre-filled on new documents.'],
      'retention' => ['Logs & activity' => 'Older rows are deleted permanently by the nightly cleanup job. 0 keeps them forever.', 'Files & sessions' => feature_on('attendance') ? 'Screenshot files and expired login sessions on disk.' : 'Expired login sessions on disk.', 'Uploads' => 'Largest file a user can upload anywhere in the app.', 'Backups' => 'Older automatic backups are removed after each new backup.'],
      'backups' => ['Schedule & retention' => 'Automatic backups run from the scheduler at this time (server time).'],
      default => [] }; }

  public static function fields(string $tab): array {
    $num = static fn (string $key, string $label, int $min, int $max, string $hint = '', array $x = []) => $x + ['key' => $key, 'label' => $label, 'type' => 'number', 'rules' => "required|integer|min:$min|max:$max", 'min' => $min, 'max' => $max, 'hint' => $hint];
    $tz = array_combine(\DateTimeZone::listIdentifiers(), \DateTimeZone::listIdentifiers());
    $days = static fn (string $key, string $label, string $hint, int $def, int $min = 0) => ['key' => $key, 'label' => $label, 'type' => 'number', 'rules' => "required|integer|min:$min|max:3650", 'min' => $min, 'max' => 3650, 'hint' => $hint, 'default' => (string) $def, 'unit' => 'days'];
    $fields = match ($tab) {
      'general' => [
        ['key' => 'general.timezone', 'label' => 'Timezone', 'type' => 'select', 'options' => $tz, 'rules' => 'required', 'section' => 'Region'],
        ['key' => 'general.date_format', 'label' => 'Date format', 'type' => 'select', 'rules' => 'required', 'options' => self::sample(['d M Y', 'd/m/Y', 'm/d/Y', 'Y-m-d', 'd-m-Y', 'M j, Y', 'j F Y'])],
        ['key' => 'general.time_format', 'label' => 'Time format', 'type' => 'select', 'rules' => 'required', 'options' => self::sample(['h:i A', 'g:i a', 'H:i'])],
        ['key' => 'general.week_start', 'label' => 'Week starts on', 'type' => 'select', 'rules' => 'required', 'options' => ['1' => 'Monday', '7' => 'Sunday', '6' => 'Saturday']],
        ['key' => 'general.currency_symbol', 'label' => 'Currency symbol', 'type' => 'text', 'rules' => 'required|max:5', 'section' => 'Money', 'hint' => 'Shown before amounts, e.g. ₹, $, €.'],
        ['key' => 'general.currency_code', 'label' => 'Currency code', 'type' => 'text', 'rules' => 'required|regex:/^[A-Z]{3}$/', 'hint' => 'ISO 4217 code, e.g. INR, USD, EUR.', 'upper' => true],
        ['key' => 'general.thousand_sep', 'label' => 'Thousands separator', 'type' => 'select', 'options' => [',' => 'Comma, 1,234,567.00', "'" => "Apostrophe, 1'234'567.00", '' => 'None, 1234567.00'], 'default' => ',', 'span' => true],
        ['key' => 'company.name', 'label' => 'Company name', 'type' => 'text', 'rules' => 'required|max:80', 'section' => 'Company', 'span' => true, 'hint' => 'Shown under the Niyanta logo and on '.(self::documents() ? self::documents().' and ' : '').'emails. The Niyanta product name and logo are fixed.'],
        ['key' => 'company.address', 'label' => 'Registered address', 'type' => 'textarea', 'rules' => 'nullable|max:500', 'span' => true],
        ['key' => 'company.email', 'label' => 'Billing email', 'type' => 'email', 'rules' => 'nullable|email|max:190'],
        ['key' => 'company.phone', 'label' => 'Billing phone', 'type' => 'text', 'rules' => 'nullable|max:30'],
        ['key' => 'company.tax_id', 'label' => 'Tax ID (GSTIN / VAT)', 'type' => 'text', 'rules' => 'nullable|max:60', 'hint' => (feature_on('invoices') || feature_on('quotations') ? 'Printed on '.self::join([feature_on('invoices') ? 'invoices' : null, feature_on('quotations') ? 'quotations' : null]).'.' : 'Your registered tax number.')]],
      'users' => [
        $num('security.password_min_length', 'Minimum password length', 8, 64, 'Passwords must also contain letters and numbers.', ['section' => 'Passwords & sessions']),
        $num('security.login_max_attempts', 'Max sign-in attempts per 15 min', 3, 20, 'Per email and IP address. Accounts lock for 30 minutes after 10 failures.'),
        $num('security.session_idle_minutes', 'Session idle timeout (minutes)', 5, 1440, 'Users are signed out after this much inactivity.')],
      'appearance' => [
        ['key' => 'appearance.primary_color', 'label' => 'Primary', 'type' => 'color', 'section' => 'Colours', 'var' => '--primary', 'hint' => 'Buttons, links, focus rings.'],
        ['key' => 'appearance.sidebar_color', 'label' => 'Sidebar', 'type' => 'color', 'var' => '--sidebar', 'hint' => 'Keep it dark for contrast.'],
        ['key' => 'appearance.accent_1', 'label' => 'Accent: indigo', 'type' => 'color', 'var' => '--lav'],
        ['key' => 'appearance.accent_2', 'label' => 'Accent: teal', 'type' => 'color', 'var' => '--mint'],
        ['key' => 'appearance.accent_3', 'label' => 'Accent: amber', 'type' => 'color', 'var' => '--lemon'],
        ['key' => 'appearance.accent_4', 'label' => 'Accent: coral', 'type' => 'color', 'var' => '--peach'],
        ['key' => 'appearance.font_family', 'label' => 'Font family', 'type' => 'select', 'rules' => 'required', 'options' => self::FONTS, 'section' => 'Typography & shape', 'preview' => 'font', 'default' => 'Plus Jakarta Sans'],
        ['key' => 'appearance.default_theme', 'label' => 'Default theme', 'type' => 'select', 'rules' => 'required', 'options' => ['system' => 'Follow device', 'light' => 'Light', 'dark' => 'Dark'], 'hint' => 'Users can override this from the top bar.', 'default' => 'system'],
        $num('appearance.font_size', 'Base font size', 12, 18, '', ['type' => 'range', 'var' => '--fs', 'unit' => 'px']),
        $num('appearance.radius', 'Corner radius', 8, 32, '', ['type' => 'range', 'var' => '--r', 'unit' => 'px'])],
      'email' => [
        ['key' => 'email.provider', 'label' => 'Email provider', 'type' => 'provider', 'options' => MailPresets::options(), 'default' => 'custom', 'section' => 'Provider', 'span' => true],
        ['key' => 'email.smtp_host', 'label' => 'SMTP host', 'type' => 'text', 'rules' => 'nullable|max:190|regex:/^[A-Za-z0-9.\-]+$/', 'section' => 'SMTP server', 'show' => 'smtp', 'placeholder' => 'smtp.example.com'],
        ['key' => 'email.smtp_port', 'label' => 'Port', 'type' => 'number', 'rules' => 'nullable|integer|min:1|max:65535', 'default' => '587', 'show' => 'smtp'],
        ['key' => 'email.smtp_encryption', 'label' => 'Encryption', 'type' => 'select', 'options' => ['tls' => 'STARTTLS (usually port 587)', 'ssl' => 'SSL/TLS (usually port 465)', 'none' => 'None (not recommended)'], 'default' => 'tls', 'show' => 'smtp'],
        ['key' => 'email.smtp_username', 'label' => 'Username', 'type' => 'text', 'rules' => 'nullable|max:190', 'show' => 'smtp', 'autocomplete' => 'off'],
        ['key' => 'email.smtp_password', 'label' => 'Password / SMTP key', 'type' => 'secret', 'rules' => 'nullable|max:500', 'show' => 'smtp', 'span' => true],
        ['key' => 'email.from_address', 'label' => 'From address', 'type' => 'email', 'rules' => 'nullable|email|max:190', 'section' => 'Sender', 'placeholder' => 'hello@yourcompany.com'],
        ['key' => 'email.from_name', 'label' => 'From name', 'type' => 'text', 'rules' => 'nullable|max:100'],
        ['key' => 'email.notifications', 'label' => 'Send email copies of notifications', 'type' => 'toggle', 'default' => '1', 'section' => 'Delivery', 'span' => true]],
      'employees' => [
        ['key' => 'hr.work_hours_per_day', 'label' => 'Work hours per day', 'type' => 'number', 'rules' => 'required|numeric|min:1|max:24', 'step' => '0.5', 'section' => 'Work schedule', 'default' => '8', 'hint' => ($use = self::join([feature_on('attendance') ? 'attendance targets, timesheets' : null, feature_on('payroll') ? 'hourly payroll' : null])) ? 'Used for '.$use.'.' : 'The standard working day of the company.'],
        ['key' => 'general.weekend_days', 'label' => 'Weekly off days', 'type' => 'days', 'hint' => 'Company default.'.(feature_on('employees') ? ' Override per employee on their profile (Work schedule'.(feature_on('leave') ? ' & leave' : '').').' : ''), 'span' => true, 'default' => '6,7']],
      'leave' => [
        $num('hr.optional_holidays_default', 'Optional holidays per year', 0, 60, 'Default yearly allowance of the Optional Holiday (OH) leave type. Can be changed per employee.', ['default' => '15', 'section' => 'Holidays'])],
      'attendance' => [
        $num('attendance.idle_minutes', 'Idle detection after (minutes)', 1, 60, 'No mouse or keyboard input for this long marks the person idle; on return they choose to keep or discard the idle time.', ['section' => 'Time tracking', 'default' => '5']),
        $num('attendance.heartbeat_seconds', 'Sync interval (seconds)', 15, 300, 'How often the app reports activity. Without a sync for 10 minutes the server stops the entry at the last sync.', ['default' => '60']),
        ['key' => 'attendance.auto_pause_lock', 'label' => 'Pause automatically when the computer locks or sleeps', 'type' => 'toggle', 'default' => '1', 'span' => true, 'hint' => 'Tracking resumes on unlock or wake (the person can also resume by hand).'],
        ['key' => 'attendance.screenshots_enabled', 'label' => 'Screenshot monitoring', 'type' => 'toggle', 'section' => 'Screenshot monitoring', 'span' => true, 'hint' => 'Captures all screens while tracking. Each person accepts the monitoring notice in the app before the first capture.'],
        $num('attendance.screenshot_interval_seconds', 'Screenshot interval (seconds)', 10, 3600, 'Between 10 seconds and 1 hour, e.g. 180 = every 3 minutes.', ['default' => '180', 'unit' => 'sec']),
        $num('attendance.screenshot_retention_days', 'Keep screenshots for (days)', 1, 365, 'Also shown under System › Data retention.', ['default' => '30']),
        ['key' => 'attendance.screenshot_notify', 'label' => 'Show a small notice each time a screenshot is taken', 'type' => 'toggle', 'default' => '1', 'span' => true],
        ['key' => 'attendance.screenshot_notify_optional', 'label' => 'People may turn the screenshot notice off in their app', 'type' => 'toggle', 'default' => '1', 'span' => true],
        ['key' => 'tracker.github_repo', 'label' => 'GitHub repository with tracker releases', 'type' => 'text', 'rules' => 'nullable|max:140|regex:/^[A-Za-z0-9_.\-]+\/[A-Za-z0-9_.\-]+$/', 'section' => 'Desktop app downloads', 'span' => true, 'placeholder' => (string) (Settings::get('updates.repo') ?: 'owner/name'), 'hint' => 'Latest release tagged tracker-v* (e.g. tracker-v1.0.0) with the installers as assets. Empty uses the update repository. Checked at most every 30 minutes.'],
        ['key' => 'payroll.salary_from_tracked_time', 'label' => 'Calculate monthly salary from tracked time', 'type' => 'toggle', 'default' => '1', 'section' => 'Payroll', 'span' => true, 'feature' => 'payroll', 'hint' => 'Monthly pay = salary × tracked approved hours ÷ expected hours (working days on the employee\'s schedule minus paid leave and holidays), capped at 100%. Hourly pay always uses tracked hours.']],
      'payroll' => [
        ['key' => 'payroll.payslip_footer', 'label' => 'Payslip footer note', 'type' => 'textarea', 'rules' => 'nullable|max:500', 'span' => true, 'section' => 'Payslips', 'hint' => 'Printed at the bottom of every payslip.', 'feature' => 'payroll']],
      'invoicing' => [
        ['key' => 'crm.invoice_prefix', 'label' => 'Invoice number prefix', 'type' => 'text', 'rules' => 'nullable|max:10|regex:/^[A-Za-z0-9\/_\-]*$/', 'default' => 'INV-', 'section' => 'Numbering', 'hint' => 'e.g. INV- → INV-00042', 'feature' => 'invoices'],
        ['key' => 'crm.quotation_prefix', 'label' => 'Quotation number prefix', 'type' => 'text', 'rules' => 'nullable|max:10|regex:/^[A-Za-z0-9\/_\-]*$/', 'default' => 'QT-', 'section' => 'Numbering', 'hint' => 'e.g. QT- → QT-00007', 'feature' => 'quotations'],
        ['key' => 'crm.default_tax_rate', 'label' => 'Default tax rate (%)', 'type' => 'number', 'rules' => 'nullable|numeric|min:0|max:100', 'default' => '18', 'step' => '0.01', 'section' => 'Tax & payment terms', 'hint' => 'e.g. 18 for GST.'],
        ['key' => 'crm.invoice_due_days', 'label' => 'Invoices due after (days)', 'type' => 'number', 'rules' => 'nullable|integer|min:0|max:365', 'default' => '15', 'hint' => 'Due date = issue date + this many days.', 'feature' => 'invoices'],
        ['key' => 'crm.invoice_terms', 'label' => 'Invoice terms', 'type' => 'textarea', 'rules' => 'nullable|max:2000', 'span' => true, 'section' => 'Default terms', 'placeholder' => 'Payment due within 15 days by bank transfer or UPI.', 'feature' => 'invoices'],
        ['key' => 'crm.quotation_terms', 'label' => 'Quotation terms', 'type' => 'textarea', 'rules' => 'nullable|max:2000', 'span' => true, 'section' => 'Default terms', 'placeholder' => 'This quotation is valid for 30 days.', 'feature' => 'quotations']],
      'retention' => [
        $days('retention.audit_days', 'Audit log', 'Who changed what. Keep at least a year for accountability.', (int) Settings::get('security.audit_retention_days', 365)) + ['section' => 'Logs & activity'],
        $days('retention.notifications_days', 'Read notifications', 'Unread notifications are never deleted.', 90),
        $days('retention.mail_queue_days', 'Sent & failed emails', 'Copies of outgoing emails in the mail queue. Pending emails are kept.', 30),
        $days('retention.cron_logs_days', 'Scheduler run history', 'One row per background job run.', 30),
        $days('retention.ai_usage_days', 'AI usage log', 'Token usage records of the AI assistant.', 180) + ['feature' => 'ai'],
        $num('attendance.screenshot_retention_days', 'Activity screenshots', 1, 365, 'Image files and their rows. Deleted by the attendance screenshot cleanup job.', ['default' => '30', 'section' => 'Files & sessions', 'unit' => 'days', 'feature' => 'attendance']),
        $days('retention.sessions_days', 'Expired sign-in sessions', 'Session files on disk with no activity for this long. 0 keeps them.', 2) + ['section' => 'Files & sessions'],
        $num('backups.keep', 'Backups to keep', 1, 90, 'The newest successful backups are kept; older ones are deleted.', ['default' => '7', 'section' => 'Backups', 'unit' => 'newest']),
        $num('storage.max_upload_mb', 'Maximum upload size (MB)', 1, 512, 'Cannot exceed the PHP upload limit shown above.', ['default' => '10', 'section' => 'Uploads'])],
      'backups' => [
        ['key' => 'backups.enabled', 'label' => 'Automatic daily backups', 'type' => 'toggle', 'default' => '1', 'span' => true, 'section' => 'Schedule & retention'],
        ['key' => 'backups.time', 'label' => 'Run at (server time)', 'type' => 'time', 'rules' => 'required|time', 'default' => '02:30'],
        $num('backups.keep', 'Keep the newest', 1, 90, 'Older successful backups are deleted automatically.', ['default' => '7']),
        ['key' => 'backups.include_uploads', 'label' => 'Include uploaded files & config (full backup)', 'type' => 'toggle', 'default' => '0', 'span' => true, 'hint' => 'Creates a .zip with the database, uploaded files and config/config.php.']],
      'cron' => [['key' => 'cron.web_enabled', 'label' => 'Allow web cron URL', 'type' => 'toggle', 'default' => '1', 'span' => true, 'hint' => 'Lets an external pinger run the scheduler through a secret URL when crontab is not available.']],
      'updates' => [
        ['key' => 'updates.repo', 'label' => 'GitHub repository', 'type' => 'text', 'rules' => 'nullable|max:140|regex:/^[A-Za-z0-9_.\-]+\/[A-Za-z0-9_.\-]+$/', 'hint' => 'owner/name, e.g. acme/niyanta'],
        ['key' => 'updates.channel', 'label' => 'Update channel', 'type' => 'select', 'rules' => 'required', 'options' => ['release' => 'Stable releases', 'commit' => 'Latest commit on branch'], 'default' => 'release'],
        ['key' => 'updates.branch', 'label' => 'Branch', 'type' => 'text', 'rules' => 'nullable|max:100|regex:/^[A-Za-z0-9_.\/\-]+$/', 'default' => 'main', 'hint' => 'Used by the commit channel.'],
        ['key' => 'updates.github_token', 'label' => 'GitHub access token', 'type' => 'secret', 'rules' => 'nullable|max:255|regex:/^[A-Za-z0-9_]+$/', 'hint' => 'Only needed for private repositories (read-only "contents" scope).']],
      default => [] };
    /* A field (or section) may declare 'feature' => name|[names]: it is shown and saved only while one of them is on. */
    return array_values(array_filter($fields, static fn ($f) => empty($f['feature']) || array_filter((array) $f['feature'], 'feature_on'))); }

  private static function sample(array $formats): array { $t = strtotime('2026-03-28 16:45'); $o = []; foreach ($formats as $f) { $o[$f] = date($f, $t); } return $o; }

  public static function value(array $f): mixed {
    if ($f['key'] === 'email.provider') { return Settings::get('email.provider') ?: (Settings::get('email.driver', 'mail') === 'mail' ? 'mail' : 'custom'); }
    return Settings::get($f['key'], self::DEFAULTS[$f['key']] ?? $f['default'] ?? null); }

  /** True when the stored theme differs from the built-in palette. */
  public static function customTheme(): bool {
    foreach (self::THEME_KEYS as $k) { $v = Settings::get($k); if ($v !== null && $v !== '' && strtolower((string) $v) !== self::DEFAULTS[$k]) { return true; } }
    return false; }

  /**
   * Validate posted values for a tab. Returns [key => value] ready for Settings::setMany.
   * Secrets left blank keep their stored value (unless clear[key] is ticked). Theme values equal to the default become null.
   */
  public static function validate(string $tab, array $input, array $clear = []): array {
    $fields = self::fields($tab);
    $rules = []; $labels = []; $errors = [];
    foreach ($fields as $f) { if (!empty($f['rules']) && !in_array($f['type'], ['select', 'days', 'toggle', 'provider'], true)) { $rules[$f['key']] = $f['rules']; $labels[$f['key']] = $f['label']; } }
    if (isset($input['general.currency_code']) && is_string($input['general.currency_code'])) { $input['general.currency_code'] = strtoupper(trim($input['general.currency_code'])); }
    try { $clean = Validator::make($input, $rules, $labels); }
    catch (ValidationException $e) { $clean = []; $errors = $e->errors; }
    $out = [];
    foreach ($fields as $f) {
      $k = $f['key']; $raw = $input[$k] ?? null;
      if (isset($errors[$k])) { continue; }
      switch ($f['type']) {
        case 'toggle': $out[$k] = in_array($raw, ['1', 'on', 'true', 1, true], true) ? '1' : '0'; break;
        case 'select': case 'provider':
          if ($raw === null && isset($f['default'])) { $raw = $f['default']; }
          $v = is_string($raw) ? $raw : '';
          if (!array_key_exists($v, $f['options']) || ($v === '' && str_contains($f['rules'] ?? '', 'required'))) { $errors[$k] = $f['label'].' is invalid.'; break; }
          $out[$k] = (string) $v; break;
        case 'days':
          $d = array_values(array_unique(array_filter(array_map('strval', is_array($raw) ? $raw : []), static fn ($x) => isset(self::DAYS[$x]))));
          sort($d);
          if (count($d) > 3) { $errors[$k] = 'Choose at most three weekly off days.'; break; }
          $out[$k] = implode(',', $d); break;
        case 'color':
          $v = is_string($raw) ? strtolower(trim($raw)) : '';
          if (!preg_match('/^#[0-9a-f]{6}$/', $v)) { $errors[$k] = $f['label'].' must be a hex colour like #5b4cf5.'; break; }
          $out[$k] = $v; break;
        case 'secret':
          if (!empty($clear[$k])) { $out[$k] = null; break; }
          if (($clean[$k] ?? null) !== null && $clean[$k] !== '') { $out[$k] = (string) $clean[$k]; }
          break;
        default: $v = $clean[$k] ?? null; $out[$k] = $v === null ? '' : (string) $v; } }
    if ($errors) { throw new ValidationException($errors); }
    foreach (self::THEME_KEYS as $k) { if (array_key_exists($k, $out) && strtolower((string) $out[$k]) === self::DEFAULTS[$k]) { $out[$k] = null; } }
    if (isset($out['email.provider'])) { $out['email.driver'] = $out['email.provider'] === 'mail' ? 'mail' : 'smtp'; }
    return $out; } }
