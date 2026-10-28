<?php
use Core\{DB, Settings, ValidationException};
use Modules\Admin\Services\{BackupService, SettingsSchema, UpdateService};

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
    $g = SettingsSchema::validate('general', ['general.timezone' => 'UTC', 'general.date_format' => 'd M Y', 'general.time_format' => 'H:i', 'general.week_start' => '1', 'general.currency_symbol' => '$', 'general.currency_code' => 'usd', 'general.thousand_sep' => '', 'general.weekend_days' => ['7', '6', '9']]);
    assert_eq('USD', $g['general.currency_code']); assert_eq('6,7', $g['general.weekend_days']); assert_eq('', $g['general.thousand_sep']); },
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
