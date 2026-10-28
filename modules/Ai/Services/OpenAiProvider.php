<?php
namespace Modules\Ai\Services;

/** OpenAI Chat Completions API. */
class OpenAiProvider extends HttpProvider {
  protected string $endpoint = 'https://api.openai.com/v1/chat/completions';
  public function name(): string { return 'openai'; }

  protected function headers(): array { return ['Authorization: Bearer '.$this->key]; }

  protected function body(array $messages, array $opts): array {
    $b = ['model' => $this->model, 'messages' => array_map(static fn ($m) => ['role' => $m['role'], 'content' => (string) $m['content']], $messages), 'max_completion_tokens' => (int) $opts['max_tokens']];
    if (isset($opts['temperature'])) { $b['temperature'] = (float) $opts['temperature']; }
    return $b; }

  public function complete(array $messages, array $opts): array {
    $j = $this->post($this->endpoint, $this->headers(), $this->body($messages, $opts));
    $choice = $j['choices'][0] ?? [];
    $text = $choice['message']['content'] ?? '';
    if (is_array($text)) { $text = implode('', array_map(static fn ($p) => is_array($p) ? (string) ($p['text'] ?? '') : (string) $p, $text)); }
    if (trim((string) $text) === '') { throw new AiException(($choice['finish_reason'] ?? '') === 'length' ? 'The answer was cut off before any text was produced. Ask an administrator to raise the max output tokens.' : 'The AI returned an empty answer. Please try again.', 'Empty completion: '.json_encode($choice['finish_reason'] ?? null)); }
    return ['text' => trim((string) $text), 'input_tokens' => (int) ($j['usage']['prompt_tokens'] ?? 0), 'output_tokens' => (int) ($j['usage']['completion_tokens'] ?? 0), 'model' => (string) ($j['model'] ?? $this->model)]; } }
