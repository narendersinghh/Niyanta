<?php
namespace Modules\Ai\Services;

/**
 * A chat model with tool calling. Conversations are passed in a neutral format and converted per wire protocol:
 *   ['role' => 'user', 'content' => string]
 *   ['role' => 'assistant', 'content' => string, 'calls' => [['id', 'name', 'args' => array]], 'raw' => provider-native blocks]
 *   ['role' => 'tool', 'id' => call id, 'name' => tool name, 'content' => string]
 * Tools: [['name', 'description', 'schema' => JSON schema object]].
 * chat() returns ['text', 'calls', 'raw', 'in', 'out', 'model', 'stop'].
 */
abstract class Provider {
  /** @param array $m ai_models row with the decrypted 'api_key'. $transport (tests / mock): fn(url, headers, body, timeout): ['code','json','raw'] */
  public function __construct(protected array $m, protected ?\Closure $transport = null) {}

  abstract public function chat(string $system, array $messages, array $tools, array $opts): array;

  public static function make(array $m): self {
    $mock = $m['provider'] === 'mock';
    if ($mock && !\Core\App::config('debug')) { throw new AiException('This AI model is not available.', 'Mock provider requested while debug is off'); }
    $t = $mock ? \Closure::fromCallable([MockServer::class, 'handle']) : null;
    return ($m['provider'] === 'anthropic' || ($mock && str_contains((string) $m['model'], 'anthropic'))) ? new AnthropicProvider($m, $t) : new OpenAiCompatibleProvider($m, $t); }

  public function model(): string { return (string) $this->m['model']; }
  public function name(): string { return (string) $this->m['provider']; }

  protected function send(string $url, array $headers, array $body, int $timeout): array {
    $r = $this->transport ? ($this->transport)($url, $headers, $body, $timeout) : Http::post($url, $headers, $body, $timeout);
    if ($r['code'] >= 400 || !is_array($r['json'])) {
      $j = $r['json'];
      $detail = is_array($j) ? (string) ($j['error']['message'] ?? $j['message'] ?? json_encode($j['error'] ?? $j)) : mb_substr($r['raw'], 0, 300);
      throw new AiException(Http::friendly($r['code']), $this->scrub('HTTP '.$r['code'].': '.$detail)); }
    return $r['json']; }

  /** Never let a key leak into logs. */
  protected function scrub(string $s): string { $k = (string) ($this->m['api_key'] ?? ''); return mb_substr($k !== '' ? str_replace($k, '***', $s) : $s, 0, 480); }

  protected function base(string $default): string { return rtrim(trim((string) ($this->m['base_url'] ?? '')) ?: $default, '/'); } }
