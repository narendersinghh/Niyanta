<?php
namespace Modules\Ai\Services;

/**
 * Offline stand-in for an LLM API (debug mode and tests only). It answers in the real wire format of the endpoint it
 * pretends to be (OpenAI chat completions or Anthropic messages), so the real provider parsers and the tool loop run.
 * Script it from the user message: "#tool name {json}" (one per line) forces tool calls, "#fail" simulates an outage,
 * "#loop" keeps calling tools. Otherwise simple keyword rules pick a tool and tool results are summarised as markdown.
 * Meeting summary requests (system prompt marked NIYANTA_MEETING_SUMMARY) get strict JSON built from the transcript lines.
 */
final class MockServer {
  public static function handle(string $url, array $headers, array $body, int $timeout = 50): array {
    $anthropic = str_ends_with($url, '/v1/messages');
    [$user, $results, $rounds] = $anthropic ? self::readAnthropic($body['messages'] ?? []) : self::readOpenAi($body['messages'] ?? []);
    if (str_contains($user, '#fail')) { return ['code' => 503, 'json' => ['error' => ['message' => 'Mock provider simulated outage']], 'raw' => '']; }
    $sys = $anthropic ? (string) ($body['system'] ?? '') : (string) ($body['messages'][0]['content'] ?? '');
    if (str_contains($sys, 'NIYANTA_MEETING_SUMMARY')) { return self::reply($anthropic, $body, self::meetingSummary($user), [], 0); }
    $calls = [];
    // A deliberately gullible model: after its first round it obeys "#tool" lines planted in tool results next to an
    // "ignore previous instructions" phrase (prompt injection through data). The server-side scope must still hold.
    if ($results && $rounds === 1 && !str_contains($user, '#loop') && ($planted = self::planted($results))) { return self::reply($anthropic, $body, '', $planted, $rounds); }
    if (str_contains($user, '#loop') || !$results) {
      $calls = $rounds > 0 && !str_contains($user, '#loop') ? [] : self::plan($user);
      // Like a real model, only call tools it was offered (scripted #tool calls may name any tool).
      $offered = array_map(static fn ($t) => $t['name'] ?? ($t['function']['name'] ?? ''), $body['tools'] ?? []);
      $planned = count($calls);
      if (!str_contains($user, '#tool')) { $calls = array_values(array_filter($calls, static fn ($c) => in_array($c[0], $offered, true))); }
      if ($planned && !$calls) { $results = [['denied', '{"error":"That information is not available to your role."}']]; } }
    $text = $calls ? '' : ($results ? self::compose($results) : self::smallTalk($user));
    return self::reply($anthropic, $body, $text, $calls, $rounds); }

  /** Response in the wire format of the endpoint (round-tripped through JSON like a real HTTP response). */
  private static function reply(bool $anthropic, array $body, string $text, array $calls, int $rounds): array {
    $in = (int) ceil(strlen((string) json_encode($body)) / 4);
    $out = (int) ceil((strlen($text) + strlen((string) json_encode($calls))) / 4);
    if ($anthropic) {
      $content = [];
      if ($text !== '') { $content[] = ['type' => 'text', 'text' => $text]; }
      foreach ($calls as $i => $c) { $content[] = ['type' => 'tool_use', 'id' => 'toolu_mock'.$rounds.$i, 'name' => $c[0], 'input' => (object) $c[1]]; }
      $j = ['id' => 'msg_mock', 'type' => 'message', 'role' => 'assistant', 'model' => $body['model'] ?? 'mock-anthropic', 'content' => $content, 'stop_reason' => $calls ? 'tool_use' : 'end_turn', 'usage' => ['input_tokens' => $in, 'output_tokens' => $out]]; }
    else {
      $msg = ['role' => 'assistant', 'content' => $calls ? null : $text];
      if ($calls) { $msg['tool_calls'] = array_map(static fn ($c, $i) => ['id' => 'call_mock'.$rounds.$i, 'type' => 'function', 'function' => ['name' => $c[0], 'arguments' => json_encode((object) $c[1])]], $calls, array_keys($calls)); }
      $j = ['id' => 'chatcmpl-mock', 'object' => 'chat.completion', 'model' => $body['model'] ?? 'mock-openai', 'choices' => [['index' => 0, 'message' => $msg, 'finish_reason' => $calls ? 'tool_calls' : 'stop']], 'usage' => ['prompt_tokens' => $in, 'completion_tokens' => $out]]; }
    return ['code' => 200, 'json' => json_decode((string) json_encode($j), true), 'raw' => '']; }

  /** Meeting summary JSON built only from the transcript lines (decisions, "will" action items, requests, questions), merged for map-reduce. */
  private static function meetingSummary(string $q): string {
    if (str_contains($q, 'Merge these partial summaries') && ($p = strpos($q, '[')) !== false && is_array($parts = json_decode(substr($q, $p), true))) {
      $m = ['overview' => '', 'decisions' => [], 'action_items' => [], 'client_requests' => [], 'open_questions' => [], 'next_meeting' => ''];
      foreach ($parts as $x) { $m['overview'] = trim($m['overview'].' '.($x['overview'] ?? '')); foreach (['decisions', 'action_items', 'client_requests', 'open_questions'] as $k) { $m[$k] = array_merge($m[$k], $x[$k] ?? []); } $m['next_meeting'] = $m['next_meeting'] ?: ($x['next_meeting'] ?? ''); }
      $m['overview'] = 'Merged summary of '.count($parts).' parts. '.mb_substr($m['overview'], 0, 300);
      return json_encode($m, JSON_UNESCAPED_UNICODE); }
    $title = preg_match('/^Meeting:\s*(.+)$/m', $q, $tm) ? trim($tm[1]) : 'the meeting';
    $body = ($p = strpos($q, "Transcript:\n")) !== false ? substr($q, $p + 12) : $q;
    $o = ['overview' => '', 'decisions' => [], 'action_items' => [], 'client_requests' => [], 'open_questions' => [], 'next_meeting' => ''];
    $speakers = [];
    foreach (explode("\n", $body) as $line) {
      $line = trim($line);
      if ($line === '') { continue; }
      $who = ''; $say = $line;
      if (preg_match('/^([^:]{2,40}):\s+(.+)$/', $line, $m)) { $who = $m[1]; $say = $m[2]; $speakers[$who] = true; }
      $l = strtolower($say);
      if (preg_match('/\b(decided|agreed|approved|we will go with|final(ised|ized))\b/', $l)) { $o['decisions'][] = $say; }
      elseif (preg_match('/\b(i|we|you|he|she|they) will\b|\baction( item)?:|\bi\'ll\b/', $l)) { $o['action_items'][] = ['text' => $say, 'owner' => $who, 'due' => preg_match('/\b(20\d{2}-\d{2}-\d{2})\b/', $say, $d) ? $d[1] : (preg_match('/\bby (monday|tuesday|wednesday|thursday|friday|tomorrow|next week)\b/', $l, $d) ? 'by '.$d[1] : '')]; }
      elseif (preg_match('/\b(we need|we want|would like|please add|can you|request)\b/', $l)) { $o['client_requests'][] = $say; }
      if (str_ends_with($say, '?')) { $o['open_questions'][] = $say; }
      if (!$o['next_meeting'] && preg_match('/\bnext (meeting|call|sync|review)\b/', $l)) { $o['next_meeting'] = $say; } }
    $o['overview'] = 'Mock summary of '.$title.' with '.max(1, count($speakers)).' speaker'.(count($speakers) === 1 ? '' : 's').': '.count($o['decisions']).' decisions and '.count($o['action_items']).' action items.';
    return json_encode($o, JSON_UNESCAPED_UNICODE); }

  /** [last user text, tool results after it [[name, content]], assistant rounds after it] */
  private static function readOpenAi(array $msgs): array {
    $user = ''; $res = []; $rounds = 0; $names = [];
    foreach ($msgs as $m) {
      if ($m['role'] === 'user') { $user = (string) $m['content']; $res = []; $rounds = 0; }
      elseif ($m['role'] === 'assistant') { $rounds++; foreach ($m['tool_calls'] ?? [] as $c) { $names[$c['id']] = $c['function']['name']; } }
      elseif ($m['role'] === 'tool') { $res[] = [$names[$m['tool_call_id']] ?? 'tool', (string) $m['content']]; } }
    return [$user, $res, $rounds]; }

  private static function readAnthropic(array $msgs): array {
    $user = ''; $res = []; $rounds = 0; $names = [];
    foreach ($msgs as $m) {
      if ($m['role'] === 'assistant') { $rounds++; foreach ((array) $m['content'] as $b) { if (is_array($b) && ($b['type'] ?? '') === 'tool_use') { $names[$b['id']] = $b['name']; } } continue; }
      if (is_string($m['content'])) { $user = $m['content']; $res = []; $rounds = 0; continue; }
      foreach ($m['content'] as $b) { if (($b['type'] ?? '') === 'tool_result') { $res[] = [$names[$b['tool_use_id']] ?? 'tool', is_string($b['content']) ? $b['content'] : json_encode($b['content'])]; } } }
    return [$user, $res, $rounds]; }

  /** Tool calls planted in tool results by an injection ("ignore previous instructions ... #tool name {json}"). */
  private static function planted(array $results): array {
    $text = '';
    foreach ($results as [, $content]) {
      $d = json_decode($content, true);
      if (is_array($d)) { array_walk_recursive($d, static function ($v) use (&$text) { if (is_string($v)) { $text .= "\n".$v; } }); } else { $text .= "\n".$content; } }
    if (!preg_match('/ignore (all )?previous instructions/i', $text)) { return []; }
    $text = preg_replace('/\s*#tool\s+/', "\n#tool ", $text) ?? $text;
    return preg_match('/#tool\s+[a-z_]+/', $text) ? self::plan($text) : []; }

  /** @return list<array{0:string,1:array}> */
  private static function plan(string $q): array {
    if (preg_match_all('/#tool\s+([a-z_]+)\s*(\{.*?\})?\s*$/m', $q, $mm, PREG_SET_ORDER)) { return array_map(static fn ($m) => [$m[1], json_decode($m[2] ?? '{}', true) ?: []], $mm); }
    if (str_contains($q, '#loop')) { return [['search_records', ['query' => 'loop']]]; }
    $l = mb_strtolower($q);
    $person = preg_match("/\b([A-Z][a-z]+)'s\b/", $q, $m) || preg_match('/\b(?:for|of|about|is)\s+([A-Z][a-z]+(?:\s[A-Z][a-z]+)?)\b/', $q, $m) ? $m[1] : null;
    $p = $person ? ['person' => $person] : [];
    $has = static fn (string ...$w) => (bool) array_filter($w, static fn ($x) => str_contains($l, $x));
    return match (true) {
      $has('on leave today', 'who is on leave', 'out today') => [['team_on_leave', []]],
      $has('ledger') => [['leave_ledger', $p]],
      $has('leave polic', 'leave rule') => [['leave_policies', []]],
      $has('onboarding', 'exit checklist', 'offboarding') => [['onboarding_status', $p]],
      $has('meeting') => [['meetings', preg_match('/(?:of|for|about)\s+(?:the\s+)?(.+?)\s+project/i', $q, $pm) ? ['project' => $pm[1]] : []]],
      $has('routine', 'to-do', 'todo') => [['todos', $p]],
      $has('late today', 'clocked in', 'team attendance', 'who is in', 'who is working') => [['team_attendance', []]],
      $has('optional holiday', 'leave balance', 'leaves left', 'leave left') => [['leave_balance', $p]],
      $has('holiday') => [['holidays', []]],
      $has('leave') => [['leave_requests', $p]],
      $has('attendance') => [['attendance', $p]],
      $has('timesheet') => [['timesheet', $p]],
      $has('work', 'summar') && $person !== null => [['work_summary', $p + ['from' => 'this_week']]],
      $has('task') => $person ? [['tasks_for', $p + ['range' => 'this_week']]] : [['my_tasks', ['range' => 'this_week']]],
      $has('overdue') && $has('invoice') => [['overdue_invoices', []]],
      $has('invoice') => [['invoices', []]],
      $has('quotation', 'quote') => [['quotations', []]],
      $has('project') => preg_match('/(?:status of|how is)\s+(?:the\s+)?(.+?)\s+project/i', $q, $pm) ? [['project_status', ['project' => $pm[1]]]] : [['projects_overview', []]],
      $has('client') => [['clients_overview', []]],
      $has('payslip', 'salary', 'pay slip') => [['payslips', $p]],
      $has('policy', 'document', 'handbook') => [['company_documents', ['query' => '']]],
      $has('profile', 'who am i', 'my manager') => [['my_profile', []]],
      $has('summarise my', 'summarize my', 'my work') => [['work_summary', ['from' => 'this_week']]],
      $has('hello', 'hi ', 'thanks', 'thank you') || mb_strlen($l) < 4 => [],
      default => [['search_records', ['query' => mb_substr($q, 0, 60)]]] }; }

  private static function smallTalk(string $q): string { return "Hi! I'm **Goraiya**, running in mock mode. Ask me about your tasks, leave, attendance, projects or invoices."; }

  /** Markdown answer built only from tool results (never invents data). */
  private static function compose(array $results): string {
    $out = [];
    foreach ($results as [$name, $content]) {
      $d = json_decode($content, true);
      $title = ucfirst(str_replace('_', ' ', $name));
      if (!is_array($d)) { $out[] = "**$title:** ".mb_substr($content, 0, 200); continue; }
      if (isset($d['error'])) { $out[] = "Sorry, I can't share that. ".$d['error']; continue; }
      if (isset($d['candidates'])) { $out[] = 'I found more than one match: '.implode(', ', array_map(static fn ($c) => is_array($c) ? (string) ($c['name'] ?? '') : (string) $c, $d['candidates'])).'. Which one did you mean?'; continue; }
      $hum = static fn ($v) => is_bool($v) ? ($v ? 'yes' : 'no') : (is_string($v) && preg_match('/^[a-z]+(_[a-z]+)+$/', $v) ? str_replace('_', ' ', $v) : (string) $v);
      $skip = ['today', 'status_filter', 'range', 'count', 'url', 'truncated', 'from', 'to', 'year'];
      $table = null; $bullets = [];
      foreach ($d as $k => $v) {
        if (is_array($v) && $v && array_is_list($v) && is_array($v[0]) && $table === null) { $table = [$k, $v]; continue; }
        if (in_array($k, $skip, true) || count($bullets) >= 6) { continue; }
        $label = ucfirst(str_replace('_', ' ', (string) $k));
        if (is_scalar($v) && $v !== '') { $bullets[] = "- **$label:** ".$hum($v); }
        elseif (is_array($v) && $v && !array_is_list($v)) { $bullets[] = "- **$label:** ".implode(', ', array_map(static fn ($a, $b) => str_replace('_', ' ', (string) $a).' '.(is_scalar($b) ? $hum($b) : '…'), array_keys($v), $v)); } }
      $what = $table ? str_replace('_', ' ', (string) $table[0]) : '';
      $emptyList = array_keys(array_filter($d, static fn ($v) => $v === []));
      if (!$table && $emptyList && !$bullets) { $out[] = 'I found no '.str_replace('_', ' ', (string) $emptyList[0]).' for that right now.'; continue; }
      $lines = [$table ? 'I found **'.count($table[1]).'** '.$what.(isset($d['from'], $d['to']) ? ' between '.$d['from'].' and '.$d['to'] : '').'.' : "Here's the **".strtolower($title).'** you asked for.'];
      if ($bullets) { $lines[] = ''; $lines[] = "### $title"; array_push($lines, ...$bullets); }
      if ($table) {
        [$k, $rows] = $table;
        $cols = array_slice(array_values(array_filter(array_keys($rows[0]), static fn ($c) => !in_array($c, ['url', 'id'], true) && is_scalar($rows[0][$c] ?? '') && !is_bool($rows[0][$c] ?? ''))), 0, 4);
        $lines[] = '';
        $lines[] = '| '.implode(' | ', array_map(static fn ($c) => ucfirst(str_replace('_', ' ', $c)), $cols)).' |';
        $lines[] = '|'.str_repeat(' --- |', count($cols));
        foreach (array_slice($rows, 0, 6) as $r) {
          $cells = array_map(static fn ($c) => str_replace('|', '/', $hum($r[$c] ?? '')), $cols);
          if (!empty($r['url']) && $cells) { $cells[0] = '['.$cells[0].']('.$r['url'].')'; }
          $lines[] = '| '.implode(' | ', $cells).' |'; }
        if (count($rows) > 6) { $lines[] = ''; $lines[] = '_Plus '.(count($rows) - 6).' more._'; } }
      $out[] = implode("\n", $lines); }
    return implode("\n\n", $out); } }
