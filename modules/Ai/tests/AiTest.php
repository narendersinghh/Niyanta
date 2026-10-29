<?php
/**
 * AI assistant: role-scoped tools (employees vs managers vs HR), feature switches, the provider-agnostic tool loop
 * through the offline mock in OpenAI and Anthropic wire formats, conversation ownership, limits and failures.
 */
use Core\{Auth, Crypto, DB, Features, Settings};
use Modules\Ai\Services\{AiException, Assistant, MockServer, People, Provider, Redactor, Tools};

/** Shared fixture: HR, a manager with a direct report on his project, an unrelated employee and manager, clients and invoices. */
function ai_fx(): array {
  static $f = null;
  if ($f) { return $f; }
  $now = date('Y-m-d H:i:s'); $today = date('Y-m-d');
  $hr = make_user('hr', 'hr@aiunit.test', ['name' => 'Hina Unit']);
  $mgr = make_user('manager', 'mgr@aiunit.test', ['name' => 'Manoj Unit']);
  $emp = make_user('employee', 'esha@aiunit.test', ['name' => 'Esha Patelunit', 'manager_id' => $mgr, 'designation' => 'Designer']);
  $other = make_user('employee', 'arjun@aiunit.test', ['name' => 'Arjun Otherunit']);
  $mgr2 = make_user('manager', 'mgr2@aiunit.test', ['name' => 'Rohit Unit']);
  $p1 = DB::insert('projects', ['name' => 'Acme Website', 'code' => 'AIU-1', 'status' => 'active', 'manager_id' => $mgr, 'deadline' => date('Y-m-d', strtotime('+20 days')), 'progress' => 40, 'created_at' => $now, 'updated_at' => $now]);
  $p2 = DB::insert('projects', ['name' => 'Secret Internal', 'code' => 'AIU-2', 'status' => 'active', 'manager_id' => $mgr2, 'created_at' => $now, 'updated_at' => $now]);
  DB::insert('project_members', ['project_id' => $p1, 'user_id' => $emp]);
  DB::insert('project_members', ['project_id' => $p2, 'user_id' => $other]);
  DB::insert('tasks', ['project_id' => $p1, 'title' => 'Design homepage hero', 'status' => 'done', 'assignee_id' => $emp, 'completed_at' => $now, 'created_at' => $now]);
  DB::insert('tasks', ['project_id' => $p1, 'title' => 'Build contact form', 'status' => 'in_progress', 'assignee_id' => $emp, 'due_date' => $today, 'created_at' => $now]);
  DB::insert('tasks', ['project_id' => $p2, 'title' => 'Classified migration', 'status' => 'in_progress', 'assignee_id' => $other, 'created_at' => $now]);
  DB::insert('time_entries', ['user_id' => $emp, 'project_id' => $p1, 'started_at' => $today.' 00:10:00', 'ended_at' => $today.' 02:40:00', 'duration_seconds' => 9000, 'source' => 'manual', 'created_at' => $now]);
  foreach ([$emp, $other] as $u) { DB::insert('attendance', ['user_id' => $u, 'work_date' => $today, 'clock_in' => $today.' 00:05:00', 'clock_out' => $today.' 08:05:00', 'worked_seconds' => 28800, 'status' => 'present']); }
  $lt = (int) DB::value('SELECT id FROM leave_types WHERE active=1 ORDER BY id LIMIT 1');
  foreach ([[$emp, 'Family surgery'], [$other, 'Private matter']] as [$u, $why]) { DB::insert('leave_requests', ['user_id' => $u, 'leave_type_id' => $lt, 'start_date' => date('Y-m-d', strtotime('+40 days')), 'end_date' => date('Y-m-d', strtotime('+40 days')), 'days' => 1, 'reason' => $why, 'status' => 'approved', 'created_at' => $now]); }
  DB::insert('compensations', ['user_id' => $emp, 'pay_type' => 'monthly', 'amount' => 98765.43, 'effective_from' => '2026-01-01', 'created_at' => $now]);
  DB::query('INSERT INTO employee_profiles (user_id, bank_account_enc, tax_id_enc, notes) VALUES (?,?,?,?)', [$emp, Crypto::encrypt('000111222333'), Crypto::encrypt('ABCPE1234F'), 'HR-ONLY-NOTE']);
  $c1 = DB::insert('clients', ['name' => 'Alpha Contact', 'company' => 'Alpha Corp', 'status' => 'active', 'email' => 'boss@alpha.test', 'created_at' => $now]);
  $c2 = DB::insert('clients', ['name' => 'Beta Contact', 'company' => 'Beta Corp', 'status' => 'active', 'created_at' => $now]);
  DB::insert('client_managers', ['client_id' => $c1, 'user_id' => $mgr]);
  DB::insert('client_managers', ['client_id' => $c2, 'user_id' => $mgr2]);
  $old = date('Y-m-d', strtotime('-30 days')); $due = date('Y-m-d', strtotime('-10 days'));
  DB::insert('invoices', ['client_id' => $c1, 'number' => 'INV-AIU-1', 'issue_date' => $old, 'due_date' => $due, 'status' => 'sent', 'currency' => 'INR', 'total' => 1000, 'amount_paid' => 200, 'created_at' => $now]);
  DB::insert('invoices', ['client_id' => $c2, 'number' => 'INV-AIU-2', 'issue_date' => $old, 'due_date' => $due, 'status' => 'overdue', 'currency' => 'INR', 'total' => 5000, 'amount_paid' => 0, 'created_at' => $now]);
  return $f = compact('hr', 'mgr', 'emp', 'other', 'mgr2', 'p1', 'p2', 'c1', 'c2'); }

function ai_user(int $id): array { act_as($id); return Auth::user(); }
function ai_tool(int $uid, string $tool, array $args = []): array { return json_decode(Tools::run(ai_user($uid), $tool, $args), true) ?? []; }
function ai_denied(array $r): bool { return isset($r['error']) && (str_starts_with($r['error'], 'Not permitted') || str_starts_with($r['error'], 'No one called')); }
function ai_mock(string $model = 'mock-openai'): void {
  DB::query('DELETE FROM ai_models');
  DB::insert('ai_models', ['label' => 'Mock', 'preset' => 'mock', 'provider' => 'mock', 'model' => $model, 'is_default' => 1, 'active' => 1, 'created_at' => date('Y-m-d H:i:s')]);
  Settings::setMany(['ai.enabled' => '1', 'ai.monthly_token_budget' => '0', 'ai.user_daily_requests' => '0']);
  DB::query('DELETE FROM rate_limits'); }
function ai_chat(int $uid, string $msg, ?int $conv = null): array {
  act_as($uid);
  $r = http('POST', '/ai/chat', ['message' => $msg] + ($conv ? ['conversation_id' => $conv] : []));
  return ['status' => $r->status(), 'json' => json_decode($r->body(), true) ?? [], 'body' => $r->body()]; }

return [
  'employee cannot read another employee\'s attendance, leave or work summary' => function () {
    ['emp' => $emp] = ai_fx();
    foreach (['attendance', 'leave_balance', 'leave_requests', 'work_summary', 'tasks_for', 'timesheet', 'payslips'] as $t) {
      $r = ai_tool($emp, $t, ['person' => 'Arjun']);
      assert_true(ai_denied($r), "$t for a colleague must be refused: ".json_encode($r)); }
    $own = ai_tool($emp, 'leave_requests');
    assert_eq('Esha Patelunit', $own['person']);
    assert_eq('Family surgery', $own['requests'][0]['reason'], 'own leave reason visible to self');
    assert_true(str_contains(json_encode(ai_tool($emp, 'work_summary')), 'Design homepage hero'), 'own work summary works');
    $names = array_column(Tools::forUser(ai_user($emp)), 'name');
    assert_true(!in_array('invoices', $names, true) && !in_array('clients_overview', $names, true), 'no client/invoice tools for employees'); },

  'compensation, bank/tax ids, HR notes and secrets never leave through tools' => function () {
    ['hr' => $hr, 'mgr' => $mgr, 'emp' => $emp] = ai_fx();
    foreach ([$hr, $mgr, $emp] as $uid) {
      $u = ai_user($uid);
      foreach (Tools::forUser($u) as $t) {
        $out = Tools::run($u, $t['name'], ['person' => 'Esha', 'query' => 'Esha', 'project' => 'Acme', 'client' => 'Alpha']);
        foreach (['98765', '000111222333', 'ABCPE1234F', 'HR-ONLY-NOTE', 'password_hash', '$2y$', 'api_key'] as $bad) { assert_true(!str_contains($out, $bad), "{$t['name']} leaked $bad for user $uid"); } } }
    $r = ai_tool($mgr, 'payslips', ['person' => 'Esha']);
    assert_true(ai_denied($r), 'manager cannot read a report\'s payslips');
    $r = ai_tool($mgr, 'leave_requests', ['person' => 'Esha']);
    assert_true(isset($r['requests'][0]) && !isset($r['requests'][0]['reason']), 'manager sees a direct report\'s leave but not the reason'); },

  'manager gets a direct report\'s work summary but not an unrelated employee\'s' => function () {
    ['mgr' => $mgr] = ai_fx();
    $r = ai_tool($mgr, 'work_summary', ['person' => "Esha's", 'from' => 'this_week']);
    assert_eq('Esha Patelunit', $r['person']['name'] ?? null, json_encode($r));
    assert_eq('Design homepage hero', $r['tasks_completed'][0]['title'] ?? null);
    assert_eq(2.5, $r['hours_total'] ?? null, 'hours tracked');
    assert_eq(1, $r['attendance']['days_present'] ?? null);
    $r = ai_tool($mgr, 'work_summary', ['person' => 'Arjun']);
    assert_true(ai_denied($r), 'unrelated employee refused: '.json_encode($r));
    assert_true(ai_denied(ai_tool($mgr, 'attendance', ['person' => 'Arjun'])), 'unrelated attendance refused');
    assert_true(!str_contains(json_encode(ai_tool($mgr, 'search_records', ['query' => 'Classified'])), 'Classified migration'), 'search stays scoped'); },

  'HR can summarise anyone but sees no tasks without project access' => function () {
    ['hr' => $hr] = ai_fx();
    $r = ai_tool($hr, 'work_summary', ['person' => 'arjun@aiunit.test']);
    assert_eq('Arjun Otherunit', $r['person']['name'] ?? null, json_encode($r));
    assert_eq(1, $r['attendance']['days_present'] ?? null);
    assert_true(!isset($r['tasks_completed']), 'tasks follow the HR role\'s own project access');
    $l = ai_tool($hr, 'leave_requests', ['person' => 'Arjun']);
    assert_eq('Private matter', $l['requests'][0]['reason'] ?? null, 'HR sees leave reasons');
    $t = ai_tool($hr, 'team_on_leave', ['date' => date('Y-m-d', strtotime('+40 days'))]);
    assert_eq(2, $t['count']);
    $t = ai_tool(ai_fx()['emp'], 'team_on_leave', ['date' => date('Y-m-d', strtotime('+40 days'))]);
    assert_true(!isset($t['people'][0]['type']) && !str_contains(json_encode($t), 'Private'), 'directory-level names only for employees'); },

  'client and invoice scoping for a manager' => function () {
    ['mgr' => $mgr] = ai_fx();
    $r = ai_tool($mgr, 'overdue_invoices');
    $nums = array_column($r['invoices'], 'number');
    assert_true(in_array('INV-AIU-1', $nums, true) && !in_array('INV-AIU-2', $nums, true), json_encode($nums));
    assert_true(isset(ai_tool($mgr, 'client_summary', ['client' => 'Beta Corp'])['error']), 'other manager\'s client refused');
    $c = ai_tool($mgr, 'client_summary', ['client' => 'Alpha']);
    assert_eq('Alpha Corp', $c['client']['name'] ?? null);
    assert_eq('800.00', $c['open_invoices']['outstanding'] ?? null);
    assert_true(!str_contains(json_encode($c), 'boss@alpha.test'), 'client email not exposed');
    assert_true(isset(ai_tool($mgr, 'project_status', ['project' => 'Secret Internal'])['error']), 'invisible project refused');
    assert_eq('Acme Website', ai_tool($mgr, 'project_status', ['project' => 'acme'])['project']['name'] ?? null); },

  'switched-off features block their tools' => function () {
    ['mgr' => $mgr, 'emp' => $emp] = ai_fx();
    Features::set('feature:invoices', false); Features::set('feature:attendance', false);
    try {
      $names = array_column(Tools::forUser(ai_user($mgr)), 'name');
      assert_true(!in_array('invoices', $names, true) && !in_array('overdue_invoices', $names, true) && !in_array('attendance', $names, true), json_encode($names));
      assert_true(ai_denied(ai_tool($mgr, 'overdue_invoices')), 'tool call refused when feature is off');
      assert_true(ai_denied(ai_tool($emp, 'attendance')), 'attendance off');
      assert_true(!isset(ai_tool($mgr, 'work_summary', ['person' => 'Esha'])['hours_total']), 'work summary drops hours when attendance is off'); }
    finally { Features::set('feature:invoices', true); Features::set('feature:attendance', true); } },

  'person resolution stays inside the visible set and asks on ambiguity' => function () {
    ['hr' => $hr, 'mgr' => $mgr] = ai_fx();
    make_user('employee', 'esha2@aiunit.test', ['name' => 'Esha Secondunit']);
    $r = People::resolve(ai_user($hr), 'Esha', People::scope(ai_user($hr)));
    assert_true(isset($r['candidates']) && count($r['candidates']) === 2, json_encode($r));
    $r = People::resolve(ai_user($mgr), 'Esha', People::scope(ai_user($mgr)));
    assert_eq('Esha Patelunit', $r['person']['name'] ?? null, 'manager only sees his report');
    $r = People::resolve(ai_user($mgr), 'Eshaa Patelunit', People::scope(ai_user($mgr)));
    assert_eq('Esha Patelunit', $r['person']['name'] ?? null, 'small typo tolerated'); },

  'tool loop with the mock provider in OpenAI format' => function () {
    ['emp' => $emp] = ai_fx();
    ai_mock('mock-openai');
    $r = ai_chat($emp, "#tool my_tasks {\"range\":\"all\"}");
    assert_eq(200, $r['status'], $r['body']);
    assert_true(str_contains($r['json']['message']['content'], 'Build contact form'), $r['json']['message']['content']);
    assert_true(str_contains($r['json']['message']['content'], '| Title |'), 'table rendered from rows');
    assert_eq(['My tasks'], $r['json']['message']['tools']);
    $cid = $r['json']['conversation']['id'];
    assert_eq(1, (int) DB::value("SELECT COUNT(*) FROM ai_messages WHERE conversation_id=? AND role='tool' AND tool_name='my_tasks'", [$cid]));
    assert_true((int) DB::value("SELECT COUNT(*) FROM ai_usage WHERE user_id=? AND feature='chat' AND status='ok'", [$emp]) >= 1, 'usage logged');
    $r = ai_chat($emp, '#tool attendance {"person":"Arjun"}', $cid);
    assert_true(str_contains($r['json']['message']['content'], 'can\'t share'), 'refusal relayed: '.$r['json']['message']['content']);
    assert_eq(4, (int) DB::value("SELECT COUNT(*) FROM ai_messages WHERE conversation_id=? AND role IN ('user','assistant')", [$cid]), 'history kept in one conversation'); },

  'tool loop with the mock provider in Anthropic format (parallel calls, keyword routing)' => function () {
    ['mgr' => $mgr] = ai_fx();
    ai_mock('mock-anthropic');
    $r = ai_chat($mgr, "Summarise Esha's work this week");
    assert_eq(200, $r['status'], $r['body']);
    assert_true(str_contains($r['json']['message']['content'], 'Design homepage hero'), $r['json']['message']['content']);
    assert_eq(['Work summary'], $r['json']['message']['tools']);
    $r = ai_chat($mgr, "#tool overdue_invoices\n#tool projects_overview {\"status\":\"all\"}");
    $c = $r['json']['message']['content'];
    assert_true(str_contains($c, 'INV-AIU-1') && !str_contains($c, 'INV-AIU-2') && str_contains($c, 'Acme Website') && !str_contains($c, 'Secret Internal'), $c);
    assert_true(str_contains($c, '](/invoices/'), 'links to app records'); },

  'tool rounds are capped' => function () {
    ['emp' => $emp] = ai_fx();
    ai_mock('mock-openai');
    $r = ai_chat($emp, '#loop');
    assert_eq(200, $r['status'], $r['body']);
    assert_true(str_contains($r['json']['message']['content'], 'could not finish'), $r['json']['message']['content']);
    assert_eq(Assistant::MAX_ROUNDS, (int) DB::value("SELECT COUNT(*) FROM ai_messages WHERE conversation_id=? AND role='tool'", [$r['json']['conversation']['id']])); },

  'conversations are private to their owner' => function () {
    ['emp' => $emp, 'other' => $other] = ai_fx();
    ai_mock();
    $cid = ai_chat($emp, 'hello there')['json']['conversation']['id'];
    act_as($other);
    assert_eq(404, http('GET', '/ai/conversations/'.$cid)->status());
    assert_eq(404, http('POST', '/ai/conversations/'.$cid.'/delete')->status());
    assert_eq(404, ai_chat($other, 'hi', $cid)['status'], 'cannot post into someone else\'s chat');
    assert_true(!str_contains(http('GET', '/ai/conversations')->body(), 'hello there'), 'not listed for others');
    act_as($emp);
    assert_eq(200, http('GET', '/ai/conversations/'.$cid)->status());
    assert_eq(200, http('POST', '/ai/conversations/'.$cid.'/delete')->status());
    assert_eq(0, (int) DB::value('SELECT COUNT(*) FROM ai_messages WHERE conversation_id=?', [$cid]), 'messages deleted with the chat'); },

  'switch, limits and provider failures fail gracefully' => function () {
    ['emp' => $emp] = ai_fx();
    ai_mock();
    Settings::set('ai.enabled', '0');
    assert_eq(503, ai_chat($emp, 'hi')['status'], 'assistant off');
    Settings::set('ai.enabled', '1');
    $n = (int) DB::value('SELECT COUNT(*) FROM ai_conversations');
    $r = ai_chat($emp, 'please #fail');
    assert_eq(502, $r['status']);
    assert_true(str_contains($r['json']['error'], 'temporarily unavailable'), $r['body']);
    assert_eq($n, (int) DB::value('SELECT COUNT(*) FROM ai_conversations'), 'failed first message leaves no empty chat');
    Settings::set('ai.user_daily_requests', '1');
    $r = ai_chat($emp, 'hi');
    assert_eq(429, $r['status'], 'daily limit: '.$r['body']);
    Settings::set('ai.user_daily_requests', '0');
    DB::query('DELETE FROM ai_models');
    assert_eq(503, ai_chat($emp, 'hi')['status'], 'no model configured');
    $real = ['id' => 0, 'provider' => 'openai_compatible', 'preset' => 'openai', 'base_url' => 'https://127.0.0.1:9/v1', 'model' => 'gpt-5-mini', 'api_key' => 'sk-test-secret', 'max_tokens' => null, 'temperature' => null];
    try { Provider::make($real)->chat('x', [['role' => 'user', 'content' => 'hi']], [], ['max_tokens' => 64, 'timeout' => 5]); assert_true(false, 'should fail'); }
    catch (AiException $e) { assert_true(str_contains($e->getMessage(), 'Could not reach') && !str_contains($e->detail, 'sk-test-secret'), $e->getMessage()); }
    assert_throws(AiException::class, static fn () => Provider::make(['provider' => 'anthropic', 'base_url' => 'http://evil.example.com', 'model' => 'claude-opus-5-5', 'api_key' => 'k'])->chat('x', [['role' => 'user', 'content' => 'hi']], [], ['max_tokens' => 64]), 'plain http to a public host is blocked'); },

  'wire formats: providers parse tool calls and keep Anthropic blocks for the loop' => function () {
    $echo = static fn (array $json) => static fn () => ['code' => 200, 'json' => $json, 'raw' => ''];
    $o = new Modules\Ai\Services\OpenAiCompatibleProvider(['provider' => 'openai_compatible', 'base_url' => 'https://api.groq.com/openai/v1', 'model' => 'm', 'api_key' => 'k'], \Closure::fromCallable($echo(['model' => 'm', 'choices' => [['message' => ['content' => null, 'tool_calls' => [['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'my_tasks', 'arguments' => '{"range":"open"}']]]], 'finish_reason' => 'tool_calls']], 'usage' => ['prompt_tokens' => 3, 'completion_tokens' => 2]])));
    $r = $o->chat('s', [['role' => 'user', 'content' => 'q']], Tools::forUser(ai_user(ai_fx()['emp'])), ['max_tokens' => 100]);
    assert_eq([['id' => 'c1', 'name' => 'my_tasks', 'args' => ['range' => 'open']]], $r['calls']);
    $seen = null;
    $a = new Modules\Ai\Services\AnthropicProvider(['provider' => 'anthropic', 'base_url' => '', 'model' => 'claude-opus-5-5', 'api_key' => 'k'], static function ($url, $h, $body) use (&$seen) { $seen = [$url, $h, $body]; return ['code' => 200, 'json' => ['model' => 'claude-opus-5-5', 'stop_reason' => 'tool_use', 'content' => [['type' => 'thinking', 'thinking' => '', 'signature' => 'sig'], ['type' => 'tool_use', 'id' => 't1', 'name' => 'my_profile', 'input' => []]], 'usage' => ['input_tokens' => 5, 'output_tokens' => 1]], 'raw' => '']; });
    $r = $a->chat('sys', [['role' => 'user', 'content' => 'q'], ['role' => 'assistant', 'content' => '', 'calls' => [['id' => 't0', 'name' => 'x', 'args' => []]], 'raw' => [['type' => 'tool_use', 'id' => 't0', 'name' => 'x', 'input' => new stdClass()]]], ['role' => 'tool', 'id' => 't0', 'name' => 'x', 'content' => '{}']], [['name' => 'x', 'description' => 'd', 'schema' => ['type' => 'object', 'properties' => new stdClass()]]], ['max_tokens' => 100, 'temperature' => 0.5]);
    assert_eq('https://api.anthropic.com/v1/messages', $seen[0]);
    assert_true(in_array('anthropic-version: 2026-10-21', $seen[1], true) && !isset($seen[2]['temperature']) && $seen[2]['system'] === 'sys', 'version header, no sampling params, top-level system');
    assert_eq('tool_result', $seen[2]['messages'][2]['content'][0]['type']);
    assert_eq('my_profile', $r['calls'][0]['name']);
    assert_true($r['raw'][0]['type'] === 'thinking' && $r['raw'][1]['input'] instanceof stdClass, 'thinking block kept verbatim, empty input stays an object'); },

  'settings: model validation, encrypted keys and legacy migration' => function () {
    ai_fx();
    act_as($GLOBALS['ADMIN_ID']);
    assert_throws(Core\ValidationException::class, static fn () => Modules\Ai\Controllers\SettingsController::validated(['label' => 'X', 'preset' => 'custom', 'model' => 'm', 'base_url' => 'http://example.com/v1'], null), 'plain http public URL rejected');
    $ok = Modules\Ai\Controllers\SettingsController::validated(['label' => 'Local', 'preset' => 'ollama', 'model' => 'llama3.1', 'base_url' => ''], null);
    assert_eq('http://localhost:11434/v1', $ok['base_url']);
    DB::query('DELETE FROM ai_models');
    $r = http('POST', '/ai/models', ['label' => 'Claude', 'preset' => 'anthropic', 'model' => 'claude-opus-5-5', 'api_key' => 'sk-ant-unit-123']);
    assert_eq(302, $r->status());
    $row = DB::first('SELECT * FROM ai_models');
    assert_true($row['is_default'] == 1 && $row['provider'] === 'anthropic' && str_starts_with($row['api_key_enc'], 'enc:') && Crypto::decrypt($row['api_key_enc']) === 'sk-ant-unit-123', 'stored encrypted, first model is default');
    http('POST', '/ai/models/'.$row['id'], ['label' => 'Claude 2', 'preset' => 'anthropic', 'model' => 'claude-sonnet-5-5', 'api_key' => '']);
    assert_eq('sk-ant-unit-123', Crypto::decrypt(DB::value('SELECT api_key_enc FROM ai_models WHERE id=?', [$row['id']])), 'blank key keeps the saved one');
    assert_true(!str_contains(http('GET', '/ai/settings')->body(), 'sk-ant-unit-123'), 'key never rendered');
    DB::query('DELETE FROM ai_models');
    Settings::setMany(['ai.provider' => 'openai', 'ai.openai_key' => 'sk-legacy-1', 'ai.model_openai' => 'gpt-4.1-mini']);
    $mig = require dirname(__DIR__).'/migrations/2026_10_31_000001_ai_assistant.php';
    $mig['up'](DB::pdo());
    Settings::setMany([]);
    $m = DB::first('SELECT * FROM ai_models');
    assert_true($m && $m['preset'] === 'openai' && $m['model'] === 'gpt-4.1-mini' && $m['is_default'] == 1 && Crypto::decrypt($m['api_key_enc']) === 'sk-legacy-1', 'legacy key moved: '.json_encode($m));
    assert_eq(null, DB::value("SELECT `value` FROM settings WHERE `key`='ai.openai_key'"), 'old copy cleared');
    DB::query('DELETE FROM ai_models'); },

  'system prompt, suggestions and redaction' => function () {
    ['emp' => $emp, 'mgr' => $mgr] = ai_fx();
    $u = ai_user($emp);
    $s = Assistant::system($u, Tools::forUser($u));
    assert_true(str_contains($s, 'Niyu') && str_contains($s, date('Y-m-d')) && str_contains($s, 'Not permitted') && !str_contains($s, '—'), 'prompt basics');
    assert_true(str_contains($s, 'work summaries (yourself only)'), 'employee scope stated');
    assert_true(in_array('What are my tasks this week?', Assistant::suggestions($u), true));
    assert_true(str_contains(implode('|', Assistant::suggestions(ai_user($mgr))), 'Esha'), 'manager gets a team suggestion');
    $r = Redactor::redact('Mail a@b.co or +91 98765 43210, invoice INV-2026-0001 due 2026-11-05, 12.5 hours, url /projects/12');
    assert_true(str_contains($r, '[email]') && str_contains($r, '[phone]') && str_contains($r, '2026-11-05') && str_contains($r, '/projects/12'), $r);
    $big = Tools::fit(['rows' => array_fill(0, 500, ['title' => str_repeat('x', 60)])]);
    assert_true(strlen($big) <= Tools::MAX_JSON && str_contains($big, 'truncated'), 'results are truncated'); },
];
