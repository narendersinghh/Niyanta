<?php
use Core\{Auth, Crypto, DB, Gate, Migrator, Modules, Pdf, RateLimiter, Settings, Validator, ValidationException, Cron, Storage};

return [
  'migrations are fully applied and idempotent' => function () { assert_eq(0, count(Migrator::pending())); assert_eq([], Migrator::migrate()); },
  'installer seeded roles, permissions and super admin' => function () {
    assert_eq(4, (int) DB::value('SELECT COUNT(*) FROM roles'));
    assert_true((int) DB::value('SELECT COUNT(*) FROM permissions') > 30, 'permissions seeded');
    assert_eq('super_admin', DB::value('SELECT r.slug FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=?', [$GLOBALS['ADMIN_ID']])); },
  'validator accepts, normalises and rejects input' => function () {
    $d = Validator::make(['email' => ' A@B.CO ', 'n' => '5', 'on' => 'on', 'dt' => '2026-01-02T10:30'], ['email' => 'required|email', 'n' => 'integer|min:1|max:10', 'on' => 'boolean', 'dt' => 'datetime', 'x' => 'nullable']);
    assert_eq('a@b.co', $d['email']); assert_eq(5, $d['n']); assert_eq(1, $d['on']); assert_eq('2026-01-02 10:30:00', $d['dt']); assert_eq(null, $d['x']);
    assert_throws(ValidationException::class, fn () => Validator::make(['email' => 'nope'], ['email' => 'required|email']));
    assert_throws(ValidationException::class, fn () => Validator::make(['d' => '2026-02-30'], ['d' => 'date']));
    assert_throws(ValidationException::class, fn () => Validator::make(['s' => 'x'], ['s' => 'in:a,b']));
    assert_throws(ValidationException::class, fn () => Validator::make(['email' => 'admin@unit.test'], ['email' => 'unique:users,email'])); },
  'crypto round-trips and rejects tampering' => function () {
    $c = Crypto::encrypt('secret-value');
    assert_true(str_starts_with($c, 'enc:') && !str_contains($c, 'secret-value'));
    assert_eq('secret-value', Crypto::decrypt($c));
    $b = base64_decode(substr($c, 4)); $b[strlen($b) - 1] = chr(ord($b[strlen($b) - 1]) ^ 1);
    assert_eq(null, Crypto::decrypt('enc:'.base64_encode($b))); },
  'secret settings are encrypted at rest' => function () {
    Settings::set('email.smtp_password', 'sk-test-123');
    assert_true(!str_contains((string) DB::value("SELECT `value` FROM settings WHERE `key`='email.smtp_password'"), 'sk-test'), 'stored encrypted');
    assert_eq('sk-test-123', Settings::get('email.smtp_password')); },
  'login succeeds, wrong password fails, account locks after repeated failures' => function () {
    $id = make_user('employee', 'login@unit.test');
    assert_eq('Invalid email or password.', Auth::attempt('login@unit.test', 'wrong'));
    assert_eq(1, (int) DB::value('SELECT failed_logins FROM users WHERE id=?', [$id]));
    assert_eq(null, Auth::attempt('login@unit.test', 'Passw0rd123'));
    assert_eq(0, (int) DB::value('SELECT failed_logins FROM users WHERE id=?', [$id]));
    for ($i = 0; $i < 5; $i++) { Auth::attempt('login@unit.test', 'bad'); }
    assert_true(str_contains((string) Auth::attempt('login@unit.test', 'Passw0rd123'), 'Too many attempts'), 'rate limited');
    RateLimiter::clear('login:login@unit.test|127.0.0.1'); },
  'inactive users cannot sign in and bumping sessions logs users out' => function () {
    $id = make_user('employee', 'inactive@unit.test', ['status' => 'inactive']);
    assert_true(Auth::attempt('inactive@unit.test', 'Passw0rd123') !== null);
    $id2 = make_user('employee', 'bump@unit.test');
    act_as($id2); assert_true(Auth::check());
    Auth::bumpSessions($id2); Auth::forget(); assert_true(!Auth::check(), 'session invalidated'); },
  'password policy is enforced' => function () { assert_true(Auth::validatePassword('short') !== null); assert_true(Auth::validatePassword('longpasswordonly') !== null); assert_eq(null, Auth::validatePassword('Longer12345')); },
  'gate: super admin has everything, roles get defaults, disabled module revokes' => function () {
    $mgr = make_user('manager', 'mgr@unit.test'); $emp = make_user('employee', 'emp@unit.test');
    act_as($GLOBALS['ADMIN_ID']); assert_true(Gate::allows('crm.clients.delete') && Gate::allows('__super_admin'));
    act_as($mgr); assert_true(Gate::allows('crm.clients.manage')); assert_true(!Gate::allows('__super_admin')); assert_true(!Gate::allows('payroll.manage'));
    act_as($emp); assert_true(!Gate::allows('crm.clients.view')); assert_true(Gate::allows('attendance.track'));
    act_as($mgr); Modules::setEnabled('crm', false); assert_true(!Gate::allows('crm.clients.manage'), 'disabled module denies');
    Modules::setEnabled('crm', true); assert_true(Gate::allows('crm.clients.manage')); },
  'router: guests are redirected to login' => function () { $r = http('GET', '/admin/users'); assert_eq(302, $r->status()); assert_true(str_contains($r->headers()['Location'], '/login')); },
  'router: missing permission returns 403' => function () { act_as(make_user('employee', 'r403@unit.test')); assert_eq(403, http('GET', '/admin/roles')->status()); assert_eq(403, http('GET', '/admin/users')->status()); },
  'router: POST without CSRF token returns 419' => function () { act_as($GLOBALS['ADMIN_ID']); assert_eq(419, http('POST', '/admin/roles', ['name' => 'X'], false)->status()); },
  'router: disabled module routes are blocked but data kept' => function () {
    act_as($GLOBALS['ADMIN_ID']);
    $before = (int) DB::value('SELECT COUNT(*) FROM clients');
    Modules::setEnabled('crm', false);
    $code = http('GET', '/clients')->status();
    Modules::setEnabled('crm', true);
    assert_true(in_array($code, [403, 404], true), "got $code");
    assert_eq($before, (int) DB::value('SELECT COUNT(*) FROM clients')); },
  'super admin pages render' => function () { act_as($GLOBALS['ADMIN_ID']); foreach (['/', '/admin/users', '/admin/roles', '/admin/settings/features', '/account', '/notifications', '/search?q=te'] as $p) { assert_eq(200, http('GET', $p)->status(), $p); } },
  'HR cannot create or edit a super admin' => function () {
    act_as(make_user('hr', 'hr@unit.test'));
    $sa = (int) DB::value("SELECT id FROM roles WHERE slug='super_admin'");
    assert_eq(403, http('POST', '/admin/users', ['name' => 'Evil', 'email' => 'evil@unit.test', 'role_id' => $sa, 'status' => 'active', 'password' => 'Abcdef12345'])->status());
    assert_eq(0, (int) DB::value("SELECT COUNT(*) FROM users WHERE email='evil@unit.test'"));
    assert_eq(403, http('GET', '/admin/users/'.$GLOBALS['ADMIN_ID'].'/edit')->status()); },
  'permission matrix cannot grant unknown or super-admin-only keys' => function () {
    act_as($GLOBALS['ADMIN_ID']);
    $before = DB::all('SELECT role_id, permission FROM role_permissions');
    $emp = (int) DB::value("SELECT id FROM roles WHERE slug='employee'");
    http('POST', '/admin/roles/permissions', ['grants' => [$emp => ['__super_admin' => 1, 'attendance.track' => 1]]]);
    $after = DB::column('SELECT permission FROM role_permissions WHERE role_id=?', [$emp]);
    DB::delete('role_permissions', '1=1'); DB::insertMany('role_permissions', $before); Gate::flush();
    assert_eq(['attendance.track'], $after); },
  'rate limiter enforces window' => function () { RateLimiter::clear('t'); for ($i = 0; $i < 3; $i++) { assert_true(RateLimiter::hit('t', 3, 60)); } assert_true(!RateLimiter::hit('t', 3, 60)); },
  'pdf writer produces a valid document' => function () {
    $p = new Pdf('Test'); $p->font(true, 16)->text(40, 60, 'Invoice ₹ 1,000 (test)'); $p->rect(40, 80, 100, 20, '#7c5cff')->line(40, 120, 300, 120); $p->addPage()->text(40, 40, 'Page 2');
    $o = $p->output();
    assert_true(str_starts_with($o, '%PDF-1.4') && str_ends_with($o, '%%EOF'));
    preg_match('/startxref\n(\d+)/', $o, $m); assert_eq('xref', substr($o, (int) $m[1], 4), 'xref offset'); assert_true(str_contains($o, '/Count 2')); },
  'cron prevents overlapping runs' => function () {
    $fp = fopen(NY_ROOT.'/storage/cache/cron.lock', 'c'); flock($fp, LOCK_EX);
    $r = Cron::run(true, 'core.cleanup'); flock($fp, LOCK_UN); fclose($fp);
    assert_true(isset($r['skipped']));
    assert_eq('success', Cron::run(true, 'core.cleanup')['core.cleanup'] ?? null); },
  'uploads reject disallowed or spoofed files' => function () {
    $tmp = tempnam(sys_get_temp_dir(), 'up'); file_put_contents($tmp, '<?php echo 1;');
    assert_throws(ValidationException::class, fn () => Storage::store(['name' => 'x.php', 'tmp_name' => $tmp, 'error' => 0, 'size' => 13], 'test'));
    assert_throws(ValidationException::class, fn () => Storage::store(['name' => 'x.pdf', 'tmp_name' => $tmp, 'error' => 0, 'size' => 13], 'test'), 'spoofed pdf');
    file_put_contents($tmp, "hello,world\n"); $s = Storage::store(['name' => 'ok.csv', 'tmp_name' => $tmp, 'error' => 0, 'size' => 12], 'test');
    assert_true(is_file(Storage::path($s['path']))); @unlink(Storage::path($s['path'])); },
  'features: disabling a feature or bundle blocks only its permissions and routes' => function () {
    act_as($GLOBALS['ADMIN_ID']);
    \Core\Features::set('feature:quotations', false);
    assert_true(!Gate::allows('crm.quotations.view') && Gate::allows('crm.finance.view'), 'only quotations off');
    assert_eq(403, http('GET', '/quotations')->status());
    \Core\Features::set('feature:quotations', true);
    \Core\Features::set('bundle:clients', false);
    assert_true(!Gate::allows('crm.finance.view') && !Modules::enabled('crm') && Gate::allows('projects.view'), 'bundle off');
    \Core\Features::set('bundle:clients', true);
    assert_true(Modules::enabled('crm')); },
  'error log groups repeats and captures browser errors' => function () {
    \Core\ErrorLog::record('server', 'error', 'Boom 12345', 'core/X.php', 10);
    \Core\ErrorLog::record('server', 'error', 'Boom 67890', 'core/X.php', 10);
    assert_eq(2, (int) DB::value("SELECT occurrences FROM error_logs WHERE message LIKE 'Boom%'"), 'numbers normalised into one group');
    http('POST', '/errors/client', ['message' => 'TypeError: x is undefined', 'source' => '/assets/js/app.js', 'line' => 3], false);
    assert_eq(1, (int) DB::value("SELECT COUNT(*) FROM error_logs WHERE source='browser'"));
    act_as($GLOBALS['ADMIN_ID']); assert_eq(200, http('GET', '/admin/audit/errors')->status()); },
  'audit log records actions' => function () { act_as($GLOBALS['ADMIN_ID']); \Core\Audit::log('test.action', 'thing', 7, ['a' => 1]); assert_eq(1, (int) DB::value("SELECT COUNT(*) FROM audit_logs WHERE action='test.action' AND entity_id=7")); },
];
