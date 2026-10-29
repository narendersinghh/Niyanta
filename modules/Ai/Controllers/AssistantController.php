<?php
namespace Modules\Ai\Controllers;
use Core\{Audit, Controller, DB, Mailer, Response, ValidationException, Validator};
use Modules\Ai\Services\{AiClient, AiException, Context, Json, Prompts, Scope};

/** Assistant home and the AI tools. Outputs are drafts: nothing is saved or sent without an explicit, separate confirmation. */
final class AssistantController extends Controller {
  /** key => [title, description, colour, icon, required permissions (all)] */
  public const TOOLS = [
    'client_summary' => ['Client summary', 'Profile, journey and open invoices distilled into one brief.', 'lav', 'briefcase', ['crm.clients.view']],
    'meeting' => ['Meeting summary', 'Turn raw notes into decisions and action items.', 'mint', 'message', []],
    'email' => ['Email draft', 'A clear, on-tone email draft ready to copy.', 'sky', 'mail', []],
    'followups' => ['Follow-up ideas', 'Smart next steps from the client journey and invoices.', 'peach', 'target', ['crm.clients.view']],
    'tasks' => ['Tasks from text', 'Extract tasks from notes, review and approve.', 'lemon', 'check-square', ['projects.view', 'projects.tasks.manage']],
    'project_report' => ['Project status report', 'Progress, milestones, overdue work, hours and risks.', 'pink', 'chart', ['projects.view']],
    'reminder' => ['Payment reminder', 'Friendly-but-firm drafts for overdue invoices.', 'sky', 'receipt', ['crm.finance.view']]];
  public const TONES = ['friendly' => 'Friendly', 'formal' => 'Formal', 'concise' => 'Short & concise', 'persuasive' => 'Persuasive', 'apologetic' => 'Apologetic'];

  public static function tools(array $u): array {
    if (!\Core\Gate::allows('ai.use', $u)) { return []; }
    return array_filter(self::TOOLS, static fn ($t) => array_filter($t[4], static fn ($p) => !Scope::can($p, $u)) === []); }

  public function index(): Response {
    $u = $this->user();
    if (!AiClient::enabled()) { return $this->view('ai::disabled', ['title' => 'AI Assistant']); }
    $tools = self::tools($u);
    $p = [];
    $clients = isset($tools['client_summary']) ? DB::all('SELECT id, name, company FROM clients WHERE 1=1'.Scope::where(Scope::clientIds($u), 'id', $p)." AND status<>'inactive' ORDER BY name LIMIT 500", $p) : [];
    $p = [];
    $projects = isset($tools['project_report']) ? DB::all('SELECT id, name, status FROM projects WHERE 1=1'.Scope::where(Scope::projectIds($u), 'id', $p)." AND status<>'cancelled' ORDER BY status='completed', name LIMIT 500", $p) : [];
    $used = AiClient::monthUsed(); $budget = AiClient::budget();
    return $this->view('ai::index', ['title' => 'AI Assistant', 'tools' => $tools, 'clients' => $clients, 'projects' => $projects,
      'taskProjects' => array_values(array_filter($projects, static fn ($r) => !in_array($r['status'], ['completed', 'cancelled'], true))),
      'invoices' => isset($tools['reminder']) ? Context::overdueInvoices($u) : [], 'used' => $used, 'budget' => $budget, 'pct' => $budget ? min(100, (int) round($used * 100 / $budget)) : 0,
      'today' => AiClient::userToday((int) $u['id']), 'daily' => AiClient::dailyLimit(), 'pending' => (int) DB::value("SELECT COUNT(*) FROM ai_actions WHERE user_id=? AND status='pending'", [$u['id']]),
      'ready' => AiClient::hasKey(AiClient::provider()), 'canManageClients' => Scope::can('crm.clients.manage', $u), 'canSend' => Scope::can('crm.finance.manage', $u), 'scripts' => ['js/ai.js'], 'styles' => ['css/ai.css']]); }

  private function fail(AiException $e): Response { return $this->json(['error' => $e->getMessage()], $e->status === 'blocked' ? 429 : ($e->status === 'disabled' ? 503 : 502)); }

  /** JSON 422 for validation errors (handled here: core Router's JSON branch calls reset() on a readonly array). */
  private static function invalid(ValidationException $e): Response { $errs = $e->errors; return Response::json(['errors' => $errs, 'error' => (string) (array_values($errs)[0] ?? 'Invalid input.')], 422); }

  private static function splitSubject(string $text): array {
    if (preg_match('/^\s*\**subject\**\s*:\s*(.+?)\R+(.*)$/is', $text, $m)) { return [trim($m[1], " *\t"), trim($m[2])]; }
    return ['', trim($text)]; }

  /** POST /ai/run/{tool}, returns JSON {text, model, tokens, ...}. */
  public function run(string $tool): Response {
    $u = $this->user();
    if (!isset(self::TOOLS[$tool])) { $this->abort(404, 'Unknown tool.'); }
    if (!isset(self::tools($u)[$tool])) { $this->abort(403, 'You do not have permission to use this tool.'); }
    if (!AiClient::enabled()) { return $this->json(['error' => 'The AI assistant is turned off.'], 503); }
    $extra = [];
    try {
      switch ($tool) {
        case 'client_summary':
        case 'followups':
          $d = $this->validate(['client_id' => 'required|integer'], ['client_id' => 'Client']);
          $prompt = "<data>\n".Context::clientBrief($u, (int) $d['client_id'], 30, true)."\n</data>";
          $r = AiClient::ask($tool, Prompts::for($tool), $prompt);
          break;
        case 'meeting':
          $d = $this->validate(['notes' => 'required|string|min:20|max:12000', 'client_id' => 'nullable|integer'], ['notes' => 'Meeting notes', 'client_id' => 'Client']);
          $head = '';
          if ($d['client_id']) { $c = Context::client($u, (int) $d['client_id']); $head = 'CLIENT: '.$c['name'].($c['company'] ? ' ('.$c['company'].')' : '')."\n"; }
          $r = AiClient::ask($tool, Prompts::for($tool), "<data>\n".$head."MEETING NOTES:\n".$d['notes']."\n</data>");
          $extra = ['can_save' => $d['client_id'] && Scope::can('crm.clients.manage', $u), 'client_id' => $d['client_id']];
          break;
        case 'email':
          $d = $this->validate(['purpose' => 'required|string|min:5|max:1000', 'recipient' => 'nullable|string|max:2000', 'tone' => 'required|in:'.implode(',', array_keys(self::TONES)), 'client_id' => 'nullable|integer'], ['purpose' => 'Purpose', 'recipient' => 'Recipient context']);
          $ctx = $d['client_id'] ? Context::clientLine($u, (int) $d['client_id'])."\n" : '';
          $r = AiClient::ask($tool, Prompts::for($tool, ['tone' => self::TONES[$d['tone']]]), "<data>\n".$ctx.'PURPOSE: '.$d['purpose']."\nRECIPIENT CONTEXT: ".($d['recipient'] ?: 'n/a')."\n</data>");
          [$extra['subject'], $extra['body']] = self::splitSubject($r['text']);
          break;
        case 'project_report':
          $d = $this->validate(['project_id' => 'required|integer'], ['project_id' => 'Project']);
          $r = AiClient::ask($tool, Prompts::for($tool), "<data>\n".Context::projectBrief($u, (int) $d['project_id'])."\n</data>");
          break;
        case 'reminder':
          $d = $this->validate(['invoice_id' => 'required|integer'], ['invoice_id' => 'Invoice']);
          $inv = Context::invoice($u, (int) $d['invoice_id']);
          if ((float) $inv['total'] - (float) $inv['amount_paid'] <= 0 || in_array($inv['status'], ['paid', 'cancelled', 'draft'], true)) { throw new ValidationException(['invoice_id' => 'This invoice has nothing outstanding.']); }
          $r = AiClient::ask($tool, Prompts::for($tool), "<data>\n".Context::invoiceBrief($inv)."\n</data>");
          [$extra['subject'], $extra['body']] = self::splitSubject($r['text']);
          $ok = filter_var((string) $inv['client_email'], FILTER_VALIDATE_EMAIL) !== false;
          $extra += ['invoice_id' => (int) $inv['id'], 'can_queue' => $ok && Scope::can('crm.finance.manage', $u), 'to' => $ok && Scope::can('crm.finance.manage', $u) ? $inv['client_email'] : null, 'no_email' => !$ok];
          break;
        case 'tasks':
          return $this->proposeTasks($u); } }
    catch (ValidationException $e) { return self::invalid($e); }
    catch (AiException $e) { return $this->fail($e); }
    return $this->json(['text' => $r['text'], 'model' => $r['model'], 'tokens' => $r['input_tokens'] + $r['output_tokens']] + $extra); }

  private function proposeTasks(array $u): Response {
    $d = $this->validate(['project_id' => 'required|integer', 'notes' => 'required|string|min:10|max:8000'], ['project_id' => 'Project', 'notes' => 'Text']);
    $pid = (int) $d['project_id'];
    if (!Scope::canSeeProject($u, $pid)) { $this->abort(404, 'Project not found.'); }
    $pr = DB::first('SELECT id, name FROM projects WHERE id=?', [$pid]) ?? $this->abort(404, 'Project not found.');
    $open = DB::column("SELECT title FROM tasks WHERE project_id=? AND status<>'done' ORDER BY id DESC LIMIT 30", [$pid]);
    $prompt = "<data>\nPROJECT: ".$pr['name']."\nEXISTING OPEN TASKS:\n".($open ? '- '.implode("\n- ", array_map(static fn ($t) => Context::cut($t, 100), $open)) : '(none)')."\n</data>\n<notes>\n".$d['notes']."\n</notes>";
    try {
      $r = AiClient::ask('tasks', Prompts::for('tasks'), $prompt);
      $tasks = Json::tasks($r['text'], 15); }
    catch (AiException $e) { return $this->fail($e); }
    $id = DB::insert('ai_actions', ['user_id' => $u['id'], 'type' => 'create_tasks', 'title' => mb_substr(count($tasks).' task'.(count($tasks) === 1 ? '' : 's').' for '.$pr['name'], 0, 190), 'payload' => json_encode(['project_id' => $pid, 'project' => $pr['name'], 'tasks' => $tasks, 'source' => mb_substr($d['notes'], 0, 2000), 'model' => $r['model']], JSON_UNESCAPED_UNICODE), 'status' => 'pending', 'created_at' => date('Y-m-d H:i:s')]);
    Audit::log('ai.tasks.proposed', 'ai_action', $id, ['project_id' => $pid, 'count' => count($tasks)]);
    return $this->json(['redirect' => url('/ai/actions/'.$id), 'text' => count($tasks).' tasks are ready for your review.']); }

  /** POST /ai/meeting/save, human-approved journey entry. */
  public function saveMeeting(): Response {
    $u = $this->user();
    if (!Scope::can('crm.clients.manage', $u)) { $this->abort(403, 'You do not have permission to edit the client journey.'); }
    $d = $this->validate(['client_id' => 'required|integer', 'title' => 'required|string|max:190', 'body' => 'required|string|max:12000', 'is_internal' => 'boolean'], ['client_id' => 'Client', 'body' => 'Summary']);
    if (!Scope::canSeeClient($u, (int) $d['client_id'])) { $this->abort(404, 'Client not found.'); }
    $now = date('Y-m-d H:i:s');
    $id = DB::transaction(static function () use ($d, $u, $now) {
      $id = DB::insert('client_activities', ['client_id' => (int) $d['client_id'], 'type' => 'meeting', 'title' => $d['title'], 'body' => $d['body'], 'is_internal' => (int) $d['is_internal'], 'occurred_at' => $now, 'user_id' => $u['id'], 'created_at' => $now]);
      DB::insert('ai_actions', ['user_id' => $u['id'], 'type' => 'save_journey', 'title' => mb_substr('Meeting summary saved: '.$d['title'], 0, 190), 'payload' => json_encode(['client_id' => (int) $d['client_id'], 'activity_id' => $id], JSON_UNESCAPED_UNICODE), 'status' => 'executed', 'result' => 'Journey entry #'.$id.' created', 'decided_by' => $u['id'], 'decided_at' => $now, 'created_at' => $now]);
      return $id; });
    Audit::log('ai.journey.saved', 'client', (int) $d['client_id'], ['activity_id' => $id]);
    $this->flash('Meeting summary saved to the client journey.');
    return $this->redirect('/clients/'.(int) $d['client_id']); }

  /** POST /ai/reminder/queue, explicit, confirmed queuing of a reminder email to the client. */
  public function queueReminder(): Response {
    $u = $this->user();
    if (!Scope::can('crm.finance.view', $u) || !Scope::can('crm.finance.manage', $u)) { $this->abort(403, 'You do not have permission to send payment reminders.'); }
    $d = $this->validate(['invoice_id' => 'required|integer', 'subject' => 'required|string|max:250', 'body' => 'required|string|max:10000', 'confirm' => 'required'], ['confirm' => 'Confirmation', 'body' => 'Message']);
    $inv = Context::invoice($u, (int) $d['invoice_id']);
    if ((float) $inv['total'] - (float) $inv['amount_paid'] <= 0 || in_array($inv['status'], ['paid', 'cancelled', 'draft'], true)) { throw new ValidationException(['invoice_id' => 'This invoice has nothing outstanding.']); }
    if (!filter_var((string) $inv['client_email'], FILTER_VALIDATE_EMAIL)) { throw new ValidationException(['invoice_id' => 'This client has no valid email address.']); }
    $now = date('Y-m-d H:i:s');
    $aid = DB::transaction(static function () use ($d, $u, $inv, $now) {
      $mid = Mailer::queue((string) $inv['client_email'], $d['subject'], Mailer::template($d['subject'], nl2br(e($d['body']))), (string) $inv['client_name']);
      DB::update('invoices', ['last_reminder_at' => $now], 'id=?', [$inv['id']]);
      return DB::insert('ai_actions', ['user_id' => $u['id'], 'type' => 'send_reminder', 'title' => mb_substr('Payment reminder for '.$inv['number'], 0, 190), 'payload' => json_encode(['invoice_id' => (int) $inv['id'], 'to' => $inv['client_email'], 'subject' => $d['subject'], 'mail_id' => $mid], JSON_UNESCAPED_UNICODE), 'status' => 'executed', 'result' => 'Email queued to '.$inv['client_email'], 'decided_by' => $u['id'], 'decided_at' => $now, 'created_at' => $now]); });
    Audit::log('ai.reminder.queued', 'invoice', (int) $inv['id'], ['ai_action_id' => $aid]);
    $this->flash('Reminder queued for '.$inv['client_email'].'.');
    return $this->redirect('/ai/actions/'.$aid); } }
