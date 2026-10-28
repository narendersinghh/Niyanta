<?php
namespace Modules\Ai\Services;

/** Shared cURL transport: TLS verified, HTTPS only, 10s connect / 60s total timeouts, friendly errors. */
abstract class HttpProvider implements ProviderInterface {
  public function __construct(protected string $key, protected string $model) {}
  public function model(): string { return $this->model; }

  protected function post(string $url, array $headers, array $body): array {
    if ($this->key === '') { throw new AiException('The AI provider is not configured yet. Ask an administrator to add an API key in AI settings.', 'Missing API key'); }
    if (!function_exists('curl_init')) { throw new AiException('The server cannot reach AI providers (PHP cURL extension is missing).', 'curl missing'); }
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers),
      CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 60,
      CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_FOLLOWLOCATION => false, CURLOPT_USERAGENT => 'Niyanta-AI/1.0']);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($raw === false || $raw === '') { throw new AiException('Could not reach the AI provider. Please check the server connection and try again.', $this->scrub('Network error: '.$err)); }
    $j = json_decode((string) $raw, true);
    if ($code >= 400 || !is_array($j)) {
      $detail = is_array($j) ? (string) ($j['error']['message'] ?? $j['message'] ?? json_encode($j['error'] ?? $j)) : mb_substr((string) $raw, 0, 300);
      throw new AiException(self::friendly($code), $this->scrub('HTTP '.$code.': '.$detail)); }
    return $j; }

  public static function friendly(int $code): string {
    return match (true) {
      $code === 401, $code === 403 => 'The AI provider rejected the API key. Ask an administrator to check the AI settings.',
      $code === 404 => 'The configured AI model was not found. Ask an administrator to check the model name in AI settings.',
      $code === 429 => 'The AI provider is rate limiting or out of credit right now. Please try again in a little while.',
      $code === 400, $code === 413, $code === 422 => 'The AI provider could not process this request. Try shorter input.',
      $code >= 500 => 'The AI provider is temporarily unavailable. Please try again shortly.',
      default => 'The AI provider returned an unexpected response. Please try again.' }; }

  /** Never let a key leak into logs. */
  protected function scrub(string $s): string { return mb_substr($this->key !== '' ? str_replace($this->key, '***', $s) : $s, 0, 480); }

  protected static function split(array $messages): array {
    $sys = []; $rest = [];
    foreach ($messages as $m) { if ($m['role'] === 'system') { $sys[] = (string) $m['content']; } else { $rest[] = ['role' => $m['role'] === 'assistant' ? 'assistant' : 'user', 'content' => (string) $m['content']]; } }
    return [implode("\n\n", $sys), $rest]; } }
