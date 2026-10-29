<?php
namespace Modules\Ai\Services;

/** OpenAI Chat Completions with function calling: OpenAI, OpenRouter, Gemini (OpenAI endpoint), Groq, Mistral, DeepSeek, Ollama, LM Studio and any compatible server. */
final class OpenAiCompatibleProvider extends Provider {
  public function chat(string $system, array $messages, array $tools, array $opts): array {
    $base = $this->base('https://api.openai.com/v1');
    $host = (string) parse_url($base, PHP_URL_HOST);
    $msgs = [['role' => 'system', 'content' => $system]];
    foreach ($messages as $m) {
      if ($m['role'] === 'user') { $msgs[] = ['role' => 'user', 'content' => (string) $m['content']]; }
      elseif ($m['role'] === 'assistant') {
        $a = ['role' => 'assistant', 'content' => (string) $m['content'] !== '' ? (string) $m['content'] : null];
        if (!empty($m['calls'])) { $a['tool_calls'] = array_map(static fn ($c) => ['id' => $c['id'], 'type' => 'function', 'function' => ['name' => $c['name'], 'arguments' => json_encode((object) $c['args'], JSON_UNESCAPED_UNICODE)]], $m['calls']); }
        if ($a['content'] === null && empty($a['tool_calls'])) { $a['content'] = ''; }
        $msgs[] = $a; }
      else { $t = ['role' => 'tool', 'tool_call_id' => $m['id'], 'content' => (string) $m['content']]; if (str_contains($host, 'mistral')) { $t['name'] = $m['name']; } $msgs[] = $t; } }
    $body = ['model' => $this->model(), 'messages' => $msgs];
    $body[$host === 'api.openai.com' ? 'max_completion_tokens' : 'max_tokens'] = (int) $opts['max_tokens'];
    if (isset($opts['temperature'])) { $body['temperature'] = (float) $opts['temperature']; }
    if ($tools) { $body['tools'] = array_map(static fn ($t) => ['type' => 'function', 'function' => ['name' => $t['name'], 'description' => $t['description'], 'parameters' => $t['schema']]], $tools); $body['tool_choice'] = 'auto'; }
    $headers = [];
    if ((string) ($this->m['api_key'] ?? '') !== '') { $headers[] = 'Authorization: Bearer '.$this->m['api_key']; }
    if (str_contains($host, 'openrouter.ai')) {
      $headers[] = 'X-Title: '.preg_replace('/[^\w .-]/', '', company_name() ?: 'Niyanta');
      $url = (string) \Core\App::config('app_url', '');
      if (preg_match('#^https?://\S+$#', $url)) { $headers[] = 'HTTP-Referer: '.$url; } }
    $j = $this->send($base.'/chat/completions', $headers, $body, (int) ($opts['timeout'] ?? 50));
    $choice = $j['choices'][0] ?? [];
    $msg = $choice['message'] ?? [];
    $text = $msg['content'] ?? '';
    if (is_array($text)) { $text = implode('', array_map(static fn ($p) => is_array($p) ? (string) ($p['text'] ?? '') : (string) $p, $text)); }
    $calls = [];
    foreach ($msg['tool_calls'] ?? [] as $i => $c) {
      $args = $c['function']['arguments'] ?? '{}';
      $args = is_array($args) ? $args : json_decode((string) $args, true);
      $calls[] = ['id' => (string) (($c['id'] ?? '') ?: 'call_'.$i.'_'.bin2hex(random_bytes(3))), 'name' => (string) ($c['function']['name'] ?? ''), 'args' => is_array($args) ? $args : []]; }
    $stop = (string) ($choice['finish_reason'] ?? '');
    if (trim((string) $text) === '' && !$calls) { throw new AiException($stop === 'length' ? 'The answer was cut off. Ask an administrator to raise the max output tokens.' : 'The AI returned an empty answer. Please try again.', 'Empty completion: '.$stop); }
    return ['text' => trim((string) $text), 'calls' => $calls, 'raw' => null, 'in' => (int) ($j['usage']['prompt_tokens'] ?? 0), 'out' => (int) ($j['usage']['completion_tokens'] ?? 0), 'model' => (string) ($j['model'] ?? $this->model()), 'stop' => $stop]; } }
