<?php
/**
 * AI module tests (no network): task JSON parsing, NL filter validation, PII redaction, AiClient with a fake provider
 * (redaction before send, usage logging, error logging, budget blocking) and scoped NL search.
 * Run: php modules/Ai/tests/ai_test.php  , everything runs inside a DB transaction that is rolled back.
 */
require dirname(__DIR__, 3).'/core/bootstrap.php';
use Core\{App, DB, Settings};
use Modules\Ai\Services\{AiClient, AiException, Json, NlSearch, ProviderInterface, Redactor, Scope};

App::boot();
$pass = 0; $fail = 0;
$ok = static function (bool $c, string $name) use (&$pass, &$fail): void { if ($c) { $pass++; echo "  \u{2713} $name\n"; } else { $fail++; echo "  \u{2717} $name\n"; } };
$throws = static function (callable $fn): ?string { try { $fn(); return null; } catch (AiException $e) { return $e->getMessage(); } };

final class FakeProvider implements ProviderInterface {
  public array $calls = [];
  public function __construct(private string|\Closure $reply, private int $in = 11, private int $out = 7) {}
  public function name(): string { return 'fake'; }
  public function model(): string { return 'fake-1'; }
  public function complete(array $messages, array $opts): array {
    $this->calls[] = ['messages' => $messages, 'opts' => $opts];
    $text = is_string($this->reply) ? $this->reply : ($this->reply)($messages, $opts);
    return ['text' => $text, 'input_tokens' => $this->in, 'output_tokens' => $this->out, 'model' => 'fake-1']; } }

echo "Task JSON parsing\n";
$t = Json::tasks("Sure! Here you go:\n```json\n{\"tasks\":[{\"title\":\"Draft homepage copy\",\"description\":\"Hero + 3 sections\",\"priority\":\"HIGH\",\"due_in_days\":3},{\"title\":\"<b>QA</b> forms\",\"priority\":\"critical\",\"due_in_days\":\"999\"},{\"title\":\"x\"},{\"description\":\"no title\"}]}\n```\nLet me know!");
$ok(count($t) === 2, 'fenced JSON with prose: 2 valid tasks kept, invalid dropped');
$ok($t[0]['priority'] === 'high' && $t[0]['due_in_days'] === 3, 'priority normalised, due_in_days kept');
$ok($t[1]['title'] === 'QA forms' && $t[1]['priority'] === 'urgent' && $t[1]['due_in_days'] === 365, 'tags stripped, synonym priority mapped, due clamped');
$t = Json::tasks('[{"title":"Call client","priority":"whatever","due_in_days":null},"Send invoice",]');
$ok(count($t) === 2 && $t[0]['priority'] === 'medium' && $t[0]['due_in_days'] === null && $t[1]['title'] === 'Send invoice', 'bare list, string items, trailing comma, unknown priority -> medium');
$ok(count(Json::tasks(json_encode(['tasks' => array_fill(0, 40, ['title' => 'Task'])]), 15)) === 15, 'task list capped at limit');
$ok($throws(static fn () => Json::tasks('I could not find tasks, sorry.')) !== null, 'prose without JSON -> friendly error');
$ok($throws(static fn () => Json::tasks('{"tasks":[]}')) !== null, 'empty task list -> friendly error');
$ok($throws(static fn () => Json::tasks('{"title":"single object"}')) !== null, 'non-list object -> friendly error');

echo "NL filter validation\n";
$f = Json::filters('```json {"entity":"invoice","keywords":"Acme; DROP TABLE users--","status":"overdue","date_from":"2026-10-31","date_to":"2026-10-01","assignee_me":"true","overdue":1,"sql":"SELECT * FROM users"} ```');
$ok($f['entity'] === 'invoices', 'entity synonym mapped to whitelist');
$ok(!str_contains($f['keywords'], ';') && $f['keywords'] === 'Acme DROP TABLE users--', 'keywords stripped of SQL punctuation (always bound as params anyway)');
$ok($f['date_from'] === '2026-10-01' && $f['date_to'] === '2026-10-31', 'reversed date range swapped');
$ok($f['assignee_me'] === true && $f['overdue'] === true && !isset($f['sql']), 'booleans coerced, unknown keys dropped');
$f = Json::filters(['entity' => 'tasks', 'status' => 'paid', 'date_from' => '2026-02-30', 'date_to' => 'yesterday', 'keywords' => ['logo', 'banner']]);
$ok($f['status'] === null && $f['date_from'] === null && $f['date_to'] === null && $f['keywords'] === 'logo banner', 'status not valid for entity / invalid dates -> null, array keywords joined');
$ok($throws(static fn () => Json::filters('{"entity":"salaries"}')) !== null, 'non-whitelisted entity rejected');
$ok($throws(static fn () => Json::filters('no json here')) !== null, 'unparseable spec rejected');

echo "PII redaction\n";
$r = Redactor::redact("Mail priya.s@acme.co.in or call +91 98765 43210 / (022) 2345-6789. A/C 123456789012, IFSC HDFC0001234, PAN ABCPE1234F, GSTIN 27AAPFU0939F1ZV, card 4111 1111 1111 1111. Invoice INV-2026-0001 of Rs 1,25,000.50 due 2026-11-05 (05/11/2026), 3 tasks, 12.5 hours.");
$ok(!str_contains($r, '@') && str_contains($r, '[email]'), 'email masked');
$ok(!str_contains($r, '98765') && !str_contains($r, '2345-6789') && substr_count($r, '[phone]') === 2, 'phone numbers masked');
$ok(!str_contains($r, '123456789012') && str_contains($r, '[account-number]'), 'bank-like digit run masked');
$ok(str_contains($r, '[ifsc]') && substr_count($r, '[tax-id]') === 2 && str_contains($r, '[card-number]'), 'IFSC, PAN, GSTIN and card masked');
$ok(str_contains($r, 'INV-2026-0001') && str_contains($r, '1,25,000.50') && str_contains($r, '2026-11-05') && str_contains($r, '05/11/2026') && str_contains($r, '12.5 hours'), 'invoice numbers, amounts, dates and decimals untouched');

DB::pdo()->beginTransaction();
try {
  echo "AiClient with fake provider\n";
  Settings::setMany(['ai.enabled' => '1', 'ai.redact_pii' => '1', 'ai.monthly_token_budget' => '0', 'ai.max_output_tokens' => '500', 'ai.temperature' => '']);
  $fake = new FakeProvider('Summary ok');
  AiClient::fake($fake);
  $before = (int) DB::value('SELECT COUNT(*) FROM ai_usage');
  $res = AiClient::ask('unit_test', 'sys', 'Contact bob@example.com on +1 415 555 2671 about invoice INV-7', ['max_tokens' => 9999]);
  $sent = $fake->calls[0]['messages'][1]['content'];
  $ok($res['text'] === 'Summary ok' && $res['input_tokens'] === 11, 'fake provider result returned');
  $ok(str_contains($sent, '[email]') && str_contains($sent, '[phone]') && !str_contains($sent, 'bob@') && str_contains($sent, 'INV-7'), 'prompt redacted before it reaches the provider');
  $ok($fake->calls[0]['opts']['max_tokens'] === 500 && !isset($fake->calls[0]['opts']['temperature']), 'max_tokens capped by setting, empty temperature not sent');
  $ok($fake->calls[0]['messages'][0] === ['role' => 'system', 'content' => 'sys'], 'system prompt passed as system message');
  $row = DB::first('SELECT * FROM ai_usage ORDER BY id DESC LIMIT 1');
  $ok((int) DB::value('SELECT COUNT(*) FROM ai_usage') === $before + 1 && $row['status'] === 'ok' && (int) $row['input_tokens'] === 11 && (int) $row['output_tokens'] === 7 && $row['feature'] === 'unit_test', 'successful call logged with tokens');
  Settings::set('ai.redact_pii', '0');
  AiClient::ask('unit_test', 'sys', 'mail bob@example.com');
  $ok(str_contains($fake->calls[1]['messages'][1]['content'], 'bob@example.com'), 'redaction can be switched off');
  AiClient::fake(new FakeProvider(static fn () => throw new AiException('Provider down, try later.', 'HTTP 503: overloaded')));
  $msg = $throws(static fn () => AiClient::ask('unit_test', 'sys', 'hello'));
  $row = DB::first('SELECT * FROM ai_usage ORDER BY id DESC LIMIT 1');
  $ok($msg === 'Provider down, try later.' && $row['status'] === 'error' && $row['error'] === 'HTTP 503: overloaded', 'provider failure surfaces friendly message and is logged as error');
  AiClient::fake(new FakeProvider(static fn () => throw new \RuntimeException('socket exploded with secret details')));
  $msg = $throws(static fn () => AiClient::ask('unit_test', 'sys', 'hello'));
  $ok($msg !== null && !str_contains($msg, 'secret'), 'unexpected exceptions become a generic friendly error');
  AiClient::fake(new FakeProvider('x'));
  Settings::set('ai.monthly_token_budget', '1');
  $msg = $throws(static fn () => AiClient::ask('unit_test', 'sys', 'hello'));
  $row = DB::first('SELECT * FROM ai_usage ORDER BY id DESC LIMIT 1');
  $ok($msg !== null && str_contains($msg, 'budget') && $row['status'] === 'blocked', 'monthly budget exhausted -> blocked and logged');
  AiClient::fake(null);
  Settings::setMany(['ai.provider' => 'mock', 'ai.monthly_token_budget' => '0']);
  App::setConfig(['debug' => false] + App::config());
  $ok(!isset(AiClient::providers()['mock']) && AiClient::provider() === 'anthropic', 'mock provider not selectable when debug is off');
  App::setConfig(['debug' => true] + App::config());
  $ok(isset(AiClient::providers()['mock']) && AiClient::make() instanceof Modules\Ai\Services\MockProvider, 'mock provider available in debug mode');
  $mt = Json::tasks(AiClient::ask('tasks', 'sys', "<notes>Design logo\nWrite brief</notes>")['text']);
  $ok(count($mt) === 2 && $mt[0]['title'] === 'Design logo', 'mock provider output parses as tasks');

  echo "Scoped NL search\n";
  $now = date('Y-m-d H:i:s');
  $mgrRole = (int) DB::value("SELECT id FROM roles WHERE slug='manager'");
  $uid = DB::insert('users', ['role_id' => $mgrRole, 'name' => 'Unit Mgr', 'email' => 'unit-'.bin2hex(random_bytes(4)).'@ai.test', 'password_hash' => 'x', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
  $mine = DB::insert('clients', ['name' => 'Zqx Unit Mine', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
  $other = DB::insert('clients', ['name' => 'Zqx Unit Other', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
  DB::insert('client_managers', ['client_id' => $mine, 'user_id' => $uid]);
  $u = DB::first('SELECT u.*, r.slug AS role_slug, r.name AS role_name FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=?', [$uid]);
  Scope::flush();
  if (class_exists('Modules\\Crm\\Services\\ClientAccess')) { Modules\Crm\Services\ClientAccess::flush(); }
  $hasView = Scope::can('crm.clients.view', $u);
  $res = NlSearch::run(Json::filters(['entity' => 'clients', 'keywords' => 'Zqx Unit']), $u);
  $titles = array_column($res['rows'], 'title');
  $ok($hasView && in_array('Zqx Unit Mine', $titles, true) && !in_array('Zqx Unit Other', $titles, true), 'manager only finds assigned clients');
  $ok(!Scope::canSeeClient($u, $other) && Scope::canSeeClient($u, $mine), 'canSeeClient respects client_managers');
  $empRole = (int) DB::value("SELECT id FROM roles WHERE slug='employee'");
  $emp = ['role_slug' => 'employee', 'role_id' => $empRole] + $u;
  $ok($throws(static fn () => NlSearch::run(Json::filters(['entity' => 'invoices']), $emp)) !== null, 'entity without permission (employee -> invoices) refused'); }
finally { DB::pdo()->rollBack(); AiClient::fake(null); Core\Cache::forget('settings'); }

echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);
