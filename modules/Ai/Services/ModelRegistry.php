<?php
namespace Modules\Ai\Services;
use Core\{App, Crypto, DB};

/** Configured chat models (ai_models) and the presets offered when adding one. API keys are stored encrypted and never leave the server. */
final class ModelRegistry {
  /** key => [name, provider type, base URL, suggested model ids, colour, needs key, hint] */
  public const PRESETS = [
    'openai' => ['OpenAI (ChatGPT)', 'openai_compatible', 'https://api.openai.com/v1', ['gpt-5-mini', 'gpt-5', 'gpt-5-nano', 'gpt-4.1-mini', 'gpt-4o-mini'], 'mint', true, 'Create a key at platform.openai.com › API keys.'],
    'anthropic' => ['Anthropic (Claude)', 'anthropic', 'https://api.anthropic.com', ['claude-opus-5-5', 'claude-sonnet-5-5', 'claude-haiku-5-5', 'claude-fable-5-1'], 'peach', true, 'Create a key at platform.claude.com › API keys.'],
    'openrouter' => ['OpenRouter', 'openai_compatible', 'https://openrouter.ai/api/v1', ['openai/gpt-5-mini', 'anthropic/claude-sonnet-4.5', 'google/gemini-2.5-flash', 'meta-llama/llama-3.3-70b-instruct', 'deepseek/deepseek-chat'], 'lav', true, 'One key for hundreds of models. Pick a model that supports tool calling.'],
    'gemini' => ['Google Gemini', 'openai_compatible', 'https://generativelanguage.googleapis.com/v1beta/openai', ['gemini-2.5-flash', 'gemini-2.5-pro', 'gemini-2.5-flash-lite'], 'sky', true, 'Uses Gemini\'s OpenAI-compatible endpoint. Key from aistudio.google.com.'],
    'groq' => ['Groq', 'openai_compatible', 'https://api.groq.com/openai/v1', ['llama-3.3-70b-versatile', 'openai/gpt-oss-120b', 'qwen/qwen3-32b'], 'lemon', true, 'Very fast open models. Key from console.groq.com.'],
    'mistral' => ['Mistral', 'openai_compatible', 'https://api.mistral.ai/v1', ['mistral-large-latest', 'mistral-medium-latest', 'mistral-small-latest'], 'pink', true, 'Key from console.mistral.ai.'],
    'deepseek' => ['DeepSeek', 'openai_compatible', 'https://api.deepseek.com/v1', ['deepseek-chat'], 'sky', true, 'Use deepseek-chat (tool calling). Key from platform.deepseek.com.'],
    'ollama' => ['Ollama (local)', 'openai_compatible', 'http://localhost:11434/v1', ['llama3.1', 'qwen2.5', 'mistral-nemo'], 'mint', false, 'Runs on your own server, no key needed. Pick a model with tool support.'],
    'custom' => ['Custom server', 'openai_compatible', '', [], 'lav', false, 'Any server that speaks the OpenAI chat completions API, e.g. LM Studio, vLLM or a gateway.'],
  ];
  public const MOCK = ['Mock (debug only)', 'mock', '', ['mock-openai', 'mock-anthropic'], 'lemon', false, 'Offline test model. Only available while debug mode is on.'];
  public const PROVIDERS = ['openai_compatible' => 'OpenAI-compatible', 'anthropic' => 'Anthropic Messages API'];

  public static function presets(): array { return self::PRESETS + (App::config('debug') ? ['mock' => self::MOCK] : []); }
  public static function providers(): array { return self::PROVIDERS + (App::config('debug') ? ['mock' => 'Mock (debug only)'] : []); }

  /** All models, newest default first. 'has_key' flag; never the key itself. */
  public static function all(): array {
    try { $rows = DB::all('SELECT * FROM ai_models ORDER BY is_default DESC, active DESC, label'); }
    catch (\Throwable) { return []; }
    foreach ($rows as &$r) { $r['has_key'] = (string) $r['api_key_enc'] !== ''; unset($r['api_key_enc']); }
    return $rows; }

  public static function find(int $id): ?array { return DB::first('SELECT * FROM ai_models WHERE id=?', [$id]); }

  /** The model chats run on: the active default, else the first active one. Mock rows only count in debug mode. */
  public static function current(): ?array {
    try { $rows = DB::all('SELECT * FROM ai_models WHERE active=1 ORDER BY is_default DESC, id'); }
    catch (\Throwable) { return null; }
    foreach ($rows as $r) { if ($r['provider'] !== 'mock' || App::config('debug')) { return $r; } }
    return null; }

  /** Row with the decrypted key, ready for Provider::make(). */
  public static function withKey(array $r): array { $r['api_key'] = (string) (Crypto::decrypt($r['api_key_enc'] ?? null) ?? ''); unset($r['api_key_enc']); return $r; }

  /** A usable model: active, and holding a key when its preset needs one. */
  public static function usable(?array $r): bool {
    if (!$r) { return false; }
    $needsKey = $r['provider'] === 'anthropic' || (self::PRESETS[$r['preset']][5] ?? false);
    return !$needsKey || (string) ($r['api_key_enc'] ?? '') !== ''; }

  public static function encrypt(string $key): string { return Crypto::encrypt($key); } }
