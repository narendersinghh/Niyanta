<?php
namespace Modules\Admin\Services;
use Core\{Settings, Validator, ValidationException};

/**
 * Declarative settings schema. Each tab lists fields: key, label, type, rules (Validator rules), options, hint, section, span.
 * Types: text, email, number, textarea, select, toggle, color, secret (write-only, encrypted), time, days (multi-check).
 * Rendering and validation are generic; tabs without fields render custom content in their view.
 */
final class SettingsSchema {
  public const FONTS = ['System' => 'System default', 'Plus Jakarta Sans' => 'Plus Jakarta Sans', 'Inter' => 'Inter', 'Poppins' => 'Poppins', 'DM Sans' => 'DM Sans', 'Manrope' => 'Manrope', 'Outfit' => 'Outfit', 'Nunito' => 'Nunito', 'Roboto' => 'Roboto'];
  public const DAYS = ['1' => 'Mon', '2' => 'Tue', '3' => 'Wed', '4' => 'Thu', '5' => 'Fri', '6' => 'Sat', '7' => 'Sun'];
  public const DEFAULTS = ['appearance.primary_color' => '#7c5cff', 'appearance.sidebar_color' => '#111116', 'appearance.accent_1' => '#c8b6ff', 'appearance.accent_2' => '#9fe8cb', 'appearance.accent_3' => '#e4f28f', 'appearance.accent_4' => '#ffb48a', 'appearance.font_size' => '14', 'appearance.radius' => '24'];

  /** Tab key => [label, icon, description, group]. Order defines the navigation. */
  public static function tabs(): array {
    return [
      'overview' => ['System overview', 'activity', 'Health of your installation at a glance.', 'System'],
      'general' => ['General', 'globe', 'Timezone, date & number formats, currency and work week.', 'Workspace'],
      'users' => ['Users & Permissions', 'users', 'Password policy, sign-in protection and access management.', 'Workspace'],
      'modules' => ['Modules', 'puzzle', 'Features enabled for everyone.', 'Workspace'],
      'appearance' => ['Appearance & Branding', 'palette', 'Your name, logo, colours and typography.', 'Workspace'],
      'email' => ['Email', 'mail', 'How the system sends notifications and invitations.', 'Workspace'],
      'ai' => ['AI', 'sparkles', 'AI providers, budgets and privacy.', 'Modules'],
      'hr' => ['HR & Attendance', 'id-card', 'Working hours, leave year and time tracking defaults.', 'Modules'],
      'payroll' => ['Payroll', 'wallet', 'Payslip labels and notes.', 'Modules'],
      'storage' => ['Storage', 'database', 'Upload limits and disk usage.', 'System'],
      'security' => ['Security', 'shield', 'Sessions, password policy, audit retention and emergency sign-out.', 'System'],
      'backups' => ['Backups', 'cloud', 'Automatic database and file backups.', 'System'],
      'cron' => ['Cron & Scheduler', 'clock', 'Background jobs, reminders and the mail queue.', 'System'],
      'updates' => ['Updates', 'refresh', 'Update source, channel and access token.', 'System']]; }

  public static function fields(string $tab): array {
    $num = static fn (string $key, string $label, int $min, int $max, string $hint = '', array $x = []) => $x + ['key' => $key, 'label' => $label, 'type' => 'number', 'rules' => "required|integer|min:$min|max:$max", 'min' => $min, 'max' => $max, 'hint' => $hint];
    $tz = array_combine(\DateTimeZone::listIdentifiers(), \DateTimeZone::listIdentifiers());
    $months = array_combine(array_map('strval', range(1, 12)), array_map(static fn ($m) => date('F', mktime(0, 0, 0, $m, 1)), range(1, 12)));
    $pwd = $num('security.password_min_length', 'Minimum password length', 8, 64, 'Passwords must also contain letters and numbers.');
    $idle = $num('security.session_idle_minutes', 'Session idle timeout (minutes)', 5, 1440, 'Users are signed out after this much inactivity.');
    $attempts = $num('security.login_max_attempts', 'Max sign-in attempts per 15 min', 3, 20, 'Per email and IP address. Accounts lock for 30 minutes after 10 failures.');
    return match ($tab) {
      'general' => [
        ['key' => 'general.timezone', 'label' => 'Timezone', 'type' => 'select', 'options' => $tz, 'rules' => 'required', 'section' => 'Region'],
        ['key' => 'general.date_format', 'label' => 'Date format', 'type' => 'select', 'rules' => 'required', 'options' => self::sample(['d M Y', 'd/m/Y', 'm/d/Y', 'Y-m-d', 'd-m-Y', 'M j, Y', 'j F Y'])],
        ['key' => 'general.time_format', 'label' => 'Time format', 'type' => 'select', 'rules' => 'required', 'options' => self::sample(['h:i A', 'g:i a', 'H:i'])],
        ['key' => 'general.week_start', 'label' => 'Week starts on', 'type' => 'select', 'rules' => 'required', 'options' => ['1' => 'Monday', '7' => 'Sunday', '6' => 'Saturday']],
        ['key' => 'general.currency_symbol', 'label' => 'Currency symbol', 'type' => 'text', 'rules' => 'required|max:5', 'section' => 'Money', 'hint' => 'Shown before amounts, e.g. ₹, $, €.'],
        ['key' => 'general.currency_code', 'label' => 'Currency code', 'type' => 'text', 'rules' => 'required|regex:/^[A-Z]{3}$/', 'hint' => 'ISO 4217 code, e.g. INR, USD, EUR.', 'upper' => true],
        ['key' => 'general.thousand_sep', 'label' => 'Thousands separator', 'type' => 'select', 'options' => [',' => 'Comma — 1,234,567.00', "'" => "Apostrophe — 1'234'567.00", '' => 'None — 1234567.00'], 'default' => ','],
        ['key' => 'general.weekend_days', 'label' => 'Weekend days', 'type' => 'days', 'section' => 'Work week', 'hint' => 'Used for working-day calculations in attendance, leave and payroll.', 'span' => true],
        ['key' => 'company.address', 'label' => 'Company address', 'type' => 'textarea', 'rules' => 'nullable|max:500', 'section' => 'Company & invoicing', 'hint' => 'Printed on invoices, quotations and payslips.', 'span' => true],
        ['key' => 'company.email', 'label' => 'Billing email', 'type' => 'email', 'rules' => 'nullable|email|max:190'],
        ['key' => 'company.phone', 'label' => 'Billing phone', 'type' => 'text', 'rules' => 'nullable|max:30'],
        ['key' => 'company.tax_id', 'label' => 'Tax ID (GSTIN / VAT)', 'type' => 'text', 'rules' => 'nullable|max:60'],
        ['key' => 'crm.invoice_due_days', 'label' => 'Invoice due in (days)', 'type' => 'number', 'rules' => 'nullable|integer|min:0|max:365', 'default' => '15'],
        ['key' => 'crm.invoice_prefix', 'label' => 'Invoice number prefix', 'type' => 'text', 'rules' => 'nullable|max:10', 'default' => 'INV-'],
        ['key' => 'crm.quotation_prefix', 'label' => 'Quotation number prefix', 'type' => 'text', 'rules' => 'nullable|max:10', 'default' => 'QT-'],
        ['key' => 'crm.default_tax_rate', 'label' => 'Default tax rate %', 'type' => 'number', 'rules' => 'nullable|numeric|min:0|max:100', 'default' => '18'],
        ['key' => 'crm.invoice_terms', 'label' => 'Default invoice terms', 'type' => 'textarea', 'rules' => 'nullable|max:2000', 'span' => true],
        ['key' => 'crm.quotation_terms', 'label' => 'Default quotation terms', 'type' => 'textarea', 'rules' => 'nullable|max:2000', 'span' => true]],
      'users' => [$pwd, $attempts, $idle],
      'appearance' => [
        ['key' => 'branding.app_name', 'label' => 'Application name', 'type' => 'text', 'rules' => 'required|max:60', 'section' => 'Brand', 'preview' => 'name'],
        ['key' => 'branding.tagline', 'label' => 'Tagline', 'type' => 'text', 'rules' => 'nullable|max:80', 'preview' => 'tagline'],
        ['key' => 'branding.login_headline', 'label' => 'Sign-in page headline', 'type' => 'text', 'rules' => 'nullable|max:140', 'span' => true, 'placeholder' => 'Clients, projects, people & payroll — beautifully in one place.'],
        ['key' => 'appearance.primary_color', 'label' => 'Primary colour', 'type' => 'color', 'section' => 'Colours', 'var' => '--primary'],
        ['key' => 'appearance.sidebar_color', 'label' => 'Sidebar colour', 'type' => 'color', 'var' => '--sidebar'],
        ['key' => 'appearance.accent_1', 'label' => 'Accent 1 (lavender)', 'type' => 'color', 'var' => '--lav'],
        ['key' => 'appearance.accent_2', 'label' => 'Accent 2 (mint)', 'type' => 'color', 'var' => '--mint'],
        ['key' => 'appearance.accent_3', 'label' => 'Accent 3 (lemon)', 'type' => 'color', 'var' => '--lemon'],
        ['key' => 'appearance.accent_4', 'label' => 'Accent 4 (peach)', 'type' => 'color', 'var' => '--peach'],
        ['key' => 'appearance.font_family', 'label' => 'Font family', 'type' => 'select', 'rules' => 'required', 'options' => self::FONTS, 'section' => 'Typography & shape', 'preview' => 'font'],
        ['key' => 'appearance.default_theme', 'label' => 'Default theme', 'type' => 'select', 'rules' => 'required', 'options' => ['system' => 'Follow device', 'light' => 'Light', 'dark' => 'Dark'], 'hint' => 'Users can override this from the top bar.'],
        $num('appearance.font_size', 'Base font size (px)', 12, 18, '', ['type' => 'range', 'var' => '--fs', 'unit' => 'px']),
        $num('appearance.radius', 'Corner radius (px)', 8, 32, '', ['type' => 'range', 'var' => '--r', 'unit' => 'px'])],
      'email' => [
        ['key' => 'email.driver', 'label' => 'Mail driver', 'type' => 'select', 'rules' => 'required', 'options' => ['mail' => 'PHP mail() — server default', 'smtp' => 'SMTP server'], 'section' => 'Delivery'],
        ['key' => 'email.notifications', 'label' => 'Send email notifications', 'type' => 'toggle', 'default' => '1', 'hint' => 'In-app notifications are always on.'],
        ['key' => 'email.smtp_host', 'label' => 'SMTP host', 'type' => 'text', 'rules' => 'nullable|max:190|regex:/^[A-Za-z0-9.\-]+$/', 'section' => 'SMTP', 'show' => 'smtp'],
        ['key' => 'email.smtp_port', 'label' => 'SMTP port', 'type' => 'number', 'rules' => 'nullable|integer|min:1|max:65535', 'default' => '587', 'show' => 'smtp'],
        ['key' => 'email.smtp_encryption', 'label' => 'Encryption', 'type' => 'select', 'options' => ['tls' => 'STARTTLS (587)', 'ssl' => 'SSL/TLS (465)', 'none' => 'None'], 'default' => 'tls', 'show' => 'smtp'],
        ['key' => 'email.smtp_username', 'label' => 'Username', 'type' => 'text', 'rules' => 'nullable|max:190', 'show' => 'smtp', 'autocomplete' => 'off'],
        ['key' => 'email.smtp_password', 'label' => 'Password', 'type' => 'secret', 'rules' => 'nullable|max:500', 'show' => 'smtp', 'span' => true],
        ['key' => 'email.from_address', 'label' => 'From address', 'type' => 'email', 'rules' => 'nullable|email|max:190', 'section' => 'Sender'],
        ['key' => 'email.from_name', 'label' => 'From name', 'type' => 'text', 'rules' => 'nullable|max:100']],
      'hr' => [
        ['key' => 'hr.work_hours_per_day', 'label' => 'Work hours per day', 'type' => 'number', 'rules' => 'required|numeric|min:1|max:24', 'step' => '0.5', 'section' => 'Work time'],
        $num('hr.grace_minutes', 'Late arrival grace (minutes)', 0, 120, 'Check-ins within this window are not marked late.', ['default' => '15']),
        ['key' => 'hr.leave_year_start', 'label' => 'Leave year starts in', 'type' => 'select', 'options' => $months, 'default' => '1', 'rules' => 'required', 'section' => 'Leave'],
        $num('hr.probation_months', 'Default probation (months)', 0, 24, '', ['default' => '6']),
        $num('attendance.idle_minutes', 'Idle detection after (minutes)', 1, 60, 'Desktop/browser tracker marks the user idle after this.', ['section' => 'Time tracking']),
        ['key' => 'attendance.screenshots_enabled', 'label' => 'Screenshot monitoring', 'type' => 'toggle', 'hint' => 'Only for users who have given consent.'],
        $num('attendance.screenshot_interval', 'Screenshot interval (minutes)', 1, 60),
        $num('attendance.screenshot_retention_days', 'Keep screenshots for (days)', 1, 365)],
      'payroll' => [
        ['key' => 'payroll.currency_label', 'label' => 'Currency label on payslips', 'type' => 'text', 'rules' => 'nullable|max:40', 'hint' => 'e.g. "Indian Rupees". Defaults to the currency code.'],
        $num('payroll.pay_day', 'Salary credit day of month', 1, 28, '', ['default' => '1']),
        ['key' => 'payroll.payslip_footer', 'label' => 'Payslip footer note', 'type' => 'textarea', 'rules' => 'nullable|max:500', 'span' => true, 'hint' => 'Printed at the bottom of every payslip.']],
      'storage' => [$num('storage.max_upload_mb', 'Maximum upload size (MB)', 1, 512, 'Cannot exceed the PHP limits shown below.')],
      'security' => [$idle, $pwd, $attempts, $num('security.audit_retention_days', 'Keep audit log for (days)', 30, 3650, 'Older entries are pruned nightly by cron.')],
      'backups' => [
        ['key' => 'backups.enabled', 'label' => 'Automatic daily backups', 'type' => 'toggle', 'default' => '1', 'span' => true],
        ['key' => 'backups.time', 'label' => 'Run at (server time)', 'type' => 'time', 'rules' => 'required|time', 'default' => '02:30'],
        $num('backups.keep', 'Keep the newest', 1, 90, 'Older successful backups are deleted automatically.', ['default' => '7']),
        ['key' => 'backups.include_uploads', 'label' => 'Include uploaded files & config (full backup)', 'type' => 'toggle', 'default' => '0', 'span' => true, 'hint' => 'Creates a .zip with the database, uploaded files and config/config.php.']],
      'cron' => [['key' => 'cron.web_enabled', 'label' => 'Allow web cron URL', 'type' => 'toggle', 'default' => '1', 'span' => true, 'hint' => 'Lets an external pinger run the scheduler through a secret URL when crontab is not available.']],
      'updates' => [
        ['key' => 'updates.repo', 'label' => 'GitHub repository', 'type' => 'text', 'rules' => 'nullable|max:140|regex:/^[A-Za-z0-9_.\-]+\/[A-Za-z0-9_.\-]+$/', 'hint' => 'owner/name, e.g. acme/niyanta'],
        ['key' => 'updates.channel', 'label' => 'Update channel', 'type' => 'select', 'rules' => 'required', 'options' => ['release' => 'Stable releases', 'commit' => 'Latest commit on branch'], 'default' => 'release'],
        ['key' => 'updates.branch', 'label' => 'Branch', 'type' => 'text', 'rules' => 'nullable|max:100|regex:/^[A-Za-z0-9_.\/\-]+$/', 'default' => 'main', 'hint' => 'Used by the commit channel.'],
        ['key' => 'updates.github_token', 'label' => 'GitHub access token', 'type' => 'secret', 'rules' => 'nullable|max:255|regex:/^[A-Za-z0-9_]+$/', 'hint' => 'Only needed for private repositories (read-only "contents" scope).']],
      default => [] }; }

  private static function sample(array $formats): array { $t = strtotime('2026-03-28 16:45'); $o = []; foreach ($formats as $f) { $o[$f] = date($f, $t); } return $o; }

  public static function value(array $f): mixed { return Settings::get($f['key'], self::DEFAULTS[$f['key']] ?? $f['default'] ?? null); }

  /**
   * Validate posted values for a tab. Returns [key => value] ready for Settings::setMany.
   * Secrets left blank keep their stored value (unless clear[key] is ticked).
   */
  public static function validate(string $tab, array $input, array $clear = []): array {
    $fields = self::fields($tab);
    $rules = []; $labels = []; $errors = [];
    foreach ($fields as $f) { if (!empty($f['rules']) && !in_array($f['type'], ['select', 'days', 'toggle'], true)) { $rules[$f['key']] = $f['rules']; $labels[$f['key']] = $f['label']; } }
    if (isset($input['general.currency_code']) && is_string($input['general.currency_code'])) { $input['general.currency_code'] = strtoupper(trim($input['general.currency_code'])); }
    try { $clean = Validator::make($input, $rules, $labels); }
    catch (ValidationException $e) { $clean = []; $errors = $e->errors; }
    $out = [];
    foreach ($fields as $f) {
      $k = $f['key']; $raw = $input[$k] ?? null;
      if (isset($errors[$k])) { continue; }
      switch ($f['type']) {
        case 'toggle': $out[$k] = in_array($raw, ['1', 'on', 'true', 1, true], true) ? '1' : '0'; break;
        case 'select':
          $v = is_string($raw) ? $raw : '';
          if (!array_key_exists($v, $f['options']) || ($v === '' && str_contains($f['rules'] ?? '', 'required'))) { $errors[$k] = $f['label'].' is invalid.'; break; }
          $out[$k] = (string) $v; break;
        case 'days':
          $d = array_values(array_unique(array_filter(array_map('strval', is_array($raw) ? $raw : []), static fn ($x) => isset(self::DAYS[$x]))));
          sort($d);
          if (count($d) > 3) { $errors[$k] = 'Choose at most three weekend days.'; break; }
          $out[$k] = implode(',', $d); break;
        case 'color':
          $v = is_string($raw) ? strtolower(trim($raw)) : '';
          if (!preg_match('/^#[0-9a-f]{6}$/', $v)) { $errors[$k] = $f['label'].' must be a hex colour like #7c5cff.'; break; }
          $out[$k] = $v; break;
        case 'secret':
          if (!empty($clear[$k])) { $out[$k] = null; break; }
          if (($clean[$k] ?? null) !== null && $clean[$k] !== '') { $out[$k] = (string) $clean[$k]; }
          break;
        default: $v = $clean[$k] ?? null; $out[$k] = $v === null ? '' : (string) $v; } }
    if ($errors) { throw new ValidationException($errors); }
    return $out; } }
