<?php
use Core\{Cron, DB, Settings, ValidationException};
use Modules\Admin\Services\{BackupService, DemoData, SettingsSchema, UpdateService};

return [
  'system admin routes are super admin only' => function () {
    foreach (['manager', 'hr'] as $role) {
      act_as(make_user($role, $role.'@adminunit.test'));
      foreach (['/admin/settings', '/admin/settings/general', '/admin/backups', '/admin/updates', '/admin/cron', '/admin/audit'] as $p) { assert_eq(403, http('GET', $p)->status(), "$role GET $p"); }
      foreach (['/admin/settings/general', '/admin/backups', '/admin/updates/migrate', '/admin/cron/token', '/admin/settings/security/logout-all'] as $p) { assert_eq(403, http('POST', $p)->status(), "$role POST $p"); } }
    act_as($GLOBALS['ADMIN_ID']);
    assert_eq(200, http('GET', '/admin/settings')->status(), 'super admin overview');
    assert_eq(200, http('GET', '/admin/audit')->status(), 'super admin audit'); },
  'settings schema validates and keeps secrets' => function () {
    assert_throws(ValidationException::class, fn () => SettingsSchema::validate('appearance', ['appearance.primary_color' => 'red']), 'bad colour');
    Settings::set('email.smtp_password', 'keep-me');
    $v = SettingsSchema::validate('email', ['email.driver' => 'smtp', 'email.smtp_encryption' => 'tls', 'email.smtp_password' => '']);
    assert_true(!array_key_exists('email.smtp_password', $v), 'blank secret is not saved');
    $v = SettingsSchema::validate('email', ['email.driver' => 'smtp', 'email.smtp_encryption' => 'tls'], ['email.smtp_password' => 1]);
    assert_true(array_key_exists('email.smtp_password', $v) && $v['email.smtp_password'] === null, 'clear removes secret');
    $g = SettingsSchema::validate('general', ['general.timezone' => 'UTC', 'general.date_format' => 'd M Y', 'general.time_format' => 'H:i', 'general.week_start' => '1', 'general.currency_symbol' => '$', 'general.currency_code' => 'usd', 'general.thousand_sep' => '']);
    assert_eq('USD', $g['general.currency_code']); assert_eq('', $g['general.thousand_sep']);
    $w = SettingsSchema::validate('employees', ['hr.work_hours_per_day' => '8', 'general.weekend_days' => ['7', '6', '9']]);
    assert_eq('6,7', $w['general.weekend_days']);
    $e = SettingsSchema::validate('email', ['email.provider' => 'mail']);
    assert_eq('mail', $e['email.driver'], 'PHP mail() preset selects the mail driver');
    assert_throws(ValidationException::class, fn () => SettingsSchema::validate('email', ['email.provider' => 'nope']), 'unknown provider'); },
  'appearance save stores only real overrides' => function () {
    $in = ['branding.app_name' => 'X', 'appearance.font_family' => 'Inter', 'appearance.default_theme' => 'system', 'appearance.font_size' => '14', 'appearance.radius' => '22'];
    foreach (SettingsSchema::THEME_KEYS as $k) { if (str_contains($k, 'color') || str_contains($k, 'accent')) { $in[$k] = strtoupper(SettingsSchema::DEFAULTS[$k]); } }
    $in['appearance.accent_4'] = '#123456';
    $v = SettingsSchema::validate('appearance', $in);
    assert_true(array_key_exists('appearance.primary_color', $v) && $v['appearance.primary_color'] === null, 'default primary becomes null');
    assert_true($v['appearance.radius'] === null && $v['appearance.font_size'] === null, 'default size/radius become null');
    assert_eq('#123456', $v['appearance.accent_4'], 'override kept');
    act_as($GLOBALS['ADMIN_ID']);
    Settings::setMany(['appearance.primary_color' => '#5b4cf5', 'appearance.accent_1' => '#abcdef']);
    assert_eq(302, http('POST', '/admin/settings/appearance/reset')->status(), 'reset theme');
    assert_true(Settings::get('appearance.primary_color') === null && Settings::get('appearance.accent_1') === null, 'theme overrides removed'); },
  'settings pages render for super admin' => function () {
    act_as($GLOBALS['ADMIN_ID']);
    foreach (array_keys(SettingsSchema::tabs()) as $t) { $r = http('GET', '/admin/settings/'.$t); assert_true(in_array($r->status(), [200, 302], true), "tab $t: ".$r->status()); }
    assert_eq(200, http('GET', '/admin/settings')->status(), 'settings home');
    assert_eq(302, http('GET', '/admin/settings/security')->status(), 'moved tab redirects');
    foreach (['/admin/users', '/admin/roles', '/admin/backups', '/admin/cron', '/admin/updates'] as $p) { assert_eq(200, http('GET', $p)->status(), $p); } },
  'retention cleanup honours settings and legacy keys' => function () {
    $old = static fn (int $d) => date('Y-m-d H:i:s', strtotime("-$d days"));
    DB::query('DELETE FROM audit_logs'); DB::query('DELETE FROM cron_logs'); DB::query('DELETE FROM mail_queue');
    $uid = $GLOBALS['ADMIN_ID'];
    DB::insertMany('audit_logs', [['user_id' => $uid, 'action' => 'unit.old', 'created_at' => $old(400)], ['user_id' => $uid, 'action' => 'unit.mid', 'created_at' => $old(40)], ['user_id' => $uid, 'action' => 'unit.new', 'created_at' => $old(1)]]);
    DB::insertMany('notifications', [['user_id' => $uid, 'title' => 'old read', 'read_at' => $old(100), 'created_at' => $old(100)], ['user_id' => $uid, 'title' => 'old unread', 'read_at' => null, 'created_at' => $old(100)]]);
    DB::insertMany('cron_logs', [['job' => 'x', 'status' => 'success', 'ran_at' => $old(45)], ['job' => 'x', 'status' => 'success', 'ran_at' => $old(2)]]);
    DB::insertMany('mail_queue', [['to_email' => 'a@b.c', 'subject' => 's', 'body_html' => 'b', 'status' => 'sent', 'created_at' => $old(50), 'sent_at' => $old(50)], ['to_email' => 'a@b.c', 'subject' => 's', 'body_html' => 'b', 'status' => 'failed', 'created_at' => $old(50), 'sent_at' => null], ['to_email' => 'a@b.c', 'subject' => 's', 'body_html' => 'b', 'status' => 'pending', 'created_at' => $old(50), 'sent_at' => null]]);
    Settings::setMany(['retention.audit_days' => '365', 'retention.notifications_days' => '90', 'retention.cron_logs_days' => '30', 'retention.mail_queue_days' => '30']);
    $c = Cron::prune();
    assert_eq(1, $c['audit'], 'audit pruned'); assert_eq(1, $c['notifications'], 'only read notifications'); assert_eq(1, $c['cron_logs']); assert_eq(2, $c['mail_queue'], 'sent+failed, pending kept');
    assert_eq(1, (int) DB::value("SELECT COUNT(*) FROM mail_queue WHERE status='pending'"));
    Settings::setMany(['retention.audit_days' => null, 'security.audit_retention_days' => '30']);
    assert_eq(1, Cron::prune()['audit'], 'legacy security.audit_retention_days still honoured');
    Settings::set('retention.audit_days', '0');
    DB::insert('audit_logs', ['user_id' => $uid, 'action' => 'unit.ancient', 'created_at' => $old(5000)]);
    assert_true(!isset(Cron::prune()['audit']), '0 keeps forever');
    assert_eq(1, (int) DB::value("SELECT COUNT(*) FROM audit_logs WHERE action='unit.ancient'"));
    assert_true(str_starts_with(Cron::cleanup(), 'removed '), 'cleanup summary');
    assert_true(isset(Settings::json('retention.last_cleanup')['total']), 'last cleanup remembered'); },
  'demo data imports and deletes cleanly' => function () {
    $tables = DB::column('SHOW TABLES');
    $snap = static function () use ($tables): array { $o = []; foreach ($tables as $t) { if (!in_array($t, ['audit_logs', 'settings', 'demo_records', 'rate_limits', 'cron_logs'], true)) { $o[$t] = (int) DB::value('SELECT COUNT(*) FROM `'.$t.'`'); } } return $o; };
    $before = $snap();
    $c = DemoData::import($GLOBALS['ADMIN_ID']);
    assert_eq(8, (int) DB::value('SELECT COUNT(*) FROM users WHERE email LIKE ?', ['%@'.DemoData::DOMAIN]), 'demo users');
    foreach (['clients' => 6, 'projects' => 5, 'holidays' => 20] as $t => $n) { assert_eq($n, $c[$t] ?? 0, "demo $t"); }
    assert_true(($c['tasks'] ?? 0) >= 35 && ($c['attendance'] ?? 0) > 50 && ($c['time_entries'] ?? 0) > 50 && ($c['invoices'] ?? 0) >= 6, 'bulk data');
    assert_true((int) DB::value("SELECT COUNT(*) FROM invoices WHERE status='overdue' AND number LIKE 'DEMO-%'") >= 1, 'overdue invoices');
    assert_eq(1, (int) DB::value("SELECT COUNT(*) FROM employee_profiles ep JOIN users u ON u.id=ep.user_id WHERE u.email=? AND ep.weekly_off_days='7'", ['karan@'.DemoData::DOMAIN]), 'six-day week employee');
    $paths = DB::column("SELECT disk_path FROM files WHERE entity_type='company_doc'");
    assert_true($paths && is_file(\Core\Storage::path($paths[0])), 'company document file written');
    assert_eq(array_sum($c), (int) DB::value('SELECT COUNT(*) FROM demo_records'), 'every row recorded');
    assert_throws(DomainException::class, fn () => DemoData::import($GLOBALS['ADMIN_ID']), 'second import refused');
    act_as($GLOBALS['ADMIN_ID']);
    foreach (['/admin/settings/demo', '/admin/users', '/clients', '/projects', '/invoices', '/hr/employees', '/timesheets/approvals', '/payroll'] as $p) { $s = http('GET', $p)->status(); assert_true(in_array($s, [200, 302], true), "$p after import: $s"); }
    assert_eq(302, http('POST', '/admin/settings/demo/delete', ['confirm' => 'nope'])->status());
    assert_true(DemoData::exists(), 'wrong confirmation keeps data');
    assert_eq(302, http('POST', '/admin/settings/demo/delete', ['confirm' => 'DELETE'])->status());
    assert_true(!DemoData::exists(), 'demo records cleared');
    assert_eq($before, $snap(), 'row counts back to the previous values');
    foreach ($paths as $p) { assert_true(!is_file(\Core\Storage::path($p)), 'file removed from disk'); } },
  'sql statement splitter honours quotes and comments' => function () {
    $f = sys_get_temp_dir().'/ny-split-'.bin2hex(random_bytes(4)).'.sql.gz';
    file_put_contents($f, gzencode("-- comment; here\nSET NAMES utf8mb4;\nINSERT INTO t VALUES ('a;b','it''s','x\\'y;'),(\"q;\");\n/* block; */ CREATE TABLE `we;ird` (id INT);\n# hash; comment\nSELECT 1"));
    $s = iterator_to_array(BackupService::statements($f), false);
    @unlink($f);
    assert_eq(4, count($s), 'statement count');
    assert_eq("INSERT INTO t VALUES ('a;b','it''s','x\\'y;'),(\"q;\")", $s[1]);
    assert_true(str_contains($s[2], 'CREATE TABLE `we;ird`')); },
  'backup and restore round trip' => function () {
    $b = BackupService::create('database', 'unit');
    assert_eq('success', $b['status'], 'backup ok');
    $before = DB::value("SELECT GROUP_CONCAT(name ORDER BY id) FROM roles");
    DB::query("UPDATE roles SET name=CONCAT(name,'!')");
    $r = BackupService::restore($b);
    assert_true($r['ok'], 'restore ok: '.($r['error'] ?? ''));
    assert_eq($before, DB::value("SELECT GROUP_CONCAT(name ORDER BY id) FROM roles"), 'data restored');
    assert_true(!is_file(NY_ROOT.'/storage/maintenance.flag'), 'maintenance off');
    foreach (DB::all("SELECT * FROM backups WHERE note='unit' OR note LIKE 'Safety backup before restoring%'") as $x) { BackupService::delete($x); } },
  'update packages are inspected safely' => function () {
    $dir = sys_get_temp_dir();
    $ok = $dir.'/ny-pkg-'.bin2hex(random_bytes(4)).'.zip';
    $z = new ZipArchive(); $z->open($ok, ZipArchive::CREATE);
    $z->addFromString('niyanta-9.9.9/niyanta.json', json_encode(['name' => 'niyanta', 'version' => '9.9.9', 'min_php' => '8.2.0']));
    $z->addFromString('niyanta-9.9.9/core/version.php', "<?php\ndefine('NY_VERSION', '9.9.9');\n");
    $z->addFromString('niyanta-9.9.9/index.php', '<?php');
    $z->close();
    $i = UpdateService::inspect($ok);
    assert_eq('niyanta-9.9.9/', $i['prefix']); assert_eq('9.9.9', $i['version']);
    $z->open($ok); $z->addFromString('niyanta-9.9.9/../../evil.php', 'x'); $z->close();
    assert_throws(RuntimeException::class, fn () => UpdateService::inspect($ok), 'zip slip');
    @unlink($ok); },
];
