<?php
/**
 * Niyanta test runner (no PHPUnit needed): php tests/run.php [filter]
 * Recreates the disposable database from tests/config.php, installs fresh, then runs
 * tests/*Test.php and modules/<Name>/tests/*Test.php. Each test file returns ['name' => fn() => void].
 */
use Core\{App, DB, Migrator, Installer, Modules, Cache};
if (PHP_SAPI !== 'cli') { exit(1); }
require __DIR__.'/../core/bootstrap.php';
$cfgFile = __DIR__.'/config.php';
if (!is_file($cfgFile)) { fwrite(STDERR, "Create tests/config.php (see tests/config.example.php)\n"); exit(1); }
$db = require $cfgFile;
if (!str_contains($db['name'], 'test') && !str_contains($db['name'], 'unit')) { fwrite(STDERR, "Refusing: test database name must contain 'test' or 'unit'.\n"); exit(1); }
define('NY_TESTING', true);
$_SERVER += ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_USER_AGENT' => 'niyanta-tests', 'REQUEST_METHOD' => 'GET', 'REQUEST_URI' => '/', 'SCRIPT_NAME' => '/index.php', 'HTTP_HOST' => 'localhost'];
App::setConfig(['app_key' => \Core\Crypto::generateKey(), 'app_url' => 'http://localhost', 'debug' => true, 'timezone' => 'UTC', 'db' => $db]);
date_default_timezone_set('UTC');
DB::init($db);
$pdo = DB::pdo();
$pdo->exec('SET FOREIGN_KEY_CHECKS=0');
foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $t) { $pdo->exec('DROP TABLE `'.$t.'`'); }
$pdo->exec('SET FOREIGN_KEY_CHECKS=1');
Cache::flush();
Migrator::ensureTable();
Migrator::migrate();
$GLOBALS['ADMIN_ID'] = Installer::seed(['app_name' => 'Niyanta Test', 'timezone' => 'UTC', 'currency_symbol' => '₹', 'name' => 'Test Admin', 'email' => 'admin@unit.test', 'password' => 'Admin12345']);
Cache::flush();
Modules::load();

/** Helpers available to tests. */
function assert_true(bool $c, string $m = 'Assertion failed'): void { if (!$c) { throw new RuntimeException($m); } }
function assert_eq(mixed $exp, mixed $act, string $m = ''): void { if ($exp != $act) { throw new RuntimeException(($m ? "$m: " : '').'expected '.var_export($exp, true).', got '.var_export($act, true)); } }
function assert_throws(string $cls, callable $fn, string $m = ''): void { try { $fn(); } catch (Throwable $e) { if ($e instanceof $cls) { return; } throw new RuntimeException("$m expected $cls, got ".$e::class.': '.$e->getMessage()); } throw new RuntimeException("$m expected $cls, nothing thrown"); }
/** Create a user with a role slug; returns the user id. */
function make_user(string $role, string $email, array $extra = []): int {
  return DB::insert('users', $extra + ['role_id' => (int) DB::value('SELECT id FROM roles WHERE slug=?', [$role]), 'name' => ucfirst($role).' '.substr(md5($email), 0, 4), 'email' => $email, 'password_hash' => password_hash('Passw0rd123', PASSWORD_DEFAULT), 'status' => 'active', 'created_at' => date('Y-m-d H:i:s')]); }
/** Act as a user for the rest of the test (no HTTP session in CLI). */
function act_as(?int $uid): void {
  $_SESSION = $uid ? ['uid' => $uid, 'sv' => (int) DB::value('SELECT session_version FROM users WHERE id=?', [$uid]), 'fp' => hash('sha256', $_SERVER['HTTP_USER_AGENT']), 'last_seen' => time(), 'login_at' => time(), '_csrf' => 'test-token'] : ['_csrf' => 'test-token'];
  \Core\Auth::forget(); \Core\Gate::flush(); }
/** Dispatch a fake HTTP request through the full router; returns Core\Response. */
function http(string $method, string $path, array $post = [], bool $csrf = true): Core\Response {
  $_SERVER['REQUEST_METHOD'] = $method; $_SERVER['REQUEST_URI'] = $path; $_GET = []; $_POST = $post + ($csrf && $method === 'POST' ? ['_token' => 'test-token'] : []);
  parse_str((string) parse_url($path, PHP_URL_QUERY), $_GET);
  $req = Core\Request::capture();
  $r = App::resetRouter();
  require NY_ROOT.'/core/routes.php';
  Modules::registerRoutes($r);
  try { return $r->dispatch($req); }
  catch (Throwable $e) { return new Core\Response($e::class.': '.$e->getMessage(), 599); } }

$filter = $argv[1] ?? '';
$files = array_merge(glob(__DIR__.'/*Test.php') ?: [], glob(NY_ROOT.'/modules/*/tests/*Test.php') ?: []);
$pass = 0; $fail = 0;
foreach ($files as $f) {
  foreach ((require $f) as $name => $fn) {
    $label = str_replace([NY_ROOT.'/', 'Test.php'], '', $f).' › '.$name;
    if ($filter && !str_contains(strtolower($label), strtolower($filter))) { continue; }
    act_as(null);
    try { $fn(); $pass++; echo "\033[32m✔\033[0m $label\n"; }
    catch (Throwable $e) { $fail++; echo "\033[31m✘ $label\n   ".$e->getMessage()." (".basename($e->getFile()).':'.$e->getLine().")\033[0m\n"; } } }
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
