<?php
namespace Modules\Ai\Services;

/** OpenRouter (OpenAI-compatible) API. */
final class OpenRouterProvider extends OpenAiProvider {
  protected string $endpoint = 'https://openrouter.ai/api/v1/chat/completions';
  public function name(): string { return 'openrouter'; }

  protected function headers(): array {
    $h = ['Authorization: Bearer '.$this->key, 'X-Title: '.preg_replace('/[^\w .-]/', '', (string) \Core\Settings::get('branding.app_name', 'Niyanta'))];
    $url = (string) \Core\App::config('app_url', '');
    if (preg_match('#^https?://[^\s]+$#', $url)) { $h[] = 'HTTP-Referer: '.$url; }
    return $h; }

  protected function body(array $messages, array $opts): array {
    $b = parent::body($messages, $opts);
    unset($b['max_completion_tokens']);
    return $b + ['max_tokens' => (int) $opts['max_tokens']]; } }
