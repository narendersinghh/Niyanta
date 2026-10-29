<?php
namespace Modules\Ai\Services;

/**
 * Anthropic Messages API with tool use (raw HTTP, API version 2026-10-21: no sampling parameters, tool_choice stays "auto").
 * Assistant turns inside a tool loop are sent back verbatim (including any thinking blocks); earlier turns are plain text.
 */
final class AnthropicProvider extends Provider {
  public const VERSION = '2026-10-21';

  public function chat(string $system, array $messages, array $tools, array $opts): array {
    $msgs = [];
    foreach ($messages as $m) {
      if ($m['role'] === 'tool') {
        $block = ['type' => 'tool_result', 'tool_use_id' => $m['id'], 'content' => (string) $m['content']];
        $last = count($msgs) - 1;
        if ($last >= 0 && $msgs[$last]['role'] === 'user' && is_array($msgs[$last]['content']) && ($msgs[$last]['content'][0]['type'] ?? '') === 'tool_result') { $msgs[$last]['content'][] = $block; }
        else { $msgs[] = ['role' => 'user', 'content' => [$block]]; }
        continue; }
      if ($m['role'] === 'assistant') {
        if (is_array($m['raw'] ?? null) && $m['raw']) { $msgs[] = ['role' => 'assistant', 'content' => $m['raw']]; continue; }
        $blocks = [];
        if ((string) $m['content'] !== '') { $blocks[] = ['type' => 'text', 'text' => (string) $m['content']]; }
        foreach ($m['calls'] ?? [] as $c) { $blocks[] = ['type' => 'tool_use', 'id' => $c['id'], 'name' => $c['name'], 'input' => (object) $c['args']]; }
        $msgs[] = ['role' => 'assistant', 'content' => $blocks ?: [['type' => 'text', 'text' => '...']]];
        continue; }
      $msgs[] = ['role' => 'user', 'content' => (string) $m['content']]; }
    $model = $this->model();
    $body = ['model' => $model, 'max_tokens' => (int) $opts['max_tokens'], 'system' => $system, 'messages' => $msgs];
    if ($tools) { $body['tools'] = array_map(static fn ($t) => ['name' => $t['name'], 'description' => $t['description'], 'input_schema' => $t['schema']], $tools); }
    $headers = ['x-api-key: '.($this->m['api_key'] ?? ''), 'anthropic-version: '.self::VERSION];
    // Chat answers should be quick: keep reasoning effort low on models with the effort control.
    if (preg_match('/^claude-(opus|sonnet|haiku|fable)-5|^claude-opus-4-[5-8]|^claude-sonnet-4-6/', $model)) { $body['output_config'] = ['effort' => 'low']; }
    // Server-side refusal fallback on models that support the "default" routing mode.
    if (preg_match('/^claude-(fable-5-1|opus-5-5|opus-5|sonnet-5-5)$/', $model) && !$this->transport) { $body['fallbacks'] = 'default'; $headers[] = 'anthropic-beta: server-side-fallback-2026-07-01'; }
    $j = $this->send($this->base('https://api.anthropic.com').'/v1/messages', $headers, $body, (int) ($opts['timeout'] ?? 50));
    $stop = (string) ($j['stop_reason'] ?? '');
    if ($stop === 'refusal') { throw new AiException('I can\'t help with that request. Try rephrasing it.', 'Refusal: '.json_encode($j['stop_details'] ?? null)); }
    $text = ''; $calls = [];
    foreach ($j['content'] ?? [] as $b) {
      if (($b['type'] ?? '') === 'text') { $text .= (string) ($b['text'] ?? ''); }
      elseif (($b['type'] ?? '') === 'tool_use') { $calls[] = ['id' => (string) $b['id'], 'name' => (string) $b['name'], 'args' => is_array($b['input'] ?? null) ? $b['input'] : []]; } }
    if (trim($text) === '' && !$calls) { throw new AiException($stop === 'max_tokens' ? 'The answer was cut off. Ask an administrator to raise the max output tokens.' : 'The AI returned an empty answer. Please try again.', 'Empty content, stop_reason='.$stop); }
    $raw = array_map(static fn ($b) => ($b['type'] ?? '') === 'tool_use' && empty($b['input']) ? ['input' => new \stdClass()] + $b : $b, $j['content'] ?? []);
    return ['text' => trim($text), 'calls' => $calls, 'raw' => $raw,'in' => (int) ($j['usage']['input_tokens'] ?? 0), 'out' => (int) ($j['usage']['output_tokens'] ?? 0), 'model' => (string) ($j['model'] ?? $model), 'stop' => $stop]; } }
