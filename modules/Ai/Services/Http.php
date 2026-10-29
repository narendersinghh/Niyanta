<?php
namespace Modules\Ai\Services;

/**
 * JSON POST transport for model providers. HTTPS is required except for loopback / private-network hosts
 * (Ollama, LM Studio). TLS is verified, redirects are not followed, keys never reach logs.
 * Returns ['code' => int, 'json' => ?array, 'raw' => string].
 */
final class Http {
  /** True when the URL may be used as a base URL: https://any-host or http:// on localhost / private ranges. */
  public static function allowedUrl(string $url): bool {
    $p = parse_url($url);
    if (!$p || empty($p['host']) || !in_array($p['scheme'] ?? '', ['http', 'https'], true) || isset($p['user']) || isset($p['pass'])) { return false; }
    return $p['scheme'] === 'https' || self::isLocal($p['host']); }

  public static function isLocal(string $host): bool {
    $host = trim(strtolower($host), '[]');
    if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) { return true; }
    if (!filter_var($host, FILTER_VALIDATE_IP)) { return false; }
    return !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) || $host === '::1'; }

  public static function post(string $url, array $headers, array $body, int $timeout = 50): array {
    if (!self::allowedUrl($url)) { throw new AiException('The AI model address is not allowed. Use https:// (or http:// only for a local server).', 'Blocked URL'); }
    if (!function_exists('curl_init')) { throw new AiException('The server cannot reach AI providers (PHP cURL extension is missing).', 'curl missing'); }
    $local = parse_url($url, PHP_URL_SCHEME) === 'http';
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json', 'Accept: application/json'], $headers),
      CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => max(5, $timeout),
      CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_PROTOCOLS => $local ? CURLPROTO_HTTP | CURLPROTO_HTTPS : CURLPROTO_HTTPS, CURLOPT_FOLLOWLOCATION => false, CURLOPT_USERAGENT => 'Niyanta-AI/2.0']);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($raw === false || $raw === '') { throw new AiException(str_contains(strtolower($err), 'timed out') ? 'The AI provider took too long to answer. Please try again with a shorter question.' : 'Could not reach the AI provider. Please check the server connection and try again.', 'Network error: '.$err); }
    $j = json_decode((string) $raw, true);
    return ['code' => $code, 'json' => is_array($j) ? $j : null, 'raw' => (string) $raw]; }

  public static function friendly(int $code): string {
    return match (true) {
      $code === 401, $code === 403 => 'The AI provider rejected the API key. Ask an administrator to check Settings › AI assistant.',
      $code === 404 => 'The configured AI model or address was not found. Ask an administrator to check Settings › AI assistant.',
      $code === 429 => 'The AI provider is rate limiting or out of credit right now. Please try again in a little while.',
      $code === 400, $code === 413, $code === 422 => 'The AI provider could not process this request. Try a shorter question or start a new chat.',
      $code >= 500 => 'The AI provider is temporarily unavailable. Please try again shortly.',
      default => 'The AI provider returned an unexpected response. Please try again.' }; } }
