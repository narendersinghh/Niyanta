<?php
namespace Modules\Ai\Services;

/**
 * Anthropic Messages API (raw HTTP, API version 2026-10-21). The system prompt is the top-level `system` field and max_tokens is required.
 * Sampling params (temperature) are not sent: API version 2026-10-21 rejects them. Thinking blocks (if any) are skipped; only text blocks are returned.
 */
final class AnthropicProvider extends HttpProvider {
  public const VERSION = '2026-10-21';
  public function name(): string { return 'anthropic'; }

  public function complete(array $messages, array $opts): array {
    [$system, $rest] = self::split($messages);
    $body = ['model' => $this->model, 'max_tokens' => (int) $opts['max_tokens'], 'messages' => $rest];
    if ($system !== '') { $body['system'] = $system; }
    $headers = ['x-api-key: '.$this->key, 'anthropic-version: '.self::VERSION];
    // Short business drafting tasks: keep reasoning effort low on models that support the effort control.
    if (preg_match('/^claude-(opus|sonnet|haiku|fable)-5|^claude-opus-4-[5-8]|^claude-sonnet-4-6/', $this->model)) { $body['output_config'] = ['effort' => 'low']; }
    // Server-side refusal fallback on models that support the "default" routing mode.
    if (preg_match('/^claude-(fable-5-1|opus-5-5|opus-5|sonnet-5-5)$/', $this->model)) { $body['fallbacks'] = 'default'; $headers[] = 'anthropic-beta: server-side-fallback-2026-07-01'; }
    $j = $this->post('https://api.anthropic.com/v1/messages', $headers, $body);
    if (($j['stop_reason'] ?? '') === 'refusal') { throw new AiException('The AI declined to answer this request. Try rephrasing it.', 'Refusal: '.json_encode($j['stop_details'] ?? null)); }
    $text = '';
    foreach ($j['content'] ?? [] as $b) { if (($b['type'] ?? '') === 'text') { $text .= (string) ($b['text'] ?? ''); } }
    if (trim($text) === '') { throw new AiException(($j['stop_reason'] ?? '') === 'max_tokens' ? 'The answer was cut off before any text was produced. Ask an administrator to raise the max output tokens.' : 'The AI returned an empty answer. Please try again.', 'Empty content, stop_reason='.($j['stop_reason'] ?? '?')); }
    return ['text' => trim($text), 'input_tokens' => (int) ($j['usage']['input_tokens'] ?? 0), 'output_tokens' => (int) ($j['usage']['output_tokens'] ?? 0), 'model' => (string) ($j['model'] ?? $this->model)]; } }
